<?php
class PaymiumApi
{
    const API_URL = 'https://paymium.com/api';

    protected $publicKey;
    protected $privateKey;

    public function __construct($settings)
    {
        $this->publicKey = $settings->publicKey;
        $this->privateKey = $settings->privateKey;
    }

    public function jsonRequest($method, $path, $datas)/*($method, array $req = array())*/
    {
        //      static $ch = null;
        $ch = curl_init();
        //      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //~ if ( in_array( $method ,$public_set ) ) {
           //~ curl_setopt($ch, CURLOPT_URL, static::API_URL . "$path");
        //~ } elseif ( in_array( $method, $private_set ) ) {
           //~ $nonce = explode(' ', microtime())[1];
           //~ $post_data = json_encode( $req );
           //~ $m = md5( $post_data, true );
           //~ $requestContentBase64String = base64_encode( $m );
           //~ $signature = $this->publicKey . "POST" . strtolower( urlencode(static::API_URL . "$path") ) . $nonce . $requestContentBase64String;
           //~ $hmacsignature = base64_encode( hash_hmac("sha256", $signature, base64_decode( $this->privateKey ), true ) );
           //~ $header_value = "amx " . $this->publicKey . ":" . $hmacsignature . ":" . $nonce;
           //~ $headers = array("Content-Type: application/json; charset=utf-8", "Authorization: $header_value");
           //~ curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
           //~ curl_setopt($ch, CURLOPT_URL, $url );
           //~ curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode( $req ) );
        //~ }
            //~ // run the query
        //~ curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        //~ curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE); // Do Not Cache
        //~ $res = curl_exec($ch);
        //~ if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
        //~ return $res;

        print static::API_URL . "$path\n";
        $nonce = time();
        $requestContentBase64String = base64_encode( md5( json_encode( $path ), true ) );
        $signature = $this->publicKey . "POST" . strtolower( urlencode(static::API_URL . "$path") ) . $nonce . $requestContentBase64String;
        $hmacsignature = base64_encode( hash_hmac("sha256", $signature, base64_decode( $this->privateKey ), true ) );
        $header_value = "amx " . $this->publicKey . ":" . $hmacsignature . ":" . $nonce;


 //       curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, static::API_URL . "$path");
        //~ if ($method == 'POST') {
            //~ curl_setopt($ch, CURLOPT_POST, 1);
            //~ curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datas));
        //~ }
        //~ curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            //~ 'Content-Type: application/json; charset=utf-8',
            //~ "Authorization: $header_value"
        //~ ));

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, TRUE); // Do Not Cache
        $server_output = curl_exec($ch);
        curl_close($ch);
        return json_decode($server_output);
    }
}
