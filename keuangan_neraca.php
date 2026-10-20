<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Neraca';

$per = $_GET['per'] ?? date('Y-m-d');

$akunAset = $pdo->query("SELECT * FROM akun WHERE tipe = 'Aset' ORDER BY kode ASC")->fetchAll();
$akunKewajiban = $pdo->query("SELECT * FROM akun WHERE tipe = 'Kewajiban' ORDER BY kode ASC")->fetchAll();
$akunModal = $pdo->query("SELECT * FROM akun WHERE tipe = 'Modal' ORDER BY kode ASC")->fetchAll();

$asetRows = []; $totalAset = 0;
foreach ($akunAset as $a) {
    $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per);
    $totalAset += $saldo;
    $asetRows[] = ['nama' => $a['nama'], 'saldo' => $saldo];
}

$kewajibanRows = []; $totalKewajiban = 0;
foreach ($akunKewajiban as $a) {
    $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per);
    $totalKewajiban += $saldo;
    $kewajibanRows[] = ['nama' => $a['nama'], 'saldo' => $saldo];
}

$modalRows = []; $totalModalDasar = 0;
foreach ($akunModal as $a) {
    $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per);
    // Prive (saldo normal Debit di tabel akun) ngurangin modal, jadi dibalik tandanya di sini
    $nilai = $a['kode'] === '3-102' ? -$saldo : $saldo;
    $totalModalDasar += $nilai;
    $modalRows[] = ['nama' => $a['nama'], 'saldo' => $nilai];
}

$labaBerjalan = get_laba_berjalan($pdo, $per);
$totalModal = $totalModalDasar + $labaBerjalan;
$totalKewajibanModal = $totalKewajiban + $totalModal;
$selisih = round($totalAset - $totalKewajibanModal, 2);
$balanced = abs($selisih) < 1;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Neraca</div>
    <div class="page-sub">Posisi keuangan usaha: Aset = Kewajiban + Modal.</div>
  </div>
  <a href="export_excel.php?type=neraca&per=<?= h($per) ?>" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-primary">Neraca</a>
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
  <div><label class="field-label">Per Tanggal</label><input type="date" name="per" value="<?= h($per) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="alert <?= $balanced ? 'alert-ok' : 'alert-danger' ?>">
  <?= $balanced ? '✓ Balance — Total Aset sama dengan Total Kewajiban + Modal.' : '⚠ Selisih ' . rupiah(abs($selisih)) . ' antara Aset dan Kewajiban+Modal — cek jurnal manual yang mungkin gak seimbang.' ?>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px; color:var(--primary);">ASET</div>
    <?php foreach ($asetRows as $r): ?>
      <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px;">
        <span><?= h($r['nama']) ?></span>
        <span style="font-family:var(--font-mono); font-weight:600;"><?= rupiah($r['saldo']) ?></span>
      </div>
    <?php endforeach; ?>
    <div style="display:flex; justify-content:space-between; padding:12px 0 0; font-weight:700; font-size:14.5px;">
      <span>TOTAL ASET</span>
      <span style="font-family:var(--font-mono);"><?= rupiah($totalAset) ?></span>
    </div>
  </div>

  <div>
    <div class="card card-pad" style="margin-bottom:16px;">
      <div style="font-weight:700; font-size:15px; margin-bottom:12px; color:var(--danger);">KEWAJIBAN</div>
      <?php if (empty($kewajibanRows)): ?>
        <div style="font-size:13px; color:var(--muted);">Gak ada hutang.</div>
      <?php else: foreach ($kewajibanRows as $r): ?>
        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px;">
          <span><?= h($r['nama']) ?></span>
          <span style="font-family:var(--font-mono); font-weight:600;"><?= rupiah($r['saldo']) ?></span>
        </div>
      <?php endforeach; endif; ?>
      <div style="display:flex; justify-content:space-between; padding:12px 0 0; font-weight:700; font-size:14.5px;">
        <span>TOTAL KEWAJIBAN</span>
        <span style="font-family:var(--font-mono);"><?= rupiah($totalKewajiban) ?></span>
      </div>
    </div>

    <div class="card card-pad">
      <div style="font-weight:700; font-size:15px; margin-bottom:12px; color:var(--ok);">MODAL</div>
      <?php foreach ($modalRows as $r): ?>
        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px;">
          <span><?= h($r['nama']) ?></span>
          <span style="font-family:var(--font-mono); font-weight:600;"><?= rupiah($r['saldo']) ?></span>
        </div>
      <?php endforeach; ?>
      <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px;">
        <span>Laba Ditahan / Berjalan</span>
        <span style="font-family:var(--font-mono); font-weight:600;"><?= rupiah($labaBerjalan) ?></span>
      </div>
      <div style="display:flex; justify-content:space-between; padding:12px 0 0; font-weight:700; font-size:14.5px;">
        <span>TOTAL MODAL</span>
        <span style="font-family:var(--font-mono);"><?= rupiah($totalModal) ?></span>
      </div>
    </div>
  </div>
</div>

<div class="card card-pad" style="margin-top:16px; background:var(--primary);">
  <div style="display:flex; justify-content:space-between; align-items:center; color:#fff;">
    <span style="font-weight:700;">TOTAL KEWAJIBAN + MODAL</span>
    <span style="font-family:var(--font-mono); font-weight:700; font-size:16px;"><?= rupiah($totalKewajibanModal) ?></span>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>