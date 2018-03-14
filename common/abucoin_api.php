<?php

class AbucoinsAPIException extends ErrorException {};

class AbucoinsApi
{
    const API_URL = 'https://api.abucoins.com';

    protected $accesskey;
    protected $secret;
    protected $passphrase;
    protected $timestamp;
    protected $curl;
    public $nApicalls;
    public $name;
    public $products;

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->secret = $keys->abucoins->secret;
        $this->accesskey = $keys->abucoins->access_key;
        $this->passphrase = $keys->abucoins->passphrase;
        $this->nApicalls = 0;
        $this->name = 'Abucoins';
        $this->curl = curl_init();
        //App specifics
        $this->products = [];
    }
    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($method, $path, $datas)
    {
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;
        //$ch = curl_init();
        curl_setopt($this->curl/*$ch*/, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->curl/*$ch*/, CURLOPT_URL, static::API_URL . "$path");
        if ($method == 'POST') {
            curl_setopt($this->curl/*$ch*/, CURLOPT_POST, 1);
            curl_setopt($this->curl/*$ch*/, CURLOPT_POSTFIELDS, json_encode($datas));
        }
        $this->timestamp = time();
        curl_setopt($this->curl/*$ch*/, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'AC-ACCESS-KEY: ' . $this->accesskey,
            'AC-ACCESS-TIMESTAMP: ' . $this->timestamp,
            'AC-ACCESS-PASSPHRASE: ' . $this->passphrase,
            'AC-ACCESS-SIGN: ' . $this->signature($path, $datas, $this->timestamp, $method),
        ));
        curl_setopt($this->curl/*$ch*/, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($this->curl/*$ch*/);
        //curl_close($ch);
        return json_decode($server_output);
    }

    public function signature($request_path = '', $body = '', $timestamp = false, $method = 'GET')
    {
        $body = is_array($body) ? json_encode($body) : $body;
        $timestamp = $timestamp ? $timestamp : time();
        $what = $timestamp . $method . $request_path . $body;
        return base64_encode(hash_hmac("sha256", $what, base64_decode($this->secret), true));
    }

    function getBalance(...$cryptos)
    {
      foreach($cryptos as $crypto)
      {
        $account = self::jsonRequest('GET', "/accounts/10502694-$crypto", null);
        if(isset($account->available) && floatval($account->available) > 0.000001)
          $res[$crypto] = floatval($account->available);
        else
          $res[$crypto] = 0;
      }
      if(count($res) == 1)
       return array_pop($res);
      else return $res;
    }

    function getBestAsk($product_id)
    {
       $book = self::jsonRequest('GET', "/products/{$product_id}/book?level=1", null);
       if( isset($book->asks[0][0], $book->asks[0][1]))
         return ['price' => floatval($book->asks[0][0]), 'size' => floatval($book->asks[0][1]) ];
       else
         return null;
    }

    function getBestBid($product_id)
    {
       $book = self::jsonRequest('GET', "/products/{$product_id}/book?level=1", null);
       if( isset($book->bids[0][0], $book->bids[0][1]))
         return ['price' => floatval($book->bids[0][0]), 'size' => floatval($book->bids[0][1]) ];
       else
         return null;
    }

    function getOrderStatus($product, $order_id)
    {
       $order = self::jsonRequest('GET', "/orders/{$order_id}", null);
       $status = [ 'status' => $order->status,
                   'filled' => floatval($order->filled_size),
                   'side' => $order->side,
                   'total' => floatval($order->filled_size * $order->price)
                 ];
       return $status;
    }

    function place_order($type, $alt, $side, $price, $size)
    {
      $order = ['product_id' => "$alt-BTC",
                'size'=>  $size,
                'side'=> $side,
                'type'=> $type,
                ];

      if($type == 'limit')
      {
        $order['price'] = $price;
        $order['time_in_force'] = 'IOC';// immediate or cancel
      }


      var_dump($order);
      $ret = self::jsonRequest('POST', '/orders', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);

      if(isset($ret->status))
      {
        if($ret->filled_size > 0)
          self::save_trade($ret->id, $alt, $side, $ret->filled_size, $price);
        return ['filled_size' => $ret->filled_size, 'id' => $ret->id];
      }
      else
        throw new AbucoinsAPIException('place order failed');
    }

    function save_trade($id, $alt, $side, $size, $price)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function getProductList()
    {
      $list = [];
      $products = self::jsonRequest('GET', "/products", null);

      foreach($products as $product)
      if(preg_match('/([A-Z]+)-BTC/', $product->id) )
      {
        $list[] = $product->base_currency;
      }

      return $list;
    }

    function getProductInfo($alt)
    {
      $id = "{$alt}-BTC";
      $product = null;
      while( ($product = self::jsonRequest('GET', "/products/{$id}", null)) ==null)
        continue;
      $info['min_order_size_alt'] = $product->base_min_size;
      $info['increment'] = $product->quote_increment;
      $info['fees'] = 0; //til end of March
      $info['min_order_size_btc'] = 0;
      $info['alt_price_decimals'] = $info['increment'];
      return $info;
    }

    function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
    {
      $id = "{$alt}-BTC";
      $book = self::jsonRequest('GET', "/products/{$id}/book?level=2", null);

      if(!isset($book->asks[0][0], $book->bids[0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book->$side[0][0]);
        $best[$side]['size'] = floatval($book->$side[0][1]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
              || ($best[$side]['size'] < $depth_alt) )
              && $i<50/*max offers for level=2*/)
        {
          if (!isset($book->$side[$i][0], $book->$side[$i][1]))
            break;
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book->$side[$i][0]*$book->$side[$i][1]) / ($book->$side[$i][1]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book->$side[$i][1]);
          $best[$side]['order_price'] = floatval($book->$side[$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }
}
