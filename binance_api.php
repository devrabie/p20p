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

    public function getP2POrders($rows = 10, $startTimestamp = null, $endTimestamp = null) {
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
        if ($endTimestamp) {
            $params['endTimestamp'] = $endTimestamp;
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

    public function getPayTransactions($rows = 10, $startTimestamp = null, $endTimestamp = null) {
        $endpoint = "/sapi/v1/pay/transactions";
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        $params = [
            'timestamp' => $timestamp,
            'recvWindow' => 5000
        ];

        if ($startTimestamp) {
            $params['startTime'] = $startTimestamp;
        }
        if ($endTimestamp) {
            $params['endTime'] = $endTimestamp;
        }

        $queryString = http_build_query($params);
        $signature = $this->generateSignature($queryString);
        $url = $this->baseUrl . $endpoint . '?' . $queryString . '&signature=' . $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: ' . $this->apiKey]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['data'] ?? [];
    }

    public function getWithdrawHistory($rows = 10, $startTimestamp = null, $endTimestamp = null) {
        $endpoint = "/sapi/v1/capital/withdraw/history";
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        $params = [
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
            'coin' => 'USDT'
        ];

        if ($startTimestamp) {
            $params['startTime'] = $startTimestamp;
        }
        if ($endTimestamp) {
            $params['endTime'] = $endTimestamp;
        }

        $queryString = http_build_query($params);
        $signature = $this->generateSignature($queryString);
        $url = $this->baseUrl . $endpoint . '?' . $queryString . '&signature=' . $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: ' . $this->apiKey]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data ?? [];
    }

    public function getDepositHistory($rows = 10, $startTimestamp = null, $endTimestamp = null) {
        $endpoint = "/sapi/v1/capital/deposit/hisrec";
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        $params = [
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
            'coin' => 'USDT'
        ];

        if ($startTimestamp) {
            $params['startTime'] = $startTimestamp;
        }
        if ($endTimestamp) {
            $params['endTime'] = $endTimestamp;
        }

        $queryString = http_build_query($params);
        $signature = $this->generateSignature($queryString);
        $url = $this->baseUrl . $endpoint . '?' . $queryString . '&signature=' . $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: ' . $this->apiKey]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data ?? [];
    }

    public function getUSDTBalance() {
        $endpoint = "/sapi/v1/asset/getUserAsset";
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        $params = [
            'timestamp' => $timestamp,
            'recvWindow' => 5000,
            'asset' => 'USDT'
        ];

        $queryString = http_build_query($params);
        $signature = $this->generateSignature($queryString);
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString . '&signature=' . $signature);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-MBX-APIKEY: ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (is_array($data)) {
            foreach ($data as $asset) {
                if (isset($asset['asset']) && $asset['asset'] === 'USDT') {
                    return floatval($asset['free']) + floatval($asset['locked']);
                }
            }
        }
        return 0;
    }
}
?>
