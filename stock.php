<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'stock';
$page_title = 'Stock Barang';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $nama = trim($_POST['nama']);
        $satuan = trim($_POST['satuan']);
        $stockFisik = clean_number($_POST['stock_fisik']);
        $hargaModal = clean_number($_POST['harga_modal']);
        $hargaJual = clean_number($_POST['harga_jual']);
        $userId = current_user()['id'] ?? null;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO stock (nama, satuan, stock_fisik, harga_modal, harga_jual) VALUES (?,?,?,?,?)");
            $stmt->execute([$nama, $satuan, $stockFisik, $hargaModal, $hargaJual]);
            $stockId = $pdo->lastInsertId();

            log_stock($pdo, $stockId, $nama, $satuan, 0, $stockFisik, 'Tambah item baru', 'Stock Manual', $stockId, $userId);

            $nilai = round($stockFisik * $hargaModal, 2);
            if ($nilai > 0) {
                // Stock yang diinput manual (bukan dari Pembelian yang tercatat lewat Keuangan)
                // diperlakukan sebagai setoran barang dari pemilik - debit Persediaan, kredit Modal
                // Pemilik. Ini biar Neraca tetep balance dan Persediaan di jurnal selalu nyambung
                // sama stock fisik yang beneran ada, bukan cuma angka di tabel stock doang.
                post_jurnal($pdo, date('Y-m-d'), 'Tambah Stock Manual: ' . $nama, [
                    ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $nilai, 'kredit' => 0, 'keterangan' => 'Stock manual: ' . $nama],
                    ['akun_id' => akun_id($pdo, '3-101'), 'debit' => 0, 'kredit' => $nilai, 'keterangan' => 'Setoran barang: ' . $nama],
                ], 'Stock Manual', $stockId, $userId);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan stock: ' . $e->getMessage();
        }
    }

    if ($action === 'lengkapi') {
        $detailId = (int)$_POST['detail_id'];
        $qty = clean_number($_POST['qty']);
        $harga = clean_number($_POST['harga_satuan']);

        if ($qty > 0) {
            $dStmt = $pdo->prepare("SELECT pd.*, p.metode_bayar, p.tanggal, p.no_bukti, p.supplier FROM pembelian_detail pd JOIN pembelian p ON p.id = pd.pembelian_id WHERE pd.id = ?");
            $dStmt->execute([$detailId]);
            $detail = $dStmt->fetch();

            if ($detail) {
                $pdo->beginTransaction();
                try {
                    $subtotal = $qty * $harga;
                    $pdo->prepare("UPDATE pembelian_detail SET qty=?, satuan=?, harga_satuan=?, subtotal=? WHERE id=?")
                        ->execute([$qty, $detail['satuan'], $harga, $subtotal, $detailId]);

                    $excludeCheck = $pdo->prepare("
                        SELECT 1 FROM stock_exclude WHERE LOWER(nama) = LOWER(?)
                        UNION SELECT 1 FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND kategori IN ('Buah','Sayur')
                    ");
                    $excludeCheck->execute([$detail['nama_barang'], $detail['nama_barang']]);
                    $isExcluded = (bool)$excludeCheck->fetch();

                    $userId = current_user()['id'] ?? null;
                    $ketLengkapi = 'Lengkapi Pembelian ' . $detail['no_bukti'] . ($detail['supplier'] ? ' - ' . $detail['supplier'] : '');

                    if (!$isExcluded) {
                        $stockFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
                        $stockFind->execute([$detail['nama_barang']]);
                        $st = $stockFind->fetch();
                        if ($st) {
                            $stockSebelum = (float)$st['stock_fisik'];
                            if ($harga > 0) {
                                $pdo->prepare("UPDATE stock SET stock_fisik = stock_fisik + ?, harga_modal = ? WHERE id = ?")->execute([$qty, $harga, $st['id']]);
                            } else {
                                $pdo->prepare("UPDATE stock SET stock_fisik = stock_fisik + ? WHERE id = ?")->execute([$qty, $st['id']]);
                            }
                            log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, $stockSebelum + $qty, $ketLengkapi, 'Pembelian', $detail['pembelian_id'], $userId, $detail['tanggal']);
                        } else {
                            $pdo->prepare("INSERT INTO stock (nama, satuan, stock_fisik, harga_modal, harga_jual) VALUES (?,?,?,?,0)")
                                ->execute([$detail['nama_barang'], $detail['satuan'], $qty, $harga]);
                            $newStockId = $pdo->lastInsertId();
                            log_stock($pdo, $newStockId, $detail['nama_barang'], $detail['satuan'], 0, $qty, $ketLengkapi, 'Pembelian', $detail['pembelian_id'], $userId, $detail['tanggal']);
                        }
                    }

                    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) t FROM pembelian_detail WHERE pembelian_id = ?");
                    $sumStmt->execute([$detail['pembelian_id']]);
                    $totalBaru = (float)$sumStmt->fetch()['t'];

                    $pdo->prepare("UPDATE pembelian SET total = ? WHERE id = ?")->execute([$totalBaru, $detail['pembelian_id']]);

                    $userId = current_user()['id'] ?? null;
                    delete_jurnal_by_ref($pdo, 'Pembelian', $detail['pembelian_id']);
                    $akunLawan = akun_pembelian_lawan($detail['metode_bayar']);
                    post_jurnal($pdo, $detail['tanggal'], 'Pembelian ' . $detail['no_bukti'] . ($detail['supplier'] ? ' - ' . $detail['supplier'] : ''), [
                        ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $totalBaru, 'kredit' => 0, 'keterangan' => 'Persediaan dari ' . $detail['no_bukti']],
                        ['akun_id' => akun_id($pdo, $akunLawan), 'debit' => 0, 'kredit' => $totalBaru, 'keterangan' => $detail['no_bukti']],
                    ], 'Pembelian', $detail['pembelian_id'], $userId, $detail['no_bukti']);

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Gagal melengkapi data: ' . $e->getMessage();
                }
            }
        }
    }

    if (!$error) {
        redirect('stock.php');
    }
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM stock WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('stock.php');
}

