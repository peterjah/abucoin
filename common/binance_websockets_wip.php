<?php
use WebSocket\Client;
require_once('../common/tools.php');
@define('WSS_URL','wss://stream.binance.com:9443');

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
touch($file);
$products = explode(',', $options['products']);

switch($options['cmd']) {
  case 'getOrderBook':
      print "Subscribing Binance Orderbook WS feed\n";
      getOrderBook($products);
      break;
}

function getOrderBook($products)
{
    $rest_api = new BinanceApi();
    global $file;
    global $options;

    $streams = [];
    $subscribe_str = '/stream?streams=';
    foreach ($products as $product) {
      $alts = explode ('-', $product);
      $symbol = BinanceApi::translate2marketName($alts[0]) . BinanceApi::translate2marketName($alts[1]);
      $streams[$symbol]['app_symbol'] = $product;
      $subscribe_str .= strtolower($symbol) . '@depth';

      $fp = fopen($file, "r");
      flock($fp, LOCK_SH, $wouldblock);
      $orderbook = json_decode(file_get_contents($file), true);
      flock($fp, LOCK_UN);
      fclose($fp);
      $snapshot = $rest_api->jsonRequest('GET', 'v1/depth', ['symbol' => $symbol, 'limit' => 1000]);
      $orderbook[$product] = $snapshot;
      file_put_contents($file, json_encode($orderbook), LOCK_EX);
    }
    $client = new Client(WSS_URL . $subscribe_str, ['timeout' => 60]);

    $date = DateTime::createFromFormat('U.u', microtime(TRUE));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    $channel_ids = [];
    while (true) {
      try
      {
        $message = $client->receive();
        if ($message) {
          $msg = json_decode($message , true);
          if ($msg['data']['e'] == 'ping') {
            //send pong
            break;
          }
          while (true) {
            $fp = fopen($file, "c+");
            if ($fp !== false && flock($fp, LOCK_EX, $wouldblock)) {
              $orderbook = json_decode(file_get_contents($file), true);

              var_dump($msg);
              if (isset($msg['data'])) {
                switch ($msg['data']['e']) {
                  case 'depthUpdate':
                      print "lastUpdateId: {$orderbook[$symbol]['lastUpdateId']}\n";
                      $symbol = $channel_ids[$msg['data']['s']];
                      if ( $msg['data']['u'] <= $orderbook[$symbol]['lastUpdateId'])
                        break;
                      $orderbook[$symbol]['lastUpdateId'] = $msg['data']['u'];

                      //var_dump($msg);
                      foreach ($msg as $idx => $data) {
                        if ($idx == 0) {
                          continue;
                        } elseif (isset($data['a'])) {
                          $side = 'asks';
                          $kside = 'a';
                        } elseif (isset($data['b'])) {
                          $side = 'bids';
                          $kside = 'b';
                        }
                        foreach ($data[$kside] as $new_offer) {
                          //remove offer
                          if ($new_offer[1] == '0') {
                            foreach ($orderbook[$symbol][$side] as $key => $offer) {
                              if ($offer[0] != $new_offer[0])
                                continue;
                              unset($orderbook[$symbol][$side][$key]);
                              break;
                            }
                            $orderbook[$symbol][$side] = array_values($orderbook[$symbol][$side]);
                          } else {
                            foreach ($orderbook[$symbol][$side] as $key => $offer) {
                              if ($side == 'bids' && $new_offer[0] > $offer[0] ||
                                  $side == 'asks' && $new_offer[0] < $offer[0] ) {
                                array_splice($orderbook[$symbol][$side], $key, 0, [0 => $new_offer]);
                                break;
                              } elseif ($new_offer[0] == $offer[0]) {
                                $orderbook[$symbol][$side][$key][0] = $new_offer[0];
                                $orderbook[$symbol][$side][$key][1] = floatval($offer[1]) + floatval($new_offer[1]);
                                break;
                              }
                            }
                          }
                        }
                      }
                      break;
                  case 'ping':

                  default: var_dump($msg);
                    break;
                }
              }

              //var_dump($orderbook);
              $orderbook['last_update'] = microtime(true);
              file_put_contents($file, json_encode($orderbook));
              flock($fp, LOCK_UN);
              fclose($fp);
              break;
            } else {
              @fclose($fp);
              print_dbg("Unable to lock file $file", true);
              usleep(10);
            }
          }

        }
      }
      catch(Exception $e)
      {
        print_dbg('Binance websocket error:' . $e->getMessage());
        print_dbg(var_dump($e));
      }
    }
}
