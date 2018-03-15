<?php

require_once('../common/tools.php');

$Api1 = getMarket($argv[1]);
$Api2 = getMarket($argv[2]);

$profit = 0;

$free_tx = ['toAbucoin' => [/*'ARK','BCH','STRAT','ZEC'*/],
                            'toCryptopia' => [/*'XMR'*/]
            ];

$altcoins_list = findCommonProducts($Api1,$Api2);
$crypto_list = $altcoins_list;
$crypto_list[] = 'BTC';
print "retrieve balances\n";
$Api2->balances = call_user_func_array(array($Api2, "getBalance"), $crypto_list);
$Api1->balances = call_user_func_array(array($Api1, "getBalance"), $crypto_list);

print "create order books obj\n";
foreach( $altcoins_list as $alt)
{
  sleep(0.5);
  $orderBook1[$alt] = new OrderBook($Api1, $alt);
  $Api1->product[$alt] = $orderBook1[$alt]->product;

  $orderBook2[$alt] = new OrderBook($Api2, $alt);
  $Api2->product[$alt] = $orderBook2[$alt]->product;
}
print " {$Api2->name} balances:\n";
var_dump($Api2->balances);
print " {$Api1->name} balances:\n";
var_dump($Api1->balances);

$btc_start_cash = $Api1->balances['BTC'] + $Api2->balances['BTC'];
@define('GAIN_TRESHOLD', 0.05);
@define('LOW_BTC_TRESH', -0.3);

