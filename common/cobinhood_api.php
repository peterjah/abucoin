<?php

class CobinhoodAPIException extends ErrorException {};

class CobinhoodApi
{
    const API_URL = 'https://api.cobinhood.com/v1';
    const WSS_URL = 'wss://feed.cobinhood.com/ws';

    protected $api_key;
    protected $curl;
    protected $account_id;
    public $nApicalls;
    public $name;
    public $products;
    public $balances;

    protected $default_curl_opt = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERAGENT      => "Mozilla/4.0 (compatible; PHP Cobinhood API)",
      CURLOPT_ENCODING       => "",
      CURLOPT_HEADER         => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 5,
      CURLOPT_AUTOREFERER    => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT        => 30
    ];

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->api_key = $keys->cobinhood->api_key;
        $this->nApicalls = 0;
        $this->name = 'Cobinhood';
        $this->PriorityLevel = 9;

        //App specifics
        $this->products = [];
        $this->balances = [];

    }

    public function jsonRequest($method = null, $path, $datas = null, $params = false)
    {
        $opt = $this->default_curl_opt;
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;

        if ($method !== null)
      			$opt[CURLOPT_CUSTOMREQUEST] = $method;
      	if ($params)
      			$opt[CURLOPT_POSTFIELDS] = json_encode($params);
        if ($datas)
          $datas = "?".http_build_query($datas);

        $nonce = intval(round(microtime(true)*1000));
        $opt[CURLOPT_HTTPHEADER] = [
          "authorization: {$this->api_key}",
          "nonce: {$nonce}"
        ];

        $ch = curl_init(self::API_URL . $path.$datas);
        curl_setopt_array($ch, $opt);
        $content = curl_exec($ch);
    		$errno   = curl_errno($ch);
    		$errmsg  = curl_error($ch);
    		//$header  = curl_getinfo($ch);
    		curl_close($ch);
    		if ($errno !== 0)
    			return ["error" => $errmsg];
    		$content = json_decode($content, true);

        if (null === $content && json_last_error() !== JSON_ERROR_NONE) {
          return ["error" => json_last_error_msg()];
    		} else if (false === $content["success"]) {
    			return ["error" => $content["error"]["error_code"]];
    		}
    		return $content;
    }

    function getBalance(...$cryptos)
    {
      $balances = $this->jsonRequest('GET', "/wallet/balances")['result']['balances'];
      $currencies = array_column($balances, 'currency');

      foreach($cryptos as $crypto)
      {
        $key = array_search($crypto, $currencies);
        if($key !== false)
        {
          $account = $balances[$key];
          $res[$crypto] = $account['total'] - $account['on_order'];
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
      $products = $this->jsonRequest('GET','/market/trading_pairs');

      foreach($products['result']['trading_pairs'] as $product)
      if($product['quote_currency_id'] == 'BTC')
      {
        $list[] = $product['base_currency_id'];
      }
      return $list;
    }

    function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
    {
      $id = "{$alt}-BTC";
      $limit = ['limit' => 50];
      $book = $this->jsonRequest('GET', "/market/orderbooks/$id", $limit)['result']['orderbook'];

      if(!isset($book['asks'][0][0], $book['bids'][0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][2]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
              || ($best[$side]['size'] < $depth_alt) )
              && $i<50/*max offers for level=2*/)
        {
          if (!isset($book[$side][$i][0], $book[$side][$i][2]))
            break;
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book[$side][$i][0]*$book[$side][$i][2]) / ($book[$side][$i][2]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book[$side][$i][2]);
          $best[$side]['order_price'] = floatval($book[$side][$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }

    function getProductInfo($alt)
    {
      $id = "{$alt}-BTC";
      $product = null;
      $i=0;
      while( ($products = self::jsonRequest('GET', "/market/trading_pairs", null)) == null && $i<5)
      {
        $i++;
        sleep(1);
        continue;
      }
      foreach ($products['result']['trading_pairs'] as $pair)
        if($pair['id'] == $id)
          $product = $pair;

      if($product == null)
        throw new CobinhoodAPIException('failed to get product infos');
      $info['min_order_size_alt'] = $product['base_min_size'];
      $info['increment'] = $product['quote_increment'];
      $info['fees'] = 0;
      $info['min_order_size_btc'] = 0;
      return $info;
    }

    function place_order($type, $alt, $side, $price, $size)
    {
      $table = ['sell' => 'ask', 'buy' => 'bid'];
      $bidask = $table[$side];
      $type = 'market';

      $order = ['trading_pair_id' => "$alt-BTC",
                'size'=>  strval($size),
                'side'=> $bidask,
                'type'=> $type,
                ];

      if($type == 'limit')
      {
        $order['price'] = strval($price);
      }

      var_dump($order);
      $ret = $this->jsonRequest('POST', '/trading/orders',null, $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);

      if(isset($ret['result']))
      {
        $status = $ret['result']['order'];
        if($ret['success'])
          $this->save_trade($status['id'], $alt, $side, $size, $price);
        return ['filled_size' => $status['filled'], 'id' => $status['id'], 'filled_btc' => null];
      }
      else
        throw new CobinhoodAPIException('place order failed');
    }

    function save_trade($id, $alt, $side, $size, $price)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }
}
