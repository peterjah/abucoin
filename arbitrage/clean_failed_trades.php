<?php
require_once('../common/tools.php');

//Todo: add an option to import failed trade in a new trade file.

// Todo: use getopt ....$options = getopt('s:l', array(
//   'solve:',
//   'login:',

//Todo: use a database !

@define('TRADES_FILE',"trades");
@define('GAINS_FILE', 'gains.json');

if (@$argv[1] == '-solve' && isset($argv[2])) {
  $ret = str_replace($argv[2], 'solved', file_get_contents(TRADES_FILE), $count);
  if($count > 0) {
    file_put_contents(TRADES_FILE, $ret);
    print "Tx $argv[2] marked as solved\n";
  }
  else {
    print "Tx $argv[2] not found or already solved\n";
  }
  exit();
}
if (@$argv[1] == '-auto-solve')
  $autoSolve=true;

while(1) {


if(@$autoSolve) {
  $ledger = parseTradeFile();
  print "$$$$$$$$$$$$$$$$$$ First pass $$$$$$$$$$$$$$$$$$\n";
  foreach($ledger as $symbol => $trades) {
    $mean_sell_price = $sell_size = 0;
    $mean_buy_price = $buy_size = 0;
    $balance = 0;
    if(count($trades)) {
      print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
      $traded = processFailedTrades(@$markets, $symbol, $trades);
      if (!empty($traded)) {
        firstPassSolve($traded);
      }
    }
  }
  //init api
  $markets = [];
  foreach( ['binance','kraken','cobinhood','paymium', 'cryptopia'] as $name) {
    $i=0;
    while($i<6) {
      try{
        $markets[$name] = new Market($name);
        $markets[$name]->api->getBalance();
        break;
      } catch(Exception $e) {
        print "failed to get market $name: $e \n";
        usleep(500000);
        $i++;
      }
    }
  }
}

print "$$$$$$$$$$$$$$$$$$ Second pass $$$$$$$$$$$$$$$$$$\n";
$ledger = parseTradeFile();
foreach($ledger as $symbol => $trades) {
  $mean_sell_price = $sell_size = 0;
  $mean_buy_price = $buy_size = 0;
  $balance = 0;
  if(count($trades)) {
    print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
    $traded = processFailedTrades(@$markets, $symbol, $trades);
    foreach (['buy','sell'] as $side ) {
      if (($size = @$traded[$side]['size']) > 0) {
        @$balance += $side == 'buy' ? $size : -1 * $size;
        print "$side: size= {$size} price= {$traded[$side]['price']} mean_fee= {$traded[$side]['mean_fees']}\n";
        if (@$autoSolve) {
          try {
            do_solve($markets, $symbol, $side, $traded[$side]);
          } catch (Exception $e) {
            print_dbg("failed to solve: {$e->getMessage()}", true);
          }
        }
      }
    }
  }
}

if(@$autoSolve)
  sleep(1800);
else
  break;
}

