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

    $orders = $binance->getP2POrders($settings['binance_fetch_limit'] ?: 10, $startTimestamp);

    // جلب أرقام العمليات المضافة مسبقاً
    $imported_stmt = $pdo->prepare("SELECT binance_order_id FROM transactions WHERE user_id = ? AND binance_order_id IS NOT NULL");
    $imported_stmt->execute([$user_id]);
    $imported_ids = $imported_stmt->fetchAll(PDO::FETCH_COLUMN);

    $formattedOrders = [];
    foreach ($orders as $order) {
        // تحويل الحالة والبيانات للشكل المطلوب في الواجهة
        $formattedOrders[] = [
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

    echo json_encode(['status' => 'success', 'orders' => $formattedOrders]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطأ Binance: ' . $e->getMessage()]);
}
?>
