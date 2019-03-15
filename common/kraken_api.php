<?php
require_once('../common/tools.php');
class KrakenAPIException extends ErrorException {};

class KrakenApi
{
    const API_URL = 'https://api.kraken.com';
    const API_VERSION = '0';
    protected $key;     // API key
    protected $secret;  // API secret
    protected $curl;    // curl handle
    public $api_calls_rate;
    protected $api_calls;
    protected $time;
    public $name;
    protected $products;
    public $balances;
    public $orderbook_file;
    public $orderbook_depth;
    public $using_websockets;

    public function __construct()
    {
      $keys = json_decode(file_get_contents("../common/private.keys"));
      if (!isset($keys->kraken))
        throw new KrakenAPIException("Unable to retrieve private keys");
      $this->secret = $keys->kraken->secret;
      $this->key = $keys->kraken->api_key;
      $this->name = 'Kraken';
      $this->PriorityLevel = 9;

      $this->curl = curl_init();
      curl_setopt_array($this->curl, array(
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_USERAGENT => 'Kraken PHP API Agent',
          CURLOPT_POST => true,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 4)
      );

      //App specifics
      $this->products = [];
      $this->balances = [];
      $this->api_calls = 0;
      $this->api_calls_rate = 0;
      $this->time = time();

      $this->orderbook_depth = 25;
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
      if ( !in_array($method ,$public_set ) )
      { //private method
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
      }
      else
      {
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
      if(isset($result['error'][0]) && $result['error'][0] == 'EAPI:Rate limit exceeded')
      {
        print "Kraken Api call limit reached\n";
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
                'DASH' => "DASH",
                'EOS' => "EOS",
                'BCH' => "BCH",
                'ADA' => "ADA",
                'QTUM' => "QTUM",
                'BSV' => "BSV",
                'XTZ' => "XTZ",
                'GNO' => "GNO",
                'MLN' => "XMLN",
                'CAD' => "ZCAD",
                'JPY' => "ZJPY",
                'GBP' => "ZGBP",
                ];
      if($reverse)
        $table = array_flip($table);
      if(array_key_exists($crypto,$table))
        return $table[$crypto];
      else
      {
        print( "KrakenApi warning: Unknown crypto $crypto\n");
        return null;
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

    function getBalance($alt = null, $in_order = true)
    {
      $res = [];
      //var_dump($cryptos);
      $i=0;
      while ( true ) {
        try {
          $balances = $this->jsonRequest('Balance');
          if ($in_order)
            $open_orders = $this->jsonRequest('OpenOrders');
          break;
        }
        catch (Exception $e) {
          $i++;
          print "{$this->name}: failed to get balances. [{$e->getMessage()}] retry $i...\n";
          usleep(50000);
          if($i > 8)
            throw new KrakenAPIException("failed to get balances [{$e->getMessage()}]");
        }
      }

      $crypto_in_order = [];
      if(isset($open_orders['result']) && count($open_orders['result']['open'])) {
        foreach($open_orders['result']['open'] as $openOrder) {
          $krakenPair = $openOrder['descr']['pair'];
          $base = substr($krakenPair,-3);//fixme
          $base = $base == 'XBT' ? 'BTC' : $base;
          $krakenAlt = substr($krakenPair,0, strlen($krakenPair)-3);
          $alt = $this->kraken2crypto($krakenAlt);
          $alt = $alt == null ? $this->kraken2crypto("X{$krakenAlt}") : $alt;
          print "alt=$alt base=$base\n";
          if($openOrder['descr']['type'] == 'sell') {
            $crypto_in_order[$alt] += $openOrder['vol'];
          } else {
            $crypto_in_order[$base] += $openOrder['vol'] * $openOrder['descr']['price'];
          }
        }
      }

      if(isset($balances['result'])) {
        foreach($balances['result'] as $crypto => $bal) {
          $crypto = $this->kraken2crypto($crypto);
          $in_order = isset($crypto_in_order[$crypto]) ? $crypto_in_order[$crypto] : 0;
          $res[$crypto] = @$this->balances[$crypto] = floatval($bal - $crypto_in_order[$crypto]);
        }
      }

      if( !isset($res) )
        throw new KrakenAPIException('failed to get balances');

      if ($alt != null)
        return $res[$alt];
      else return $res;
    }

    function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price $base\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function getProductList($base = null)
    {
      $list = [];
      $i=0;
      while (true) { try {
          $products = $this->jsonRequest('AssetPairs');
          $tradeVolume = $this->jsonRequest('TradeVolume', ['pair' => 'XLTCXXBT']);
          break;
        }
        catch (Exception $e) {
            $i++;
            print "{$this->name}: failed to get product info. retry $i...\n";
            usleep(50000);
            if($i > 8)
              throw new KrakenAPIException("failed to get product infos [{$e->getMessage()}]");
        }
      }

      $fees = $tradeVolume['result']['fees']['XLTCXXBT']['fee'];
      foreach($products['result'] as $kraken_symbol => $product) {
        if (substr($kraken_symbol, -2) == '.d')
          continue;
        $alt = self::kraken2crypto($product['base']);
        $base = self::kraken2crypto($product['quote']);
        if (!isset($alt) || !isset($base))
          continue;
        $params = [ 'api' => $this,
                    'alt' => $alt,
                    'base' => $base,
                    'fees' => $fees,
                    'min_order_size' => self::minimumAltTrade($alt),
                    'lot_size_step' => pow(10,-1*$product['lot_decimals']),
                    'size_decimals' => $product['lot_decimals'],
                    'min_order_size_base' => 0,//sel,::minimumAltTrade($base),
                    'price_decimals' => $product['pair_decimals'],
                    'symbol_exchange' => $kraken_symbol,
                  ];
        $product = new Product($params);
        $list[$product->symbol] = $product;

        if (!isset($this->balances[$alt]))
          $this->balances[$alt] = 0;
        if (!isset($this->balances[$base]))
          $this->balances[$base] = 0;
      }
      $this->products = $list;

      return $list;
    }

    function place_order($product, $type, $side, $price, $size, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;

      $pair = $product->symbol_exchange;

      $order = ['pair' => $pair,
                'type' => $side,
                'ordertype' => $type,
                'volume' => strval(truncate($size,$product->size_decimals)),
                'expiretm' => '+20' //todo: compute working expire time...(unix timestamp)
              ];
      if($type == 'limit')
      {
        print "price:\n";
        var_dump($price);
        $order['price'] = strval(truncate($price,$product->price_decimals));
      }
      var_dump($order);
      $ret = $this->jsonRequest('AddOrder', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
      if(count($ret['error']))
       throw new KrakenAPIException($ret['error'][0]);
      else {
       //give server some time to handle order
       usleep(500000);//0.5 sec
       $id = $ret['result']['txid'][0];
       $status = [];
       $order_canceled = false;
       $timeout = 3;//sec
       $begin = microtime(true);
       while ((@$status['status'] != 'closed') && (microtime(true) - $begin) < $timeout) {
         $status = $this->getOrderStatus(null, $id);
         print_dbg("open order check: {$status['status']}");
         if(!isset($status)) {
           $status = $this->getOrdersHistory(['id' => $id]);
           var_dump($status);
           print_dbg("closed order check: {$status['status']}");
         }
       }
       if(empty($status['status']) || $status['status'] == 'open') {
         $order_canceled = $this->cancelOrder(null, $id);
         $begin = microtime(true);
         while (empty($status['status']) && (microtime(true) - $begin) < $timeout) {
           $status = $this->getOrdersHistory(['id' => $id]);
         }
       }
       print_dbg("{$this->name} trade $id status: {$status['status']}. filled: {$status['filled']}");
       var_dump($status);

       if($status['filled'] > 0) {
         $this->save_trade($id, $product, $side, $status['filled'], $status['price'], $tradeId);
       } elseif ($order_canceled) {
         return ['filled_size' => 0, 'id' => $id, 'filled_base' => 0, 'price' => 0];
       } else {
         throw new Exception("Unable to locate order in history");
       }
       return ['filled_size' => $status['filled'], 'id' => $id, 'price' => $status['price']];
      }
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
                'BSV'=>0.000002,
                'XTZ'=>1,
                'USDT'=>0,
                'USD'=>0,
                'EUR'=>0,
              ];

    if(array_key_exists($crypto,$table))
      return $table[$crypto];
    else
      throw new KrakenAPIException("Unknown crypto $crypto");
    }

    function getOrderStatus($alt = null, $order_id)
    {
      $i=0;
      for ($i=0; $i<5; $i++) {
        try{
          $open_orders = $this->jsonRequest('OpenOrders')['result']['open'];
          break;
        }catch (Exception $e){usleep(500000); print ("{$this->name}: Failed to get status retrying...$i\n");}
      }

      if(count($open_orders)) {
        foreach ($open_orders as $id => $open_order) {
          if($id == $order_id) {
            var_dump($open_order);
            return  $status = [ 'id' => $id,
                                'status' => 'open',
                                'filled' => $open_order['vol_exec'],
                                'filled_base' => $open_order['cost']
                              ];
          }
        }
      }
   }

   function ping()
   {
     $ping = $this->jsonRequest('Time');
     return count($ping['error']) ? false : true;
   }

   //mean api_call_time= 0.34057093024254
   function getOrdersHistory($filter = null)
   {
     $params = [];
     if(isset($filter['id'])) {
       $params['txid'] = $filter['id'];
     }

     $i=0;
     while($i<8) {
       try {
         $trades = $this->jsonRequest('QueryOrders', $params);
         break;
       }catch (Exception $e){ $i++; usleep(500000); print_dbg("Failed to getOrdersHistory. [{$e->getMessage()}]..$i");}
     }
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
           var_dump($status);
         }
       }
       return $status;
     }
   }

