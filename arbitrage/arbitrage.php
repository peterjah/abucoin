<?php
require_once('../common/abucoin_api.php');
require_once('../common/cryptopia_api.php');

$keys = json_decode(file_get_contents("../common/private.keys"));
$abucoinsApi = new AbucoinsApi($keys->abucoins);
$CryptopiaApi = new CryptopiaApi($keys->cryptopia);

//var_dump($abucoinsApi->jsonRequest('GET', "/products", null));
//var_dump($CryptopiaApi->jsonRequest("GetBalance",['Currency'=> "BTC"]));
