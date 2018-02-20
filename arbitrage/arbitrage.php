<?php

require_once('../common/tools.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);


while(true)
{
  foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP'] as $Alt)
  {
    //Get order book
    $AbuOrderbook = new OrderBook($abucoinsApi, "$Alt-BTC");
    $abuBook = $AbuOrderbook->book;
    $CryptOrderbook = new OrderBook($CryptopiaApi, "$Alt-BTC");
    $cryptBook = $CryptOrderbook->book;

    print "buy {$abuBook['asks']['size']}$Alt on Abucoins at {$abuBook['asks']['price']}BTC\n";
    print "sell {$cryptBook['bids']['size']}$Alt on Cryptopia at {$cryptBook['bids']['price']}BTC\n";

    $gain_percent = (($cryptBook['bids']['price']/$abuBook['asks']['price'])-1)*100;
    print "gain $gain_percent%\n";
    $tradeSize = $cryptBook['bids']['size'] > $abuBook['asks']['size'] ? $abuBook['asks']['size'] : $cryptBook['bids']['size'];
    $gain1 = $tradeSize * ($cryptBook['bids']['price']*((100-$CryptOrderbook->fees)/100) - $abuBook['asks']['price']);
    $gain1_str = "gain1: buy $tradeSize $Alt on abu sell cryptop: ".$gain1."BTC ($gain_percent%)\n";
    print "$gain1_str";
    if($gain1>0)
      file_put_contents('gain1',$gain1_str,FILE_APPEND);


    print "\nbuy {$cryptBook['asks']['size']}$Alt on Cryptopia at {$cryptBook['asks']['price']}BTC\n";
    print "sell {$abuBook['bids']['size']}$Alt on Abucoins at {$abuBook['bids']['price']}BTC\n";
    $gain_percent = (($abuBook['bids']['price']/$cryptBook['asks']['price'])-1)*100;
    print "gain $gain_percent%\n";
    $tradeSize = $cryptBook['asks']['size'] > $abuBook['bids']['size'] ? $abuBook['bids']['size'] : $cryptBook['asks']['size'];
    $gain2 = $tradeSize * ($abuBook['bids']['price'] - $cryptBook['asks']['price']*((100+$CryptOrderbook->fees)/100));
    $gain2_str = "gain2: buy $tradeSize $Alt on cryptopia sell abuc: ".$gain2."BTC ($gain_percent%)\n";
    print "$gain2_str\n";
    if($gain2>0)
      file_put_contents('gain2',$gain2_str,FILE_APPEND);

    sleep(2);
  }
}
