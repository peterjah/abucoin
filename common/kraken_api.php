<?php
use WebSocket\Client;
@define('WSS_AUTH_URL','wss://ws-auth.kraken.com');

require_once('../common/tools.php');
class KrakenAPIException extends ErrorException {

  public function __construct($msg, $data = null)
  {
    parent::__construct($msg);
    $this->data = $data;
  }

  public function msg() {
    return "Kraken error: {$this->getMessage()}";
  }
};

class KrakenApi
{
    const API_URL = 'https://api.kraken.com';
    const API_VERSION = '0';
    protected $key;     // API key
    protected $secret;  // API secret
    protected $curl;    // curl handle
    protected $api_calls;
    protected $time;
    protected $products;

    public function __construct()
    {
      $keys = json_decode(file_get_contents("../common/private.keys"));
      if (!isset($keys->kraken))
        throw new KrakenAPIException("Unable to retrieve private keys");
      $this->secret = $keys->kraken->secret;
      $this->key = $keys->kraken->api_key;
      $this->name = 'Kraken';

      $this->curl = curl_init();
      curl_setopt_array($this->curl, array(
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_USERAGENT => 'Kraken PHP API Agent',
          CURLOPT_POST => true,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 8)
      );

      //App specifics
      $this->products = [];
      $this->balances = [];
      $this->api_calls = 0;
      $this->api_calls_rate = 0;
      $this->time = time();

      $this->orderbook_depth = 10;
      $this->renewWebsocketToken();
    }

    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($method, array $request = array())
    {
      $this->api_calls++;
      $now = time();
      if (($now - $this->time) > 60) {
        $this->api_calls_rate = $this->api_calls;
        $this->api_calls = 0;
        $this->time = $now;
      }

      $public_set = array( 'Ticker', 'Assets', 'Depth', 'AssetPairs', 'Time');
      if (!in_array($method, $public_set)) {
        //private method
        if(!isset($request['nonce'])) {
          // generate a 64 bit nonce using a timestamp at microsecond resolution
          // string functions are used to avoid problems on 32 bit systems
          $nonce = explode(' ', microtime());
          $request['nonce'] = $nonce[1] . str_pad(substr($nonce[0], 2, 6), 6, '0');
        }
        $postdata = http_build_query($request, '', '&');
        // set API key and sign the message
        $path = '/' . self::API_VERSION . '/private/' . $method;
        $sign = hash_hmac('sha512', $path . hash('sha256', $request['nonce'] . $postdata, true), base64_decode($this->secret), true);
        $headers = array(
            'API-Key: ' . $this->key,
            'API-Sign: ' . base64_encode($sign)
            );
      } else {
        $path = '/' . self::API_VERSION . '/public/' . $method;
        $headers = array ();
        $postdata = http_build_query($request, '', '&');
      }
      // make request
      curl_setopt($this->curl, CURLOPT_URL, self::API_URL . $path);
      curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postdata);
      curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 5);
      $result = curl_exec($this->curl);
      if($result===false)
          throw new KrakenAPIException('CURL error: ' . curl_error($this->curl));

      // decode results
      $result = json_decode($result, true);
      if(!is_array($result))
          throw new KrakenAPIException('JSON decode error');
      if(isset($result['error'][0]) && $result['error'][0] == 'EAPI:Rate limit exceeded') {
        print_dbg("Kraken: api call limit reached", true);
        sleep(15);
        throw new KrakenAPIException($result['error'][0]);
      }

      return $result;
    }

    static function crypto2kraken($crypto, $reverse = false)
    {
      $table = ['BTC' => 'XXBT',
                'XRP' => 'XXRP',
                'LTC' => 'XLTC',
                'XLM' => 'XXLM',
                'ETH' => 'XETH',
                'ETC' => 'XETC',
                'REP' => 'XREP',
                'ZEC' => 'XZEC',
                'XMR' => 'XXMR',
                'EUR' => 'ZEUR',
                'USD' => "ZUSD",
                'USDT' => "USDT",
                'DOGE' => "XXDG",
                'MLN' => "XMLN",
                'CAD' => "ZCAD",
                'JPY' => "ZJPY",
                'GBP' => "ZGBP",
                ];
      if($reverse)
        $table = array_flip($table);
      if(array_key_exists($crypto,$table))
        return $table[$crypto];
      else {
        return $crypto;
      }
    }

    static function kraken2crypto($crypto)
    {
      return self::crypto2kraken($crypto, true);
    }

    //Used in websockets
    static function translate2marketName($crypto, $reverse = false)
    {
      $table = ['BTC' => 'XBT',
                'DOGE' => 'XDG',
               ];
      if($reverse)
        $table = array_flip($table);
      if(array_key_exists($crypto,$table))
        return $table[$crypto];
      else {
        return $crypto;
      }
    }

    function getBalance()
    {
      $balances = $this->wrappedRequest('Balance');
      $open_orders = $this->wrappedRequest('OpenOrders');
      
      $crypto_in_order = [];
      if(isset($open_orders['result']['open'])) {
        foreach($open_orders['result']['open'] as $openOrder) {
          $product = getProductByParam($this->products, "exchange_symbol", $openOrder['descr']['pair']);

          if($openOrder['descr']['type'] == 'sell') {
            @$crypto_in_order[$product->alt] += $openOrder['vol'];
          } else {
            @$crypto_in_order[$product->base] += $openOrder['vol'] * $openOrder['descr']['price'];
          }
        }
      }

      $assetBalances = [];
      foreach($balances['result'] as $krakenAlt => $bal) {
        $crypto = $this->kraken2crypto($krakenAlt);
        $assetBalances[$crypto] = @$this->balances[$crypto] = floatval($bal - $crypto_in_order[$crypto]);
      }

      if( !isset($assetBalances) )
        throw new KrakenAPIException('failed to get balances');

      return $assetBalances;
    }

    function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price $base\n";
      file_put_contents(TRADE_FILE, $trade_str, FILE_APPEND | LOCK_EX);
    }

    function getProductList($base = null)
    {
      $products = $this->wrappedRequest('AssetPairs');
      $tradeVolume = $this->wrappedRequest('TradeVolume');

      $tradedVolume = $tradeVolume['result']['volume'];
      foreach($products['result'] as $kraken_symbol => $product) {

        if (substr($kraken_symbol, -2) == '.d')
          continue;

        $symbols = explode('/',$product['wsname']);
        $alt = $this->translate2marketName($symbols[0], true);
        $base = $this->translate2marketName($symbols[1], true);

        //compute fee level
        foreach($product['fees'] as $feesLevel) {
          if ($tradedVolume > $feesLevel[0]) {
            $fees = $feesLevel[1];
            continue;
          }
          break;
        }

        $params = [ 'api' => $this,
                    'alt' => $alt,
                    'base' => $base,
                    'fees' => $fees,
                    'min_order_size' => self::minimumAltTrade($alt),
                    'lot_size_step' => pow(10,-1*$product['lot_decimals']),
                    'size_decimals' => $product['lot_decimals'],
                    'min_order_size_base' => 0,//??
                    'price_decimals' => $product['pair_decimals'],
                    'exchange_symbol' => $kraken_symbol,
                    'alt_symbol' => $product['altname'],
                    'ws_name' => $product['wsname'],
                  ];

        $product = new Product($params);
        $this->products[$product->symbol] = $product;

        if (!isset($this->balances[$alt]))
          $this->balances[$alt] = 0;
        if (!isset($this->balances[$base]))
          $this->balances[$base] = 0;
      }

      return $this->products;
    }

    function getProductsStr($symbol_list) {
      $products_str = "";
      foreach ($symbol_list as $symbol) {
        $products_str .= "{$this->products[$symbol]->alt_symbol},";
      }
      // remove last ,
      return substr($products_str, 0, strlen($products_str)-1);
    }

    function place_order($product, $type, $side, $price, $size, $tradeId, $saveTrade = true)
    {
      $client = new Client(WSS_AUTH_URL, ['timeout' => 60]);
      $error = "";
      $client->send(json_encode(
       [
         'event' => 'addOrder',
         'token' => $this->websocket_token,
         'pair' => $product->ws_name,
         'type' => $side,
         'ordertype' => $type,
         'volume' => $this->toString($size, $product->size_decimals),
         'price' => $this->toString($price, $product->price_decimals),
         'expiretm' => '+20',
         ]
      ));
      while (true) {
        $msg = $client->receive();
        if ($msg) {
          $msg = json_decode($msg , true);
          print "new message:\n";
          var_dump($msg);
          if($msg['status'] === 'error') {
            $error = "{$msg['errorMessage']} token: {$this->websocket_token}";
            break;
          }
          if ($msg['status'] === 'ok' && $msg['event'] === 'addOrderStatus') {
            $id = $msg['txid'];
            $status = $this->waitForStatus($msg['txid']);
            var_dump($status);

            print_dbg("Order final status: {$status['status']}", true);
            if(empty($status['status']) || $status['status'] == 'open' || $status['status'] == 'expired') {
              $order_canceled = $this->cancelOrder(null, $id);
              $begin = microtime(true);
              $timeout = 10;//sec
              while ((empty($status['status']) || $status['status'] == 'open') && (microtime(true) - $begin) < $timeout) {
                $status = $this->getOrdersHistory(['id' => $id]);
                usleep(50000);
              }
            }
            
            print_dbg("{$this->name} trade $id status: {$status['status']}. filled: {$status['filled']} @ {$status['price']} $product->base");
            var_dump($status);
      
            if($status['filled'] > 0) {
              if($saveTrade) {
                $this->save_trade($id, $product, $side, $status['filled'], $status['price'], $tradeId);
              }
            } elseif ($order_canceled  || $status['status'] == 'expired') {
              return ['filled_size' => 0, 'id' => $id, 'filled_base' => 0, 'price' => 0];
            } else {
              throw new KrakenAPIException("Unable to locate order in history");
            }
            return ['filled_size' => $status['filled'], 'id' => $id, 'price' => $status['price']];
          }
        }
      }
      throw new KrakenAPIException("websocket place order failed: $error");
    }

    static function minimumAltTrade($crypto)
    {
      $table = ['REP'=>0.3,
                'BTC'=>0.002,
                'BCH'=>0.000002,
                'DASH'=>0.03,
                'DOGE'=>3000,
                'EOS'=>3,
                'ETH'=>0.02,
                'ETC'=>0.3,
                'ICN'=>2,
                'LTC'=>0.1,
                'MLN'=>0.1,
                'XMR'=>0.1,
                'XRP'=>30,
                'XLM'=>30,
                'ZEC'=>0.03,
                'GNO'=>0.03,
                'ADA'=>1,
                'QTUM'=>0.1,
                'BSV'=>0.002,
                'XTZ'=>1,
                'USDT'=>5,
                'GBP'=>10,
                'USD'=>10,
                'EUR'=>10,
                'ATOM'=>0.1,
                'BAT'=> 50,
                'LINK'=>10,
                'DAI'=>10,
                'ICX'=>50,
                'NANO'=>10,
                'OMG'=>10,
                'SC'=>5000,
                'WAVES'=>10,
	              'PAXG'=>0.01,
		            'LSK'=>10,
                'USDC'=>5,
                'TRX'=>500,
                'ALGO'=>50,
                'OXT'=>50,
                'KAVA'=>10,
                'KNC'=>10,
                'STORJ'=>50,
                'AUD'=>10,
                'COMP'=>0.025,
              ];

    if(array_key_exists($crypto,$table))
      return $table[$crypto];
    else
      print_dbg("Warning, unknown minimum order for $crypto", true);
      return 0;
    }

    function getOrderStatus($alt = null, $order_id)
    {
      $open_orders = $this->wrappedRequest('OpenOrders')['result']['open'];
      if(count($open_orders)) {
        foreach ($open_orders as $id => $open_order) {
          if($id == $order_id) {
            return  [ 'id' => $id,
                      'status' => 'open',
                      'filled' => $open_order['vol_exec'],
                      'filled_base' => $open_order['cost']
                    ];
          }
        }
      }
   }
   function renewWebsocketToken()
   {
      $token = $this->wrappedRequest('GetWebSocketsToken');
      $this->websocket_token = $token['result']['token'];
   }

   function ping()
   {
      try {
        $this->wrappedRequest('Time');
      } catch(KrakenAPIException $e) {
        if(count($e->data)) {
          print_dbg($e->data[0], true);
          return false;
        }
     }
     return true;
   }

   function getOrdersHistory($filter = null)
   {
     $params = [];
     if(isset($filter['id'])) {
       $params['txid'] = $filter['id'];
     }

     $trades = $this->wrappedRequest('QueryOrders', $params);

     if(!empty($trades['result'])) {
       foreach($trades['result'] as $idx => $order)
       {
         if ($filter['id'] == $idx) {
           $status = [ 'id' => $idx,
                       'side' => $order['descr']['type'],
                       'status' => $order['status'],
                       'filled' => floatval($order['vol_exec']),
                       'filled_base' => floatval($order['cost']),
                       'price' => floatval($order['price'])
                     ];
         }
       }
       return $status;
     }
   }

   function cancelOrder($product, $orderId)
   {
      print_dbg($this->name . " canceling order $orderId", true);
      try{
        $this->wrappedRequest('CancelOrder', ['txid' => $orderId]);
      }catch (Exception $e)
      {
        if($e->getMessage() == 'EOrder:Unknown order')
        {
          return false;
        }
        throw new KrakenAPIException($e->getMessage());
      }
      return true;
   }

   function wrappedRequest($method, $request = [])
   {
     $retry = 6;
      for($i = 0; $i<=$retry; $i++) {
        try {
          $ret = $this->jsonRequest($method, $request);
          break;
        }catch (Exception $e) {
          if ($i === $retry) {
            throw new KrakenAPIException($e->getMessage());
          }
          usleep(500000);//0.5 sec
        }
      }
      if(count($ret['error'])) {
        print_dbg("Kraken: Api method $method error: [{$ret['error'][0]}]", true);
        throw new KrakenAPIException($ret['error'][0], $ret['error']);
      }
      return $ret;
   }

   function refreshTickers($symbol_list)
   {
    $str = $this->getProductsStr($symbol_list);

    $tickers = $this->wrappedRequest('Ticker',['pair' => $str]);

    foreach($tickers['result'] as $symbol => $ticker) {
      //price
      $book['bids'][0] = $ticker['b'][0];
      $book['asks'][0] = $ticker['a'][0];
      //vol
      $book['bids'][1] = $ticker['b'][2];
      $book['asks'][1] = $ticker['a'][2];

      $product = getProductByParam($this->products, "exchange_symbol", $symbol);
      if (isset($product)) {
        $this->ticker[$product->symbol] = $book;
      }
    }
    return $this->ticker;
   }

   function getTickerOrderBook($product)
   {
       foreach (['asks', 'bids'] as $side) {
          if (!isset($this->ticker[$product->symbol])) {
            throw new KrakenAPIException("Unknown ticker {$product->symbol}");
          }
          $best[$side]['price'] = $best[$side]['order_price'] = floatval($this->ticker[$product->symbol][$side][0]);
          $best[$side]['size'] = floatval($this->ticker[$product->symbol][$side][1]);
       }
       return $best;
   }

   function getOrderBook($product, $depth_base = 0, $depth_alt = 0)
   {
        $depth_base = max($depth_base, $product->min_order_size_base);
        $depth_alt = max($depth_alt, $product->min_order_size);

        $book = $this->wrappedRequest('Depth', ['pair' => $product->exchange_symbol, 'count' => $this->orderbook_depth]);

        $book = $book['result'][$product->exchange_symbol];
        if (!isset($book['asks'], $book['bids'])) {
            throw new KrakenAPIException("failed to get order book with rest api");
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
                $best[$side]['price'] = ($best[$side]['price']*$best[$side]['size'] + $price * $size) / ($size + $best[$side]['size']);
                $best[$side]['size'] += $size;
                $best[$side]['order_price'] = $price;
                $i++;
            }
        }
        return $best;
   }

   function toString($float, $prec) {
    $pow = pow(10, $prec);
    return number_format(floor($float*$pow)/$pow, $prec, '.', '');
   }

   function waitForStatus($id) {
    $status = [];
    $timeout = 10;//sec
    $begin = microtime(true);
    while ((@$status['status'] != 'closed') && (@$status['status'] != 'canceled') && (microtime(true) - $begin) < $timeout) {
      $status = $this->getOrderStatus(null, $id);

      if(!isset($status)) {
        $status = $this->getOrdersHistory(['id' => $id]);
        print_dbg("closed order check: {$status['status']}");
      }
      usleep(2000000); // 2 sec
    }
    return $status;
  }
}
