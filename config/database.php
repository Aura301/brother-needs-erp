<?php
$DB_HOST = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$DB_NAME = getenv('MYSQLDATABASE') ?: 'railway';
$DB_USER = getenv('MYSQLUSER') ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: 'GFkezZuNpWpLbLCmDpXMzmeBiQwkPZsW';
$DB_PORT = getenv('MYSQLPORT') ?: '3306';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . $e->getMessage());
}
