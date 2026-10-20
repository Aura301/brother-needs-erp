<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'mitra';
$page_title = 'Edit Mitra';

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE mitra SET nama=?, jenis_kerjasama=?, kontak=?, alamat=?, tanggal_mulai=?, status=?, catatan=? WHERE id=?");
    $stmt->execute([
        trim($_POST['nama']), trim($_POST['jenis_kerjasama']), trim($_POST['kontak']),
        trim($_POST['alamat']), $_POST['tanggal_mulai'] ?: null, $_POST['status'], trim($_POST['catatan']), $id,
    ]);
    redirect('master_mitra.php');
}

$stmt = $pdo->prepare("SELECT * FROM mitra WHERE id = ?");
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) { redirect('master_mitra.php'); }

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Mitra</div>
    <div class="page-sub"><?= h($m['nama']) ?></div>
  </div>
  <a href="master_mitra.php" class="btn btn-ghost">← Kembali</a>
</div>

<div class="card card-pad" style="max-width:520px;">
  <form method="POST">
    <div class="form-row grid-2">
      <div><label class="field-label">Nama Mitra</label><input type="text" name="nama" value="<?= h($m['nama']) ?>" required></div>
      <div><label class="field-label">Jenis Kerjasama</label><input type="text" name="jenis_kerjasama" value="<?= h($m['jenis_kerjasama']) ?>"></div>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Kontak</label><input type="text" name="kontak" value="<?= h($m['kontak']) ?>"></div>
      <div><label class="field-label">Tanggal Mulai Kerjasama</label><input type="date" name="tanggal_mulai" value="<?= h($m['tanggal_mulai']) ?>"></div>
    </div>
    <div class="form-row">
      <label class="field-label">Alamat</label>
      <input type="text" name="alamat" value="<?= h($m['alamat']) ?>">
    </div>
    <div class="form-row">
      <label class="field-label">Status</label>
      <select name="status">
        <option value="Aktif" <?= $m['status']==='Aktif'?'selected':'' ?>>Aktif</option>
        <option value="Tidak Aktif" <?= $m['status']==='Tidak Aktif'?'selected':'' ?>>Tidak Aktif</option>
      </select>
    </div>
    <div class="form-row">
      <label class="field-label">Catatan</label>
      <textarea name="catatan" rows="3"><?= h($m['catatan']) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan Perubahan</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
