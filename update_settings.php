<?php
require_once 'db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id']; // هذا المعرف سيستخدم في كل الكويري القادم
require_once 'db.php';

// الآن نعدل كل الاستعلامات (Queries) لتأخذ user_id
$settings = $pdo->prepare("SELECT * FROM settings WHERE user_id = ?");
$settings->execute([$user_id]);
$s = $settings->fetch();

$buy_stats = $pdo->prepare("SELECT SUM(total_fiat_paid) as spent, SUM(crypto_amount) as bought FROM transactions WHERE user_id = ? AND type='buy'");
$buy_stats->execute([$user_id]);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $buy_p = floatval($_POST['default_buy_price']);
    $sell_p = floatval($_POST['default_sell_price']);
    $pdo->prepare("UPDATE settings SET default_buy_price = ?, default_sell_price = ? WHERE id = 1")
        ->execute([$buy_p, $sell_p]);
    header("Location: index.php?updated=1");
}