<?php
require_once('../common/tools.php');

function sortBalance($a, $b)
{
  if ($a["btc_percent"] == $b["btc_percent"])
    return 0;
  return ($a["btc_percent"] < $b["btc_percent"]) ? 1 : -1;
}


foreach(['cryptopia','kraken', 'cobinhood', 'binance'] as $api)
  $apis[$api] = getMarket($api);

//$ticker = json_decode(file_get_contents("https://api.99cryptocoin.com/v1/ticker"), true);
$cryptoInfo = [];

if(isset($argv[1]))
  $crypto = strtoupper($argv[1]);
else
{
  $btc_price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=EUR"), true);
  $balances = [];
  $total_btc = 0;
  foreach( $apis as $exchange)
  {
    $i=0;
    while ($i < 5) {
      try {
        $crypto_list = array_merge( $exchange->getProductList(),['BTC']);
        $exchange->getBalance();
        break;
      } catch (Exception $e) {
          $i++;
          print "failed to get {$exchange->name} infos";
        }
    }
    foreach($exchange->balances as $alt => $bal)
      if($bal >0)
      {
        if( ($key = array_search($alt, array_column($balances, 'alt'))) !== false)
        {
          $cryptoInfo = $balances[$key];
          $cryptoInfo['bal'] += $bal;
        }
        else
        {
          $cryptoInfo['bal'] = $bal;
        }
        $cryptoInfo['alt'] = $alt;

        //very slowwww
        $price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$alt}&tsyms=BTC"), true);
        if (isset($price['BTC'])) {
          $cryptoInfo['btc'] = $cryptoInfo['bal'] * $price['BTC'];
          $total_btc += $cryptoInfo['btc'];
          $cryptoInfo['eur'] = $cryptoInfo['btc'] * $btc_price['EUR'];
        }
        else {
          $cryptoInfo['btc'] = -1;
          $cryptoInfo['eur'] = -1;
        }

        if($key !== false)
          $balances[$key] = $cryptoInfo;
        else
          $balances[] = $cryptoInfo;
      }
  }

  print "btc value: $total_btc \n";

  foreach($balances as $key => $cryptoInfo){
    if ($cryptoInfo['btc'] > 0)
      $balances[$key]['btc_percent'] = ($cryptoInfo['btc'] / $total_btc)*100;
    else
      $balances[$key]['btc_percent'] = -1;
  }

  usort($balances, "sortBalance");
  $nbCryptos=0;
  printf (" Crypto   | %-15s | %-15s |  %s\n", 'Balance', 'percent', 'EUR');
  foreach($balances as $cryptoInfo)
  {
    $nbCryptos++;
    if( $cryptoInfo['btc_percent'] === -1)
      printf (" %-8s | %-15s | %-15s | -- EUR\n", $cryptoInfo['alt'],$cryptoInfo['bal'], '--%');
    else {
      $bal = number_format($cryptoInfo['bal'], 5);
      $btc_percent = number_format($cryptoInfo['btc_percent'], 3);
      $eur =  number_format($cryptoInfo['eur'], 3);
      printf (" %-8s | %-15s | %-15s | %s EUR\n", $cryptoInfo['alt'], $bal, "$btc_percent%", $eur);
    }
  }

   print "total holdings: $nbCryptos cryptos \n";
   exit();
}


$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$crypto}&tsyms=BTC"), true);
var_dump("price= {$price['BTC']}");

$Cashroll = 0;
foreach ( $apis as $api)
{
  $bal = in_array($crypto, $api->getProductList()) ? $api->getBalance($crypto) : 0;
  print $api->name . ": ". $bal . "$crypto\n";
  $Cashroll += $bal;
}
print ("Cashroll: $Cashroll $crypto = ".($Cashroll*$price['BTC'])."BTC\n");
