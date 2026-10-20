<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'master';
$page_title = 'Kelola User';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username']);
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Username sudah dipakai, pilih yang lain.';
        } elseif (strlen($_POST['password']) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, nama, role) VALUES (?,?,?,?)");
            $stmt->execute([$username, $hash, trim($_POST['nama']), $_POST['role']]);
            redirect('master_users.php');
        }
    }

    if ($action === 'reset_password') {
        if (strlen($_POST['password']) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, (int)$_POST['id']]);
            redirect('master_users.php?reset=1');
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $me = current_user();
    if ($id !== $me['id']) { // gak boleh hapus akun sendiri yang lagi dipakai
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    }
    redirect('master_users.php');
}

$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
$showAdd = isset($_GET['tambah']);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Master Data</div>
    <div class="page-sub">Kelola daftar produk, harga, SPPG, dan profil usaha.</div>
  </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="master_produk.php" class="btn btn-subtle">Produk &amp; Harga</a>
  <a href="master_sppg.php" class="btn btn-subtle">Daftar SPPG</a>
  <a href="master_profil.php" class="btn btn-subtle">Profil Usaha &amp; Invoice</a>
  <a href="master_users.php" class="btn btn-primary">Kelola User</a>
</div>

<?php if (isset($_GET['reset'])): ?>
  <div class="alert alert-ok">Password berhasil diganti.</div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:14px;">
  <a href="master_users.php?tambah=1" class="btn btn-accent">+ Tambah User</a>
</div>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:14px;">
  <form method="POST" style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="action" value="add">
    <div><label class="field-label">Nama</label><input type="text" name="nama" required></div>
    <div><label class="field-label">Username</label><input type="text" name="username" required></div>
    <div><label class="field-label">Password</label><input type="text" name="password" required placeholder="min 6 karakter"></div>
    <div><label class="field-label">Role</label>
      <select name="role"><option value="Admin">Admin</option><option value="Owner">Owner</option></select>
    </div>
    <button type="submit" class="btn btn-primary">Simpan</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Ganti Password</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td style="font-weight:600;"><?= h($u['nama']) ?></td>
        <td style="font-family:var(--font-mono);"><?= h($u['username']) ?></td>
        <td><span class="badge badge-muted"><?= h($u['role']) ?></span></td>
        <td>
          <form method="POST" style="display:flex; gap:6px;">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <input type="text" name="password" placeholder="Password baru" style="width:140px; padding:5px 7px;">
            <button type="submit" class="btn btn-subtle btn-sm">Ganti</button>
          </form>
        </td>
        <td style="text-align:right;">
          <?php if ($u['id'] !== current_user()['id']): ?>
            <a href="master_users.php?delete=<?= $u['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus user ini?')">🗑</a>
          <?php else: ?>
            <span style="font-size:11px; color:var(--muted);">(akun kamu)</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
