<?php
require_once('../common/tools.php');

$handle = fopen("gains", "r");
$gains = 0;

$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$btcPrice = $price['EUR'];

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/(\d+-\d+-\d+ \d+:\d+:\d+): (.*) BTC (-?\d+.\d+(E[-+]?[0-9]+)?)%( \((.*)%\))?.*/',$line,  $matches);
        //preg_match('/(\d+-\d+-\d+ \d+:\d+:\d+): (.*) BTC (.*)% ?(\(.*\))?%.*/',$line,  $matches);
        $real_percent_gains = isset($matches[6]) ? number_format($matches[6], 2) : 0;
        print "{$matches[1]}: {$matches[2]} =".number_format($matches[2]*$btcPrice, 3)."EUR ".number_format($matches[3], 2)."% ($real_percent_gains%)\n";
        $gains += floatval($matches[2]);
    }

    fclose($handle);
}

print ("\ngains: $gains = ".($gains*$btcPrice)."EUR\n");

$Cashroll = 0;
foreach (['binance','cryptopia','kraken','cobinhood'] as $market)
{
  $Api = getMarket($market);
  $Api->getBalance();
  print ("$Api->name: {$Api->balances['BTC']} BTC\n");
  $Cashroll += $Api->balances['BTC'];
}
print ("Total cashroll: $Cashroll BTC = ".($Cashroll*$btcPrice)."EUR\n");
