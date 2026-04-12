<?php
session_start();
require_once 'db.php';
require_once 'binance_api.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired']);
    exit();
}

$user_id = $_SESSION['user_id'];

// جلب المفاتيح من قاعدة البيانات
$stmt = $pdo->prepare("SELECT binance_api_key, binance_api_secret, binance_fetch_limit FROM settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$settings = $stmt->fetch();

if (!$settings || empty($settings['binance_api_key']) || empty($settings['binance_api_secret'])) {
    echo json_encode(['status' => 'error', 'message' => 'يرجى إدخال مفاتيح API في الإعدادات أولاً']);
    exit();
}

// فك تشفير المفتاح السري
$encryption_key = 'ledger_pro_secure_key_' . $user_id;
$decrypted_secret = openssl_decrypt($settings['binance_api_secret'], 'AES-128-ECB', $encryption_key);

if (!$decrypted_secret) {
    echo json_encode(['status' => 'error', 'message' => 'خطأ في فك تشفير المفتاح السري']);
    exit();
}

try {
    $binance = new BinanceP2P($settings['binance_api_key'], $decrypted_secret);

    $startTimestamp = null;
    if (!empty($_GET['start_date'])) {
        $startTimestamp = strtotime($_GET['start_date'] . ' 00:00:00') * 1000;
    }

    $fetch_limit = $settings['binance_fetch_limit'] ?: 10;
    $orders = $binance->getP2POrders($fetch_limit, $startTimestamp);
    $pay_txs = $binance->getPayTransactions($fetch_limit, $startTimestamp);
    $withdrawals = $binance->getWithdrawHistory($fetch_limit, $startTimestamp);
    $deposits = $binance->getDepositHistory($fetch_limit, $startTimestamp);

    // جلب أرقام العمليات المضافة مسبقاً
    $imported_stmt = $pdo->prepare("SELECT binance_order_id FROM transactions WHERE user_id = ? AND binance_order_id IS NOT NULL");
    $imported_stmt->execute([$user_id]);
    $imported_ids = $imported_stmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedOrders = [];

    // 1. معالجة عمليات P2P
    foreach ($orders as $order) {
        $formattedOrders[] = [
            'source' => 'P2P',
            'orderNumber' => $order['orderNumber'],
            'side' => $order['tradeType'], // BUY or SELL
            'amount' => $order['amount'],
            'unitPrice' => $order['unitPrice'],
            'totalPrice' => $order['totalPrice'],
            'fiat' => $order['fiat'],
            'createTime' => date('Y-m-d H:i:s', $order['createTime'] / 1000),
            'asset' => $order['asset'],
            'status' => $order['status'],
            'is_imported' => in_array($order['orderNumber'], $imported_ids)
        ];
    }

    // 2. معالجة عمليات Binance Pay (USDT فقط)
    foreach ($pay_txs as $tx) {
        if ($tx['currency'] === 'USDT') {
            $type = $tx['type']; // RECEIVE, SEND, etc.
            $formattedOrders[] = [
                'source' => 'PAY',
                'orderNumber' => $tx['orderId'],
                'side' => ($type === 'RECEIVE' || $type === 'TRANSFER_IN') ? 'BUY' : 'SELL',
                'amount' => $tx['amount'],
                'unitPrice' => 0, // Pay doesn't have exchange rate
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'createTime' => date('Y-m-d H:i:s', $tx['transactionTime'] / 1000),
                'asset' => 'USDT',
                'status' => 'COMPLETED',
                'note' => $tx['productName'] ?? $type,
                'is_imported' => in_array($tx['orderId'], $imported_ids)
            ];
        }
    }

    // 3. معالجة عمليات الإيداع (Deposits)
    foreach ($deposits as $dp) {
        if ($dp['coin'] === 'USDT') {
            $formattedOrders[] = [
                'source' => 'DEPOSIT',
                'orderNumber' => $dp['txId'] ?: $dp['id'],
                'side' => 'BUY', // Deposit is always an incoming operation
                'amount' => $dp['amount'],
                'unitPrice' => 0,
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'binance_fee' => 0,
                'createTime' => date('Y-m-d H:i:s', $dp['insertTime'] / 1000),
                'asset' => 'USDT',
                'status' => ($dp['status'] == 1) ? 'COMPLETED' : 'PENDING',
                'is_imported' => in_array($dp['txId'] ?: $dp['id'], $imported_ids)
            ];
        }
    }

    // 4. معالجة عمليات السحب (Withdrawals)
    foreach ($withdrawals as $wd) {
        if ($wd['coin'] === 'USDT') {
            $formattedOrders[] = [
                'source' => 'WITHDRAW',
                'orderNumber' => $wd['id'],
                'side' => 'SELL', // Withdrawal is always an outgoing operation
                'amount' => $wd['amount'],
                'unitPrice' => 0,
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'createTime' => date('Y-m-d H:i:s', strtotime($wd['applyTime'])),
                'asset' => 'USDT',
                'status' => ($wd['status'] == 6) ? 'COMPLETED' : 'PENDING',
                'is_imported' => in_array($wd['id'], $imported_ids)
            ];
        }
    }

    // ترتيب الكل: الأحدث أولاً
    usort($formattedOrders, function($a, $b) {
        return strtotime($b['createTime']) - strtotime($a['createTime']);
    });

    echo json_encode(['status' => 'success', 'orders' => $formattedOrders]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطأ Binance: ' . $e->getMessage()]);
}
?>
