<?php

require_once('../common/tools.php');

$Api1 = getMarket($argv[1]);
$Api2 = getMarket($argv[2]);

$profit = 0;

$free_tx = ['toAbucoin' => ['ARK','BCH','STRAT','ZEC'],
            'toCryptopia' => ['GNT','XMR']
            ];

print "retrieve balances\n";
$market1_btc_bal = $Api1->getBalance('BTC');
$market2_btc_bal = $Api2->getBalance('BTC');

$altcoins_list = findCommonProducts($Api1,$Api2);
foreach( $altcoins_list as $alt)
{
  sleep(1);
  $market2_alt_bal[$alt] = $Api2->getBalance($alt) ?: 0;
  $market1_alt_bal[$alt] = $Api1->getBalance($alt) ?: 0;
}
var_dump($market2_alt_bal);
var_dump($market1_alt_bal);
var_dump($market1_btc_bal);
var_dump($market2_btc_bal);

while(true)
{
  foreach( $altcoins_list as $alt)
  {

    $get_btc_market1 = $market1_btc_bal > 0.01 ?false:true;
    $get_btc_market2 = $market2_btc_bal > 0.01 ?false:true;
    try
    {
      print "Testing $alt trade\n";
      $orderBook1 = new OrderBook($Api1, $alt);
      $orderBook2 = new OrderBook($Api2, $alt);

      //SELL Cryptopia => BUY Abucoins
      $tradeSize_btc = 1; //dummy init
      while($tradeSize_btc > 0)
      {

        if( ($alt_bal = $market2_alt_bal[$alt]) == 0)
          break;
        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $market1_btc_bal) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $book1 = $orderBook1->refresh();
        $book2 = $orderBook2->refresh();

        $sell_price = $book2['bids']['price'];
        $buy_price = $book1['asks']['price'];
        //var_dump($sell_price); var_dump($buy_price);
        $tradeSize = $book2['bids']['size'] > $book1['asks']['size'] ? $book1['asks']['size'] : $book2['bids']['size'];
        $gain_percent = ( ( ($sell_price *(1-($orderBook2->product->fees/100)))/
                           ($buy_price *(1+($orderBook1->product->fees/100)) ) )-1)*100;

        print "SELL {$Api2->name} => BUY {$Api1->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";
        $gain_treshold = 0.05;
        if(in_array($alt, $free_tx['toAbucoin']) || $get_btc_market2)
          $gain_treshold = 0;
        if($gain_percent >= $gain_treshold)
        {

          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");
          $tradeSize_btc = do_arbitrage($orderBook2, $book2['bids']['order_price'], $alt_bal, $orderBook1, $book1['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": +$gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            usleep(5000);
            $market1_alt_bal[$alt] = $Api1->getBalance($alt);
            $market2_btc_bal = $Api2->getBalance('BTC');
            $market1_btc_bal = $Api1->getBalance('BTC');
            $market2_alt_bal[$alt] = $Api2->getBalance($alt);
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
        if( ($alt_bal = $market1_alt_bal[$alt]) == 0)
          break;
        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $market2_btc_bal) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $book1 = $orderBook1->refresh();
        $book2 = $orderBook2->refresh();

        $sell_price = $book1['bids']['price'];
        $buy_price = $book2['asks']['price'];
        $tradeSize = $book2['asks']['size'] > $book1['bids']['size'] ? $book1['bids']['size'] : $book2['asks']['size'];

        $gain_percent = (( ($sell_price *(1-($orderBook1->product->fees/100)))/
                           ($buy_price *(1+($orderBook2->product->fees/100))) )-1)*100;
        print "SELL {$Api1->name} => BUY {$Api2->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";

        $gain_treshold = 0.05;
        if(in_array($alt, $free_tx['toCryptopia']) || $get_btc_market1)
          $gain_treshold = -0.2;
        if($gain_percent >= $gain_treshold )
        {
          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");

          $tradeSize_btc = do_arbitrage($orderBook1, $book1['bids']['order_price'],$alt_bal , $orderBook2, $book2['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": $gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            usleep(5000);
            $market1_alt_bal[$alt] = $Api1->getBalance($alt);
            $market2_btc_bal = $Api2->getBalance('BTC');
            $market1_btc_bal = $Api1->getBalance('BTC');
            $market2_alt_bal[$alt] = $Api2->getBalance($alt);
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
  try
  {
    //refresh BTC balances
    $market2_btc_bal = $Api2->getBalance('BTC');
    $market1_btc_bal = $Api1->getBalance('BTC');
  }catch (Exception $e)
  {
    print $e;
  }
  print "~~~~~~~~~~~~~~api calls: ".($Api1->nApicalls + $Api2->nApicalls)."~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
