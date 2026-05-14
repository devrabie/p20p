<?php
require_once 'db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id']; // هذا المعرف سيستخدم في كل الكويري القادم

// الآن نعدل كل الاستعلامات (Queries) لتأخذ user_id
$settings = $pdo->prepare("SELECT * FROM settings WHERE user_id = ?");
$settings->execute([$user_id]);
$s = $settings->fetch();

$buy_stats = $pdo->prepare("SELECT SUM(total_fiat_paid) as spent, SUM(crypto_amount) as bought FROM transactions WHERE user_id = ? AND type='buy'");
$buy_stats->execute([$user_id]);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $buy_p = floatval($_POST['default_buy_price']);
    $sell_p = floatval($_POST['default_sell_price']);

    $api_key = $_POST['binance_api_key'] ?? '';
    $api_secret = $_POST['binance_api_secret'] ?? '';
    $fetch_limit = intval($_POST['binance_fetch_limit'] ?? 10);
    $auto_sync = isset($_POST['auto_sync_enabled']) ? 1 : 0;

    // تحديث الأسعار والإعدادات الأساسية
    $pdo->prepare("UPDATE settings SET default_buy_price = ?, default_sell_price = ?, binance_fetch_limit = ?, auto_sync_enabled = ? WHERE user_id = ?")
        ->execute([$buy_p, $sell_p, $fetch_limit, $auto_sync, $user_id]);

    // تحديث مفتاح API إذا تم إدخاله (لا نحدثه إذا كان فارغاً أو عبارة عن نجوم)
    if (!empty($api_key)) {
        $pdo->prepare("UPDATE settings SET binance_api_key = ? WHERE user_id = ?")
            ->execute([$api_key, $user_id]);
    }

    if (!empty($api_secret) && $api_secret !== '********') {
        // تشفير المفتاح السري قبل التخزين (تشفير بسيط بمفتاح ثابت للمثال، يفضل استخدام بيئة أكثر أماناً)
        $encryption_key = 'ledger_pro_secure_key_' . $user_id;
        $encrypted_secret = openssl_encrypt($api_secret, 'AES-128-ECB', $encryption_key);

        $pdo->prepare("UPDATE settings SET binance_api_secret = ? WHERE user_id = ?")
            ->execute([$encrypted_secret, $user_id]);
    }

    header("Location: index.php?updated=1");
}