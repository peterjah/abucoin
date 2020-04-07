<?php
include "../vendor/autoload.php";

require_once('../common/cryptopia_api.php');
require_once('../common/kraken_api.php');
require_once('../common/cobinhood_api.php');
require_once('../common/binance_api.php');
require_once('../common/paymium_api.php');
date_default_timezone_set("UTC");

@define('LOSS_TRESHOLD', 0.3); //percent
@define('TRADE_FILE', 'trades');

class Product
{
  public function __construct($params) {
    $this->api = $params['api'];
    $this->alt = $params['alt'];
    $this->base = $params['base'];
    $this->symbol = "{$params['alt']}-{$params['base']}";
    $this->fees = @$params['fees'];
    $this->min_order_size = @$params['min_order_size'] ?: 0;
    $this->lot_size_step = @$params['lot_size_step'];
    $this->size_decimals = @$params['size_decimals'];
    $this->min_order_size_base = @$params['min_order_size_base'] ?: 0;
    $this->price_decimals = @$params['price_decimals'];
    $this->exchange_symbol = @$params['exchange_symbol'];
    $this->ws_name = @$params['ws_name'];
    $this->alt_symbol = @$params['alt_symbol'];
  }

  function refreshBook($side, $depth_base = 0, $depth_alt = 0, $use_rest = true)
  {
    $depth_base = max($depth_base, $this->min_order_size_base);
    $depth_alt = max($depth_alt, $this->min_order_size);
    $book = $this->api->getTickerOrderBook($this);
    if ($side == 'buy' &&
      ($book['asks']['size'] < $depth_alt
      || ($book['asks']['size'] * $book['asks']['price']) < $depth_base)) {
        print("ticker size is too low\n");
        if ($use_rest) {
            return $this->book = $this->api->getOrderBook($this, $depth_base, $depth_alt);
        }
        return false;
    }

    if ($side == 'sell' &&
    ($book['bids']['size'] < $depth_alt
    || ($book['bids']['size'] * $book['bids']['price']) < $depth_base)) {
      print("ticker size is too low\n");
      if ($use_rest) {
          return $this->book = $this->api->getOrderBook($this, $depth_base, $depth_alt);
      }
      return false;
    }

    return $this->book = $book;
  }
}

function getProductByParam($products, $param, $value)
{
  foreach($products as $product) {
    if (isset($product->$param)) {
      if ($product->$param == $value) {
        return $product;
      }
    } else {
      new \Exception("Unknown market param\"$param\"");
    }
  }
  return null;
}

class Market
{
  public function __construct($market_name)
  {
    $market_table = [ 'kraken' => 'KrakenApi',
                      'binance' => 'BinanceApi',
                      'paymium' => 'PaymiumApi'
                      ];
    if( isset($market_table[$market_name]))
      $this->api =  new $market_table[$market_name]();
    else throw new \Exception("Unknown market \"$market_name\"");

    $this->updateProductList();

    $this->getBalance();
  }

  function getBalance() {
    return $this->api->getBalance();
  }

  function updateProductList() {
    $this->products = $this->api->getProductList();
  }
}

