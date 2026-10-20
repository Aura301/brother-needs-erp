<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Edit Petty Cash';
$error = '';

function kategori_ke_akun_kode_edit($kategori) {
    if ($kategori === 'Parkir & Transport') return '6-102';
    if ($kategori === 'Konsumsi Dadakan') return '6-105';
    if ($kategori === 'Lain-lain Kecil') return '6-104';
    return '6-101';
}

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'];
    $akun = trim($_POST['akun']);
    $kategori = $_POST['kategori'];
    $catatan = trim($_POST['catatan']);
    $jumlah = clean_number($_POST['jumlah']);
    $userId = current_user()['id'] ?? null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE expenses SET tanggal=?, akun=?, kategori=?, catatan=?, jumlah=? WHERE id=? AND jenis='Petty Cash'");
        $stmt->execute([$tanggal, $akun, $kategori, $catatan, $jumlah, $id]);

        delete_jurnal_by_ref($pdo, 'Petty Cash', $id);
        $bebanKode = kategori_ke_akun_kode_edit($kategori);
        post_jurnal($pdo, $tanggal, 'Petty Cash: ' . $catatan, [
            ['akun_id' => akun_id($pdo, $bebanKode), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $catatan],
            ['akun_id' => akun_id($pdo, '1-102'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $catatan],
        ], 'Petty Cash', $id, $userId);

        $pdo->commit();
        redirect('keuangan_petty_cash.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Gagal menyimpan perubahan: ' . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND jenis = 'Petty Cash'");
$stmt->execute([$id]);
$e = $stmt->fetch();
if (!$e) { redirect('keuangan_petty_cash.php'); }

$kategoriList = ['ATK & Perlengkapan Kecil', 'Parkir & Transport', 'Konsumsi Dadakan', 'Lain-lain Kecil'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Petty Cash</div>
    <div class="page-sub"><?= h($e['catatan']) ?></div>
  </div>
  <a href="keuangan_petty_cash.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-pad" style="max-width:480px;">
  <form method="POST">
    <div class="form-row grid-2">
      <div><label class="field-label">Tanggal</label><input type="date" name="tanggal" value="<?= h($e['tanggal']) ?>" required></div>
      <div><label class="field-label">Akun (nama admin)</label><input type="text" name="akun" value="<?= h($e['akun']) ?>"></div>
    </div>
    <div class="form-row">
      <label class="field-label">Kategori</label>
      <select name="kategori">
        <?php foreach ($kategoriList as $k): ?>
          <option value="<?= h($k) ?>" <?= $e['kategori'] === $k ? 'selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="field-label">Catatan</label>
      <input type="text" name="catatan" value="<?= h($e['catatan']) ?>" required>
    </div>
    <div class="form-row">
      <label class="field-label">Jumlah</label>
      <input type="number" step="any" name="jumlah" value="<?= $e['jumlah'] + 0 ?>" required>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan Perubahan</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>