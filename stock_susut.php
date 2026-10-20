<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'stock';
$page_title = 'Catat Rusak / Susut';

$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) { redirect('stock.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = clean_number($_POST['qty']);
    $alasan = trim($_POST['alasan']);
    $tanggal = $_POST['tanggal'];
    $userId = current_user()['id'] ?? null;

    if ($qty <= 0) {
        $error = 'Jumlah rusak harus lebih dari 0.';
    } elseif ($qty > $item['stock_fisik']) {
        $error = 'Jumlah rusak (' . $qty . ') gak boleh lebih dari stock yang ada sekarang (' . ($item['stock_fisik'] + 0) . ' ' . $item['satuan'] . ').';
    } else {
        $nilaiKerugian = $qty * $item['harga_modal'];

        $pdo->beginTransaction();
        try {
            $stockSebelum = (float)$item['stock_fisik'];
            $pdo->prepare("UPDATE stock SET stock_fisik = stock_fisik - ? WHERE id = ?")->execute([$qty, $id]);

            $ins = $pdo->prepare("INSERT INTO stock_susut (stock_id, nama, satuan, qty, nilai_kerugian, alasan, tanggal, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$id, $item['nama'], $item['satuan'], $qty, $nilaiKerugian, $alasan, $tanggal, $userId]);
            $susutId = $pdo->lastInsertId();

            log_stock($pdo, $id, $item['nama'], $item['satuan'], $stockSebelum, $stockSebelum - $qty, 'Rusak/Susut' . ($alasan ? ': ' . $alasan : ''), 'Susut Barang', $susutId, $userId, $tanggal);

            if ($nilaiKerugian > 0) {
                $ket = 'Susut/Rusak: ' . $item['nama'] . ' ' . $qty . ' ' . $item['satuan'] . ($alasan ? ' (' . $alasan . ')' : '');
                post_jurnal($pdo, $tanggal, $ket, [
                    ['akun_id' => akun_id($pdo, '6-108'), 'debit' => $nilaiKerugian, 'kredit' => 0, 'keterangan' => $ket],
                    ['akun_id' => akun_id($pdo, '1-120'), 'debit' => 0, 'kredit' => $nilaiKerugian, 'keterangan' => $ket],
                ], 'Susut Barang', $susutId, $userId);
            }

            $pdo->commit();
            redirect('stock.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$riwayat = $pdo->prepare("SELECT * FROM stock_susut WHERE stock_id = ? ORDER BY tanggal DESC, id DESC");
$riwayat->execute([$id]);
$riwayatList = $riwayat->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Catat Rusak / Susut</div>
    <div class="page-sub"><?= h($item['nama']) ?> — stock sekarang: <?= $item['stock_fisik'] + 0 ?> <?= h($item['satuan']) ?></div>
  </div>
  <div style="display:flex; gap:8px;">
    <a href="export_excel.php?type=susut" class="btn btn-ghost">⬇ Export Semua Riwayat Rusak (Excel)</a>
    <a href="stock.php" class="btn btn-ghost">← Kembali</a>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="alert alert-info">
  💡 Ini buat barang yang ternyata gak layak jual (busuk, rusak, dll) pas disortir. Stock otomatis berkurang,
  dan nilainya (qty × harga modal) otomatis kecatat sebagai kerugian di Laba Rugi — soalnya barang ini udah dibayar
  waktu beli, tapi gak menghasilkan apa-apa.
</div>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Catat Barang Rusak</div>
    <form method="POST">
      <div class="form-row">
        <label class="field-label">Tanggal</label>
        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-row">
        <label class="field-label">Jumlah Rusak (<?= h($item['satuan']) ?>)</label>
        <input type="number" step="any" name="qty" max="<?= $item['stock_fisik'] ?>" placeholder="misal 5" required>
      </div>
      <div class="form-row">
        <label class="field-label">Alasan (opsional)</label>
        <input type="text" name="alasan" placeholder="misal: busuk pas sortir, kena hujan, dll">
      </div>
      <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">
        Perkiraan kerugian: <b style="font-family:var(--font-mono);" id="preview-kerugian">Rp0</b>
        <span style="display:none;" id="harga-modal-data" data-modal="<?= $item['harga_modal'] ?>"></span>
      </div>
      <button type="submit" class="btn btn-danger" style="width:100%; justify-content:center;">Catat Kerugian</button>
    </form>
  </div>

  <div class="card" style="overflow:hidden;">
    <?php if (empty($riwayatList)): ?>
      <div class="empty-state"><div class="icon">🗑️</div><div class="title">Belum ada riwayat rusak/susut buat item ini</div></div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Tanggal</th><th>Jumlah</th><th>Alasan</th><th>Kerugian</th></tr></thead>
      <tbody>
        <?php foreach ($riwayatList as $r): ?>
        <tr>
          <td><?= fmt_tanggal($r['tanggal']) ?></td>
          <td style="font-family:var(--font-mono);"><?= $r['qty'] + 0 ?> <?= h($r['satuan']) ?></td>
          <td><?= h($r['alasan']) ?: '-' ?></td>
          <td style="font-family:var(--font-mono); color:var(--danger); font-weight:700;">-<?= rupiah($r['nilai_kerugian']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
  const hargaModal = parseFloat(document.getElementById('harga-modal-data').dataset.modal) || 0;
  document.querySelector('[name="qty"]').addEventListener('input', function() {
    const qty = parseFloat(this.value) || 0;
    document.getElementById('preview-kerugian').textContent = 'Rp' + Math.round(qty * hargaModal).toLocaleString('id-ID');
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>