function do_solve($markets, $symbol, $side, $traded)
{
  print "trying to solve tx..\n";
  $size = $traded['size'];
  foreach($markets as $market) {
    $api = $market->api;
    print "try with {$api->name}\n";
    if(!isset($market->products[$symbol]))
      continue;

    $product = $market->products[$symbol];
    $alt_bal = $api->balances[$product->alt];
    $base_bal = $api->balances[$product->base];

    if($size < $product->min_order_size )
      continue;
    try {
      $book = $product->refreshBook(0, $size);
    } catch (Exception $e) {
      print_dbg("{$e->getMessage()}: continue..", true);
      continue;
    }
    if ($side == 'buy') {
      $price = $book['bids']['price'];
      $order_price = $book['bids']['order_price'];
      $action = 'sell';
      $expected_gains = computeGains($traded['price'], $traded['mean_fees'], $price, $product->fees, $size);
      if ($alt_bal < $size)
        continue;
    }
    else {
      $action = 'buy';
      $price = $book['asks']['price'];
      $order_price = $book['asks']['order_price'];
      $expected_gains = computeGains($price, $product->fees, $traded['price'], $traded['mean_fees'], $size);
      if ($base_bal < $size * $price)
        continue;
    }

    if($size * $price < $product->min_order_size_base)
      continue;

    if($expected_gains['base'] > 0) {
      $i=0;
      while ($i<6) {
        try {
          print_dbg("Trade cleaner: $action $size $product->alt @ {$price} $product->base");
          $status = $api->place_order($product, 'market', $action, $order_price, $size, 'solved');
          print_dbg("Trade cleaner: filled: {$status['filled_size']}");
          break;
        } catch(Exception $e) {
          print "{$api->name}: Unable to $action :  $e \n";
          usleep(500000);
          $i++;
        }
      }
      if (@$status['filled_size'] > 0) {
        markSolved(array_keys($traded['ids']));
        $arbitrage_log = [ 'date' => date("Y-m-d H:i:s"),
                       'alt' => $product->alt,
                       'base' => $product->base,
                       'id' => 'solved',
                       'expected_gains' => $expected_gains,
                     ];

        if ($action == 'buy') {
          $gains = computeGains( $status['price'], $product->fees, $traded['price'], $traded['mean_fees'], $status['filled_size']);
          $stats = ['buy_price_diff' => ($status['price'] * 100 / $price) - 100];
          $arbitrage_log['buy_market'] = $api->name;
        } else {
          $gains = computeGains( $traded['price'], $traded['mean_fees'], $status['price'], $product->fees, $status['filled_size']);
          $stats = ['sell_price_diff' => ($status['price'] * 100 / $price) - 100];
          $arbitrage_log['sell_market'] = $api->name;
        }
        $arbitrage_log['final_gains'] = $gains;
        $arbitrage_log['stats'] = $stats;
        print_dbg("solved on $api->name: size:{$status['filled_size']} $product->alt, mean_price:{$traded['price']}, mean_fees:{$traded['mean_fees']}, price:{$status['price']} $product->base");

        save_gain($arbitrage_log);

        $market->api->getBalance();
        break;
      }
    }
  }
}

function processFailedTrades($markets, $symbol, $ops)
{
  $ret = [];
  foreach (['buy', 'sell'] as $side) {
    $ret[$side]['size'] = 0;
    $ret[$side]['price'] = 0;
    $ret[$side]['mean_fees'] = 0;
    foreach($ops as $id => $op) {
      if ($op['side'] != $side)
        continue;
      if(isset($markets[$op['exchange']])){
        $market = $markets[$op['exchange']];
        $product = @$market->products[$symbol];
        $fees = @$product->fees;
      }

      $ret[$side]['price'] = ($ret[$side]['price'] * $ret[$side]['size'] + $op['price'] * $op['size']) / ( $ret[$side]['size'] + $op['size'] );
      $ret[$side]['mean_fees'] = ($ret[$side]['mean_fees'] * $ret[$side]['size'] + @$fees * $op['size']) / ($ret[$side]['size'] + $op['size']);
      $ret[$side]['size'] += $op['size'];
      $ret[$side]['ids'][$id] = $op;
      print("{$op['line']}\n");
    }
  }
  return $ret;
}

function firstPassSolve($traded)
{
  $buy = $traded['buy'];
  $sell = $traded['sell'];
  if ($buy['size'] > 0 && $sell['size'] > 0 && $buy['price'] < $sell['price']) {
    if($buy['size'] > $sell['size']) {
      $size = $sell['size'];
      $res_size = $buy['size'] - $sell['size'];
      $res_side = 'buy';
      $res_price = $buy['price'];
    } else {
      $size = $buy['size'];
      $res_size = $sell['size'] - $buy['size'];
      $res_side = 'sell';
      $res_price = $sell['price'];
    }
    firstPassSolveSide($size, $res_size, $res_side, $res_price, $traded);
  }
}

function firstPassSolveSide($size, $res_size, $res_side, $res_price, $traded)
{
  $buy = $traded['buy'];
  $sell = $traded['sell'];
  $gains = computeGains( $buy['price'], $buy['mean_fees'], $sell['price'], $sell['mean_fees'], $size);
  if($gains['base'] > 0) {
    //solve all trade
    markSolved(array_keys($buy['ids']));
    markSolved(array_keys($sell['ids']));
    //generate new trade to solve
    $op = array_values($buy['ids']);
    $alt = $op[0]['alt'];
    $base = $op[0]['base'];
    save_trade($alt, $base, $res_side, $res_size, $res_price);
    $arbitrage_log = [ 'date' => date("Y-m-d H:i:s"),
                   'alt' => $alt,
                   'base' => $base,
                   'id' => 'solved',
                   'expected_gains' => $gains,
                   'final_gains' => $gains,
                 ];
    if($res_side == 'buy')
      $arbitrage_log['buy_market'] = 'Trade Cleaner';
    else
      $arbitrage_log['sell_market'] = 'Trade Cleaner';
    print_dbg("first pass solved: size:$size $alt, gain:{$gains['base']} $base");
    save_gain($arbitrage_log);
  }
}

