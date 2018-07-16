<?php
require_once('../common/tools.php');

function sortBalance($a, $b)
{
  if ($a["btc_percent"] == $b["btc_percent"])
    return 0;
  return ($a["btc_percent"] < $b["btc_percent"]) ? 1 : -1;
}

$CryptopiaApi = getMarket('cryptopia');
$KrakenApi = getMarket('kraken');
$CobinApi = getMarket('cobinhood');
$BinanceApi = getMarket('binance');

if(isset($argv[1]))
  $crypto = strtoupper($argv[1]);
else
{
  $balances = [];
  $total_btc = 0;
  foreach( [$CryptopiaApi, $KrakenApi, $CobinApi, $BinanceApi] as $exchange)
  {
    $crypto_list = array_merge( $exchange->getProductList(),['BTC']);

    $cur_balances = call_user_func_array(array($exchange, "getBalance"), $crypto_list);
    foreach($cur_balances as $alt => $bal)
      if($bal >0)
      {
        if($key = array_search($alt, array_column($balances, 'alt')))
        {
          $cryptoInfo = $balances[$key];
          $cryptoInfo['bal'] += $bal;
        }
        else
        {
          $cryptoInfo = [];
          $cryptoInfo['bal'] = $bal;
        }
        $cryptoInfo['alt'] = $alt;

        $price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$alt}&tsyms=BTC"), true);
        $cryptoInfo['btc'] = count($price) == 1 ? $cryptoInfo['bal'] * $price['BTC'] : 0;
        $total_btc += $cryptoInfo['btc'];

        if($key)
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
    print "{$cryptoInfo['alt']} : {$cryptoInfo['bal']} (=". number_format($cryptoInfo['btc_percent'], 3) ."%)\n";

   exit();
}


$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$crypto}&tsyms=BTC"), true);
var_dump("price= {$price['BTC']}");

$Cashroll = 0;
$bal = in_array($crypto, $CryptopiaApi->getProductList()) ? $CryptopiaApi->getBalance($crypto) : 0;
print $CryptopiaApi->name . ": ". $bal . "$crypto\n";
$Cashroll += $bal;
$bal = in_array($crypto, $CobinApi->getProductList()) ? $CobinApi->getBalance($crypto) : 0;
print $CobinApi->name . ": ". $bal . "$crypto\n";
$Cashroll += $bal;
$bal = in_array($crypto, $KrakenApi->getProductList()) ? $KrakenApi->getBalance($crypto) : 0;
print $KrakenApi->name . ": ". $bal . "$crypto\n";
$Cashroll += $bal;
$bal = in_array($crypto, $BinanceApi->getProductList()) ? $BinanceApi->getBalance($crypto) : 0;
print $BinanceApi->name . ": ". $bal . "$crypto\n";
$Cashroll += $bal;

print ("Cashroll: $Cashroll $crypto = ".($Cashroll*$price['BTC'])."BTC\n");
