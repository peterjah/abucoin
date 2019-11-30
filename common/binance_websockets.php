<?php
use WebSocket\Client;
require_once('../common/tools.php');
require_once('../common/websockets_tools.php');
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
$products = explode(',', $options['products']);

switch($options['cmd']) {
  case 'getOrderBook':
      while(true) {
      touch($file);
      print_dbg ("Subscribing Binance Orderbook WS feed", true);
      getOrderBook($products);
      unlink($file);
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
    $subscribe_str .= strtolower($symbol) . '@depth@100ms/';
  }
  $subscribe_str = substr($subscribe_str, 0, strlen($subscribe_str)-1);
  $client = new Client(WSS_URL . $subscribe_str, ['timeout' => 60]);
  foreach ($app_symbols as $symbol => $app_symbol) {
    $snapshot = $rest_api->jsonRequest('GET', 'v3/depth', ['symbol' => $symbol, 'limit' => $options['bookdepth']]);
    $orderbook[$app_symbol] = $snapshot;
    $orderbook[$app_symbol]['isSnapshot'] = true;

  }
  file_put_contents($file, json_encode($orderbook), LOCK_EX);

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
          if (!isset($msg['data']['e'])) {
            print_dbg("unknown data received \"{$msg['data']}\"", true);
            var_dump($$msg['data']);
          }
          switch ($msg['data']['e']) {
            case 'depthUpdate':
                $app_symbol = $app_symbols[$msg['data']['s']];
                $lastUpdateId = $orderbook[$app_symbol]['lastUpdateId'];
                if ( $msg['data']['u'] <= $lastUpdateId) {
                  print_dbg("Binance websocket: should not happen", true);
                  break;
                }
                $u_1 = $lastUpdateId + 1;
                if ($msg['data']['U'] <= $u_1 && $msg['data']['u'] >= $u_1) {
                  $orderbook[$app_symbol]['isSnapshot'] = false;
                }
                if (!$orderbook[$app_symbol]['isSnapshot'] && $msg['data']['U'] != $u_1) {
                  $sync = false;
                  break;
                }
                $orderbook[$app_symbol]['lastUpdateId'] = $msg['data']['u'];
                $stackSize = intval($options['bookdepth']);
                foreach (['bids', 'asks'] as $side) {
                  $side_letter = substr($side,0,1);
                  if (isset($msg['data'][$side_letter])) {
                    $offers =$msg['data'][$side_letter];
                    $orderbook[$app_symbol][$side] =
                      handle_offers($orderbook[$app_symbol], $offers, $side, $stackSize);
                  }
                }
                break;
            case 'ping':
              //send pong
              print_dbg('Ping. Send pong',true);
              $msg['data']['e'] = 'pong';
              $client->send(json_encode($msg));
              break;
            default:
              print_dbg("$file unknown event received \"{$msg['data']['e']}\"", true);
              var_dump($msg);
              break;
          }
        }
        if(!$sync) {
          print_dbg("{$msg['data']['s']} $app_symbol orderbook out of sync u={$msg['data']['u']} U={$msg['data']['U']} lastUpdateId + 1= $u_1",true);
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
