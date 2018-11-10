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

  $arbId = substr($sell_api->name, 0, 2) . substr($buy_api->name, 0, 2) . '_' . time();

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
      $order_status = $first_api->place_order('limit', $alt, $first_action, $price, $tradeSize, $arbId);
      break;
    }
    catch(Exception $e){
       print ("unable to $first_action retrying...: $e\n");
       $err = $e->getMessage();
       if( $err != 'no response from api' && $err != 'EAPI:Invalid nonce' )
         print_dbg("unable to $first_action $alt (first action) [$err] on {$first_api->name}: tradeSize=$tradeSize at $price. try $i");

       if($i == 5 || $err == 'ERROR: Insufficient Funds.' || $err == 'Market is closed.')
         throw new \Exception("unable to $first_action. [$err]");
       usleep(500000);
       $i++;
    }
  }
  $tradeSize = $order_status['filled_size'];

  $ret = [];
  $ret[$first_action] = $order_status;
  $ret[$first_action]['filled_size'] = $tradeSize;

  $second_status = [];
  if($tradeSize > 0)
  {
    $i=0;
    while(true)
    {
      try{
        $price = $second_action == 'buy' ? $buy_price : $sell_price;
        $second_status = $second_api->place_order('market',$alt, $second_action, $price, $tradeSize, $arbId);
        break;
      }
      catch(Exception $e){
         print ("unable to $second_action retrying...: $e\n");

         var_dump($second_status);
         $err = $e->getMessage();
         if( $err != 'no response from api' && $err != 'EAPI:Invalid nonce' )
         {
           print_dbg("unable to $second_action $alt (second action) [$err] on {$second_api->name}: tradeSize=$tradeSize at $price. try $i");
         }
         if($err =='EOrder:Insufficient funds' || $err == 'insufficient_balance'|| $err == 'ERROR: Insufficient Funds.' ||
            $err == 'Account has insufficient balance for requested action.')
         {
           $second_api->getBalance();
           if($second_action == 'buy')
           {
             $btc_bal = $second_api->balances['BTC'];
             $tradeSize = $btc_bal / $price;
           } else {
             $alt_bal = $second_api->balances[$alt];
             $tradeSize = $alt_bal;
           }
           print_dbg("Insufficient funds to $second_action $alt, btc_bal:$btc_bal alt_bal:$alt_bal");
         }
         if ($err == 'EGeneral:Invalid arguments:volume' || $err == 'Invalid quantity.' || $err == 'invalid_order_size')
         {
           $tradeSize = 0;
           break;
         }
         if($i == 8){
           $tradeSize = 0;
           break;
         }
         $i++;
         usleep(500000);
      }
    }
  }

  $ret[$second_action] = $second_status;
  $ret[$second_action]['filled_size'] = $tradeSize;
  return $ret;
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

function print_dbg($dbg_str)
{
  $str = date("Y-m-d H:i:s")." $dbg_str\n";
  file_put_contents('debug',$str,FILE_APPEND);
}
