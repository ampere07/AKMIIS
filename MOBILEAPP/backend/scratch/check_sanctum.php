<?php
$host = '15.235.167.58';
$db = 'atsscbms_sync_db1';
$user = 'atsscbms_sync_db1';
$pass = 'Sync2026!';
$port = '3306';

try {
    $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'personal_access_tokens'");
    $exists = $stmt->fetch();
    if ($exists) {
        echo "personal_access_tokens table EXISTS.\n";
    } else {
        echo "personal_access_tokens table DOES NOT EXIST.\n";
    }
} catch (\PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
