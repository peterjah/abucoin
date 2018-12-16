<?php

require_once('../common/cryptopia_api.php');
require_once('../common/kraken_api.php');
require_once('../common/cobinhood_api.php');
require_once('../common/binance_api.php');

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

  public function __construct($params){
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
    $book = null;
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
                      'binance' => 'BinanceApi'
                      ];
    if( isset($market_table[$market_name]))
      $this->api =  new $market_table[$market_name]();
    else throw new \Exception("Unknown market \"$market_name\"");

    $this->products = $this->api->getProductList();
  }

  function refreshBook($product, $depth_base, $depth_alt)
  {
    $depth_base = max($depth_base, $product->min_order_size_base);
    $depth_alt = max($depth_alt, $product->min_order_size);
    return $this->products[$product->symbol]->book = $this->api->getOrderBook($product, $depth_alt, $depth_base);
  }

  function getBalance() {
    $this->api->getBalance();
  }
  function updateProductList() {
    $this->products = $this->api->getProductList();
  }
}

function do_arbitrage($symbol, $sell_market, $sell_price, $buy_market, $buy_price, $tradeSize)
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

  $arbId = substr($sell_api->name, 0, 2) . substr($buy_api->name, 0, 2) . '_' . time();

  print "start with= {$first_market->api->name} \n";
  print "balances: $base_bal $base; $alt_bal $alt \n";

  $min_trade_base = max($buy_product->min_order_size_base, $sell_product->min_order_size_base);
  $min_trade_alt = max($buy_product->min_order_size, $sell_product->min_order_size);

  $size_decimals = min($buy_product->size_decimals, $sell_product->size_decimals);
  print "truncate $tradeSize to size_decimals precision: $size_decimals\n";
  $tradeSize = truncate($tradeSize, $size_decimals);
  print "result: $tradeSize\n";

  $base_amount = $buy_price * $tradeSize;

  $base_to_spend_fee = ($base_amount * (1 + $buy_product->fees/100));
  print "base_amount = $base_amount $base, base_to_spend_fee = $base_to_spend_fee $base\n";
  if($base_to_spend_fee > $base_bal)//Check base balance
  {
    if($base_bal > 0)
    {
      $base_to_spend_fee = $base_bal;
      $base_amount = $base_to_spend_fee * (1 - $buy_product->fees/100);
      $tradeSize = truncate(($base_amount / $buy_price),$size_decimals);
    }
    else
    {
      print "not enough $base \n";
      return 0;
    }
  }

  if($tradeSize > $alt_bal)//check alt balance
  {
    if($alt_bal > 0)
      $tradeSize = truncate($alt_bal, $size_decimals);
    else
    {
      print "not enough $alt \n";
      return 0;
    }
  }
  $base_to_spend_fee = $buy_price * $tradeSize * (1 + $buy_product->fees/100);

  print "BUY $tradeSize $alt on {$buy_api->name} at $buy_price $base = ".($base_to_spend_fee)."$base\n";
  print "SELL $tradeSize $alt on {$sell_api->name} at $sell_price $base = ".($sell_price*$tradeSize)."$base\n";
  //Some checks
  if($base_to_spend_fee < $min_trade_base)
  { //will be removed by tweeking orderbook feed
    print "insufisent tradesize to process. base_to_spend_fee=$base_to_spend_fee min_trade_base = $min_trade_base $base\n";
    return 0;
  }
  if($tradeSize < $min_trade_alt)
  {
    print "insufisent tradesize to process. tradeSize = $tradeSize min_trade_alt = $min_trade_alt $alt\n";
    return 0;
  }
  print "base_to_spend_fee = $base_to_spend_fee for $tradeSize $alt\n";

  $buy_price = truncate($buy_price, $buy_product->price_decimals);
  $sell_price = truncate($sell_price, $sell_product->price_decimals);
  print "truncated: buy_price = $buy_price at {$buy_product->price_decimals} decimals sell_price =  $sell_price at {$sell_product->price_decimals} decimals\n";

  print "base_to_spend_fee = $base_to_spend_fee for $tradeSize $alt\n";

  $price = $first_action == 'buy' ? $buy_price : $sell_price;

  $i=0;
  while(true)
  {
    try{
      $order_status = $first_market->api->place_order($first_market->products[$symbol], 'limit', $first_action, $price, $tradeSize, $arbId);
      break;
    }
    catch(Exception $e){
       print ("unable to $first_action retrying...: $e\n");
       $err = $e->getMessage();
       // if( $err != 'no response from api' || $err != 'EAPI:Invalid nonce' )
       //   print_dbg("unable to $first_action $alt (first action) [$err] on {$first_market->api->name}: tradeSize=$tradeSize at $price. try $i");

       if($i == 5 || $err == 'ERROR: Insufficient Funds.' || $err == 'Market is closed.' || $err == 'EOrder:Insufficient Funds.')
         throw new \Exception("unable to $first_action. [$err]");
       if ($err == 'Rest API trading is not enabled.')
         throw new \Exception($err);
       usleep(500000);
       $i++;
    }
  }
  $tradeSize = $order_status['filled_size'];

  $ret = [];
  $ret[$first_action] = $order_status;
  $ret[$first_action]['filled_size'] = $tradeSize;

  $second_status = [];
  if($tradeSize > 0)
  {
    $i=0;
    while(true) {
      try {
        $price = $second_action == 'buy' ? $buy_price : $sell_price;
        $second_status = $second_market->api->place_order($second_market->products[$symbol], 'market', $second_action, $price, $tradeSize, $arbId);
        break;
      }
      catch(Exception $e) {
         print ("unable to $second_action retrying...: $e\n");

         var_dump($second_status);
         $err = $e->getMessage();
         if($err == 'Order status: rejected') {
           print_dbg("Cobinhood order rejected. update products");
           $second_market->updateProductList();
           $second_market->getBalance();
         }
         if($err =='EOrder:Insufficient funds' || $err == 'insufficient_balance'|| $err == 'ERROR: Insufficient Funds.' ||
            $err == 'Account has insufficient balance for requested action.')
         {
           $second_market->getBalance();
           print_dbg("Insufficient funds to $second_action $tradeSize $alt @ $price , base_bal:{$second_market->api->balances[$base]} alt_bal:{$second_market->api->balances[$alt]}");
           if($second_action == 'buy')
           {
             $base_bal = $second_market->api->balances[$base];
             //price may be not relevant anymore. moreover we want a market order
             $book = $buy_market->refreshBook($buy_product, $base_bal, $tradeSize);
             $tradeSize = min(truncate($base_bal / ($book['asks']['order_price'] * (1 + $buy_product->fees/100)) , $size_decimals), $tradeSize);
             $buy_price = $book['asks']['order_price'];
           } else {
             $alt_bal = $second_market->api->balances[$alt];
             $tradeSize = truncate($alt_bal* (1 - $sell_product->fees/100), $size_decimals);
           }
           print_dbg("new tradesize: $tradeSize, new price $buy_price");
         }
         // if( $err != 'no response from api' && $err != 'EAPI:Invalid nonce' )
         // {
         //   print_dbg("unable to $second_action $alt (second action) [$err] on {$second_market->api->name}: tradeSize=$tradeSize at $price. try $i");
         // }
         if ($err == 'EGeneral:Invalid arguments:volume' || $err == 'Invalid quantity.' || $err == 'invalid_order_size' ||
             $err == 'Filter failure: MIN_NOTIONAL' || $err == 'balance_locked' || $err == 'try_again_later')
         {
           $tradeSize = 0;
           break;
         }
         if ($err == 'Rest API trading is not enabled.')
           throw new \Exception($err);
         if($i == 8){
           $tradeSize = 0;
           break;
         }
         $i++;
         usleep(500000);
      }
    }
  }

  $ret[$second_action] = $second_status;
  $ret[$second_action]['filled_size'] = $tradeSize;
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

function computeGains($buy_price, $fees1, $sell_price, $fees2, $tradeSize)
{
    if (empty($buy_price) || empty($sell_price) || empty($tradeSize))
      return null;
    $spend_base_unit = $buy_price*((100+$fees2)/100);
    $sell_base_unit = $sell_price*((100-$fees1)/100);
    $gain_per_unit = $sell_base_unit - $spend_base_unit;
    $gain_percent = (($sell_base_unit / $spend_base_unit)-1)*100;
    $gain_base = $tradeSize * $gain_per_unit;
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
