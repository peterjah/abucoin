<?php
require_once('../common/tools.php');

function sortBalance($a, $b)
{
  if ($a["btc_percent"] == $b["btc_percent"])
    return 0;
  return ($a["btc_percent"] < $b["btc_percent"]) ? 1 : -1;
}

$markets = [];
foreach(['cryptopia','kraken', 'cobinhood', 'binance'] as $api) {
  $i=0;
  while ($i < 5) {
    try {
      $markets[$api] = new Market($api);
      $markets[$api]->getBalance();
      break;
    } catch (Exception $e) {
      $i++;
      print "failed to get {$markets[$api]->api->name} infos. [{$e->getMessage()}]\n";
    }
  }
}

//$ticker = json_decode(file_get_contents("https://api.99cryptocoin.com/v1/ticker"), true);
$cryptoInfo = [];

if(isset($argv[1]))
  $crypto = strtoupper($argv[1]);
else {
  $btc_price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
  $balances = [];
  foreach( $markets as $market) {
    $crypto_string = '';
    $prices = [];
    foreach($market->api->balances as $alt => $bal) {
      if($bal > 0) {
        if (strlen("{$crypto_string},{$alt}") < 300) {
          $crypto_string .= empty($crypto_string) ? "$alt" : ",{$alt}";
        } else {
          $prices = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/pricemulti?fsyms={$crypto_string}&tsyms=BTC"), true);
          $crypto_string = "$alt";
        }
      }
    }
    $prices = array_merge(json_decode(file_get_contents("https://min-api.cryptocompare.com/data/pricemulti?fsyms={$crypto_string}&tsyms=BTC"), true));

    foreach($market->api->balances as $alt => $bal)
      if($bal >0) {
        @$balances[$alt]['bal'] += $bal;
        if (isset($prices[$alt]['BTC'])) {
          $balances[$alt]['btc'] = $balances[$alt]['bal'] * $prices[$alt]['BTC'];
          $balances[$alt]['eur'] = $balances[$alt]['btc'] * $btc_price['EUR'];
        } else {
          $balances[$alt]['btc'] = -1;
          $balances[$alt]['eur'] = -1;
        }
      }
  }

  foreach($balances as $balance) {
    if($balance['btc'] != -1)
      @$total_btc += $balance['btc'];
  }

  foreach($balances as $alt => $balance) {
    if ($balance['btc'] > 0) {
      $balances[$alt]['btc_percent'] = ($balance['btc'] / $total_btc)*100;
    } else
        $balances[$alt]['btc_percent'] = -1;
  }
  print "btc value: $total_btc \n";
  $bal = uasort($balances, "sortBalance");

  printf (" Crypto   | %-15s | %-15s |  %s\n", 'Balance', 'percent', 'EUR');
  foreach($balances as $alt => $cryptoInfo) {
    if( $cryptoInfo['btc_percent'] === -1)
      printf (" %-8s | %-15s | %-15s | -- EUR\n", $alt, $cryptoInfo['bal'], '--%');
    else {
      $bal = number_format($cryptoInfo['bal'], 5);
      $btc_percent = number_format($cryptoInfo['btc_percent'], 3);
      $eur =  number_format($cryptoInfo['eur'], 3);
      printf (" %-8s | %-15s | %-15s | %s EUR\n", $alt, $bal, "$btc_percent%", $eur);
    }
  }
   print "total holdings: ".count($balances)." cryptos \n";
   exit();
}

$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$crypto}&tsyms=BTC"), true);
var_dump("price= {$price['BTC']}");

$Cashroll = 0;
foreach ( $markets as $market) {
  if(array_key_exists($crypto, $market->api->balances)) {
    $bal =  $market->api->balances[$crypto];
    $Cashroll += $bal;
  } else {
    $bal = 'N/A';
  }
  print $market->api->name . ": ". $bal . " $crypto\n";
}
print ("Cashroll: $Cashroll $crypto = ".($Cashroll*$price['BTC'])."BTC\n");
