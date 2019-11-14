<?php
use WebSocket\Client;
require_once('../common/tools.php');
@define('WSS_URL','wss://ws.kraken.com');

declare(ticks = 1);
function sig_handler($sig) {
  global $file;
    switch($sig) {
        case SIGINT:
        case SIGTERM:
          print_dbg("Kraken WS: signal $sig catched! Exiting...", true);
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

$products = explode(',', $options['products']);
$file = $options['file'];

switch($options['cmd']) {
  case 'getOrderBook':
  while (true) {
      touch($file);
      print "Subscribing Kraken Orderbook WS feed\n";
      getOrderBook($products);
      unlink($file);
    }
}

function getOrderBook($products)
{
    $client = new Client(WSS_URL, ['timeout' => 60]);
    global $file;
    global $options;

    $streams = [];
    $kraken_products = [];
    foreach ($products as $product) {
      $alts = explode ('-', $product);
      $symbol = KrakenApi::translate2marketName($alts[0]) .'/'. KrakenApi::translate2marketName($alts[1]);
      $streams[$symbol]['app_symbol'] = $product;
      $kraken_products[] = $symbol;
    }
    $client->send(json_encode([
        "event" => "subscribe",
        "pair" => $kraken_products,
        "subscription" => ['name' => 'book', 'depth' => intval($options['bookdepth'])]
    ]));

    $date = DateTime::createFromFormat('U.u', microtime(TRUE));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    $channel_ids = [];
    $orderbook = [];
    while (true) {
      try
      {
        $message = $client->receive();
        if ($message) {
          if ($date < DateTime::createFromFormat('U.u', microtime(TRUE))) {
              $client->send(json_encode(["event"=>"ping"]));
              $date->add(new DateInterval('PT' . 5 . 'S'));
          }
          $msg = json_decode($message , true);
          if ($msg == null) {
            print_dbg("$file failed to decode json: \"{$message}\"", true);
            var_dump($message);
            break;
          }

          if (isset($msg['event'])) {
            switch ($msg['event']) {
              case 'systemStatus':
                  if ($msg['status'] != 'online')
                    throw new \Exception("Kraken WS system is onfline");
                  break;
              case 'subscriptionStatus':
                  if ($msg['status'] != 'subscribed')
                    throw new \Exception("Kraken WS subsscription failed: {$msg['errorMessage']}");
                  $app_symbol = $streams[$msg['pair']]['app_symbol'];
                  $channel_ids[$msg['channelID']] = $app_symbol;
                  break;
              case 'pong':
              case 'heartbeat': break;
              default:
                print_dbg("$file unknown event received \"{$msg['event']}\"", true);
                var_dump($msg);
                break;
            }
          }
          elseif (isset($msg[1]['as']) || isset($msg[1]['bs'])) {
            $symbol = $channel_ids[$msg[0]];
            if (count($msg[1]['as']))
              $orderbook[$symbol]['asks'] = $msg[1]['as'];
            if (count($msg[1]['bs']))
              $orderbook[$symbol]['bids'] = $msg[1]['bs'];
          }
          elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
            $symbol = $channel_ids[$msg[0]];
            //var_dump($msg);
            if (isset($msg[1]['a'])) {
              $side = 'asks';
              $kside = 'a';
            }
            elseif (isset($msg[1]['b'])) {
              $side = 'bids';
              $kside = 'b';
            }
            else {
              print_dbg("$file unknown message received \"{$msg}\"", true);
              var_dump($msg);
            }


            var_dump($msg[1]);
            foreach ($msg[1][$kside] as $new_offer) {
              $new_price = floatval($new_offer[0]);
              //delete message
              if (floatval($new_offer[1]) == 0) {
                foreach ($orderbook[$symbol][$side] as $key => $offer) {
                  if (floatval($offer[0]) != $new_price)
                    continue;
                  print("delete offer level {$offer[0]}\n");
                  unset($orderbook[$symbol][$side][$key]);
                  break;
                }
                $orderbook[$symbol][$side] = array_values($orderbook[$symbol][$side]);
              } else {
                foreach ($orderbook[$symbol][$side] as $key => $offer) {
                  if ($side == 'bids' && $new_price > floatval($offer[0]) ||
                      $side == 'asks' && $new_price < floatval($offer[0]) ) {
                    print("new offer level {$new_offer[0]}  {$key}\n");
                    array_splice($orderbook[$symbol][$side], $key, 0, [0 => $new_offer]);
                    break;
                  } elseif ($new_price == floatval($offer[0])) {
                    print("update offer level {$offer[0]}  {$key}\n");
                    $orderbook[$symbol][$side][$key][0] = $new_offer[0];
                    $orderbook[$symbol][$side][$key][1] = $new_offer[1];
                    break;
                  }
                  if ($key == count($orderbook[$symbol][$side]) -1 ) {
                    print("new offer level at last level: {$new_offer[0]}  {$key}\n");
                    $orderbook[$symbol][$side][$key+1][0] = $new_offer[0];
                    $orderbook[$symbol][$side][$key+1][1] = $new_offer[1];
                  }
                }
                $orderbook[$symbol][$side] = array_slice($orderbook[$symbol][$side], 0, intval($options['bookdepth']));
                $orderbook[$symbol][$side] = array_values($orderbook[$symbol][$side]);
              }
            }
            $orderbook['last_update'] = microtime(true);
          } else {
            print_dbg("$file msg received", true);
            var_dump($msg);
            break;
          }
          //var_dump($orderbook);
          file_put_contents($file, json_encode($orderbook), LOCK_EX);
        }
      }
      catch(Exception $e)
      {
        print_dbg("$file error:" . $e->getMessage(), true);
        break;
      }
    }
}
