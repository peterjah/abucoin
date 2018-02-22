<?php

require_once('../common/tools.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

$profit = 0;
while(true)
{
  foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP', 'DASH', 'ZEC'] as $alt)
  {
    print "Testing $alt trade\n";
    $AbuOrderbook = new OrderBook($abucoinsApi, "$alt-BTC");
    $abuBook = $AbuOrderbook->book;
    $CryptOrderbook = new OrderBook($CryptopiaApi, "$alt-BTC");
    $cryptBook = $CryptOrderbook->book;

    $gain_percent = (($cryptBook['bids']['price']/$abuBook['asks']['price'])-1)*100 - $CryptOrderbook->fees;
    $tradeSize = $cryptBook['bids']['size'] > $abuBook['asks']['size'] ? $abuBook['asks']['size'] : $cryptBook['bids']['size'];
    $gain = $tradeSize * ($cryptBook['bids']['price']*((100-$CryptOrderbook->fees)/100) - $abuBook['asks']['price']);
    if($gain_percent>0.1 && $gain_percent < 20 /*price should be double checked for cryptopia*/)
    {
      $profit+=$gain;
      $gain_str = "gain: buy $tradeSize $alt on cryptopia sell abuc: ".$gain."BTC (".number_format($gain_percent,2)."%)\n";
      file_put_contents('gain2',$gain_str,FILE_APPEND);

      //check both balances
      $account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-$alt", null);
      $balance = $account->available;
      $btc_account = $CryptopiaApi->jsonRequest("GetBalance",['Currency'=> "BTC"]);
      $btc_bal = $btc_account[0]->Available;

      $btc_to_spend = $abuBook['asks']['size'] * $tradeSize;
      if($btc_to_spend > 0.005)//dont be greedy for testing !!
      {
        $btc_to_spend = 0.005;
        $tradeSize = $btc_to_spend / $abuBook['asks']['price'];
      }
      if($btc_to_spend < $CryptOrderbook->product->min_order_size_btc)
        continue;

      if($balance > 0 && $tradeSize > $balance)
        $tradeSize = $balance;

      //truncate tradesize
      $tradeSize = ceiling($tradeSize, $CryptOrderbook->product->$increment);

      print "\nBUY $tradeSize $alt on Cryptopia at {$abuBook['asks']['price']}BTC\n";
      print "SELL $tradeSize $alt on Abucoins at {$cryptBook['bids']['price']}BTC\n";
      print "GAIN ".number_format($gain_percent,2)."%\n";

      if($btc_to_spend < $btc_bal)
      {
        if($tradeSize <= $balance)
        {

          print "balances: $btc_bal BTC; $balance $alt \n";
          print "btc_to_spend = $btc_to_spend for $tradeSize $alt\n";

          place_limit_order($CryptopiaApi, $alt, 'buy', $abuBook['asks']['price'], $tradeSize);
          place_limit_order($abucoinsApi, $alt, 'sell', $cryptBook['bids']['price'], $tradeSize);

        }
        else
          print "not enough $alt \n";
      }
      else
        print "not enough BTC \n";


    }


    $gain_percent = (($abuBook['bids']['price']/$cryptBook['asks']['price'])-1)*100 - $CryptOrderbook->fees;
    $tradeSize = $cryptBook['asks']['size'] > $abuBook['bids']['size'] ? $abuBook['bids']['size'] : $cryptBook['asks']['size'];
    $gain2 = $tradeSize * ($abuBook['bids']['price'] - $cryptBook['asks']['price']*((100+$CryptOrderbook->fees)/100));
    if($gain_percent>0.1 && $gain_percent < 20 /*price should be double checked for cryptopia*/)
    {
      $profit+=$gain2;
      $gain2_str = "gain2: buy $tradeSize $alt on cryptopia sell abuc: ".$gain2."BTC (".number_format($gain_percent,2)."%)\n";
      file_put_contents('gain2',$gain2_str,FILE_APPEND);

      //check both balances
      $account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-$alt", null);
      $balance = $account->available;
      $btc_account = $CryptopiaApi->jsonRequest("GetBalance",['Currency'=> "BTC"]);
      $btc_bal = $btc_account[0]->Available;

      $btc_to_spend = $cryptBook['asks']['price'] * $tradeSize;
      if($btc_to_spend > 0.005)//dont be greedy for testing !!
      {
        $btc_to_spend = 0.005;
        $tradeSize = $btc_to_spend / $cryptBook['asks']['price'];
      }
      if($btc_to_spend < $CryptOrderbook->product->min_order_size_btc)
        continue;

      if($balance > 0 && $tradeSize > $balance)
        $tradeSize = $balance;

      print "\nBUY $tradeSize $alt on Cryptopia at {$cryptBook['asks']['price']}BTC\n";
      print "SELL $tradeSize $alt on Abucoins at {$abuBook['bids']['price']}BTC\n";
      print "GAIN ".number_format($gain_percent,2)."%\n";

      //truncate tradesize
      $tradeSize = ceiling($tradeSize, $CryptOrderbook->product->increment);

      if($btc_to_spend < $btc_bal)
      {
        if($tradeSize <= $balance)
        {

          print "balances: $btc_bal BTC; $balance $alt \n";
          print "btc_to_spend = $btc_to_spend for $tradeSize $alt\n";

          place_limit_order($CryptopiaApi, $alt, 'buy', $cryptBook['asks']['price'], $tradeSize);
          place_limit_order($abucoinsApi, $alt, 'sell', $abuBook['bids']['price'], $tradeSize);

        }
        else
          print "not enough $alt \n";
      }
      else
        print "not enough BTC \n";


    }

    sleep(1);
  }
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
