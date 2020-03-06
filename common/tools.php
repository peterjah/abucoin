<?php
include "../vendor/autoload.php";

require_once('../common/cryptopia_api.php');
require_once('../common/kraken_api.php');
require_once('../common/cobinhood_api.php');
require_once('../common/binance_api.php');
require_once('../common/paymium_api.php');
date_default_timezone_set("UTC");

class Product
{
  public $symbol;
  public $alt;
  public $base;
  public $min_order_size;
  public $lot_size_step;
  public $size_decimals;
  public $min_order_size_base;
  public $price_decimals;
  public $fees;
  public $book;
  public $api;
  public $symbol_exchange;

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
    $this->symbol_exchange = @$params['symbol_exchange'];

    $book = null;
  }

  function refreshBook($depth_base = 0, $depth_alt = 0)
  {
    $depth_base = max($depth_base, $this->min_order_size_base);
    $depth_alt = max($depth_alt, $this->min_order_size);
    return $this->book = $this->api->getOrderBook($this, $depth_base, $depth_alt);
  }

  function removeBestOffer($side)
  {
    array_slice($this->book[$side], 1);
  }
}

function getProductBySymbol($api, $symbol)
{
  foreach($api->products as $product) {
    if ($product->symbol == $symbol)
      return $product;
    }
}

class Market
{
  public $api;
  public $products;

  public function __construct($market_name)
  {
    $market_table = [ 'cryptopia' => 'CryptopiaApi',
                      'kraken' => 'KrakenApi',
                      'cobinhood' => 'CobinhoodApi',
                      'binance' => 'BinanceApi',
                      'paymium' => 'PaymiumApi'
                      ];
    if( isset($market_table[$market_name]))
      $this->api =  new $market_table[$market_name](0.01);
    else throw new \Exception("Unknown market \"$market_name\"");

    $this->updateProductList();
  }

  function getBalance() {
    return $this->api->getBalance(null, false);
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
  $alt_bal = $sell_api->balances[$alt];
  $base_bal = $buy_api->balances[$base];

  $pid = pcntl_fork();
  if ($pid == -1) {
      throw new \Exception("unable to fork process");
  } else if ($pid) {
     // we are the parent
     print "I am the father pid = $pid\n";
     $status['sell'] = place_order($sell_market, 'limit', $symbol, 'sell', $sell_price, $size, $arbId);
     print "SOLD {$status['sell']['filled_size']} $alt on {$sell_api->name} at {$status['sell']['price']}\n";
  } else {
     // we are the child
     print "I am the child pid = $pid\n";
     $status['buy'] = place_order($buy_market, 'limit', $symbol, 'buy', $buy_price, $size, $arbId);
     print "BOUGHT {$status['buy']['filled_size']} $alt on {$buy_api->name} at {$status['buy']['price']}\n";
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
    $status['buy'] = ['filled_size' => $matches[6], 'price' => $matches[8]];
  }

  foreach(['buy','sell'] as $side) {
    $toSell = $side == 'sell';
    $opSide = $toSell ? 'buy' : 'sell';
    $filled = $status[$opSide]['filled_size'];
    $product = $toSell ? $sell_product : $buy_product;
    $opProduct = $toSell ? $buy_product : $sell_product;
    $market = $toSell ? $sell_market : $buy_market;
    if ($status[$side]['filled_size'] == 0 && $filled > 0) {
      $book = $market->api->getOrderBook($product, $product->min_order_size_base, $filled, false);
      if($toSell) {
        $new_price = $book['bids']['price'];
        $expected_gains = computeGains($status[$opSide]['price'], $opProduct->fees, $new_price, $product->fees, $filled);
      } else {
        $new_price = $book['asks']['price'];
        $expected_gains = computeGains($new_price, $product->fees, $status[$opSide]['price'], $opProduct->fees, $filled);
      }
      print_dbg("last chance to $side $alt at $new_price... expected gains: {$expected_gains["base"]} $base {$expected_gains["percent"]}%", true);
      if ($expected_gains['percent'] >= -0.1) {
        print_dbg("retrying to $side $alt at $new_price", true);
        $status[$side] = place_order($market, 'limit', $symbol, $side, $new_price, $filled, $arbId);
        print "$side {$status[$side]['filled_size']} $alt on {$market->api->name} at {$status[$side]['price']}\n";
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
       $err = $e->getMessage();
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
           $book = $product->refreshBook(0, $size);
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

function get_tradesize($symbol, $sell_market, $buy_market)
{
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];

  $alt_bal = $sell_market->api->balances[$buy_product->alt];
  $base_bal = $buy_market->api->balances[$buy_product->base];

  $min_trade_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
  $min_trade_alt = max($buy_product->min_order_size, $sell_product->min_order_size);
  $size_decimals = min($buy_product->size_decimals, $sell_product->size_decimals);
  $buy_book = $buy_product->refreshBook($min_trade_base, $min_trade_alt);
  $sell_book = $sell_product->refreshBook($min_trade_base, $min_trade_alt);

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

function subscribeWsOrderBook($market, $products_list, $suffix)
{

  $websocket_script = "../common/".strtolower($market->api->name)."_websockets.php";
  if (file_exists($websocket_script)) {
    print ("Subscribing {$market->api->name} Orderbook WS feed\n");
    $market->api->orderbook_file  = "{$market->api->name}_orderbook_{$suffix}.json";
    $products_str = '';
    $idx = 1;
    foreach ($products_list as $symbol) {
      $product = $market->products[$symbol];
      $products_str .= $product->alt . "-" . $product->base;
      if ($idx != count($products_list) )
        $products_str .= ',';
      $idx++;
    }
    $cmd = "nohup php ../common/".strtolower($market->api->name)."_websockets.php --file {$market->api->orderbook_file} --cmd getOrderBook \
           --products {$products_str} --bookdepth {$market->api->orderbook_depth} >/dev/null 2>&1 &";
    print ("$cmd\n");
    shell_exec($cmd);
    sleep(1);
  }
}

function getWsOrderbook($file, $product) {
  $fp = fopen($file, "r");
  flock($fp, LOCK_SH, $wouldblock);
  $orderbook = json_decode(file_get_contents($file), true);
  flock($fp, LOCK_UN);
  fclose($fp);
  $update_timeout = 3;
  if (microtime(true) - $orderbook['last_update'] > $update_timeout) {
    //print_dbg("$file orderbook not uptaded since $update_timeout sec. Switching to rest API");
    return false;
  }
  if (!isset($orderbook[$product->symbol])) {
    print_dbg("$file: Unknown websocket stream $product->symbol", true);
    return false;
  }
  return $orderbook[$product->symbol];
}
