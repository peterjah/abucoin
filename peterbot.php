<?php
require_once('abucoin_api.php');

$crypto = @$argv[1];

if(!$crypto && !ctype_lower($crypto))
  exit("specify a crypto to trade: ex \"php peterbot.php eth\"");

$keep_min_btc = $argv[2];
$keep_min_crypto = $argv[3];

@define('CRYPTO', strtoupper($crypto));
@define('TRADE_FILE', "tradelist_$crypto.list");
@define('BUY_FILE', "buy_$crypto.list");
@define('PENDING_ORDERS_FILE', "pending_orders_$crypto.list");

@define('KEEP_MIN_BTC', $keep_min_btc);
@define('KEEP_MIN_CRYPT', $keep_min_crypto);
@define('SELL_TRESHOLD', 1.5);
@define('BUY_TRESHOLD', 0.9);
@define('MAX_TX_BTC', 0.0005);
@define('PROFIT_TRESHOLD', 8);

$keys = json_decode(file_get_contents("private.keys"));
$configAbucoins = [
    'secret' => $keys->secret,
    'access_key' => $keys->access_key,
    'passphrase' => $keys->passphrase
];
print("Connect to abucoin\n");
//Init API
$abucoinsApi = new AbucoinsApi($configAbucoins);
$product = $abucoinsApi->jsonRequest('GET', "/products/".CRYPTO."-BTC", null);
@define('MIN_BUY_SIZE', $product->base_min_size);
@define('MIN_STEP_CRYPTO', $product->quote_increment);

function save_trade($ret, $price = 0)
{
  print("saving trade\n");
  $new_trade = ["side" => $ret->side,
             "price" => $price ?:$ret->price,
             "size" => $ret->type == "limit" ? $ret->size : $ret->filled_size,
             "time" => $ret->created_at,
             "fees" => $ret->type == "limit" ? 0 : $ret->fill_fees,
             "id" => $ret->id,
             ];
  $tradelist = [];
  if(file_exists(TRADE_FILE))
  {
    $tradelist = json_decode(file_get_contents(TRADE_FILE));
  }
  $tradelist[] = $new_trade;
  file_put_contents(TRADE_FILE, json_encode($tradelist));
}

function place_order($abucoinsApi, $type, $side, $price, $volume, $funds = null)
{
  $do_trade = true;

  $order = ["product_id" => CRYPTO."-BTC",
            "price"=> $type == "limit" ? $price : null,
            "size"=>  $volume,
            "funds"=> $funds,
            "side"=> $side,
            "type"=> $type,
             ];

  if($do_trade)
  {
    $ret = $abucoinsApi->jsonRequest('POST', '/orders', $order);
    sleep(1);
    var_dump($ret);
    if(isset($ret->status))
    {
      if($order['side'] == "market")
      {
        save_trade($ret, $price);
        if($side == "buy")
        {
          $buylist = [];
          if(file_exists(TRADE_FILE))
            $buylist = json_decode(file_get_contents(BUY_FILE));
          $buylist[] = ["price" => $price, "size" => $volume];
          file_put_contents(BUY_FILE, json_encode($buylist));
        }
      }
      return $ret;
    }
    else print("order failed with status: {$ret->message}\n");
    return null;
  }

}

function take_profit($api, $best_bids, $balance)
{
  print("\ntake profit. best buy price: {$best_bids['price']}\n");
  $nbtrades=0;
  $tradelist = [];
  if(file_exists(BUY_FILE))
    $tradelist = json_decode(file_get_contents(BUY_FILE));

  if($tradelist != null)
  {
    $keeporders = [];
    foreach($tradelist as $idx => $trade)
    {
        $gain = (($best_bids['price']/$trade->price)*100 - 100);
        print("$idx - price:{$trade->price} size:{$trade->size} profit: ".number_format($gain, 2)."%\n");
        if($gain > PROFIT_TRESHOLD && $best_bids['size'] < $balance)
        {
          $size = $trade->size;
          $funds = $size * $best_bids['price'];
          if($best_bids['size'] < $size)
          {
            $size = $best_bids['size'];
            $trade->size -= $size;
            $keeorders[] = $trade;
          }

          $ret = place_order($api, "market", "sell", $trade->price, $size, $funds);
          sleep(1);
          if(isset($ret) && $ret->status == "closed")
          {
            $nbtrades++;
          }
          else print "order failed";
          var_dump($ret);
        }
        else $keeporders[] = $trade;
    }
    file_put_contents(BUY_FILE, json_encode($keeporders));
  }
  return $nbtrades;
}

