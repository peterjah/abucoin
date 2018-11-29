<?php

require_once('../common/tools.php');

$Api1 = getMarket($argv[1]);
$Api2 = getMarket($argv[2]);

$profit = 0;

$altcoins_list = findCommonProducts($Api1,$Api2);
$crypto_list = $altcoins_list;
$crypto_list[] = 'BTC';
foreach($crypto_list as $crypto)
  $Api1->balances[$crypto] = $Api2->balances[$crypto] = 0;

print "retrieve balances\n";
while(true) { try {
    $Api1->getBalance();
    $Api2->getBalance();
    break;
  } catch (Exception $e) {}
}


  foreach( $altcoins_list as $alt) {
    print "create $alt order books \n";
    while(true) { try {
        $market1[$alt] = new Market($Api1, $alt);
        $market2[$alt] = new Market($Api2, $alt);
        break;
      } catch (Exception $e) {print "{$e->getMessage()}\n";}
    }
}

$btc_start_cash = $Api1->balances['BTC'] + $Api2->balances['BTC'];

@define('BUY_TRESHOLD', 0.000001);
@define('CRITICAL_BUY_TRESHOLD', -0.000005);
@define('CRITICAL_BUY_TRESHOLD2', -0.00001);

$nLoops = 0;
while(true) {
  foreach( $altcoins_list as $alt) {
    print "Testing $alt trade\n";
    try {
      $profit += testSwap($alt, $market1[$alt], $market2[$alt]);
    }
    catch (Exception $e)
    {
      print $e;
      //refresh balances
      sleep(3);
      try {
        $Api1->getBalance();
        $Api2->getBalance();
      }catch (Exception $e){}
    }
    try {
      $profit += testSwap($alt, $market2[$alt], $market1[$alt]);
    }
    catch (Exception $e) {
      print $e;
      //refresh balances
      sleep(3);
      if($e->getMessage() == 'Rest API trading is not enabled.')
      {
        sleep(3600);//exchange maintenance ?
        break;
      }
      try {
        $Api1->getBalance();
        $Api2->getBalance();
      }catch (Exception $e){}
    }
  }

  if($nLoops == PHP_INT_MAX)
    $nLoops=0;
  else
    $nLoops++;

  if( ($nLoops % 10) == 0) {
    //ping api
    try {
      while($Api1->ping() === false) {
        print "Failed to ping {$Api1->name} api. Sleeping...\n";
        sleep(30);
      }
      while($Api2->ping() === false) {
        print "Failed to ping {$Api2->name} api. Sleeping...\n";
        sleep(30);
      }
    }catch (Exception $e){}

    print "Refreshing balances\n";
    try {$Api1->getBalance();}
      catch (Exception $e){}
    try {$Api2->getBalance();}
      catch (Exception $e){}
  }

  $btc_cash_roll = $Api1->balances['BTC'] + $Api2->balances['BTC'];
  print "~~~~ ".date("Y-m-d H:i:s")." ~~~~~\n\n";
  print "~~~~cumulated profit: $profit BTC~~~~~\n\n";
  print "~~~~{$Api2->name}:{$Api2->balances['BTC']}BTC  {$Api1->name}:{$Api1->balances['BTC']}BTC~~~~\n\n";
  print "~~~~Cash roll: $btc_cash_roll BTC, GAIN=".($btc_cash_roll-$btc_start_cash)."BTC~~~~\n\n";
}

