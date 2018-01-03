<?php

$crypto = @$argv[1];

if(!$crypto && !ctype_lower($crypto))
  exit("specify a crypto: ex \"php trade_balance_sheet.php eth\"");
@define('CRYPTO', strtoupper($crypto));
@define('TRADE_FILE', "tradelist_$crypto.list");

$tradelist = json_decode(file_get_contents(TRADE_FILE));

//ticker
$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=".CRYPTO."&tsyms=EUR"), true);
$ethPrice = $price['EUR'];
//btc ticker
$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$btcPrice = $price['EUR'];

$price =  json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=".CRYPTO."&tsyms=BTC"), true);
$ethPriceBtc = $price['BTC'];
//$init_eth_bal = $tradelist[1]->init_balance_eth;
//$init_btc_bal = $tradelist[0]->init_balance_btc;
//print "$init_eth_bal\n";
//print "$init_btc_bal\n";

$totalbuy = 0;
$meanbuyprice = 0;
$totalsell = 0;
$meansellprice = 0;

$btc_balance = 0;
$eth_balance = 0;
foreach($tradelist as $trade )
{
  if(isset($trade->init_balance_btc) || isset($trade->init_balance_eth))
    continue;
  if($trade->side == "buy")
  {
    $meanbuyprice = ( $meanbuyprice*$totalbuy + $trade->size * $trade->price ) / ($totalbuy + $trade->size);
    $totalbuy += $trade->size;
    $btc_balance -= $trade->size * $trade->price + $trade->fees;
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

print("totalbuy = $totalbuy ".CRYPTO."\n");
print("meanbuyprice = $meanbuyprice BTC\n");
print("totalsell = $totalsell ".CRYPTO."\n");
print("meansellprice = $meansellprice BTC\n\n");

print("Mean gain: ".($totalsell*$meansellprice - $totalbuy*$meanbuyprice)." BTC\n");

print("BTC BALANCE = $btc_balance BTC (=".number_format($btc_balance*$btcPrice, 2)."EUR)\n");
print(CRYPTO." BALANCE = $eth_balance ".CRYPTO."(=".number_format($eth_balance*$ethPrice, 2)."EUR)\n");

print("Total gain: ".number_format($btc_balance*$btcPrice + $eth_balance*$ethPrice, 2)." EUR\n");
print("Total gain: ".number_format($btc_balance + $eth_balance*$ethPriceBtc, 5)." BTC\n");


