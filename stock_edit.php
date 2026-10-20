<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'stock';
$page_title = 'Edit Stock';
$error = '';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ?");
$stmt->execute([$id]);
$s = $stmt->fetch();
if (!$s) { redirect('stock.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = current_user()['id'] ?? null;

    $namaBaru = trim($_POST['nama']);
    $satuanBaru = trim($_POST['satuan']);
    $stockBaru = clean_number($_POST['stock_fisik']);
    $modalBaru = clean_number($_POST['harga_modal']);
    $jualBaru = clean_number($_POST['harga_jual']);

    $nilaiLama = round((float)$s['stock_fisik'] * (float)$s['harga_modal'], 2);
    $nilaiBaru = round($stockBaru * $modalBaru, 2);
    $selisih = round($nilaiBaru - $nilaiLama, 2);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE stock SET nama=?, satuan=?, stock_fisik=?, harga_modal=?, harga_jual=?, updated_by=? WHERE id=?");
        $stmt->execute([$namaBaru, $satuanBaru, $stockBaru, $modalBaru, $jualBaru, $userId, $id]);

        log_stock($pdo, $id, $namaBaru, $satuanBaru, (float)$s['stock_fisik'], $stockBaru, 'Edit manual stock', 'Stock Edit', $id, $userId);

        // Sinkron ke Master Produk (cari lewat nama LAMA): nama, satuan, harga modal, harga jual.
        // Cuma kolom yang BERUBAH di edit ini yang diteruskan (edit qty doang gak nyentuh Master Produk).
        // Harga cuma disinkron kalau satuannya sama (biar gak ketuker gr vs kg).
        // Kalau nama baru udah dipake produk lain, nama gak diganti (biar gak dobel).
        $cariProduk = $pdo->prepare("SELECT * FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) LIMIT 1");
        $cariProduk->execute([$s['nama']]);
        $pr = $cariProduk->fetch();
        if ($pr) {
            $bentrok = $pdo->prepare("SELECT 1 FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND id <> ?");
            $bentrok->execute([$namaBaru, $pr['id']]);
            $namaProdukBaru = $bentrok->fetch() ? $pr['nama'] : $namaBaru;
            $satuanSama = strtolower(trim($satuanBaru)) === strtolower(trim($pr['satuan']));
            $modalBerubah = abs($modalBaru - (float)$s['harga_modal']) > 0.0001;
            $jualBerubah = abs($jualBaru - (float)$s['harga_jual']) > 0.0001;
            $satuanBerubah = strtolower(trim($satuanBaru)) !== strtolower(trim($s['satuan']));
            $namaBerubah = strtolower(trim($namaBaru)) !== strtolower(trim($s['nama']));
            $pdo->prepare("UPDATE produk SET nama=?, satuan=?, harga_modal=?, harga_jual=? WHERE id=?")->execute([
                $namaBerubah ? $namaProdukBaru : $pr['nama'],
                $satuanBerubah ? $satuanBaru : $pr['satuan'],
                ($satuanSama && $modalBerubah) ? $modalBaru : $pr['harga_modal'],
                ($satuanSama && $jualBerubah) ? $jualBaru : $pr['harga_jual'],
                $pr['id'],
            ]);
        }
        if (strtolower(trim($s['nama'])) !== strtolower(trim($namaBaru))) {
            $pdo->prepare("UPDATE stock_exclude SET nama=? WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?))")->execute([$namaBaru, $s['nama']]);
            $pdo->prepare("UPDATE stock_sync_ignore SET stock_nama=? WHERE LOWER(TRIM(stock_nama)) = LOWER(TRIM(?))")->execute([$namaBaru, $s['nama']]);
        }

        if (abs($selisih) > 0.01) {
            // Perubahan nilai stock gara-gara edit manual (bukan dari Pembelian/Penjualan yang
            // udah otomatis posting jurnal) ini selisih KETEMUAN LEBIH/KURANG dari pencatatan -
            // sifatnya untung/rugi operasional, BUKAN setoran modal - jadi lawannya ke akun
            // Pendapatan/Beban (biar kehitung di Laba Rugi), bukan ke Modal Pemilik lagi.
            if ($selisih > 0) {
                post_jurnal($pdo, date('Y-m-d'), 'Penyesuaian Edit Stock: ' . $namaBaru, [
                    ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $selisih, 'kredit' => 0, 'keterangan' => 'Edit stock: ' . $namaBaru],
                    ['akun_id' => akun_id($pdo, '4-102'), 'debit' => 0, 'kredit' => $selisih, 'keterangan' => 'Keuntungan selisih stock: ' . $namaBaru],
                ], 'Stock Edit', $id, $userId);
            } else {
                $abs = abs($selisih);
                post_jurnal($pdo, date('Y-m-d'), 'Penyesuaian Edit Stock: ' . $namaBaru, [
                    ['akun_id' => akun_id($pdo, '6-109'), 'debit' => $abs, 'kredit' => 0, 'keterangan' => 'Kerugian selisih stock: ' . $namaBaru],
                    ['akun_id' => akun_id($pdo, '1-120'), 'debit' => 0, 'kredit' => $abs, 'keterangan' => 'Edit stock: ' . $namaBaru],
                ], 'Stock Edit', $id, $userId);
            }
        }

        $pdo->commit();
        redirect('stock.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Gagal menyimpan perubahan: ' . $e->getMessage();
        // re-fetch biar form nampilin data yang beneran kesimpen di db, bukan yang gagal disimpen
        $stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ?");
        $stmt->execute([$id]);
        $s = $stmt->fetch();
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Stock</div>
    <div class="page-sub"><?= h($s['nama']) ?></div>
  </div>
  <a href="stock.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-pad" style="max-width:480px;">
  <form method="POST">
    <div class="form-row">
      <label class="field-label">Nama Barang</label>
      <input type="text" name="nama" value="<?= h($s['nama']) ?>" required>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Satuan</label><input type="text" name="satuan" value="<?= h($s['satuan']) ?>" required></div>
      <div><label class="field-label">Stock Fisik</label><input type="number" step="any" name="stock_fisik" value="<?= $s['stock_fisik'] + 0 ?>"></div>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Harga Modal</label><input type="number" step="any" name="harga_modal" value="<?= $s['harga_modal'] + 0 ?>"></div>
      <div><label class="field-label">Harga Jual</label><input type="number" step="any" name="harga_jual" value="<?= $s['harga_jual'] + 0 ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan Perubahan</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>