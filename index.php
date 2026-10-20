<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'dashboard';
$page_title = 'Dashboard';

$periode = $_GET['periode'] ?? 'bulan';
if (!in_array($periode, ['hari', 'minggu', 'bulan'])) $periode = 'bulan';

if ($periode === 'hari') {
    $dari = date('Y-m-d');
    $sampai = date('Y-m-d');
    $labelPeriode = 'Hari Ini';
} elseif ($periode === 'minggu') {
    $dari = date('Y-m-d', strtotime('monday this week'));
    $sampai = date('Y-m-d');
    $labelPeriode = 'Minggu Ini';
} else {
    $dari = date('Y-m-01');
    $sampai = date('Y-m-d');
    $labelPeriode = 'Bulan Ini';
}

$omzet = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM orders WHERE tanggal BETWEEN ? AND ?");
$omzet->execute([$dari, $sampai]);
$omzet = $omzet->fetch()['t'];

$margin = $pdo->prepare("SELECT COALESCE(SUM(total_margin),0) t FROM orders WHERE tanggal BETWEEN ? AND ?");
$margin->execute([$dari, $sampai]);
$margin = $margin->fetch()['t'];

$marginDiterima = $pdo->prepare("SELECT COALESCE(SUM(total_margin),0) t FROM orders WHERE tanggal BETWEEN ? AND ? AND status = 'Lunas'");
$marginDiterima->execute([$dari, $sampai]);
$marginDiterima = $marginDiterima->fetch()['t'];

$piutang = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM orders WHERE status != 'Lunas'")->fetch()['t'];

