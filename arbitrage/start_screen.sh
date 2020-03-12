#!/bin/sh
cd $PWD
screen -S binance_kraken -dm  php arbitrage.php binance kraken

screen -S trade_cleaner -dm php clean_failed_trades.php -auto-solve
