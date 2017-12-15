<?php
require_once('abucoin_api.php');

@define('TRADE_FILE', 'tradelist');
@define('BUY_FILE', 'buy');

@define('KEEP_MIN_BTC', 0.04);
@define('KEEP_MIN_ETH', 0.65);

@define('SELL_ETH_TRESHOLD', 1.2);
@define('BUY_ETH_TRESHOLD', 1);

function save_trade($ret)
{
  print("saving trade\n");
  $new_trade = ["side" => $ret->side,
             "price" => $ret->price,
             "size" => $ret->size,
             "time" => $ret->created_at,
             "fees" => $ret->fill_fees,
             "id" => $ret->id,
             ];
  $tradelist = [];
   if(file_exists(TRADE_FILE))
     $tradelist = json_decode(file_get_contents(TRADE_FILE));
  if($tradelist != null)
  {
    $tradelist[] = $new_trade;
    file_put_contents(TRADE_FILE, json_encode($tradelist));
  }
}

function place_order($abucoinsApi, $type, $side, $price, $volume, $funds = null)
{
  $do_trade = true;

  $order = ["product_id" => "ETH-BTC",
            "price"=> $price,
            "size"=> $volume,
            "funds"=> $funds,
            "side"=> $side,
            "type"=> $type,
            "time_in_force"=> "GTC",
             ];

  if($do_trade)
  {
    $ret = $abucoinsApi->jsonRequest('POST', '/orders', $order);
    var_dump($ret);

    if($ret->status == "done")
    {
      save_trade($ret);
      if($side == "buy")
      {
        $buylist = [];
        if(file_exists(TRADE_FILE))
          $buylist = json_decode(file_get_contents(BUY_FILE));
        $buylist[] = ["price" => $price, "size" => $volume];
        file_put_contents(BUY_FILE, json_encode($buylist));
      }
    }
    else print("order failed with status: {$ret->status}\n");
  }

}

function take_profit($api, $best_buyer_price)
{
  print("take profit. best buy price: $best_buyer_price\n");

  $tradelist = json_decode(file_get_contents(BUY_FILE));

  if($tradelist != null)
  {
    $keeporders = [];
    foreach($tradelist as $idx => $trade)
    {
        $gain = (($best_buyer_price/$trade->price)*100 - 100);
        print("$idx - price:{$trade->price} size:{$trade->size} profit: ".number_format($gain, 2)."%\n");
        if($gain > 7)
        {
          $ret = place_order($api, "market", "sell", null, null, $trade->size);
        if(isset($ret) && $ret->status == "done")
          save_trade($ret);
        }
        else $keeporders[] = $trade;
    }
    file_put_contents(BUY_FILE, json_encode($keeporders));
  }
}


$keys = json_decode(file_get_contents("private.keys"));
$configAbucoins = [
    'secret' => $keys->secret,
    'access_key' => $keys->access_key,
    'passphrase' => $keys->passphrase
];


print("Connect to abucoin\n");
//Init API
$abucoinsApi = new AbucoinsApi($configAbucoins);

//Balances
$eth_account = $abucoinsApi->jsonRequest('GET', '/accounts/10502694-ETH', null);
$eth_balance = $eth_account->available;
$btc_account = $abucoinsApi->jsonRequest('GET', '/accounts/10502694-BTC', null);
$btc_balance = $btc_account->available;
print("btc balance = $btc_balance\n");
print("eth balance = $eth_balance\n");

$nbTrades=0;
while(true)
{
  //eth ticker
  $ethTicker = "https://min-api.cryptocompare.com/data/price?fsym=ETH&tsyms=BTC";
  $ethPrice = json_decode(file_get_contents($ethTicker), true);
  if($ethPrice == null)
    continue;
  $eth_global_price = $ethPrice['BTC'];

  //ETH Abucoins price
  $ethBtc_ticker = $abucoinsApi->jsonRequest('GET', '/products/ETH-BTC/ticker', null);
  $abu_eth_price = $ethBtc_ticker->price;

  print("cryptocompare eth price = $eth_global_price\n");
  print("abucoins eth price = $abu_eth_price\n");

  //Get order book
  $orderbook = $abucoinsApi->jsonRequest('GET', '/products/ETH-BTC/book?level=2', null);
  $best_buyer = $orderbook->bids[0][0];
  $best_buyer_vol = $orderbook->bids[0][1];
  $best_seller = $orderbook->asks[0][0];
  $best_seller_vol = $orderbook->asks[0][1];

  //0.001 is minimum volume trade
  if($best_buyer_vol < 0.001)
  {
    $best_buyer = $orderbook->bids[1][0];
    $best_buyer_vol = $orderbook->bids[1][1];
  }
  if($best_seller_vol < 0.001)
  {
    $best_seller = $orderbook->asks[1][0];
    $best_seller_vol = $orderbook->asks[1][1];
  }

  //compute price gap in %
  $ecart_bids = (100- ($eth_global_price/$best_buyer)*100);
  $ecart_asks = (100- ($eth_global_price/$best_seller)*100);

  //custom safety check

  print("\nbest abucoin buyer at  = $best_buyer ($best_buyer_vol)\n");
  print("sell at ".number_format($ecart_bids, 2)."% of the price\n");

  if($ecart_bids > 0 && abs($ecart_bids) > SELL_ETH_TRESHOLD)
  {
    if($eth_balance > floatval(KEEP_MIN_ETH))
    {
      print("c'est pas mal de lui vendre.\n");
      //place sell order of maximum 0.02 eth
      if($best_buyer_vol > 0.03)
        $best_buyer_vol = 0.03;

        place_order($abucoinsApi, "limit", "sell", $best_buyer, $best_buyer_vol);
        $nbTrades++;
    } else print("not enough ETH\n");
  }

  print("\nbest abucoin seller at  = $best_seller ($best_seller_vol)\n");
  print("buy at ".number_format($ecart_asks, 2)."% of the price\n");

  if($ecart_asks < 0 && abs($ecart_asks) > BUY_ETH_TRESHOLD)
    {
      if($btc_balance > floatval(KEEP_MIN_BTC))
      {
      print("c'est pas mal de lui acheter\n");
      //place buy order of maximum 0.02 eth
      if($best_seller_vol > 0.03)
        $best_seller_vol = 0.03;

        place_order($abucoinsApi, "limit", "buy", $best_seller, $best_seller_vol);
        $nbTrades++;
      } else print("not enough BTC\n");
    }

  take_profit($abucoinsApi, $best_buyer);
  print("\n $$$$$$$$$$$$$$$$$$$$$$ $nbTrades trades filled $$$$$$$$$$$$$$$$$$$$$$$$ \n");
  sleep(30);
}
