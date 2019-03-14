<?php
require_once('../common/tools.php');

@define('FILE', 'benchs.json');
$binance = new Market('binance');
$kraken = new Market('kraken');
$symbol = 'ETH-BTC';
$binance_symbol = 'ETHBTC';
$symbol_list = getCommonProducts($binance, $kraken);
subscribeWsOrderBook($binance, $symbol_list, 'benchs');

$file = $binance->api->orderbook_file;
print "$file\n";
while(!file_exists($file)) {
  usleep(1000);
}
print ("waiting websocket is ready\n");
while(!isset($book[$symbol])) {
  sleep(1);
  $fp = fopen($file, "r");
  flock($fp, LOCK_SH, $wouldblock);
  $book = json_decode(file_get_contents($file), true);
  flock($fp, LOCK_UN);
  fclose($fp);
}
$total_rest_time = 0;
$total_websocket_time = 0;
$webisfaster = 0;
$same = 0;
$nloops = 100;
for ($i=0; $i<$nloops; $i++) {
  $now = microtime(true);
  $book = $binance->api->jsonRequest('GET', 'v1/depth', ['symbol' => $binance_symbol, 'limit' => 10]);
  $rest_api_time = microtime(true) - $now;
  $rest_lastUpdateId = $book['lastUpdateId'];
  print ("Rest lastUpdateId: $rest_lastUpdateId:\n");

  $now = microtime(true);
  $fp = fopen($file, "r");
  flock($fp, LOCK_SH, $wouldblock);
  $book = json_decode(file_get_contents($file), true);
  $book = $book[$symbol];
  flock($fp, LOCK_UN);
  fclose($fp);
  $websocket_time = microtime(true) - $now;
  $web_lastUpdateId = $book['lastUpdateId'];
  print ("Websocket lastUpdateId: $web_lastUpdateId\n");

  $space = $web_lastUpdateId <=> $rest_lastUpdateId;
  if($space == 1)
    $webisfaster++;
  if($space == 0)
    $same++;

  $total_rest_time += $rest_api_time;
  $total_websocket_time += $websocket_time;
  sleep(1);
}
$mean_rest = $total_rest_time / $nloops;
$mean_websocket = $total_websocket_time / $nloops;
print ("mean apicall time = $mean_rest \n");
print ("mean mean_websocket time = $mean_websocket \n");
print ("websocket is fastest $webisfaster times and same $same times on $nloops loops\n");

shell_exec("kill -9 $(pgrep -lfa binance | awk '{print $1;}')");
unlink($file);
