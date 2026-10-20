<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'stock';
$page_title = 'Riwayat Stock';

$periode = $_GET['periode'] ?? 'semua';
if (!in_array($periode, ['hari', 'minggu', 'bulan', 'semua', 'custom'])) $periode = 'semua';

$jenis = $_GET['jenis'] ?? 'semua';
if (!in_array($jenis, ['masuk', 'keluar', 'semua'])) $jenis = 'semua';

$search = trim($_GET['q'] ?? '');

// Pencarian by tanggal (rentang Dari - Sampai), sama pola kayak di Riwayat & Invoice.
$dariFilter = trim($_GET['dari'] ?? '');
$sampaiFilter = trim($_GET['sampai'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dariFilter)) $dariFilter = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampaiFilter)) $sampaiFilter = '';
if ($dariFilter !== '' || $sampaiFilter !== '') {
    $periode = 'custom';
}

$sql = "SELECT sl.*, u.nama AS nama_user, (s.id IS NULL) AS item_sudah_dihapus
        FROM stock_log sl
        LEFT JOIN users u ON u.id = sl.created_by
        LEFT JOIN stock s ON s.id = sl.stock_id
        WHERE 1=1";
$params = [];

if ($periode === 'hari') {
    $sql .= " AND sl.tanggal = ?";
    $params[] = date('Y-m-d');
} elseif ($periode === 'minggu') {
    $sql .= " AND sl.tanggal >= ?";
    $params[] = date('Y-m-d', strtotime('monday this week'));
} elseif ($periode === 'bulan') {
    $sql .= " AND DATE_FORMAT(sl.tanggal, '%Y-%m') = ?";
    $params[] = date('Y-m');
} elseif ($periode === 'custom') {
    if ($dariFilter !== '') { $sql .= " AND sl.tanggal >= ?"; $params[] = $dariFilter; }
    if ($sampaiFilter !== '') { $sql .= " AND sl.tanggal <= ?"; $params[] = $sampaiFilter; }
}

if ($jenis === 'masuk') {
    $sql .= " AND sl.perubahan > 0";
} elseif ($jenis === 'keluar') {
    $sql .= " AND sl.perubahan < 0";
}

if ($search !== '') {
    $sql .= " AND sl.nama LIKE ?";
    $params[] = '%' . $search . '%';
}

$sql .= " ORDER BY sl.tanggal DESC, sl.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$totalMasuk = 0;
$totalKeluar = 0;
foreach ($logs as $l) {
    if ($l['perubahan'] > 0) $totalMasuk++;
    else $totalKeluar++;
}

// Badge warna per sumber transaksi, biar gampang kebaca sekilas.
$badgeRefTipe = [
    'Penjualan' => 'badge-danger',
    'Pembelian' => 'badge-ok',
    'Edit Order' => 'badge-muted',
    'Edit Pembelian' => 'badge-muted',
    'Stock Manual' => 'badge-muted',
    'Stock Edit' => 'badge-muted',
    'Susut Barang' => 'badge-danger',
    'Stock Import' => 'badge-muted',
];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Riwayat Stock</div>
    <div class="page-sub">Histori tiap kali stock barang berubah — masuk dari mana, keluar kemana, kapan, dan berapa sebelum/sesudahnya.</div>
  </div>
  <a href="stock.php" class="btn btn-ghost">← Kembali ke Stock</a>
</div>

<div class="alert alert-info">
  💡 Riwayat ini mulai kecatet otomatis dari transaksi baru. Transaksi lama (sebelum fitur ini aktif) gak otomatis muncul di sini.
</div>

