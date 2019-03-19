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
        touch($file);
        print "Subscribing Cobinhood Orderbook WS feed\n";
        getOrderBook($products);
        unlink($file);
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
    $orderbook = [];
    while (true) {
      try
      {
        $message = $client->receive();
        if ($message) {
          if ($date < DateTime::createFromFormat('U.u', microtime(TRUE))) {
              $client->send(json_encode(["action"=>"ping"]));
              $date->add(new DateInterval('PT' . 5 . 'S'));
          }
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
                  $new_price = floatval($new_offer[0]);
                  if ($new_offer[1] <= 0) {
                    foreach ($orderbook[$app_symbol][$side] as $key => $offer) {
                      if (floatval($offer[0]) != $new_price)
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
                      if ($side == 'bids' && $new_price > floatval($offer[0]) ||
                          $side == 'asks' && $new_price < floatval($offer[0]) ) {
                        array_splice($orderbook[$app_symbol][$side], $key, 0, [0 => $new_offer]);
                        break;
                      } elseif ($new_price == floatval($offer[0])) {
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
            case 'pong': break;
              // print "new $side book:\n";
              // var_dump($orderbook[$symbol]['asks']);
              break;
            default:
              print_dbg("$file unknown event received \"{$msg['h'][2]}\"", true);
              var_dump($msg);
              break;
          }
          //var_dump($orderbook);
          $orderbook['last_update'] = microtime(true);
          file_put_contents($file, json_encode($orderbook), LOCK_EX);
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
