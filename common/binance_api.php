<?php

class BinanceAPIException extends ErrorException {};

class BinanceApi
{
    const API_URL = 'https://www.binance.com/api/';

    protected $api_key;
    protected $api_secret;
    protected $curl;
    protected $account_id;
    public $api_calls_rate;
    protected $api_calls;
    protected $time;
    public $name;
    protected $products;
    public $balances;

    protected $default_curl_opt = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => "",
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT        => 5
    ];

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->api_key = $keys->binance->api_key;
        $this->api_secret = $keys->binance->secret;

        $this->api_calls = 0;
        $this->name = 'Binance';

        $this->PriorityLevel = 1;
        //App specifics
        $this->products = [];
        $this->balances = [];
        $this->api_calls = 0;
        $this->api_calls_rate = 0;
        $this->time = time();
    }

    public function jsonRequest($method = null, $path, array $params = [])
    {
      $this->api_calls++;
      $now = time();
      if (($now - $this->time) > 60) {
        $this->api_calls_rate = $this->api_calls;
        $this->api_calls = 0;
        $this->time = $now;
      }

      $public_set = array( 'v1/depth', 'v1/exchangeInfo', 'v1/ping');

      $opt = $this->default_curl_opt;
      $url = self::API_URL . $path;
      $headers = array(
          "X-MBX-APIKEY: {$this->api_key}"
          );
      if ( !in_array($path ,$public_set ) )
      { //private method
        $params['timestamp'] = number_format(microtime(true) * 1000, 0, '.', '');
        $query = http_build_query($params, '', '&');
        $signature = hash_hmac('sha256', $query, $this->api_secret);
        $url .= '?' . $query . "&signature={$signature}";
      }
      else if (count($params) > 0) {
        $query = http_build_query($params, '', '&');
        $url .= '?' . $query;
      }

      if ($method !== null)
        $opt[CURLOPT_CUSTOMREQUEST] = $method;

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_USERAGENT, "User-Agent: Mozilla/4.0 (compatible; PHP Binance API)");
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt_array($ch, $opt);

      $content = curl_exec($ch);
      $errno   = curl_errno($ch);
      $errmsg  = curl_error($ch);
      $header  = curl_getinfo($ch);
      curl_close($ch);
      if ($errno !== 0)
        return ["error" => $errmsg];
      $content = json_decode($content, true);

      if (null === $content && json_last_error() !== JSON_ERROR_NONE) {
        return ["error" => "json_decode() error $errmsg"];
      }
      if(isset($content['code']) && $content['code'] < 0)
      {
        if( substr_compare($content['msg'],'Way too many requests;',0,20) == 0)
        {
          print_dbg ("Too many api calls on Binance. Sleeping....");
          sleep(60);
        }
        throw new BinanceAPIException($content['msg']);
      }
      return $content;
    }

    function getBalance($alt = null)
    {
      $res = [];
      $i=0;
      while (true) {
        try {
          $balances = $this->jsonRequest('GET', "v3/account");
          if( isset($balances['balances']))
            $balances = $balances['balances'];
          else {
            throw new BinanceAPIException('failed to get balances');
          }
          break;
        }
        catch (Exception $e) {
          if($i > 8)
            throw new BinanceAPIException("failed to get balances [{$e->getMessage()}]");
          $i++;
          print "{$this->name}: failed to get balances. retry $i...\n";
          usleep(50000);
        }
      }

      foreach($balances as $bal) {
        $crypto = self::binance2crypto($bal['asset']);
        $res[$crypto] = $this->balances[$crypto] = floatval($bal['free']);
      }

      if($alt != null)
       return $res[$alt];
      else return $res;
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET','v1/exchangeInfo')["symbols"];
      //var_dump($products);
      foreach($products as $product) {
        if ($product['status'] == 'TRADING') {
          $alt = self::binance2crypto($product['baseAsset']);
          $base = self::binance2crypto($product['quoteAsset']);
          $params = [ 'api' => $this,
                      'alt' => $alt,
                      'base' => $base,
                      'fees' => 0.075,
                    ];
          foreach($product['filters'] as $filter) {
            if ($filter['filterType'] == 'PRICE_FILTER') {
              $params['price_decimals'] = strlen(substr(strrchr(rtrim($filter['tickSize'],0), "."), 1));
            }
            if ($filter['filterType'] == 'LOT_SIZE') {
              $params['min_order_size'] = floatval($filter['minQty']);
              $params['size_decimals'] = strlen(substr(strrchr(rtrim($filter['stepSize'],0), "."), 1));
              $params['lot_size_step'] = floatval($filter['stepSize']);
            }
            if ($filter['filterType'] == 'MIN_NOTIONAL') {
              $params['min_order_size_base'] = floatval($filter['minNotional']);
            }
          }
          $product = new Product($params);
          $list[$product->symbol] = $product;

          if (!isset($this->balances[$alt]))
            $this->balances[$alt] = 0;
          if (!isset($this->balances[$base]))
            $this->balances[$base] = 0;
        }
      }
      $this->products = $list;
      return $list;
    }

    function getOrderBook($product, $depth_alt = 0, $depth_base = 0)
    {
      $symbol = self::crypto2binance($product->alt) . self::crypto2binance($product->base);
      $max_orders = 50;
      $i=0;
      while (true) {
        try {
          $book = $this->jsonRequest('GET', 'v1/depth', ['symbol' => $symbol, 'limit' => $max_orders]);
          break;
        } catch (Exception $e) {
          if($i > 8)
            throw new BinanceAPIException("failed to get order book [{$e->getMessage()}]");
          $i++;
          print "{$this->name}: failed to get order book. retry $i...\n";
          usleep(50000);
        }
      }
      if(!isset($book['asks'][0][0], $book['bids'][0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][1]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_base)
              || ($best[$side]['size'] < $depth_alt) )
              && $i<$max_orders)
        {
          if (!isset($book[$side][$i][0], $book[$side][$i][1]))
            break;
          $size = floatval($book[$side][$i][1]);
          $price = floatval($book[$side][$i][0]);
          $best[$side]['price'] = ($best[$side]['price']*$best[$side]['size'] + $price*$size) / ($size+$best[$side]['size']);
          $best[$side]['size'] += $size;
          $best[$side]['order_price'] = floatval($book[$side][$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }


    function place_order($product, $type, $side, $price, $size, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      $table = ['sell' => 'SELL', 'buy' => 'BUY'];
      $table2 = ['market' => 'MARKET', 'limit' => 'LIMIT'];
      $orderSide = $table[$side];
      $orderType = $table2[$type];

      $size_str = number_format($size, $product->size_decimals, '.', '');
      $order = ['symbol' =>  self::crypto2binance($alt) . self::crypto2binance($base),
                'quantity'=>  $size_str,
                'side'=> $orderSide,
                'type'=> $orderType,
                ];

      if($type == 'limit')
      {
        $order['price'] = number_format($price, $product->price_decimals, '.', '');
        $order['timeInForce'] = 'GTC';
      }

      var_dump($order);
      $status = $this->jsonRequest('POST', 'v3/order', $order);
      print "{$this->name} trade says:\n";
      var_dump($status);

      if( count($status) && !isset($status['code']))
      {
        $id = $status['orderId'];
        $filled_size = 0;
        $filled_base = 0;

        if($status['status'] == 'FILLED')
        {
          $filled_size = floatval($status['executedQty']);
          $filled_base = floatval($status['cummulativeQuoteQty']);
          /*real price*/
          $pond_price = 0;
          foreach($status['fills'] as $fills)
          {
            $pond_price += $fills['price'] * $fills['qty'];
          }
          $price = $pond_price / $filled_size;
        }
        else
        {
          sleep(3);
          $this->cancelOrder($product, $id);
          $status = $this->getOrderStatus($product, $id);
          print_dbg("Check {$this->name} order $id status: {$status['status']} $side $alt filled:{$status['filled']} ");
          var_dump($status);
          $filled_size = $status['filled'];
          $filled_base = $status['filled_base'];
        }
        if ($filled_size > 0)
          $this->save_trade($id, $product, $side, $filled_size, $price, $tradeId);
        return ['filled_size' => $filled_size, 'id' => $id, 'filled_base' => $filled_base, 'price' => $price];
      }
      else
        throw new BinanceAPIException("place order failed: {$status['msg']}");
    }

    function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price $base\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function getOrderStatus($product, $orderId)
    {
      print "get order status of $orderId \n";
      $i=0;
      $alt = self::crypto2binance($product->alt);
      $base = self::crypto2binance($product->base);
      $symbol = "{$alt}{$base}";
      while($i<5)
      {
        try{
          $order = $this->jsonRequest('GET', 'v3/order', ["symbol" => $symbol, "orderId" => $orderId]);
          break;
        }catch (Exception $e){ $i++; usleep(500000); print ("{$this->name}: Failed to get status retrying...$i\n");}
      }

      var_dump($order);
      if(isset($order))
      {
        if ($order['status'] == 'FILLED')
          $status = 'closed';
        else
          $status = 'open';

        return  $status = [ 'id' => $orderId,
                            'side' => strtolower($order['side']),
                            'status' => $status,
                            'filled' => floatval($order['executedQty']),
                            'filled_base' => floatval($order['executedQty']) * $order['price'],
                            'price' => $order['price']
                          ];
        }
    }

    function cancelOrder($product, $orderId)
    {
      $alt = self::crypto2binance($product->alt);
      $base = self::crypto2binance($product->base);
      $symbol = "{$alt}{$base}";
      print_dbg("canceling $symbol order $orderId");

      $i=0;
      while($i<10)
      {
        try{
          $ret = $this->jsonRequest('DELETE', 'v3/order', ["symbol" => $symbol, "orderId" => $orderId]);
          break;
        }catch (Exception $e)
        {
          print_dbg("Failed to cancel order. [{$e->getMessage()}] retrying...$i");
          if($e->getMessage() == 'UNKNOWN_ORDER')
          {
            return false;
          }
          $i++;
          sleep(1);
        }
      }

      if(isset($ret['error']))
      {
        var_dump($ret);
        print_dbg("Failed to cancel order. [{$ret['error']['msg']}]");
        return false;
      }
      return true;

    }

    function ping()
    {
      $ping = $this->jsonRequest('GET', 'v1/ping');
      return isset($ping['error']) ? false : true;
    }

    static function crypto2binance($crypto, $reverse = false)
    {
      $table = ['BSV' => 'BCHSV',
                'BCH' => 'BCHABC',
                'USD' => 'PAX',
                ];
      if($reverse)
        $table = array_flip($table);
      if(array_key_exists($crypto,$table))
        return $table[$crypto];
      else {
        return $crypto;
      }
    }

    static function binance2crypto($crypto)
    {
        return self::crypto2binance($crypto, true);
    }
}
