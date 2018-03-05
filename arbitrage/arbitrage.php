<?php

require_once('../common/tools.php');

$Api1 = getMarket($argv[1]);
$Api2 = getMarket($argv[2]);

$profit = 0;

$free_tx = ['toAbucoin' => ['ARK'/*,'BCH','STRAT','ZEC'*/],
                            'toCryptopia' => [/*'XMR'*/]
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
$btc_start_cash = $market1_btc_bal + $market2_btc_bal;
$gain_treshold = 0.05;
$low_btc_treshold = -0.1;
$nLoops = 0;
while(true)
{
  foreach( $altcoins_list as $alt)
  {
    $btc_cash_roll = $market1_btc_bal + $market2_btc_bal;
    $low_btc_market_tresh = ($btc_cash_roll)*0.1; //10% of total btc hodling
    $get_btc_market1 = $market1_btc_bal > $low_btc_market_tresh ?false:true;
    $get_btc_market2 = $market2_btc_bal > $low_btc_market_tresh ?false:true;
    // print "Get back btc on {$Api1->name}".($get_btc_market1?"true":"false")."  tresh is $low_btc_market_tresh\n";
    // print "Get back btc on {$Api2->name}".($get_btc_market2?"true":"false")."\n";
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
        $book1 = $orderBook1->refreshBook($orderBook2->product->min_order_size_btc);
        $book2 = $orderBook2->refreshBook($orderBook1->product->min_order_size_btc);

        $sell_price = $book2['bids']['price'];
        $buy_price = $book1['asks']['price'];
        //var_dump($sell_price); var_dump($buy_price);
        $tradeSize = min($book2['bids']['size'],$book1['asks']['size']);
        $gain_percent = ( ( ($sell_price *(1-($orderBook2->product->fees/100)))/
                           ($buy_price *(1+($orderBook1->product->fees/100)) ) )-1)*100;

        print "SELL {$Api2->name} => BUY {$Api1->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";

        if(in_array($alt, $free_tx['toAbucoin']) || $get_btc_market2)
          $gain_treshold = $low_btc_treshold;
        if($gain_percent >= $gain_treshold)
        {

          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");
          $tradeSize_btc = do_arbitrage($alt, $orderBook2, $book2['bids']['order_price'], $alt_bal, $orderBook1, $book1['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": $gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            sleep(1);
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
        $book1 = $orderBook1->refreshBook($orderBook2->product->min_order_size_btc);
        $book2 = $orderBook2->refreshBook($orderBook1->product->min_order_size_btc);

        $sell_price = $book1['bids']['price'];
        $buy_price = $book2['asks']['price'];
        $tradeSize = min($book2['asks']['size'], $book1['bids']['size']);

        $gain_percent = (( ($sell_price *(1-($orderBook1->product->fees/100)))/
                           ($buy_price *(1+($orderBook2->product->fees/100))) )-1)*100;
        print "SELL {$Api1->name} => BUY {$Api2->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";

        if(in_array($alt, $free_tx['toCryptopia']) || $get_btc_market1)
          $gain_treshold = $low_btc_treshold;
        if($gain_percent >= $gain_treshold )
        {
          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");

          $tradeSize_btc = do_arbitrage($alt, $orderBook1, $book1['bids']['order_price'],$alt_bal , $orderBook2, $book2['asks']['order_price'], $btc_bal, $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": $gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            sleep(1);
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
    sleep(1);
    $market2_btc_bal = $Api2->getBalance('BTC');
    $market1_btc_bal = $Api1->getBalance('BTC');
    print "{$Api2->name}:{$market2_btc_bal}BTC  {$Api1->name}:{$market1_btc_bal}BTC\n";
    $btc_cash_roll = $market1_btc_bal + $market2_btc_bal;
  }catch (Exception $e)
  {
    print "failed to get balances\n";
    print $e;
  }
  if($nLoops == 20)
  { // refresh all balances
    foreach( $altcoins_list as $alt)
    {
      sleep(1);
      $market2_alt_bal[$alt] = $Api2->getBalance($alt) ?: 0;
      $market1_alt_bal[$alt] = $Api1->getBalance($alt) ?: 0;
    }
    $nLoops = 0;
  }
  else
    $nLoops++;

  print "~~~~~~~~~~~~~~api calls: ".($Api1->nApicalls + $Api2->nApicalls)."~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
  print "~~~~~~~~~~~~~~cumulated profit: $profit BTC~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n\n";
  print "~~~~~~~~~~~~~~Cash roll: $btc_cash_roll BTC, GAIN=".($btc_cash_roll-$btc_start_cash)."BTC~~~~~~~~~~~~~~~~~~~~~~\n\n";
}
