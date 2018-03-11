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

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->publicKey = $keys->cryptopia->publicKey;
        $this->privateKey = $keys->cryptopia->privateKey;
        $this->name = 'Cryptopia';
        $this->nApicalls = 0;
        $this->curl = curl_init();
        //App specifics
        $this->products = [];
    }
    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($path, array $datas = array())
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
            return ['error' => $response->Error];
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
      $res = [];
      foreach($cryptos as $crypto)
      {
        $account = null;
        $nbtry = 0;
        while($account == null)
        {
          try
          {
            $account = self::jsonRequest("GetBalance",['Currency'=> $crypto]);
             //var_dump($account);
            if( isset($account[0]->Available))
              $res[$crypto] = $account[0]->Available;
            else
              $res[$crypto] = 0;
          }
          catch (Exception $e)
          {
            $nbtry++;
            print "failed to get balance for $crypto. retry $nbtry...\n";
            sleep(1);
            if($nbtry > 5)
              return 0;
          }
        }
      }

      if(count($res) == 1)
        return array_pop($res);
      else return $res;
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

      $open_orders = self::jsonRequest('GetOpenOrders',['Market'=> "{$alt}/BTC", 'Count' => 10]);
      //$trade_history = self::jsonRequest('GetTradeHistory',['Market'=> "{$alt}/BTC", 'Count' => 10]);
      foreach ($open_orders as $open_order)
      if($open_order->OrderId == $order_id)
         $order = $open_order;
      var_dump($order);
      if(!isset($order)) {//order has been filled
        $status = 'closed';
        $filled = -1;
        $filled_btc = -1;
      }
      else {
        $status = 'partially_filled';
        $filled = $order->Amount - $order->Remaining;
        $filled_btc = $filled * $order->Rate;
      }
      return  $status = [ 'status' => $status,
                          'filled' => $filled,
                          'filled_btc' => $filled_btc
                        ];
    }

    function save_trade($id, $alt, $side, $size, $price)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function place_order($type, $alt, $side, $price, $size, $alt_price_decimals)
    {
      var_dump($alt_price_decimals);
      $min_trade_size_btc = $this->product[$alt]->min_order_size_btc;
      $market_price = $side == 'buy' ? $price*2 : ceiling($min_trade_size_btc/$size, $alt_price_decimals);

      var_dump($market_price);
      $order = ['Market' => "$alt/BTC",
                'Type' => $side,
                'Rate' =>  $type == 'limit' ? $price : $market_price,
                'Amount' => $size,
               ];

      var_dump($order);
      $ret = self::jsonRequest('SubmitTrade', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
      if(isset($ret->FilledOrders))
      {
        if (count($ret->FilledOrders))
        {
          $filled_size = $size; //it is the exact filled size ?
          $id = $ret->FilledOrders[0];
          self::save_trade($id, $alt, $side, $size, $price);
        }
        else
        {
          $filled_size = -1;//order not fully filled yet
          $id = $ret->OrderId;
        }
        return ['filled_size' => $filled_size, 'id' => $id];
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
          $info['alt_price_decimals'] = $info['increment'];
          break;
        }
      return $info;
    }

   function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
   {
     $id = "{$alt}_BTC";
     $ordercount = 25;
     $book = self::jsonRequest("GetMarketOrders/{$id}/$ordercount");

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

   function cancelOrder($orderId)
   {
     print ("canceling order $orderId\n");
     $ret = self::jsonRequest('CancelTrade', [ 'Type' => 'Trade', 'OrderId' => intval($orderId)]);
     var_dump($ret);
     if(isset($ret['error']))
       return false;
     return true;

   }
}
