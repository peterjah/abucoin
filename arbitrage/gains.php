<?php
require_once('../common/tools.php');

$handle = fopen("gains", "r");
$gains = [];

$prices['EUR'] = 1;
$btc_price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
$prices['BTC'] = $btc_price['EUR'];
$eth_price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=ETH&tsyms=EUR"), true);
$prices['ETH'] = $eth_price['EUR'];
$usdt_price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=USDT&tsyms=EUR"), true);
$prices['USDT'] = $prices['USD'] = $usdt_price['EUR'];

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/(\d+-\d+-\d+ \d+:\d+:\d+): (.*) ([A-Z]+) (-?\d+.?\d*(E[-+]?[0-9]+)?)%( \((.*)%\))?.*/',$line,  $matches);
        //preg_match('/(\d+-\d+-\d+ \d+:\d+:\d+): (.*) BTC (.*)% ?(\(.*\))?%.*/',$line,  $matches);
        $real_percent_gains = isset($matches[7]) ? number_format($matches[7], 2) : 0;
        $base = $matches[3];
        print "{$matches[1]}: ".sprintf("%.3e",$matches[2])." {$matches[3]} =".number_format($matches[2]*$prices[$base], 3)."EUR ".number_format($matches[4], 2)."% ($real_percent_gains%)\n";
        @$gains[$base] += floatval($matches[2]);
    }
    fclose($handle);
}

print "\n";
$total_gains_eur = 0;
foreach ($gains as $base => $gain) {
  $total_gains_eur += $gain * $prices[$base];
  print ("gains: $gain $base = ".($gain * $prices[$base])."EUR\n");
}
print ("total: $total_gains_eur EUR\n");
print "\n";

$Cashroll = 0;
foreach (['binance','cryptopia','kraken','cobinhood'] as $market_name) {
  $i=0;
  while ($i < 5) {
    try {
      $market = new Market($market_name);
      $market->api->getBalance();
      print ("{$market->api->name}: {$market->api->balances['BTC']} BTC\n");
      $Cashroll += $market->api->balances['BTC'];
      break;
    } catch (Exception $e) {
      $i++;
      print "failed to get $market_name infos. [{$e->getMessage()}]\n";
    }
  }
}
print ("Total cashroll: $Cashroll BTC = ".($Cashroll*$prices['BTC'])."EUR\n");