<div class="card card-pad" style="margin-bottom:16px;">
  <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
    <a href="stock_riwayat.php?periode=hari&jenis=<?= h($jenis) ?>&q=<?= h($search) ?>&dari=&sampai=" class="btn <?= $periode === 'hari' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Hari Ini</a>
    <a href="stock_riwayat.php?periode=minggu&jenis=<?= h($jenis) ?>&q=<?= h($search) ?>&dari=&sampai=" class="btn <?= $periode === 'minggu' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Minggu Ini</a>
    <a href="stock_riwayat.php?periode=bulan&jenis=<?= h($jenis) ?>&q=<?= h($search) ?>&dari=&sampai=" class="btn <?= $periode === 'bulan' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Bulan Ini</a>
    <a href="stock_riwayat.php?periode=semua&jenis=<?= h($jenis) ?>&q=<?= h($search) ?>&dari=&sampai=" class="btn <?= $periode === 'semua' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Semua</a>
  </div>
  <form method="GET" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <input type="hidden" name="periode" value="<?= h($periode) ?>">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <select name="jenis" onchange="this.form.submit()" style="min-width:150px;">
        <option value="semua" <?= $jenis === 'semua' ? 'selected' : '' ?>>Semua Jenis</option>
        <option value="masuk" <?= $jenis === 'masuk' ? 'selected' : '' ?>>⬆ Stock Masuk</option>
        <option value="keluar" <?= $jenis === 'keluar' ? 'selected' : '' ?>>⬇ Stock Keluar</option>
      </select>
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cari nama barang..." style="min-width:200px;">
      <button type="submit" class="btn btn-subtle btn-sm">Cari</button>
      <div style="display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--muted);">
        <span>Dari</span>
        <input type="date" name="dari" value="<?= h($dariFilter) ?>" onchange="this.form.submit()" style="padding:6px 8px;">
        <span>Sampai</span>
        <input type="date" name="sampai" value="<?= h($sampaiFilter) ?>" onchange="this.form.submit()" style="padding:6px 8px;">
        <?php if ($dariFilter !== '' || $sampaiFilter !== ''): ?>
          <a href="stock_riwayat.php?periode=semua&jenis=<?= h($jenis) ?>&q=<?= h($search) ?>&dari=&sampai=" class="btn btn-ghost btn-sm" style="padding:4px 10px;">✕ Reset Tanggal</a>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<div class="stat-grid cols-3" style="margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-icon ok">⬆</div>
    <div class="stat-label">Transaksi Stock Masuk</div>
    <div class="stat-value" style="color:var(--ok);"><?= $totalMasuk ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent" style="background:var(--danger-soft); color:var(--danger);">⬇</div>
    <div class="stat-label">Transaksi Stock Keluar</div>
    <div class="stat-value" style="color:var(--danger);"><?= $totalKeluar ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent">📜</div>
    <div class="stat-label">Total Baris Sesuai Filter</div>
    <div class="stat-value"><?= count($logs) ?><?= count($logs) >= 500 ? '+' : '' ?></div>
    <?php if (count($logs) >= 500): ?><div style="font-size:11px; color:var(--muted); margin-top:4px;">Ditampilin 500 terbaru - persempit filter buat lihat lebih spesifik.</div><?php endif; ?>
  </div>
</div>

<div class="card" style="overflow:hidden;">
  <?php if (empty($logs)): ?>
    <div class="empty-state">
      <div class="icon">📜</div>
      <div class="title">Belum ada riwayat di periode/filter ini</div>
      <div>Riwayat bakal otomatis kecatet tiap kali ada Order, Pembelian, Edit Stock, Rusak/Susut, dll.</div>
    </div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Tanggal</th><th>Barang</th><th>Jenis</th><th>Perubahan</th><th>Sebelum → Sesudah</th><th>Keterangan</th><th>Sumber</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l):
        $masuk = (float)$l['perubahan'] > 0;
      ?>
      <tr>
        <td style="white-space:nowrap;"><?= fmt_tanggal($l['tanggal']) ?></td>
        <td style="font-weight:600;">
          <?= h($l['nama']) ?>
          <?php if ($l['item_sudah_dihapus']): ?><span style="font-size:10px; color:var(--muted); display:block;">(barang udah dihapus dari Stock)</span><?php endif; ?>
        </td>
        <td><span class="badge <?= $masuk ? 'badge-ok' : 'badge-danger' ?>"><?= $masuk ? '⬆ Masuk' : '⬇ Keluar' ?></span></td>
        <td style="font-family:var(--font-mono); font-weight:700; color:<?= $masuk ? 'var(--ok)' : 'var(--danger)' ?>;">
          <?= $masuk ? '+' : '' ?><?= $l['perubahan'] + 0 ?> <?= h($l['satuan']) ?>
        </td>
        <td style="font-family:var(--font-mono); font-size:12px; white-space:nowrap;"><?= $l['stock_sebelum'] + 0 ?> → <?= $l['stock_sesudah'] + 0 ?> <?= h($l['satuan']) ?></td>
        <td style="font-size:12.5px;"><?= h($l['keterangan']) ?><?php if ($l['nama_user']): ?><div style="font-size:10.5px; color:var(--muted);">oleh <?= h($l['nama_user']) ?></div><?php endif; ?></td>
        <td><?php if ($l['ref_tipe']): ?><span class="badge <?= $badgeRefTipe[$l['ref_tipe']] ?? 'badge-muted' ?>"><?= h($l['ref_tipe']) ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>