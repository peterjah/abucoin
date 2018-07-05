<?php

require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');
require_once('../common/kraken_api.php');
require_once('../common/cobinhood_api.php');
require_once('../common/binance_api.php');

function getMarket($market_name)
{
  $market_table = [ 'abucoins' => 'AbucoinsApi',
                    'cryptopia' => 'CryptopiaApi',
                    'kraken' => 'KrakenApi',
                    'cobinhood' => 'CobinhoodApi',
                    'binance' => 'BinanceApi'
                    ];
  if( isset($market_table[$market_name]))
    return new $market_table[$market_name]();
  else throw new \Exception("Unknown market \"$market_name\"");
}

class product
{
  public $alt;
  public $min_order_size_alt;
  public $min_order_size_btc;
  public $alt_price_decimals;
  public $fees;
  public $increment;

  public function __construct($api, $alt)
  {
    $this->alt = $alt;
    $infos = $api->getProductInfo($alt);
    $this->min_order_size_alt = $infos['min_order_size_alt'];
    $this->increment = $infos['increment'];
    $this->fees = $infos['fees'];
    $this->min_order_size_btc = $infos['min_order_size_btc'];
    $this->alt_price_decimals = isset($infos['alt_price_decimals']) ? $infos['alt_price_decimals'] : 8;
    $this->alt_size_decimals = isset($infos['alt_size_decimals']) ? $infos['alt_size_decimals']
                                          : strlen(substr(strrchr("{$this->increment}", "."), 1));
  }
}

class OrderBook
{
  public $api;
  public $product;
  public $book;

  public function __construct($api, $alt)
  {
    $this->api = $api;
    $this->product = new product($api, $alt);
    $this->book = null;
  }

  function refreshBook($depth_btc, $depth_alt)
  {
    $depth_btc = max($depth_btc, $this->product->min_order_size_btc);
    $depth_alt = max($depth_alt, $this->product->min_order_size_alt);
    $this->book = $this->api->getOrderBook($this->product->alt, $depth_btc, $depth_alt);
    return $this->book;
  }
}

