<?php
require_once('../common/tools.php');

$handle = fopen("gains", "r");
$gains = 0;

$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$btcPrice = $price['EUR'];

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/.*: (.*) BTC/',$line,  $matches);
        print "{$matches[1]} =".number_format($matches[1]*$btcPrice, 3)."EUR \n";
        $gains += floatval($matches[1]);
    }

    fclose($handle);
}

print ("gains: $gains = ".($gains*$btcPrice)."EUR\n");

$abucoinsApi = getMarket('abucoins');
$CryptopiaApi = getMarket('cryptopia');
$KrakenApi = getMarket('kraken');
$Cashroll = $CryptopiaApi->getBalance('BTC') + $abucoinsApi->getBalance('BTC') + $KrakenApi->getBalance('BTC');
print ("Cashroll: $Cashroll BTC = ".($Cashroll*$btcPrice)."EUR\n");