$nLoops = 0;
while(true)
{
  foreach( $altcoins_list as $alt)
  {
    try
    {
      print "Testing $alt trade\n";

      //SELL Cryptopia => BUY Abucoins
      $tradeSize_btc = 1; //dummy init
      while($tradeSize_btc > 0)
      {
        $btc_cash_roll = $Api1->balances['BTC'] + $Api2->balances['BTC'];
        $low_btc_market_tresh = ($btc_cash_roll)*0.1; //10% of total btc hodling
        $get_btc_market2 = $Api2->balances['BTC'] < $low_btc_market_tresh;
        // print "Get back btc on {$Api1->name}".($get_btc_market1?"true":"false")."  tresh is $low_btc_market_tresh\n";
        // print "Get back btc on {$Api2->name}".($get_btc_market2?"true":"false")."\n";

        if( ($alt_bal = $Api2->balances[$alt]) == 0)
          break;

        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $Api1->balances['BTC']) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $min_order_btc = max($orderBook1[$alt]->product->min_order_size_btc, $orderBook2[$alt]->product->min_order_size_btc);
        $min_order_alt = max($orderBook1[$alt]->product->min_order_size_alt, $orderBook2[$alt]->product->min_order_size_alt);
        $book1 = $orderBook1[$alt]->refreshBook($min_order_btc,$min_order_alt);
        $book2 = $orderBook2[$alt]->refreshBook($min_order_btc,$min_order_alt);

        $sell_price = $book2['bids']['price'];
        $buy_price = $book1['asks']['price'];
        //var_dump($sell_price); var_dump($buy_price);
        $tradeSize = min($book2['bids']['size'], $book1['asks']['size']);
        $gain_percent = ( ( ($sell_price *(1-($orderBook2[$alt]->product->fees/100)))/
                           ($buy_price *(1+($orderBook1[$alt]->product->fees/100)) ) )-1)*100;

        //print "SELL {$Api2->name} => BUY {$Api1->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";
        //print "tradeSize=$tradeSize min {$book2['bids']['size']}, {$book1['asks']['size']}\n";

        $gain_treshold = GAIN_TRESHOLD;
        if(in_array($alt, $free_tx['toAbucoin']) || $get_btc_market2)
        {
          $gain_treshold = LOW_BTC_TRESH;
          $half_cash_alt = ($btc_cash_roll/2) * $book1['asks']['order_price'];
          if( $tradeSize > $half_cash_alt && $gain_percent < 0)
            $tradeSize = $half_cash_alt > $min_order_alt ? $half_cash_alt : $min_order_alt;
        }

        if($gain_percent >= $gain_treshold)
        {
          //balance double check
          if( ($alt_bal = $Api2->getBalance($alt)) > 0)
            $Api2->balances[$alt] = $alt_bal;
          else
             break;

          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");
          print "do arbitrage for $alt. estimated gain: {$gain_percent}%\n";
          $tradeSize_btc = do_arbitrage($alt, $orderBook2[$alt], $book2['bids']['order_price'], $orderBook1[$alt], $book1['asks']['order_price'], $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": $gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            sleep(0.5);
            foreach ([$Api1,$Api2] as $Api) {
              $balances = $Api->getBalance('BTC', $alt);
                $Api->balances = array_merge($Api->balances, $balances);
            }
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
      //refresh balances
      sleep(3);
      try{
        $Api1->balances[$alt] = $Api1->getBalance($alt);
        $Api2->balances[$alt] = $Api2->getBalance($alt);
      }catch (Exception $e){}
    }
    try
    {
      //SELL Abucoins => BUY Cryptopia
      $tradeSize_btc = 1; //dummy init
      while($tradeSize_btc > 0)
      {
        $btc_cash_roll = $Api1->balances['BTC'] + $Api2->balances['BTC'];
        $low_btc_market_tresh = ($btc_cash_roll)*0.1; //10% of total btc hodling
        $get_btc_market1 = $Api1->balances['BTC'] < $low_btc_market_tresh;

        if( ($alt_bal = $Api1->balances[$alt]) == 0)
          break;

        //print("alt_bal=$alt_bal ");
        if( ($btc_bal = $Api2->balances['BTC']) == 0)
          break;
        //print("btc_bal=$btc_bal \n");
        $min_order_btc = max($orderBook1[$alt]->product->min_order_size_btc, $orderBook2[$alt]->product->min_order_size_btc);
        $min_order_alt = max($orderBook1[$alt]->product->min_order_size_alt, $orderBook2[$alt]->product->min_order_size_alt);
        $book1 = $orderBook1[$alt]->refreshBook($min_order_btc,$min_order_alt);
        $book2 = $orderBook2[$alt]->refreshBook($min_order_btc,$min_order_alt);

        $sell_price = $book1['bids']['price'];
        $buy_price = $book2['asks']['price'];
        $tradeSize = min($book2['asks']['size'], $book1['bids']['size']);

        $gain_percent = (( ($sell_price *(1-($orderBook1[$alt]->product->fees/100)))/
                           ($buy_price *(1+($orderBook2[$alt]->product->fees/100))) )-1)*100;
        //print "SELL {$Api1->name} => BUY {$Api2->name}: GAIN ".number_format($gain_percent,3)."%  sell_price=$sell_price buy_price=$buy_price\n";
        //print "tradeSize=$tradeSize min {$book2['asks']['size']},{$book1['bids']['size']}\n";

        $gain_treshold = GAIN_TRESHOLD;
        if(in_array($alt, $free_tx['toCryptopia']) || $get_btc_market1)
        {
          $gain_treshold = LOW_BTC_TRESH;
          $half_cash_alt = ($btc_cash_roll/2) * $book2['asks']['order_price'];
          if($tradeSize >  $half_cash_alt && $gain_percent < 0)
            $tradeSize = $half_cash_alt > $min_order_alt ? $half_cash_alt : $min_order_alt;
        }
        if($gain_percent >= $gain_treshold )
        {
          if($sell_price <= $buy_price && $gain_treshold > 0)
            throw new \Exception("wtf");
          //balance double check
          if( ($alt_bal = $Api1->getBalance($alt)) > 0)
            $Api1->balances[$alt] = $alt_bal;
          else
             break;
          print "do arbitrage for $alt. estimated gain: {$gain_percent}%\n";
          $tradeSize_btc = do_arbitrage($alt, $orderBook1[$alt], $book1['bids']['order_price'], $orderBook2[$alt], $book2['asks']['order_price'], $tradeSize);
          if($tradeSize_btc>0)
          {
            print("log tx\n");
            $gain_btc = $tradeSize_btc*$gain_percent/100;
            $profit+=$gain_btc;
            $trade_str = date("Y-m-d H:i:s").": $gain_btc BTC\n";
            file_put_contents('gains',$trade_str,FILE_APPEND);

            //refresh balances
            sleep(0.5);
            foreach ([$Api1,$Api2] as $Api) {
              $balances = $Api->getBalance('BTC', $alt);
                $Api->balances = array_merge($Api->balances, $balances);
            }

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
      //refresh balances
      sleep(3);
      try
      {
        $Api1->balances[$alt] = $Api1->getBalance($alt);
        $Api2->balances[$alt] = $Api2->getBalance($alt);
      }catch (Exception $e){}
    }
  }

  if($nLoops == PHP_INT_MAX)
    $nLoops=0;
  else
    $nLoops++;

  try
  {
    $alt_to_refresh = $altcoins_list[ ($nLoops % count($altcoins_list) )];

    foreach ([$Api1,$Api2] as $Api) {
      $balances = $Api->getBalance('BTC', $alt_to_refresh);
        $Api->balances = array_merge($Api->balances, $balances);
    }
    $btc_cash_roll = $Api1->balances['BTC'] + $Api2->balances['BTC'];
  }catch (Exception $e)
  {
    print "failed to get balances\n";
  }

  print "~~~~api calls: ".($Api1->nApicalls + $Api2->nApicalls)."~~~~~\n\n";
  print "~~~~cumulated profit: $profit BTC~~~~~\n\n";
  print "~~~~{$Api2->name}:{$Api2->balances['BTC']}BTC  {$Api1->name}:{$Api1->balances['BTC']}BTC~~~~\n\n";
  print "~~~~Cash roll: $btc_cash_roll BTC, GAIN=".($btc_cash_roll-$btc_start_cash)."BTC~~~~\n\n";
}
