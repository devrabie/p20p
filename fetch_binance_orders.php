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
$stmt = $pdo->prepare("SELECT * FROM settings WHERE user_id = ?");
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
    $endTimestamp = null;

    if (!empty($_GET['start_timestamp'])) {
        $startTimestamp = intval($_GET['start_timestamp']);
    } elseif (!empty($_GET['start_date'])) {
        $startTime = $_GET['start_time'] ?? '00:00:00';
        if (strlen($startTime) == 5) $startTime .= ':00';
        $startTimestamp = strtotime($_GET['start_date'] . ' ' . $startTime) * 1000;
    }

    if (!empty($_GET['end_timestamp'])) {
        $endTimestamp = intval($_GET['end_timestamp']);
    } elseif (!empty($_GET['end_date'])) {
        $endTime = $_GET['end_time'] ?? '23:59:59';
        if (strlen($endTime) == 5) $endTime .= ':59';
        $endTimestamp = strtotime($_GET['end_date'] . ' ' . $endTime) * 1000;
    }

    $fetch_limit = $settings['binance_fetch_limit'] ?: 10;

    // جلب البيانات من المصادر المختلفة
    $orders = $binance->getP2POrders($fetch_limit, $startTimestamp, $endTimestamp);
    $pay_txs = $binance->getPayTransactions($fetch_limit, $startTimestamp, $endTimestamp);
    $withdrawals = $binance->getWithdrawHistory($fetch_limit, $startTimestamp, $endTimestamp);
    $deposits = $binance->getDepositHistory($fetch_limit, $startTimestamp, $endTimestamp);

    // جلب أرقام العمليات المضافة مسبقاً
    $imported_stmt = $pdo->prepare("SELECT binance_order_id FROM transactions WHERE user_id = ? AND binance_order_id IS NOT NULL");
    $imported_stmt->execute([$user_id]);
    $imported_ids = $imported_stmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedOrders = [];

    // 1. معالجة عمليات P2P
    foreach ($orders as $order) {
        $formattedOrders[] = [
            'source' => 'P2P',
            'orderNumber' => (string)$order['orderNumber'],
            'side' => $order['tradeType'],
            'amount' => abs(floatval($order['amount'])),
            'unitPrice' => $order['unitPrice'],
            'totalPrice' => $order['totalPrice'],
            'fiat' => $order['fiat'],
            'binance_fee' => $order['commission'] ?? 0,
            'createTime' => date('Y-m-d H:i:s', $order['createTime'] / 1000),
            'createTimestamp' => $order['createTime'],
            'asset' => $order['asset'],
            'status' => $order['orderStatus'], // COMPLETED, CANCELLED, etc.
            'is_imported' => in_array($order['orderNumber'], $imported_ids),
            'raw' => $order
        ];
    }

    // 2. معالجة عمليات Binance Pay (USDT فقط)
    foreach ($pay_txs as $tx) {
        if ($tx['currency'] === 'USDT') {
            $raw_amount = floatval($tx['amount']);
            $order_id = (string)($tx['transactionId'] ?? $tx['orderId']);

            // تحديد الاتجاه بناءً على معرف المستخدم
            $side = 'SELL';
            if (isset($tx['uid']) && isset($tx['receiverInfo']['binanceId'])) {
                if ($tx['uid'] == $tx['receiverInfo']['binanceId']) {
                    $side = 'BUY';
                }
            } elseif ($raw_amount > 0) {
                $side = 'BUY';
            }

            $formattedOrders[] = [
                'source' => 'PAY',
                'orderNumber' => $order_id,
                'side' => $side,
                'amount' => abs($raw_amount),
                'unitPrice' => 0,
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'binance_fee' => $tx['totalPaymentFee'] ?? 0,
                'createTime' => date('Y-m-d H:i:s', $tx['transactionTime'] / 1000),
                'createTimestamp' => $tx['transactionTime'],
                'asset' => 'USDT',
                'status' => 'COMPLETED',
                'note' => $tx['note'] ?? ($tx['productName'] ?? ''),
                'is_imported' => in_array($order_id, $imported_ids),
                'raw' => $tx
            ];
        }
    }

    // 3. معالجة عمليات الإيداع (Deposits)
    foreach ($deposits as $dp) {
        if ($dp['coin'] === 'USDT') {
            $status_map = [0 => 'PENDING', 1 => 'COMPLETED', 6 => 'COMPLETED'];
            $formattedOrders[] = [
                'source' => 'DEPOSIT',
                'orderNumber' => (string)($dp['txId'] ?: $dp['id']),
                'side' => 'BUY',
                'amount' => abs(floatval($dp['amount'])),
                'unitPrice' => 0,
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'binance_fee' => 0,
                'createTime' => date('Y-m-d H:i:s', $dp['insertTime'] / 1000),
                'createTimestamp' => $dp['insertTime'],
                'asset' => 'USDT',
                'status' => $status_map[$dp['status']] ?? 'OTHER',
                'is_imported' => in_array($dp['txId'] ?: $dp['id'], $imported_ids),
                'raw' => $dp
            ];
        }
    }

    // 4. معالجة عمليات السحب (Withdrawals)
    foreach ($withdrawals as $wd) {
        if ($wd['coin'] === 'USDT') {
            $status_map = [6 => 'COMPLETED', 1 => 'PENDING', 3 => 'CANCELLED', 5 => 'FAILED'];
            // تحويل الوقت من UTC إلى توقيت اليمن المحلي
            $utc_time = $wd['applyTime'];
            if (strpos($utc_time, ' ') !== false && strpos($utc_time, 'UTC') === false) {
                $utc_time .= ' UTC';
            }
            $createTimestamp = strtotime($utc_time) * 1000;

            $formattedOrders[] = [
                'source' => 'WITHDRAW',
                'orderNumber' => (string)$wd['id'],
                'side' => 'SELL',
                'amount' => abs(floatval($wd['amount'])),
                'unitPrice' => 0,
                'totalPrice' => 0,
                'fiat' => 'USDT',
                'binance_fee' => $wd['transactionFee'] ?? 0,
                'createTime' => date('Y-m-d H:i:s', $createTimestamp / 1000),
                'createTimestamp' => $createTimestamp,
                'asset' => 'USDT',
                'status' => $status_map[$wd['status']] ?? 'OTHER',
                'is_imported' => in_array($wd['id'], $imported_ids),
                'raw' => $wd
            ];
        }
    }

    // ترتيب الكل: الأحدث أولاً
    usort($formattedOrders, function($a, $b) {
        return $b['createTimestamp'] - $a['createTimestamp'];
    });

    echo json_encode([
        'status' => 'success',
        'orders' => $formattedOrders,
        'search_start_timestamp' => $startTimestamp,
        'search_end_timestamp' => $endTimestamp,
        'oldest_timestamp' => !empty($formattedOrders) ? min(array_column($formattedOrders, 'createTimestamp')) : null
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطأ Binance: ' . $e->getMessage()]);
}
?>
