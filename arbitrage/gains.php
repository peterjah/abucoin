<?php
require_once('../common/tools.php');

$handle = fopen("gains", "r");
$gains = 0;

$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$btcPrice = $price['EUR'];

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/.*: (.*) BTC (.*)%$/',$line,  $matches);
        print "{$matches[1]} =".number_format($matches[1]*$btcPrice, 3)."EUR ".number_format($matches[2], 2)."%\n";
        $gains += floatval($matches[1]);
    }

    fclose($handle);
}

print ("\ngains: $gains = ".($gains*$btcPrice)."EUR\n");

$Cashroll = 0;
foreach (['binance','cryptopia','kraken','cobinhood'] as $market)
{
  $Api = getMarket($market);
  $Balance = $Api->getBalance('BTC');
  print ("$Api->name: $Balance BTC\n");
  $Cashroll += $Balance;
}
print ("Total cashroll: $Cashroll BTC = ".($Cashroll*$btcPrice)."EUR\n");
