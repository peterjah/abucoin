<?php
require_once('../common/tools.php');
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
    public $products;
    public $balances;

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->secret = $keys->kraken->secret;
        $this->key = $keys->kraken->key;
        $this->nApicalls = 0;
        $this->name = 'Kraken';
        $this->PriorityLevel = 15;

        $this->curl = curl_init();
        curl_setopt_array($this->curl, array(
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Kraken PHP API Agent',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true)
        );

        //App specifics
        $this->products = [];
        $this->balances = [];
    }

    function __destruct()
    {
        curl_close($this->curl);
    }

    public function jsonRequest($method, array $request = array())
    {
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;

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
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 5);
        $result = curl_exec($this->curl);
        if($result===false)
            throw new KrakenAPIException('CURL error: ' . curl_error($this->curl));

        // decode results
        $result = json_decode($result, true);
        if(!is_array($result))
            throw new KrakenAPIException('JSON decode error');
        if(isset($result['error'][0]) && $result['error'][0] == 'EAPI:Rate limit exceeded')
        {
          print "Kraken Api call limit reached\n";
          sleep(15);
        }

        return $result;
    }

    static function crypto2kraken($crypto)
    {
      $table = ['BTC' => 'XXBT',
                'XRP' => 'XXRP',
                'LTC' => 'XLTC',
                'XLM' => 'XXLM',
                'ETH' => 'XETH',
                'ETC' => 'XETC',
                'REP' => 'XREP',
                'ZEC' => 'XZEC',
                'XMR' => 'XXMR',
                'EOS' => 'EOS',
                'BCH' => 'BCH',
                'DASH' => 'DASH',
                'GNO' => 'GNO',
                'ICN' => 'ICN'
                ];
      return $table[$crypto];
    }

    function getBalance(...$cryptos)
    {

      $res = [];
      //var_dump($cryptos);
      $i=0;
      while (!isset($balances['result']) || !isset($positions['result']) && $i<5)
      {
        $balances = $this->jsonRequest('Balance');
        $positions = $this->jsonRequest('OpenOrders');

        foreach($cryptos as $crypto)
        {
          $kraken_name = $this->crypto2kraken($crypto);
          $pair = $this->getPair($crypto);
          //var_dump($balances);
          if(isset($balances['result'][$kraken_name]) && floatval($balances['result'][$kraken_name] > 0) )
          {

            $crypto_in_order = 0;
            if(isset($positions['result']) && count($positions['result']['open']))
            {
              foreach($positions['result']['open'] as $openOrder)
              {
                if($openOrder['descr']['pair'] == $pair) //Sell orders
                {
                  $crypto_in_order += $openOrder['vol'];
                }
                elseif($crypto == 'BTC' && $openOrder['descr']['type'] == 'buy')
                {
                  $crypto_in_order += $openOrder['vol'] * $openOrder['descr']['price'];
                }
              }
            }
            $res[$crypto] = floatval($balances['result'][$kraken_name] - $crypto_in_order);
          }
          else
            $res[$crypto] = 0;
        }
        $i++;
      }
      if( !isset($res) )
        throw new KrakenAPIException('failed to get balances');

      if(count($res) == 1)
        return array_pop($res);
      else return $res;
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
      $products = $this->jsonRequest('AssetPairs');

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
      $products = $this->jsonRequest('AssetPairs');
      $pair = $this->getPair($alt);

      $tradeVolume = $this->jsonRequest('TradeVolume', ['pair' => $pair]);
      //var_dump($tradeVolume);
      foreach($products['result'] as $product)
        if($product['altname'] == $id)
        {
          //var_dump($product);
          $info['min_order_size_alt'] = $this->minimumAltTrade($alt);
          $info['increment'] = pow(10,-1*$product['lot_decimals']);
          $info['fees'] = floatval($tradeVolume['result']['fees'][$pair]['fee']);
          $info['min_order_size_btc'] = pow(10,-1*$product['pair_decimals']);//$this->minimumAltTrade('BTC');??
          $info['alt_price_decimals'] = $product['pair_decimals'];
          //var_dump($product);
          break;
        }
      return $info;
    }

    function place_order($type, $alt, $side, $price, $size)
    {
      $type = 'market';
      $pair = $this->getPair($alt);
      // safety check
      if($side == 'buy')
        $size = min($size , $this->balances['BTC']/$price);
      else
        $size = min($size , $this->balances[$alt]);

      $size_str = rtrim(rtrim(sprintf("%.6F", floordec($size,6)), '0'), ".");

      $order = ['pair' => $pair,
                'type' => $side,
                'ordertype' => $type,
                'volume' => $size_str,
                'expiretm' => '+20' //todo: compute working expire time...(unix timestamp)
               ];
      if($type == 'limit')
      {
        $alt_price_decimals = $this->product[$alt]->alt_price_decimals;
        $precision = pow(10,-1*$alt_price_decimals);
        $price = $side == 'buy' ? ceiling($price,$precision) : floordec($price,$alt_price_decimals);
        $price_str = rtrim(rtrim(sprintf("%.{$alt_price_decimals}F", $price), '0'), ".");
        $order['price'] = $price_str;
      }
      var_dump($order);
      $ret = $this->jsonRequest('AddOrder', $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);
       if(count($ret['error']))
         throw new KrakenAPIException($ret['error'][0]);
       else {
         $filled_size = $size; //todo !!
         $id = $ret['result']['txid'][0];
         $this->save_trade($id, $alt, $side, $size, $price);
       }
       return ['filled_size' => $filled_size, 'id' => $id];
    }

    function minimumAltTrade($alt)
    {
      $table = ['REP'=>0.3,
                'BTC'=>0.002,
                'BCH'=>0.002,
                'DASH'=>0.03,
                'DOGE'=>3000,
                'EOS'=>3,
                'ETH'=>0.02,
                'ETC'=>0.3,
                'ICN'=>2,
                'LTC'=>0.1,
                'MLN'=>0.1,
                'XMR'=>0.1,
                'XRP'=>30,
                'XLM'=>300,
                'ZEC'=>0.03,
                'GNO'=>0.03
              ];
      return $table[$alt];
    }

    static function getPair($alt)
    {
      $table = ['XRP' => 'XXRPXXBT',
                'LTC' => 'XLTCXXBT',
                'XLM' => 'XXLMXXBT',
                'ETH' => 'XETHXXBT',
                'ETC' => 'XETCXXBT',
                'REP' => 'XREPXXBT',
                'ZEC' => 'XZECXXBT',
                'XMR' => 'XXMRXXBT',
                'EOS' => 'EOSXBT',
                'BCH' => 'BCHXBT',
                'DASH' => 'DASHXBT',
                'GNO' => 'GNOXBT',
                'ICN' => 'XICNXXBT',
                'BTC' => null
                ];
      return $table[$alt];
    }

    function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
    {
//      $crypto = $this->crypto2kraken($alt);
      //$id = 'GNOXBT';
      $id = $this->getPair($alt);
      $ordercount = 25;
      $book = $this->jsonRequest('Depth',['pair' => $id, 'count' => $ordercount]);

      if(count($book['error']))
        throw new KrakenAPIException($book['error'][0]);

      $book = $book['result'][$id];

      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][1]);
        $i=1;
        while( (($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
                || ($best[$side]['size'] < $depth_alt) )
                && $i < $ordercount)
        {
          if (!isset($book[$side][$i][0], $book[$side][$i][1]))
            break;
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book[$side][$i][0]*$book[$side][$i][1]) / ($book[$side][$i][1]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book[$side][$i][1]);
          $best[$side]['order_price'] = floatval($book[$side][$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }

    function getOrderStatus($alt = null, $order_id)
    {
      $open_orders = $this->jsonRequest('OpenOrders');
      if (isset($open_orders['result']))
        $open_orders = $open_orders['result']['open'];

      if(count($open_orders))
      {
          var_dump($open_orders);
        foreach ($open_orders as $id => $open_order)
          if($id == $order_id)
          {
              $status = 'open';
              $filled = $open_order['vol_exec'];
              $filled_btc = $open_order['cost'];
             break;
          }
      }
      if( !isset($status) )
      { //todo get trade history to know if its fillet or canceled
        $status = 'closed';
        $filled = null;
        $filled_btc = null;
      }
      return  $status = [ 'status' => $status,
                          'filled' => $filled,
                          'filled_btc' => $filled_btc
                        ];
   }
}
