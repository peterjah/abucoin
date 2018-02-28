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

  public $id;
  public $min_order_size_alt;
  public $min_order_size_btc;
  public $fees;
  public $increment;

  public function __construct($api, $alt)
  {
    $this->api = $api;

    if($api instanceof AbucoinsApi)
    {
      $this->id = "$alt-BTC";
      $product = null;
      while( ($product = $api->jsonRequest('GET', "/products/{$this->id}", null)) ==null)
       continue;
      $this->min_order_size_alt = $product->base_min_size;
      $this->increment = $product->quote_increment;
      $this->fees = 0; //til end of March
      $this->min_order_size_btc = 0;
    }
    elseif($api instanceof CryptopiaApi)
    {
      $this->id = "$alt_BTC";
      $products = $api->jsonRequest('GetTradePairs');
      foreach( $products as $product)
        if($product->Label == str_replace('_', '/', $this->id))
        {
          $this->min_order_size_alt = $this->increment = $product->MinimumTrade;
          $this->min_order_size_btc = $product->MinimumBaseTrade;
          $this->fees = $product->TradeFee;
        }
    }
    elseif($api instanceof KrakenApi)
    {
      $this->id = "{$alt}XBT";
      $products = null;
      $products = $api->jsonRequest('AssetPairs');
      foreach($products['result'] as $product)
        if($product['altname'] == $this->id)
        {
          $this->min_order_size_alt = $this->increment = pow(10,-1*$product['lot_decimals']);
          print ("increment= {$this->increment}\n");
          $this->min_order_size_btc = pow(10,-1*$product['pair_decimals']);
          print ("increment= {$this->min_order_size_btc}\n");
          $this->fees = $product['fees'][0/*depending on monthly spendings*/][1];
          print ("fees= {$this->fees}\n");
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
    $this->book = null;
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

      if(!isset($book->asks[0][0], $book->bids[0][0], $this->product->min_order_size_alt))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book->$side[0][0]);
        $best[$side]['size'] = floatval($book->$side[0][1]);
        $i=1;
        while(($best[$side]['size'] < $this->product->min_order_size_alt
              || ($best[$side]['size'] * $best[$side]['price'] < 0.0005) ) && $i<50) //todo: ajust book with cryptopia minimum tradesize... change architecure (one class for twos markets)
        {
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book->$side[$i][0]*$book->$side[$i][1]) / ($book->$side[$i][1]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book->$side[$i][1]);
          $best[$side]['order_price'] = floatval($book->$side[$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }
    elseif($this->api instanceof CryptopiaApi)//todo: factorize and tweek
    {
      $ordercount = 25;
      $book = $this->api->jsonRequest("GetMarketOrders/{$this->product->id}/$ordercount");

      if(!isset($book->Sell, $book->Buy, $this->product->min_order_size_btc))
        return null;

        //bids
        $highest_bid = &$best['bids'];
        $highest_bid['price'] = $highest_bid['order_price'] = $book->Buy[0]->Price;
        $highest_bid['size'] = $book->Buy[0]->Volume;
        $i=1;
        while($highest_bid['size'] * $highest_bid['price'] < $this->product->min_order_size_btc && $i<$ordercount)
        {
          $highest_bid['price'] = floatval(($highest_bid['price']*$highest_bid['size'] + $book->Buy[$i]->Total) / ($highest_bid['size']+$book->Buy[$i]->Volume) );
          $highest_bid['size'] += floatval($book->Buy[$i]->Volume);
          $highest_bid['order_price'] = floatval($book->Buy[$i]->Price);
          //print "best highest_bid price={$highest_bid['price']} size={$highest_bid['size']}\n";

          $i++;
        }
        //asks
        $lowest_ask = &$best['asks'];
        $lowest_ask['price'] = $lowest_ask['order_price'] = $book->Sell[0]->Price;
        $lowest_ask['size'] = $book->Sell[0]->Volume;
        $i=1;
        while($lowest_ask['size'] * $lowest_ask['price'] < $this->product->min_order_size_btc && $i<$ordercount)
        {
          $lowest_ask['price'] = floatval(($lowest_ask['price']*$lowest_ask['size'] + $book->Sell[$i]->Total) / ($lowest_ask['size']+$book->Sell[$i]->Volume) );
          $lowest_ask['size'] += floatval($book->Sell[$i]->Volume);
          $lowest_ask['order_price'] = floatval($book->Sell[$i]->Price);
          //print "best lowest_ask price={$lowest_ask['price']} size={$lowest_ask['size']}\n";
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

function do_arbitrage($sell_market, $sell_price, $alt_bal, $buy_market, $buy_price, $btc_bal, $tradeSize)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;

  //retrive alt to trade
  if(preg_match('/(.*)[_-]BTC/', $sell_market->product->id, $matches))
    $alt = $matches[1];
  print "do arbitrage for $alt\n";

  print "balances: $btc_bal BTC; $alt_bal $alt \n";

  $min_buy_btc = $buy_market->product->min_order_size_btc;
  $min_buy_alt = $buy_market->product->min_order_size_alt;
  $min_sell_btc = $sell_market->product->min_order_size_btc;
  $min_sell_alt = $sell_market->product->min_order_size_alt;

  $btc_to_spend = $buy_price * $tradeSize * ((1 + $buy_market->product->fees)/100);
  if($btc_to_spend > 0.03)//dont be greedy for testing !!
  {
    $btc_to_spend = 0.03;
    $tradeSize = $btc_to_spend / $buy_price;
  }
  if($btc_to_spend > $btc_bal)//Check btc balance
  {
    if($btc_bal > 0)
    {
      $btc_to_spend = $btc_bal -0.000001; //keep a minimum of btc...
      $tradeSize = $btc_to_spend / $buy_price;
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
  if($btc_to_spend < $min_buy_btc || $btc_to_spend < $min_sell_btc)
  { //will be removed by tweeking orderbook feed
    print "insufisent tradesize to process. min_buy_btc = $min_buy_btc min_sell_btc = $min_sell_btc BTC\n";
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


  print "btc_to_spend = $btc_to_spend for $tradeSize $alt\n";

  $trade_id = $buy_api->place_limit_order($alt, 'buy', $buy_price, $tradeSize);
  $buy_status = $buy_api->getOrderStatus($buy_market->product->id, $trade_id);
  print "tradesize = $tradeSize buy_status={$buy_status['filled']}\n";
  if ($buy_status['filled'] > 0 )
  {
    if($buy_status['filled'] < $tradeSize)
    {
      print ("filled {$buy_status['filled']} of $tradeSize $alt \n");
      if($buy_api instanceof CryptopiaApi)
      {
        sleep(1);
        try
        {
          $buy_status = $buy_api->getOrderStatus($buy_market->product->id, $trade_id);
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
