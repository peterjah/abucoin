<?php
require_once('../common/tools.php');
require_once('../common/websockets_tools.php');

@define('FILE', 'benchs.json');
$binance = new Market('binance');
$kraken = new Market('kraken');
$symbol = 'BTC-USDT';
$binance_symbol = 'BTCUSDT';
$symbol_list = getCommonProducts($binance, $kraken);
$file = getcwd() . "/" . subscribeWsOrderBook('binance', $symbol_list);

print "$file\n";
while (!file_exists($file)) {
    usleep(1000);
}
print("waiting websocket is ready\n");
while (!isset($book[$symbol])) {
    $book = parseBook($file);
    sleep(1);
}

$bookStart = $tickerStart = microtime(true);
$lastBookVal = $lastTickerVal = null;
$bookStr = $tickerStr = "";
$bookVal = $tickerVal = 0;
$bookNbUp = $tickerNbUp = 0;

$nloops = 300;
$REST=true;
$speed = 5;//ms
for ($i=0; $i<$nloops; $i++) {
    $now = microtime(true);

    if ($REST) {
        $ticker = $binance->api->jsonRequest('GET', 'v3/depth', ['symbol' => $binance_symbol, 'limit' => 10]);
        //var_dump($ticker);
        $tickerVal = $ticker['asks'][0][0];
    } else {
        $ticker = parseBook($file)[$symbol];
        $tickerVal = $ticker["asks"][0][0];
    }

    $updated = "";
    //var_dump($book);
    if ($tickerVal !== $lastTickerVal) {
        $updated = "<TKR>";
        $tickerStr .= "~";
        $lastTickerVal = $tickerVal;
        $tickerNbUp++;
    }
    print("$i -Ticker-: best ask: {$tickerVal} => " . $tickerStr ." ". $updated ."\n");

    $book = parseBook($file)[$symbol];
    $bookVal = $book["asks"][0];
    $updated = "";
    //var_dump($book);
    if ($bookVal !== $lastBookVal) {
        $updated = "<BKK>";
        $bookStr .= "~";
        $lastBookVal = $bookVal;
        $bookNbUp++;
    }
    print("$i -Book-: best ask: {$bookVal} => " . $bookStr ." ". $updated ."\n\n");

    $loop_time = microtime(true) - $now;
    if ($loop_time < $speed) {
        $sleepTimeMs = $speed-$loop_time;
        usleep($sleepTimeMs*1000);
    }
}

print("speed : $speed\n");

print("tickerNbUp: $tickerNbUp\n");
print("bookNbUp: $bookNbUp\n");

shell_exec("kill -2 $(pgrep -lfa binance | awk '{print $1;}')");
