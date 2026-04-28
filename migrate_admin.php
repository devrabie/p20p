<?php
require_once 'db.php';

try {
    // 1. إضافة الأعمدة الجديدة لجدول المستخدمين
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('role', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
        echo "<p>تم إضافة عمود الصلاحيات (role) بنجاح.</p>";
    }

    if (!in_array('status', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        echo "<p>تم إضافة عمود الحالة (status) بنجاح.</p>";
    }

    // 2. تعيين المستخدم devrabie كمسؤول
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE username = ?");
    $stmt->execute(['devrabie']);

    if ($stmt->rowCount() > 0) {
        echo "<p>تم تعيين المستخدم <b>devrabie</b> كمسؤول بنجاح.</p>";
    } else {
        echo "<p>تنبيه: لم يتم العثور على المستخدم devrabie أو أنه مسؤول بالفعل.</p>";
    }

    echo "<h1>تم تحديث قاعدة البيانات بنجاح!</h1>";
    echo "<a href='index.php'>العودة للرئيسية</a>";

} catch (PDOException $e) {
    echo "<h1>فشل التحديث!</h1>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
}
?>
