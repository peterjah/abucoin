<?php

class CryptopiaAPIException extends ErrorException {};

class CryptopiaApi
{
    const API_URL = 'https://www.cryptopia.co.nz/api/';

    protected $publicKey;
    protected $privateKey;
    protected $curl;
    public $nApicalls;
    public $name;
    public $products;
    public $balances;

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->publicKey = $keys->cryptopia->publicKey;
        $this->privateKey = $keys->cryptopia->privateKey;
        $this->name = 'Cryptopia';
        $this->nApicalls = 0;
        $this->curl = curl_init();
        $this->PriorityLevel = 1;
        //App specifics
        $this->products = [];
        $this->balances = [];
    }
    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($method = null, $path, array $datas = array())
    {
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;
        $public_set = array( "GetCurrencies", "GetTradePairs", "GetMarkets", "GetMarket", "GetMarketHistory", "GetMarketOrders" );
        //$private_set = array( "GetBalance", "GetDepositAddress", "GetOpenOrders", "GetTradeHistory", "GetTransactions", "SubmitTrade", "CancelTrade", "SubmitTip" );
        $ch = curl_init();
        $url = static::API_URL . "$path";
        $nonce = time();
        $i = 1;
        while($i < count(explode('/', $path)))
        {
          $path = dirname($path);
          $i++;
        }
        if ( !in_array($path ,$public_set ) ) {
          $requestContentBase64String = base64_encode( md5( json_encode( $datas ), true ) );
          $signature = $this->publicKey . "POST" . strtolower( urlencode($url) ) . $nonce . $requestContentBase64String;
          $hmacsignature = base64_encode( hash_hmac("sha256", $signature, base64_decode( $this->privateKey ), true ) );
          $header_value = "amx " . $this->publicKey . ":" . $hmacsignature . ":" . $nonce;
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
        if(isset($response->Success))
        {
          if ($response->Success)
            return $response->Data;
          else
          {
            print "Cryptopia Api error: $response->Error \n";
            return ['error' => $response->Error];
          }
        }
        else
        {
          if (isset($response))
          {
            var_dump($response);
            throw new Exception($response->Message);
          } else throw new Exception('no response from api');
        }
    }

    function getBalance(...$cryptos)
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
          $currencies = array_column($accounts, 'Symbol');
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
      if(count($res) == 1)
        return array_pop($res);
      else return $res;
    }

    function getOrderStatus($alt, $order_id)
    {
      print "get order status of $order_id \n";
      $i=0;
      while($i<5)
      {
        try{
          $open_orders = $this->jsonRequest('POST', 'GetOpenOrders',['Market'=> "{$alt}/BTC", 'Count' => 10]);
          break;
        }catch (Exception $e){ $i++; sleep(0.5); print ("Failed to get status retrying...$i\n");}
      }
      //$trade_history = $this->jsonRequest('GetTradeHistory',['Market'=> "{$alt}/BTC", 'Count' => 10]);
      foreach ($open_orders as $open_order)
        if($open_order->OrderId == $order_id)
        {
           $order = $open_order;
           break;
        }
      var_dump($order);
      if(!isset($order)) {//order has not been filled?
        $status = 'closed';
        $filled = 0;
        $filled_btc = 0;
      }
      else {
        var_dump($order);
        $status = 'open';
        $filled = floatval($order->Amount - $order->Remaining);
        $filled_btc = floatval($filled * $order->Rate);
      }
      return  $status = [ 'status' => $status,
                          'filled' => $filled,
                          'filled_btc' => $filled_btc
                        ];
    }

    function save_trade($id, $alt, $side, $size, $price, $tradeId)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function place_order($type, $alt, $side, $price, $size, $tradeId)
    {
      if($type == 'market')
      {
        $book = $this->getOrderBook($alt, null, $size);
        $offer = $side == 'buy' ? $book['asks'] : $book['bids'];
      }

     if($side == 'buy') {
       $bal = @$this->balances['BTC'];
       if(!isset($bal))
         $bal = $this->getBalance('BTC');
        $new_size = min($size, $bal/$price);
     }
     else {
       $altBal = @$this->balances[$alt];
       if(!isset($altBal))
         $altBal = $this->getBalance($alt);
        $new_size = min($size, $altBal);
      }

      $order = ['Market' => "$alt/BTC",
                'Type' => $side,
                'Rate' => $type == 'limit' ? $price : $offer['order_price'],
                'Amount' => $new_size,
               ];

      var_dump($order);
      $ret = $this->jsonRequest('POST', 'SubmitTrade', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
      if(isset($ret->FilledOrders))
      {
        if (count($ret->FilledOrders))
        {
          $filled_size = $new_size; //information is not provided here
          $id = $ret->FilledOrders[0];
          $filled_btc = 0;
          $this->save_trade($id, $alt, $side, $size, $type == 'limit' ? $price : $offer['price'], $tradeId);
        }
        else
        {
          $filled_size = -1;//order not fully filled yet
          $filled_btc = -1;
          $id = $ret->OrderId;
          if($type == 'market')//should not happen
          {
            $debug_str = date("Y-m-d H:i:s")." Should not happen order stil open on {$this->name}: $id $side $alt @ $price filled:$filled_size\n";
            file_put_contents('debug',$debug_str,FILE_APPEND);
            $this->cancelOrder('notUsed',$id);
            throw new CryptopiaAPIException('market order failed');
          }
        }
        return ['filled_size' => $filled_size, 'id' => $id , 'filled_btc' => $filled_btc];
      }
      else {
        throw new CryptopiaAPIException($ret['error']);
      }
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET', 'GetTradePairs');
      foreach($products as $product)
      if(preg_match('/([A-Z]+)\/BTC/', $product->Label) )
      {
        if( $product->Symbol != 'BTG') //BTG is not Bitcoin Gold on cryptopia...
          $list[] = $product->Symbol;
      }
      return $list;
    }

    function getProductInfo($alt)
    {
      $id = "{$alt}/BTC";
      $products = $this->jsonRequest('GET', 'GetTradePairs');
      foreach( $products as $product)
        if($product->Label == $id)
        {
          $info['min_order_size_alt'] = $info['increment'] = $product->MinimumTrade;
          $info['fees'] = $product->TradeFee;
          $info['min_order_size_btc'] = $product->MinimumBaseTrade;
          $info['alt_price_decimals'] = 8;//$info['increment'];
          break;
        }
      return $info;
    }

   function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
   {
     $id = "{$alt}_BTC";
     $ordercount = 25;
     $book = $this->jsonRequest('GET', "GetMarketOrders/{$id}/$ordercount");
     //var_dump($book);
     if(!isset($book->Sell, $book->Buy))
       return null;
     foreach( ['asks', 'bids'] as $side)
     {
       $offer = $side == 'asks' ? $book->Sell : $book->Buy;
       $best[$side]['price'] = $best[$side]['order_price'] = floatval($offer[0]->Price);
       $best[$side]['size'] = floatval($offer[0]->Volume);
       $i=1;
       while( (($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
              || ($best[$side]['size'] < $depth_alt) )
              && $i < $ordercount)
       {
         $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $offer[$i]->Total) / ($best[$side]['size']+$offer[$i]->Volume) );
         $best[$side]['size'] += floatval($offer[$i]->Volume);
         $best[$side]['order_price'] = floatval($offer[$i]->Price);
         $i++;
       }
     }
     return $best;
   }

   function cancelOrder($alt, $orderId)
   {
     print ("canceling order $orderId\n");
     $i=0;
     while($i<10)
     {
       try{
         $ret = $this->jsonRequest('POST', 'CancelTrade', [ 'Type' => 'Trade', 'OrderId' => intval($orderId)]);
         break;
       }catch (Exception $e){ $i++; sleep(1); print ("Failed to cancel order. retrying...$i\n");}
     }
     var_dump($ret);
     if(isset($ret['error']))
       return false;
     return true;

   }
}
