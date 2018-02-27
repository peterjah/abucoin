<?php

require_once('../common/tools.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

$profit = 0;

$free_tx = ['toAbucoin' => ['ARK','BCH','ETC','STRAT','BCH'],
            'toCryptopia' => ['GNT','XMR']
            ];

print "retrieve balances\n";
$abucoins_btc_bal = $abucoinsApi->getBalance('BTC');
$cryptopia_btc_bal = $CryptopiaApi->getBalance('BTC');
foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP', 'DASH', 'ZEC'] as $alt)
{
  sleep(1);
  $cryptopia_alt_bal[$alt] = $CryptopiaApi->getBalance($alt) ?: 0;
  $abucoins_alt_bal[$alt] = $abucoinsApi->getBalance($alt) ?: 0;
}
var_dump($cryptopia_alt_bal);
var_dump($abucoins_alt_bal);
var_dump($abucoins_btc_bal);
var_dump($cryptopia_btc_bal);

while(true)
{
  foreach( ['GNT' ,'HSR', 'LTC', 'XMR', 'STRAT', 'ETC', 'TRX', 'ETH', 'ARK', 'BCH', 'REP', 'DASH', 'ZEC'] as $alt)
  {

    $get_btc_abucoins = $abucoins_btc_bal > 0 ? false:true;
    $get_btc_cryptopia = $cryptopia_btc_bal > 0 ? false:true;
    try
    {
      print "Testing $alt trade\n";
      $AbuOrderbook = new OrderBook($abucoinsApi, "$alt-BTC");
      $CryptOrderbook = new OrderBook($CryptopiaApi, "$alt-BTC");

      //SELL Cryptopia => BUY Abucoins
      $tradeSize_btc = 1; //dummy init
      while($tradeSize_btc > 0)
      {

        if( ($alt_bal = $cryptopia_alt_bal[$alt]) == 0)
          break;
        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $abucoins_btc_bal) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $abuBook = $AbuOrderbook->refresh();
        $cryptBook = $CryptOrderbook->refresh();

        $sell_price = $cryptBook['bids']['price'];
        $buy_price = $abuBook['asks']['price'];
        //var_dump($sell_price); var_dump($buy_price);
        $tradeSize = $cryptBook['bids']['size'] > $abuBook['asks']['size'] ? $abuBook['asks']['size'] : $cryptBook['bids']['size'];
        $gain_percent = ( ( ($sell_price *(1-($CryptOrderbook->product->fees/100)))/
                           ($buy_price *(1+($AbuOrderbook->product->fees/100)) ) )-1)*100;

        print("GAIN1= $gain_percent sell_price=$sell_price buy_price=$buy_price\n");

        $gain_treshold = 0.05;
        if(in_array($alt, $free_tx['toAbucoin']) || $get_btc_cryptopia)
          $gain_treshold = 0;
        if($gain_percent >= $gain_treshold)
        {
          //~ print "SELL Cryptopia => BUY Abucoins: GAIN ".number_format($gain_percent,3)."%\n";
          //~ print("sell_price = $sell_price buy_price = $buy_price\n");
          //~ print("sell_order_price = {$cryptBook['bids']['order_price']} buy_order_price = {$abuBook['asks']['order_price']}\n");
          //~ print ("tradeSize=$tradeSize\n");
          $tradeSize_btc = do_arbitrage($CryptOrderbook, $cryptBook['bids']['order_price'], $alt_bal, $AbuOrderbook, $abuBook['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": +$gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            $abucoins_alt_bal[$alt] = $abucoinsApi->getBalance($alt);
            $cryptopia_btc_bal = $CryptopiaApi->getBalance('BTC');
            $abucoins_btc_bal = $abucoinsApi->getBalance('BTC');
            $cryptopia_alt_bal[$alt] = $CryptopiaApi->getBalance($alt);
          }
          else
            $tradeSize_btc = 0;
        }
        else
            $tradeSize_btc = 0;
      }
    }
    catch (Exception $e)
    {
      print $e;
    }
    try
    {
      //SELL Abucoins => BUY Cryptopia
      $tradeSize_btc = 1; //dummy init
      while($tradeSize_btc > 0)
      {
        if( ($alt_bal = $abucoins_alt_bal[$alt]) == 0)
          break;
        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $cryptopia_btc_bal) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $abuBook = $AbuOrderbook->refresh();
        $cryptBook = $CryptOrderbook->refresh();

        $sell_price = $abuBook['bids']['price'];
        $buy_price = $cryptBook['asks']['price'];
        $tradeSize = $cryptBook['asks']['size'] > $abuBook['bids']['size'] ? $abuBook['bids']['size'] : $cryptBook['asks']['size'];

        $gain_percent = (( ($sell_price *(1-($AbuOrderbook->product->fees/100)))/
                           ($buy_price *(1+($CryptOrderbook->product->fees/100))) )-1)*100;
        print("GAIN2= $gain_percent sell_price=$sell_price buy_price=$buy_price\n");
          //~ print("sell_price = $sell_price buy_price = $buy_price\n");
          //~ print("sell_order_price = {$abuBook['bids']['order_price']} buy_order_price = {$cryptBook['asks']['order_price']}\n");
          //~ print ("tradeSize=$tradeSize\n");
        $gain_treshold = 0.05;
        if(in_array($alt, $free_tx['toCryptopia']) || $get_btc_abucoins)
          $gain_treshold = 0;
        if($gain_percent >= $gain_treshold )
        {
          print "SELL Abucoins => BUY Cryptopia: GAIN ".number_format($gain_percent,3)."%\n";
          $tradeSize_btc = do_arbitrage($AbuOrderbook, $abuBook['bids']['order_price'],$alt_bal , $CryptOrderbook, $cryptBook['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": +$gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            $abucoins_alt_bal[$alt] = $abucoinsApi->getBalance($alt);
            $cryptopia_btc_bal = $CryptopiaApi->getBalance('BTC');
            $abucoins_btc_bal = $abucoinsApi->getBalance('BTC');
            $cryptopia_alt_bal[$alt] = $CryptopiaApi->getBalance($alt);
          }
          else
            $tradeSize_btc = 0;
        }
        else
          $tradeSize_btc = 0;
      }
    }
    catch (Exception $e)
    {
      print $e;
    }

  }
  print "~~~~~~~~~~~~~~api calls: ".($abucoinsApi->nApicalls + $CryptopiaApi->nApicalls)."~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
