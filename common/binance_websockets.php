<?php
use WebSocket\Client;
require_once('../common/tools.php');
@define('WSS_URL','wss://stream.binance.com:9443');

proc_nice(-10);

declare(ticks = 1);
function sig_handler($sig) {
  global $file;
    switch($sig) {
        case SIGINT:
        case SIGTERM:
          print_dbg("Binance WS: signal $sig catched! Exiting...", true);
          unlink($file);
          exit();
    }
}
pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");

$options =  getopt('', array(
   'file:',
   'cmd:',
   'products:',
   'bookdepth:'
 ));

if(!isset($options['cmd'])) {
  print_dbg("No websocket method provided",true);
}
if(!isset($options['file'])) {
  print_dbg("No output file provided",true);
}
$file = $options['file'];
$products = explode(',', $options['products']);

switch($options['cmd']) {
  case 'getOrderBook':
      while(true) {
      touch($file);
      print_dbg ("Subscribing Binance Orderbook WS feed", true);
      getOrderBook($products);
    }

}

function getOrderBook($products)
{
  $rest_api = new BinanceApi();
  global $file;
  global $options;

  $orderbook = [];
  $subscribe_str = '/stream?streams=';
  $app_symbols = [];
  foreach ($products as $product) {
    $alts = explode ('-', $product);
    $symbol = BinanceApi::translate2marketName($alts[0]) . BinanceApi::translate2marketName($alts[1]);
    $app_symbols[$symbol] = $product;
    $subscribe_str .= strtolower($symbol) . '@depth/';
  }
  $client = new Client(WSS_URL . $subscribe_str, ['timeout' => 60]);
  foreach ($app_symbols as $symbol => $app_symbol) {
    $snapshot = $rest_api->jsonRequest('GET', 'v1/depth', ['symbol' => $symbol, 'limit' => 1000]);
    $orderbook[$app_symbol] = $snapshot;
    $orderbook[$app_symbol]['isSnapshot'] = true;
  }
  file_put_contents($file, json_encode($orderbook), LOCK_EX);
  $date = DateTime::createFromFormat('U.u', microtime(TRUE));
  $date->add(new DateInterval('PT' . 5 . 'S'));

  $channel_ids = [];
  $sync = true;
  while (true) {
    try
    {
      $message = $client->receive();
      if ($message) {
        $msg = json_decode($message , true);
        //var_dump($msg);
        if (isset($msg['data'])) {
          switch ($msg['data']['e']) {
            case 'depthUpdate':
                $app_symbol = $app_symbols[$msg['data']['s']];
                $lastUpdateId = $orderbook[$app_symbol]['lastUpdateId'];
                if ( $msg['data']['u'] <= $lastUpdateId)
                  break;
                $u_1 = $lastUpdateId + 1;
                if ($msg['data']['U'] <= $u_1 && $msg['data']['u'] >= $u_1) {
                  $orderbook[$app_symbol]['isSnapshot'] = false;
                }
                if (!$orderbook[$app_symbol]['isSnapshot'] && $msg['data']['U'] != $u_1) {
                  $sync = false;
                  break;
                }
                $orderbook[$app_symbol]['lastUpdateId'] = $msg['data']['u'];

                foreach (['bids', 'asks'] as $side) {
                  $side_letter = substr($side,0,1);
                  if (isset($msg['data'][$side_letter])) {
                    foreach ($msg['data'][$side_letter] as $new_offer) {
                    //remove offer
                    $new_price = floatval($new_offer[0]);
                    if (floatval($new_offer[1]) == 0) {
                      foreach ($orderbook[$app_symbol][$side] as $key => $offer) {
                        if (floatval($offer[0]) != $new_price)
                          continue;
                        unset($orderbook[$app_symbol][$side][$key]);
                        break;
                      }
                      $orderbook[$app_symbol][$side] = array_values($orderbook[$app_symbol][$side]);
                    } else {
                      foreach ($orderbook[$app_symbol][$side] as $key => $offer) {
                        if ($side == 'bids' && $new_price > floatval($offer[0]) ||
                            $side == 'asks' && $new_price < floatval($offer[0]) ) {
                          array_splice($orderbook[$app_symbol][$side], $key, 0, [0 => $new_offer]);
                          break;
                        } elseif ($new_price == floatval($offer[0])) {
                          $orderbook[$app_symbol][$side][$key][0] = $new_offer[0];
                          $orderbook[$app_symbol][$side][$key][1] = $new_offer[1];
                          break;
                        }
                      }
                    }
                  }
                }
              }
              break;
            case 'ping':
              //send pong
              print_dbg('Ping. Send pong',true);
              $msg['data']['e'] = 'pong';
              $client->send(json_encode($msg));
              break;
            default: var_dump($msg);
              break;
          }
        }
        if(!$sync) {
          print_dbg("{$msg['data']['s']} $app_symbol orderbook out of sync u={$msg['data']['u']} U={$msg['data']['U']} lastUpdateId + 1= $u_1",true);
          unlink($file);
          //var_dump($msg);
          break;
        }
        //var_dump($orderbook);
        $orderbook['last_update'] = microtime(true);
        file_put_contents($file, json_encode($orderbook), LOCK_EX);
      }
    }
    catch(Exception $e)
    {
      print_dbg('Binance websocket error:' . $e->getMessage(),true);
      //print_dbg(var_dump($e),true);
      break;
    }
  }
}
