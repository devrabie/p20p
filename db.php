<?php
/**
 * ملف الاتصال بقاعدة البيانات
 */

$host = '127.0.0.1'; // استبدل localhost بـ 127.0.0.1 لحل مشكلة الأندرويد
$db   = 'p2p'; // تأكد أن هذا هو اسم قاعدة البيانات التي أنشأتها
$user = 'root'; // اسم المستخدم الافتراضي في أغلب تطبيقات الأندرويد هو root
$pass = 'root';     // كلمة السر الافتراضية عادة تكون فارغة في KSWEB أو AWebServer
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// ضبط التوقيت الافتراضي لـ PHP لليمن
date_default_timezone_set('Asia/Aden');

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     // ضبط توقيت قاعدة البيانات لليمن GMT+3 لضمان دقة العمليات
     $pdo->exec("SET time_zone = '+03:00'");
     
} catch (\PDOException $e) {
     // عرض رسالة الخطأ بشكل واضح إذا فشل الاتصال
     die("فشل الاتصال بقاعدة البيانات يابطل! تأكد من تشغيل MySQL في التطبيق. الخطأ: " . $e->getMessage());
}
?>