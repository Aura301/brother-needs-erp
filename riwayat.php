<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'riwayat';
$page_title = 'Riwayat & Invoice';

ensure_invoice_jenis_schema($pdo);
$jenisFilter = $_GET['jenis'] ?? 'semua';
if ($jenisFilter !== 'semua') $jenisFilter = normalize_jenis($jenisFilter);
$jQ = '&jenis=' . urlencode($jenisFilter);
$jenisCfgAll = invoice_jenis_config();

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('riwayat.php' . (isset($_GET['jenis']) ? '?jenis=' . urlencode($jenisFilter) : ''));
}

if (isset($_GET['set_status']) && $_GET['set_status'] === 'belum_bayar' && isset($_GET['id'])) {
    $orderId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT bukti_bayar FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $existing = $stmt->fetch();
    if ($existing && $existing['bukti_bayar']) {
        $filePath = __DIR__ . '/assets/uploads/bukti_bayar/' . $existing['bukti_bayar'];
        if (file_exists($filePath)) @unlink($filePath);
    }
    $pdo->prepare("UPDATE orders SET status='Belum Bayar', bukti_bayar=NULL, lunas_by=NULL, lunas_at=NULL WHERE id=?")
        ->execute([$orderId]);
    redirect('invoice.php?id=' . $orderId);
}

$periode = $_GET['periode'] ?? 'semua';
if (!in_array($periode, ['hari', 'minggu', 'bulan', 'semua', 'custom'])) $periode = 'semua';

$status = $_GET['status'] ?? 'semua';
if (!in_array($status, ['Lunas', 'Belum Bayar', 'semua'])) $status = 'semua';

$sppgFilter = trim($_GET['sppg'] ?? '');

// Pencarian by tanggal (rentang Dari - Sampai). Kalau salah satu diisi, otomatis
// pindah ke mode custom dan nimpa pilihan periode cepat (Hari Ini/Minggu Ini/dst).
$dariFilter = trim($_GET['dari'] ?? '');
$sampaiFilter = trim($_GET['sampai'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dariFilter)) $dariFilter = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampaiFilter)) $sampaiFilter = '';
if ($dariFilter !== '' || $sampaiFilter !== '') {
    $periode = 'custom';
}

$daftarSppg = $pdo->query("SELECT DISTINCT sppg_nama FROM orders ORDER BY sppg_nama ASC")->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT * FROM orders WHERE 1=1";
$params = [];
if ($periode === 'hari') {
    $sql .= " AND tanggal = ?";
    $params[] = date('Y-m-d');
} elseif ($periode === 'minggu') {
    $sql .= " AND tanggal >= ?";
    $params[] = date('Y-m-d', strtotime('monday this week'));
} elseif ($periode === 'bulan') {
    $sql .= " AND DATE_FORMAT(tanggal, '%Y-%m') = ?";
    $params[] = date('Y-m');
} elseif ($periode === 'custom') {
    if ($dariFilter !== '') {
        $sql .= " AND tanggal >= ?";
        $params[] = $dariFilter;
    }
    if ($sampaiFilter !== '') {
        $sql .= " AND tanggal <= ?";
        $params[] = $sampaiFilter;
    }
}
if ($status !== 'semua') {
    $sql .= " AND status = ?";
    $params[] = $status;
}
if ($sppgFilter !== '') {
    $sql .= " AND sppg_nama = ?";
    $params[] = $sppgFilter;
}
if ($jenisFilter !== 'semua') {
    $sql .= " AND jenis = ?";
    $params[] = $jenisFilter;
}
$sql .= " ORDER BY tanggal DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Riwayat & Invoice</div>
    <div class="page-sub">Semua invoice yang udah pernah dibuat.</div>
  </div>
</div>

<?php if (isset($_GET['edited'])): ?>
  <div class="alert alert-ok">Perubahan invoice tersimpan.</div>
<?php endif; ?>
<?php if (isset($_GET['split_created'])): ?>
  <div class="alert alert-ok">Invoice baru berhasil dibuat dari hasil pemisahan item.</div>
<?php endif; ?>

<div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
  <a href="riwayat.php?periode=<?= h($periode) ?>&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=<?= h($dariFilter) ?>&sampai=<?= h($sampaiFilter) ?>&jenis=semua" class="btn <?= $jenisFilter === 'semua' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Semua Jenis</a>
  <?php foreach ($jenisCfgAll as $jk => $jc): ?>
    <a href="riwayat.php?periode=<?= h($periode) ?>&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=<?= h($dariFilter) ?>&sampai=<?= h($sampaiFilter) ?>&jenis=<?= $jk ?>" class="btn <?= $jenisFilter === $jk ? 'btn-primary' : 'btn-subtle' ?> btn-sm"><?= $jc['icon'] ?> <?= h($jc['short']) ?> (<?= h($jc['prefix']) ?>)</a>
  <?php endforeach; ?>
