<?php
class AbucoinsApi
{
    const API_URL = 'https://api.abucoins.com';

    protected $accesskey;
    protected $secret;
    protected $passphrase;
    protected $timestamp;

    public function __construct($settings)
    {
        $this->secret = $settings['secret'];
        $this->accesskey = $settings['access_key'];
        $this->passphrase = $settings['passphrase'];
        $this->timestamp = time();
    }

    public function jsonRequest($method, $path, $datas)
    {
        $this->timestamp = time();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_URL, static::API_URL . "$path");
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datas));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'AC-ACCESS-KEY: ' . $this->accesskey,
            'AC-ACCESS-TIMESTAMP: ' . $this->timestamp,
            'AC-ACCESS-PASSPHRASE: ' . $this->passphrase,
            'AC-ACCESS-SIGN: ' . $this->signature($path, $datas, $this->timestamp, $method),
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        curl_close($ch);
        return json_decode($server_output);
    }

    public function signature($request_path = '', $body = '', $timestamp = false, $method = 'GET')
    {
        $body = is_array($body) ? json_encode($body) : $body;
        $timestamp = $timestamp ? $timestamp : time();
        $what = $timestamp . $method . $request_path . $body;
        return base64_encode(hash_hmac("sha256", $what, base64_decode($this->secret), true));
    }

}
