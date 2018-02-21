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
    }
    elseif($api instanceof CryptopiaApi)
    {
      $this->id = str_replace('-', '_', $product_id);
      $pairs = $this->api->jsonRequest("GetTradePairs");
      foreach( $pairs as $pair)
        if($pair->Label == str_replace('_', '/', $this->id))
        {
          $this->min_order_size_alt = $pair->MinimumTrade;
          $this->min_order_size_btc = $pair->MinimumBaseTrade;
          $this->fees = $pair->TradeFee;
        }
    }
  }
}

class OrderBook
{
  protected $api;
  public $product;

  public $book;
  public $fees;

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
        $best[$side]['price'] = $book->$side[0][0];
        $best[$side]['size'] = $book->$side[0][1];
        $i=1;
        while($best[$side]['size'] < $this->product->min_order_size_alt)
        {
          $best[$side]['price'] = ($best[$side]['price'] * $best[$side]['size'] + $book->$side[$i][0] * $book->$side[$i][1]) / 2;
          $best[$side]['size'] += $book->$side[$i][1];
          $i++;
        }
      }
      return $best;
    }
    elseif($this->api instanceof CryptopiaApi)//todo: Minimum total trade is 0.00050000 BTC"
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
  $trade_str = "$exchange: trade $id: $side $size $alt at $price\n";
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

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}