function markSolved($ids)
{
  foreach($ids as $id ) {
    print "mark $id solved\n";
    $fp = fopen(TRADES_FILE, "r");
    flock($fp, LOCK_SH, $wouldblock);
    $data = file_get_contents(TRADES_FILE);
    flock($fp, LOCK_UN);
    fclose($fp);
    file_put_contents(TRADES_FILE, str_replace($id, 'solved', $data), LOCK_EX);
  }
}

function save_trade($alt, $base, $side, $size, $price)
{
  $timestamp = intval(microtime(true)*1000);
  $trade_str = date("Y-m-d H:i:s").": arbitrage: toSolve_" . $timestamp ." cleaner: trade cleanerTx: $side $size $alt at $price $base\n";
  file_put_contents(TRADES_FILE, $trade_str,FILE_APPEND);
}

function save_gain($arbitrage_log)
{
  $fp = fopen(GAINS_FILE, "r");
  flock($fp, LOCK_SH, $wouldblock);
  $gains_logs = json_decode(file_get_contents(GAINS_FILE), true);
  flock($fp, LOCK_UN);
  fclose($fp);
  $gains_logs['arbitrages'][] = $arbitrage_log;
  file_put_contents(GAINS_FILE, json_encode($gains_logs), LOCK_EX);
}

function parseTradeFile()
{
  $handle = fopen(TRADES_FILE, "r");
  $ledger = [];
  if ($handle) {
    while (($line = fgets($handle)) !== false) {
      preg_match('/^(.*): arbitrage: (.*) ([a-zA-Z]+): trade (.*): ([a-z]+) ([.-E0-]+) ([A-Z]+) at ([.-E0-]+) ([A-Z]+)$/',$line, $matches);

      if(count($matches) == 10) {
        $date = $matches[1];
        $OpId = $matches[2];
        $exchange = strtolower($matches[3]);
        $trade_id = $matches[4];
        $side = $matches[5];
        $size = floatval($matches[6]);
        $alt = $matches[7];
        $price = $matches[8];
        $base = $matches[9];
        $symbol = "{$alt}-{$base}";

        if($OpId != 'solved') {
          if( !isset($ledger[$symbol][$OpId]) ) {
            $ledger[$symbol][$OpId] = ['date' => $date,
                                            'side' =>$side,
                                            'size' =>$size,
                                            'price' =>$price,
                                            'id' => $trade_id,
                                            'exchange' => $exchange,
                                            'line' => trim($line),
                                            'alt' => $alt,
                                            'base' => $base
                                            ];
          }
          elseif (isset($ledger[$symbol]["{$OpId}_2"]) && $ledger[$symbol]["{$OpId}_2"]['size'] == $size) {
            unset($ledger[$symbol]["{$OpId}_2"]);
          }
          else {
            if ($ledger[$symbol][$OpId]['size'] != $size) {
              //print "$symbol Different size trade: {$ledger[$symbol][$OpId]['side']} {$ledger[$symbol][$OpId]['size']} != $side $size\n";
              if($ledger[$symbol][$OpId]['size'] < $size) {
                 $ledger[$symbol][$OpId]['side'] = $side;
                 $ledger[$symbol][$OpId]['exchange'] = $exchange;
                 $ledger[$symbol][$OpId]['price'] = $price;
              }
              $ledger[$symbol][$OpId]['size'] = abs($ledger[$symbol][$OpId]['size'] - $size);

              $new_line = "$date: arbitrage: $OpId {$ledger[$symbol][$OpId]['exchange']}: trade $trade_id: {$ledger[$symbol][$OpId]['side']} "
                           ."{$ledger[$symbol][$OpId]['size']} $alt at {$ledger[$symbol][$OpId]['price']}";
              $ledger[$symbol][$OpId]['line'] = $new_line;
            }
            else
              unset($ledger[$symbol][$OpId]);
          }
        }
      } else {
        print "following line doesnt match the regex:\n";
        print "$line\n";
      }
    }
    fclose($handle);
  }
  return $ledger;
}
