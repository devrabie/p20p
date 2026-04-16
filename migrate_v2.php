<?php
require_once 'db.php';

try {
    // تحديث طول عمود binance_order_id ليستوعب txId الطويل في عمليات الإيداع
    $pdo->exec("ALTER TABLE transactions MODIFY COLUMN binance_order_id VARCHAR(100) DEFAULT NULL");

    echo "<h1>تم تحديث طول عمود معرف الطلب بنجاح!</h1>";
    echo "<p>تم زيادة الطول إلى 100 حرف.</p>";
    echo "<a href='index.php'>العودة للرئيسية</a>";
} catch (PDOException $e) {
    echo "<h1>فشل التحديث!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
