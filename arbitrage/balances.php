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
    $crypto_list = array_merge( $exchange->getProductList(),['BTC']);
    $exchange->getBalance();
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

        $price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$alt}&tsyms=BTC"), true);
        $cryptoInfo['btc'] = count($price) == 1 ? $cryptoInfo['bal'] * $price['BTC'] : 0;
        $total_btc += $cryptoInfo['btc'];
        $cryptoInfo['eur'] = $cryptoInfo['btc'] * $btc_price['EUR'];

        if($key !== false)
          $balances[$key] = $cryptoInfo;
        else
          $balances[] = $cryptoInfo;
      }
  }

  print "btc value: $total_btc \n";

  foreach($balances as $key => $cryptoInfo)
  {
      $balances[$key]['btc_percent'] = ($cryptoInfo['btc'] / $total_btc)*100;
  }

  usort($balances, "sortBalance");
  foreach($balances as $cryptoInfo)
    print "{$cryptoInfo['alt']} : {$cryptoInfo['bal']} (=". number_format($cryptoInfo['btc_percent'], 3) ."%) ".number_format($cryptoInfo['eur'], 2)." EUR \n";

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
