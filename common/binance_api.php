<?php

class BinanceAPIException extends ErrorException
{
    public function __construct($msg, $data = null)
    {
        parent::__construct($msg);
        $this->data = $data;
    }
    public function msg()
    {
        return "Binance error: {$this->getMessage()}";
    }
};

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
    public $orderbook_file;
    public $orderbook_depth;
    public $max_price_diff;

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
        if (!isset($keys->binance)) {
            throw new BinanceAPIException("Unable to retrieve private keys");
        }
        $this->api_key = $keys->binance->api_key;
        $this->api_secret = $keys->binance->secret;

        $this->api_calls = 0;
        $this->name = 'Binance';

        //App specifics
        $this->products = [];
        $this->balances = [];
        $this->api_calls = 0;
        $this->api_calls_rate = 0;
        $this->time = time();

        $this->orderbook_depth = 20;
    }

    public function jsonRequest($method, $path, $params = [])
    {
        $this->api_calls++;
        $now = time();
        if (($now - $this->time) > 60) {
            $this->api_calls_rate = $this->api_calls;
            $this->api_calls = 0;
            $this->time = $now;
        }

        $public_set = array( 'v3/depth', 'v3/exchangeInfo', 'v3/ping', 'v3/ticker/bookTicker');

        $opt = $this->default_curl_opt;
        $url = self::API_URL . $path;
        $headers = array(
          "X-MBX-APIKEY: {$this->api_key}"
          );
        if (!in_array($path, $public_set)) { //private method
            $params['timestamp'] = number_format(microtime(true) * 1000, 0, '.', '');
            $query = http_build_query($params, '', '&');
            $signature = hash_hmac('sha256', $query, $this->api_secret);
            $url .= '?' . $query . "&signature={$signature}";
        } elseif (count($params) > 0) {
            $query = http_build_query($params, '', '&');
            $url .= '?' . $query;
        }

        $opt[CURLOPT_CUSTOMREQUEST] = $method;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, "User-Agent: Mozilla/4.0 (compatible; PHP Binance API)");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt_array($ch, $opt);

        $content = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errmsg  = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return ["error" => $errmsg];
        }
        $content = json_decode($content, true);

        if (null === $content && json_last_error() !== JSON_ERROR_NONE) {
            return ["error" => "json_decode() error $errmsg"];
        }
        if (isset($content['code']) && $content['code'] < 0) {
            if (substr_compare($content['msg'], 'Way too many requests;', 0, 20) == 0) {
                print_dbg("Too many api calls on Binance. Sleeping....");
                sleep(60);
            }
            throw new BinanceAPIException($content['msg']);
        }
        return $content;
    }

    public function wrappedRequest($method, $path, $params = [])
    {
        $retry = 6;
        for ($i = 0; $i<=$retry; $i++) {
            try {
                $ret = $this->jsonRequest($method, $path, $params);
                break;
            } catch (Exception $e) {
                if ($i === $retry) {
                    throw new BinanceAPIException($e->getMessage());
                }
                usleep(500000);//0.5 sec
            }
        }
        if (isset($ret['error'])) {
            print_dbg("Binance: Api method $method $path error: [{$ret['error']}]", true);
            throw new BinanceAPIException($ret['error'], $ret);
        }
        return $ret;
    }

    public function getBalance()
    {
        $res = [];
        $balances = $this->wrappedRequest('GET', "v3/account");
        //var_dump($balances);
        foreach ($balances['balances'] as $bal) {
            $crypto = self::binance2crypto($bal['asset']);
            $res[$crypto] = $this->balances[$crypto] = floatval($bal['free']);
        }

        return $res;
    }

    public function getProductList()
    {
        $list = [];
        $products = $this->wrappedRequest('GET', 'v3/exchangeInfo');

        foreach ($products['symbols'] as $product) {
            if ($product['status'] == 'TRADING') {
                $alt = self::binance2crypto($product['baseAsset']);
                $base = self::binance2crypto($product['quoteAsset']);
                $params = [ 'api' => $this,
                      'alt' => $alt,
                      'base' => $base,
                      'fees' => 0.075,
                      'exchange_symbol' => $product['symbol'],
                    ];
                foreach ($product['filters'] as $filter) {
                    if ($filter['filterType'] == 'PRICE_FILTER') {
                        $params['price_decimals'] = strlen(substr(strrchr(rtrim($filter['tickSize'], 0), "."), 1));
                    }
                    if ($filter['filterType'] == 'LOT_SIZE') {
                        $params['min_order_size'] = floatval($filter['minQty']);
                        $params['size_decimals'] = strlen(substr(strrchr(rtrim($filter['stepSize'], 0), "."), 1));
                        $params['lot_size_step'] = floatval($filter['stepSize']);
                    }
                    if ($filter['filterType'] == 'MIN_NOTIONAL') {
                        $params['min_order_size_base'] = floatval($filter['minNotional']);
                    }
                }
                $product = new Product($params);
                $list[$product->symbol] = $product;

                if (!isset($this->balances[$alt])) {
                    $this->balances[$alt] = 0;
                }
                if (!isset($this->balances[$base])) {
                    $this->balances[$base] = 0;
                }
            }
        }
        $this->products = $list;
        return $list;
    }

    public function refreshTickers($symbol_list)
    {
        if (file_exists(($this->orderbook_file))) {
            $this->ticker = getWsOrderbook($this->orderbook_file);
            return $this->ticker;
        } else {
            $tickers = $this->wrappedRequest('GET', 'v3/ticker/bookTicker');

            foreach ($tickers as $ticker) {
                //price
                $book['bids'][0] = $ticker['bidPrice'];
                $book['asks'][0] = $ticker['askPrice'];
                //vol
                $book['bids'][1] = $ticker['bidQty'];
                $book['asks'][1] = $ticker['askQty'];

                $product = getProductByParam($this->products, "exchange_symbol", $ticker['symbol']);
                if (isset($product)) {
                    $this->ticker[$product->symbol] = $book;
                }
            }
            return $this->ticker;
        }
    }

    public function getTickerOrderBook($product)
    {
        foreach (['asks', 'bids'] as $side) {
            if (!isset($this->ticker[$product->symbol])) {
                throw new BinanceAPIException("Unknown ticker {$product->symbol}");
            }
            $best[$side]['price'] = $best[$side]['order_price'] = floatval($this->ticker[$product->symbol][$side][0]);
            $best[$side]['size'] = floatval($this->ticker[$product->symbol][$side][1]);
        }
        return $best;
    }

    public function getOrderBook($product, $depth_base = 0, $depth_alt = 0)
    {
        $depth_base = max($depth_base, $product->min_order_size_base);
        $depth_alt = max($depth_alt, $product->min_order_size);

        $symbol = self::crypto2binance($product->alt) . self::crypto2binance($product->base);

        $book = $this->wrappedRequest('GET', 'v3/depth', ['symbol' => $symbol, 'limit' => $this->orderbook_depth]);

        if (!isset($book['asks'], $book['bids'])) {
            throw new BinanceAPIException("failed to get order book with rest api");
        }

        foreach (['asks', 'bids'] as $side) {
            $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
            $best[$side]['size'] = floatval($book[$side][0][1]);
            $i=1;
            while ((($best[$side]['size'] * $best[$side]['price'] < $depth_base)
              || ($best[$side]['size'] < $depth_alt))
              && $i < $this->orderbook_depth) {
                if (!isset($book[$side][$i])) {
                    break;
                }

                $price = floatval($book[$side][$i][0]);
                $size = floatval($book[$side][$i][1]);

                $best[$side]['price'] = ($best[$side]['price']*$best[$side]['size'] + $price*$size) / ($size+$best[$side]['size']);
                $best[$side]['size'] += $size;
                $best[$side]['order_price'] = $price;
                //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
                $i++;
            }
        }
        return $best;
    }

    // found issue of order filled and not retrieved...
    public function place_order($product, $type, $side, $price, $size, $tradeId, $saveTrade = true)
    {
        $alt = $product->alt;
        $base = $product->base;
        $timeout = 10;// sec

        $order = ['symbol' =>  self::crypto2binance($alt) . self::crypto2binance($base),
                'quantity'=>  formatString($size, $product->size_decimals),
                'side'=> strtoupper($side),
                'type'=> strtoupper($type),
                ];

        if ($type == 'limit') {
            $order['price'] = formatString($price, $product->price_decimals);
            $order['recvWindow'] = $timeout*1000;
        }
        $order['timeInForce'] = 'GTC';

        $status = $this->jsonRequest('POST', 'v3/order', $order);
        print "{$this->name} trade says:\n";
        var_dump($status);

        if (count($status) && !isset($status['code'])) {
            $id = $status['orderId'];
            $filled_size = 0;
            $filled_base = 0;
            $order_canceled = false;

            if ($status['status'] == 'FILLED') {
                $filled_size = floatval($status['executedQty']);
                $filled_base = floatval($status['cummulativeQuoteQty']);
                /*real price*/
                $pond_price = 0;
                foreach ($status['fills'] as $fills) {
                    $pond_price += $fills['price'] * $fills['qty'];
                }
                $price = $pond_price / $filled_size;
                print_dbg("{$this->name}: trade $id closed: $filled_size $alt @ $price", true);
            } else {
                //give server some time to handle order
                usleep(500000);// 0.5 sec
                $status = [];
                $begin = microtime(true);
                while ((@$status['status'] != 'closed') && ((microtime(true) - $begin) < $timeout)) {
                    $status = $this->getOrderStatus($product, $id);
                    sleep(1);
                }

                print_dbg("Check {$this->name} order $id status: {$status['status']} $side $alt filled:{$status['filled']}", true);

                if (empty($status['status']) || $status['status'] == 'open') {
                    $order_canceled = $this->cancelOrder($product, $id);
                    $begin = microtime(true);
                    while ((!$order_canceled || empty($status['status'])) && (microtime(true) - $begin) < $timeout) {
                        $status = $this->getOrderStatus($product, $id);
                        sleep(1);
                    }
                }

                print_dbg("Final check status: {$status['status']} $side $alt filled:{$status['filled']}", true);
                var_dump($status);
                $filled_size = $status['filled'];
                $filled_base = floatval($status['filled_base']);
                $price = $status['price'];
            }
            if ($filled_size > 0) {
                if ($saveTrade) {
                    $this->save_trade($id, $product, $side, $filled_size, $price, $tradeId);
                }
                return ['filled_size' => $filled_size, 'id' => $id, 'filled_base' => $filled_base, 'price' => $price];
            } else {
                return ['filled_size' => 0, 'id' => $id, 'filled_base' => 0, 'price' => 0];
            }
        } else {
            throw new BinanceAPIException("place order failed: {$status['msg']}");
        }
    }

    public function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
        $alt = $product->alt;
        $base = $product->base;
        print("saving trade\n");
        $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price $base\n";
        file_put_contents(TRADE_FILE, $trade_str, FILE_APPEND | LOCK_EX);
    }

    public function getOrderStatus($product, $orderId)
    {
        $alt = self::crypto2binance($product->alt);
        $base = self::crypto2binance($product->base);
        $symbol = "{$alt}{$base}";


        try {
            $order = $this->wrappedRequest('GET', 'v3/order', ["symbol" => $symbol, "orderId" => $orderId]);
        } catch (Exception $e) {
            if ($e->getMessage() === 'Order does not exist.') {
                print_dbg("getOrderStatus failed for sym=$symbol id=:$orderId" . $e->getMessage(), true);
            }
        }

        if (isset($order)) {
            if ($order['status'] == 'FILLED') {
                $status = 'closed';
            } else {
                $status = 'open';
            }
            $filled_base = floatval($order['cummulativeQuoteQty']);
            $filled = floatval($order['executedQty']);
            $price = floatval($order['price']);
            return  $status = [ 'id' => $orderId,
                            'side' => strtolower($order['side']),
                            'status' => $status,
                            'filled' => $filled,
                            'filled_base' => $filled_base > 0 ? $filled_base : $filled * $price,
                            'price' => $price
                          ];
        }
    }

    public function cancelOrder($product, $orderId)
    {
        $alt = self::crypto2binance($product->alt);
        $base = self::crypto2binance($product->base);
        $symbol = "{$alt}{$base}";
        print_dbg("canceling $symbol order $orderId", true);

        try {
            $this->wrappedRequest('DELETE', 'v3/order', ["symbol" => $symbol, "orderId" => $orderId]);
        } catch (Exception $e) {
            if ($e->getMessage() === 'Unknown order sent.') {
                print_dbg("Unknown order sent", true);
                return false;
            }
            throw new BinanceAPIException($e->getMessage());
        }
        return true;
    }

    public function ping()
    {
        $ping = $this->jsonRequest('GET', 'v3/ping');
        return isset($ping['error']) ? false : true;
    }

    public static function crypto2binance($crypto, $reverse = false)
    {
        $table = [
               ];
        if ($reverse) {
            $table = array_flip($table);
        }
        if (array_key_exists($crypto, $table)) {
            return $table[$crypto];
        } else {
            return $crypto;
        }
    }

    public static function binance2crypto($crypto)
    {
        return self::crypto2binance($crypto, true);
    }

    public static function translate2marketName($crypto)
    {
        return self::crypto2binance($crypto);
    }
}
