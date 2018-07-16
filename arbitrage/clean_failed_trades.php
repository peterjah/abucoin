<?php
require_once('../common/tools.php');

@define('FILE',"trades");

if (@$argv[1] == '-solve' && isset($argv[2])) {
  $ret = str_replace($argv[2], 'solved', file_get_contents(FILE), $count);
  if($count > 0)
  {
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

while(1)
{
  $handle = fopen(FILE, "r");

  $legder = [];

  if ($handle) {
    while (($line = fgets($handle)) !== false) {
        preg_match('/^(\d+-\d+-\d+ \d+:\d+:\d+): arbitrage: (.*) ([a-zA-Z]+): trade (.*): ([a-z]+) (\d+|\d+\.\d+) ([A-Z]+) at (\d+|\d+\.\d+E?-?\d+?)$/',$line, $matches);

        if(count($matches) == 9)
        {
          $date = $matches[1];
          $OpId = $matches[2];
          $exchange = strtolower($matches[3]);
          $trade_id = $matches[4];
          $side = $matches[5];
          $size = floatval($matches[6]);
          $alt = $matches[7];
          $price = $matches[8];

          if( !isset($legder[$alt]['balance']))
            $legder[$alt]['balance'] = 0;
          if($OpId != 'solved') {
            if( !isset($legder[$alt]['ops'][$OpId]) )
            {
              $legder[$alt]['ops'][$OpId] = ['date' => $date,
                                              'side' =>$side,
                                              'size' =>$size,
                                              'price' =>$price,
                                              'id' => $trade_id,
                                              'exchange' => $exchange,
                                              'line' => $line
                                              ];
            }
            elseif (isset($legder[$alt]['ops']["{$OpId}_2"]) && $legder[$alt]['ops']["{$OpId}_2"]['size'] == $size)
            {
              unset($legder[$alt]['ops']["{$OpId}_2"]);
            }
            else
            {
              if($legder[$alt]['ops'][$OpId]['size'] != $size) {
                print "Tx id conflict:\n $line";
                $legder[$alt]['ops']["{$OpId}_2"] = ['date' => $date,
                                                'side' =>$side,
                                                'size' =>$size,
                                                'price' =>$price,
                                                'id' => $trade_id,
                                                'exchange' => $exchange,
                                                'line' => $line
                                                ];
              }
              else
                unset($legder[$alt]['ops'][$OpId]);
            }

            //update ledger balance
            $legder[$alt]['balance'] += $side == 'buy' ? $size : -1 * $size;
          }
        }
    }
    fclose($handle);
  }

  foreach($legder as $alt => $altOps) {
    $mean_sell_price = $sell_size = 0;
    $mean_buy_price = $buy_size = 0;
    if(count($altOps['ops']))
    {
      print "$$$$$$$$$$$$$$$$$$ $alt $$$$$$$$$$$$$$$$$$\n";
      foreach($altOps['ops'] as $id => $ops) {
        $ops['TxId'] = $id;
        print "{$ops['line']}";
        if( $ops['side'] == 'sell') {
          $mean_sell_price = ($mean_sell_price * $sell_size + $ops['price'] * $ops['size']) / ( $sell_size + $ops['size'] );
          $sell_size += $ops['size'];
        }
        else {
          $mean_buy_price = ($mean_buy_price * $buy_size + $ops['price'] * $ops['size']) / ( $buy_size + $ops['size'] );
          $buy_size += $ops['size'];
        }
      }
      $trade_success = false;
      if($buy_size) {
        print "mean_buy_price= $mean_buy_price: buy_size= $buy_size\n";
        if(@$autoSolve) {
          print "trying to solve tx..\n";
          foreach( ['binance','kraken','cobinhood','cryptopia'] as $exchange) {
            $Api = getMarket($exchange);
            try {
              if($Api->getBalance($alt) < $buy_size)
                continue;
              $orderBook = new OrderBook($Api, $alt);
              $book = $orderBook->refreshBook(0,$buy_size);
              $Api->products[$alt] = $orderBook->product;
            }catch(Exception $e) {continue;}
            if($book['bids']['price'] > $mean_buy_price)
            {
              var_dump($book['bids']);
              $i=0;
              while($i<6) {
                try{
                  $Api->place_order('market', $alt, 'sell', $book['bids']['order_price'], $buy_size, 'solved');
                  foreach($altOps['ops'] as $id => $ops) {
                    if ($ops['side'] == 'buy')
                      file_put_contents(FILE, str_replace($id, 'solved', file_get_contents(FILE)));
                  }
                  $trade_success = true;
                  break;
                } catch(Exception $e) {
                  print "failed to sell: $e \n";
                  usleep(50000);
                  $i++;
                }
              }
              if($trade_success)
                break;
            }
          }
        }
      }
      $trade_success = false;
      if($sell_size) {
        print "mean_sell_price= $mean_sell_price: sell_size= $sell_size\n";
        if(@$autoSolve) {
          print "trying to solve tx..\n";
          foreach( ['binance','kraken','cobinhood','cryptopia'] as $exchange) {
            $Api = getMarket($exchange);
            try {
              if($Api->getBalance('BTC') < $sell_size * $book['asks']['order_price'])
               continue;
               $orderBook = new OrderBook($Api, $alt);
               $book = $orderBook->refreshBook(0,$sell_size);
               $Api->products[$alt] = $orderBook->product;
            }catch(Exception $e) {continue;}
            print " {$Api->name} asks order price: {$book['asks']['order_price']}";
            if($book['asks']['price'] < $mean_sell_price)
            {
              var_dump($book['asks']);
              $i=0;
              while($i<6) {
                try{
                  print "place order price: {$book['asks']['order_price']}";
                  $Api->place_order('market', $alt, 'buy', $book['asks']['order_price'], $sell_size, 'solved');
                  foreach($altOps['ops'] as $id => $ops) {
                    if ($ops['side'] == 'sell')
                      file_put_contents(FILE, str_replace($id, 'solved', file_get_contents(FILE)));
                    }
                  $trade_success = true;
                  break;
                } catch(Exception $e) {
                  print "failed to buy: $e \n";
                usleep(50000);
                $i++;
                }
              }
              if($trade_success)
                break;
            }
          }
        }
      }

      if($altOps['balance'] != 0)
        var_dump($altOps['balance']);
    }
  }

if(@$autoSolve)
  sleep(3600*2);
else
  break;
}
