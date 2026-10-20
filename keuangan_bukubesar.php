<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Buku Besar';

$akunList = $pdo->query("SELECT * FROM akun ORDER BY kode ASC")->fetchAll();

$akunId = (int)($_GET['akun_id'] ?? ($akunList[0]['id'] ?? 0));
$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$akunStmt = $pdo->prepare("SELECT * FROM akun WHERE id = ?");
$akunStmt->execute([$akunId]);
$akun = $akunStmt->fetch();

$transaksi = [];
$saldoAwal = 0;
$saldoAkhir = 0;

if ($akun) {
    // saldo sebelum tanggal "dari" jadi saldo awal periode
    $saldoAwal = get_saldo_akun($pdo, $akunId, $akun['saldo_normal'], date('Y-m-d', strtotime($dari . ' -1 day')));

    $stmt = $pdo->prepare("
        SELECT jd.debit, jd.kredit, jd.keterangan, ju.tanggal, ju.no_bukti, ju.sumber
        FROM jurnal_detail jd JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        WHERE jd.akun_id = ? AND ju.tanggal BETWEEN ? AND ?
        ORDER BY ju.tanggal ASC, ju.id ASC
    ");
    $stmt->execute([$akunId, $dari, $sampai]);
    $rows = $stmt->fetchAll();

    $saldoJalan = $saldoAwal;
    foreach ($rows as $r) {
        $mutasi = $akun['saldo_normal'] === 'Debit' ? ($r['debit'] - $r['kredit']) : ($r['kredit'] - $r['debit']);
        $saldoJalan += $mutasi;
        $transaksi[] = $r + ['saldo' => $saldoJalan];
    }
    $saldoAkhir = $saldoJalan;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Buku Besar</div>
    <div class="page-sub">Riwayat mutasi &amp; saldo berjalan tiap akun.</div>
  </div>
  <a href="export_excel.php?type=bukubesar&akun_id=<?= $akunId ?>&dari=<?= h($dari) ?>&sampai=<?= h($sampai) ?>" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-primary">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
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

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end; flex-wrap:wrap;">
  <div style="min-width:240px;">
    <label class="field-label">Pilih Akun</label>
    <select name="akun_id" onchange="this.form.submit()">
      <?php foreach ($akunList as $a): ?>
        <option value="<?= $a['id'] ?>" <?= $a['id'] == $akunId ? 'selected' : '' ?>><?= h($a['kode']) ?> — <?= h($a['nama']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="field-label">Dari Tanggal</label><input type="date" name="dari" value="<?= h($dari) ?>"></div>
  <div><label class="field-label">Sampai Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<?php if ($akun): ?>
<div class="card" style="overflow:hidden;">
  <div style="padding:16px 18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
    <div>
      <div style="font-weight:700; font-size:15px;"><?= h($akun['kode']) ?> — <?= h($akun['nama']) ?></div>
      <div style="font-size:12px; color:var(--muted);">Saldo normal: <?= h($akun['saldo_normal']) ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:11px; color:var(--muted);">SALDO AWAL</div>
      <div style="font-family:var(--font-mono); font-weight:700;"><?= rupiah($saldoAwal) ?></div>
    </div>
  </div>

  <?php if (empty($transaksi)): ?>
    <div class="empty-state"><div class="icon">📘</div><div class="title">Gak ada mutasi di periode ini</div></div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Kredit</th><th style="text-align:right;">Saldo</th></tr></thead>
    <tbody>
      <?php foreach ($transaksi as $t): ?>
      <tr>
        <td><?= fmt_tanggal($t['tanggal']) ?></td>
        <td style="font-family:var(--font-mono);"><?= h($t['no_bukti']) ?></td>
        <td><?= h($t['keterangan']) ?> <span class="badge badge-muted"><?= h($t['sumber']) ?></span></td>
        <td style="text-align:right; font-family:var(--font-mono);"><?= $t['debit'] > 0 ? rupiah($t['debit']) : '-' ?></td>
        <td style="text-align:right; font-family:var(--font-mono);"><?= $t['kredit'] > 0 ? rupiah($t['kredit']) : '-' ?></td>
        <td style="text-align:right; font-family:var(--font-mono); font-weight:700;"><?= rupiah($t['saldo']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div style="padding:14px 18px; border-top:2px solid var(--text); display:flex; justify-content:space-between; align-items:center; background:var(--bg);">
    <span style="font-weight:700;">SALDO AKHIR</span>
    <span style="font-family:var(--font-mono); font-weight:700; font-size:15px;"><?= rupiah($saldoAkhir) ?></span>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>