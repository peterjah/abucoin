<?php
class CryptopiaApi
{
    const API_URL = 'https://www.cryptopia.co.nz/api/';

    protected $publicKey;
    protected $privateKey;
    public $nApicalls;
    public $name;

    public function __construct($settings)
    {
        $this->publicKey = $settings->publicKey;
        $this->privateKey = $settings->privateKey;
        $this->name = 'Cryptopia';
        $this->nApicalls = 0;
    }

    public function jsonRequest($path, array $datas = array())
    {
        $this->nApicalls++;
        $public_set = array( "GetCurrencies", "GetTradePairs", "GetMarkets", "GetMarket", "GetMarketHistory", "GetMarketOrders" );
        //$private_set = array( "GetBalance", "GetDepositAddress", "GetOpenOrders", "GetTradeHistory", "GetTransactions", "SubmitTrade", "CancelTrade", "SubmitTip" );
        $ch = curl_init();
        $url = static::API_URL . "$path";
        $nonce = time();
        $requestContentBase64String = base64_encode( md5( json_encode( $datas ), true ) );
        $signature = $this->publicKey . "POST" . strtolower( urlencode($url) ) . $nonce . $requestContentBase64String;
        $hmacsignature = base64_encode( hash_hmac("sha256", $signature, base64_decode( $this->privateKey ), true ) );
        $header_value = "amx " . $this->publicKey . ":" . $hmacsignature . ":" . $nonce;

        $i = 1;
        $pathLength = count(explode('/', $path));
        while($i < $pathLength)
        {
          $path = dirname($path);
          $i++;
        }
        if ( !in_array($path ,$public_set ) ) {
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
          isset($response) && var_dump($response);
          throw new Exception('Unknown api error');
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
            if(isset($account[0]->Available) && $account[0]->Available > 0.0000001)
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

    function getOrderStatus($product, $order_id)
    {
       $trade_history = self::jsonRequest('GetTradeHistory',['Market'=> $product, 'Count' => 10]);
       foreach ($trade_history as $trade)
         if($trade->TradeId == $order_id)
           $order = $trade;
       $status = [ 'status' => null,
                   'filled' => floatval($order->Amount),
                   'side' => $order->Type,
                   'total' => floatval($order->Total)
                 ];
       return $status;
    }

}
