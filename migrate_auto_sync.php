<?php
require_once 'db.php';

try {
    // التحقق من وجود الأعمدة قبل إضافتها لتجنب الأخطاء
    $columns = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('auto_sync_enabled', $columns)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN auto_sync_enabled TINYINT(1) DEFAULT 0");
        echo "تم إضافة عمود auto_sync_enabled بنجاح.<br>";
    } else {
        echo "العمود موجود مسبقاً.<br>";
    }

    echo "<h1>تم تحديث قاعدة البيانات بنجاح!</h1>";
} catch (PDOException $e) {
    echo "<h1>فشل التحديث!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
