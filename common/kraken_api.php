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
    public $nApicalls;
    public $name;
    protected $products;
    public $balances;

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->secret = $keys->kraken->secret;
        $this->key = $keys->kraken->key;
        $this->nApicalls = 0;
        $this->name = 'Kraken';
        $this->PriorityLevel = 9;

        $this->curl = curl_init();
        curl_setopt_array($this->curl, array(
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Kraken PHP API Agent',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20)
        );

        //App specifics
        $this->products = [];
        $this->balances = [];
    }

    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($method, array $request = array())
    {
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;

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

    function getBalance($alt = null)
    {
      $res = [];
      //var_dump($cryptos);
      $i=0;
      while ( true ) {
        try {
          $balances = $this->jsonRequest('Balance');
          $orders = $this->jsonRequest('OpenOrders');
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
      if(isset($orders['result']) && count($orders['result']['open'])) {
        foreach($orders['result']['open'] as $openOrder) {
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
      //var_dump($products);
      $fees = $tradeVolume['result']['fees']['XLTCXXBT']['fee'];
      foreach($products['result'] as $product) {
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

      $type = 'market';
      $pair = $this->getPair($product);
      // safety check
      if($side == 'buy' && $price > 0) {
        $bal = @$this->balances[$base];
          while(!isset($bal) && $i < 6) {
            try {
              $bal = $this->getBalance($base);
            } catch (Exception $e) {$i++;}
          }
        $size = min($size , $bal/$price);
      }
      else if($side == 'sell') {
        $altBal = @$this->balances[$alt];
        if(!isset($altBal))
          $altBal = $this->getBalance($alt);
        $size = min($size , $altBal);
      }

      $order = ['pair' => $pair,
                'type' => $side,
                'ordertype' => $type,
                'volume' => strval(truncate($size,$product->size_decimals)),
                'expiretm' => '+20' //todo: compute working expire time...(unix timestamp)
               ];
      if($type == 'limit')
      {
        $price = $side == 'buy' ? ceiling($price, $product->lot_size_step) : truncate($price, $product->price_decimals);
        $price_str = rtrim(rtrim(sprintf("%.{$price_decimals}F", $price), '0'), ".");
        $order['price'] = $price_str;
      }
      var_dump($order);
      $ret = $this->jsonRequest('AddOrder', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
       if(count($ret['error']))
         throw new KrakenAPIException($ret['error'][0]);
       else {
         $filled_size = $size; //todo !! no filled infos in kraken return :(
         $id = $ret['result']['txid'][0];
         $this->save_trade($id, $product, $side, $size, $price, $tradeId);
       }
       return ['filled_size' => $filled_size, 'id' => $id, 'price' => $price];//price maybe wrong :/
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

    static function getPair($product)
    {
      $table = ['XRP-BTC' => 'XXRPXXBT',
                'LTC-BTC' => 'XLTCXXBT',
                'XLM-BTC' => 'XXLMXXBT',
                'ETH-BTC' => 'XETHXXBT',
                'ETC-BTC' => 'XETCXXBT',
                'REP-BTC' => 'XREPXXBT',
                'ZEC-BTC' => 'XZECXXBT',
                'XMR-BTC' => 'XXMRXXBT',
                'EOS-BTC' => 'EOSXBT',
                'BCH-BTC' => 'BCHXBT',
                'DASH-BTC' => 'DASHXBT',
                'GNO-BTC' => 'GNOXBT',
                'ICN-BTC' => 'XICNXXBT',
                'MLN-BTC' => 'XMLNXXBT',
                'XDG-BTC' => 'XXDGXXBT',
                'ADA-BTC' => 'ADAXBT',
                'QTUM-BTC' => 'QTUMXBT',
                'BSV-BTC' => 'BSVXBT',
                'XTZ-BTC' => 'XTZXBT',
                'ADA-ETH' => 'ADAETH',
                'EOS-ETH' => 'EOSETH',
                'GNO-ETH' => 'GNOETH',
                'QTUM-ETH' => 'QTUMETH',
                'ETC-ETH' => 'XETCXETH',
                'ETH-USD' => 'XETHZUSD',
                'REP-ETH' => 'XREPXETH',
                'MLN-ETH' => 'XMLNXETH',
                'XTZ-ETH' => 'XTZETH',
                'ZEC-USD' => 'XZECZUSD',
                'XRP-USD' => 'XXRPZUSD',
                'XRP-USD' => 'XXRPZUSD',
                'XMR-USD' => 'XXMRZUSD',
                'XLM-USD' => 'XXLMZUSD',
                'BTC-USD' => 'XXBTZUSD',
                'XTZ-USD' => 'XTZUSD',
                'REP-USD' => 'XREPZUSD',
                'LTC-USD' => 'XLTCZUSD',
                'ETC-USD' => 'XETCZUSD',
                'QTUM-USD' => 'QTUMUSD',
                'GNO-USD' => 'GNOUSD',
                'EOS-USD' => 'EOSUSD',
                'DASH-USD' => 'DASHUSD',
                'BSV-USD' => 'BSVUSD',
                'BCH-USD' => 'BCHUSD',
                'ADA-USD' => 'ADAUSD',
                'USDT-USD' => 'USDTZUSD'
                ];
    if(array_key_exists($product->symbol,$table))
      return $table[$product->symbol];
    else
      throw new KrakenAPIException("Unknown symbol $product->symbol");
    }

    function getOrderBook($product, $depth_alt = 0, $depth_base = 0)
    {
      $id = $this->getPair($product);
      $ordercount = 25;
      $i=0;
      while (true) {
        try {
            $book = $this->jsonRequest('Depth',['pair' => $id, 'count' => $ordercount]);
            break;
          } catch (Exception $e) {
            if($i > 8)
              throw new BinanceAPIException("failed to get order book [{$e->getMessage()}]");
            $i++;
            print "{$this->name}: failed to get order book. retry $i...\n";
            usleep(50000);
          }
        }

      if(count($book['error']))
        throw new KrakenAPIException($book['error'][0]);

      $book = $book['result'][$id];

      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][1]);
        $i=1;
        while( (($best[$side]['size'] * $best[$side]['price'] < $depth_base)
                || ($best[$side]['size'] < $depth_alt) )
                && $i < $ordercount)
        {
          if (!isset($book[$side][$i][0], $book[$side][$i][1]))
            break;
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book[$side][$i][0]*$book[$side][$i][1]) / ($book[$side][$i][1]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book[$side][$i][1]);
          $best[$side]['order_price'] = floatval($book[$side][$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }

    function getOrderStatus($alt = null, $order_id)
    {
      $open_orders = $this->jsonRequest('OpenOrders');
      if (isset($open_orders['result']))
        $open_orders = $open_orders['result']['open'];

      if(count($open_orders))
      {
          var_dump($open_orders);
        foreach ($open_orders as $id => $open_order)
          if($id == $order_id)
          {
              $status = 'open';
              $filled = $open_order['vol_exec'];
              $filled_base = $open_order['cost'];
             break;
          }
      }
      if( !isset($status) )
      { //todo get trade history to know if its fillet or canceled
        $status = 'closed';
        $filled = null;
        $filled_base = null;
      }
      return  $status = [ 'id' => $order_id,
                          'status' => $status,
                          'filled' => $filled,
                          'filled_base' => $filled_base
                        ];
   }

   function ping()
   {
     $ping = $this->jsonRequest('Time');
     return count($ping['error']) ? false : true;
   }

   static function symbol2kraken($symbol, $reverse = false)
   {
     $table = ['XRP-BTC' => 'XXRPXXBT',
               'LTC-BTC' => 'XLTCXXBT',
               'XLM-BTC' => 'XXLMXXBT',
               'ETH-BTC' => 'XETHXXBT',
               'ETC-BTC' => 'XETCXXBT',
               'REP-BTC' => 'XREPXXBT',
               'ZEC-BTC' => 'XZECXXBT',
               'XMR-BTC' => 'XXMRXXBT',
               'EOS-BTC' => 'EOSXBT',
               'BCH-BTC' => 'BCHXBT',
               'DASH-BTC' => 'DASHXBT',
               'GNO-BTC' => 'GNOXBT',
               'ICN-BTC' => 'XICNXXBT',
               'MLN-BTC' => 'XMLNXXBT',
               'XDG-BTC' => 'XXDGXXDG',
               'ADA-BTC' => 'ADAXBT',
               'QTUM-BTC' => 'QTUMXBT',
               'BSV-BTC' => 'BSVXBT',
               'XTZ-BTC' => 'XTZXBT',
               'ADA-ETH' => 'ADAETH',
               'EOS-ETH' => 'EOSETH',
               'GNO-ETH' => 'GNOETH',
               'QTUM-ETH' => 'QTUMETH',
               'ETC-ETH' => 'XETCXETH',
               'REP-ETH' => 'XREPXETH',
               'XTZ-ETH' => 'XTZETH',
               'DOGE-BTC' => 'XXDGXXBT',
               ];
     if($reverse)
       $table = array_flip($table);
     if(array_key_exists($symbol, $table))
       return $table[$symbol];
     else {
       return null;
     }
   }

   static function kraken2symbol($symbol)
   {
     return symbol2kraken($symbol, true);
   }

}