function testSwap($alt, $buy_market, $sell_market)
{
  $profit = 0;
  $tradeSize = 1; //dummy init
  while($tradeSize > 0) {
    $btc_cash_roll = $buy_market->api->balances['BTC'] + $sell_market->api->balances['BTC'];
    $get_btc_market = $buy_market->api->balances['BTC'] > $sell_market->api->balances['BTC'];
    $get_btc_market_critical = $btc_cash_roll > 0.001 ? $sell_market->api->balances['BTC'] < $btc_cash_roll * 0.1 /*10% of cashroll*/: false;

    $min_order_btc = max($buy_market->product->min_order_size_btc, $sell_market->product->min_order_size_btc);
    $min_order_alt = max($buy_market->product->min_order_size_alt, $sell_market->product->min_order_size_alt);

    if( ($alt_bal=$sell_market->api->balances[$alt]) < $min_order_alt)
      break;

    if( ($btc_bal=$buy_market->api->balances['BTC']) < $min_order_btc)
      break;

    $buy_book = $buy_market->refreshBook($min_order_btc,$min_order_alt);
    $sell_book = $sell_market->refreshBook($min_order_btc,$min_order_alt);

    $sell_price = $sell_book['bids']['price'];
    $buy_price = $buy_book['asks']['price'];
    $tradeSize = min($sell_book['bids']['size'], $buy_book['asks']['size']);
    $buy_fees = $buy_market->product->fees;
    $sell_fees = $sell_market->product->fees;

    $expected_gains = computeGains($buy_price, $buy_fees, $sell_price, $sell_fees, $tradeSize);

    // if ($get_btc_market_critical) {
    //   $half_cash = $btc_cash_roll / 2;
    //   //dynamic treshold depending on tradesize
    //   $critical_tresh = CRITICAL_BUY_TRESHOLD2 + (CRITICAL_BUY_TRESHOLD2 * $tradeSize * $sell_price / $half_cash);
    //   if ($expected_gains['btc'] >= $critical_tresh) {
    //     print_dbg("expected_gains['btc']: {$expected_gains['btc']} critical_tresh = $critical_tresh refill (1=half) = ".$tradeSize * $sell_price / $half_cash);
    //     print_dbg("Do it !!!!");
    //   }
    // }

    //swap conditions
    if ($expected_gains['btc'] > BUY_TRESHOLD ||
       ($get_btc_market_critical && ($expected_gains['btc'] >= CRITICAL_BUY_TRESHOLD)) ||
       ($get_btc_market && $expected_gains['btc'] >= 0) ) {

      $buy_market->api->getBalance();
      $sell_market->api->getBalance();

      if ($get_btc_market_critical) {
        //print_dbg("Do ".($get_btc_market_critical? "critical":'') ." swap! expected win {$expected_gains['btc']} BTC {$expected_gains['percent']}%");
        $half_cash = $btc_cash_roll / 2;
        if ((($tradeSize * $sell_price) > $half_cash) && $expected_gains['btc'] < 0) {
          $new_tradeSize = $half_cash > $min_order_alt ? $half_cash : $min_order_alt;
          //print_dbg("reducing tradesize from $tradeSize to $new_tradeSize");
          $tradeSize = $new_tradeSize;
        }
      }



      print "do arbitrage for $alt. estimated gain: {$expected_gains['percent']}%";
      $status = do_arbitrage($alt, $sell_market, $sell_book['bids']['order_price'], $buy_market, $buy_book['asks']['order_price'], $tradeSize);
      if ($status['buy']['filled_size'] > 0 && $status['sell']['filled_size'] > 0) {

        if ($status['buy']['filled_size'] != $status['sell']['filled_size'])
          print_dbg("Different tradesizes buy:{$status['buy']['filled_size']} != sell:{$status['sell']['filled_size']}");

        $tradeSize = min($status['buy']['filled_size'] , $status['sell']['filled_size']);
        $final_gains = computeGains($status['buy']['price'], $buy_fees, $status['sell']['price'], $sell_fees, $tradeSize);
        $profit += $final_gains['btc'];
        print("log tx\n");
        $trade_str = date("Y-m-d H:i:s").": {$final_gains['btc']} BTC {$expected_gains['percent']}% ({$final_gains['percent']}%)\n";
        file_put_contents('gains',$trade_str,FILE_APPEND);

        //Just in case
        $buy_market->api->balances[$alt] += $status['buy']['filled_size'];
        $buy_market->api->balances['BTC'] -= $tradeSize * $status['sell']['price'];
        $sell_market->api->balances['BTC'] += $tradeSize * $status['buy']['price'];
        $sell_market->api->balances[$alt] -= $status['sell']['filled_size'];
      }
      else
        $tradeSize = 0;
    }
    else
        $tradeSize = 0;
  }
  return $profit;
}
