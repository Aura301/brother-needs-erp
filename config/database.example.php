<?php
/**
 * Koneksi database. COPY file ini jadi config/database.php lalu sesuaikan.
 * (config/database.php sengaja di-.gitignore supaya kredensial gak ke-commit.)
 * Default XAMPP: host=localhost, user=root, password=kosong.
 */
$DB_HOST = 'localhost';
$DB_NAME = 'brother_needs';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . $e->getMessage() . '
        <br><br>Cek dulu: (1) XAMPP MySQL sudah "Start"? (2) Database "brother_needs" sudah di-import lewat phpMyAdmin? (3) Setting di config/database.php sudah sesuai?');
}
