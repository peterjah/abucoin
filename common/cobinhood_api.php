<?php

class CobinhoodAPIException extends ErrorException {};

class CobinhoodApi
{
    const API_URL = 'https://api.cobinhood.com/v1';
    const WSS_URL = 'wss://feed.cobinhood.com/ws';

    protected $api_key;
    protected $curl;
    protected $account_id;
    public $nApicalls;
    public $name;
    public $products;
    public $balances;

    protected $side_translate = ['sell' => 'ask', 'buy' => 'bid'];


    protected $default_curl_opt = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERAGENT      => "Mozilla/4.0 (compatible; PHP Cobinhood API)",
      CURLOPT_ENCODING       => "",
      CURLOPT_HEADER         => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 5,
      CURLOPT_AUTOREFERER    => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT        => 5
    ];

    public function __construct()
    {
        $keys = json_decode(file_get_contents("../common/private.keys"));
        $this->api_key = $keys->cobinhood->api_key;
        $this->nApicalls = 0;
        $this->name = 'Cobinhood';
        $this->PriorityLevel = 10;

        //App specifics
        $this->products = [];
        $this->balances = [];

    }

    public function jsonRequest($method = null, $path, $datas = null, $params = false)
    {
        $opt = $this->default_curl_opt;
        if($this->nApicalls < PHP_INT_MAX)
          $this->nApicalls++;
        else
          $this->nApicalls = 0;

        if ($method !== null)
      			$opt[CURLOPT_CUSTOMREQUEST] = $method;
      	if ($params)
      			$opt[CURLOPT_POSTFIELDS] = json_encode($params);
        if ($datas)
          $datas = "?".http_build_query($datas);

        $nonce = intval(round(microtime(true)*1000));
        $opt[CURLOPT_HTTPHEADER] = [
          "authorization: {$this->api_key}",
          "nonce: {$nonce}"
        ];

        $ch = curl_init(self::API_URL . $path.$datas);
        curl_setopt_array($ch, $opt);
        $content = curl_exec($ch);
    		$errno   = curl_errno($ch);
    		$errmsg  = curl_error($ch);
    		//$header  = curl_getinfo($ch);
    		curl_close($ch);
    		if ($errno !== 0)
    			return ["error" => $errmsg];
    		$content = json_decode($content, true);

        if (null === $content && json_last_error() !== JSON_ERROR_NONE) {
          return ["error" => json_last_error_msg()];
    		} else if (false === $content["success"]) {
    			return ["error" => $content["error"]["error_code"]];
    		}
    		return $content;
    }

    function getBalance(...$cryptos)
    {
      $res = [];
      $balances = $this->jsonRequest('GET', "/wallet/balances")['result']['balances'];

      foreach($balances as $bal)
      {
        $this->balances[self::crypto2Cobinhood($bal['currency'], true)] = floatval($bal['total'] - $bal['on_order']);
      }
      foreach($cryptos as $crypto)
      {
        $res[$crypto] = isset($this->balances[$crypto]) ? $this->balances[$crypto] : 0;
      }
      if(count($res) == 1)
       return array_pop($res);
      else return $res;
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET','/market/trading_pairs');

      foreach($products['result']['trading_pairs'] as $product)
      if($product['quote_currency_id'] == 'BTC')
      {
        $list[] = self::crypto2Cobinhood($product['base_currency_id'], true);
      }
      return $list;
    }

    function getOrderBook($alt, $depth_btc = 0, $depth_alt = 0)
    {
      $id = self::crypto2Cobinhood($alt) . '-BTC';
      $limit = ['limit' => 50];
      $book = $this->jsonRequest('GET', "/market/orderbooks/$id", $limit)['result']['orderbook'];

      if(!isset($book['asks'][0][0], $book['bids'][0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][2]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_btc)
              || ($best[$side]['size'] < $depth_alt) )
              && $i<50/*max offers for level=2*/)
        {
          if (!isset($book[$side][$i][0], $book[$side][$i][2]))
            break;
          $best[$side]['price'] = floatval(($best[$side]['price']*$best[$side]['size'] + $book[$side][$i][0]*$book[$side][$i][2]) / ($book[$side][$i][2]+$best[$side]['size']));
          $best[$side]['size'] += floatval($book[$side][$i][2]);
          $best[$side]['order_price'] = floatval($book[$side][$i][0]);
          //print "best price price={$best[$side]['price']} size={$best[$side]['size']}\n";
          $i++;
        }
      }
      return $best;
    }

    function getProductInfo($alt)
    {
      $id = self::crypto2Cobinhood($alt) . '-BTC';
      $product = null;
      $i=0;
      while( ($products = self::jsonRequest('GET', "/market/trading_pairs", null)) == null && $i<5)
      {
        $i++;
        sleep(1);
        continue;
      }
      foreach ($products['result']['trading_pairs'] as $pair)
        if($pair['id'] == $id)
          $product = $pair;

      if($product == null)
        throw new CobinhoodAPIException('failed to get product infos');
      $info['min_order_size_alt'] = $product['base_min_size'];
      $info['increment'] = $product['quote_increment'];
      $info['fees'] = 0;
      $info['min_order_size_btc'] = 0;
      return $info;
    }

    function place_order($type, $alt, $side, $price, $size, $tradeId)
    {
      $bidask = $this->side_translate[$side];
      $type = 'market';

      $order = ['trading_pair_id' => "$alt-BTC",
                'size'=>  strval($size),
                'side'=> $bidask,
                'type'=> $type,
                ];

      if($type == 'limit')
      {
        $order['price'] = strval($price);
      }

      var_dump($order);

      $ret = $this->jsonRequest('POST', '/trading/orders',null, $order);
      print "{$this->name} trade says:\n";
      var_dump($ret);

      if(isset($ret['result']))
      {
        $status = $ret['result']['order'];
        $filled_size = $filled_btc = 0;
//        if(($status['state'] == 'filled') || ($status['state'] == 'partially_filled')/*nogood for limit order*/)
        if($ret['success'] && $status['state'] != 'rejected')
        {
          print_dbg("Cobinhood trade state: {$status['state']}");
          if($status['state'] == 'filled') {
            $filled_size = $size;
            $filled_btc = $filled_size * floatval($status['price']);
          }
          else {
            sleep(3);
            $status2 = $this->getOrderStatus($alt, $status['id']);
            if(empty($status2)) {
              //check closed orders

              $trades = $this->getOrdersHistory(['alt' => $alt, 'since' => 15]);
              print_dbg("checking history: {$trades[0]['status']}");
              if($trades[0]['status'] != 'rejected') {
                $filled_size += $trades[0]['filled'];
                $filled_btc += $trades[0]['filled_btc'];
              }

            }
            else {
              if($this->cancelOrder('notUsed',$status['id'])) {
                $filled_size = $status2['filled'];
                $filled_btc = $status2['filled_btc'];
              } else {
                  $filled_size = $size;
                  $filled_btc = $filled_size * floatval($status['price']);
                }
            }
            print_dbg("order status state: {$status2['status']} filled_size = $filled_size");
          }
          if($status['state'] != 'open')
            $this->save_trade($status['id'], $alt, $side, $size, $price, $tradeId);
          return ['filled_size' => $size, 'id' => $status['id'], 'filled_btc' => null, 'price' => $price];
        }
        else
          return ['filled_size' => 0, 'id' => null, 'filled_btc' => null, 'price' => $price];
      }
      else {
        throw new CobinhoodAPIException("{$ret['error']}");
      }
    }

    function save_trade($id, $alt, $side, $size, $price, $tradeId)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size $alt at $price\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function ping()
    {
      $ping = $this->jsonRequest('GET', '/system/time');
      return $ping['success'] === true ? true : false;
    }

    function getOrderStatus($alt, $orderId, $closed = false)
    {
      print "get order status of $orderId \n";
      $i=0;
      $symbol = "{$alt}-BTC";
      while($i<5)
      {
        try{
          if($closed)
            $orders = $this->jsonRequest('GET', '/trading/order_history', ["trading_pair_id" => $symbol]);
          else
            $orders = $this->jsonRequest('GET', '/trading/orders', ["trading_pair_id" => $symbol]);
          break;
        }catch (Exception $e){ $i++; usleep(500000); print_dbg("{$this->name}: Failed to get orders [{$e->getMessage}] retrying...$i");}
      }

      //var_dump($open_orders);
      foreach ($orders['result']['orders'] as $open_order)
        if($orders['id'] == $orderId)
        {
           $order = $open_order;
           var_dump($order);
           break;
        }
      if(isset($order))
      {

          print_dbg("check $alt order status: {$order['state']}");
          if ($order['state'] == 'filled')
            $status = 'closed';
          elseif ($order['state'] == 'rejected')
            $status = 'rejected';
          else
            $status = 'open';

          $side_translate = array_flip($this->side_translate);
          return  $status = [ 'id' => $orderId,
                              'side' => $side_translate[$order['side']],
                              'status' => $status,
                              'filled' => floatval($order['filled']),
                              'filled_btc' => floatval($order['filled']) * $order['price'],
                              'price' => $order['price']
                            ];
        }

        print_dbg("{$this->name}: Unable to find open order $orderId It may be filled or rejected...");
        return null;
    }

    function getOrdersHistory($filter = null)
    {
      $params = [];
      $params['page'] = 1;
      $params['limit'] = 12;

      if(isset($filter['alt']))
        $params['trading_pair_id'] = "{$filter['alt']}-BTC";

      $i=0;
      while($i<8)
      {
        try{
          $trades = $this->jsonRequest('GET', '/trading/order_history', $params);
          break;
        }catch (Exception $e){ $i++; usleep(500000); print_dbg("Failed to getOrdersHistory. [{$e->getMessage()}]..$i");}
      }
      if($trades['success']) {
        $status = [];
        var_dump($trades['result']['orders']);
        $trades = $trades['result']['orders'];
        foreach($trades as $idx => $order)
        {
          if( !isset($filter['since']) || ((time() - strtotime($order['completed_at'])) < $filter['since']) ) {
            $side_translate = array_flip($this->side_translate);
            $status[] = [ 'id' => $order['id'],
                        'side' => $side_translate[$order['side']],
                        'status' => $order['state'],
                        'filled' => floatval($order['filled']),
                        'filled_btc' => floatval($order['filled']) * $order['eq_price'],
                        'price' => $order['eq_price']
                      ];

          }
        }
        print_dbg("{$this->name} getOrdersHistory: " . count($status) . " trades found in history");
        return $status;
      }
    }

    function cancelOrder($alt, $orderId)
    {
      print_dbg("canceling order $orderId");
      $i=0;
      while($i<10)
      {
        try{
          $ret = $this->jsonRequest('DELETE', '/trading/orders',["order_id" => $orderId]);
          break;
        }catch (Exception $e){ $i++; sleep(1); print_dbg("Failed to cancel order. [{$e->getMessage}] retrying...$i");}
      }
      var_dump($ret);
      if(isset($ret['error']))
        return false;
      return true;

    }

    static function crypto2Cobinhood($crypto, $reverse = false)
    {
      $table = ['BSV' => 'BCHSV',
                ];
      if($reverse)
        $table = array_flip($table);
      if(array_key_exists($crypto,$table))
        return $table[$crypto];
      else {
        return $crypto;
      }
    }
}
