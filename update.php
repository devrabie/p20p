<?php
require_once 'db.php';
require_once 'fifo_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $price = floatval($_POST['price']);
    $binance_fee = floatval($_POST['binance_fee']);
    $manual_fee = floatval($_POST['manual_fee']);
    $transaction_date = $_POST['transaction_date'];
    $binance_order_id = !empty($_POST['binance_order_id']) ? $_POST['binance_order_id'] : null;

    $stmt = $pdo->prepare("SELECT id FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    if (!$stmt->fetch()) {
        die("عذراً، لا تملك صلاحية تعديل هذه العملية.");
    }

    // --- المنطق المحاسبي الموحد (مطابق لـ process.php) ---
    if ($type == 'sell') {
        // حالة البيع: الرسوم تُضاف فوق المبلغ المباع لتخصم من المحفظة
        $total_crypto_impact = $amount + $binance_fee;

        // التحقق من المخزون (بصرف النظر عن الكمية القديمة لهذه العملية لتجنب التعقيد، نتحقق من الإجمالي الجديد)
        // الطريقة الأدق: المخزون الحالي + الكمية القديمة المحذوفة - الكمية الجديدة
        $old_tx = $pdo->prepare("SELECT total_crypto_deducted, type FROM transactions WHERE id = ?");
        $old_tx->execute([$id]);
        $old_data = $old_tx->fetch();
        $old_impact = floatval($old_data['total_crypto_deducted']);
        $old_type = $old_data['type'];

        $current_stock = getFIFOStock($pdo, $user_id);
        $adjusted_stock = ($old_type == 'sell') ? ($current_stock + $old_impact) : ($current_stock - floatval($old_data['crypto_amount'] ?? 0));

        if ($type == 'sell' && $total_crypto_impact > ($adjusted_stock + 0.0001)) {
            die("خطأ: المخزون غير كافٍ بعد التعديل! المتوفر حالياً بدون هذه العملية: " . round($adjusted_stock, 4));
        }

        $total_fiat = $amount * $price;
        $manual_fee_final = 0;
    } else {
        // حالة الشراء: الهندسة العكسية - التأثير هو الكمية الصافية المستلمة
        $gross_crypto = $amount + $binance_fee;
        $total_crypto_impact = $amount;
        $total_fiat = ($gross_crypto * $price) + $manual_fee;
        $manual_fee_final = $manual_fee;
    }

    $sql = "UPDATE transactions SET 
            type = ?,
            crypto_amount = ?, 
            price_per_unit = ?, 
            binance_fee = ?, 
            manual_fee = ?,
            total_fiat_paid = ?, 
            total_crypto_deducted = ?,
            created_at = ?,
            binance_order_id = ?
            WHERE id = ? AND user_id = ?";
    
    $pdo->prepare($sql)->execute([
        $type, $amount, $price, $binance_fee, $manual_fee_final, $total_fiat, $total_crypto_impact, $transaction_date, $binance_order_id, $id, $user_id
    ]);

    // إعادة حساب FIFO بعد التعديل
    recalculateFIFO($pdo, $user_id);
    
    header("Location: index.php?updated=1");
}