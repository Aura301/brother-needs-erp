<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'master';
$page_title = 'Edit Produk';

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lama = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
    $lama->execute([$id]);
    $pLama = $lama->fetch();

    $namaBaru = trim($_POST['nama']);
    $satuanBaru = trim($_POST['satuan']);
    $modalBaru = clean_number($_POST['harga_modal']);
    $jualBaru = clean_number($_POST['harga_jual']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE produk SET kategori=?, nama=?, satuan=?, harga_modal=?, harga_jual=? WHERE id=?");
        $stmt->execute([$_POST['kategori'], $namaBaru, $satuanBaru, $modalBaru, $jualBaru, $id]);

        // Sinkron ke Stock Barang (cari lewat nama LAMA): nama, satuan, harga modal, harga jual.
        // Qty stock gak disentuh. Cuma kolom yang BERUBAH di edit ini yang diteruskan.
        // Harga cuma disinkron kalau satuannya sama (biar gak ketuker gr vs kg).
        // Kalau nama baru udah dipake barang stock lain, nama stock gak diganti (biar gak dobel).
        if ($pLama) {
            $cariStock = $pdo->prepare("SELECT * FROM stock WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) LIMIT 1");
            $cariStock->execute([$pLama['nama']]);
            $st = $cariStock->fetch();
            if ($st) {
                $bentrok = $pdo->prepare("SELECT 1 FROM stock WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND id <> ?");
                $bentrok->execute([$namaBaru, $st['id']]);
                $namaStockBaru = $bentrok->fetch() ? $st['nama'] : $namaBaru;
                $satuanSama = strtolower(trim($satuanBaru)) === strtolower(trim($st['satuan']));
                $modalBerubah = abs($modalBaru - (float)$pLama['harga_modal']) > 0.0001;
                $jualBerubah = abs($jualBaru - (float)$pLama['harga_jual']) > 0.0001;
                $satuanBerubah = strtolower(trim($satuanBaru)) !== strtolower(trim($pLama['satuan']));
                $namaBerubah = strtolower(trim($namaBaru)) !== strtolower(trim($pLama['nama']));
                $pdo->prepare("UPDATE stock SET nama=?, satuan=?, harga_modal=?, harga_jual=? WHERE id=?")->execute([
                    $namaBerubah ? $namaStockBaru : $st['nama'],
                    $satuanBerubah ? $satuanBaru : $st['satuan'],
                    ($satuanSama && $modalBerubah) ? $modalBaru : $st['harga_modal'],
                    ($satuanSama && $jualBerubah) ? $jualBaru : $st['harga_jual'],
                    $st['id'],
                ]);
                if ($namaBerubah && strtolower(trim($st['nama'])) !== strtolower(trim($namaStockBaru))) {
                    $pdo->prepare("UPDATE stock_exclude SET nama=? WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?))")->execute([$namaStockBaru, $st['nama']]);
                    $pdo->prepare("UPDATE stock_sync_ignore SET stock_nama=? WHERE LOWER(TRIM(stock_nama)) = LOWER(TRIM(?))")->execute([$namaStockBaru, $st['nama']]);
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Gagal menyimpan perubahan: ' . h($e->getMessage()));
    }
    redirect('master_produk.php?jump_to=' . $id);
}

$stmt = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) { redirect('master_produk.php'); }

$kategoriList = ['Buah', 'Sayur', 'Frozen', 'Bahan Baku', 'Daging & Seafood', 'Saus', 'Jasa/Lainnya'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Produk</div>
    <div class="page-sub"><?= h($p['nama']) ?></div>
  </div>
  <a href="master_produk.php" class="btn btn-ghost">← Kembali</a>
</div>

<div class="card card-pad" style="max-width:480px;">
  <form method="POST">
    <div class="form-row">
      <label class="field-label">Kategori</label>
      <select name="kategori">
        <?php foreach ($kategoriList as $k): ?>
          <option value="<?= h($k) ?>" <?= $p['kategori'] === $k ? 'selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="field-label">Nama Produk</label>
      <input type="text" name="nama" value="<?= h($p['nama']) ?>" required>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Satuan</label><input type="text" name="satuan" value="<?= h($p['satuan']) ?>" required></div>
      <div></div>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Harga Modal</label><input type="number" step="any" name="harga_modal" value="<?= $p['harga_modal'] + 0 ?>"></div>
      <div><label class="field-label">Harga Jual</label><input type="number" step="any" name="harga_jual" value="<?= $p['harga_jual'] + 0 ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan Perubahan</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>