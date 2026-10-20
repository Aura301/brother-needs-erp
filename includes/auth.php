<?php
// Cookie sesi diperketat: gak bisa diakses lewat JavaScript (anti pencurian sesi via XSS),
// dan cuma dikirim ke domain sendiri.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Auto-logout kalau gak ada aktivitas selama 60 menit - biar kalau lupa logout
// di device yang dipinjem/dipake bareng, sesinya gak nyangkut selamanya.
$TIMEOUT_DETIK = 60 * 60;
if (isset($_SESSION['user']) && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $TIMEOUT_DETIK) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

require_login();