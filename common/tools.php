<?php

require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');
require_once('../common/kraken_api.php');

function getMarket($market_name)
{
  $market_table = [ 'abucoins' => 'AbucoinsApi',
                    'cryptopia' => 'CryptopiaApi',
                    'kraken' => 'KrakenApi'
                    ];
  if( isset($market_table[$market_name]))
    return new $market_table[$market_name]();
  else throw "Unknown market \"$market_name\"";
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
    $this->alt_price_decimals = $infos['alt_price_decimals'] ?: 8;
  }
}

class OrderBook
{
  public $api;
  public $product;

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
    return $this->api->getOrderBook($this->product->alt, $depth_btc, $depth_alt);
  }
}

function save_trade($exchange, $id, $alt, $side, $size, $price)
{
  print("saving trade\n");
  $trade_str = date("Y-m-d H:i:s").": $exchange: trade $id: $side $size $alt at $price\n";
  file_put_contents('trades',$trade_str,FILE_APPEND);
}

function do_arbitrage($alt, $sell_market, $sell_price, $buy_market, $buy_price, $tradeSize)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;
  $alt_bal = $sell_api->balances[$alt];
  $btc_bal = $buy_api->balances['BTC'];
  //todo: always start by abucoins trade. always finish by kraken trade
  if($buy_api instanceof AbucoinsApi)
    $first_api = $buy_api;
  elseif($sell_api instanceof AbucoinsApi)
    $first_api = $sell_api;
  elseif($buy_api instanceof KrakenApi)
    $first_api = $sell_api;
  else
    $first_api = $buy_api;

  print "start with= $first_api->name \n";
  print "balances: $btc_bal BTC; $alt_bal $alt \n";

  $min_trade_btc = max($buy_market->product->min_order_size_btc, $sell_market->product->min_order_size_btc);
  $min_trade_alt = max($buy_market->product->min_order_size_alt, $sell_market->product->min_order_size_alt);

  $btc_amount = $buy_price * $tradeSize;
  // if($btc_amount > 0.005)//dont be greedy for testing !!
  // {
  //   $btc_amount = 0.005;
  //   $tradeSize = $btc_amount / $buy_price;
  // }
  $btc_to_spend_fee = ($btc_amount * (1 + $buy_market->product->fees/100));
  print "btc_amount = $btc_amount , $btc_to_spend_fee=$btc_to_spend_fee $alt\n";
  if($btc_to_spend_fee > $btc_bal)//Check btc balance
  {
    if($btc_bal > 0)
    {
      $btc_to_spend_fee = $btc_bal; //keep a minimum of btc...
      $btc_amount = $btc_to_spend_fee * (1 - $buy_market->product->fees/100);
      $tradeSize = ($btc_amount / $buy_price) - $buy_market->product->increment;
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
      $tradeSize = $alt_bal;
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

  //ceil tradesize
  $tradeSize = floordec($tradeSize, strlen(substr(strrchr("{$buy_market->product->increment}", "."), 1)));
  $buy_price = ceiling($buy_price, 0.00000001);
  $sell_price = floordec($sell_price, 8);

  print "btc_to_spend_fee = $btc_to_spend_fee for $tradeSize $alt\n";


  $first_action = $first_api == $buy_api ? 'buy' : 'sell';
  $price = $first_action == 'buy' ? $buy_price : $sell_price;

  $i=0;
  while($i<5)
  {
    try{
      $order_status = $first_api->place_order('limit', $alt, $first_action, $price, $tradeSize);
      break;
    }
    catch(Exception $e){
       print ("unable to $first_action retrying...\n");
       $i++;
       $debug_str = date("Y-m-d H:i:s")." unable to $first_action $alt (second action) on {$first_api->name}: tradeSize=$tradeSize at $price. try $i\n";
       file_put_contents('debug',$debug_str,FILE_APPEND);
    }
  }
  print "tradesize = $tradeSize buy_status={$order_status['filled_size']}\n";

  if($order_status['filled_size'] < $tradeSize)
  {
    var_dump($order_status);
    if($first_api instanceof CryptopiaApi)
    {
      try
      {
        print ("Verify cryptopia trade...\n");
        sleep(10);
        $status = $first_api->getOrderStatus($alt, $order_status['id']);
        var_dump($status);
        if( $status['status'] == 'closed' )
          $first_api->save_trade($order_status['id'], $alt, $first_action, $status['filled'], $price);
        else
        {
          $tradeSize = $status['filled'];
          $first_api->cancelOrder($order_status['id']);
          $debug_str = date("Y-m-d H:i:s")." canceled order: {$order_status['id']} $alt tradeSize=$tradeSize filled:{$status['filled']}\n";
          file_put_contents('debug',$debug_str,FILE_APPEND);
        }
      } catch (Exception $e){}
    }
    else
      $tradeSize = $order_status['filled_size'];
  }

  $second_status = [];
  if($tradeSize > 0)
  {
    $i=0;
    while($i<5)
    {
      try{
        $second_api = $first_api == $buy_api ? $sell_api : $buy_api;
        $second_action = $second_api == $buy_api ? 'buy' : 'sell';
        $price = $second_action == 'buy' ? $buy_price : $sell_price;
        $second_status = $second_api->place_order('market',$alt, $second_action, $price, $tradeSize);
        break;
      }
      catch(Exception $e){
         print ("unable to $second_action retrying...\n");
         $i++;
         var_dump($second_status);
         $debug_str = date("Y-m-d H:i:s")." unable to $second_action $alt (second action) on {$second_api->name}: tradeSize=$tradeSize at $price. try $i\n";
         file_put_contents('debug',$debug_str,FILE_APPEND);
      }
    }

  }
  // //Second action
  // if($second_status['filled_size'] < $tradeSize)
  // {
  //   if($second_api instanceof CryptopiaApi)
  //   {
  //     try
  //     {
  //       print ("Verify cryptopia trade...\n");
  //       sleep(20);
  //       $status = $second_api->getOrderStatus($alt, $second_status['id']);
  //       $second_api->cancelOrder($second_status['id']);
  //       var_dump($status);
  //       if( $status['status'] == 'closed' )
  //         $second_api->save_trade($second_status['id'], $alt, $second_action, $status['filled'], $price);
  //       else {
  //         $debug_str = date("Y-m-d H:i:s")." Should not happen order stil open on {$first_api->name}: {$second_status['id']} $second_action $tradeSize $alt @ $price filled:{$status['filled']}\n";
  //         file_put_contents('debug',$debug_str,FILE_APPEND);
  //
  //         $book = $second_api->getOrderBook($alt, null, $tradeSize);
  //         $offer = $second_action == 'buy' ? $book['asks'] : $book['bids'];
  //
  //         $second_status = $second_api->place_order('market',$alt, $second_action, $offer['order_price'], $tradeSize);
  //         if($second_status['filled_size'] < $tradeSize)
  //         {
  //           $second_api->cancelOrder($second_status['id']);
  //           return 0;
  //         }
  //         else {
  //           $second_api->save_trade($second_status['id'], $alt, $second_action, $tradeSize, $offer['price']);
  //         }
  //       }
  //
  //     }
  //     catch(Exception $e){}
  //   }
  // }
  return $tradeSize*$buy_price;
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}

function floordec($number,$decimals=2){
     return floor($number*pow(10,$decimals))/pow(10,$decimals);
}

function findCommonProducts($market1, $market2)
{
  return array_values(array_intersect($market1->getProductList(), $market2->getProductList()));
}
