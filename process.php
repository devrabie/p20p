<?php
/**
 * نظام Ledger Pro - المحرك البرمجي المحدث (AJAX Version)
 * معالجة البيانات، الهندسة العكسية، وتحديث الأسعار الافتراضية تلقائياً
 */

session_start();
require_once 'db.php';

// 1. ضبط توقيت السيرفر لليمن (GMT+3)
date_default_timezone_set('Asia/Aden');

// تجهيز نوع الرد ليكون JSON دائماً
header('Content-Type: application/json');

// 2. حماية الملف: التأكد من تسجيل دخول المستخدم
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit();
}

$user_id = $_SESSION['user_id'];

// 3. التحقق من إرسال البيانات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['amount'])) {
    
    // أ- استلام البيانات وتحويلها لـ Float
    $type = $_POST['type']; 
    $crypto_amount = floatval($_POST['amount'] ?? 0); // الكمية الصافية
    $price_per_unit = floatval($_POST['price'] ?? 0);
    $manual_fiat_fee = floatval($_POST['manual_fee'] ?? 0); 

    // ب- معالجة التاريخ
    $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d H:i:s');

    // ج- المنطق المحاسبي (مطابق تماماً لصور بينانس)
    $total_fiat_paid = 0; 
    $total_crypto_impact = 0;
    $binance_fee = 0;

    if ($type == 'sell') {
        // حالة البيع: الرسوم تُضاف فوق المبلغ المباع
        if (isset($_POST['binance_fee']) && $_POST['binance_fee'] !== '') {
            $binance_fee = floatval($_POST['binance_fee']);
        } else {
            $binance_fee = $crypto_amount * 0.001;
        }
        $total_crypto_impact = $crypto_amount + $binance_fee;
        $total_fiat_paid = $crypto_amount * $price_per_unit;
        $manual_fee_final = 0;
    } else {
        // حالة الشراء: الهندسة العكسية
        if (isset($_POST['binance_fee']) && $_POST['binance_fee'] !== '' && floatval($_POST['binance_fee']) >= 0) {
            $binance_fee = floatval($_POST['binance_fee']);
            $gross_crypto = $crypto_amount + $binance_fee;
        } else {
            $gross_crypto = $crypto_amount / 0.999;
            $binance_fee = $gross_crypto - $crypto_amount;
        }
        $total_crypto_impact = $crypto_amount;
        $total_fiat_paid = ($gross_crypto * $price_per_unit) + $manual_fiat_fee;
        $manual_fee_final = $manual_fiat_fee;
    }

    // 4. تنفيذ عملية الحفظ وتحديث الإعدادات في Transaction واحدة لضمان الدقة
    try {
        $pdo->beginTransaction();

        // أ. حفظ العملية في الجدول الرئيسي
        $sql = "INSERT INTO transactions (
                    user_id, type, crypto_amount, price_per_unit, currency, 
                    binance_fee, manual_fee, total_fiat_paid, total_crypto_deducted, created_at
                ) VALUES (?, ?, ?, ?, 'YER', ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id, $type, $crypto_amount, $price_per_unit, 
            $binance_fee, $manual_fee_final, $total_fiat_paid, $total_crypto_impact, $transaction_date
        ]);

        // ب. الميزة الجديدة: تحديث السعر الافتراضي في الإعدادات تلقائياً
        if ($type == 'buy') {
            $update_sql = "UPDATE settings SET default_buy_price = ? WHERE user_id = ?";
        } else {
            $update_sql = "UPDATE settings SET default_sell_price = ? WHERE user_id = ?";
        }
        $pdo->prepare($update_sql)->execute([$price_per_unit, $user_id]);

        $pdo->commit();

        // 5. الرد بنجاح
        echo json_encode([
            'status' => 'success',
            'message' => 'تم الحفظ وتحديث السعر الافتراضي!',
            'last_date' => $transaction_date
        ]);
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'خطأ: ' . $e->getMessage()]);
        exit();
    }
}