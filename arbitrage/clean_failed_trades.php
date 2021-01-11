<?php
require_once('../common/tools.php');

//Todo: add an option to import failed trade in a new trade file.

// Todo: use getopt ....$options = getopt('s:l', array(
//   'solve:',
//   'login:',

//Todo: use a database !

@define('TRADES_FILE', "trades");
@define('GAINS_FILE', 'gains.json');
@define('STOP_LOSS_PERCENT', '-2');
@define('STOP_LOSS_EUR', '-5');
@define('TAKE_PROFIT_PERCENT', '2');
@define('TAKE_PROFIT_EUR', '5');
@define('LOOP_TIME_MIN', '5');

if (@$argv[1] == '-solve' && isset($argv[2])) {
    $fp = fopen(TRADES_FILE, "r");
    flock($fp, LOCK_SH, $wouldblock);
    $ret = str_replace($argv[2], 'solved', file_get_contents(TRADES_FILE), $count);
    fclose($fp);
    if ($count > 0) {
        file_put_contents(TRADES_FILE, $ret, LOCK_EX);
        print "Tx $argv[2] marked as solved\n";
    } else {
        print "Tx $argv[2] not found or already solved\n";
    }
    exit();
}
if (@$argv[1] == '-auto-solve') {
    $autoSolve=true;
}

while (1) {
    if (@$autoSolve) {
        $ledger = parseTradeFile();
        print "$$$$$$$$$$$$$$$$$$ First pass $$$$$$$$$$$$$$$$$$\n";
        foreach ($ledger as $symbol => $trades) {
            if (count($trades)) {
                print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
                $traded = processFailedTrades(@$markets, $symbol, $trades);
                if (!empty($traded)) {
                    firstPassSolve($traded);
                }
            }
        }
        //init api
        $markets = [];
        // Ordered by trade fee
        foreach (['binance', 'kraken'] as $name) {
            $i=0;
            while ($i<6) {
                try {
                    $markets[$name] = new Market($name);
                    $markets[$name]->api->getBalance();
                    break;
                } catch (Exception $e) {
                    print "failed to get market $name: $e \n";
                    usleep(500000);
                    $i++;
                }
            }
        }
    }

    print "$$$$$$$$$$$$$$$$$$ Second pass $$$$$$$$$$$$$$$$$$\n";
    $ledger = parseTradeFile();
    foreach ($ledger as $symbol => $trades) {
        if (count($trades)) {
            print "$$$$$$$$$$$$$$$$$$ $symbol $$$$$$$$$$$$$$$$$$\n";
            $traded = processFailedTrades(@$markets, $symbol, $trades);
            foreach (['buy','sell'] as $side) {
                $size = @$traded[$side]['size'];
                if ($size > 0) {
                    print "$side: size= {$size} price= {$traded[$side]['price']} mean_fee= {$traded[$side]['mean_fees']}\n";
                    if (@$autoSolve) {
                        try {
                            do_solve($markets, $symbol, $side, $traded[$side]);
                        } catch (Exception $e) {
                            print_dbg("failed to solve $side $symbol: {$e->getMessage()}", true);
                        }
                    }
                }
            }
        }
    }

    if (@$autoSolve) {
        sleep(LOOP_TIME_MIN * 60);
    }
    else {
        break;
    }
}