function do_arbitrage($alt, $sell_market, $sell_price, $buy_market, $buy_price, $tradeSize)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;
  $alt_bal = $sell_api->balances[$alt];
  $btc_bal = $buy_api->balances['BTC'];

  if($sell_api->PriorityLevel < $buy_api->PriorityLevel) {
    $first_api = $sell_api;
    $first_action = 'sell';
    $second_api = $buy_api;
    $second_action = 'buy';
  }
  else {
    $first_api = $buy_api;
    $first_action = 'buy';
    $second_api = $sell_api;
    $second_action = 'sell';
  }

  $tradeId = substr($sell_api->name, 0, 2) . substr($buy_api->name, 0, 2) . '_' . time();

  print "start with= $first_api->name \n";
  print "balances: $btc_bal BTC; $alt_bal $alt \n";

  $min_trade_btc = max($buy_market->product->min_order_size_btc, $sell_market->product->min_order_size_btc);
  $min_trade_alt = max($buy_market->product->min_order_size_alt, $sell_market->product->min_order_size_alt);

  $precision = min($buy_market->product->alt_size_decimals, $sell_market->product->alt_size_decimals);
  print "round to alt_size_decimals precision: $precision\n";
  $tradeSize = floordec($tradeSize, $precision);

  $btc_amount = $buy_price * $tradeSize;

  $btc_to_spend_fee = ($btc_amount * (1 + $buy_market->product->fees/100));
  print "btc_amount = $btc_amount , btc_to_spend_fee=$btc_to_spend_fee $alt\n";
  if($btc_to_spend_fee > $btc_bal)//Check btc balance
  {
    if($btc_bal > 0)
    {
      $btc_to_spend_fee = $btc_bal; //keep a minimum of btc...
      $btc_amount = $btc_to_spend_fee * (1 - $buy_market->product->fees/100);
      $tradeSize = floordec(($btc_amount / $buy_price),$precision);
    }
    else
    {
      print "not enough BTC \n";
      return 0;
    }
  }

  if($tradeSize > $alt_bal)//check alt balance
  {
    if($alt_bal > 0)
      $tradeSize = floordec($alt_bal, $precision);
    else
    {
      print "not enough $alt \n";
      return 0;
    }
  }
  $btc_to_spend_fee = $buy_price*$tradeSize * (1 + $buy_market->product->fees/100);

  print "BUY $tradeSize $alt on {$buy_api->name} at $buy_price BTC = ".($btc_to_spend_fee)."BTC\n";
  print "SELL $tradeSize $alt on {$sell_api->name} at $sell_price BTC = ".($sell_price*$tradeSize)."BTC\n";
  //Some checks
  if($btc_to_spend_fee < $min_trade_btc)
  { //will be removed by tweeking orderbook feed
    print "insufisent tradesize to process. btc_to_spend_fee=$btc_to_spend_fee min_trade_btc = $min_trade_btc BTC\n";
    return 0;
  }
  if($tradeSize < $min_trade_alt)
  {
    print "insufisent tradesize to process. tradeSize = $tradeSize min_trade_alt = $min_trade_alt $alt\n";
    return 0;
  }

  $buy_price = ceiling($buy_price, 0.00000001);
  $sell_price = floordec($sell_price, 8);

  print "btc_to_spend_fee = $btc_to_spend_fee for $tradeSize $alt\n";

  $price = $first_action == 'buy' ? $buy_price : $sell_price;

  $i=0;
  while(true)
  {
    try{
      $order_status = $first_api->place_order('limit', $alt, $first_action, $price, $tradeSize,$tradeId);
      break;
    }
    catch(Exception $e){
       print ("unable to $first_action retrying...: $e\n");
       sleep(0.5);
       $i++;
       $err = $e->getMessage();
       if( $err != 'no response from api' && $err != 'EAPI:Invalid nonce' )
       {
         $debug_str = date("Y-m-d H:i:s")." unable to $first_action $alt (first action) [$err] on {$first_api->name}: tradeSize=$tradeSize at $price. try $i\n";
         file_put_contents('debug',$debug_str,FILE_APPEND);
       }
       if($i == 5)
         throw new \Exception("unable to $first_action");
    }
  }
  print "tradesize = $tradeSize buy_status={$order_status['filled_size']}\n";

  if($order_status['filled_size'] < $tradeSize)
  {
    var_dump($order_status);
    if($first_api instanceof CryptopiaApi || $first_api instanceof BinanceApi )
    {
      print ("Verify trade...\n");
      sleep(10);
      $i=0;
      while($i < 10)
      {
        try
        {
          $debug_str = date("Y-m-d H:i:s")." Verify trade on {$first_api->name} : {$order_status['id']} $alt tradeSize=$tradeSize :";

          $status = $first_api->getOrderStatus($alt, $order_status['id']);
          var_dump($status);
          $debug_str += " order is {$status['status']}.";
          if( $status['status'] == 'closed' )
          {
            $first_api->save_trade($order_status['id'], $alt, $first_action, $tradeSize, $price, $tradeId);
            $debug_str += " Saving trade. filled:{$status['filled']}\n";
            print ("order is closed...\n");
          }
          else
          {
            $tradeSize = $status['filled'];
            $first_api->cancelOrder($alt, $order_status['id']);
            if($tradeSize > 0)
            {
              $first_api->save_trade($order_status['id'], $alt, $first_action, $tradeSize, $price, $tradeId);
            }
            $debug_str += " Canceling order : filled:{$status['filled']}\n";
          }
          file_put_contents('debug',$debug_str,FILE_APPEND);
          break;
        } catch (Exception $e)
        {
          $i++;
          print ("Verification failed...\n");
          sleep(1);
        }
      }
    }
    else
      if($order_status['filled_size'] > 0)
        $tradeSize = $order_status['filled_size'];
  }

  $second_status = [];
  if($tradeSize > 0)
  {
    $i=0;
    while(true)
    {
      try{
        $price = $second_action == 'buy' ? $buy_price : $sell_price;
        $second_status = $second_api->place_order('market',$alt, $second_action, $price, $tradeSize, $tradeId);
        break;
      }
      catch(Exception $e){
         print ("unable to $second_action retrying...: $e\n");
         $i++;
         sleep(0.5);
         var_dump($second_status);
         $err = $e->getMessage();
         if( $err != 'no response from api' && $err != 'EAPI:Invalid nonce' )
         {
           $debug_str = date("Y-m-d H:i:s")." unable to $second_action $alt (second action) [$err] on {$second_api->name}: tradeSize=$tradeSize at $price. try $i\n";
           file_put_contents('debug',$debug_str,FILE_APPEND);
         }
         if($e =='EOrder:Insufficient funds' || $e == 'place order failed: insufficient_balance')
         {
           $tradeSize = $tradeSize*0.95;
         }
         if($i == 8){
           $tradeSize = -1;
           break;
         }
      }
    }
  }
  return $tradeSize;
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}

function floordec($number,$precision = 0)
{
  if($precision == 0)
    return floor($number);
  return floor($number*pow(10,$precision))/pow(10,$precision);
}

function findCommonProducts($market1, $market2)
{
  return array_values(array_intersect($market1->getProductList(), $market2->getProductList()));
}