</div>

<div class="card card-pad" style="margin-bottom:16px;">
  <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
    <a href="riwayat.php?periode=hari&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=&sampai=<?= $jQ ?>" class="btn <?= $periode === 'hari' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Hari Ini</a>
    <a href="riwayat.php?periode=minggu&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=&sampai=<?= $jQ ?>" class="btn <?= $periode === 'minggu' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Minggu Ini</a>
    <a href="riwayat.php?periode=bulan&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=&sampai=<?= $jQ ?>" class="btn <?= $periode === 'bulan' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Bulan Ini</a>
    <a href="riwayat.php?periode=semua&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=&sampai=<?= $jQ ?>" class="btn <?= $periode === 'semua' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Semua</a>
  </div>
  <form method="GET" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <input type="hidden" name="periode" value="<?= h($periode) ?>">
    <input type="hidden" name="jenis" value="<?= h($jenisFilter) ?>">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <select name="status" onchange="this.form.submit()" style="min-width:150px;">
        <option value="semua" <?= $status === 'semua' ? 'selected' : '' ?>>Semua Status</option>
        <option value="Lunas" <?= $status === 'Lunas' ? 'selected' : '' ?>>✓ Udah Lunas</option>
        <option value="Belum Bayar" <?= $status === 'Belum Bayar' ? 'selected' : '' ?>>Belum Bayar</option>
      </select>
      <select name="sppg" onchange="this.form.submit()" style="min-width:200px;">
        <option value="">Semua SPPG</option>
        <?php foreach ($daftarSppg as $s): ?>
          <option value="<?= h($s) ?>" <?= $sppgFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--muted);">
        <span>Dari</span>
        <input type="date" name="dari" value="<?= h($dariFilter) ?>" onchange="this.form.submit()" style="padding:6px 8px;">
        <span>Sampai</span>
        <input type="date" name="sampai" value="<?= h($sampaiFilter) ?>" onchange="this.form.submit()" style="padding:6px 8px;">
        <?php if ($dariFilter !== '' || $sampaiFilter !== ''): ?>
          <a href="riwayat.php?periode=semua&status=<?= h($status) ?>&sppg=<?= h($sppgFilter) ?>&dari=&sampai=<?= $jQ ?>" class="btn btn-ghost btn-sm" style="padding:4px 10px;">✕ Reset Tanggal</a>
        <?php endif; ?>
      </div>
    </div>
    <div style="font-size:13px; color:var(--muted);">Total sesuai filter: <b style="color:var(--text); font-family:var(--font-mono);"><?= rupiah(array_sum(array_column($orders, 'total'))) ?></b> (<?= count($orders) ?> invoice)</div>
  </form>
</div>

<div class="card" style="overflow:hidden;">
  <?php if (empty($orders)): ?>
    <div class="empty-state"><div class="icon">🧾</div><div class="title">Gak ada invoice di periode ini</div></div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Invoice</th><th>Jenis</th><th>Tanggal</th><th>SPPG</th><th>Total</th><th>Margin</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td style="font-family:var(--font-mono); font-weight:600;"><?= h($o['invoice_no']) ?></td>
          <td style="font-size:12px; color:var(--muted);"><?= h($jenisCfgAll[$o['jenis'] ?? 'utama']['short'] ?? 'Utama') ?></td>
          <td><?= fmt_tanggal($o['tanggal']) ?></td>
          <td><?= h($o['sppg_nama']) ?></td>
          <td style="font-family:var(--font-mono); font-weight:700;"><?= rupiah($o['total']) ?></td>
          <td style="font-family:var(--font-mono); color:var(--ok);"><?= rupiah($o['total_margin']) ?></td>
          <td><span class="badge <?= $o['status']==='Lunas'?'badge-ok':'badge-danger' ?>"><?= h($o['status']) ?></span></td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="invoice.php?id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Lihat →</a>
            <?php if ($o['status'] !== 'Lunas'): ?>
              <a href="edit_order.php?id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
            <?php endif; ?>
            <a href="riwayat.php?delete=<?= $o['id'] ?>&jenis=<?= h($jenisFilter) ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus order ini? Data gak bisa dibalikin.')">Hapus</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:12px 18px; border-top:2px solid var(--text); background:var(--bg); display:flex; justify-content:flex-end; gap:40px;">
      <div style="text-align:right;">
        <div style="font-size:11px; color:var(--muted);">TOTAL PENJUALAN</div>
        <div style="font-family:var(--font-mono); font-weight:700;"><?= rupiah(array_sum(array_column($orders, 'total'))) ?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:11px; color:var(--muted);">TOTAL MARGIN</div>
        <div style="font-family:var(--font-mono); font-weight:700; color:var(--ok);"><?= rupiah(array_sum(array_column($orders, 'total_margin'))) ?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>