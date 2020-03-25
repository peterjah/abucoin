<?php

require_once('../common/tools.php');

declare(ticks = 1);
function sig_handler($sig) {
  global $sig_stop;
    switch($sig) {
        case SIGINT:
        case SIGTERM:
          print_dbg("signal $sig catched! Exiting...", true);
          $sig_stop = true;
    }
}
pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
$sig_stop = false;

@define('GAINS_FILE', 'gains.json');
if(!file_exists(GAINS_FILE))
  touch(GAINS_FILE);

@define('BUY_TRESHOLD', 0.01); //percent

$market1 = new Market($argv[1]);
$market2 = new Market($argv[2]);

$profits = [];

$symbol_list = getCommonProducts($market1, $market2);

$use_websocket = true;

foreach ([$market1, $market2] as $market) {
  while (true) { try {
      if ($use_websocket) {
        subscribeWsOrderBook($market, $symbol_list, getmypid());
      }
      print "retrieve {$market->api->name} balances\n";
      $market->getBalance();
      break;
    } catch (Exception $e) {}
  }
}

$btc_start_cash = $market1->api->balances['BTC'] + $market2->api->balances['BTC'];

$last_update = time();
$loop_begin = microtime(true);
while(true) {
  if (!$sig_stop) {
    foreach( $symbol_list as $symbol) {
      if ($sig_stop) {
        break;
      }
      print "Testing $symbol trade\n";
      $product1 = $market1->products[$symbol];
      $product2 = $market2->products[$symbol];
      $alt = $product1->alt;
      $base = $product1->base;
      $min_order_size_base = max($product1->min_order_size_base, $product2->min_order_size_base);
      $min_order_size_alt = max($product1->min_order_size, $product2->min_order_size);
      try {
        while (true) {
          $status = [];
          if ($market2->api->balances[$alt] > $min_order_size_alt && $market1->api->balances[$base] > $min_order_size_base) {
            $status = testSwap($symbol, $market1/*buy*/, $market2/*sell*/);
          }
          if(empty($status) || $status['final_gains']['base'] <= 0) {
            break;
          }
          else {
            print "second book refresh\n";
            $profits[$base] += $status['final_gains']['base'];
          }
        }
      }
      catch (Exception $e)
      {
        $err = $e->getMessage();
        print "$err\n";
        if (!strpos($err, 'failed to get order book')) {
          print_dbg($err, true);
        }
        try {
          $market1->getBalance();
          $market2->getBalance();
        }catch (Exception $e){}
      }
      try {
        while (true) {
          $status = [];
          if ($market1->api->balances[$alt] > $min_order_size_alt && $market2->api->balances[$base] > $min_order_size_base) {
            $status = testSwap($symbol, $market2/*buy*/, $market1/*sell*/);
          }
          if(empty($status) || $status['final_gains']['base'] <= 0) {
            break;
          }
          else {
            $profits[$base] += $status['final_gains']['base'];
          }
        }
      }
      catch (Exception $e) {
        $err = $e->getMessage();
        print "$err\n";
        if (!strpos($err, 'failed to get order book')) {
          print_dbg($err, true);
        }
        if($e->getMessage() == 'Rest API trading is not enabled.')
        {
          sleep(3600);//exchange maintenance ?
          break;
        }
        try {
          $market1->getBalance();
          $market2->getBalance();
        }catch (Exception $e){}
      }
    }
  } else { //Quit !
    foreach ([$market1, $market2] as $market) {
      $orderbook_file = $market->api->orderbook_file;
      //Should be useless
      if (isset($orderbook_file))
        unlink($orderbook_file);
    }
    exit();
  }

  $btc_cash_roll = $market1->api->balances['BTC'] + $market2->api->balances['BTC'];
  print "~~~~ ".date("Y-m-d H:i:s")." ~~~~~\n\n";
  foreach($profits as $base => $profit) {
    print "~~~~cumulated gain: $profit $base~~~~~\n\n";
  }
  print "~~~~{$market2->api->name}:{$market2->api->balances['BTC']}BTC  {$market1->api->name}:{$market1->api->balances['BTC']}BTC~~~~\n\n";
  print "~~~~Cash roll: $btc_cash_roll BTC ~~~~\n\n";
  print "~~~~Websocket: {$market1->api->name}:" . ($market1->api->using_websockets ? 'yes' : 'no')."~~~~\n";
  print "~~~~Websocket: {$market2->api->name}:" . ($market2->api->using_websockets ? 'yes' : 'no')."~~~~\n\n";
  print "~~~~Api call stats: {$market2->api->name}: {$market2->api->api_calls_rate}/min , {$market1->api->name}: {$market1->api->api_calls_rate}/min~~~~\n\n";

  //avoid useless cpu usage
  $loop_time = microtime(true) - $loop_begin;
  $min_loop_time = 0.1;//sec
  if( $loop_time < $min_loop_time) {
    usleep(($min_loop_time-$loop_time)*1000000);
  }
  $loop_begin = microtime(true);

  if( time() - $last_update > 60/*sec*/) {
    try {
      foreach([$market1, $market2] as $market) {
        while($market->api->ping() === false) {
          print "Failed to ping {$market->api->name} api. Sleeping...\n";
          sleep(30);
        }
        $market->getBalance();
        //refresh product infos
        $market->updateProductList();
      }
    } catch (Exception $e){}
    $last_update = time();

    foreach ([$market1, $market2] as $market) {
      if($market->api instanceof KrakenApi) {
        print "Renew kraken websocket auth token\n";
        $market->api->renewWebsocketToken();
      }
    }
  }
}