if (isset($_GET['unexclude'])) {
    $stmt = $pdo->prepare("DELETE FROM stock_exclude WHERE id = ?");
    $stmt->execute([(int)$_GET['unexclude']]);
    redirect('stock.php');
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM stock WHERE nama LIKE ? ORDER BY nama ASC");
    $stmt->execute(['%' . $search . '%']);
    $stocks = $stmt->fetchAll();
} else {
    $stocks = $pdo->query("SELECT * FROM stock ORDER BY nama ASC")->fetchAll();
}
$showAdd = isset($_GET['tambah']) || (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'add') && $error);

$pending = $pdo->query("
    SELECT pd.*, p.no_bukti, p.tanggal, p.supplier
    FROM pembelian_detail pd JOIN pembelian p ON p.id = pd.pembelian_id
    WHERE (pd.qty <= 0 OR pd.harga_satuan <= 0)
      AND NOT EXISTS (SELECT 1 FROM stock_exclude se WHERE LOWER(se.nama) = LOWER(TRIM(pd.nama_barang)))
      AND NOT EXISTS (
          SELECT 1 FROM produk pr WHERE LOWER(TRIM(pr.nama)) = LOWER(TRIM(pd.nama_barang)) AND pr.kategori IN ('Buah','Sayur')
      )
    ORDER BY p.tanggal DESC
")->fetchAll();

// Total nilai inventory - ini yang idealnya sama/mendekati angka "Persediaan Barang
// Dagang" di Neraca, jadi bisa dipakai buat cross-check kesehatan pembukuan juga.
$allStock = $pdo->query("SELECT stock_fisik, harga_modal, harga_jual FROM stock")->fetchAll();
$totalNilaiModal = 0;
$totalNilaiJual = 0;
foreach ($allStock as $s) {
    $totalNilaiModal += (float)$s['stock_fisik'] * (float)$s['harga_modal'];
    $totalNilaiJual += (float)$s['stock_fisik'] * (float)$s['harga_jual'];
}
$potensiUntung = $totalNilaiJual - $totalNilaiModal;

$excludeList = $pdo->query("SELECT * FROM stock_exclude ORDER BY nama ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Stock Barang</div>
    <div class="page-sub">Bahan baku &amp; barang dapur internal. Berkurang otomatis saat nama item cocok dengan order.</div>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="stock_riwayat.php" class="btn btn-ghost">📜 Riwayat Stock</a>
    <a href="export_excel.php?type=stock" class="btn btn-ghost">⬇ Export Excel</a>
    <a href="export_excel.php?type=susut" class="btn btn-ghost">⬇ Export Rusak/Susut</a>
    <a href="stock_import.php" class="btn btn-subtle">⬆ Import dari Excel</a>
    <a href="stock.php?tambah=1" class="btn btn-accent">+ Tambah Item</a>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="stat-grid cols-3" style="margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-icon accent">📦</div>
    <div class="stat-label">Total Nilai Inventory (Modal)</div>
    <div class="stat-value"><?= rupiah($totalNilaiModal) ?></div>
    <div style="font-size:11px; color:var(--muted); margin-top:4px;">Idealnya mirip "Persediaan" di Neraca</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon ok">💰</div>
    <div class="stat-label">Total Nilai Kalau Semua Terjual</div>
    <div class="stat-value"><?= rupiah($totalNilaiJual) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon ok">△</div>
    <div class="stat-label">Potensi Untung (Belum Terjual)</div>
    <div class="stat-value"><?= rupiah($potensiUntung) ?></div>
  </div>
</div>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <form method="POST" style="display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="action" value="add">
    <div><label class="field-label">Nama Barang</label><input type="text" name="nama" value="<?= h($_POST['nama'] ?? '') ?>" required></div>
    <div><label class="field-label">Satuan</label><input type="text" name="satuan" value="<?= h($_POST['satuan'] ?? 'kg') ?>" required></div>
    <div><label class="field-label">Stock Fisik</label><input type="number" step="any" name="stock_fisik" value="<?= h($_POST['stock_fisik'] ?? '0') ?>"></div>
    <div><label class="field-label">Harga Modal</label><input type="number" step="any" name="harga_modal" value="<?= h($_POST['harga_modal'] ?? '0') ?>"></div>
    <div><label class="field-label">Harga Jual</label><input type="number" step="any" name="harga_jual" value="<?= h($_POST['harga_jual'] ?? '0') ?>"></div>
    <button type="submit" class="btn btn-primary">Simpan</button>
  </form>
</div>
<?php endif; ?>

<?php if (!empty($pending)): ?>
<div class="card card-pad" style="margin-bottom:16px; border:1px solid var(--danger);">
  <div style="font-weight:700; font-size:14px; margin-bottom:2px; color:var(--danger);">⚠ Perlu Dilengkapi (<?= count($pending) ?>)</div>
  <div style="font-size:11.5px; color:var(--muted); margin-bottom:12px;">
    Barang dari Pembelian yang jumlah/harganya belum diisi. Lengkapi di sini — otomatis update Stock &amp; jurnal Pembelian-nya juga.
  </div>
  <?php foreach ($pending as $p): $fid = 'lengkapi' . $p['id']; ?>
    <form method="POST" id="<?= $fid ?>" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; padding:10px 0; border-bottom:1px solid var(--border);">
      <input type="hidden" name="action" value="lengkapi">
      <input type="hidden" name="detail_id" value="<?= $p['id'] ?>">
      <div style="min-width:160px;">
        <div style="font-weight:600; font-size:13px;"><?= h($p['nama_barang']) ?></div>
        <div style="font-size:11px; color:var(--muted);"><?= h($p['no_bukti']) ?> · <?= fmt_tanggal($p['tanggal']) ?><?= $p['supplier'] ? ' · ' . h($p['supplier']) : '' ?></div>
      </div>
      <div><label class="field-label">Qty</label><input type="number" step="any" name="qty" placeholder="misal 90" style="width:100px;" required></div>
      <div><label class="field-label">Satuan</label><input type="text" value="<?= h($p['satuan']) ?>" disabled style="width:70px; opacity:0.6;"></div>
      <div><label class="field-label">Harga Satuan</label><input type="number" step="any" name="harga_satuan" placeholder="opsional" style="width:130px;"></div>
      <button type="submit" class="btn btn-primary btn-sm">✓ Lengkapi</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="GET" class="card card-pad" style="margin-bottom:16px; max-width:360px;">
  <label class="field-label">Cari Barang</label>
  <div style="display:flex; gap:8px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Ketik nama barang...">
    <button type="submit" class="btn btn-subtle">Cari</button>
    <?php if ($search): ?><a href="stock.php" class="btn btn-ghost">✕</a><?php endif; ?>
  </div>
</form>

<?php if (!empty($excludeList)): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <div style="font-weight:700; font-size:13px; margin-bottom:2px;">🚫 Barang yang Gak Di-Stock (<?= count($excludeList) ?>)</div>
  <div style="font-size:11.5px; color:var(--muted); margin-bottom:10px;">
    Barang ini beli-pas-order, langsung kepake hari itu juga — jadi Pembelian gak nyentuh Stock buat barang ini, meski transaksinya tetep kecatat & posting jurnal normal.
  </div>
  <div style="display:flex; flex-wrap:wrap; gap:8px;">
    <?php foreach ($excludeList as $ex): ?>
      <span style="display:inline-flex; align-items:center; gap:6px; background:var(--bg); border-radius:999px; padding:5px 6px 5px 12px; font-size:12px;">
        <?= h($ex['nama']) ?>
        <a href="stock.php?unexclude=<?= $ex['id'] ?>" title="Batalkan - biar di-stock lagi" style="color:var(--danger); text-decoration:none;" onclick="return confirm('Batalkan? Barang ini bakal ditrack di Stock lagi mulai pembelian berikutnya.')">✕</a>
      </span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <?php if (empty($stocks)): ?>
    <div class="empty-state">
      <div class="icon">📦</div>
      <div class="title"><?= $search ? 'Gak ada hasil buat "' . h($search) . '"' : 'Belum ada stock' ?></div>
      <div><?= $search ? 'Coba kata kunci lain.' : 'Tambahkan barang untuk mulai melacak stock.' ?></div>
    </div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Stock Fisik</th><th>Modal</th><th>Jual</th><th>Untung/Item</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($stocks as $s):
        $untung = (float)$s['harga_jual'] - (float)$s['harga_modal'];
        $menipis = (float)$s['stock_fisik'] <= 5;
      ?>
      <tr>
        <td style="font-weight:600;"><?= h($s['nama']) ?></td>
        <td style="color:<?= $menipis ? 'var(--danger)' : 'var(--text)' ?>; font-weight:<?= $menipis ? '700' : '400' ?>;"><?= $s['stock_fisik'] + 0 ?> <?= h($s['satuan']) ?></td>
        <td><?= rupiah($s['harga_modal']) ?></td>
        <td><?= rupiah($s['harga_jual']) ?></td>
        <td style="color:<?= $untung >= 0 ? 'var(--ok)' : 'var(--danger)' ?>;"><?= rupiah($untung) ?></td>
        <td style="text-align:right; white-space:nowrap;">
          <a href="keuangan_pembelian.php?tambah=1&item=<?= urlencode($s['nama']) ?>&satuan=<?= urlencode($s['satuan']) ?>" class="btn btn-subtle btn-sm">📥 Restock</a>
          <a href="stock_riwayat.php?q=<?= urlencode($s['nama']) ?>" class="btn btn-subtle btn-sm">📜 Riwayat</a>
          <a href="stock_susut.php?id=<?= $s['id'] ?>" class="btn btn-subtle btn-sm" style="color:var(--danger);">🗑️ Rusak</a>
          <a href="stock_edit.php?id=<?= $s['id'] ?>" class="btn btn-subtle btn-sm">✎ Edit</a>
          <a href="stock.php?delete=<?= $s['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus barang ini dari stock?')">🗑</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>