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
        $timestamp = number_format(microtime(true) * 1000, 0, '.', '');

        // 1. Spot Balance (getUserAsset)
        $spot_endpoint = "/sapi/v1/asset/getUserAsset";
        $spot_params = ['timestamp' => $timestamp, 'recvWindow' => 5000, 'asset' => 'USDT'];
        $spot_qs = http_build_query($spot_params);
        $spot_sig = $this->generateSignature($spot_qs);

        $ch = curl_init($this->baseUrl . $spot_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $spot_qs . '&signature=' . $spot_sig);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: ' . $this->apiKey]);
        $spot_res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        // 2. Funding Balance (get-funding-asset)
        $funding_endpoint = "/sapi/v1/asset/get-funding-asset";
        $funding_params = ['timestamp' => $timestamp, 'recvWindow' => 5000, 'asset' => 'USDT'];
        $funding_qs = http_build_query($funding_params);
        $funding_sig = $this->generateSignature($funding_qs);

        $ch = curl_init($this->baseUrl . $funding_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $funding_qs . '&signature=' . $funding_sig);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: ' . $this->apiKey]);
        $funding_res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $total = 0;
        if (is_array($spot_res)) {
            foreach ($spot_res as $a) if (($a['asset'] ?? '') === 'USDT') $total += floatval($a['free']) + floatval($a['locked']);
        }
        if (is_array($funding_res)) {
            foreach ($funding_res as $a) if (($a['asset'] ?? '') === 'USDT') $total += floatval($a['free']) + floatval($a['freeze']) + floatval($a['withdrawing']);
        }

        return [
            'total' => $total,
            'spot' => $spot_res,
            'funding' => $funding_res
        ];
    }
}
?>
