<?php
require_once('../common/tools.php');

//init api
$markets = [];
$products = [];
foreach( ['binance','kraken','cobinhood','cryptopia'] as $name) {
  $i=0;
  while($i<6) {
    try{
      $markets[$name] = new Market($name);
      $markets[$name]->api->getBalance();
      foreach($markets[$name]->products as $product)
        $products[] = $product;
      break;
    } catch(Exception $e) {
      print "failed to get market $name: $e \n";
      usleep(500000);
      $i++;
    }
  }
}
  //iterate over all products
foreach ($products as $product1) {
  foreach ($products as $product2) {
    if ($product1 == $product2)
      continue;
    //case buy product 1
    if ($product1->alt == $product2->base){
      if ($product1->base == $product2->alt)
        continue;
      // ETH-BTC REP-ETH REP-BTC
      // BTC->ETH ETH->REP REP->BTC
      $quote = $product1->base;
      $swap1 = $product1->alt;
      $swap2 = $product2->alt;
      $action1 = 'buy';
      $action2 = 'buy';
      $symbol3 = "{$swap2}-{$quote}";
      $action3 = 'sell';
      foreach ($products as $product3) {
        if ($product3->symbol == $symbol3) {
          $book1 = $product1->refreshBook()['bids'];
          $sizequote = $product1->api->balances[$quote];
          $sizeswap1 = ($sizequote / $book1['order_price']) * (1 - $product1->fees/100);
          $book2 = $product2->refreshBook()['bids'];
          $sizeswap2 = ($sizeswap1 / $book2['order_price']) * (1 - $product2->fees/100);

          $book3 = $product3->refreshBook()['asks'];
          $sizequotefinal = $sizeswap2 * $book3['order_price'] * (1 - $product3->fees/100);
          if ($sizequotefinal > $sizequote) {
            print "$quote->$swap1 ({$product1->api->name}) "
                  ."$swap1->$swap2 ({$product2->api->name}) "
                  ."$swap2->$quote ({$product3->api->name})\n";
            $gain = $sizequotefinal - $sizequote;
            $gainpercent = number_format(($gain / $sizequote)*100, 2);;
            print "YAAAHOUUUU sizequote: $sizequote $quote gains: ".$gain." $quote $gainpercent%\n";
          }
        }
      }
    } elseif ($product1->alt == $product2->alt) {
      if ($product1->base == $product2->base)
        continue;
      // ETH-BTC ETH-REP BTC-REP
      // BTC->ETH ETH->REP REP->BTC
      $quote = $product1->base;
      $swap1 = $product1->alt;
      $swap2 = $product2->alt;
      $action1 = 'buy';
      $action2 = 'sell';
      $symbol3 = "{$quote}-{$swap2}";
      $action3 = 'buy';
      foreach ($products as $product3) {
        if ($product3->symbol == $symbol3) {
          $book1 = $product1->refreshBook()['bids'];
          $sizequote = $product1->api->balances[$quote];
          $sizeswap1 = ($sizequote / $book1['order_price']) * (1 - $product1->fees/100);
          $book2 = $product2->refreshBook()['asks'];
          $sizeswap2 = $sizeswap1 * $book2['order_price'] * (1 - $product2->fees/100);

          $book3 = $product3->refreshBook()['bids'];
          $sizequotefinal = ($sizeswap2 / $book3['order_price']) * (1 - $product3->fees/100);
          if ($sizequotefinal > $sizequote) {
            print "$quote->$swap1 ({$product1->api->name}) "
                  ."$swap1->$swap2 ({$product2->api->name}) "
                  ."$swap2->$quote ({$product3->api->name})\n";
            $gain = $sizequotefinal - $sizequote;
            $gainpercent = number_format(($gain / $sizequote)*100, 2);;
            print "YAAAHOUUUU sizequote: $sizequote $quote gains: ".$gain." $quote $gainpercent%\n";
          }
        }
      }
    } elseif ($product1->base == $product2->base) {
      if ($product1->alt == $product2->alt)
        continue;
      // ETH-BTC REP-BTC REP-ETH
      // ETH->BTC BTC->REP REP->ETH
      $quote = $product1->alt;
      $swap1 = $product1->base;
      $swap2 = $product2->alt;
      $action1 = 'sell';
      $action2 = 'buy';
      $symbol3 = "{$swap2}-{$quote}";
      $action3 = 'sell';
      foreach ($products as $product3) {
        if ($product3->symbol == $symbol3) {
          $book1 = $product1->refreshBook()['asks'];
          $sizequote = $product1->api->balances[$quote];
          $sizeswap1 = $sizequote * $book1['order_price'] * (1 - $product1->fees/100);
          $book2 = $product2->refreshBook()['bids'];
          $sizeswap2 = ($sizeswap1 / $book2['order_price']) * (1 - $product2->fees/100);

          $book3 = $product3->refreshBook()['asks'];
          $sizequotefinal = $sizeswap2 * $book3['order_price'] * (1 - $product3->fees/100);
          if ($sizequotefinal > $sizequote) {
            print "$quote->$swap1 ({$product1->api->name}) "
                  ."$swap1->$swap2 ({$product2->api->name}) "
                  ."$swap2->$quote ({$product3->api->name})\n";
            $gain = $sizequotefinal - $sizequote;
            $gainpercent = number_format(($gain / $sizequote)*100, 2);;
            print "YAAAHOUUUU sizequote: $sizequote $quote gains: ".$gain." $quote $gainpercent%\n";
          }
        }
      }
    }  elseif ($product1->base == $product2->alt) {
      if ($product1->alt == $product2->base)
        continue;
      // ETH-BTC BTC-REP ETH-REP
      // ETH->BTC BTC->REP REP->ETH
      $quote = $product1->alt;
      $swap1 = $product1->base;
      $swap2 = $product2->alt;
      $action1 = 'sell';
      $action2 = 'sell';
      $symbol3 = "{$quote}-{$swap2}";
      $action3 = 'buy';
      foreach ($products as $product3) {
        if ($product3->symbol == $symbol3) {
          $book1 = $product1->refreshBook()['asks'];
          $sizequote = $product1->api->balances[$quote];
          $sizeswap1 = $sizequote * $book1['order_price'] * (1 - $product1->fees/100);
          $book2 = $product2->refreshBook()['asks'];
          $sizeswap2 = $sizeswap1 * $book2['order_price'] * (1 - $product2->fees/100);

          $book3 = $product3->refreshBook()['bids'];
          $sizequotefinal = ($sizeswap2 / $book3['order_price']) * (1 - $product3->fees/100);
          if ($sizequotefinal > $sizequote) {
            print "$quote->$swap1 ({$product1->api->name}) "
                  ."$swap1->$swap2 ({$product2->api->name}) "
                  ."$swap2->$quote ({$product3->api->name})\n";
            $gain = $sizequotefinal - $sizequote;
            $gainpercent = number_format(($gain / $sizequote)*100, 2);;
            print "YAAAHOUUUU sizequote: $sizequote $quote gains: ".$gain." $quote $gainpercent%\n";
          }
        }
      }
    } else continue;
  }
}
