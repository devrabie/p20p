<?php
require_once 'db.php';

try {
    // التحقق من وجود الأعمدة قبل إضافتها لتجنب الأخطاء
    $columns = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('binance_api_key', $columns)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN binance_api_key VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('binance_api_secret', $columns)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN binance_api_secret TEXT DEFAULT NULL");
    }
    if (!in_array('binance_fetch_limit', $columns)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN binance_fetch_limit INT DEFAULT 10");
    }

    // إضافة عمود لرقم طلب بينانس في جدول العمليات لمنع التكرار
    $tx_columns = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('binance_order_id', $tx_columns)) {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN binance_order_id VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE transactions ADD INDEX (binance_order_id)");
    }

    echo "<h1>تم تحديث قاعدة البيانات بنجاح!</h1>";
    echo "<p>يمكنك الآن العودة للموقع واستخدام ميزة بينانس.</p>";
    echo "<a href='index.php'>العودة للرئيسية</a>";
} catch (PDOException $e) {
    echo "<h1>فشل التحديث!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
