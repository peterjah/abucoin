<?php
require_once('../common/tools.php');

@define('GAINS_FILE', 'gains.json');

$options =  getopt('', array(
   'exchange:',
 ));

$gains = [];
$nTx = [];
$prices['EUR'] = 1;
foreach (['BTC','ETH','USD','USDT','EUR'] as $base) {
  $price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$base}&tsyms=EUR"), true);
  $prices[$base] = $price['EUR'];
  $nTx[$base] = 0;
}
$prices['USD'] = $prices['USDT'];

$fp = fopen(GAINS_FILE, "r");
flock($fp, LOCK_SH, $wouldblock);
$data = json_decode(file_get_contents(GAINS_FILE), true);
flock($fp, LOCK_UN);
fclose($fp);

foreach ($data['arbitrages'] as $arbitrage) {
  $alt = $arbitrage['alt'];
  $base = $arbitrage['base'];
  $gains_base = $arbitrage['final_gains']['base'];
  $real_percent_gains = $arbitrage['final_gains']['percent'];
  $expected_percent_gains = $arbitrage['expected_gains']['percent'];
  $buy_market = strtolower(@$arbitrage['buy_market']);
  $sell_market = strtolower(@$arbitrage['sell_market']);
  $buy_price_diff = (@$arbitrage['stats']['buy_price_diff'] > 0 ? '+':'') . number_format(@$arbitrage['stats']['buy_price_diff'], 2);
  $sell_price_diff = (@$arbitrage['stats']['sell_price_diff'] > 0 ? '+':'') . number_format(@$arbitrage['stats']['sell_price_diff'], 2);

  $op_str = "{$arbitrage['date']}: ";
  if($arbitrage['id'] == 'solved') {
    $op_str .= 'solved: ' . (isset($arbitrage['buy_market']) ? "buy on {$arbitrage['buy_market']}" :
                            (isset($arbitrage['sell_market']) ? "sell on {$arbitrage['sell_market']}" : "sovled "));
  } else {
    $op_str .= "buy $alt $buy_market ($buy_price_diff%)-> sell $sell_market ($sell_price_diff%)";
  }
  print "$op_str ".sprintf("%.3e",$gains_base) . " {$base} =".number_format($gains_base*$prices[$base], 3)."EUR ".
        number_format($expected_percent_gains, 2)."% (".number_format($real_percent_gains, 2)."%)\n";
  if (isset($options['exchange']) ) {
    $exchange = strtolower(substr($options['exchange'], 0, 4));
      if(strpos($exchanges, $exchange) !== false) {
      @$gains[$exchange][$base] += floatval($gains_base);
      @$mean_percent_gains[$exchange][$base] += $real_percent_gains;
      $nTx[$base]++;
    }
  }
  else {
   @$gains[$base] += floatval($gains_base);
   @$mean_percent_gains[$base] += $real_percent_gains;
   $nTx[$base]++;
  }
}

print "\n";
$total_gains_eur = 0;
if (isset($options['exchange']) && !empty($gains[$exchange])) {
  print "~~~~~~~~~~~~~~~~ {$options['exchange']} ~~~~~~~~~~~~~~~~~~~\n";
  $gains = $gains[$exchange];
  $mean_percent_gains = $mean_percent_gains[$exchange];
}

if (isset($gains)) {
  foreach ($gains as $base => $gain) {
    $mean_percent = number_format($mean_percent_gains[$base] / $nTx[$base], 3);
    $total_gains_eur += $gain * $prices[$base];
    print "~~~~~~~~~~~~~~~~ $base ~~~~~~~~~~~~~~~~~~~\n";
    print ("gains: $gain $base = ".($gain * $prices[$base])."EUR\n");
    print ("Nb Tx: {$nTx[$base]}. Mean gains: $mean_percent %\n\n");
  }
}
print ("total: $total_gains_eur EUR\n");
print "\n";

$Cashroll = 0;
foreach (['binance','cryptopia','kraken','cobinhood'] as $market_name) {
  $i=0;
  while ($i < 5) {
    try {
      $market = new Market($market_name);
      $market->api->getBalance();
      print ("{$market->api->name}: {$market->api->balances['BTC']} BTC\n");
      $Cashroll += $market->api->balances['BTC'];
      break;
    } catch (Exception $e) {
      $i++;
      print "failed to get $market_name infos. [{$e->getMessage()}]\n";
    }
  }
}
print ("Total cashroll: $Cashroll BTC = ".($Cashroll*$prices['BTC'])."EUR\n");
