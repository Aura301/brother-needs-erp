<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'keuangan';
$page_title = 'Keuangan';

// Uang Masuk / Keluar / Saldo dihitung langsung dari jurnal (akun Kas & Kas Kecil),
// jadi otomatis akurat - termasuk modal yang disetor, dan pembelian hutang gak ikut
// ke-anggep ngurangin kas (soalnya belum bayar tunai).
$totalMasuk = $pdo->query("
    SELECT COALESCE(SUM(jd.debit),0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode IN ('1-101','1-102')
")->fetch()['t'];
$totalKeluar = $pdo->query("
    SELECT COALESCE(SUM(jd.kredit),0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode IN ('1-101','1-102')
")->fetch()['t'];
$saldo = $totalMasuk - $totalKeluar;

$piutang = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM orders WHERE status != 'Lunas'")->fetch()['t'];
$hutang = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM pembelian WHERE metode_bayar = 'Hutang'")->fetch()['t'];

$jurnalTerbaru = $pdo->query("SELECT * FROM jurnal_umum ORDER BY tanggal DESC, id DESC LIMIT 6")->fetchAll();
$adaModal = (int)$pdo->query("SELECT COUNT(*) c FROM jurnal_umum WHERE sumber = 'Modal'")->fetch()['c'] > 0;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Keuangan</div>
    <div class="page-sub">Jurnal umum, pembelian, penjualan, petty cash, sampai laba rugi — semua kecatat otomatis.</div>
  </div>
  <a href="export_excel.php?type=lengkap" class="btn btn-ghost">⬇ Export Semua (Excel)</a>
</div>

<?php if (!$adaModal): ?>
  <div class="alert alert-info" style="display:flex; justify-content:space-between; align-items:center;">
    <span>👋 Baru pertama kali setup? Catat dulu <b>modal awal usaha</b> kamu biar Saldo Kas kehitung bener dari awal.</span>
    <a href="keuangan_modal.php" class="btn btn-primary btn-sm">+ Setor Modal Awal</a>
  </div>
<?php endif; ?>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-primary">Ringkasan</a>
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
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon ok">↑</div>
    <div class="stat-label">Total Uang Masuk</div>
    <div class="stat-value"><?= rupiah($totalMasuk) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Pengeluaran</div>
    <div class="stat-value"><?= rupiah($totalKeluar) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $saldo >= 0 ? 'ok' : 'danger' ?>">$</div>
    <div class="stat-label">Saldo Kas</div>
    <div class="stat-value"><?= rupiah($saldo) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent">≈</div>
    <div class="stat-label">Piutang / Hutang</div>
    <div class="stat-value" style="font-size:14px;"><?= rupiah($piutang) ?> / <?= rupiah($hutang) ?></div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:4px;">Jurnal Terbaru</div>
    <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">Semua transaksi otomatis tercatat di sini secara akuntansi berpasangan (debit = kredit).</div>
    <?php if (empty($jurnalTerbaru)): ?>
      <div class="empty-state"><div class="icon">📒</div><div class="title">Belum ada jurnal</div><div>Transaksi pertama kamu akan otomatis kecatat di sini.</div></div>
    <?php else: foreach ($jurnalTerbaru as $j): ?>
      <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--border); font-size:13px;">
        <div>
          <div style="font-weight:600;"><?= h($j['keterangan']) ?></div>
          <div style="font-size:11px; color:var(--muted); font-family:var(--font-mono);"><?= h($j['no_bukti']) ?> · <?= fmt_tanggal($j['tanggal']) ?></div>
        </div>
        <span class="badge badge-muted"><?= h($j['sumber']) ?></span>
      </div>
    <?php endforeach; endif; ?>
    <div style="margin-top:12px;"><a href="keuangan_jurnal.php" class="btn btn-ghost btn-sm">Lihat semua jurnal →</a></div>
  </div>

  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Ringkasan Modul Keuangan</div>
    <div style="display:flex; flex-direction:column; gap:10px; font-size:13px;">
      <a href="keuangan_modal.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>💰 Modal &amp; Kas — setor modal awal atau ambil prive</span><span>→</span>
      </a>
      <a href="keuangan_jurnal.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>📒 Jurnal Umum — semua transaksi debit/kredit</span><span>→</span>
      </a>
      <a href="keuangan_bukubesar.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>📘 Buku Besar — mutasi &amp; saldo tiap akun</span><span>→</span>
      </a>
      <a href="keuangan_neracasaldo.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>📊 Neraca Saldo — cek debit=kredit</span><span>→</span>
      </a>
      <a href="keuangan_neraca.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>⚖️ Neraca — Aset = Kewajiban + Modal</span><span>→</span>
      </a>
      <a href="keuangan_aruskas.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>💵 Arus Kas — tracking kas keluar-masuk per kategori</span><span>→</span>
      </a>
      <a href="keuangan_pembelian.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>🛍️ Pembelian — belanja ke supplier, otomatis nambah stock</span><span>→</span>
      </a>
      <a href="riwayat.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>🧾 Penjualan — riwayat invoice ke SPPG</span><span>→</span>
      </a>
      <a href="keuangan_petty_cash.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>💵 Petty Cash — pengeluaran kecil &amp; dadakan</span><span>→</span>
      </a>
      <a href="keuangan_beban.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>🧾 Beban Operasional — gaji, listrik, ongkir, maintenance</span><span>→</span>
      </a>
      <a href="keuangan_labarugi.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>📈 Laba Rugi — pendapatan dikurangi HPP &amp; beban</span><span>→</span>
      </a>
      <a href="keuangan_tren.php" style="display:flex; justify-content:space-between; padding:10px 12px; background:var(--bg); border-radius:8px;">
        <span>📊 Tren &amp; Perbandingan — omzet per hari/minggu/bulan/tahun</span><span>→</span>
      </a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>