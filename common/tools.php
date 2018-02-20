<?php

require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');

class product
{
  protected $api;

  public $id;
  public $min_order_size;
  public $fees;

  public function __construct($api, $product_id)
  {
    $this->api = $api;

    if($api instanceof AbucoinsApi)
    {
      $this->id = $product_id;
      $product = $this->api->jsonRequest('GET', "/products/{$this->id}", null);
      $this->min_order_size = $product->base_min_size;
      $this->fees = 0; //til end of March
    }
    elseif($api instanceof CryptopiaApi)
    {
      $this->id = str_replace('-', '_', $product_id);
      $pairs = $this->api->jsonRequest("GetTradePairs");
      foreach( $pairs as $pair)
        if($pair->Label == str_replace('_', '/', $this->id))
        {
          $min_order = $pair->MinimumTrade;
          $this->min_order_size = $pair->MinimumBaseTrade;
          $this->fees = $pair->TradeFee;
        }
    }
  }
}

class OrderBook
{
  protected $api;
  protected $product;

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

      if(!isset($book->asks) || !isset($book->bids) || !isset($this->product->min_order_size))
        return null;

      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $book->$side[0][0];
        $best[$side]['size'] = $book->$side[0][1];
        $i=1;
        while($best[$side]['size'] < $this->product->min_order_size)
        {
          $best[$side]['price'] = ($best[$side]['price'] * $best[$side]['size'] + $book->$side[$i][0] * $book->$side[$i][1]) / 2;
          $best[$side]['size'] += $book->$side[$i][1];
          $i++;
        }
      }
      return $best;
    }
    elseif($this->api instanceof CryptopiaApi)
    {
      $book = $this->api->jsonRequest("GetMarketOrders/{$this->product->id}/10");

      if(!isset($book->Sell) || !isset($book->Buy) || !isset($this->product->min_order_size))
        return null;

      foreach( ['Sell', 'Buy'] as $side)
      {
        $stdSide = $side == 'Buy' ? 'bids' : 'asks';
        $best[$stdSide]['price'] = $book->$side[0]->Price;
        $best[$stdSide]['size'] = $book->$side[0]->Volume;
        $i=1;
        while($best[$stdSide]['size'] < $this->product->min_order_size)
        {
          $best[$stdSide]['price'] = ($best[$stdSide]['price'] * $best[$stdSide]['size'] + $book->$side[$i]->Total) / 2;
          $best[$stdSide]['size'] += $book->$side[$i]->Volume;
          $i++;
        }
      }
      return $best;
    }
    else throw "wrong api provided";
  }
}
