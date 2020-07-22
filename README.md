# Arbitrage bot
installation:
sudo apt-get update && sudo apt-get install -y php7.0 php-bcmath php-curl composer

clone project
In the repository root directory:
composer create-project
composer require textalk/websocket

create a json file "private.keys" in common folder and fill it with your api credentials:
> {"cryptopia":
>    {"api_key":"XXX","secret":"YYY"},
>  "kraken":
>    {"api_key":"XXX","secret":"YYY"},
>  "cobinhood":
>    {"api_key":"XXX"},
>  "binance" :
>    {"api_key":"XXX","secret":"YYY"},
>  "paymium" :
>    {"api_key":"XXX","secret":"YYY"}
> }

Test your setup by getting your portfolio:
php arbitrage/balances.php


todo:
Use a database to store transactions 
