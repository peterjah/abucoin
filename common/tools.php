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
  if($btc_amount > 0.005)//dont be greedy for testing !!
  {
    $btc_amount = 0.005;
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
  $btc_to_spend_fee = $buy_price*$tradeSize * (1 + $buy_market->product->fees/100);

  print "BUY $tradeSize $alt on {$buy_api->name} at $buy_price BTC = ".($btc_to_spend_fee)."BTC\n";
  print "SELL $tradeSize $alt on {$sell_api->name} at $sell_price BTC = ".($sell_price*$tradeSize)."BTC\n";

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

  $order_status = $buy_api->place_limit_order($alt, 'buy', $buy_price, $tradeSize);
  print "tradesize = $tradeSize buy_status={$order_status['filled_size']}\n";

  if($order_status['filled_size'] < $tradeSize)
  {

    if($buy_api instanceof CryptopiaApi)
    {
      try
      {
        sleep(1);
        $buy_status = $buy_api->getOrderStatus($alt, $order_status['id']);
        if($buy_status['status'] == 'partially_filled')
        {
          print ("new eval: filled {$buy_status['filled']} of $tradeSize $alt \n");
          $tradeSize = $buy_status['filled'];
        }
      } catch (Exception $e){}
    }
    else
      $tradeSize = $order_status['filled_size'];
  }
  if($tradeSize > 0)
  {
    $sell_status = $sell_api->place_limit_order($alt, 'sell', $sell_price, $tradeSize);
    if($sell_status['filled_size'] != $tradeSize)
    {
      print "order size missmatchs. filled: {$sell_status['filled_size']} of $tradeSize\n"; //todo: proper log system
      if($sell_api instanceof CryptopiaApi)
        if($sell_api->cancelOrder($sell_status['id']))//order canceled
          return 0;
        else { //order maybe passed
            $sell_api->save_trade($sell_status['id'], $alt, 'sell', $tradeSize, $sell_price);
          return $tradeSize;
        }
    }
  }
  if( isset($buy_status) && $buy_status['status'] == 'partially_filled')
  {
    sleep(1);
    if($sell_api instanceof CryptopiaApi)
      $buy_api->cancelOrder($order_status['id']);
  }
  return $tradeSize*$buy_price;
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}

function findCommonProducts($market1, $market2)
{
  return array_intersect($market1->getProductList(), $market2->getProductList());
}
