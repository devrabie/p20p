<?php
require_once 'db.php';

try {
    echo "<h1>بدء تحسين قاعدة البيانات...</h1>";

    // جلب الاندكسات الحالية لتجنب التكرار (لأن MySQL لا يدعم IF NOT EXISTS للاندكسات بشكل مباشر)
    $stmt = $pdo->query("SHOW INDEX FROM transactions");
    $existing_indexes = $stmt->fetchAll(PDO::FETCH_COLUMN, 2); // 2 هو رقم العمود Key_name
    $existing_indexes = array_unique($existing_indexes);

    // 1. تحسين الفهارس لجدول العمليات (transactions)
    echo "<p>تحليل الفهارس الحالية...</p>";

    // فهرس مركب للمستخدم والتاريخ (مهم جداً للرسوم البيانية والتقارير)
    if (!in_array('idx_user_date', $existing_indexes)) {
        $pdo->exec("CREATE INDEX idx_user_date ON transactions (user_id, created_at)");
        echo "<p>✅ تم إنشاء فهرس (user_id, created_at).</p>";
    }

    // فهرس مركب للمستخدم والنوع والتاريخ (مهم لحسابات FIFO والمخزون)
    if (!in_array('idx_user_type_date', $existing_indexes)) {
        $pdo->exec("CREATE INDEX idx_user_type_date ON transactions (user_id, type, created_at)");
        echo "<p>✅ تم إنشاء فهرس (user_id, type, created_at).</p>";
    }

    // فهرس لرقم طلب بينانس مع المستخدم (لمنع التكرار بسرعة)
    if (!in_array('idx_user_binance_id', $existing_indexes)) {
        $pdo->exec("CREATE INDEX idx_user_binance_id ON transactions (user_id, binance_order_id)");
        echo "<p>✅ تم إنشاء فهرس (user_id, binance_order_id).</p>";
    }

    // 2. تحسين جدول الإعدادات
    $stmt_settings = $pdo->query("SHOW INDEX FROM settings");
    $settings_indexes = $stmt_settings->fetchAll(PDO::FETCH_COLUMN, 2);
    if (!in_array('idx_settings_user', $settings_indexes)) {
        $pdo->exec("CREATE INDEX idx_settings_user ON settings (user_id)");
        echo "<p>✅ تم إنشاء فهرس لمستخدم الإعدادات.</p>";
    }

    echo "<h2>🚀 تم الانتهاء من تحسين الاندكسات بنجاح!</h2>";
    echo "<p>تم تسريع عمليات البحث والفلترة وحسابات FIFO بشكل ملحوظ.</p>";
    echo "<a href='index.php'>العودة للرئيسية</a>";

} catch (PDOException $e) {
    echo "<h1>فشل التحسين!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
