<?php
/**
 * ملف الاتصال بقاعدة البيانات
 */

// دالة بسيطة لتحميل متغيرات البيئة من ملف .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos(trim($line), '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1'; // استبدل localhost بـ 127.0.0.1 لحل مشكلة الأندرويد
$db   = getenv('DB_NAME') ?: 'p2p'; // تأكد أن هذا هو اسم قاعدة البيانات التي أنشأتها
$user = getenv('DB_USER') ?: 'root'; // اسم المستخدم الافتراضي في أغلب تطبيقات الأندرويد هو root
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root';     // كلمة السر الافتراضية عادة تكون فارغة في KSWEB أو AWebServer
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