function async_arbitrage($symbol, $sell_market, $sell_price, $buy_market, $buy_price, $size, $arbId)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];
  $alt = $buy_product->alt;
  $base = $buy_product->base;

  $pid = pcntl_fork();
  if ($pid == -1) {
      throw new \Exception("unable to fork process");
  } else if ($pid) {
     // we are the parent
     print "I am the father pid = $pid\n";
     $status['sell'] = place_order($sell_market, 'limit', $symbol, 'sell', $sell_price, $size, $arbId);
     print_dbg("SOLD {$status['sell']['filled_size']} $alt on {$sell_api->name} at {$status['sell']['price']}. expected {$sell_product->book['bids']['price']}\n",true);
  } else {
     // we are the child
     print "I am the child pid = $pid\n";
     $status['buy'] = place_order($buy_market, 'limit', $symbol, 'buy', $buy_price, $size, $arbId);
     print_dbg("BOUGHT {$status['buy']['filled_size']} $alt on {$buy_api->name} at {$status['buy']['price']}. expected {$buy_product->book['asks']['price']}\n",true);
     exit();
  }
  pcntl_waitpid($pid, $stat);
  var_dump($stat);
  //get child status:
  $grep = shell_exec("tail -n20 trades | grep \"{$arbId} " . ucfirst($buy_api->name) .'"' );
  if (empty($grep)) {
    print_dbg("failed to retrieve child trade status", true);
    $status['buy'] = ['filled_size' => 0, 'price' => 0];
  } else {
    preg_match('/^(.*): arbitrage: (.*) ([a-zA-Z]+): trade (.*): ([a-z]+) ([.-E0-]+) ([A-Z]+) at ([.-E0-]+) ([A-Z]+)$/',$grep, $matches);
    if (count($matches) !== 10) {
      print_dbg("Invalid match count...",true);
    }
    $status['buy'] = ['filled_size' => $matches[6], 'price' => $matches[8]];
  }

  foreach(['buy','sell'] as $side) {
    $toSell = $side == 'sell';
    $opSide = $toSell ? 'buy' : 'sell';
    $filled = $status[$opSide]['filled_size'];
    $product = $toSell ? $sell_product : $buy_product;
    $opProduct = $toSell ? $buy_product : $sell_product;
    $market = $toSell ? $sell_market : $buy_market;
    $newStatus = [];
    if ($status[$side]['filled_size'] < $filled ) {
      $size = $filled - $status[$side]['filled_size'];
      $book = $market->api->getOrderBook($product, $product->min_order_size_base, $size);
      if($toSell) {
        $new_price = $book['bids']['price'];
        $expected_gains = computeGains($status[$opSide]['price'], $opProduct->fees, $new_price, $product->fees, $size);
      } else {
        $new_price = $book['asks']['price'];
        $expected_gains = computeGains($new_price, $product->fees, $status[$opSide]['price'], $opProduct->fees, $size);
      }
      $base_bal = $market->api->balances[$base];
      $size = min(truncate($base_bal / ($new_price * (1 + $product->fees/100)) , $product->size_decimals), $size);

      if (($size >= $product->min_order_size) && ($size * $new_price >= $product->min_order_size_base)) {
        print_dbg("last chance to $side $size $alt at $new_price... expected gains: {$expected_gains["base"]} $base {$expected_gains["percent"]}%", true);
        if ($expected_gains['percent'] >= (-1 * LOSS_TRESHOLD)) {
          print_dbg("retrying to $side $alt at $new_price", true);

          $isCompositeTrade = $status[$side]['filled_size'] > 0;
          $newStatus = place_order($market, 'limit', $symbol, $side, $new_price, $size, $arbId, !$isCompositeTrade);
          print "$side {$newStatus['filled_size']} $alt on {$market->api->name} at {$newStatus[$side]['price']}\n";

          if ($isCompositeTrade) {
            $status[$side]['price'] = (($status[$side]['price'] * $status[$side]['filled_size']) + ($newStatus['filled_size'] * $newStatus['price']) /
            ($status[$side]['filled_size'] + $newStatus['filled_size']));
              $status[$side]['filled_size'] += $newStatus['filled_size'];

              //delete first pass trade and write new one
              $fp = fopen(TRADES_FILE, "r+");
              flock($fp, LOCK_EX, $wouldblock);
              file_put_contents(TRADES_FILE, preg_replace ( '/.*'. $arbId .' '. $market->api->name . ' .*\n/' , '' , file_get_contents(TRADES_FILE) ));
              flock($fp, LOCK_UN);
              fclose($fp);
              // save new trade
              $market->api->save_trade('composite', $product, $side, $status[$side]['filled_size'], $status[$side]['price'], $arbId);
          } else {
            $status[$side] = $newStatus;
          }

        }
      }
      unlink($market->api->orderbook_file);
      print_dbg("Restarting {$market->api->name} websockets", true);
    }
  }

  return $status;
}

function place_order($market, $type, $symbol, $side, $price, $size, $arbId)
{
  $product = $market->products[$symbol];
  $alt = $product->alt;
  $base = $product->base;
  $i=0;
  while(true) {
    try {
      return $market->api->place_order($product, $type, $side, $price, $size, $arbId);
    }
    catch(Exception $e) {
       $err = $e->msg();
       print_dbg("unable to $side retrying. $i ..: {$err}", true);
       if($err =='EOrder:Insufficient funds' || $err == 'insufficient_balance' || $err == 'ERROR: Insufficient Funds.' ||
          $err == 'Account has insufficient balance for requested action.' || $err == 'Order rejected')
       {
         $market->getBalance();
         print_dbg("Insufficient funds to $side $size $alt @ $price , base_bal:{$market->api->balances[$base]} alt_bal:{$market->api->balances[$alt]}", true);
         if($side == 'buy')
         {
           $base_bal = $market->api->balances[$base];
           //price may be not relevant anymore. moreover we want a market order
           $book = $product->refreshBook($side, 0, $size);
           $size = min(truncate($base_bal / ($book['asks']['price'] * (1 + $product->fees/100)) , $product->size_decimals), $size);
           $buy_price = $book['asks']['order_price'];
           print_dbg("new tradesize: $size, new price $buy_price base_bal: $base_bal", true);
         } else {
           $alt_bal = $market->api->balances[$alt];
           $size = truncate($alt_bal* (1 - $product->fees/100), $product->size_decimals);
         }
       }

       if ($err == 'EGeneral:Invalid arguments:volume' || $err == 'Invalid quantity.' || $err == 'invalid_order_size' ||
           $err == 'Filter failure: MIN_NOTIONAL' || $err == 'balance_locked' || $err == 'try_again_later')
       {
         print_dbg("$err. giving up...");
         break;
       }
       if ($err == 'Rest API trading is not enabled.' || $err == "Unable to locate order in history")
         throw new \Exception($err);
       if($i == 8){
         break;
       }
       $i++;
       usleep(500000);
    }
  }
  throw new \Exception($err);
}