function take_profit_limit($api, $best_asks, $marketPrice, $balance)
{
  print("Take_profit_limit ask is {$best_asks['price']}\n");
  $tradelist = [];
  $nbtrades=0;
  if(file_exists(PENDING_ORDERS_FILE))
    $orderId = json_decode(file_get_contents(PENDING_ORDERS_FILE));

  if(isset($orderId) && $orderId->id != "")
  {
    $order = $api->jsonRequest('GET', "/orders/{$orderId->id}", null);
    if($order->status == "closed")
    {
      print("Limit order {$orderId->id} passed!\n");
      var_dump($order);
      save_trade($order);
      $nbtrades=1;
    } else print("Limit order {$orderId->id} not passed\n");

    $api->jsonRequest('DELETE', "/orders/$order->id", null);
  }
  if(file_exists(BUY_FILE))
    $tradelist = json_decode(file_get_contents(BUY_FILE));

  if(count($tradelist) > 0)
  {
    $lowerbuy=PHP_INT_MAX;
    foreach($tradelist as $trade)
    {
      if($trade->price < $lowerbuy)
      {
        $lowerbuy = $trade->price;
        $lowertrade = $trade;
      }
    }
    $size = $lowertrade->size;
    $sellprice = $lowertrade->price + $lowertrade->price * (PROFIT_TRESHOLD/100);
  }
  else
  {
    $size = $balance * 0.1; //place X% of the balance
    $size = $size > MIN_BUY_SIZE ? $size : MIN_BUY_SIZE;
    $sellprice = $marketPrice + $marketPrice * (PROFIT_TRESHOLD/100);
  }
  if($sellprice < $best_asks['price'])
    $sellprice = $best_asks['price'] - MIN_STEP_CRYPTO;

  print("place order of $size at $sellprice\n");

  $ret = place_order($api, "limit", "sell", $sellprice, $size);
  file_put_contents(PENDING_ORDERS_FILE, json_encode(["id" => $ret->id]));
  $order = $api->jsonRequest('GET', "/orders/$ret->id", null);
  //var_dump($order);
  return $nbtrades;
}

//Balances
$account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-".CRYPTO, null);
$balance = $account->available;
$btc_account = $abucoinsApi->jsonRequest('GET', '/accounts/10502694-BTC', null);
$btc_balance = $btc_account->available;
print("btc balance = $btc_balance\n");
print("$crypto balance = $balance\n");
$orderId=0;
$nbTrades=0;
while(true)
{
  //ticker
  $ticker = "https://min-api.cryptocompare.com/data/price?fsym=".CRYPTO."&tsyms=BTC";
  $marketPrice = json_decode(file_get_contents($ticker), true);
  if($marketPrice == null)
    continue;
  $marketPrice = $marketPrice['BTC'];

  //Abucoins price
  $ticker = $abucoinsApi->jsonRequest('GET', "/products/".CRYPTO."-BTC/ticker", null);
  $abuPrice = $ticker->price;

  print("cryptocompare $crypto price = $marketPrice\n");
  print("abucoins $crypto price = $abuPrice\n");

  //Get order book
  $orderbook = $abucoinsApi->jsonRequest('GET', "/products/".CRYPTO."-BTC/book?level=2", null);

  foreach( ['asks', 'bids'] as $side)
  {
    $best[$side]['price'] = $orderbook->$side[0][0];
    $best[$side]['size'] = $orderbook->$side[0][1];
    $i=1;
    while($best[$side]['size'] < MIN_BUY_SIZE)
    {
      $best[$side]['price'] = $orderbook->$side[$i][0];
      $best[$side]['size'] += $orderbook->$side[$i][1];
      $i++;
    }
  }

  //compute price gap in %
  $ecart_bids = (100- ($marketPrice/$best['bids']['price'])*100);
  $ecart_asks = (100- ($marketPrice/$best['asks']['price'])*100);


  print("\nbest abucoin buyer at  = {$best['bids']['price']} for {$best['bids']['size']} $crypto\n");
  print("sell at ".number_format($ecart_bids, 2)."% of the price\n");

  //custom safety check
  if($ecart_bids > 0 && abs($ecart_bids) > SELL_TRESHOLD)
  {
    if($balance > KEEP_MIN_CRYPT)
    {
        print("c'est pas mal de lui vendre.\n");
        $size = $best['bids']['size'];
        $fund =  $size * $best['bids']['price'];
        if( $fund > MAX_TX_BTC) //
        {
          $fund = MAX_TX_BTC;
          $size = $fund / $best['bids']['price'];
        }
        $order = place_order($abucoinsApi, "market", "sell", $best['bids']['price'],$size, $fund);
        if($order)
          $nbTrades++;

    } else print("not enough $crypto\n");
  }

  print("\nbest abucoin seller ask = {$best['asks']['price']} {$best['asks']['size']}\n");
  print("buy at ".number_format($ecart_asks, 2)."% of the price\n");

  if($ecart_asks < 0 && abs($ecart_asks) > BUY_TRESHOLD)
    {
      if($btc_balance > KEEP_MIN_BTC)
      {
        print("c'est pas mal de lui acheter\n");
        $size = $best['bids']['size'];
        $fund =  $size * $best['bids']['price'];

        if( $fund > MAX_TX_BTC)
        {
          $fund = MAX_TX_BTC;
          $size = $fund / $best['bids']['price'];
        }

        $order = place_order($abucoinsApi, "market", "buy", $best['asks']['price'], $size, $fund);
        if($order)
          $nbTrades++;

      } else print("not enough BTC\n");
    }
  if($balance > KEEP_MIN_CRYPT)
  {
    $nbTrades += take_profit_limit($abucoinsApi, $best['asks'], $marketPrice, $balance);
    //$nbTrades += take_profit($abucoinsApi, $best['bids'], $balance);
  } else print("not enough $crypto to take profit\n");
  print("\n $$$$$$$$$$$$$$$$$$$$$$ $nbTrades trades filled $$$$$$$$$$$$$$$$$$$$$$$$ \n");
  sleep(30);
}
