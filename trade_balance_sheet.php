<?php
@define('TRADE_FILE', 'tradelist');

$tradelist = json_decode(file_get_contents(TRADE_FILE));

//eth ticker
$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=ETH&tsyms=EUR"), true);
$ethPrice = $price['EUR'];
//btc ticker
$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$btcPrice = $price['EUR'];


$totalbuy = 0;
$meanbuyprice = 0;
$totalsell = 0;
$meansellprice = 0;

$btc_balance = 0;
$eth_balance = 0;
foreach($tradelist as $trade )
{
  if($trade->side == "buy")
  {
    $meanbuyprice = ( $meanbuyprice*$totalbuy + $trade->size * $trade->price ) / ($totalbuy + $trade->size);
    $totalbuy += $trade->size;
    $btc_balance -= $trade->size * $trade->price - $trade->fees;
    $eth_balance += $trade->size;
  }
  else
  {
    $meansellprice = ( $meansellprice*$totalsell + $trade->size * $trade->price ) / ($totalsell + $trade->size);
    $totalsell += $trade->size;

    $btc_balance += $trade->size * $trade->price - $trade->fees;
    $eth_balance -= $trade->size;
  }
}

print("totalbuy = $totalbuy ETH\n");
print("meanbuyprice = $meanbuyprice BTC\n");
print("totalsell = $totalsell ETH\n");
print("meansellprice = $meansellprice BTC\n\n");

print("BTC BALANCE = $btc_balance BTC (=".($btc_balance*$btcPrice)."EUR\n");
print("ETH BALANCE = $eth_balance ETH(=".($eth_balance*$ethPrice)."EUR\n");

print("Total gain: ".($btc_balance*$btcPrice + $eth_balance*$ethPrice)."EUR\n");
