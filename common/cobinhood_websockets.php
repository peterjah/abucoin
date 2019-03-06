<?php
use WebSocket\Client;
require_once('../common/tools.php');
@define('WSS_URL','wss://ws.cobinhood.com/v2/ws');

declare(ticks = 1);
function sig_handler($sig) {
  global $file;
    switch($sig) {
        case SIGINT:
        case SIGTERM:
          print_dbg("Cobinhood WS: signal $sig catched! Exiting...", true);
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
        print "Subscribing Cobinhood Orderbook WS feed\n";
        getOrderBook($products);
        sleep(1);
      }
}

function getOrderBook($products)
{
    $client = new Client(WSS_URL, ['timeout' => 60]);
    global $file;
    $streams = [];
    foreach ($products as $product) {
      $alts = explode ('-', $product);
      $symbol = CobinhoodApi::translate2marketName($alts[0]) .'-'. CobinhoodApi::translate2marketName($alts[1]);
      $streams[$symbol]['app_symbol'] = $product;
      $client->send(json_encode([
          "action" => "subscribe",
          "type" => "order-book",
          "trading_pair_id" => $symbol,
      ]));
    }

    $date = DateTime::createFromFormat('U.u', microtime(TRUE));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    while (true) {
      try
      {
        $message = $client->receive();


        $orderbook = [];
        $orderbook = json_decode(file_get_contents($file), true);
        if ($message) {
          if ($date < DateTime::createFromFormat('U.u', microtime(TRUE))) {
              $client->send(json_encode(["action"=>"ping"]));
              $date->add(new DateInterval('PT' . 5 . 'S'));
          }
          while (true) {
            $fp = fopen($file, "c+");
            if ($fp !== false && flock($fp, LOCK_EX, $wouldblock)) {
              $msg = json_decode($message , true);
              if (preg_match('/order-book.(.*-.*).1E-/', $msg['h'][0], $matches)) {
                $symbol = @$matches[1];
                $app_symbol = $streams[$symbol]['app_symbol'];
              }
              switch ($msg['h'][2]) {
                case 's': foreach (['bids', 'asks'] as $side) {
                            $orderbook[$app_symbol][$side] = array_values($msg['d'][$side]);
                          }
                          break;
                case 'u':
                //  var_dump($msg['d']);
                  foreach (['bids', 'asks'] as $side) {
                    foreach ($msg['d'][$side] as $new_offer) {
                      //remove offer
                      if ($new_offer[1] <= 0) {
                        foreach ($orderbook[$app_symbol][$side] as $key => $offer) {
                          if ($offer[0] != $new_offer[0])
                            continue;
                          //count
                          $orderbook[$app_symbol][$side][$key][1] = intval($offer[1]) + intval($new_offer[1]);
                          //volume
                          $orderbook[$app_symbol][$side][$key][2] += floatval($offer[1]) + floatval($new_offer[2]);
                          if($orderbook[$app_symbol][$side][$key][1] == 0)
                            unset($orderbook[$app_symbol][$side][$key]);
                          break;
                        }
                        $orderbook[$app_symbol][$side] = array_values($orderbook[$app_symbol][$side]);
                      } else {
                        foreach ($orderbook[$app_symbol][$side] as $key => $offer) {
                          if ($side == 'bids' && $new_offer[0] > $offer[0] ||
                              $side == 'asks' && $new_offer[0] < $offer[0] ) {
                            array_splice($orderbook[$app_symbol][$side], $key, 0, [0 => $new_offer]);
                            break;
                          } elseif ($new_offer[0] == $offer[0]) {
                            $orderbook[$app_symbol][$side][$key][0] = $new_offer[0];
                            $orderbook[$app_symbol][$side][$key][1] = intval($offer[1]) + intval($new_offer[1]);
                            $orderbook[$app_symbol][$side][$key][2] = floatval($offer[2]) + floatval($new_offer[2]);
                            break;
                          } else {
                            continue;
                          }
                        }
                      }
                    }
                  }
                  // print "new $side book:\n";
                  // var_dump($orderbook[$symbol]['asks']);
                  break;
                case 'pong': break;
                default: var_dump($msg);
                  break;
              }
              //var_dump($orderbook);
              $orderbook['last_update'] = microtime(true);
              file_put_contents($file, json_encode($orderbook));
              flock($fp, LOCK_UN);
              break;
            } else {
              print_dbg("Unable to lock file $file", true);
              usleep(10);
            }
          }
          fclose($fp);
        }
      }
      catch(Exception $e)
      {
        print_dbg("$file error:" . $e->getMessage());
        //if (preg_match('/Empty read; connection dead\?  Stream state: ({.+})$/', $e->getMessage(), $matchs));
        break;
      }
    }
}
