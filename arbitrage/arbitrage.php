<?php

require_once('../common/tools.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

$profit = 0;
while(true)
{
  foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP', 'DASH'] as $alt)
  {
    $AbuOrderbook = new OrderBook($abucoinsApi, "$alt-BTC");
    $abuBook = $AbuOrderbook->book;
    $CryptOrderbook = new OrderBook($CryptopiaApi, "$alt-BTC");
    $cryptBook = $CryptOrderbook->book;

    print "buy {$abuBook['asks']['size']}$alt on Abucoins at {$abuBook['asks']['price']}BTC\n";
    print "sell {$cryptBook['bids']['size']}$alt on Cryptopia at {$cryptBook['bids']['price']}BTC\n";

    $gain_percent = (($cryptBook['bids']['price']/$abuBook['asks']['price'])-1)*100;
    print "gain $gain_percent%\n";
    $tradeSize = $cryptBook['bids']['size'] > $abuBook['asks']['size'] ? $abuBook['asks']['size'] : $cryptBook['bids']['size'];
    $gain1 = $tradeSize * ($cryptBook['bids']['price']*((100-$CryptOrderbook->fees)/100) - $abuBook['asks']['price']);
    $gain1_str = "gain1: buy $tradeSize $alt on abu sell cryptop: ".$gain1."BTC (".number_format($gain_percent,2)."%)\n";
    print "$gain1_str";
    if($gain1>0)
    {
      $profit+=$gain1;
      file_put_contents('gain1',$gain1_str,FILE_APPEND);
    }


    print "\nbuy {$cryptBook['asks']['size']}$alt on Cryptopia at {$cryptBook['asks']['price']}BTC\n";
    print "sell {$abuBook['bids']['size']}$alt on Abucoins at {$abuBook['bids']['price']}BTC\n";
    $gain_percent = (($abuBook['bids']['price']/$cryptBook['asks']['price'])-1)*100;
    print "gain $gain_percent%\n";
    $tradeSize = $cryptBook['asks']['size'] > $abuBook['bids']['size'] ? $abuBook['bids']['size'] : $cryptBook['asks']['size'];
    $gain2 = $tradeSize * ($abuBook['bids']['price'] - $cryptBook['asks']['price']*((100+$CryptOrderbook->fees)/100));
    $gain2_str = "gain2: buy $tradeSize $alt on cryptopia sell abuc: ".$gain2."BTC (".number_format($gain_percent,2)."%)\n";
    print "$gain2_str\n";
    if($gain2>0)
    {
      $profit+=$gain2;
      file_put_contents('gain2',$gain2_str,FILE_APPEND);

      //check both balances
      $account = $abucoinsApi->jsonRequest('GET', "/accounts/10502694-$alt", null);
      $balance = $account->available;
      $btc_account = $CryptopiaApi->jsonRequest("GetBalance",['Currency'=> "BTC"]);
      $btc_bal = $btc_account[0]->Available;

      $btc_to_spend = $cryptBook['asks']['price'] * $tradeSize;
      if($btc_to_spend > 0.01)
      {
        $btc_to_spend = 0.01;
        $tradeSize = $btc_to_spend / $cryptBook['asks']['price'];
      }

      print "balance: $btc_bal BTC; $balance $alt \n";
      print "btc_to_spend = $btc_to_spend tradeSize=$tradeSize\n";

      echo "Are you sure you want to do this?  Type 'yes' to continue: ";
      $handle = fopen ("php://stdin","r");
      $line = fgets($handle);
      if(trim($line) != 'y'){
          echo "ABORTING!\n";
          continue;
      }
      fclose($handle);

      if($btc_to_spend < $btc_bal)
      {
        if($tradeSize < $balance)
        {
          place_limit_order($abucoinsApi, $alt, 'sell', $abuBook['bids']['price'], $tradeSize);
          place_limit_order($CryptopiaApi, $alt, 'buy', $cryptBook['asks']['price'], $tradeSize);
        }
        else
          print "not enough $alt \n";
      }
      else
        print "not enough BTC \n";


    }

    sleep(2);
  }
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
