<?php
require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

function getOrderBook($abucoinsApi, $product_id)
{
  $product = $abucoinsApi->jsonRequest('GET', "/products/$product_id", null);
  $book = $abucoinsApi->jsonRequest('GET', "/products/$product_id/book?level=2", null);

  foreach( ['asks', 'bids'] as $side)
  {
    $best[$side]['price'] = $book->$side[0][0];
    $best[$side]['size'] = $book->$side[0][1];
    $i=1;
    while($best[$side]['size'] < $product->base_min_size)
    {
      $best[$side]['price'] = ($best[$side]['price'] * $best[$side]['size'] + $book->$side[$i][0] * $book->$side[$i][1]) / 2;
      $best[$side]['size'] += $book->$side[$i][1];
      $i++;
    }
  }
  return $best;
}

while(true)
{
  $account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-EUR", null);
  $balance = $account->available;
  print("BTC balance = $balance\n");
  //EUR -> BTC -> LSK -> EUR
  //Get order book

  foreach(['LSK', 'ETH', 'BCH' , 'BTG'] as $Crypto)
  {
    print("Try multi switch with $Crypto\n");
    $BTC_EURbook = getOrderBook($abucoinsApi, 'BTC-EUR');
    $Crypto_BTCbook = getOrderBook($abucoinsApi, "$Crypto-BTC");
    $Crypto_EURbook = getOrderBook($abucoinsApi, "$Crypto-EUR");


    $bestBTC_Seller = $BTC_EURbook['asks']; //var_dump($bestBTC_Seller);
    $bestLSK_Seller = $Crypto_BTCbook['asks']; //var_dump($bestLSK_Seller);
    $bestLSK_Buyer = $Crypto_EURbook['bids']; //var_dump($bestLSK_Buyer);

    $roundAmountEur = $bestBTC_Seller['price'] * $bestBTC_Seller['size'];
    if( ($bestLSK_Seller['price'] *$bestLSK_Seller['size']) < $bestBTC_Seller['size'])
    {
      $btc2buy = $bestLSK_Seller['price'] *$bestLSK_Seller['size'];
      $roundAmountEur = $btc2buy * $bestBTC_Seller['price'];
      //print("wesh1 $roundAmountEur\n");
    }
    if($bestLSK_Buyer['price'] * $bestLSK_Buyer['size'] < $roundAmountEur)
    {
      $roundAmountEur = $bestLSK_Buyer['price'] * $bestLSK_Buyer['size'];
      //print("wesh2 $roundAmountEur\n");
    }

//    if($roundAmountEur >= $balance)
//      $roundAmountEur = $balance;
    print("play with = $roundAmountEur EUR\n");

    //buy $roundAmountEur of btc
    $tmpBtc = $roundAmountEur / $bestBTC_Seller['price'];
      //var_dump("tmpBtc = $tmpBtc");
    //buy $tmpBtc of lsk
    $tmpLsk = $tmpBtc / $bestLSK_Seller['price'];
      //var_dump("tmpLsk = $tmpLsk");
    //sell lsk for eur
    $gainEur = $tmpLsk * $bestLSK_Buyer['price'] - $roundAmountEur;

    print("gain = ".number_format($gainEur,2)." Eur ".number_format(($gainEur/$roundAmountEur)*100, 2) ."%\n");


    if($gainEur > 0.1) //10 cts!
    { // do trades
      exec("echo $gainEur >> gains")
    }
    print("\n");
  }
  print("~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n");
  sleep(2);
}