$totalMasuk = $pdo->query("
    SELECT COALESCE(SUM(jd.debit),0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode IN ('1-101','1-102')
")->fetch()['t'];
$totalKeluar = $pdo->query("
    SELECT COALESCE(SUM(jd.kredit),0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode IN ('1-101','1-102')
")->fetch()['t'];
$saldo = $totalMasuk - $totalKeluar;

// "Aman Diambil Prive" - biar gak ketuker modal/untung pas mau narik Prive. Laba
// bersih itu angka AKRUAL (diakui begitu invoice dibuat), BUKAN cerminan uang yang
// beneran udah ada di kas - sebagian bisa masih nyangkut di piutang yang belum
// ketagih. Jadi yang aman buat ditarik = yang paling KECIL di antara:
// (a) sisa "jatah" laba bersih yang belum pernah ditarik sebagai Prive, dan
// (b) Kas yang BENER-BENER bebas dipake - bukan cuma saldo Kas mentah, tapi udah
//     dikurangin Hutang Usaha yang masih nunggak ke supplier. Soalnya kalau gak
//     dikurangin, Prive bisa narik sampe abis, padahal duit buat bayar supplier
//     ikut kebawa kepake - itu yang bikin Rp13,5jt kemarin keliatan gede padahal
//     gak aman beneran ditarik semua.
$labaBersihKumulatif = get_laba_berjalan($pdo, date('Y-m-d'));
$totalPriveDiambil = (float)$pdo->query("
    SELECT COALESCE(SUM(jd.debit),0)-COALESCE(SUM(jd.kredit),0) t
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id
    WHERE a.kode = '3-102'
")->fetch()['t'];
$sisaJatahLaba = $labaBersihKumulatif - $totalPriveDiambil;

$hutangUsaha = (float)$pdo->query("
    SELECT COALESCE(SUM(jd.kredit),0)-COALESCE(SUM(jd.debit),0) t
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id
    WHERE a.kode = '2-101'
")->fetch()['t'];
$kasBebasDipake = max(0, $saldo - $hutangUsaha);
$amanDiambilPrive = max(0, min($sisaJatahLaba, $kasBebasDipake));

$orderTerbaru = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5")->fetchAll();

$sppgTeratas = $pdo->query("
    SELECT sppg_nama, SUM(total) total FROM orders GROUP BY sppg_nama ORDER BY total DESC LIMIT 4
")->fetchAll();

$stockMenipis = $pdo->query("SELECT * FROM stock WHERE stock_fisik <= 5 ORDER BY stock_fisik ASC LIMIT 6")->fetchAll();

$jumlahInsight = 0;
try {
    $konsumsi30 = $pdo->query("
        SELECT oi.nama, SUM(oi.qty) qty_terpakai FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY LOWER(TRIM(oi.nama))
    ")->fetchAll();
    $konsumsiMap30 = [];
    foreach ($konsumsi30 as $k) { $konsumsiMap30[strtolower(trim($k['nama']))] = (float)$k['qty_terpakai']; }
    foreach ($pdo->query("SELECT nama, stock_fisik FROM stock")->fetchAll() as $s) {
        $tp = $konsumsiMap30[strtolower(trim($s['nama']))] ?? 0;
        if ($tp > 0 && ($s['stock_fisik'] / ($tp / 30)) <= 7) $jumlahInsight++;
    }
    $netKas7 = $pdo->query("
        SELECT COALESCE(SUM(jd.debit),0)-COALESCE(SUM(jd.kredit),0) n FROM jurnal_detail jd
        JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        WHERE a.kode IN ('1-101','1-102') AND ju.tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetch()['n'];
    if ($netKas7 < 0) $jumlahInsight++;
    $jumlahInsight += (int)$pdo->query("SELECT COUNT(*) c FROM orders WHERE status != 'Lunas' AND DATEDIFF(CURDATE(), tanggal) >= 14")->fetch()['c'] > 0 ? 1 : 0;
} catch (Exception $e) { $jumlahInsight = 0; }

$namaHari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$namaBulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Dashboard</div>
    <div class="page-sub"><?php
      echo $namaHari[(int)date('w')] . ', ' . date('j') . ' ' . $namaBulan[(int)date('n')] . ' ' . date('Y');
    ?></div>
  </div>
</div>

<?php if ($jumlahInsight > 0): ?>
<a href="insight.php" class="card card-pad" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-left:4px solid var(--danger); text-decoration:none; color:inherit;">
  <div style="display:flex; align-items:center; gap:10px;">
    <span style="font-size:22px;">💡</span>
    <span style="font-weight:700;">Ada <?= $jumlahInsight ?> hal yang perlu diperhatiin</span>
    <span style="color:var(--muted); font-size:12.5px;">(stock, arus kas, atau piutang)</span>
  </div>
  <span style="color:var(--primary); font-weight:600; white-space:nowrap;">Lihat Insight →</span>
</a>
<?php endif; ?>

<div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:12px;">
  <a href="index.php?periode=hari" class="btn btn-sm <?= $periode === 'hari' ? 'btn-primary' : 'btn-subtle' ?>">Hari Ini</a>
  <a href="index.php?periode=minggu" class="btn btn-sm <?= $periode === 'minggu' ? 'btn-primary' : 'btn-subtle' ?>">Minggu Ini</a>
  <a href="index.php?periode=bulan" class="btn btn-sm <?= $periode === 'bulan' ? 'btn-primary' : 'btn-subtle' ?>">Bulan Ini</a>
</div>

<div class="stat-grid cols-4" style="margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-icon accent">↑</div>
    <div class="stat-label">Omzet <?= h($labelPeriode) ?></div>
    <div class="stat-value"><?= rupiah($omzet) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent">△</div>
    <div class="stat-label">Margin <?= h($labelPeriode) ?></div>
    <div class="stat-value"><?= rupiah($margin) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon ok">✓</div>
    <div class="stat-label">Margin Sudah Diterima</div>
    <div class="stat-value"><?= rupiah($marginDiterima) ?></div>
    <div style="font-size:11px; color:var(--muted); margin-top:4px;">Dari invoice yang udah Lunas aja</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">Rp</div>
    <div class="stat-label">Piutang Belum Bayar</div>
    <div class="stat-value"><?= rupiah($piutang) ?></div>
  </div>
</div>

<?php if ($sisaJatahLaba < 0): ?>
<div class="alert alert-danger" style="margin-bottom:16px;">
  ⚠ Prive yang udah ditarik (<?= rupiah($totalPriveDiambil) ?>) udah ngelewatin laba bersih kumulatif (<?= rupiah($labaBersihKumulatif) ?>) sebesar <b><?= rupiah(abs($sisaJatahLaba)) ?></b> — artinya sebagian udah makan modal, bukan cuma untung lagi.
</div>
<?php elseif ($hutangUsaha > 0 && $saldo <= $hutangUsaha): ?>
<div class="alert alert-danger" style="margin-bottom:16px;">
  ⚠ Saldo Kas (<?= rupiah($saldo) ?>) bahkan belum cukup buat nutup Hutang Usaha yang masih nunggak ke supplier (<?= rupiah($hutangUsaha) ?>) — jangan narik Prive dulu sebelum utang ini dibayar.
</div>
<?php endif; ?>

<div class="stat-grid cols-4" style="margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-icon accent">$</div>
    <div class="stat-label">Saldo Kas</div>
    <div class="stat-value"><?= rupiah($saldo) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $amanDiambilPrive > 0 ? 'ok' : 'danger' ?>">💸</div>
    <div class="stat-label">Aman Diambil Prive</div>
    <div class="stat-value" style="color:<?= $amanDiambilPrive > 0 ? 'var(--ok)' : 'var(--danger)' ?>;"><?= rupiah($amanDiambilPrive) ?></div>
    <div style="font-size:11px; color:var(--muted); margin-top:4px;">
      Sisa jatah laba: <?= rupiah($sisaJatahLaba) ?> · Kas bebas (udah dikurangin Hutang Usaha <?= rupiah($hutangUsaha) ?>): <?= rupiah($kasBebasDipake) ?>
    </div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1.4fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
      <div style="font-weight:700; font-size:15px;">Order Terbaru</div>
      <a href="order.php" class="btn btn-primary btn-sm">+ Buat Order</a>
    </div>
    <?php if (empty($orderTerbaru)): ?>
      <div style="font-size:13px; color:var(--muted);">Belum ada order.</div>
    <?php else: foreach ($orderTerbaru as $o): ?>
      <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border);">
        <div>
          <div style="font-weight:600; font-size:13.5px;"><?= h($o['sppg_nama']) ?></div>
          <div style="font-size:11.5px; color:var(--muted);"><?= h($o['invoice_no']) ?> · <?= fmt_tanggal($o['tanggal']) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:700; font-family:var(--font-mono);"><?= rupiah($o['total']) ?></div>
          <span class="badge <?= $o['status']==='Lunas'?'badge-ok':'badge-danger' ?>"><?= h($o['status']) ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div>
    <div class="card card-pad" style="margin-bottom:16px;">
      <div style="font-weight:700; font-size:14px; margin-bottom:10px;">SPPG Teratas</div>
      <?php if (empty($sppgTeratas)): ?>
        <div style="font-size:12.5px; color:var(--muted);">Belum ada data.</div>
      <?php else: foreach ($sppgTeratas as $i => $s): ?>
        <div style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px;">
          <span><?= $i+1 ?>. <?= h($s['sppg_nama']) ?></span>
          <span style="font-weight:600; font-family:var(--font-mono);"><?= rupiah($s['total']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card card-pad">
      <div style="font-weight:700; font-size:14px; margin-bottom:10px; color:var(--danger);">⚠ Stock Menipis</div>
      <?php if (empty($stockMenipis)): ?>
        <div style="font-size:12.5px; color:var(--muted);">Semua stock aman.</div>
      <?php else: foreach ($stockMenipis as $s): ?>
        <div style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px;">
          <span><?= h($s['nama']) ?></span>
          <span style="font-weight:700; color:var(--danger);"><?= $s['stock_fisik'] + 0 ?> <?= h($s['satuan']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>