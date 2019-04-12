<?php

class CryptopiaAPIException extends ErrorException {};

class CryptopiaApi
{
    const API_URL = 'https://www.cryptopia.co.nz/api/';

    protected $key;
    protected $secret;
    protected $curl;
    protected $api_calls;
    public $api_calls_rate;
    public $name;
    protected $products;
    public $balances;
    protected $time;
    public $orderbook_file;
    public $orderbook_depth;
    public $using_websockets;
    public $max_price_diff;

    public function __construct($max_price_diff = null)
    {
      $keys = json_decode(file_get_contents("../common/private.keys"));
      if (!isset($keys->cryptopia))
        throw new CryptopiaAPIException("Unable to retrieve private keys");
      $this->key = $keys->cryptopia->api_key;
      $this->secret = $keys->cryptopia->secret;
      $this->name = 'Cryptopia';
      $this->api_calls = 0;
      $this->api_calls_rate = 0;
      $this->time = time();
      $this->curl = curl_init();
      $this->PriorityLevel = 0;
      //App specifics
      $this->products = [];
      $this->balances = [];

      if (isset($max_price_diff))
        $this->max_price_diff = $max_price_diff;
      else
        $this->max_price_diff = 0.01;//1%
    }

    function __destruct()
    {
      curl_close($this->curl);
    }

