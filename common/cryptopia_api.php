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
        $this->PriorityLevel = 0;
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
            throw new CryptopiaAPIException($response->Message);
          }
          else
          {
            usleep(500000);
            throw new CryptopiaAPIException('no response from api');
          }
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

    function getOrderStatus($alt, $order_id, $isClose = false)
    {
      print "get order status of $order_id \n";
      if(!$isClose)
      {
        $i=0;
        while($i<8)
        {
          try{
            $open_orders = $this->jsonRequest('POST', 'GetOpenOrders',['Market'=> "{$alt}/BTC", 'Count' => 20]);
            break;
          }catch (Exception $e){ $i++; usleep(500000); print ("Failed to GetOpenOrders...$i\n");}
        }
        foreach ($open_orders as $open_order)
          if($open_order->OrderId == $order_id)
          {
             $order = $open_order;
             var_dump($order);
             break;
          }
      }
      //order has been filled
      if(!isset($order)) {
        $i=0;
        while($i<8)
          {
          try{
            $closed_orders = $this->jsonRequest('POST', 'GetTradeHistory',['Market'=> "{$alt}/BTC", 'Count' => 10]);
            break;
          }catch (Exception $e){ $i++; usleep(500000); print ("Failed to GetTradeHistory:". $e->getMessage()." $i\n");}
        }
        foreach ($closed_orders as $closed_order)
          if($closed_order->TradeId == $order_id)
          {
             $order = $closed_order;
             var_dump($order);
             break;
          }
        $status = 'closed';
        $filled = $size = floatval($order->Amount);
      }
      else {
        var_dump($order);
        $status = 'open';
        $size = floatval($order->Amount);
        $filled = floatval($order->Amount) - floatval($order->Remaining);
      }
      $price = $order->Rate;
      $filled_btc = floatval($filled * $price);
      return  $status = [ 'id' => $order_id,
                          'side' => strtolower($order->Type),
                          'status' => $status,
                          'filled' => $filled,
                          'filled_btc' => $filled_btc,
                          'price' => $price,
                          'size' => $size,
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
        print "market offer:\n"; var_dump($offer);
        $price_diff = 100*(abs($offer['price'] - $price)/$price);
        print "price diff: $price_diff \n";
        if($price_diff > 3/*%*/)
        {
          throw new CryptopiaAPIException('market order failed: real order price is too different from the expected price');
        }
        $price = $offer['price'];
      }

     if($side == 'buy') {
       $bal = @$this->balances['BTC'];
       if(!isset($bal))
         $bal = $this->getBalance('BTC');
       $size = min($size, $bal/$price);
     }
     else {
       $altBal = @$this->balances[$alt];
       if(!isset($altBal))
         $altBal = $this->getBalance($alt);
       $size = min($size, $altBal);
      }

      $order = ['Market' => "$alt/BTC",
                'Type' => $side,
                'Rate' => $price,
                'Amount' => $size,
               ];

      var_dump($order);
      $ret = $this->jsonRequest('POST', 'SubmitTrade', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
      if(isset($ret->FilledOrders))
      {
        $id = $ret->OrderId;
        $filled_size = 0;
        $filled_btc = 0;

        //order filled
        if($id == null)
        {
          $filled_size = $size;
          $filled_btc = $filled_size * $price;
          $id = $ret->FilledOrders[0];
        }
        else //order partially filled or not filled
        {
          foreach($ret->FilledOrders as $fillsId)
          {
            $fills = $this->getOrderStatus($alt, $fillsId, true);
            var_dump($fills);
            $filled_size += $fills['filled'];
            $filled_btc += $fills['filled_btc'];
            print_dbg("{$this->name} order $id status: directly filled:$filled_size ");
          }
          sleep(3);
          $status = $this->getOrderStatus($alt, $id);
          print_dbg("{$this->name} order $id status: {$status['status']} $side $alt order size:{$status['size']} original order size: $size filled:{$status['filled']} ");
          var_dump($status);
          if($this->cancelOrder('notUsed',$id))
          {
            $filled_size += $status['filled'];
            $filled_btc += $status['filled_btc'];
          }
          else {
            $filled_size = $size;
            $filled_btc = $filled_size * $price;
          }
        }
        if ($filled_size > 0)
          $this->save_trade($id, $alt, $side, $filled_size, $price, $tradeId);
        return ['filled_size' => $filled_size, 'id' => $id , 'filled_btc' => $filled_btc, 'price' => $price];
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
        if($product->Status == 'OK')
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
          return $info;
        }
      return null;
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
     while($i<20)
     {
       try{
         $ret = $this->jsonRequest('POST', 'CancelTrade', [ 'Type' => 'Trade', 'OrderId' => intval($orderId)]);
         break;
       }catch (Exception $e){ $i++; sleep(1); print_dbg("Failed to cancel order. [{$e->getMessage()}] retrying... $i");}
     }
     var_dump($ret);
     if(isset($ret['error'])){
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