function testSwap($symbol, $buy_market, $sell_market)
{
  $arbitrage_logs = [];
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];
  $alt = $buy_product->alt;
  $base = $sell_product->base;
  $base_cash_roll = $buy_market->api->balances[$base] + $sell_market->api->balances[$base];
  $get_base_market = $sell_market->api->balances[$base] * 10 < $base_cash_roll;

  $buy_fees = $buy_product->fees;
  $sell_fees = $sell_product->fees;

  $min_trade_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
  $min_trade_alt = max($buy_product->min_order_size, $sell_product->min_order_size);

  $buy_book = $buy_product->refreshBook('buy', $min_trade_base, $min_trade_alt, false);
  $sell_book = $sell_product->refreshBook('sell', $min_trade_base, $min_trade_alt, false);

  if (!$buy_book || !$sell_book) {
    return [];
  }
  $trade_size = get_tradesize($symbol, $sell_market, $sell_book, $buy_market, $buy_book);

  if ($trade_size > 0) {
    $sell_price = $sell_book['bids']['price'];
    $sell_order_price = truncate($sell_book['bids']['order_price'], $sell_product->price_decimals);
    $buy_price = $buy_book['asks']['price'];
    $buy_order_price = truncate($buy_book['asks']['order_price'], $buy_product->price_decimals);

    $expected_gains = computeGains($buy_price, $buy_fees, $sell_price, $sell_fees, $trade_size);
    //swap conditions
    $do_swap = false;
    if ($expected_gains['percent'] > BUY_TRESHOLD ||
       ($get_base_market && ($expected_gains['base'] >= 0)) ) {
         $do_swap = true;
    }

    if ($do_swap) {
      $buy_market->getBalance();
      $sell_market->getBalance();
      $arbId = substr($sell_market->api->name, 0, 2) . substr($buy_market->api->name, 0, 2) . '_' . number_format(microtime(true) * 100, 0, '.', '');
      print_dbg("\n Arbitrage for {$symbol}. estimated gain: ".number_format($expected_gains['percent'], 3)."%");
      print_dbg("SELL $trade_size $alt on {$sell_market->api->name} at $sell_price, orderPrice: $sell_order_price", true);
      print_dbg("BUY $trade_size $alt on {$buy_market->api->name} at $buy_price, orderPrice: $buy_order_price", true);
      $status = async_arbitrage($symbol, $sell_market, $sell_order_price, $buy_market, $buy_order_price, $trade_size, $arbId);
      $filled_buy = $status['buy']['filled_size'];
      $filled_sell = $status['sell']['filled_size'];
      if ($filled_buy > 0 && $filled_sell > 0) {
        if ($filled_buy != $filled_sell)
          print_dbg("Different tradesizes buy:{$filled_buy} != sell:{$filled_sell}");

        $trade_size = min($filled_buy , $filled_sell);
        $final_gains = computeGains($status['buy']['price'], $buy_fees, $status['sell']['price'], $sell_fees, $trade_size);
        $profit += $final_gains['base'];

        $stats = ['buy_price_diff' => ($status['buy']['price'] * 100 / $buy_price) - 100,
                  'sell_price_diff' => ($status['sell']['price'] * 100 / $sell_price) - 100
                  ];
        $arbitrage_logs = [ 'date' => date("Y-m-d H:i:s"),
                        'alt' => $alt,
                        'base' => $base,
                        'id' => $arbId,
                        'expected_gains' => $expected_gains,
                        'final_gains' => $final_gains,
                        'sell_market' => $sell_market->api->name,
                        'buy_market' => $buy_market->api->name,
                        'stats' => $stats
                      ];
        $fp = fopen(GAINS_FILE, "r");
        flock($fp, LOCK_SH, $wouldblock);
        $gains_logs = json_decode(file_get_contents(GAINS_FILE), true);
        flock($fp, LOCK_UN);
        fclose($fp);
        $gains_logs['arbitrages'][] = $arbitrage_logs;
        file_put_contents(GAINS_FILE, json_encode($gains_logs), LOCK_EX);

        //Just in case
        $buy_market->api->balances[$alt] += $filled_buy;
        $buy_market->api->balances[$base] -= $trade_size * $status['sell']['price'];
        $sell_market->api->balances[$base] += $trade_size * $status['buy']['price'];
        $sell_market->api->balances[$alt] -= $filled_sell;
      }
      else {
        print_dbg("Arbitrage $arbId failed...", true);
      }
    }
  }
  return $arbitrage_logs;
}
