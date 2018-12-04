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

    function getBalance($alt = null)
    {
      $res = [];
      $i=0;
      while ( true ) {
        try {
          $balances = $this->jsonRequest('GET', "/wallet/balances")['result']['balances'];
          break;
        }
        catch (Exception $e) {
          if($i > 8)
            throw new BinanceAPIException("failed to get balances [{$e->getMessage()}]");
          $i++;
          print "{$this->name}: failed to get balances. retry $i...\n";
          usleep(50000);
        }
      }

      foreach($balances as $bal) {
        $alt = self::cobinhood2crypto($bal['currency']);
        $res[$alt] = $this->balances[$alt] = floatval($bal['total'] - $bal['on_order']);
      }

      if($alt != null)
       return $res[$alt];
      else return $res;
    }

    function getProductList()
    {
      $list = [];
      $products = $this->jsonRequest('GET','/market/trading_pairs')['result'];
      //var_dump($products);
      foreach($products['trading_pairs'] as $product) {
        $alt = self::cobinhood2crypto($product['base_currency_id']);
        $base = self::cobinhood2crypto($product['quote_currency_id']);
        $params = [ 'api' => $this,
                    'alt' => $alt,
                    'base' => $base,
                    'fees' => $product['maker_fee'],
                    'price_decimals' => strlen(substr(strrchr(rtrim($product['quote_increment'],0), "."), 1)),
                    'min_order_size' => floatval($product['base_min_size']),
                    'lot_size_step' => floatval($product['quote_increment']),
                    'min_order_size_base' => 0,
                    'size_decimals' => 8,
                  ];
        $product = new Product($params);
        $list[$product->symbol] = $product;

        if (!isset($this->balances[$alt]))
          $this->balances[$alt] = 0;
        if (!isset($this->balances[$base]))
          $this->balances[$base] = 0;
      }
      $this->products = $list;
      return $list;
    }

    function getOrderBook($product, $depth_alt = 0, $depth_base = 0)
    {
      $symbol = self::crypto2Cobinhood($product->alt) ."-". self::crypto2Cobinhood($product->base);
      $limit = ['limit' => 50];
      $book = $this->jsonRequest('GET', "/market/orderbooks/{$symbol}", $limit)['result']['orderbook'];

      if(!isset($book['asks'][0][0], $book['bids'][0][0]))
        return null;
      foreach( ['asks', 'bids'] as $side)
      {
        $best[$side]['price'] = $best[$side]['order_price'] = floatval($book[$side][0][0]);
        $best[$side]['size'] = floatval($book[$side][0][2]);
        $i=1;
        while( ( ($best[$side]['size'] * $best[$side]['price'] < $depth_base)
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

    function place_order($product, $type, $side, $price, $size, $tradeId)
    {
      $alt = $product->alt;
      $base = $product->base;
      $bidask = $this->side_translate[$side];
      $type = 'market';

      $order = ['trading_pair_id' => self::crypto2Cobinhood($alt) ."-". self::crypto2Cobinhood($base),
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
        $filled_size = $filled_base = 0;
        if($ret['success'] && $status['state'] != 'rejected')
        {
          print_dbg("Cobinhood trade state: {$status['state']}");
          if($status['state'] == 'filled') {
            $filled_size = $size;
            $filled_base = $filled_size * floatval($status['price']);
          }
          else {
            sleep(3);
            $status2 = $this->getOrderStatus($product, $status['id']);
            if(empty($status2)) {
              //check closed orders
              $status2 = $this->getOrdersHistory(['alt' => $alt, 'base' => $base, 'since' => 15]);
              print_dbg("checking history: {$trades[0]['status']}");
              if($status2[0]['status'] != 'rejected') {
                $filled_size += $trades[0]['filled'];
                $filled_base += $trades[0]['filled_base'];
              }
              else {
                throw new CobinhoodAPIException("Order rejected");
              }

            }
            else {
              if($this->cancelOrder($product, $status['id'])) {
                $filled_size = $status2['filled'];
                $filled_base = $status2['filled_base'];
              } else {
                  $filled_size = $size;
                  $filled_base = $filled_size * floatval($status['price']);
                }
            }
            print_dbg("order status state: {$status2['status']} filled_size = $filled_size");
          }
          if($status['state'] != 'open' && $status2[0]['status'] != 'rejected')
            $this->save_trade($status['id'], $product, $side, $size, $price, $tradeId);
          return ['filled_size' => $size, 'id' => $status['id'], 'filled_base' => null, 'price' => $price];
        }
        else
          return ['filled_size' => 0, 'id' => null, 'filled_base' => null, 'price' => $price];
      }
      else {
        throw new CobinhoodAPIException("{$ret['error']}");
      }
    }

    function save_trade($id, $product, $side, $size, $price, $tradeId)
    {
      print("saving trade\n");
      $trade_str = date("Y-m-d H:i:s").": arbitrage: $tradeId {$this->name}: trade $id: $side $size {$product->alt} at $price {$product->base}\n";
      file_put_contents('trades',$trade_str,FILE_APPEND);
    }

    function ping()
    {
      $ping = $this->jsonRequest('GET', '/system/time');
      return $ping['success'] === true ? true : false;
    }

    function getOrderStatus($product, $orderId, $closed = false)
    {
      print "get order status of $orderId \n";
      $alt = self::crypto2cobinhood($product->alt);
      $base = self::crypto2cobinhood($product->base);
      $i=0;
      $symbol = "{$alt}-{$base}";
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
                              'filled_base' => floatval($order['filled']) * $order['price'],
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
      {
        $params['trading_pair_id'] = self::crypto2cobinhood($filter['alt'])."-".self::crypto2cobinhood($filter['base']);
      }

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
        $trades = $trades['result']['orders'];
        foreach($trades as $idx => $order)
        {
          if( !isset($filter['since']) || ((time() - strtotime($order['completed_at'])) < $filter['since']) ) {
            $side_translate = array_flip($this->side_translate);
            $status[] = [ 'id' => $order['id'],
                        'side' => $side_translate[$order['side']],
                        'status' => $order['state'],
                        'filled' => floatval($order['filled']),
                        'filled_base' => floatval($order['filled']) * $order['eq_price'],
                        'price' => $order['eq_price']
                      ];

          }
        }
        print_dbg("{$this->name} getOrdersHistory: " . count($status) . " trades found in history");
        return $status;
      }
    }

    function cancelOrder($product, $orderId)
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

    static function cobinhood2crypto($crypto)
    {
      return self::crypto2Cobinhood($crypto, true);
    }

}
