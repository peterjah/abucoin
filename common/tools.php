<?php

require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');

class product
{
  protected $api;

  public $id;
  public $min_order_size_alt;
  public $min_order_size_btc;
  public $fees;
  public $increment;

  public function __construct($api, $product_id)
  {
    $this->api = $api;

    if($api instanceof AbucoinsApi)
    {
      $this->id = $product_id;
      $product = $this->api->jsonRequest('GET', "/products/{$this->id}", null);
      $this->min_order_size_alt = $product->base_min_size;
      $this->increment = $product->quote_increment;
      $this->fees = 0; //til end of March
      $this->min_order_size_btc = 0;
    }
    elseif($api instanceof CryptopiaApi)
    {
      $this->id = str_replace('-', '_', $product_id);
      $pairs = $this->api->jsonRequest("GetTradePairs");
      foreach( $pairs as $pair)
        if($pair->Label == str_replace('_', '/', $this->id))
        {
          $this->min_order_size_alt = $this->increment = $pair->MinimumTrade;
          $this->min_order_size_btc = $pair->MinimumBaseTrade;
          $this->fees = $pair->TradeFee;
        }
    }
  }
}

class OrderBook
{
  public $api;
  public $product;
  public $book;


  public function __construct($api, $product_id)
  {
    $this->api = $api;
    $this->product = new product($api, $product_id);
    $this->book = self::getOrderBook();
  }

  function refresh()
  {
    return $this->book = self::getOrderBook();
  }

  function getOrderBook()
  {
    if($this->api instanceof AbucoinsApi)
    {
      $book = $this->api->jsonRequest('GET', "/products/{$this->product->id}/book?level=2", null);

      if(!isset($book->asks) || !isset($book->bids) || !isset($this->product->min_order_size_alt))
        return null;

      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = floatval($book->$side[0][0]);
        $best[$side]['size'] = floatval($book->$side[0][1]);
        $i=1;
        while($best[$side]['size'] < $this->product->min_order_size_alt)
        {
          $best[$side]['price'] = floatval(($best[$side]['price'] * $best[$side]['size'] + $book->$side[$i][0] * $book->$side[$i][1]) / 2);
          $best[$side]['size'] += floatval($book->$side[$i][1]);
          $i++;
        }
      }
      return $best;
    }
    elseif($this->api instanceof CryptopiaApi)//todo: factorize and tweek
    {
      $book = $this->api->jsonRequest("GetMarketOrders/{$this->product->id}/10");

      if(!isset($book->Sell) || !isset($book->Buy) || !isset($this->product->min_order_size_alt))
        return null;

        //bids
        $highest_bid = &$best['bids'];
        $highest_bid['price'] = $book->Buy[0]->Price;
        $highest_bid['size'] = $book->Buy[0]->Volume;
        $i=1;
        while($highest_bid['size'] * $highest_bid['price'] < $this->product->min_order_size_alt)
        {
          $highest_bid['price'] = ($highest_bid['price'] * $highest_bid['size'] + $book->Buy[$i]->Total) / 2;
          $highest_bid['size'] += $book->Buy[$i]->Volume;
          $i++;
        }

        //asks
        $lowest_ask = &$best['asks'];
        $lowest_ask['price'] = $book->Sell[0]->Price;
        $lowest_ask['size'] = $book->Sell[0]->Volume;
        $i=1;
        while($lowest_ask['size'] * $lowest_ask['price'] < $this->product->min_order_size_btc)
        {
          $lowest_ask['price'] = ($lowest_ask['price'] * $lowest_ask['size'] + $book->Sell[$i]->Total) / 2;
          $lowest_ask['size'] += $book->Sell[$i]->Volume;
          $i++;
        }
      return $best;
    }
    else throw "wrong api provided";
  }
}

function save_trade($exchange, $id, $alt, $side, $size, $price)
{
  print("saving trade\n");
  $trade_str = date("Y-m-d H:i:s").": $exchange: trade $id: $side $size $alt at $price\n";
  file_put_contents('trades',$trade_str,FILE_APPEND);
}

