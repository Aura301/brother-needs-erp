<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'keuangan';
$page_title = 'Laba Rugi';

$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT a.id AS akun_id, a.kode, a.nama AS akun_nama, a.tipe,
           jd.debit, jd.kredit, jd.keterangan, ju.tanggal, ju.no_bukti
    FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id
    JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE ju.tanggal BETWEEN ? AND ? AND a.tipe IN ('Pendapatan','HPP','Beban')
    ORDER BY a.kode ASC, ju.tanggal ASC
");
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

$grouped = ['Pendapatan' => [], 'HPP' => [], 'Beban' => []];
foreach ($rows as $r) {
    $grouped[$r['tipe']][$r['akun_id']]['nama'] = $r['kode'] . ' — ' . $r['akun_nama'];
    $grouped[$r['tipe']][$r['akun_id']]['detail'][] = $r;
    $net = $r['tipe'] === 'Pendapatan' ? ($r['kredit'] - $r['debit']) : ($r['debit'] - $r['kredit']);
    $grouped[$r['tipe']][$r['akun_id']]['total'] = ($grouped[$r['tipe']][$r['akun_id']]['total'] ?? 0) + $net;
}

$totalPendapatan = array_sum(array_column($grouped['Pendapatan'], 'total'));
$totalHpp = array_sum(array_column($grouped['HPP'], 'total'));
$labaKotor = $totalPendapatan - $totalHpp;
$totalBeban = array_sum(array_column($grouped['Beban'], 'total'));
$labaBersih = $labaKotor - $totalBeban;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Laba Rugi</div>
    <div class="page-sub">Dihitung otomatis dari jurnal umum periode berjalan.</div>
  </div>
  <a href="export_excel.php?type=labarugi&dari=<?= h($dari) ?>&sampai=<?= h($sampai) ?>" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-subtle">Neraca</a>
  <a href="keuangan_aruskas.php" class="btn btn-subtle">Arus Kas</a>
  <a href="keuangan_hutang.php" class="btn btn-subtle">Hutang</a>
  <a href="keuangan_pembelian.php" class="btn btn-subtle">Pembelian</a>
  <a href="riwayat.php" class="btn btn-subtle">Penjualan</a>
  <a href="keuangan_petty_cash.php" class="btn btn-subtle">Petty Cash</a>
  <a href="keuangan_beban.php" class="btn btn-subtle">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-primary">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<?php
$today = date('Y-m-d');
$awalMinggu = date('Y-m-d', strtotime('monday this week'));
$awalBulan = date('Y-m-01');
$awalTahun = date('Y-01-01');
$periodePresets = [
    'Hari Ini' => [$today, $today],
    'Minggu Ini' => [$awalMinggu, $today],
    'Bulan Ini' => [$awalBulan, $today],
    'Tahun Ini' => [$awalTahun, $today],
];
?>
<div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
  <?php foreach ($periodePresets as $label => $range): $aktif = ($dari === $range[0] && $sampai === $range[1]); ?>
    <a href="keuangan_labarugi.php?dari=<?= $range[0] ?>&sampai=<?= $range[1] ?>" class="btn btn-sm <?= $aktif ? 'btn-primary' : 'btn-subtle' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end;">
  <div><label class="field-label">Dari Tanggal</label><input type="date" name="dari" value="<?= h($dari) ?>"></div>
  <div><label class="field-label">Sampai Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="stat-grid cols-3">
  <div class="stat-card">
    <div class="stat-icon ok">↑</div>
    <div class="stat-label">Laba Kotor</div>
    <div class="stat-value"><?= rupiah($labaKotor) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Beban</div>
    <div class="stat-value"><?= rupiah($totalBeban) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $labaBersih >= 0 ? 'ok' : 'danger' ?>">Σ</div>
    <div class="stat-label">Laba Bersih</div>
    <div class="stat-value"><?= rupiah($labaBersih) ?></div>
  </div>
</div>

