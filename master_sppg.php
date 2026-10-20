<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'sppg';
$page_title = 'Daftar SPPG';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' && trim($_POST['nama']) !== '') {
        $stmt = $pdo->prepare("INSERT INTO sppg (nama, alamat, kontak, status) VALUES (?, '', '', 'Aktif')");
        $stmt->execute([trim($_POST['nama'])]);
    }
    if ($action === 'update') {
        $stmt = $pdo->prepare("UPDATE sppg SET nama = ?, alamat = ?, kontak = ?, status = ? WHERE id = ?");
        $stmt->execute([trim($_POST['nama']), trim($_POST['alamat']), trim($_POST['kontak']), $_POST['status'], (int)$_POST['id']]);
    }
    redirect('master_sppg.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM sppg WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('master_sppg.php');
}

$filterStatus = $_GET['status'] ?? '';
$sql = "SELECT * FROM sppg WHERE 1=1";
$params = [];
if ($filterStatus !== '') { $sql .= " AND status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY status ASC, nama ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sppgs = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Daftar SPPG</div>
    <div class="page-sub">Dapur/customer yang dipakai buat Buat Order &amp; Invoice.</div>
  </div>
</div>

<div class="card card-pad" style="margin-bottom:14px;">
  <form method="POST" style="display:flex; gap:10px;">
    <input type="hidden" name="action" value="add">
    <input type="text" name="nama" placeholder="Nama SPPG baru..." style="flex:1;" required>
    <button type="submit" class="btn btn-accent">+ Tambah</button>
    <a href="master_sppg_import.php" class="btn btn-subtle">⬆ Import dari Excel</a>
    <a href="export_excel.php?type=sppg" class="btn btn-subtle">⬇ Export Excel</a>
  </form>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:14px; max-width:280px;">
  <select name="status" onchange="this.form.submit()">
    <option value="">Semua Status</option>
    <option value="Aktif" <?= $filterStatus === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
    <option value="Tidak Aktif" <?= $filterStatus === 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
  </select>
</form>

<div class="alert alert-info">
  SPPG yang statusnya <b>Tidak Aktif</b> otomatis gak muncul lagi di pilihan pas Buat Order, tapi riwayat order lama tetap aman gak kehapus. Kalau ternyata join lagi, tinggal ubah balik ke Aktif.
</div>

<div class="card" style="overflow:hidden;">
  <?php if (empty($sppgs)): ?>
    <div class="empty-state"><div class="icon">📋</div><div class="title">Belum ada SPPG</div></div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Nama SPPG</th><th>Alamat</th><th>Kontak</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($sppgs as $s): $fid = 'f' . $s['id']; ?>
      <tr>
        <td style="font-weight:600;">
          <form id="<?= $fid ?>" method="POST" style="display:none;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
          </form>
          <input type="text" name="nama" form="<?= $fid ?>" value="<?= h($s['nama']) ?>" required style="border:1px solid transparent; padding:4px; background:transparent; width:100%; font-weight:600;" onfocus="this.style.border='1px solid var(--border)'" onblur="this.style.border='1px solid transparent'">
        </td>
        <td>
          <input type="text" name="alamat" form="<?= $fid ?>" value="<?= h($s['alamat']) ?>" placeholder="—" style="border:1px solid transparent; padding:4px; background:transparent; width:100%;" onfocus="this.style.border='1px solid var(--border)'" onblur="this.style.border='1px solid transparent'">
        </td>
        <td><input type="text" name="kontak" form="<?= $fid ?>" value="<?= h($s['kontak']) ?>" placeholder="—" style="border:1px solid transparent; padding:4px; background:transparent; width:100%;" onfocus="this.style.border='1px solid var(--border)'" onblur="this.style.border='1px solid transparent'"></td>
        <td>
          <select name="status" form="<?= $fid ?>" style="width:auto; padding:5px 8px;">
            <option value="Aktif" <?= $s['status'] === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
            <option value="Tidak Aktif" <?= $s['status'] === 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
          </select>
        </td>
        <td style="text-align:right; white-space:nowrap;">
          <button type="submit" form="<?= $fid ?>" class="btn btn-subtle btn-sm">💾 Simpan</button>
          <a href="master_sppg.php?delete=<?= $s['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus SPPG ini secara permanen? Kalau cuma mau nonaktifin, pakai status Tidak Aktif aja.')">🗑</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>