function do_solve($markets, $symbol, $side, $traded)
{
    print "trying to solve tx..\n";
    $bestGain = null;
    $bestMarket = null;
    $bestPrice = null;
    $takeProfit = false;
    $stopLoss = false;
    foreach ($markets as $market) {
        $api = $market->api;
        print "try with {$api->name}\n";
        if (!isset($market->products[$symbol])) {
            continue;
        }

        $product = $market->products[$symbol];
        $alt_bal = $api->balances[$product->alt];
        $base_bal = $api->balances[$product->base];

        $size = truncate($traded['size'], $product->size_decimals);
        if ($size < $product->min_order_size) {
            continue;
        }
        try {
            $book = $product->refreshBook($side, 0, $size);
        } catch (Exception $e) {
            print_dbg("{$e->getMessage()}: continue..", true);
            continue;
        }
        if ($side == 'buy') {
            $price = $book['bids']['price'];
            $action = 'sell';
            $expected_gains = computeGains($traded['price'], $traded['mean_fees'], $price, $product->fees, $size);
            if ($alt_bal < $size) {
                continue;
            }
        } else {
            $action = 'buy';
            $price = $book['asks']['price'];
            $expected_gains = computeGains($price, $product->fees, $traded['price'], $traded['mean_fees'], $size);
            if ($base_bal < $size * $price) {
                continue;
            }
        }

        if ($size * $price < $product->min_order_size_base) {
            continue;
        }
        $eurPrice = json_decode(file_get_contents("https://min-api.cryptocompare.com/data/price?fsym={$product->base}&tsyms=EUR"), true)['EUR'];
        $gainEur = $eurPrice * $expected_gains['base'];
        $takeProfit = $takeProfit || ($expected_gains['percent'] >= TAKE_PROFIT_PERCENT) || ($gainEur >= TAKE_PROFIT_EUR);
        $stopLoss = $stopLoss || ($expected_gains['percent'] <= STOP_LOSS_PERCENT) || ($gainEur <= STOP_LOSS_EUR);

        if ($bestGain === null || $expected_gains['percent'] > $bestGain['percent']) {
            $bestGain = $expected_gains;
            $bestMarket = $market;
            $bestPrice = $price;
        }
        print_dbg("{$market->api->name}: $action $symbol: expected gain/loss: {$gainEur}EUR {$expected_gains['percent']}%", true);
    }

    if ($takeProfit || $stopLoss) {
        $api = $bestMarket->api;
        $product = $market->products[$symbol];
        $size = truncate($traded['size'], $product->size_decimals);

        $i=0;
        while ($i<6) {
            try {
                print_dbg("Trade cleaner: {$api->name}: $action $size $product->alt @ {$price} $product->base");
                $status = $api->place_order($product, 'market', $action, $bestPrice, $size, $stopLoss ? 'stop_loss' : 'solved');
                print_dbg("Trade cleaner: filled: {$status['filled_size']}");
                break;
            } catch (Exception $e) {
                print_dbg("{$api->name}: Unable to $action :  $e");
                usleep(500000);
                $i++;
            }
        }
        if (@$status['filled_size'] > 0) {
            markSolved(array_keys($traded['ids']), $stopLoss);
            $arbitrage_log = [ 'date' => date("Y-m-d H:i:s"),
                    'alt' => $product->alt,
                    'base' => $product->base,
                    'id' => $stopLoss ? 'stop_loss' : 'solved',
                    'expected_gains' => $bestGain,
                    ];

            if ($action == 'buy') {
                $gains = computeGains($status['price'], $product->fees, $traded['price'], $traded['mean_fees'], $status['filled_size']);
                $stats = ['buy_price_diff' => ($status['price'] * 100 / $price) - 100];
                $arbitrage_log['buy_market'] = $api->name;
            } else {
                $gains = computeGains($traded['price'], $traded['mean_fees'], $status['price'], $product->fees, $status['filled_size']);
                $stats = ['sell_price_diff' => ($status['price'] * 100 / $price) - 100];
                $arbitrage_log['sell_market'] = $api->name;
            }
            $arbitrage_log['final_gains'] = $gains;
            $arbitrage_log['stats'] = $stats;
            print_dbg("solved on $api->name: size:{$status['filled_size']} $product->alt, price:{$status['price']} $product->base");
            save_gain($arbitrage_log);

            if($status['filled_size'] < $size) {
                save_trade($product->alt, $product->base, $side, $size - $status['filled_size'], $traded['price'], "partialSolve");
            }
        }
    }
}

function processFailedTrades($markets, $symbol, $ops)
{
    $ret = [];
    foreach (['buy', 'sell'] as $side) {
        $ret[$side]['size'] = 0;
        $ret[$side]['price'] = 0;
        $ret[$side]['mean_fees'] = 0;
        foreach ($ops as $id => $op) {
            if ($op['side'] != $side) {
                continue;
            }
            $exchange = strtolower($op['exchange']);
            if (isset($markets[$exchange])) {
                $market = $markets[$exchange];
                $product = @$market->products[$symbol];
                $fees = @$product->fees;
            }

            $ret[$side]['price'] = ($ret[$side]['price'] * $ret[$side]['size'] + $op['price'] * $op['size']) / ($ret[$side]['size'] + $op['size']);
            $ret[$side]['mean_fees'] = ($ret[$side]['mean_fees'] * $ret[$side]['size'] + @$fees * $op['size']) / ($ret[$side]['size'] + $op['size']);
            $ret[$side]['size'] += $op['size'];
            $ret[$side]['ids'][$id] = $op;
            print("{$op['line']}\n");
        }
    }
    return $ret;
}

function firstPassSolve($traded)
{
    $buy = $traded['buy'];
    $sell = $traded['sell'];
    if ($buy['size'] > 0 && $sell['size'] > 0 && $buy['price'] < $sell['price']) {
        if ($buy['size'] > $sell['size']) {
            $size = $sell['size'];
            $res_size = $buy['size'] - $sell['size'];
            $res_side = 'buy';
            $res_price = $buy['price'];
        } else {
            $size = $buy['size'];
            $res_size = $sell['size'] - $buy['size'];
            $res_side = 'sell';
            $res_price = $sell['price'];
        }
        firstPassSolveSide($size, $res_size, $res_side, $res_price, $traded);
    }
}