function place_limit_order($api, $alt, $side, $price, $volume)
{
  $error = '';
  if($api instanceof AbucoinsApi)
  {
    $order = ['product_id' => "$alt-BTC",
              'price'=> $price,
              'size'=>  $volume,
              'side'=> $side,
              'type'=> 'limit',
              'time_in_force' => 'IOC', // immediate or cancel
               ];
    var_dump($order);
    $ret = $api->jsonRequest('POST', '/orders', $order);
    sleep(1);
    print "abucoin trade says:\n";
    var_dump($ret);

    if(!isset($ret->status))
      $error = $ret->message ?: 'unknown error';
    else
    {
      save_trade('Abucoins', $ret->id, $alt, $side, $volume, $price);
      return $ret->id;
    }
  }
  elseif($api instanceof CryptopiaApi)
  {
    $order = ['Market' => "$alt/BTC",
          'Type' => $side,
          'Rate' =>  $price,
          'Amount' => $volume,
           ];
    var_dump($order);
    $ret = $api->jsonRequest('SubmitTrade', $order);
    sleep(1);
    print "cryptopia trade says:\n";
    var_dump($ret);
    if(!isset($ret->OrderId) && !isset($ret->FilledOrders))
    {
      $error = $ret ?: 'unknown error';
    }
    else
    {
      $trade_id = $ret->OrderId ?:$ret->FilledOrders[0];
      save_trade('Cryptopia', $trade_id, $alt, $side, $volume, $price);
      return $trade_id;
    }
  }

  throw new Exception("order failed with status: $error\n");
}

function do_arbitrage($sell_market, $sell_price, $buy_market, $buy_price, $tradeSize)
{
  if($sell_price<$buy_price)
    throw new \Exception("wtf");

  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;

  //retrive alt to trade
  if(preg_match('/(.*)[_-]BTC/', $sell_market->product->id, $matches))
    $alt = $matches[1];
  print "do arbitrage for $alt\n";

  $btc_bal = $buy_api->getBalance('BTC');
  $alt_bal = $sell_api->getBalance($alt);

  print "balances: $btc_bal BTC; $alt_bal $alt \n";

  $min_buy_btc = $buy_market->product->min_order_size_btc;
  $min_buy_alt = $buy_market->product->min_order_size_alt;
  $min_sell_btc = $sell_market->product->min_order_size_btc;
  $min_sell_alt = $sell_market->product->min_order_size_alt;

  $btc_to_spend = $buy_price * $tradeSize;
  if($btc_to_spend > 0.005)//dont be greedy for testing !!
  {
    $btc_to_spend = 0.005;
    $tradeSize = $btc_to_spend / $buy_price;
  }
  if($alt_bal > 0 && $tradeSize > $alt_bal)
    $tradeSize = $alt_bal;

  print "BUY $tradeSize $alt on {$buy_api->name} at $buy_price BTC = ".($buy_price*$tradeSize)."BTC\n";
  print "SELL $tradeSize $alt on {$sell_api->name} at $sell_price BTC = ".($buy_price*$tradeSize)."BTC\n";

  if($btc_to_spend < $min_buy_btc || $btc_to_spend < $min_sell_btc)
  { //will be removed by tweeking orderbook feed
    print "insufisent tradesize to process. min_buy_btc = $min_buy_btc min_sell_btc = $min_sell_btc BTC\n";
    return null;
  }

  if($tradeSize < $min_sell_alt || $tradeSize < $min_buy_alt)
  {
    print "insufisent tradesize to process. min_sell_alt = $min_sell_alt min_buy_alt = $min_buy_alt $alt\n";
    return null;
  }

  //ceil tradesize
  $tradeSize = ceiling($tradeSize, $buy_market->product->increment);

  if($btc_to_spend < $btc_bal)
  {
    if($tradeSize <= $alt_bal)
    {
      print "btc_to_spend = $btc_to_spend for $tradeSize $alt\n";

      place_limit_order($buy_api, $alt, 'buy', $buy_price, $tradeSize);
      place_limit_order($sell_api, $alt, 'sell', $sell_price, $tradeSize);

      return $btc_to_spend;
    }
    else
      print "not enough $alt \n";
  }
  else
    print "not enough BTC \n";
  return null;
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}
