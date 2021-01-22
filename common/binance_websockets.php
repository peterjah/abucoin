<?php
use WebSocket\Client;

require_once('../common/tools.php');
require_once('../common/websockets_tools.php');
@define('WSS_URL', 'wss://stream.binance.com:9443');

declare(ticks = 1);
pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");

$options =  getopt('', array(
   'file:',
   'products:',
 ));

if (!isset($options['file'])) {
    print_dbg("No output file provided", true);
}
$file = $options['file'];
$products = explode(',', $options['products']);
while (true) {
    touch($file);
    print_dbg("Subscribing Binance Orderbook WS feed", true);
    getOrderBook($products, $file);
    unlink($file);
}


function getOrderBook($products, $file)
{

    $orderbook = [];
    $subscribe_str = '/stream?streams=';
    $app_symbols = [];
    foreach ($products as $product) {
        $alts = explode('-', $product);
        $symbol = BinanceApi::translate2marketName($alts[0]) . BinanceApi::translate2marketName($alts[1]);
        $app_symbols[$symbol] = $product;
        $subscribe_str .= strtolower($symbol) . "@bookTicker/";
    }

    $subscribe_str = substr($subscribe_str, 0, strlen($subscribe_str)-1);
    $client = new Client(WSS_URL . $subscribe_str, ['timeout' => 60]);
  
    while (true) {
        try {
            $message = $client->receive();
            if ($message) {
                $msg = json_decode($message, true);
                if (isset($msg['data'])) {
                    $app_symbol = $app_symbols[$msg['data']['s']];
                    //price
                    $orderbook[$app_symbol]['bids'][0] = $msg['data']['b'];
                    $orderbook[$app_symbol]['asks'][0] = $msg['data']['a'];
                    //vol
                    $orderbook[$app_symbol]['bids'][1] = $msg['data']['B'];
                    $orderbook[$app_symbol]['asks'][1] = $msg['data']['A'];

                    $orderbook['last_update'] = microtime(true);
                    if (!file_exists($file)) {
                        print_dbg('Restarting Binance websocket', true);
                        break;
                    }
                    file_put_contents($file, json_encode($orderbook), LOCK_EX);
                }
            }
        } catch (Exception $e) {
            print_dbg('Binance websocket error:' . $e->getMessage(), true);
            break;
        }
    }
}

function sig_handler($sig)
{
    switch ($sig) {
        case SIGINT:
        case SIGTERM:
        case SIGKILL:
          print_dbg("Kraken WS: signal $sig catched! Exiting...", true);
          unlink($GLOBALS['file']);
          exit();
    }
}