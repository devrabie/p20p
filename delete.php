<?php
require_once 'db.php';
require_once 'fifo_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

if (isset($_GET['id'])) {
    // حذف العملية فقط إذا كانت تخص المستخدم المسجل حالياً
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['id'], $user_id]);

    // إعادة حساب FIFO بعد الحذف
    recalculateFIFO($pdo, $user_id);
}

header("Location: index.php?deleted=1");