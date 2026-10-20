<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Neraca Saldo';

$sampai = $_GET['sampai'] ?? date('Y-m-d');

$akunList = $pdo->query("SELECT * FROM akun ORDER BY kode ASC")->fetchAll();

$rows = [];
$totalDebit = 0;
$totalKredit = 0;

foreach ($akunList as $a) {
    $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $sampai);
    if (abs($saldo) < 0.005) continue; // skip akun yang saldonya 0, biar gak penuh
    $debit = $a['saldo_normal'] === 'Debit' ? $saldo : 0;
    $kredit = $a['saldo_normal'] === 'Kredit' ? $saldo : 0;
    $totalDebit += $debit;
    $totalKredit += $kredit;
    $rows[] = ['kode' => $a['kode'], 'nama' => $a['nama'], 'debit' => $debit, 'kredit' => $kredit];
}

$balanced = round($totalDebit, 2) === round($totalKredit, 2);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Neraca Saldo</div>
    <div class="page-sub">Cek kesehatan pembukuan — total debit harus sama dengan total kredit.</div>
  </div>
  <a href="export_excel.php?type=neracasaldo&sampai=<?= h($sampai) ?>" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-primary">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-subtle">Neraca</a>
  <a href="keuangan_aruskas.php" class="btn btn-subtle">Arus Kas</a>
  <a href="keuangan_hutang.php" class="btn btn-subtle">Hutang</a>
  <a href="keuangan_pembelian.php" class="btn btn-subtle">Pembelian</a>
  <a href="riwayat.php" class="btn btn-subtle">Penjualan</a>
  <a href="keuangan_petty_cash.php" class="btn btn-subtle">Petty Cash</a>
  <a href="keuangan_beban.php" class="btn btn-subtle">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end;">
  <div><label class="field-label">Per Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="alert <?= $balanced ? 'alert-ok' : 'alert-danger' ?>">
  <?= $balanced ? '✓ Balance — total debit sama dengan total kredit, pembukuan sehat.' : '⚠ Gak balance! Ada selisih antara total debit dan kredit, kemungkinan ada jurnal yang gak seimbang.' ?>
</div>

<div class="card" style="overflow:hidden;">
  <table class="data-table">
    <thead><tr><th>Kode</th><th>Nama Akun</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Kredit</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-family:var(--font-mono);"><?= h($r['kode']) ?></td>
        <td style="font-weight:600;"><?= h($r['nama']) ?></td>
        <td style="text-align:right; font-family:var(--font-mono);"><?= $r['debit'] > 0 ? rupiah($r['debit']) : '-' ?></td>
        <td style="text-align:right; font-family:var(--font-mono);"><?= $r['kredit'] > 0 ? rupiah($r['kredit']) : '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="padding:14px 18px; border-top:2px solid var(--text); background:var(--bg); display:flex; justify-content:flex-end; gap:40px;">
    <div style="text-align:right;">
      <div style="font-size:11px; color:var(--muted);">TOTAL DEBIT</div>
      <div style="font-family:var(--font-mono); font-weight:700; font-size:15px;"><?= rupiah($totalDebit) ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:11px; color:var(--muted);">TOTAL KREDIT</div>
      <div style="font-family:var(--font-mono); font-weight:700; font-size:15px;"><?= rupiah($totalKredit) ?></div>
    </div>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="empty-state"><div class="icon">📊</div><div class="title">Belum ada transaksi sampai tanggal ini</div></div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>