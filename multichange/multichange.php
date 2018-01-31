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

  if(!isset($book->asks) || !isset($book->bids) || !isset($product->base_min_size))
   return null;

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

$ntrades = 0;
while(true)
{

  foreach(['EUR', 'USD', 'PLN' ] as $Fiat)
  {
    print("Try multi switch with $Fiat\n");
    $account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-$Fiat", null);
    if(!isset($account->available))
      continue;
    $balance = $account->available;
    print("$Fiat balance = $balance\n");
    //FIAT -> BTC -> Crypto -> FIAT
    //Get order book

    foreach(['LSK', 'ETH', 'BCH' , 'BTG'] as $Crypto)
    {
      print("Try multi switch with $Crypto\n");
      if( ($BTC_FIATbook = getOrderBook($abucoinsApi, "BTC-$Fiat")) == null ||
          ($Crypto_BTCbook = getOrderBook($abucoinsApi, "$Crypto-BTC"))== null ||
          ($Crypto_FIATbook = getOrderBook($abucoinsApi, "$Crypto-$Fiat"))== null)
        continue;

      $bestBTC_Seller = $BTC_FIATbook['asks'];//ACHTUNG bids<->asks //var_dump($bestBTC_Seller);
      $bestCrypto_Seller = $Crypto_BTCbook['asks']; //var_dump($bestCrypto_Seller);
      $bestCrypto_Buyer = $Crypto_FIATbook['bids']; //var_dump($bestCrypto_Buyer);

      $roundAmountEur = $bestBTC_Seller['price'] * $bestBTC_Seller['size'];
      if( ($bestCrypto_Seller['price'] *$bestCrypto_Seller['size']) < $bestBTC_Seller['size'])
      {
        $btc2buy = $bestCrypto_Seller['price'] *$bestCrypto_Seller['size'];
        $roundAmountEur = $btc2buy * $bestBTC_Seller['price'];
        //print("wesh1 $roundAmountEur\n");
      }
      if($bestCrypto_Buyer['price'] * $bestCrypto_Buyer['size'] < $roundAmountEur)
      {
        $roundAmountEur = $bestCrypto_Buyer['price'] * $bestCrypto_Buyer['size'];
        //print("wesh2 $roundAmountEur\n");
      }

  //    if($roundAmountEur >= $balance)
  //      $roundAmountEur = $balance;
      print("play with = $roundAmountEur $Fiat\n");

      //buy $roundAmountEur of btc
      $tmpBtc = $roundAmountEur / $bestBTC_Seller['price'];
        //var_dump("tmpBtc = $tmpBtc");
      //buy $tmpBtc of lsk
      $tmpLsk = $tmpBtc / $bestCrypto_Seller['price'];
        //var_dump("tmpLsk = $tmpLsk");
      //sell lsk for eur
      $gainEur = $tmpLsk * $bestCrypto_Buyer['price'] - $roundAmountEur;
      $gainPercent = ($gainEur/$roundAmountEur)*100;
      print("gain = ".number_format($gainEur,2)." Eur ".number_format($gainPercent, 2) ."%\n");


      if($gainEur > 0.1) //10 cts!
      { // do trades
        exec("$ntrades - echo playing with $roundAmountEur $Fiat in $Crypto gain $gainEur $Fiat = $gainPercent%>> gains");
        exec("$ntrades - echo buy $tmpBtcBTC at {$bestBTC_Seller['price']}; buy $tmpLsk at {$bestCrypto_Seller['price']}; sell $tmpLsk at {$bestCrypto_Buyer['price']}>> debug");
      }
      print("\n");
    }
  }
  print("~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n");
  sleep(5);
}
