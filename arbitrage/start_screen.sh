#!/bin/sh
cd $PWD
screen -S cobinhood_kraken -dm  php arbitrage.php cobinhood kraken
sleep 0.1
screen -S cobinhood_binance -dm  php arbitrage.php cobinhood binance
sleep 0.1
screen -S cryptopia_kraken -dm  php arbitrage.php cryptopia kraken
sleep 0.1
screen -S binance_kraken -dm  php arbitrage.php binance kraken
sleep 0.3
screen -S binance_cryptopia -dm  php arbitrage.php binance cryptopia
sleep 0.5
screen -S cobinhood_cryptopia -dm  php arbitrage.php cobinhood cryptopia
sleep 0.5
screen -S paymium_kraken -dm  php arbitrage.php paymium kraken

screen -S trade_cleaner -dm php clean_failed_trades.php -auto-solve
