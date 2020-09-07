<?php
use WebSocket\Client;
require_once('../common/tools.php');
require_once('../common/websockets_tools.php');
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
        "subscription" => ['name' => 'ticker']
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
          elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
            $symbol = $channel_ids[$msg[0]];
            //price
            $orderbook[$symbol]['bids'][0][0] = $msg[1]['b'][0];
            $orderbook[$symbol]['asks'][0][0] = $msg[1]['a'][0];
            //vol
            $orderbook[$symbol]['bids'][0][1] = $msg[1]['b'][2];
            $orderbook[$symbol]['asks'][0][1] = $msg[1]['a'][2];

            $orderbook['last_update'] = microtime(true);
          } else {
            print_dbg("$file msg received", true);
            var_dump($msg);
            break;
          }
          if (!file_exists($file)) {
            print_dbg('Restarting Kraken websocket', true);
            break;
          }
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
