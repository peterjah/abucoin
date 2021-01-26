<?php
use Wrench\Client;

require_once('../common/tools.php');
require_once('../common/websockets_tools.php');

@define('WSS_URL', 'wss://ws.kraken.com/:443');
@define('DEPTH', 10);
@define('WRITE_FREQ_MS', 20);

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
    $origin = exec('curl -s http://ipecho.net/plain');

    $client = new Client(WSS_URL, "http://" . $origin);
    $client->connect();

    $streams = [];
    $kraken_products = [];
    foreach ($products as $product) {
        $alts = explode('-', $product);
        $symbol = KrakenApi::translate2marketName($alts[0]) .'/'. KrakenApi::translate2marketName($alts[1]);
        $streams[$symbol]['app_symbol'] = $product;
        $kraken_products[] = $symbol;
    }
    $client->sendData(json_encode([
        "event" => "subscribe",
        "pair" => $kraken_products,
        "subscription" => ['name' => 'book']
    ]));

    $date = DateTime::createFromFormat('U.u', microtime(true));
    $date->add(new DateInterval('PT' . 5 . 'S'));

    $channel_ids = [];
    $orderbook = ['last_update' => 0];
    $frameIdx = null;
    while (true) {
        try {
            if(isset($message[$frameIdx])) {
                $frame = $message[$frameIdx]->getPayload();
            } else {
                $message = $client->receive();
                $frameIdx = 0;
                $frame = $message[0]->getPayload();
            }
            $frameIdx++;
            if ($frame) {
                if ($date < DateTime::createFromFormat('U.u', microtime(true))) {
                    $client->sendData(json_encode(["event"=>"ping"]));
                    $date->add(new DateInterval('PT' . 5 . 'S'));
                }
                $msg = json_decode($frame, true);
                if ($msg == null) {
                    print_dbg("$file failed to decode json: \"{$frame}\"", true);
                    continue;
                }

                if (isset($msg['event'])) {
                    switch ($msg['event']) {
                        case 'systemStatus':
                            if ($msg['status'] != 'online') {
                                throw new \Exception("Kraken WS system is onfline");
                            }
                            print("new systemStatus msg status: {$msg['status']}\n");
                            break;
                        case 'subscriptionStatus':
                            if ($msg['status'] === 'error') {
                                throw new \Exception("Kraken WS subsscription failed: {$msg['errorMessage']}");
                            }
                            $app_symbol = $streams[$msg['pair']]['app_symbol'];

                            if ($msg['status'] === 'unsubscribed') {
                                print("unsubscribed from: $app_symbol channelID: {$msg['channelID']}\n");
                                $alts = explode('-', $app_symbol);
                                $kraken_symbol = KrakenApi::translate2marketName($alts[0]) .'/'. KrakenApi::translate2marketName($alts[1]);
                                $client->sendData(json_encode([
                                    "event" => "subscribe",
                                    "pair" => [$kraken_symbol],
                                    "subscription" => ['name' => 'book']
                                ]));
                            } else {
                                print("new channel subscription (status: {$msg['status']}) id: $app_symbol {$msg['channelID']}\n");
                                $channel_ids[$msg['channelID']] = $app_symbol;
                            }
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
                    $orderbook[$symbol]["restarting"] = false;
                }  elseif (isset($msg[1]['a']) || isset($msg[1]['b'])) {
                    $symbol = $channel_ids[$msg[0]];
                    if (!$orderbook[$symbol]["restarting"]) {
                        foreach (['bids', 'asks'] as $side) {
                            $side_letter = substr($side, 0, 1);
                            if (isset($msg[1][$side_letter])) {
                                $offers = $msg[1][$side_letter];
                                $orderbook[$symbol][$side] =
                          handle_offers($orderbook[$symbol], $offers, $side, DEPTH);
                                if (isset($offers[3])) {
                                    print_dbg("$file replica frame!!!", true);
                                }
                            }
                        }
                        if (isset($msg[1]["c"])) {
                            if (!checkSumValid($orderbook[$symbol], $msg[1]["c"])) {
                                print_dbg("$file $symbol invalid checksum. Restarting...", true);
                                $orderbook[$symbol]["restarting"] = true;
                                $client->sendData(json_encode([
                                "event" => "unsubscribe",
                                "channelID" => $msg[0],
                            ]));
                            }
                        }
                    }
                } else {
                    print_dbg("$file unknown msg received", true);
                    var_dump($msg);
                    break;
                }
            }
            $now = microtime(true);
            if(($now - $orderbook['last_update'])*1000 > WRITE_FREQ_MS) {
                $orderbook['last_update'] = microtime(true);
                file_put_contents($file, json_encode($orderbook), LOCK_EX);
            }
        } catch (Exception $e) {
            print_dbg("$file error:" . $e->getMessage(), true);
        }
    }
}

function checkSumValid($book, $checksum) {
    $str = "";
    foreach (['asks', 'bids' ] as $side) {
        foreach ($book[$side] as $offer) {
            $str .= ltrim(str_replace(".", "", $offer[0]), "0");
            $str .= ltrim(str_replace(".", "", $offer[1]), "0");
        }
    }
    return crc32($str) == $checksum;
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