   function cancelOrder($product, $orderId)
   {
     print_dbg($this->name . " canceling order $orderId");
     $i=0;
     while($i<10)
     {
       try{
         $ret = $this->jsonRequest('CancelOrder', ['txid' => $orderId]);
         break;
       }catch (Exception $e)
       {
         print_dbg("Failed to cancel order. [{$e->getMessage()}] retrying...$i");
         if($e->getMessage() == 'EOrder:Unknown order')
         {
           return false;
         }
         $i++;
         sleep(1);
       }
     }
     if(isset($ret['error'][0]))
     {
       var_dump($ret);
       print_dbg("Failed to cancel order. [{$ret['error'][0]}]");
       return false;
     }
     return true;
   }

   function getOrderBook($product, $depth_base = 0, $depth_alt = 0)
   {
     $file = $this->orderbook_file;
     $this->using_websockets = false;
     if (file_exists($file)) {
       $book = getWsOrderbook($file, $product);
       if ($book !== false)
         $this->using_websockets = true;
     }
     if ($this->using_websockets === false) {
       $i=0;
       while (true) {
         try {
             $book = $this->jsonRequest('Depth',['pair' => $product->symbol_exchange, 'count' => $this->orderbook_depth]);
           break;
           } catch (Exception $e) {
             if($i > 8)
               throw new KrakenAPIException("failed to get order book [{$e->getMessage()}]");
             $i++;
             print "{$this->name}: failed to get order book. retry $i...\n";
             usleep(50000);
           }
         }
       if(count($book['error']))
         throw new KrakenAPIException($book['error'][0]);
       $book = $book['result'][$product->symbol_exchange];
     }
     if(!isset($book['asks'], $book['bids'])) {
       throw new KrakenAPIException("failed to get order book with ".$this->using_websockets ? 'websocket' : 'rest api');
     }

     foreach( ['asks', 'bids'] as $side)
     {
       $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
       $best[$side]['size'] = floatval($book[$side][0][1]);
       $i=1;
       while( (($best[$side]['size'] * $best[$side]['price'] < $depth_base)
               || ($best[$side]['size'] < $depth_alt) )
               && $i < $this->orderbook_depth)
       {
         if (!isset($book[$side][$i][0], $book[$side][$i][1]))
           break;
         $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book[$side][$i][0]*$book[$side][$i][1]) / ($book[$side][$i][1]+$best[$side]['size']));
         $best[$side]['size'] += floatval($book[$side][$i][1]);
         $best[$side]['order_price'] = floatval($book[$side][$i][0]);
         $i++;
       }
     }
     return $best;
   }
}
