<?php
use WebSocket\Client;

require_once('../common/tools.php');
require_once('../common/websockets_tools.php');

@define('WSS_URL', 'wss://ws.kraken.com');
@define('DEPTH', 10);

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
    print "Subscribing Kraken Orderbook WS feed\n";
    getOrderBook($products, $file);
    unlink($file);
}


function getOrderBook($products, $file)
{
    $client = new Client(WSS_URL, ['timeout' => 60, 'filter' => ['text', 'ping', 'pong', 'close']]);

    $streams = [];
    $kraken_products = [];
    foreach ($products as $product) {
        $alts = explode('-', $product);
        $symbol = KrakenApi::translate2marketName($alts[0]) .'/'. KrakenApi::translate2marketName($alts[1]);
        $streams[$symbol]['app_symbol'] = $product;
        $kraken_products[] = $symbol;
    }
    $client->send(json_encode([
        "event" => "subscribe",
        "pair" => $kraken_products,
        "subscription" => ['name' => 'book']
    ]));

    $date = DateTime::createFromFormat('U.u', microtime(true));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    $channel_ids = [];
    $orderbook = [];
    while (true) {
        try {
            $message = $client->receive();
            if ($message) {
                if ($date < DateTime::createFromFormat('U.u', microtime(true))) {
                    $client->send(json_encode(["event"=>"ping"]));
                    $date->add(new DateInterval('PT' . 5 . 'S'));
                }
                $msg = json_decode($message, true);
                if ($msg == null) {
                    print_dbg("$file failed to decode json: \"{$message}\"", true);
                    var_dump($message);
                    break;
                }

                if (isset($msg['event'])) {
                    switch ($msg['event']) {
                        case 'systemStatus':
                            if ($msg['status'] != 'online') {
                                throw new \Exception("Kraken WS system is onfline");
                            }
                            break;
                        case 'subscriptionStatus':
                            if ($msg['status'] != 'subscribed') {
                                throw new \Exception("Kraken WS subsscription failed: {$msg['errorMessage']}");
                            }
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
                } elseif (isset($msg[1]['as']) || isset($msg[1]['bs'])) {
                    print("snapshot received \n");
                    $symbol = $channel_ids[$msg[0]];
                    if (count($msg[1]['as'])) {
                        $orderbook[$symbol]['asks'] = $msg[1]['as'];
                    }
                    if (count($msg[1]['bs'])) {
                        $orderbook[$symbol]['bids'] = $msg[1]['bs'];
                    }
                }  elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
                    $symbol = $channel_ids[$msg[0]];
                    foreach (['bids', 'asks'] as $side) {
                      $side_letter = substr($side,0,1);
                      if (isset($msg[1][$side_letter])) {
                        $offers = $msg[1][$side_letter];
                        $orderbook[$symbol][$side] =
                          handle_offers($orderbook[$symbol], $offers, $side, DEPTH);
                      }
                    }
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
        } catch (Exception $e) {
            print_dbg("$file error:" . $e->getMessage(), true);
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
