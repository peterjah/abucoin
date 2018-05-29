<?php
require_once('../common/tools.php');
if(isset($argv[1]))
  $crypto = strtoupper($argv[1]);
else exit("quel crypto?\n");

$CryptopiaApi = getMarket('cryptopia');
$KrakenApi = getMarket('kraken');
$CobinApi = getMarket('cobinhood');
$BinanceApi = getMarket('binance');


$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$crypto}&tsyms=BTC"), true);
var_dump("price= {$price['BTC']}");

$Cashroll = 0;
$Cashroll += in_array($crypto, $CryptopiaApi->getProductList()) ? $CryptopiaApi->getBalance($crypto) : 0;
$Cashroll += in_array($crypto, $CobinApi->getProductList()) ? $CobinApi->getBalance($crypto) : 0;
$Cashroll += in_array($crypto, $KrakenApi->getProductList()) ? $KrakenApi->getBalance($crypto) : 0;
$Cashroll += in_array($crypto, $BinanceApi->getProductList()) ? $BinanceApi->getBalance($crypto) : 0;

print ("Cashroll: $Cashroll $crypto = ".($Cashroll*$price['BTC'])."BTC\n");