function ceiling($number, $significance = 1)
{
    return ( is_numeric($number) && is_numeric($significance) ) ? (ceil($number/$significance)*$significance) : false;
}

function truncate($number, $decimals)
{
  return floatval(bcdiv(number_format($number,8,'.',''), 1, $decimals));
}

function getCommonProducts($market1, $market2)
{
  $symbols1 = array_keys($market1->products);
  $symbols2 = array_keys($market2->products);

  return array_values(array_intersect($symbols1, $symbols2));
}

function computeGains($buy_price, $fees1, $sell_price, $fees2, $trade_size)
{
    if (empty($buy_price) || empty($sell_price) || empty($trade_size))
      throw new \Exception("Unable to compute gains");
    $spend_base_unit = $buy_price*((100+$fees2)/100);
    $sell_base_unit = $sell_price*((100-$fees1)/100);
    $gain_per_unit = $sell_base_unit - $spend_base_unit;
    $gain_percent = (($sell_base_unit / $spend_base_unit)-1)*100;
    $gain_base = $trade_size * $gain_per_unit;
    return ['percent' => $gain_percent,
            'base' => $gain_base ];
}

function print_dbg($dbg_str, $print_stderr = false)
{
  $str = date("Y-m-d H:i:s")." $dbg_str\n";
  file_put_contents('debug',$str,FILE_APPEND);
  if ($print_stderr)
    print($str);
}

function get_tradesize($symbol, $sell_market, $sell_book, $buy_market, $buy_book)
{
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];

  $alt_bal = $sell_market->api->balances[$buy_product->alt];
  $base_bal = $buy_market->api->balances[$buy_product->base];

  $min_trade_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
  $min_trade_alt = max($buy_product->min_order_size, $sell_product->min_order_size);
  $size_decimals = min($buy_product->size_decimals, $sell_product->size_decimals);

  // get first order size
  $trade_size = min($sell_book['bids']['size'], $buy_book['asks']['size']);

  $buy_order_price = $buy_book['asks']['price'];

  // not enough founds
  if ($base_bal < $min_trade_base || $alt_bal < $min_trade_alt) {
    return 0;
  }

  $base_to_spend_fee = ($buy_order_price * $trade_size * (1 + $buy_product->fees/100));

  if ($base_to_spend_fee > $base_bal) {
      $base_to_spend_fee = $base_bal;
      $base_amount = $base_to_spend_fee * (1 - $buy_product->fees/100);
      $trade_size = $base_amount / $buy_order_price;
  }

  if ($trade_size > $alt_bal) {
    $trade_size = $alt_bal;
  }

  $trade_size = truncate($trade_size, $size_decimals);
  $base_to_spend_fee = ($buy_order_price * $trade_size * (1 + $buy_product->fees/100));
  if ($base_to_spend_fee < $min_trade_base || $trade_size < $min_trade_alt) {
    return 0;
  }

  return $trade_size;
}

function subscribeWsOrderBook($market_name, $symbol_list, $depth)
{
  $websocket_script = "../common/".strtolower($market_name)."_websockets.php";
  if (file_exists($websocket_script)) {
    $suffix = getmypid();
    print ("Subscribing {$market_name} Orderbook WS feed\n");
    $orderbook_file  = "{$market_name}_orderbook_{$suffix}.json";
    $products_str = implode(",", $symbol_list);

    $cmd = "nohup php ../common/".strtolower($market_name)."_websockets.php --file {$orderbook_file} --cmd getOrderBook \
           --products $products_str --bookdepth $depth >/dev/null 2>&1 &";
    print ("$cmd\n");
    shell_exec($cmd);
    sleep(1);
    return $orderbook_file;
  }
}

function getWsOrderbook($file) {
  $fp = fopen($file, "r");
  flock($fp, LOCK_SH, $wouldblock);
  $orderbook = json_decode(file_get_contents($file), true);
  flock($fp, LOCK_UN);
  fclose($fp);
  $update_timeout = 30;
  if (microtime(true) - $orderbook['last_update'] > $update_timeout) {
    throw new \Exception("$file orderbook not uptaded since $update_timeout sec");
  }
  return $orderbook;
}
