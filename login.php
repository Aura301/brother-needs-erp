<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true); // ganti ID sesi pas login berhasil, biar lebih aman
        $_SESSION['user'] = [
            'id' => $user['id'], 'nama' => $user['nama'],
            'username' => $user['username'], 'role' => $user['role'],
        ];
        $_SESSION['last_activity'] = time();
        header('Location: index.php');
        exit;
    } else {
        $error = 'Username atau password salah.';
    }
}

$company = get_company($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Login - Brother Needs</title>
<link rel="stylesheet" href="assets/css/style.css">
<script>
  (function () {
    var saved = localStorage.getItem('bn-theme');
    var theme = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (theme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  })();
</script>
<style>
  body { padding: 16px; }
  .login-card { width: 340px; max-width: 100%; }
</style>
</head>
<body style="background:var(--sidebar); min-height:100vh; display:flex; align-items:center; justify-content:center;">

<div class="card card-pad login-card">
  <div style="text-align:center; margin-bottom:20px;">
    <img src="assets/img/logo.png" alt="Brother Needs" style="height:56px; object-fit:contain; margin-bottom:8px;">
    <div style="font-size:12.5px; color:var(--muted);">Masuk untuk kelola order, stock &amp; keuangan</div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['timeout'])): ?>
    <div class="alert alert-info">⏱ Sesi kamu berakhir karena gak ada aktivitas selama 1 jam. Silakan login lagi.</div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-row">
      <label class="field-label">Username</label>
      <input type="text" name="username" required autofocus>
    </div>
    <div class="form-row">
      <label class="field-label">Password</label>
      <input type="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px 0;">Masuk</button>
  </form>
</div>

</body>
</html>