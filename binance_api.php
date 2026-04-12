<?php
/**
 * Binance P2P API Integration Class
 */
class BinanceP2P {
    private $apiKey;
    private $apiSecret;
    private $baseUrl = "https://api.binance.com";

    public function __construct($apiKey, $apiSecret) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    private function generateSignature($queryString) {
        return hash_hmac('sha256', $queryString, $this->apiSecret);
    }

    public function getP2POrders($rows = 10, $startTimestamp = null) {
        $endpoint = "/sapi/v1/c2c/orderMatch/listUserOrderHistory";
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        $params = [
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
            'rows' => $rows
        ];

        if ($startTimestamp) {
            $params['startTimestamp'] = $startTimestamp;
        }

        $queryString = http_build_query($params);
        $signature = $this->generateSignature($queryString);
        $url = $this->baseUrl . $endpoint . '?' . $queryString . '&signature=' . $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-MBX-APIKEY: ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['code']) && $data['code'] < 0) {
            throw new Exception($data['msg']);
        }

        return $data['data'] ?? [];
    }
}
?>