<div class="card card-pad">
  <table class="data-table" style="margin-bottom:0;">
    <tbody>
      <tr style="background:var(--bg);"><td colspan="2" style="font-weight:700;">PENDAPATAN</td></tr>
      <?php foreach ($grouped['Pendapatan'] as $akun): ?>
        <tr><td style="padding-left:28px;"><?= h($akun['nama']) ?></td><td style="text-align:right; font-family:var(--font-mono);"><?= rupiah($akun['total']) ?></td></tr>
      <?php endforeach; ?>
      <tr><td style="font-weight:700;">Total Pendapatan</td><td style="text-align:right; font-weight:700; font-family:var(--font-mono);"><?= rupiah($totalPendapatan) ?></td></tr>

      <tr style="background:var(--bg);"><td colspan="2" style="font-weight:700;">HARGA POKOK PENJUALAN</td></tr>
      <?php foreach ($grouped['HPP'] as $akun): ?>
        <tr><td style="padding-left:28px;"><?= h($akun['nama']) ?></td><td style="text-align:right; font-family:var(--font-mono);">(<?= rupiah($akun['total']) ?>)</td></tr>
      <?php endforeach; ?>
      <tr><td style="font-weight:700;">Total HPP</td><td style="text-align:right; font-weight:700; font-family:var(--font-mono);">(<?= rupiah($totalHpp) ?>)</td></tr>

      <tr style="border-top:2px solid var(--text);"><td style="font-weight:700;">LABA KOTOR</td><td style="text-align:right; font-weight:700; font-family:var(--font-mono);"><?= rupiah($labaKotor) ?></td></tr>

      <tr style="background:var(--bg);"><td colspan="2" style="font-weight:700;">BEBAN OPERASIONAL</td></tr>
      <?php foreach ($grouped['Beban'] as $akun): ?>
        <tr><td style="padding-left:28px;"><?= h($akun['nama']) ?></td><td style="text-align:right; font-family:var(--font-mono);">(<?= rupiah($akun['total']) ?>)</td></tr>
      <?php endforeach; ?>
      <tr><td style="font-weight:700;">Total Beban</td><td style="text-align:right; font-weight:700; font-family:var(--font-mono);">(<?= rupiah($totalBeban) ?>)</td></tr>

      <tr style="border-top:2px solid var(--text); background:var(--primary);">
        <td style="font-weight:700; color:#fff; padding:12px 16px;">LABA BERSIH</td>
        <td style="text-align:right; font-weight:700; font-family:var(--font-mono); color:#fff; padding:12px 16px;"><?= rupiah($labaBersih) ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div style="font-weight:700; font-size:16px; margin:26px 0 12px;">Rincian Transaksi</div>
<?php foreach (['Pendapatan' => 'Pendapatan', 'HPP' => 'Harga Pokok Penjualan', 'Beban' => 'Beban Operasional'] as $tipe => $label): ?>
  <?php foreach ($grouped[$tipe] as $akun): if (empty($akun['detail'])) continue; ?>
    <div class="card card-pad" style="margin-bottom:12px;">
      <div style="font-weight:700; font-size:13.5px; margin-bottom:8px;"><?= h($akun['nama']) ?> <span style="font-weight:400; color:var(--muted);">(<?= $label ?>)</span></div>
      <table class="data-table" style="font-size:12.5px;">
        <thead><tr><th>Tanggal</th><th>No. Bukti</th><th>Keterangan</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Kredit</th></tr></thead>
        <tbody>
          <?php foreach ($akun['detail'] as $d): ?>
          <tr>
            <td><?= fmt_tanggal($d['tanggal']) ?></td>
            <td style="font-family:var(--font-mono);"><?= h($d['no_bukti']) ?></td>
            <td><?= h($d['keterangan']) ?></td>
            <td style="text-align:right; font-family:var(--font-mono);"><?= $d['debit'] > 0 ? rupiah($d['debit']) : '-' ?></td>
            <td style="text-align:right; font-family:var(--font-mono);"><?= $d['kredit'] > 0 ? rupiah($d['kredit']) : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php if (empty($rows)): ?>
  <div class="empty-state"><div class="icon">📈</div><div class="title">Belum ada transaksi di periode ini</div></div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>