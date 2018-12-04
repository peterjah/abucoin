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
              print "Different size trade: {$ledger[$symbol][$OpId]['side']} {$ledger[$symbol][$OpId]['size']} != $side $size\n";
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
//var_dump($ledger);
  foreach($ledger as $symbol => $trades) {
    $mean_sell_price = $sell_size = 0;
    $mean_buy_price = $buy_size = 0;
    $balance = 0;
    if(count($trades)) {
      $traded = getFailedTrades($symbol, $trades);
      print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
      foreach (['buy','sell'] as $side ) {
        if (($size = @$traded[$side]['size']) > 0) {
          @$balance += $side == 'buy' ? $size : -1 * $size;
          print "$side: size= {$size} price= {$traded[$side]['price']} mean_price= {$traded[$side]['mean_fees']}\n";
          if (@$autoSolve) {
            do_solve($symbol, $trades, $side, $traded[$side]);
          }
        }
      }
      print("balance: $balance\n");

    }
  }

if(@$autoSolve)
  sleep(3600);
else
  break;
}

function do_solve($symbol, $side, $ops, $traded)
{
  print "mean_buy_fee= {$traded['mean_fees']}\n";

  print "trying to solve tx..\n";
  $size = $traded['size'];
  foreach( ['binance','kraken','cobinhood','cryptopia'] as $exchange) {
    print "$exchange..\n";
    try {
      $market = new Market($exchange);
      $api = $market->api;
      $product = $market->products[$symbol];
      $alt_bal = $market->getBalance($alt);
      if ($alt_bal < $size)
        continue;
      $book = $market->refreshBook($product, 0, $size);
      if ($side == 'buy')
        $book = $book['bids'];
      else
        $book = $book['asks'];
      if($size < $product->min_order_size )
        continue;
      if($size * $book['price'] < $product->min_order_size_base)
        continue;
    } catch(Exception $e) {continue;}

    if(isset($book) && $book['price'] > $traded['price'])
    {
      var_dump($book);
      $action = $side == 'buy' ? 'sell' : 'buy';
      $i=0;
      while($i<6) {
        try{
          $status = $api->place_order('market', $alt, $action, $book['order_price'], $size, 'solved');
          foreach($ops as $id => $ops) {
            if ($ops['side'] == $side)
              file_put_contents(FILE, str_replace($id, 'solved', file_get_contents(FILE)));
          }
          $gains = computeGains( $traded['price'], $traded['mean_fees'], $status['price'], $product->fees, $size);
          print_dbg("solved on $api->name: buy_size:{$size} $alt, mean_buy_price:{$traded['price']}, mean_fees:{$traded['mean_fees']}, price:{$status['price']}");

          $trade_str = date("Y-m-d H:i:s").": {$gains['btc']} $product->base {$gains['percent']}%\n";
          file_put_contents('gains',$trade_str,FILE_APPEND);
          break;
        } catch(Exception $e) {
          print "failed to sell: $e \n";
          usleep(500000);
          $i++;
        }
      }
    }
  }
}

function getFailedTrades($symbol, $ops) {
  $size = 0;
  $mean_price = 0;
  $mean_fees = 0;
  $ret = [];
  foreach (['buy', 'sell'] as $side) {
    foreach($ops as $op) {
      if ($op['side'] != $side)
        continue;
      $i=0;
      while($i<6) {
        try {
          $market = new Market(strtolower($op['exchange']));
          $product = $market->products[$symbol];
          break;
        } catch(Exception $e) {
          usleep(500000);
          $i++;
        }
      }
      $ret[$side]['price'] = ($mean_price * $size + $op['price'] * $op['size']) / ( $size + $op['size'] );
      $ret[$side]['mean_fees'] = ($mean_fees*$size + $product->fees*$op['size']) / ($size + $op['size']);
      @$ret[$side]['size'] += $op['size'];
      print($op['line']);
    }
  }
  return $ret;
}
