<?php
/**
 * سكربت المزامنة التلقائية لعمليات بينانس
 * يجب تشغيل هذا الملف عبر Cron Job كل ساعة أو ساعتين
 * مثال (cPanel): 0 * * * * php /path/to/ledger-pro/auto_sync.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/binance_api.php';
require_once __DIR__ . '/fifo_helper.php';

// حماية لضمان تشغيله فقط من الـ CLI (Cron)
if (php_sapi_name() !== 'cli') { die("Access Denied"); }

// 1. جلب كل المستخدمين الذين قاموا بتفعيل المزامنة التلقائية ولديهم مفاتيح API
$stmt = $pdo->query("SELECT * FROM settings WHERE auto_sync_enabled = 1 AND binance_api_key IS NOT NULL AND binance_api_secret IS NOT NULL");
$users_settings = $stmt->fetchAll();

if (empty($users_settings)) {
    echo "No users with auto-sync enabled found.\n";
    exit();
}

$endTimestamp = time() * 1000;
$startTimestamp = $endTimestamp - (24 * 60 * 60 * 1000); // آخر 24 ساعة

foreach ($users_settings as $setting) {
    $user_id = $setting['user_id'];

    // فك تشفير المفتاح السري
    $encryption_key = 'ledger_pro_secure_key_' . $user_id;
    $decrypted_secret = openssl_decrypt($setting['binance_api_secret'], 'AES-128-ECB', $encryption_key);

    if (!$decrypted_secret) {
        echo "Error decrypting API secret for user $user_id. Skipping...\n";
        continue;
    }

    try {
        $binance = new BinanceP2P($setting['binance_api_key'], $decrypted_secret);

        // جلب العمليات لآخر 24 ساعة (نفس منطق fetch_binance_orders.php بس أسرع/أبسط)
        $fetch_limit = 50; // نزيد الحد لضمان عدم تفويت العمليات في حالة المزامنة التلقائية
        $orders = $binance->getP2POrders($fetch_limit, $startTimestamp, $endTimestamp);
        $pay_txs = $binance->getPayTransactions($fetch_limit, $startTimestamp, $endTimestamp);
        $withdrawals = $binance->getWithdrawHistory($fetch_limit, $startTimestamp, $endTimestamp);
        $deposits = $binance->getDepositHistory($fetch_limit, $startTimestamp, $endTimestamp);

        // جلب العمليات المحفوظة مسبقاً لهذا المستخدم
        $imported_stmt = $pdo->prepare("SELECT binance_order_id FROM transactions WHERE user_id = ? AND binance_order_id IS NOT NULL");
        $imported_stmt->execute([$user_id]);
        $imported_ids = $imported_stmt->fetchAll(PDO::FETCH_COLUMN);

        $new_transactions_inserted = false;

        // 2. تحديث سعر الصرف الافتراضي بناءً على أحدث عمليات P2P المكتملة
        $latest_p2p_buy_price = null;
        $latest_p2p_sell_price = null;

        foreach ($orders as $order) {
            if ($order['orderStatus'] === 'COMPLETED') {
                if ($order['tradeType'] === 'BUY' && $latest_p2p_buy_price === null) {
                    $latest_p2p_buy_price = floatval($order['unitPrice']);
                } elseif ($order['tradeType'] === 'SELL' && $latest_p2p_sell_price === null) {
                    $latest_p2p_sell_price = floatval($order['unitPrice']);
                }
            }
        }

        // تحديث الإعدادات في قاعدة البيانات والذاكرة الحالية إذا وجدنا أسعار جديدة
        $current_buy_price = floatval($setting['default_buy_price']);
        $current_sell_price = floatval($setting['default_sell_price']);

        $settings_updated = false;
        if ($latest_p2p_buy_price !== null && $latest_p2p_buy_price > 0 && $latest_p2p_buy_price != $current_buy_price) {
            $current_buy_price = $latest_p2p_buy_price;
            $settings_updated = true;
        }
        if ($latest_p2p_sell_price !== null && $latest_p2p_sell_price > 0 && $latest_p2p_sell_price != $current_sell_price) {
            $current_sell_price = $latest_p2p_sell_price;
            $settings_updated = true;
        }

        if ($settings_updated) {
            $pdo->prepare("UPDATE settings SET default_buy_price = ?, default_sell_price = ? WHERE user_id = ?")
                ->execute([$current_buy_price, $current_sell_price, $user_id]);
        }

        // --- دالة مساعدة لتسجيل العملية في قاعدة البيانات (محاكاة process.php) ---
        $insertTx = function($order_id, $type, $amount, $price, $binance_fee, $created_at) use ($pdo, $user_id, &$new_transactions_inserted) {
            $manual_fee = 0; // الرسوم اليدوية صفر في المزامنة التلقائية

            if ($type === 'sell') {
                $total_crypto_impact = $amount + $binance_fee;
                // يجب التحقق من المخزون قبل البيع التلقائي
                $current_stock = getFIFOStock($pdo, $user_id);
                if ($total_crypto_impact > ($current_stock + 0.0001)) {
                    // لا يمكن البيع تلقائياً إذا المخزون غير كافٍ (لتجنب تعليق النظام بالسالب)
                    echo "Skipping SELL $order_id: Insufficient stock.\n";
                    return;
                }
                $total_fiat = $amount * $price;
            } else {
                $gross_crypto = $amount + $binance_fee;
                $total_crypto_impact = $amount;
                $total_fiat = ($gross_crypto * $price) + $manual_fee;
            }

            $sql = "INSERT INTO transactions (
                user_id, type, crypto_amount, price_per_unit, binance_fee, manual_fee,
                total_fiat_paid, total_crypto_deducted, created_at, binance_order_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id, $type, $amount, $price, $binance_fee, $manual_fee,
                $total_fiat, $total_crypto_impact, $created_at, $order_id
            ]);

            $new_transactions_inserted = true;
            echo "Inserted $type tx: $order_id\n";
        };

        // 3. معالجة وإدراج العمليات المكتملة غير الموجودة في النظام

        // P2P
        foreach ($orders as $order) {
            $order_id = (string)$order['orderNumber'];
            if ($order['orderStatus'] === 'COMPLETED' && !in_array($order_id, $imported_ids)) {
                $type = $order['tradeType'] === 'BUY' ? 'buy' : 'sell';
                $amount = abs(floatval($order['amount']));
                $price = floatval($order['unitPrice']);
                $fee = floatval($order['commission'] ?? 0);
                $created_at = date('Y-m-d H:i:s', $order['createTime'] / 1000);

                if ($type === 'buy') $amount = $amount - $fee; // تصحيح الكمية الصافية للشراء

                $insertTx($order_id, $type, $amount, $price, $fee, $created_at);
                $imported_ids[] = $order_id; // تحديث المصفوفة لمنع التكرار في نفس الدورة
            }
        }

        // Pay
        foreach ($pay_txs as $tx) {
            if ($tx['currency'] === 'USDT') {
                $order_id = (string)($tx['transactionId'] ?? $tx['orderId']);
                if (!in_array($order_id, $imported_ids)) {
                    $raw_amount = floatval($tx['amount']);

                    $side = 'SELL';
                    if (isset($tx['uid']) && isset($tx['receiverInfo']['binanceId'])) {
                        if ($tx['uid'] == $tx['receiverInfo']['binanceId']) $side = 'BUY';
                    } elseif ($raw_amount > 0) {
                        $side = 'BUY';
                    }

                    $type = $side === 'BUY' ? 'buy' : 'sell';
                    $amount = abs($raw_amount);
                    $fee = floatval($tx['totalPaymentFee'] ?? 0);
                    $price = $type === 'buy' ? $current_buy_price : $current_sell_price;
                    $created_at = date('Y-m-d H:i:s', $tx['transactionTime'] / 1000);

                    if ($type === 'buy') $amount = $amount - $fee;

                    $insertTx($order_id, $type, $amount, $price, $fee, $created_at);
                    $imported_ids[] = $order_id;
                }
            }
        }

        // Withdrawals
        foreach ($withdrawals as $wd) {
            if ($wd['coin'] === 'USDT' && $wd['status'] == 6) { // 6 = COMPLETED
                $order_id = (string)$wd['id'];
                if (!in_array($order_id, $imported_ids)) {
                    $type = 'sell';
                    $amount = abs(floatval($wd['amount']));
                    $fee = floatval($wd['transactionFee'] ?? 0);
                    $price = $current_sell_price;

                    $utc_time = $wd['applyTime'];
                    if (strpos($utc_time, ' ') !== false && strpos($utc_time, 'UTC') === false) $utc_time .= ' UTC';
                    $created_at = date('Y-m-d H:i:s', strtotime($utc_time));

                    $insertTx($order_id, $type, $amount, $price, $fee, $created_at);
                    $imported_ids[] = $order_id;
                }
            }
        }

        // Deposits
        foreach ($deposits as $dp) {
            if ($dp['coin'] === 'USDT' && in_array($dp['status'], [1, 6])) { // 1 or 6 = COMPLETED
                $order_id = (string)($dp['txId'] ?: $dp['id']);
                if (!in_array($order_id, $imported_ids)) {
                    $type = 'buy';
                    $amount = abs(floatval($dp['amount']));
                    $fee = 0; // عادة الايداع بدون رسوم من جهة بينانس
                    $price = $current_buy_price;
                    $created_at = date('Y-m-d H:i:s', $dp['insertTime'] / 1000);

                    $insertTx($order_id, $type, $amount, $price, $fee, $created_at);
                    $imported_ids[] = $order_id;
                }
            }
        }

        // 4. إعادة حساب FIFO إذا تم إدراج أي عملية جديدة
        if ($new_transactions_inserted) {
            recalculateFIFO($pdo, $user_id);
            echo "FIFO recalculated for user $user_id.\n";
        } else {
            echo "No new transactions for user $user_id.\n";
        }

    } catch (Exception $e) {
        echo "Error syncing user $user_id: " . $e->getMessage() . "\n";
    }
}
echo "Auto-sync completed successfully.\n";
?>