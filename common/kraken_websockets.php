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
   'bookdepth:',
 ));


if(!isset($options['cmd'])) {
  print_dbg("No websocket method provided",true);
}
if(!isset($options['file'])) {
  print_dbg("No output file provided",true);
}
$file = $options['file'];

$products = explode(',', str_replace('-', '/', $options['products']));

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
    global $options;

    $client->send(json_encode([
        "event" => "subscribe",
        "pair" => $products,
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
                  $symbol = str_replace('/', "-", $msg['pair']);
                  $channel_ids[$msg['channelID']] = $symbol;
                  print "$symbol subscriptionStatusid= {$msg['channelID']}\n";
                  break;
              case 'pong':
              case 'heartbeat': break;
              default: var_dump($msg);
                break;
            }
          }
          elseif (isset($msg[1]['as']) && isset($msg[1]['bs'])) {
            $symbol = $channel_ids[$msg[0]];
            print "$symbol snapshot received\n";
            $orderbook[$symbol]['asks'] = $msg[1]['as'];
            $orderbook[$symbol]['bids'] = $msg[1]['bs'];
            //var_dump($orderbook[$symbol]['asks']);
          }
          elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
            $symbol = $channel_ids[$msg[0]];
            print "$symbol update received\n";
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
                print("Nb of offer to update: ".count($data[$kside])."\n");
                //remove offer
                if ($new_offer[1] == '0') {
                  print "$symbol remove $side\n";
                  foreach ($orderbook[$symbol][$side] as $key => $offer) {
                    if ($offer[0] != $new_offer[0])
                      continue;
                    print "found it!\n";
                    unset($orderbook[$symbol][$side][$key]);
                    break;
                  }
                  $orderbook[$symbol][$side] = array_values($orderbook[$symbol][$side]);
                } else {
                  print "$symbol add $side\n";
                  foreach ($orderbook[$symbol][$side] as $key => $offer) {
                    if ($side == 'bids' && $new_offer[0] > $offer[0] ||
                        $side == 'asks' && $new_offer[0] < $offer[0] ) {
                      //    var_dump($msg);
                      // var_dump($new_offer);
                      // print "new_offer[0] <> offer[0]\n";
                      // var_dump($offer);
                      array_splice($orderbook[$symbol][$side], $key, 0, [0 => $new_offer]);
                      break;
                    } elseif ($new_offer[0] == $offer[0]) {
                      print "offer[0] = new_offer[0]\n";
                      $orderbook[$symbol][$side][$key][0] = $new_offer[0];
                      $orderbook[$symbol][$side][$key][1] = floatval($offer[1]) + floatval($new_offer[1]);
                      $orderbook[$symbol][$side][$key][2] = $new_offer[2];
                      break;
                    }
                  }
                }
              }
            }

          }
          //var_dump($orderbook);
          file_put_contents($file, json_encode($orderbook));
          flock($fp, LOCK_UN);
          fclose($fp);
        }
      }
      catch(Exception $e)
      {
        print $e->getMessage();
        break;
      }
    }
}