function firstPassSolveSide($size, $res_size, $res_side, $res_price, $traded)
{
    $buy = $traded['buy'];
    $sell = $traded['sell'];
    $gains = computeGains($buy['price'], $buy['mean_fees'], $sell['price'], $sell['mean_fees'], $size);
    if ($gains['base'] > 0) {
        //solve all trade
        markSolved(array_keys($buy['ids']));
        markSolved(array_keys($sell['ids']));
        //generate new trade to solve
        $op = array_values($buy['ids']);
        $alt = $op[0]['alt'];
        $base = $op[0]['base'];
        if ($res_size > 0) {
            save_trade($alt, $base, $res_side, $res_size, $res_price);
        }
        $arbitrage_log = [ 'date' => date("Y-m-d H:i:s"),
                   'alt' => $alt,
                   'base' => $base,
                   'id' => 'solved',
                   'expected_gains' => $gains,
                   'final_gains' => $gains,
                 ];
        if ($res_side == 'buy') {
            $arbitrage_log['buy_market'] = 'Trade Cleaner';
        } else {
            $arbitrage_log['sell_market'] = 'Trade Cleaner';
        }
        print_dbg("first pass solved: size:$size $alt, gain:{$gains['base']} $base");
        save_gain($arbitrage_log);
    }
}

function markSolved($ids, $stopLoss = false)
{
    foreach ($ids as $id) {
        print_dbg("mark $id " . ($stopLoss ? 'stop_loss' : 'solved'));
        $fp = fopen(TRADES_FILE, "r+");
        flock($fp, LOCK_EX, $wouldblock);
        file_put_contents(TRADES_FILE, str_replace($id, $stopLoss ? 'stop_loss' : 'solved', file_get_contents(TRADES_FILE)));
        fclose($fp);
    }
}

function save_trade($alt, $base, $side, $size, $price, $id = "toSolve")
{
    $timestamp = intval(microtime(true)*1000);
    $trade_str = date("Y-m-d H:i:s").": arbitrage: {$id}_" . $timestamp ." cleaner: trade cleanerTx: $side $size $alt at $price $base\n";
    file_put_contents(TRADES_FILE, $trade_str, FILE_APPEND | LOCK_EX);
}

function save_gain($arbitrage_log)
{
    $fp = fopen(GAINS_FILE, "r+");
    flock($fp, LOCK_EX, $wouldblock);
    $gains_logs = json_decode(file_get_contents(GAINS_FILE), true);
    $gains_logs['arbitrages'][] = $arbitrage_log;
    file_put_contents(GAINS_FILE, json_encode($gains_logs));
    fclose($fp);
}

function parseTradeFile()
{
    $handle = fopen(TRADES_FILE, "r");
    $ledger = [];
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            preg_match('/^(.*): arbitrage: (.*) ([a-zA-Z]+): trade (.*): ([a-z]+) ([.-E0-]+) ([A-Z]+) at ([.-E0-]+) ([A-Z]+)$/', $line, $matches);

            if (count($matches) == 10) {
                $symbol = "{$matches[7]}-{$matches[9]}";
                $tradeInfos = [
          'date' => $matches[1],
          'side' => $matches[5],
          'size' => floatval($matches[6]),
          'price' => $matches[8],
          'id' => $matches[4],
          'exchange' => $matches[3],
          'line' => trim($line),
          'alt' => $matches[7],
          'base' => $matches[9],
          'symbol' => $symbol,
        ];
                $OpId = $matches[2];
        
                if ($OpId != 'solved' && $OpId != 'stop_loss') {
                    $size = $tradeInfos['size'];
                    if (!isset($ledger[$symbol]) || !isset($ledger[$symbol][$OpId]['isFailed']) || $ledger[$symbol][$OpId]['isFailed'] === false) {
                        if ($size == 0) { //special for tradecleaner TX
                            markSolved([$OpId]);
                        } else {
                            $ledger[$symbol][$OpId] = $tradeInfos;
                        }
                        $ledger[$symbol][$OpId]['isFailed'] = true;
                    } else {
                        if ($ledger[$symbol][$OpId]['size'] != $size) {
                            print "$symbol Different size trade: {$ledger[$symbol][$OpId]['side']} {$ledger[$symbol][$OpId]['size']} != {$tradeInfos['side']} $size\n";
                            if ($ledger[$symbol][$OpId]['size'] < $size) {
                                $ledger[$symbol][$OpId]['side'] = $tradeInfos['side'];
                                $ledger[$symbol][$OpId]['exchange'] = $tradeInfos['exchange'];
                                $ledger[$symbol][$OpId]['price'] = $tradeInfos['price'];
                            }
                            $ledger[$symbol][$OpId]['size'] = abs($ledger[$symbol][$OpId]['size'] - $size);

                            $new_line = "{$tradeInfos['date']}: arbitrage: $OpId {$ledger[$symbol][$OpId]['exchange']}: trade {$tradeInfos['id']}: {$ledger[$symbol][$OpId]['side']} "
                           ."{$ledger[$symbol][$OpId]['size']} {$tradeInfos['alt']} at {$ledger[$symbol][$OpId]['price']}";
                            $ledger[$symbol][$OpId]['line'] = $new_line;
                        } else {
                            $ledger[$symbol][$OpId]['isFailed'] = false;
                        }
                    }
                }
            } else {
                print "following line doesnt match the regex:\n";
                print "$line\n";
            }
        }
        fclose($handle);
    }
    foreach ($ledger as $idx => $symb) {
        foreach ($symb as $op => $trade) {
            if ($trade['isFailed'] === false) {
                unset($ledger[$idx][$op]);
            }
        }
    }
    return $ledger;
}