    public function jsonRequest($method = null, $path, array $datas = array())
    {
      $this->api_calls++;
      $now = time();
      if (($now - $this->time) > 60) {
        $this->api_calls_rate = $this->api_calls;
        $this->api_calls = 0;
        $this->time = $now;
      }

      $public_set = array( "GetCurrencies", "GetTradePairs", "GetMarkets", "GetMarket", "GetMarketHistory", "GetMarketOrders" );
      //$private_set = array( "GetBalance", "GetDepositAddress", "GetOpenOrders", "GetTradeHistory", "GetTransactions", "SubmitTrade", "CancelTrade", "SubmitTip" );
      $ch = curl_init();
      $url = static::API_URL . "$path";
      $nonce = number_format(microtime(true) * 1000, 0, '.', '');
      $i = 1;
      while($i < count(explode('/', $path)))
      {
        $path = dirname($path);
        $i++;
      }
      if ( !in_array($path ,$public_set ) ) {
        $requestContentBase64String = base64_encode( md5( json_encode( $datas ), true ) );
        $signature = $this->key . "POST" . strtolower( urlencode($url) ) . $nonce . $requestContentBase64String;
        $hmacsignature = base64_encode( hash_hmac("sha256", $signature, base64_decode( $this->secret ), true ) );
        $header_value = "amx " . $this->key . ":" . $hmacsignature . ":" . $nonce;
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        "Authorization: $header_value",
        ));

      }
      if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datas));
      }

      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
      curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE); // Do Not Cache
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);

      $server_output = curl_exec($ch);
      curl_close($ch);

      $response = json_decode($server_output);

      if(isset($response->Success)) {
        if ($response->Success)
          return $response->Data;
        else {
          print "Cryptopia Api error: $response->Error \n";
          if($response->Error == 'API calls quota exceeded! maximum 100 calls per Minute') {
            sleep(15);
          }
          throw new CryptopiaAPIException($response->Error);
        }
      }
      else {
        if (isset($response)) {
          //var_dump($response);
          throw new CryptopiaAPIException($response->Message);
        }
        else {
          if ($server_output == 'The service is unavailable.' || empty($server_output)) {
            usleep(50000);
          }
          else
            throw new CryptopiaAPIException('no response from api');
        }
      }
    }

    function getBalance($alt = null)
    {
      $i=0;
      $accounts = null;
      while($accounts == null)
      {
        try {
          $accounts = $this->jsonRequest('POST', "GetBalance",['Currency'=> null]);
          foreach($accounts as $bal)
          {
            $this->balances[$bal->Symbol] = floatval($bal->Available);
          }
          $cryptos = array_column($accounts, 'Symbol');
          break;
        }
        catch (Exception $e)
        {
          $i++;
          print "{$this->name}: failed to get balances. retry $i...\n";
          usleep(50000);
          if($i > 8)
            throw new CryptopiaAPIException('failed to get balances');
        }
      }

      //var_dump($accounts);
      $res = [];
      foreach($cryptos as $crypto)
      {
        $res[$crypto] = isset($this->balances[$crypto]) ? $this->balances[$crypto] : 0;
      }
      if( !isset($res) )
        throw new CryptopiaAPIException('failed to get balances');
      if($alt != null)
        return $res[$alt];
      else return $res;
    }

    function getOpenOrders($filter = null)
    {
      $params = [];
      $params['Count'] = 20;
      if(isset($filter['alt']))
        $params['Market'] = "{$filter['alt']}/{$filter['base']}";

      $i=0;
      while($i<8)
      {
        try{
          $open_orders = $this->jsonRequest('POST', 'GetOpenOrders', $params);
          break;
        }catch (Exception $e){ $i++; usleep(50000); /*print_dbg("Failed to GetOpenOrders. [{$e->getMessage()}]..$i");*/}
      }
      if(isset($filter['since']))//in seconds
      {
        foreach($open_orders as $idx => $order)
        {
          if((time() - strtotime($order->TimeStamp)) > $filter['since'])
            unset($open_orders[$idx]);
        }
      }
      return $open_orders;
    }

    function getOrderHistory($filter = null)
    {
      $params = [];
      $params['Count'] = 20;
      if(isset($filter['alt']))
        $params['Market'] = "{$filter['alt']}/{$filter['base']}";

      $i=0;
      while($i<8)
      {
        try{
          $history = $this->jsonRequest('POST', 'GetTradeHistory', $params);
          break;
        }catch (Exception $e){ $i++; usleep(100000); print("Failed to getOrderHistory. [{$e->getMessage()}]..$i");}
      }
      if(isset($filter['since']))//in seconds
      {
        foreach($history as $idx => $order)
        {
          if((time() - strtotime($order->TimeStamp)) > $filter['since'])
            unset($history[$idx]);
        }
      }
      return $history;
    }

    function getOrderStatus($product, $order_id, $isClose = false)
    {
      $alt = $product->alt;
      $base = $product->base;
      print "get order status of $order_id \n";
      if(!$isClose)
      {
        $open_orders = $this->getOpenOrders(['alt' => $alt, 'base' => $base]);

        foreach ($open_orders as $open_order)
          if($open_order->OrderId == $order_id)
          {
             $order = $open_order;
             var_dump($order);
             break;
          }
      }
      //order has been filled// fixme two different scenario
      if(!isset($order)) {
        $closed_orders = $this->getOrderHistory(['alt' => $alt, 'base' => $base]);
        foreach ($closed_orders as $closed_order)
          if($closed_order->TradeId == $order_id)
          {
             $order = $closed_order;
             var_dump($order);
             $filled = $size = floatval($order->Amount);
             break;
          }
        $status = 'closed';
      }
      else {
        var_dump($order);
        $status = 'open';
        $size = floatval($order->Amount);
        $filled = floatval($order->Amount) - floatval($order->Remaining);
      }
      $price = $order->Rate;
      $filled_base = floatval($filled * $price);
      return  $status = [ 'id' => $order_id,
                          'side' => strtolower($order->Type),
                          'status' => $status,
                          'filled' => $filled,
                          'filled_base' => $filled_base,
                          'price' => $price,
                          'size' => $size,
                        ];
    }

    function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price $base\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function place_order($product, $type, $side, $price, $size, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;

      if($type == 'market') {
        $book = $this->getOrderBook($product, $product->min_order_size_base, $size);
        if ($side == 'buy') {
          $new_price = $book['asks']['order_price'];
          $price_diff = ($new_price / $price) - 1;
        } else {
          $new_price = $book['bids']['order_price'];
          $price_diff = 1 - ($new_price / $price);
        }
        print_dbg("{$this->name}: market offer: $new_price price diff: $price_diff");
        if($price_diff > $this->max_price_diff) {
          throw new CryptopiaAPIException('market order failed: real order price is too different from the expected price');
        }
        $price = $offer['price'];
      }
     if($side == 'buy') {
       $bal = @$this->balances[$base];
       if($bal == 0)
         $bal = $this->getBalance($base);
       $size = min($size, $bal/$price);
     }
     else {
       $altBal = @$this->balances[$alt];
       if($altBal == 0)
         $altBal = $this->getBalance($alt);
       $size = min($size, $altBal);
      }

      $order = ['Market' => "{$alt}/{$base}",
                'Type' => $side,
                'Rate' => $price,
                'Amount' => $size,
               ];

      var_dump($order);
      try {
        $order_timestamp = time();
        $ret = $this->jsonRequest('POST', 'SubmitTrade', $order);
        print "{$this->name} trade says:\n";
        var_dump($ret);
        if(isset($ret->FilledOrders))
        {
          $id = $ret->OrderId;
          $filled_size = 0;
          $filled_base = 0;

          //order filled
          if($id == null) {
            $filled_size = $size;
            $filled_base = $filled_size * $price;
            $id = $ret->FilledOrders[0];
          }
          else {//order partially filled or not filled
            foreach($ret->FilledOrders as $fillsId) {
              $fills = $this->getOrderStatus($product, $fillsId, true);
              var_dump($fills);
              $filled_size += $fills['filled'];
              $filled_base += $fills['filled_base'];
              print_dbg("{$this->name} order $id status: directly filled:$filled_size ");
            }
            sleep(3);
            $status = $this->getOrderStatus($product, $id);
            print_dbg("{$this->name} order $id status: {$status['status']} $side $alt order size:{$status['size']} original order size: $size filled:{$status['filled']} ", true);
            var_dump($status);
            if($this->cancelOrder($product,$id)) {
              $filled_size += $status['filled'];
              $filled_base += $status['filled_base'];
            }
            else {
              $filled_size = $size;
              $filled_base = $filled_size * $price;
            }
          }
          if ($filled_size > 0)
            $this->save_trade($id, $product, $side, $filled_size, $price, $tradeId);
          return ['filled_size' => $filled_size, 'id' => $id , 'filled_base' => $filled_base, 'price' => $price];
        }
      }
      catch(CryptopiaAPIException $e) {
        $err = $e->getMessage();
        print "$err";
        if($err == 'no response from api')
        {
          //what to do with no traced executed trade? :(
          //this could be dangerous...

          $open_orders = $this->GetOpenOrders(['alt' => $alt, 'base' => $base, 'since' => time() - $order_timestamp]);
          if(!empty($open_orders))
          {
            print_dbg("{$this->name} $err: ".count($open_orders)." open order found", true);
            foreach($open_orders as $order) // should be only one
            {
              $cancelOk = $this->cancelOrder($product, $order->OrderId);
              print_dbg("{$this->name} Canceling open order after no api response: " . ( $cancelOk ? "Ok":"Ko"));
            }
          }
          else {// order has been filled ??
            print_dbg("{$this->name} $err: No open orders found", true);
            $history = $this->getOrderHistory(['alt' => $alt, 'base' => $base, 'since' => time() - $order_timestamp]);
            if(!empty($history))
            {
              print_dbg("{$this->name} $err: ".count($history)." closed order found in history", true);
              foreach($history as $order) // should be only one
              {
                var_dump($order);
                if ($order->Amount == $size) {
                  print_dbg("{$this->name} Saving $size $alt order after no api response: ", true);
                  $price = $order->Rate;
                  $id = $order->TradeId;
                  $this->save_trade($id, $product, $side, $size, $price, $tradeId);
                  return ['filled_size' => $size, 'id' => $id, 'filled_base' => $price * $size, 'price' => $price];
                }
              }
            }
          }
            //print_dbg("{$this->name} $alt order failed: $err");
        }
        throw new CryptopiaAPIException($err);
      }
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET', 'GetTradePairs');
      //var_dump($products);
      foreach($products as $product) {
        if ($product->Status == 'OK') {
          $alt = $product->Symbol;
          $base = $product->BaseSymbol;
          $params = [ 'api' => $this,
                      'alt' => $alt,
                      'base' => $base,
                      'fees' => $product->TradeFee,
                      'min_order_size' => $product->MinimumTrade,
                      'lot_size_step' => $product->MinimumTrade,
                      'size_decimals' => strlen(substr(strrchr(number_format($product->MinimumTrade,8,'.',''), "."), 1)),
                      'min_order_size_base' => $product->MinimumBaseTrade,
                      'price_decimals' => strlen(substr(strrchr(number_format($product->MinimumPrice,8,'.',''), "."), 1)),
                    ];
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

   function getOrderBook($product, $depth_base = 0, $depth_alt = 0)
   {
     $id = "{$product->alt}_{$product->base}";
     $ordercount = 25;
     $i=0;
     while (true) {
       try {
         $book = $this->jsonRequest('GET', "GetMarketOrders/{$id}/$ordercount");
         break;
       } catch (Exception $e) {
         if($i > 8)
           throw new BinanceAPIException("failed to get order book [{$e->getMessage()}]");
         $i++;
         print "{$this->name}: failed to get order book. retry $i...\n";
         usleep(50000);
       }
     }
     //var_dump($book);
     if(!isset($book->Sell[0], $book->Buy[0]))
        throw new CryptopiaAPIException("{$this->name}: failed to get order book $id with " . ($this->using_websockets ? 'websocket' : 'rest api'));

     foreach( ['asks', 'bids'] as $side) {
       $offer = $side == 'asks' ? $book->Sell : $book->Buy;
       $best[$side]['price'] = $best[$side]['order_price'] = floatval($offer[0]->Price);
       $best[$side]['size'] = floatval($offer[0]->Volume);
       $i=1;
       while( (($best[$side]['size'] * $best[$side]['price'] < $depth_base)
              || ($best[$side]['size'] < $depth_alt) )
              && $i < $ordercount) {
         $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $offer[$i]->Total) / ($best[$side]['size']+$offer[$i]->Volume) );
         $best[$side]['size'] += floatval($offer[$i]->Volume);
         $best[$side]['order_price'] = floatval($offer[$i]->Price);
         $i++;
       }
     }
     return $best;
   }

   function cancelOrder($product, $orderId)
   {
     print_dbg("canceling order $orderId", true);
     $i=0;
     while($i<20) {
       try {
         $ret = $this->jsonRequest('POST', 'CancelTrade', [ 'Type' => 'Trade', 'OrderId' => intval($orderId)]);
         break;
       } catch (Exception $e){ $i++; usleep(50000); print_dbg("Failed to cancel order. [{$e->getMessage()}] retrying... $i");}
     }
     var_dump($ret);
     if(isset($ret['error'])) {
        print_dbg("Failed to cancel order! [{$ret['error']}]. It may be filled");
        return false;
     }
     return true;
   }

   function ping()
   {
     $list = $this->getProductList();
     return count($list) ? true : false;
   }
}
