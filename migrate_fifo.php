<?php
require_once 'db.php';

try {
    $tx_columns = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('fifo_cost_basis', $tx_columns)) {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN fifo_cost_basis DECIMAL(16,4) DEFAULT 0");
    }
    if (!in_array('fifo_profit', $tx_columns)) {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN fifo_profit DECIMAL(16,4) DEFAULT 0");
    }

    // Ensure indexes for performance
    $pdo->exec("ALTER TABLE transactions ADD INDEX IF NOT EXISTS (user_id)");
    $pdo->exec("ALTER TABLE transactions ADD INDEX IF NOT EXISTS (created_at)");
    $pdo->exec("ALTER TABLE transactions ADD INDEX IF NOT EXISTS (type)");

    // تشغيل إعادة الحساب لجميع المستخدمين لضمان دقة البيانات القديمة فوراً
    require_once 'fifo_helper.php';
    $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($users as $uid) {
        recalculateFIFO($pdo, $uid);
    }

    echo "<h1>تم تحديث قاعدة البيانات وتشغيل نظام FIFO بنجاح!</h1>";
    echo "<p><a href='index.php'>العودة للرئيسية</a></p>";
} catch (PDOException $e) {
    echo "<h1>فشل التحديث!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
