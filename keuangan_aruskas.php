<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Arus Kas';

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

$dari = $_GET['dari'] ?? $awalBulan;
$sampai = $_GET['sampai'] ?? $today;

// Saldo Kas + Kas Kecil SEBELUM tanggal "dari" - jadi Saldo Awal periode
$saldoAwalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(jd.debit) - SUM(jd.kredit),0) t
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE a.kode IN ('1-101','1-102') AND ju.tanggal < ?
");
$saldoAwalStmt->execute([$dari]);
$saldoAwal = (float)$saldoAwalStmt->fetch()['t'];

// Semua transaksi yang nyentuh Kas/Kas Kecil dalam periode - ini otomatis kebaca
// dari jurnal yang udah ada dari AWAL PAKAI APLIKASI, gak cuma transaksi baru.
$stmt = $pdo->prepare("
    SELECT ju.tanggal, ju.no_bukti, ju.keterangan, ju.sumber, a.kode AS akun_kode, a.nama AS akun_nama, jd.debit, jd.kredit
    FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id
    JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE a.kode IN ('1-101','1-102') AND ju.tanggal BETWEEN ? AND ?
    ORDER BY ju.tanggal ASC, ju.id ASC
");
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

$saldoJalan = $saldoAwal;
$transaksi = [];
$breakdownMasuk = [];
$breakdownKeluar = [];
foreach ($rows as $r) {
    $mutasi = (float)$r['debit'] - (float)$r['kredit'];
    $saldoJalan += $mutasi;
    $transaksi[] = $r + ['saldo' => $saldoJalan];
    if ($mutasi >= 0) {
        $breakdownMasuk[$r['sumber']] = ($breakdownMasuk[$r['sumber']] ?? 0) + $mutasi;
    } else {
        $breakdownKeluar[$r['sumber']] = ($breakdownKeluar[$r['sumber']] ?? 0) + abs($mutasi);
    }
}
$saldoAkhir = $saldoJalan;
$totalMasuk = array_sum($breakdownMasuk);
$totalKeluar = array_sum($breakdownKeluar);
arsort($breakdownKeluar);
arsort($breakdownMasuk);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Arus Kas</div>
    <div class="page-sub">Tracking uang masuk &amp; keluar dari Kas + Kas Kecil, lengkap sama kategorinya.</div>
  </div>
  <a href="export_excel.php?type=aruskas&dari=<?= h($dari) ?>&sampai=<?= h($sampai) ?>" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-subtle">Neraca</a>
  <a href="keuangan_aruskas.php" class="btn btn-primary">Arus Kas</a>
  <a href="keuangan_hutang.php" class="btn btn-subtle">Hutang</a>
  <a href="keuangan_pembelian.php" class="btn btn-subtle">Pembelian</a>
  <a href="riwayat.php" class="btn btn-subtle">Penjualan</a>
  <a href="keuangan_petty_cash.php" class="btn btn-subtle">Petty Cash</a>
  <a href="keuangan_beban.php" class="btn btn-subtle">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
  <?php foreach ($periodePresets as $label => $range): $aktif = ($dari === $range[0] && $sampai === $range[1]); ?>
    <a href="keuangan_aruskas.php?dari=<?= $range[0] ?>&sampai=<?= $range[1] ?>" class="btn btn-sm <?= $aktif ? 'btn-primary' : 'btn-subtle' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end;">
  <div><label class="field-label">Dari Tanggal</label><input type="date" name="dari" value="<?= h($dari) ?>"></div>
  <div><label class="field-label">Sampai Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-label" style="margin-bottom:10px;">Saldo Awal</div>
    <div class="stat-value"><?= rupiah($saldoAwal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon ok">↑</div>
    <div class="stat-label">Total Masuk</div>
    <div class="stat-value"><?= rupiah($totalMasuk) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Keluar</div>
    <div class="stat-value"><?= rupiah($totalKeluar) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent">$</div>
    <div class="stat-label">Saldo Akhir</div>
    <div class="stat-value"><?= rupiah($saldoAkhir) ?></div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:14px; margin-bottom:10px; color:var(--danger);">↓ Kas Keluar Buat Apa Aja</div>
    <?php if (empty($breakdownKeluar)): ?>
      <div style="font-size:12.5px; color:var(--muted);">Gak ada kas keluar di periode ini.</div>
    <?php else: foreach ($breakdownKeluar as $sumber => $jml): ?>
      <div style="display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--border); font-size:13px;">
        <span><?= h($sumber) ?></span>
        <span style="font-family:var(--font-mono); font-weight:600; color:var(--danger);"><?= rupiah($jml) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="card card-pad">
    <div style="font-weight:700; font-size:14px; margin-bottom:10px; color:var(--ok);">↑ Kas Masuk Dari Mana Aja</div>
    <?php if (empty($breakdownMasuk)): ?>
      <div style="font-size:12.5px; color:var(--muted);">Gak ada kas masuk di periode ini.</div>
    <?php else: foreach ($breakdownMasuk as $sumber => $jml): ?>
      <div style="display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--border); font-size:13px;">
        <span><?= h($sumber) ?></span>
        <span style="font-family:var(--font-mono); font-weight:600; color:var(--ok);"><?= rupiah($jml) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="card" style="overflow:hidden;">
  <div style="padding:14px 18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between;">
    <div style="font-weight:700; font-size:14px;">Rincian Transaksi</div>
    <div style="font-size:11px; color:var(--muted);">SALDO AWAL: <b style="color:var(--text); font-family:var(--font-mono);"><?= rupiah($saldoAwal) ?></b></div>
  </div>
  <?php if (empty($transaksi)): ?>
    <div class="empty-state"><div class="icon">💵</div><div class="title">Gak ada mutasi kas di periode ini</div></div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Akun</th><th style="text-align:right;">Masuk</th><th style="text-align:right;">Keluar</th><th style="text-align:right;">Saldo</th></tr></thead>
    <tbody>
      <?php foreach ($transaksi as $t): ?>
      <tr>
        <td><?= fmt_tanggal($t['tanggal']) ?></td>
        <td><?= h($t['keterangan']) ?> <span class="badge badge-muted"><?= h($t['sumber']) ?></span></td>
        <td style="font-size:12px; color:var(--muted);"><?= h($t['akun_nama']) ?></td>
        <td style="text-align:right; font-family:var(--font-mono); color:var(--ok);"><?= $t['debit'] > 0 ? rupiah($t['debit']) : '-' ?></td>
        <td style="text-align:right; font-family:var(--font-mono); color:var(--danger);"><?= $t['kredit'] > 0 ? rupiah($t['kredit']) : '-' ?></td>
        <td style="text-align:right; font-family:var(--font-mono); font-weight:700;"><?= rupiah($t['saldo']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div style="padding:14px 18px; border-top:2px solid var(--text); background:var(--bg); display:flex; justify-content:space-between; align-items:center;">
    <span style="font-weight:700;">SALDO AKHIR</span>
    <span style="font-family:var(--font-mono); font-weight:700; font-size:15px;"><?= rupiah($saldoAkhir) ?></span>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>