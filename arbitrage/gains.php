<?php
require_once('../common/tools.php');

$options =  getopt('', array(
   'exchange1:',
 ));

$handle = fopen("gains", "r");
$gains = [];
$nTx = [];
$prices['EUR'] = 1;
foreach (['BTC','ETH','USDT'] as $base) {
  $price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$base}&tsyms=EUR"), true);
  $prices[$base] = $price['EUR'];
  $nTx[$base] = 0;
}
$prices['USD'] = $prices['USDT'];

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/(\d+-\d+-\d+ \d+:\d+:\d+): ?(Id=.*)? (.*) ([A-Z]+) (-?\d+.?\d*(E[-+]?[0-9]+)?)%( \((.*)%\))?.*/',$line,  $matches);
        $real_percent_gains = isset($matches[8]) ? number_format($matches[8], 2) : 0;
        $base = $matches[4];
        if (isset($matches[2])) {
          $exchanges = strtolower(substr($matches[2], 3, 4));
          $exchange[0] = substr($exchanges, 0, 2);
          $exchange[1] = substr($exchanges, 2, 2);
        }
        else {
          $exchanges = '';
        }
        $gains_base = $matches[3];
        print "{$matches[1]}: {$exchanges} ".sprintf("%.3e",$gains_base)." {$base} =".number_format($gains_base*$prices[$base], 3)."EUR ".number_format($matches[5], 2)."% ($real_percent_gains%)\n";
        if (isset($options['exchange1']) ) {
          $exchange1 = strtolower(substr($options['exchange1'], 0, 2));
          if(strpos($exchanges, $exchange1) !== false) {
            @$gains[$exchange1][$base] += floatval($gains_base);
            @$mean_percent_gains[$exchange1][$base] += $real_percent_gains;
            $nTx[$base]++;
          }
        }
        else {
         @$gains[$base] += floatval($gains_base);
         @$mean_percent_gains[$base] += $real_percent_gains;
         $nTx[$base]++;
        }
    }
    fclose($handle);
}
print "\n";
$total_gains_eur = 0;
if (isset($options['exchange1']) && !empty($gains[$exchange1])) {
  print "~~~~~~~~~~~~~~~~ {$options['exchange1']} ~~~~~~~~~~~~~~~~~~~\n";
  $gains = $gains[$exchange1];
  $mean_percent_gains = $mean_percent_gains[$exchange1];
}

if (isset($gains)) {
  foreach ($gains as $base => $gain) {
    $mean_percent = number_format($mean_percent_gains[$base] / $nTx[$base], 3);
    $total_gains_eur += $gain * $prices[$base];
    print "~~~~~~~~~~~~~~~~ $base ~~~~~~~~~~~~~~~~~~~\n";
    print ("gains: $gain $base = ".($gain * $prices[$base])."EUR\n");
    print ("Nb Tx: {$nTx[$base]}. Mean gains: $mean_percent %\n\n");
  }
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
