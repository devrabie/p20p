<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    $amount = floatval($_POST['amount']);
    $price = floatval($_POST['price']);
    $transaction_date = $_POST['transaction_date'];

    $stmt = $pdo->prepare("SELECT type, manual_fee FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    $row = $stmt->fetch();

    if (!$row) {
        die("عذراً، لا تملك صلاحية تعديل هذه العملية.");
    }

    // --- المنطق المحاسبي المحدث للتعديل ---
    $binance_fee = $amount * 0.001;

    if ($row['type'] == 'sell') {
        // بيع: التأثير = الكمية + الرسوم
        $total_crypto_impact = $amount + $binance_fee;
        $total_fiat = $amount * $price;
    } else {
        // شراء: التأثير = الكمية - الرسوم (الصافي المستلم)
        $total_crypto_impact = $amount - $binance_fee;
        $total_fiat = ($amount * $price) + $row['manual_fee'];
    }

    $sql = "UPDATE transactions SET 
            crypto_amount = ?, 
            price_per_unit = ?, 
            binance_fee = ?, 
            total_fiat_paid = ?, 
            total_crypto_deducted = ?,
            created_at = ? 
            WHERE id = ? AND user_id = ?";
    
    $pdo->prepare($sql)->execute([
        $amount, $price, $binance_fee, $total_fiat, $total_crypto_impact, $transaction_date, $id, $user_id
    ]);
    
    header("Location: index.php?updated=1");
}