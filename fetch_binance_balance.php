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
    echo json_encode(['status' => 'error', 'message' => 'API keys not configured']);
    exit();
}

// فك تشفير المفتاح السري
$encryption_key = 'ledger_pro_secure_key_' . $user_id;
$decrypted_secret = openssl_decrypt($settings['binance_api_secret'], 'AES-128-ECB', $encryption_key);

if (!$decrypted_secret) {
    echo json_encode(['status' => 'error', 'message' => 'Error decrypting secret']);
    exit();
}

try {
    $binance = new BinanceP2P($settings['binance_api_key'], $decrypted_secret);
    $data = $binance->getUSDTBalance();

    echo json_encode(['status' => 'success', 'balance' => $data['total'], 'raw' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
