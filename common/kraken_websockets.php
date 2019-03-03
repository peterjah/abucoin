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
$file = $options['file'];

$products = explode(',', $options['products']);

switch($options['cmd']) {
  case 'getOrderBook':
  while (true) {
      print "Subscribing Kraken Orderbook WS feed\n";
      getOrderBook($products);
      sleep(1);
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
    while (true) {
      try
      {
        $message = $client->receive();
        $fp = fopen($file, "c+");
        flock($fp, LOCK_EX, $wouldblock);

        $orderbook = [];
        $orderbook = json_decode(file_get_contents($file), true);
        if ($message) {
          if ($date < DateTime::createFromFormat('U.u', microtime(TRUE))) {
              $client->send(json_encode(["event"=>"ping"]));
              $date->add(new DateInterval('PT' . 5 . 'S'));
          }
          $msg = json_decode($message , true);
          //var_dump($msg);
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
              default: var_dump($msg);
                break;
            }
          }
          elseif (isset($msg[1]['as']) && isset($msg[1]['bs'])) {
            $symbol = $channel_ids[$msg[0]];
            $orderbook[$symbol]['asks'] = $msg[1]['as'];
            $orderbook[$symbol]['bids'] = $msg[1]['bs'];
          }
          elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
            $symbol = $channel_ids[$msg[0]];
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

          }
          //var_dump($orderbook);
          $orderbook['last_update'] = microtime(true);
          file_put_contents($file, json_encode($orderbook));
          flock($fp, LOCK_UN);
          fclose($fp);
        }
      }
      catch(Exception $e)
      {
        print_dbg("$file error:" . $e->getMessage());
        break;
      }
    }
}
