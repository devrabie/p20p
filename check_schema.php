<?php
require_once 'db.php';
$stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'binance_order_id'");
$column = $stmt->fetch();
print_r($column);
?>
