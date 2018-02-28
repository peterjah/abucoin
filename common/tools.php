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
  protected $api;

  public $alt;
  public $min_order_size_alt;
  public $min_order_size_btc;
  public $fees;
  public $increment;

  public function __construct($api, $alt)
  {
    $this->api = $api;
    $this->alt = $alt;
    $infos = $api->getProductInfo($alt);
    $this->min_order_size_alt = $infos['min_order_size_alt'];
    $this->increment = $infos['increment'];
    $this->fees = $infos['fees'];
    $this->min_order_size_btc = $infos['min_order_size_btc'];
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

  function refreshBook($depth_btc)
  {
    $depth_btc = max($depth_btc,$this->product->min_order_size_btc);
    return $this->api->getOrderBook($this->product->alt, $depth_btc);
  }
}

function save_trade($exchange, $id, $alt, $side, $size, $price)
{
  print("saving trade\n");
  $trade_str = date("Y-m-d H:i:s").": $exchange: trade $id: $side $size $alt at $price\n";
  file_put_contents('trades',$trade_str,FILE_APPEND);
}

function do_arbitrage($alt, $sell_market, $sell_price, $alt_bal, $buy_market, $buy_price, $btc_bal, $tradeSize)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;

  print "do arbitrage for $alt\n";

  print "balances: $btc_bal BTC; $alt_bal $alt \n";

  $min_buy_btc = $buy_market->product->min_order_size_btc;
  $min_buy_alt = $buy_market->product->min_order_size_alt;
  $min_sell_btc = $sell_market->product->min_order_size_btc;
  $min_sell_alt = $sell_market->product->min_order_size_alt;

  $btc_amount = $buy_price * $tradeSize;
  if($btc_amount > 0.03)//dont be greedy for testing !!
  {
    $btc_amount = 0.03;
    $tradeSize = $btc_amount / $buy_price;
  }
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

  print "BUY $tradeSize $alt on {$buy_api->name} at $buy_price BTC = ".($buy_price*$tradeSize)."BTC\n";
  print "SELL $tradeSize $alt on {$sell_api->name} at $sell_price BTC = ".($buy_price*$tradeSize)."BTC\n";

  //Some checks
  if($btc_to_spend_fee < $min_buy_btc || $btc_to_spend_fee < $min_sell_btc)
  { //will be removed by tweeking orderbook feed
    print "insufisent tradesize to process. btc_to_spend_fee=$btc_to_spend_fee min_buy_btc = $min_buy_btc min_sell_btc = $min_sell_btc BTC\n";
    return 0;
  }

  if($tradeSize < $min_sell_alt || $tradeSize < $min_buy_alt)
  {
    print "insufisent tradesize to process. min_sell_alt = $min_sell_alt min_buy_alt = $min_buy_alt $alt\n";
    return 0;
  }

  //ceil tradesize
  $tradeSize = ceiling($tradeSize, $buy_market->product->increment);
  $buy_price = ceiling($buy_price, 0.00000001);
  $sell_price = ceiling($sell_price, 0.00000001);


  print "btc_to_spend_fee = $btc_to_spend_fee for $tradeSize $alt\n";

  $trade_id = $buy_api->place_limit_order($alt, 'buy', $buy_price, $tradeSize);
  //$buy_status = $buy_api->getOrderStatus($buy_market->product->alt, $trade_id);
  print "tradesize = $tradeSize buy_status={$buy_status['filled']}\n";
  if (false && $buy_status['filled'] > 0 )
  {
    if($buy_status['filled'] < $tradeSize)
    {
      print ("filled {$buy_status['filled']} of $tradeSize $alt \n");
      if($buy_api instanceof CryptopiaApi)
      {
        sleep(1);
        try
        {
          $buy_status = $buy_api->getOrderStatus($buy_market->product->alt, $trade_id);
         var_dump($buy_status);
         $tradeSize = $buy_status['filled'];
         $buy_api->jsonRequest('CancelTrade', [ 'Type' => 'Trade', 'OrderId' => $trade_id]);
          print ("new eval: filled {$buy_status['filled']} of $tradeSize $alt \n");
        } catch (Exception $e){}
      }
    }
    $sell_api->place_limit_order($alt, 'sell', $sell_price, $tradeSize);
    return $tradeSize*$buy_price;
  }// handle properly the case when only first order is matched

  return 0;
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}

function findCommonProducts($market1, $market2)
{
  return array_intersect($market1->getProductList(), $market2->getProductList());
}
