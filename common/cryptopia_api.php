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

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->publicKey = $keys->cryptopia->publicKey;
        $this->privateKey = $keys->cryptopia->privateKey;
        $this->name = 'Cryptopia';
        $this->nApicalls = 0;
        $this->curl = curl_init();
    }
    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($path, array $datas = array())
    {
        $this->nApicalls++;
        $public_set = array( "GetCurrencies", "GetTradePairs", "GetMarkets", "GetMarket", "GetMarketHistory", "GetMarketOrders" );
        //$private_set = array( "GetBalance", "GetDepositAddress", "GetOpenOrders", "GetTradeHistory", "GetTransactions", "SubmitTrade", "CancelTrade", "SubmitTip" );
        $ch = curl_init();
        $url = static::API_URL . "$path";
        $nonce = time();
        $i = 1;
        $pathLength = count(explode('/', $path));
        while($i < $pathLength)
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
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datas));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE); // Do Not Cache
        $server_output = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($server_output);
        if(isset($response->Success))
        {
          if ($response->Success)
            return $response->Data;
          else
            return $response->Error;
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

    function getBalance($crypto)
    {
      $account = null;
      while($account == null)
      {
        try {
          $account = self::jsonRequest("GetBalance",['Currency'=> $crypto]);
          //var_dump($account);
          //isset($account[0]->Available) && var_dump($account[0]->Available);
          if($account)
            if(isset($account[0]->Available) && $account[0]->Available > 0.000001)
             return $account[0]->Available;

        }
        catch (Exception $e)
        {
          print $e;
          sleep(1);
        }
      }
    }

    function getBestAsk($product_id)
    {
       $book = self::jsonRequest("GetMarketOrders/{$product_id}/1");
       if( isset($book->Sell[0]->Price, $book->Sell[0]->Volume))
         return ['price' => floatval($book->Sell[0]->Price), 'size' => floatval($book->Sell[0]->Volume) ];
       else
         return null;
    }

    function getBestBid($product_id)
    {
       $book = self::jsonRequest("GetMarketOrders/{$product_id}/1");
       if( isset($book->Buy[0]->Price, $book->Buy[0]->Volume))
         return ['price' => floatval($book->Buy[0]->Price), 'size' => floatval($book->Buy[0]->Volume) ];
       else
         return null;
    }

    function getOrderStatus($alt, $order_id)
    {
       $trade_history = self::jsonRequest('GetTradeHistory',['Market'=> "{$alt}/BTC", 'Count' => 10]);
       var_dump($trade_history);
       foreach ($trade_history as $trade)
         if($trade->TradeId == $order_id)
           $order = $trade;
      if(!isset($order))
        throw new CryptopiaAPIException('get order status failed');

       $status = [ 'status' => null,
                   'filled' => floatval($order->Amount),
                   'side' => $order->Type,
                   'total' => floatval($order->Total)
                 ];
       return $status;
    }

    function save_trade($id, $alt, $side, $size, $price)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function place_limit_order($alt, $side, $price, $size)
    {
      $order = ['Market' => "$alt/BTC",
                'Type' => $side,
                'Rate' =>  $price,
                'Amount' => $size,
               ];
      var_dump($order);
      $ret = self::jsonRequest('SubmitTrade', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
      if(isset($ret->FilledOrders))
      {
        $trade_id = isset($ret->FilledOrders[0]) ? $ret->FilledOrders[0] : $ret->OrderId/*order not filled completely*/;
        self::save_trade($trade_id, $alt, $side, $size, $price);
        return $trade_id;
      }
      else
        throw new CryptopiaAPIException('place order failed');
    }

    function getProductList()
    {
      $list = [];
      $products = self::jsonRequest('GetTradePairs');
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
      $products = self::jsonRequest('GetTradePairs');
      foreach( $products as $product)
        if($product->Label == $id)
        {
          $info['min_order_size_alt'] = $info['increment'] = $product->MinimumTrade;
          $info['fees'] = $product->TradeFee;
          $info['min_order_size_btc'] = $product->MinimumBaseTrade;
          break;
        }
      return $info;
    }

   function getOrderBook($alt, $depth_btc = 0)
   {
     $id = "{$alt}_BTC";
     $ordercount = 25;
     $book = self::jsonRequest("GetMarketOrders/{$id}/$ordercount");

     if(!isset($book->Sell, $book->Buy))
       return null;
     foreach( ['asks', 'bids'] as $side)
     {
       $offer = $side == 'asks' ? $book->Buy : $book->Sell;
       $best[$side]['price'] = $best[$side]['order_price'] = floatval($offer[0]->Price);
       $best[$side]['size'] = floatval($offer[0]->Volume);
       $i=1;
       while(($best[$side]['size'] * $best[$side]['price'] < $depth_btc) && $i<50/*should be useless*/)
       {
         $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $offer[$i]->Total) / ($best[$side]['size']+$offer[$i]->Volume) );
         $best[$side]['size'] += floatval($offer[$i]->Volume);
         $best[$side]['order_price'] = floatval($offer[$i]->Price);
         $i++;
       }
     }
     return $best;
   }
}
