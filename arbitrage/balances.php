<?php
require_once('../common/tools.php');
@define('FILE', "portfolio.json");

$options =  getopt('', array(
   'diff:',
   'alt:',
 ));

function sortBalance($a, $b)
{
    if ($a["btc_percent"] == $b["btc_percent"]) {
        return 0;
    }
    return ($a["btc_percent"] < $b["btc_percent"]) ? 1 : -1;
}

$markets = [];
foreach (['kraken', 'binance'] as $api) {
    $i=0;
    while ($i < 5) {
        try {
            $markets[$api] = new Market($api);
            $markets[$api]->getBalance();
            break;
        } catch (Exception $e) {
            $i++;
            print "failed to get $api infos. [{$e->getMessage()}]\n";
        }
    }
}

$cryptoInfo = [];

if (isset($options['alt'])) {
    $crypto = strtoupper($options['alt']);
} else {
    $saved_data = json_decode(file_get_contents(FILE), true);
    $balances = [];
    $basePrices = getEurPrices($markets['binance']);
    $tickers = $markets['binance']->api->refreshTickers([]);
    foreach ($markets as $market) {
        foreach ($market->api->balances as $alt => $bal) {
            if ($bal > 0) {
                @$balances[$alt]['bal'] += $bal;
                if(isset($basePrices[$alt])) {
                    $balances[$alt]['eur'] = $balances[$alt]['bal'] * $basePrices[$alt];
                    $balances[$alt]['btc'] = $balances[$alt]['eur'] / $basePrices["BTC"];
                } elseif (isset($tickers[$alt .'-BTC'])) {
                    $btcPrice = ($tickers[$alt .'-BTC']["bids"][0] + $tickers[$alt .'-BTC']["asks"][0])/2;
                    $balances[$alt]['btc'] = $balances[$alt]['bal'] * $btcPrice;
                    $balances[$alt]['eur'] = $balances[$alt]['btc'] * $basePrices["BTC"];
                } elseif (isset($tickers[$alt .'-BNB'])) {
                    $bnbPrice = ($tickers[$alt .'-BNB']["bids"][0] + $tickers[$alt .'-BNB']["asks"][0])/2;
                    $btcPrice = ($tickers['BNB-BTC']["bids"][0] + $tickers['BNB-BTC']["asks"][0])/2;
                    $balances[$alt]['btc'] = $balances[$alt]['bal'] * $bnbPrice * $btcPrice;
                    $balances[$alt]['eur'] = $balances[$alt]['btc'] * $basePrices["BTC"];
                } else {
                    $balances[$alt]['btc'] = -1;
                    $balances[$alt]['eur'] = -1;
                }
            }
        }
    }

    foreach ($balances as $balance) {
        @$total_btc += $balance['btc'] != -1 ? $balance['btc'] : 0;
        @$total_eur += $balance['eur'] != -1 ? $balance['eur'] : 0;
    }

    $changes = [];
    $change_days = isset($options['diff']) ? $options['diff'] : 1;
    $today = date('d-m-Y', time());
    $date_ref = DateTime::createFromFormat('d-m-Y', $today);
    $date_ref->modify("-{$change_days} day");

    $ref = @$saved_data[$date_ref->format('d-m-Y')];

    foreach ($balances as $alt => $balance) {
        if ($balance['btc'] > 0) {
            $balances[$alt]['btc_percent'] = ($balance['btc'] / $total_btc)*100;
        } else {
            $balances[$alt]['btc_percent'] = -1;
        }
        if (isset($ref['balances'][$alt]['bal'])) {
            $changes[$alt]['bal_percent'] = (($balance['bal'] - $ref['balances'][$alt]['bal']) / $ref['balances'][$alt]['bal'])*100;
        } else {
            $changes[$alt]['bal_percent'] = 100;
        }
    }

    $bal = uasort($balances, "sortBalance");

    printf(" Crypto   | %-15s | %-15s | %-15s |  %s\n", 'Balance', 'Change', 'Percent', 'EUR');
    foreach ($balances as $alt => $cryptoInfo) {
        if ($cryptoInfo['btc_percent'] === -1) {
            printf(" %-8s | %-15s | %-15s | %-15s | -- EUR\n", $alt, $cryptoInfo['bal'], "{$changes[$alt]['bal_percent']}%", '--%');
        } else {
            $bal = number_format($cryptoInfo['bal'], 5);
            $btc_percent = number_format($cryptoInfo['btc_percent'], 3);
            $eur =  number_format($cryptoInfo['eur'], 3);
            $bal_percent = number_format($changes[$alt]['bal_percent'], 2);
            printf(" %-8s | %-15s | %-15s | %-15s | %s EUR\n", $alt, $bal, "$bal_percent%", "$btc_percent%", $eur);
        }
    }
    print "total holdings: ".count($balances)." cryptos \n";
    if (isset($ref)) {
        $btc_change = (($total_btc - $ref['total_btc']) / $ref['total_btc'])*100;
        $btc_change = ($btc_change > 0 ? "+" : "") . formatString($btc_change, 2);
        $eur_change = (($total_eur - $ref['total_eur']) / $ref['total_eur'])*100;
        $eur_change = ($eur_change > 0 ? "+" : "") . formatString($eur_change, 2);
    }
    print "BTC value: $total_btc (".(isset($ref) ? $btc_change : '-')."%)\n";
    print "EUR value: $total_eur (".(isset($ref) ? $eur_change : '-')."%)\n";

    //save it
    if (!isset($saved_data[$today])) {
        $saved_data[$today] = ['prices' => $prices,
                            'balances' => $balances,
                            'total_btc' => $total_btc,
                            'total_eur' => $total_eur,];
        file_put_contents(FILE, json_encode($saved_data));
    }
    exit();
}

$price = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$crypto}&tsyms=BTC"), true);
var_dump("price= {$price['BTC']}");

$Cashroll = 0;
foreach ($markets as $market) {
    if (array_key_exists($crypto, $market->api->balances)) {
        $bal =  $market->api->balances[$crypto];
        $Cashroll += $bal;
    } else {
        $bal = 'N/A';
    }
    print $market->api->name . ": ". $bal . " $crypto\n";
}
print("Cashroll: $Cashroll $crypto = ".($Cashroll*$price['BTC'])."BTC\n");
