<?php

class BinanceAPIException extends ErrorException {};

class BinanceApi
{
    const API_URL = 'https://www.binance.com/api/';

    protected $api_key;
    protected $api_secret;
    protected $curl;
    protected $account_id;
    public $nApicalls;
    public $name;
    public $products;
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

        $this->nApicalls = 0;
        $this->name = 'Binance';

        $this->PriorityLevel = 10;
        //App specifics
        $this->products = [];
        $this->balances = [];
    }

    public function jsonRequest($method = null, $path, array $params = [])
    {
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;

        $public_set = array( 'v1/depth', 'v1/exchangeInfo');

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
//      curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt_array($ch, $opt);

        $content = curl_exec($ch);
    		$errno   = curl_errno($ch);
    		$errmsg  = curl_error($ch);
    		$header  = curl_getinfo($ch);
    		curl_close($ch);
    		if ($errno !== 0)
    			return ["error" => $errmsg];
    		$content = json_decode($content, true);
//        var_dump($content);
        if (null === $content && json_last_error() !== JSON_ERROR_NONE) {
    			return ["error" => "json_decode() error $errmsg"];
    		}
    		return $content;
    }

    function getBalance(...$cryptos)
    {
      $balances = $this->jsonRequest('GET', "v3/account")['balances'];
      $currencies = array_column($balances, 'asset');

      foreach($cryptos as $crypto)
      {
        $key = array_search($crypto, $currencies);
        if($key !== false)
        {
          $account = $balances[$key];
          $res[$crypto] = $account['free'];
        }
        else $res[$crypto] = 0;
      }
      if(count($res) == 1)
       return array_pop($res);
      else return $res;
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET','v1/exchangeInfo')["symbols"];

      foreach($products as $product)
        if($product['quoteAsset'] == 'BTC')
        {
          $list[] = $product['baseAsset'];
        }
      return $list;
    }

    function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
    {
      $id = "{$alt}BTC";
      $book = $this->jsonRequest('GET', "v1/depth", ["symbol" => $id, "limit" => 50]);

      if(!isset($book['asks'][0][0], $book['bids'][0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][1]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
              || ($best[$side]['size'] < $depth_alt) )
              && $i<50/*max offers for level=2*/)
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

    function getProductInfo($alt)
    {
      $id = "{$alt}BTC";
      $products = $this->jsonRequest('GET','v1/exchangeInfo')["symbols"];

      foreach ($products as $product)
        if($product['symbol'] == $id)
          break;
      //var_dump($product);
      if($product == null)
        throw new BinanceAPIException('failed to get product infos');
      $info['min_order_size_alt'] = floatval($product["filters"][1]["minQty"]);
      $info['increment'] = floatval($product["filters"][1]["stepSize"]);
      $info['fees'] = 0.05;
      $info['min_order_size_btc'] = floatval($product["filters"][0]["minPrice"]);
      $info['alt_price_decimals'] = strlen(substr(strrchr($info['increment'], "."), 1));
      return $info;
    }

    function place_order($type, $alt, $side, $price, $size)
    {
      $type = 'market';//Hack
      $table = ['sell' => 'SELL', 'buy' => 'BUY'];
      $table2 = ['market' => 'MARKET', 'limit' => 'LIMIT'];
      $orderSide = $table[$side];
      $orderType = $table2[$type];

      $order = ['symbol' => "{$alt}BTC",
                'quantity'=>  $size,
                'side'=> $orderSide,
                'type'=> $orderType,
                ];

      if($type == 'limit')
      {
        $order['price'] = $price;
        $order['timeInForce'] = 'GTC';
      }

      var_dump($order);
      $ret = $this->jsonRequest('POST', 'v3/order', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);

      if( count($ret) && !isset($ret['code']))
      {
        $status = $ret;;
        if($status['executedQty'] > 0)
          $this->save_trade($status['orderId'], $alt, $side, $status['executedQty'], $price);
        return ['filled_size' => $status['executedQty'], 'id' => $status['orderId'], 'filled_btc' => null];
      }
      else
        throw new BinanceAPIException("place order failed: {$ret['msg']}");
    }

    function save_trade($id, $alt, $side, $size, $price)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }
}
