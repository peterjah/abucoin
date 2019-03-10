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
      $this->api =  new $market_table[$market_name]();
    else throw new \Exception("Unknown market \"$market_name\"");

    $this->products = $this->api->getProductList();
  }

  function getBalance() {
    return $this->api->getBalance(null, false);
  }
  function updateProductList() {
    $this->products = $this->api->getProductList();
  }
}

function do_arbitrage($symbol, $sell_market, $sell_price, $buy_market, $buy_price, $trade_size, $arbId)
{
  $sell_api = $sell_market->api;
  $buy_api = $buy_market->api;
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];
  $alt = $buy_product->alt;
  $base = $sell_product->base;
  $alt_bal = $sell_api->balances[$alt];
  $base_bal = $buy_api->balances[$base];

  if($sell_api->PriorityLevel < $buy_api->PriorityLevel) {
    $first_market = $sell_market;
    $first_action = 'sell';
    $second_market = $buy_market;
    $second_action = 'buy';
  }
  else {
    $first_market = $buy_market;
    $first_action = 'buy';
    $second_market = $sell_market;
    $second_action = 'sell';
  }

  print "start with= {$first_market->api->name} \n";
  print "balances: $base_bal $base; $alt_bal $alt \n";

  $buy_price = truncate($buy_price, $buy_product->price_decimals);
  $sell_price = truncate($sell_price, $sell_product->price_decimals);

  print "BUY $trade_size $alt on {$buy_api->name} at $buy_price $base = ".($buy_price*$trade_size)."$base\n";
  print "SELL $trade_size $alt on {$sell_api->name} at $sell_price $base = ".($sell_price*$trade_size)."$base\n";

  $price = $first_action == 'buy' ? $buy_price : $sell_price;

  $i=0;
  while(true)
  {
    try{
      $order_status = $first_market->api->place_order($first_market->products[$symbol], 'limit', $first_action, $price, $trade_size, $arbId);
      break;
    }
    catch(Exception $e){
       print ("unable to $first_action retrying...: $e\n");
       $err = $e->getMessage();
       // if( $err != 'no response from api' || $err != 'EAPI:Invalid nonce' )
       //   print_dbg("unable to $first_action $alt (first action) [$err] on {$first_market->api->name}: tradeSize=$trade_size at $price. try $i");

       if($i == 5 || $err == 'ERROR: Insufficient Funds.' || $err == 'Market is closed.' || $err == 'EOrder:Insufficient Funds.')
         throw new \Exception("unable to $first_action. [$err]");
       if ($err == 'Rest API trading is not enabled.')
         throw new \Exception($err);
       if ($err == "Unable to locate order in history")
         throw new \Exception($err);
       usleep(500000);
       $i++;
    }
  }
  $trade_size = $order_status['filled_size'];

  $ret = [];
  $ret[$first_action] = $order_status;
  $ret[$first_action]['filled_size'] = $trade_size;

  $second_status = [];
  if($trade_size > 0)
  {
    $i=0;
    while(true) {
      try {
        $price = $second_action == 'buy' ? $buy_price : $sell_price;
        $second_status = $second_market->api->place_order($second_market->products[$symbol], 'market', $second_action, $price, $trade_size, $arbId);
        break;
      }
      catch(Exception $e) {
         print ("unable to $second_action retrying...: $e\n");
         var_dump($second_status);
         $err = $e->getMessage();
         if($err =='EOrder:Insufficient funds' || $err == 'insufficient_balance'|| $err == 'ERROR: Insufficient Funds.' ||
            $err == 'Account has insufficient balance for requested action.' || $err == 'Order rejected')
         {
           $second_market->getBalance();
           print_dbg("Insufficient funds to $second_action $trade_size $alt @ $price , base_bal:{$second_market->api->balances[$base]} alt_bal:{$second_market->api->balances[$alt]}");
           if($second_action == 'buy')
           {
             $base_bal = $second_market->api->balances[$base];
             //price may be not relevant anymore. moreover we want a market order
             $book = $buy_product->refreshBook($base_bal, $trade_size);
             $trade_size = min(truncate($base_bal / ($book['asks']['order_price'] * (1 + $buy_product->fees/100)) , $buy_product->size_decimals), $trade_size);
             $buy_price = $book['asks']['order_price'];
             print_dbg("new tradesize: $trade_size, new price $buy_price base_bal: $base_bal");
           } else {
             $alt_bal = $second_market->api->balances[$alt];
             $trade_size = truncate($alt_bal* (1 - $sell_product->fees/100), $sell_product->size_decimals);
           }
         }
         if ($err == "Unable to locate order in history") {
           print_dbg("$err. giving up...");
           throw new \Exception($err);
         }
         if ($err == 'EGeneral:Invalid arguments:volume' || $err == 'Invalid quantity.' || $err == 'invalid_order_size' ||
             $err == 'Filter failure: MIN_NOTIONAL' || $err == 'balance_locked' || $err == 'try_again_later')
         {
           print_dbg("$err. giving up...");
           $trade_size = 0;
           break;
         }
         if ($err == 'Rest API trading is not enabled.')
           throw new \Exception($err);
         if($i == 8){
           $trade_size = 0;
           break;
         }
         $i++;
         usleep(500000);
      }
    }
  }

  $ret[$second_action] = $second_status;
  $ret[$second_action]['filled_size'] = $trade_size;
  return $ret;
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

function check_tradesize($symbol, $sell_market, $sell_price, $buy_market, $buy_price, $trade_size)
{
  $buy_product = $buy_market->products[$symbol];
  $sell_product = $sell_market->products[$symbol];

  $alt_bal = $sell_market->api->balances[$buy_product->alt];
  $base_bal = $buy_market->api->balances[$buy_product->base];

  $min_trade_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
  $min_trade_alt = max($buy_product->min_order_size, $sell_product->min_order_size);
  $size_decimals = min($buy_product->size_decimals, $sell_product->size_decimals);

  if ($base_bal < $min_trade_base || $alt_bal < $min_trade_alt) {
    return 0;
  }

  $buy_price = truncate($buy_price, $buy_product->price_decimals);
  $sell_price = truncate($sell_price, $sell_product->price_decimals);

  $base_to_spend_fee = ($buy_price * $trade_size * (1 + $buy_product->fees/100));

  if ($base_to_spend_fee > $base_bal) {
      $base_to_spend_fee = $base_bal;
      $base_amount = $base_to_spend_fee * (1 - $buy_product->fees/100);
      $trade_size = $base_amount / $buy_price;
  }

  if ($trade_size > $alt_bal) {
    $trade_size = $alt_bal;
  }

  $trade_size = truncate($trade_size, $size_decimals);
  $base_to_spend_fee = ($buy_price * $trade_size * (1 + $buy_product->fees/100));
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
    print_dbg("$file: Unknown websocket stream $product->symbol");
    throw new Exception("$file: Unknown websocket stream $product->symbol");
  }
  return $orderbook[$product->symbol];
}
