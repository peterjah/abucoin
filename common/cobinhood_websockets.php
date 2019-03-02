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
      print "Subscribing Cobinhood Orderbook WS feed\n";
      getOrderBook($products);
      break;
}

function getOrderBook($products)
{
    $client = new Client(WSS_URL, ['timeout' => 60]);
    global $file;
    foreach ($products as $product) {
        $client->send(json_encode([
            "action" => "subscribe",
            "type" => "order-book",
            "trading_pair_id" => $product,
            //"precision" => "1E-6"
        ]));
    }

    $date = DateTime::createFromFormat('U.u', microtime(TRUE));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    while (true) {
      try
      {
        $message = $client->receive();
        $fp = fopen($file, "c+");
        flock($fp, LOCK_EX, $wouldblock);
        //print "wouldblock=$wouldblock\n";
        $orderbook = [];
        $orderbook = json_decode(file_get_contents($file), true);
        if ($message) {
          if ($date < DateTime::createFromFormat('U.u', microtime(TRUE))) {
              $client->send(json_encode(["action"=>"ping"]));
              $date->add(new DateInterval('PT' . 5 . 'S'));
          }
          $msg = json_decode($message , true);

          preg_match('/order-book.(.*-.*).1E-/', $msg['h'][0], $matches);
          $symbol = @$matches[1];
          switch ($msg['h'][2]) {
            case 's': foreach (['bids', 'asks'] as $side) {
                        $orderbook[$symbol][$side] = array_values($msg['d'][$side]);
                      }
                      break;
            case 'u':
              print "update received\n";
            //  var_dump($msg['d']);
              foreach (['bids', 'asks'] as $side) {
                foreach ($msg['d'][$side] as $new_offer) {
                  print("Nb of offer to update: ".count($msg['d'][$side])."\n");
                  //remove offer
                  if ($new_offer[1] <= 0) {
                    print "remove $side\n";
                    foreach ($orderbook[$symbol][$side] as $key => $offer) {
                      if ($offer[0] != $new_offer[0])
                        continue;
                      print "found it!\n";
                      //count
                      $orderbook[$symbol][$side][$key][1] = intval($offer[1]) + intval($new_offer[1]);
                      //volume
                      $orderbook[$symbol][$side][$key][2] += floatval($offer[1]) + floatval($new_offer[2]);
                      print "new $side\n";
                      if($orderbook[$symbol][$side][$key][1] == 0)
                        unset($orderbook[$symbol][$side][$key]);
                      break;
                    }
                    $orderbook[$symbol][$side] = array_values($orderbook[$symbol][$side]);
                  } else {
                    print "add $side\n";
                    foreach ($orderbook[$symbol][$side] as $key => $offer) {
                      if ($side == 'bids' && $new_offer[0] > $offer[0] ||
                          $side == 'asks' && $new_offer[0] < $offer[0] ) {
                        print "new_offer[0] <> offer[0]\n";
                        array_splice($orderbook[$symbol][$side], $key, 0, [0 => $new_offer]);
                        break;
                      } elseif ($new_offer[0] == $offer[0]) {
                        print "offer[0] = new_offer[0]\n";
                        $orderbook[$symbol][$side][$key][0] = $new_offer[0];
                        $orderbook[$symbol][$side][$key][1] = intval($offer[1]) + intval($new_offer[1]);
                        $orderbook[$symbol][$side][$key][2] = floatval($offer[2]) + floatval($new_offer[2]);
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
          file_put_contents($file, json_encode($orderbook));
          flock($fp, LOCK_UN);
          fclose($fp);
        }
      }
      catch(Exception $e)
      {
        print_dbg('Cobinhood websocket  error:' . $e->getMessage()):
      }
    }
}
