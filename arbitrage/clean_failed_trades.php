<?php
require_once('../common/tools.php');

//Todo: add an option to import failed trade in a new trade file.

// Todo: use getopt ....$options = getopt('s:l', array(
//   'solve:',
//   'login:',

//Todo: use a database !

@define('FILE',"trades");

if (@$argv[1] == '-solve' && isset($argv[2])) {
  $ret = str_replace($argv[2], 'solved', file_get_contents(FILE), $count);
  if($count > 0) {
    file_put_contents(FILE, $ret);
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
  $handle = fopen(FILE, "r");
  $ledger = [];
  if ($handle) {
    while (($line = fgets($handle)) !== false) {
      preg_match('/^(\d+-\d+-\d+ \d+:\d+:\d+): arbitrage: (.*) ([a-zA-Z]+): trade (.*): ([a-z]+) (\d+|\d+\.\d+) ([A-Z]+) at (\d+|\d+\.\d+E?-?\d+?) ?([A-Z]+)?$/',$line, $matches);

      if(count($matches) == 10) {
        $date = $matches[1];
        $OpId = $matches[2];
        $exchange = strtolower($matches[3]);
        $trade_id = $matches[4];
        $side = $matches[5];
        $size = floatval($matches[6]);
        $alt = $matches[7];
        $price = $matches[8];
        $base = isset($matches[9]) ? $matches[9] : 'BTC';
        $symbol = "{$alt}-{$base}";

        if($OpId != 'solved') {
          if( !isset($ledger[$symbol][$OpId]) ) {
            $ledger[$symbol][$OpId] = ['date' => $date,
                                            'side' =>$side,
                                            'size' =>$size,
                                            'price' =>$price,
                                            'id' => $trade_id,
                                            'exchange' => $exchange,
                                            'line' => $line
                                            ];
          }
          elseif (isset($ledger[$symbol]["{$OpId}_2"]) && $ledger[$symbol]["{$OpId}_2"]['size'] == $size) {
            unset($ledger[$symbol]["{$OpId}_2"]);
          }
          else {
            if($ledger[$symbol][$OpId]['size'] != $size) {
              //print "Different size trade: {$ledger[$symbol][$OpId]['side']} {$ledger[$symbol][$OpId]['size']} != $side $size\n";
              if($ledger[$symbol][$OpId]['size'] < $size) {
                 $ledger[$symbol][$OpId]['side'] = $side;
                 $ledger[$symbol][$OpId]['exchange'] = $exchange;
                 $ledger[$symbol][$OpId]['price'] = $price;
              }
              $ledger[$symbol][$OpId]['size'] = abs($ledger[$symbol][$OpId]['size'] - $size);

              $new_line = "$date: arbitrage: $OpId {$ledger[$symbol][$OpId]['exchange']}: trade $trade_id: {$ledger[$symbol][$OpId]['side']} "
                           ."{$ledger[$symbol][$OpId]['size']} $alt at {$ledger[$symbol][$OpId]['price']}\n";
              $ledger[$symbol][$OpId]['line'] = $new_line;
            }
            else
              unset($ledger[$symbol][$OpId]);
          }
        }
      }
    }
    fclose($handle);
  }
if(@$autoSolve) {
  //init api
  $markets = [];
  foreach( ['binance','kraken','cobinhood','cryptopia'] as $name) {
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
foreach($ledger as $symbol => $trades) {
  $mean_sell_price = $sell_size = 0;
  $mean_buy_price = $buy_size = 0;
  $balance = 0;
  if(count($trades)) {
    print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
    $traded = getFailedTrades(@$markets, $symbol, $trades);
    foreach (['buy','sell'] as $side ) {
      if (($size = @$traded[$side]['size']) > 0) {
        @$balance += $side == 'buy' ? $size : -1 * $size;
        print "$side: size= {$size} price= {$traded[$side]['price']} mean_fee= {$traded[$side]['mean_fees']}\n";
        if (@$autoSolve) {
          do_solve($markets, $symbol, $side, $traded[$side]);
        }
      }
    }
    print("balance: $balance\n");
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
    if(!isset($market->products[$symbol]))
      continue;
    $product = $market->products[$symbol];
    $alt_bal = $api->balances[$product->alt];
    $base_bal = $api->balances[$product->base];

    $book = $market->refreshBook($product, 0, $size);

    if ($side == 'buy') {
      $book = $book['bids'];
      $action = 'sell';
      $expected_gains = computeGains($traded['price'], $traded['mean_fees'], $book['price'], $product->fees, $size);
      if ($alt_bal < $size)
        continue;
    }
    else {
      $action = 'buy';
      $book = $book['asks'];
      $expected_gains = computeGains($book['price'], $product->fees, $traded['price'], $traded['mean_fees'], $size);
      if ($base_bal < $size * $book['price'])
        continue;
    }
    print "try with {$market->api->name}... price: {$book['price']} {$product->base}\n";
    if($size < $product->min_order_size )
      continue;
    if($size * $book['price'] < $product->min_order_size_base)
      continue;

    if($expected_gains['base'] > 0) {
      $i=0;
      while($i<6) {
        try {
          print_dbg("Trade cleaner: $action $size $product->alt @ {$book['price']} $product->base");
          $status = $api->place_order($product, 'market', $action, $book['order_price'], $size, 'solved');
          break;
        } catch(Exception $e) {
          print "{$api->name}: Unable to $action :  $e \n";
          usleep(500000);
          $i++;
        }
      }
      if ($status['filled_size'] > 0) {
        foreach($traded['ids'] as $id ) {
          file_put_contents(FILE, str_replace($id, 'solved', file_get_contents(FILE)));
        }
        if ($action == 'buy') {
          $gains = computeGains( $status['price'], $product->fees, $traded['price'], $traded['mean_fees'], $status['filled_size']);
        } else {
          $gains = computeGains( $traded['price'], $traded['mean_fees'], $status['price'], $product->fees, $status['filled_size']);
        }
        print_dbg("solved on $api->name: size:{$status['filled_size']} $product->alt, mean_price:{$traded['price']}, mean_fees:{$traded['mean_fees']}, price:{$status['price']} $product->base");
        $trade_str = date("Y-m-d H:i:s").": {$gains['base']} $product->base {$expected_gains['percent']}% ({$gains['percent']}%)\n";
        file_put_contents('gains',$trade_str,FILE_APPEND);
        $market->api->getBalance();
        break;
      }
    }
  }
}

function getFailedTrades($markets, $symbol, $ops)
{
  $ret = [];
  foreach (['buy', 'sell'] as $side) {
    $ret[$side]['size'] = 0;
    $ret[$side]['price'] = 0;
    $ret[$side]['mean_fees'] = 0;
    foreach($ops as $id => $op) {
      if ($op['side'] != $side)
        continue;
      $market = $markets[$op['exchange']];
      $product = @$market->products[$symbol];
      $ret[$side]['price'] = ($ret[$side]['price'] * $ret[$side]['size'] + $op['price'] * $op['size']) / ( $ret[$side]['size'] + $op['size'] );
      $ret[$side]['mean_fees'] = ($ret[$side]['mean_fees'] * $ret[$side]['size'] + @$product->fees*$op['size']) / ($ret[$side]['size'] + $op['size']);
      $ret[$side]['size'] += $op['size'];
      $ret[$side]['ids'][] = $id;
      print($op['line']);
    }
  }
  return $ret;
}
