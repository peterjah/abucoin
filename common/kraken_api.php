<?php

class KrakenAPIException extends ErrorException {};

class KrakenApi
{
    const API_URL = 'https://api.kraken.com';
    const API_VERSION = '0';
    protected $key;     // API key
    protected $secret;  // API secret
    protected $curl;    // curl handle
    public $nApicalls;
    public $name;

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->secret = $keys->kraken->secret;
        $this->key = $keys->kraken->key;
        $this->nApicalls = 0;
        $this->name = 'Kraken';
        $this->curl = curl_init();

        curl_setopt_array($this->curl, array(
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Kraken PHP API Agent',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true)
        );

    }

    function __destruct()
    {
        curl_close($this->curl);
    }

    /**
     * Query public methods
     *
     * @param string $method method name
     * @param array $request request parameters
     * @return array request result on success
     * @throws KrakenAPIException
     */
    public function jsonRequest($method, array $request = array())
    {
        $public_set = array( 'Ticker', 'Assets', 'Depth', 'AssetPairs');

        if ( !in_array($method ,$public_set ) )
        { //private method
          if(!isset($request['nonce'])) {
            // generate a 64 bit nonce using a timestamp at microsecond resolution
            // string functions are used to avoid problems on 32 bit systems
            $nonce = explode(' ', microtime());
            $request['nonce'] = $nonce[1] . str_pad(substr($nonce[0], 2, 6), 6, '0');
          }
          $postdata = http_build_query($request, '', '&');
          // set API key and sign the message
          $path = '/' . self::API_VERSION . '/private/' . $method;
          $sign = hash_hmac('sha512', $path . hash('sha256', $request['nonce'] . $postdata, true), base64_decode($this->secret), true);
          $headers = array(
              'API-Key: ' . $this->key,
              'API-Sign: ' . base64_encode($sign)
              );
        }
        else
        {
          $path = '/' . self::API_VERSION . '/public/' . $method;
          $headers = array ();
          $postdata = http_build_query($request, '', '&');
        }
        // make request
        curl_setopt($this->curl, CURLOPT_URL, self::API_URL . $path);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($this->curl);
        if($result===false)
            throw new KrakenAPIException('CURL error: ' . curl_error($this->curl));

        // decode results
        $result = json_decode($result, true);
        if(!is_array($result))
            throw new KrakenAPIException('JSON decode error');

        return $result;
    }

    static function crypto2kraken($crypto)
    {
      $table = ['BTC' => 'XXBT',
                'BTC' => 'XXBT',
                'XRP' => 'XXRP',
                'LTC' => 'XLTC',
                'XLM' => 'XXLM',
                'ETH' => 'XETH',
                'ETC' => 'XETC',
                'REP' => 'XREP',
                'ZEC' => 'XZEC',
                'XMR' => 'XXMR',
                'EOS' => 'EOS',
                'BCH' => 'BCH'
                ];
      return $table[$crypto];
    }

    function getBalance($crypto)
    {
       $balances = self::jsonRequest('Balance');
       $kraken_name = self::crypto2kraken($crypto);
       if(isset($balances['result'][$kraken_name]))
         return floatval($balances['result'][$kraken_name]);
       else
         return 0;
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
      $products = self::jsonRequest('AssetPairs');

      foreach($products['result'] as $product)
      if(preg_match('/([A-Z]+)XBT$/', $product['altname'], $matches) )
      {
        $list[] = $matches[1];
      }
      return $list;
    }

    function getProductInfo($alt)
    {
      $id = "{$alt}XBT";
      $products = null;
      $products = self::jsonRequest('AssetPairs');
      foreach($products['result'] as $product)
        if($product['altname'] == $id)
        {
          $info['min_order_size_alt'] = $info['increment'] = pow(10,-1*$product['lot_decimals']);
          $info['fees'] = $product['fees'][0/*depending on monthly spendings*/][1];
          $info['min_order_size_btc'] = pow(10,-1*$product['pair_decimals']);
          break;
        }
      return $info;
    }
}
