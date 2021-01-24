<?php
require_once('../common/tools.php');
require_once('../common/websockets_tools.php');

@define('FILE', 'benchs.json');
$binance = new Market('binance');
$kraken = new Market('kraken');
$symbol = 'BTC-USDT';
$kraken_symbol = 'BTCUSDT';
$symbol_list = getCommonProducts($binance, $kraken);
$symbol_list = [$symbol];
$tickerFile = getcwd() . "/" . subscribeWsOrderBook('kraken', $symbol_list, 1);
$bookFile = getcwd() . "/" . subscribeWsOrderBook('krakenbook', $symbol_list, 1);

print "tickerFile: $tickerFile\n";
print "bookFile: $bookFile\n";
while (!(file_exists($tickerFile) && file_exists($bookFile))) {
    usleep(1000);
}

print("waiting websocket is ready\n");
while (!(isset($book[$symbol]) && $ticker[$symbol])) {
    $book = parseBook($bookFile);
    $ticker = parseBook($tickerFile);
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

    if($REST) {
        $ticker = $kraken->api->jsonRequest('Ticker', ['pair' => $kraken_symbol]);
        // var_dump($ticker);
        $tickerVal = $ticker['result']["XBTUSDT"]['a'][0];
    } else {
        $ticker = parseBook($tickerFile)[$symbol];
        $tickerVal = $ticker["asks"][0][0];
    }

    $updated = "";
    //var_dump($book);
    if($tickerVal !== $lastTickerVal) {
        $updated = "<TKR>";
        $tickerStr .= "~";
        $lastTickerVal = $tickerVal;
        $tickerNbUp++;
    }
    print("$i -Ticker-: best ask: {$tickerVal} => " . $tickerStr ." ". $updated ."\n");

    $book = parseBook($bookFile)[$symbol];
    $bookVal = $book["asks"][0][0];
    $updated = "";
    //var_dump($book);
    if($bookVal !== $lastBookVal) {
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

shell_exec("kill -2 $(pgrep -lfa kraken | awk '{print $1;}')");
