<?php

class PaymiumAPIException extends ErrorException {};

class PaymiumApi
{
    const API_URL = 'https://paymium.com/api/v1/';

    protected $api_key;
    protected $secret;
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
        if (!isset($keys->paymium))
          throw new PaymiumAPIException("Unable to retrieve API keys");
        $this->api_key = $keys->paymium->api_key;
        $this->secret = $keys->paymium->secret;
        $this->name = 'Paymium';
        $this->PriorityLevel = 10;

        //App specifics
        $this->products = [];
        $this->balances = [];

        //Api calls counter
        $this->api_calls = 0;
        $this->api_calls_rate = 0;
        $this->time = time();
    }

    public function jsonRequest($method, $path, $datas = [])/*($method, array $req = array())*/
    {
      $this->api_calls++;
      $now = time();
      if (($now - $this->time) > 60) {
        $this->api_calls_rate = $this->api_calls;
        $this->api_calls = 0;
        $this->time = $now;
      }

      $public_set = ['countries', 'data/eur/depth'];

      $opt = $this->default_curl_opt;
      $url = self::API_URL . $path;
      $nonce = number_format(microtime(true) * 1000, 0, '.', '');
      $sign = null;
      $headers = [];

      if ( !in_array($path ,$public_set ) ) {
       //private method
        if ($method === 'POST' && $datas) {
           $body = http_build_query($datas, '', '&');
           $url .= '?' . $body;
        }
        $sign = hash_hmac('sha256', $nonce . $url, $this->secret, false);
        $headers = Array("Authorization: Bearer ACCESS_TOKEN",
                    "Api-Key: {$this->api_key}",
                    "Api-Signature: $sign",
                    "Api-Nonce: $nonce",
                    'Content-Type: application/json');
      }
      $ch = curl_init($url);
      if ($datas) {
        curl_setopt($ch, CURLOPT_POST, 1);
      }
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt_array($ch, $opt);
      $content = curl_exec($ch);
      $errno   = curl_errno($ch);
      $errmsg  = curl_error($ch);
      curl_close($ch);
      if ($errno !== 0)
        return ["error" => $errmsg];
      $content = json_decode($content, true);
      return $content;
    }

    function getBalance($alt = null)
    {
      $res = [];
      $i=0;
      while (true) {
        try {
          $user_infos = $this->jsonRequest('GET', "user");
          //var_dump($user_infos);
          break;
        }
        catch (Exception $e) {
          if($i > 8)
            throw new PaymiumAPIException("failed to get balances [{$e->getMessage()}]");
          $i++;
          print "{$this->name}: failed to get balances. retry $i...\n";
          usleep(50000);
        }
      }

      $res['BTC'] = $this->balances['BTC'] = $user_infos['balance_btc'];
      $res['EUR'] = $this->balances['EUR'] = $user_infos['balance_eur'];

      if($alt != null)
       return $res[$alt];
      else return $res;
    }

    function getProductList()
    {
      $list = [];
      $alt = 'BTC';
      $base = 'EUR';
      $params = [ 'api' => $this,
                  'alt' => $alt,
                  'base' => $base,
                  'fees' => 0.59,
                  'price_decimals' => 2,
                  'size_decimals' => 8,
                  'min_order_size' => 0.001,
                  'lot_size_step' => 0.00000001,
                  'min_order_size_base' => 0
                ];
      $product = new Product($params);
      $list[$product->symbol] = $product;

      if (!isset($this->balances[$alt]))
        $this->balances[$alt] = 0;
      if (!isset($this->balances[$base]))
        $this->balances[$base] = 0;

      $this->products = $list;
      return $list;
    }

    function getOrderBook($product, $depth_base = 0, $depth_alt = 0)
    {
      $i=0;
      $max_orders = 50;
      while (true) {
        try {
          $book = $this->jsonRequest('GET', 'data/eur/depth');
          break;
        } catch (Exception $e) {
          if($i > 8)
            throw new PaymiumAPIException("failed to get order book [{$e->getMessage()}]");
          $i++;
          print "{$this->name}: failed to get order book. retry $i...\n";
          usleep(50000);
        }
      }
      if(!isset($book['asks'], $book['bids']))
        return null;
        //var_dump($book['asks']);

      foreach( ['asks', 'bids'] as $side)
      {
        $offers = $book[$side];
        if($side == 'bids')
          $offers = array_reverse($book[$side]);

        $best[$side]['price'] = 0;
        $best[$side]['size'] = 0;
        $i=1;
        foreach ($offers as $offer) {
          $size = floatval($offer['amount']);
          $price = floatval($offer['price']);
          $best[$side]['price'] = ($best[$side]['price']*$best[$side]['size'] + $price * $size) / ($size+$best[$side]['size']);
          $best[$side]['order_price'] = floatval($offer['price']);
          $best[$side]['size'] += $size;
          if(($best[$side]['size'] * $best[$side]['price'] > $depth_base)
                && ($best[$side]['size'] > $depth_alt)
                || $i >= $max_orders )
              break;
          $i++;
        }
      }
      return $best;
    }

    function place_order($product, $type, $side, $price, $size, $tradeId)
    {
      $order = ['type' =>  ucfirst($type) . 'Order',
                'currency'=>  'EUR',
                'direction'=> $side,
                'amount'=> $size,
                ];
      if($type == 'limit') {
        $order['price'] = number_format($price, $product->price_decimals, '.', '');
      }
      var_dump($order);
      $status = $this->jsonRequest('POST', 'user/orders', $order);
      print "{$this->name} trade says:\n";
      var_dump($status);

      if( count($status) )
      {
        $id = $status['uuid'];
        $filled_size = 0;
        $filled_base = 0;

        $i=0;
        $status = $status = $this->getOrderStatus($product, $id);;
        while (($status['status'] != 'closed') && $i < 10) {
          print_dbg("Paymium trade state: {$status['status']}");
          sleep(1);
          $status = $this->getOrderStatus($product, $id);
          $i++;
        }

        if($status['status'] == 'closed')
        {
          $filled_size = $status['filled'];
          $filled_base = $status['filled_base'];
          $price = $filled_base / $filled_size;
        }
        else
          throw new PaymiumAPIException("order $id not filled");
        if ($filled_size > 0)
          $this->save_trade($id, $product, $side, $filled_size, $price, $tradeId);
        return ['filled_size' => $filled_size, 'id' => $id, 'filled_base' => $filled_base, 'price' => $price];
      }
      else
        throw new PaymiumAPIException("place order failed: {$status['msg']}");
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
      while($i<5)
      {
        try{
          $order = $this->jsonRequest('GET', "user/orders/$orderId");
          break;
        }catch (Exception $e){ $i++; usleep(500000); print ("{$this->name}: Failed to get status retrying...$i\n");}
      }

      var_dump($order);
      if(isset($order))
      {
        $price = null;
        if ($order['state'] == 'filled') {
          $status = 'closed';
          $price = $order['traded_currency'] / $order['traded_btc'];
        }
        else
          $status = 'open';

        return  $status = [ 'id' => $orderId,
                            'side' => $order['direction'],
                            'status' => $status,
                            'filled' => $order['traded_btc'],
                            'filled_base' => $order['traded_currency'],
                            'price' => $price
                          ];
        }
    }

    function ping()
    {
      $ping = $this->jsonRequest('GET', '/api/v1/data/eur/ticker');
      return isset($ping['high']) ? false : true;
    }
}
