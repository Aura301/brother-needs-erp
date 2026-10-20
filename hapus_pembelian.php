<?php
/* Hapus Pembelian yang salah catat: balikin stock (kalau barangnya memang di-stock),
   hapus jurnal pembeliannya SATU UTUH, lalu hapus baris pembelian + detailnya.
   Dibuka dari: hapus_pembelian.php?id=<id pembelian>  (id-nya sama kayak di link Edit). */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Hapus Pembelian';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM pembelian WHERE id = ?");
$stmt->execute([$id]);
$pembelian = $stmt->fetch();
if (!$pembelian) { redirect('keuangan_pembelian.php'); }

$dStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ? ORDER BY id ASC");
$dStmt->execute([$id]);
$details = $dStmt->fetchAll();

// Pembelian Hutang yang udah ada pembayarannya gak boleh dihapus langsung (kas-nya udah keluar)
$bayarCount = 0;
try {
    $bStmt = $pdo->prepare("SELECT COUNT(*) FROM pembelian_bayar WHERE pembelian_id = ?");
    $bStmt->execute([$id]);
    $bayarCount = (int)$bStmt->fetchColumn();
} catch (Exception $e) { $bayarCount = 0; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['konfirmasi'] ?? '') === '1') {
    if ($bayarCount > 0) {
        $error = 'Pembelian ini udah punya pembayaran hutang. Hapus/batalin pembayarannya dulu di menu Hutang.';
    } else {
        $pdo->beginTransaction();
        try {
            $userId = current_user()['id'] ?? null;
            $ket = 'Hapus Pembelian ' . $pembelian['no_bukti'] . ($pembelian['supplier'] ? ' - ' . $pembelian['supplier'] : '');

            $excludeCheck = $pdo->prepare("
                SELECT 1 FROM stock_exclude WHERE LOWER(nama) = LOWER(?)
                UNION SELECT 1 FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND kategori IN ('Buah','Sayur')
            ");
            $stockFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            foreach ($details as $d) {
                if ((float)$d['qty'] <= 0) continue;
                $excludeCheck->execute([$d['nama_barang'], $d['nama_barang']]);
                if ($excludeCheck->fetch()) continue;           // barang ini dulu gak di-stock, jadi gak ada yang dibalikin
                $stockFind->execute([$d['nama_barang']]);
                $st = $stockFind->fetch();
                if (!$st) continue;
                $sebelum = (float)$st['stock_fisik'];
                $sesudah = max(0, $sebelum - (float)$d['qty']);
                $pdo->prepare("UPDATE stock SET stock_fisik = ? WHERE id = ?")->execute([$sesudah, $st['id']]);
                if (function_exists('log_stock')) {
                    log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $sebelum, $sesudah, $ket, 'Hapus Pembelian', $id, $userId, $pembelian['tanggal']);
                }
            }

            delete_jurnal_by_ref($pdo, 'Pembelian', $id);        // header + semua baris jurnal (cascade)
            $pdo->prepare("DELETE FROM pembelian_detail WHERE pembelian_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM pembelian WHERE id = ?")->execute([$id]);
            $pdo->commit();
            redirect('keuangan_pembelian.php?dihapus=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menghapus: ' . $e->getMessage();
        }
    }
}

$akunEfek = ['Tunai' => 'Kas (1-101) balik nambah', 'Kas Kecil' => 'Kas Kecil (1-102) balik nambah', 'Hutang' => 'Hutang berkurang'][$pembelian['metode_bayar']] ?? 'Saldo akun pembayaran balik';

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Hapus Pembelian <?= h($pembelian['no_bukti']) ?></div>
    <div class="page-sub">Cek dulu bener gak ini pembelian yang mau dihapus.</div>
  </div>
  <a href="keuangan_pembelian.php" class="btn btn-ghost">← Batal</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-pad" style="max-width:720px; margin-bottom:16px;">
  <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px 20px; margin-bottom:14px; font-size:13px;">
    <div><div class="field-label">Tanggal</div><?= fmt_tanggal($pembelian['tanggal']) ?></div>
    <div><div class="field-label">Supplier</div><?= h($pembelian['supplier'] ?: '-') ?></div>
    <div><div class="field-label">Metode Bayar</div><?= h($pembelian['metode_bayar']) ?></div>
    <div><div class="field-label">Keterangan</div><?= h($pembelian['keterangan'] ?: '-') ?></div>
  </div>
  <table class="data-table">
    <thead><tr><th>Barang</th><th>Qty</th><th>Harga</th><th style="text-align:right;">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($details as $d): ?>
      <tr><td><?= h($d['nama_barang']) ?></td><td><?= $d['qty'] + 0 ?> <?= h($d['satuan']) ?></td><td><?= rupiah($d['harga_satuan']) ?></td><td style="text-align:right; font-family:var(--font-mono);"><?= rupiah($d['subtotal']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="display:flex; justify-content:space-between; border-top:2px solid var(--text); margin-top:6px; padding-top:8px; font-weight:700;">
    <span>Total</span><span style="font-family:var(--font-mono);"><?= rupiah($pembelian['total']) ?></span>
  </div>
</div>

<?php if ($bayarCount > 0): ?>
  <div class="alert alert-danger" style="max-width:720px;">Pembelian Hutang ini udah punya <?= $bayarCount ?> pembayaran. Batalin dulu pembayarannya di menu Hutang, baru bisa dihapus.</div>
<?php else: ?>
  <div class="alert alert-info" style="max-width:720px;">
    Kalau dihapus: jurnal pembelian ini hilang (<?= h($akunEfek) ?>), stock barang yang dulu ikut nambah dikurangi lagi, dan catatan pembeliannya hilang permanen. Pembukuan tetap balance.
  </div>
  <form method="POST" style="max-width:720px;">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="konfirmasi" value="1">
    <button type="submit" class="btn" style="background:var(--danger); color:#fff;" onclick="return confirm('Yakin hapus pembelian ini? Gak bisa dibalikin.')">🗑 Ya, Hapus Pembelian Ini</button>
    <a href="keuangan_pembelian.php" class="btn btn-ghost">Batal</a>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
