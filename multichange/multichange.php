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

  $BTC_EURbook = getOrderBook($abucoinsApi, 'BTC-EUR');
  $LSK_BTCbook = getOrderBook($abucoinsApi, 'LSK-BTC');
  $LSK_EURbook = getOrderBook($abucoinsApi, 'LSK-EUR');


  $bestBTC_Seller = $BTC_EURbook['asks']; //var_dump($bestBTC_Seller);
  $bestLSK_Seller = $LSK_BTCbook['asks']; //var_dump($bestLSK_Seller);
  $bestLSK_Buyer = $LSK_EURbook['bids']; //var_dump($bestLSK_Buyer);

  $roundAmountEur = $bestBTC_Seller['price'] * $bestBTC_Seller['size'];
  if( ($bestLSK_Seller['price'] *$bestLSK_Seller['size']) < $bestBTC_Seller['size'])
  {
    $btc2buy = $bestLSK_Seller['price'] *$bestLSK_Seller['size'];
    $roundAmountEur = $btc2buy * $bestBTC_Seller['price'];
  }
  if($bestLSK_Buyer['price'] * $bestLSK_Buyer['size'] < $roundAmountEur)
  {
    $roundAmountEur = $bestLSK_Buyer['price'] * $bestLSK_Buyer['size'];
  }

  if($roundAmountEur >= $balance)
    $roundAmountEur = $balance;
  var_dump("play with = $roundAmountEur EUR");

  //buy $roundAmountEur of btc
  $tmpBtc = $roundAmountEur / $bestBTC_Seller['price'];
    //var_dump("tmpBtc = $tmpBtc");
  //buy $tmpBtc of lsk
  $tmpLsk = $tmpBtc / $bestLSK_Seller['price'];
    //var_dump("tmpLsk = $tmpLsk");
  //sell lsk for eur
  $gainEur = $tmpLsk * $bestLSK_Buyer['price'] - $roundAmountEur;

  print("gain = $gainEur\n");


  if($gainEur > 0.1) //10 cts!
  { // do trades

  }
  print("~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n");
  sleep(2);
}
