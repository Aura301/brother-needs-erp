<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'insight';
$page_title = 'Insight & Rekomendasi';

$today = date('Y-m-d');

// ============================================================
// 1. STOCK BAKAL HABIS - berdasarkan rata-rata pemakaian 30 hari terakhir
// ============================================================
$konsumsi = $pdo->query("
    SELECT oi.nama, SUM(oi.qty) qty_terpakai
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE o.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY LOWER(TRIM(oi.nama))
")->fetchAll();
$konsumsiMap = [];
foreach ($konsumsi as $k) { $konsumsiMap[strtolower(trim($k['nama']))] = (float)$k['qty_terpakai']; }

$semuaStock = $pdo->query("SELECT * FROM stock ORDER BY nama ASC")->fetchAll();
$stockAkanHabis = [];
foreach ($semuaStock as $s) {
    $key = strtolower(trim($s['nama']));
    $terpakai30hari = $konsumsiMap[$key] ?? 0;
    if ($terpakai30hari <= 0) continue;
    $rataHarian = $terpakai30hari / 30;
    $sisaHari = $rataHarian > 0 ? $s['stock_fisik'] / $rataHarian : null;
    if ($sisaHari !== null && $sisaHari <= 7) {
        $stockAkanHabis[] = ['nama' => $s['nama'], 'sisa' => $s['stock_fisik'], 'satuan' => $s['satuan'], 'rata_harian' => $rataHarian, 'sisa_hari' => $sisaHari];
    }
}
usort($stockAkanHabis, fn($a, $b) => $a['sisa_hari'] <=> $b['sisa_hari']);

// ============================================================
// 2. ARUS KAS 7 HARI TERAKHIR - defisit atau sehat
// ============================================================
$arusKas7hari = $pdo->query("
    SELECT COALESCE(SUM(jd.debit),0) masuk, COALESCE(SUM(jd.kredit),0) keluar
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE a.kode IN ('1-101','1-102') AND ju.tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetch();
$netArusKas = (float)$arusKas7hari['masuk'] - (float)$arusKas7hari['keluar'];

// ============================================================
// 3. PIUTANG NUNGGAK - lebih dari 14 hari belum dibayar
// ============================================================
$piutangNunggak = $pdo->query("
    SELECT invoice_no, sppg_nama, tanggal, total, DATEDIFF(CURDATE(), tanggal) umur_hari
    FROM orders WHERE status != 'Lunas' AND DATEDIFF(CURDATE(), tanggal) >= 14
    ORDER BY umur_hari DESC
")->fetchAll();
$totalPiutangNunggak = array_sum(array_column($piutangNunggak, 'total'));

// ============================================================
// 4. TREN MARGIN - bulan ini vs rata-rata 3 bulan sebelumnya
// ============================================================
$marginBulanIni = $pdo->query("
    SELECT COALESCE(SUM(total_margin),0) m, COALESCE(SUM(total),0) o
    FROM orders WHERE DATE_FORMAT(tanggal,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
")->fetch();
$persenBulanIni = $marginBulanIni['o'] > 0 ? ($marginBulanIni['m'] / $marginBulanIni['o']) * 100 : null;

$margin3BulanLalu = $pdo->query("
    SELECT COALESCE(SUM(total_margin),0) m, COALESCE(SUM(total),0) o
    FROM orders
    WHERE tanggal >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 3 MONTH)
      AND tanggal < DATE_FORMAT(CURDATE(),'%Y-%m-01')
")->fetch();
$persen3BulanLalu = $margin3BulanLalu['o'] > 0 ? ($margin3BulanLalu['m'] / $margin3BulanLalu['o']) * 100 : null;

$marginTurun = ($persenBulanIni !== null && $persen3BulanLalu !== null && $persenBulanIni < $persen3BulanLalu - 3);

// ============================================================
// 5. PROYEKSI BUDGET PETTY CASH - bakal jebol sebelum akhir bulan?
// ============================================================
$company = get_company($pdo);
$budgetPettyCash = (float)($company['petty_cash_budget'] ?? 0);
$terpakaiBulanIni = $pdo->query("
    SELECT COALESCE(SUM(jumlah),0) t FROM expenses
    WHERE jenis = 'Petty Cash' AND DATE_FORMAT(tanggal,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
")->fetch()['t'];
$hariBerjalan = (int)date('j');
$hariDalamBulan = (int)date('t');
$proyeksiPettyCash = $hariBerjalan > 0 ? ($terpakaiBulanIni / $hariBerjalan) * $hariDalamBulan : 0;
$pettyCashAkanJebol = $budgetPettyCash > 0 && $proyeksiPettyCash > $budgetPettyCash;

$totalInsight = count($stockAkanHabis) + ($netArusKas < 0 ? 1 : 0) + (count($piutangNunggak) > 0 ? 1 : 0) + ($marginTurun ? 1 : 0) + ($pettyCashAkanJebol ? 1 : 0);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Insight & Rekomendasi</div>
    <div class="page-sub">Analisis otomatis dari histori transaksi - bukan AI, cuma rumus statistik yang ngecek pola data kamu.</div>
  </div>
</div>

<?php if ($totalInsight === 0): ?>
<div class="card card-pad" style="text-align:center; padding:40px;">
  <div style="font-size:36px; margin-bottom:10px;">✅</div>
  <div style="font-weight:700; font-size:16px;">Semua kelihatan sehat!</div>
  <div style="color:var(--muted); font-size:13px; margin-top:4px;">Gak ada peringatan yang perlu diperhatiin sekarang.</div>
</div>
<?php endif; ?>

<?php if (!empty($stockAkanHabis)): ?>
<div class="card card-pad" style="margin-bottom:16px; border-left:4px solid var(--danger);">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">📦 Stock Bakal Habis (<?= count($stockAkanHabis) ?>)</div>
  <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">Berdasarkan rata-rata pemakaian 30 hari terakhir.</div>
  <?php foreach ($stockAkanHabis as $s): ?>
    <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border);">
      <div>
        <span style="font-weight:600;"><?= h($s['nama']) ?></span>
        <span style="font-size:12px; color:var(--muted);"> — sisa <?= number_format($s['sisa'], 1) ?> <?= h($s['satuan']) ?>, biasanya kepake ~<?= number_format($s['rata_harian'], 1) ?> <?= h($s['satuan']) ?>/hari</span>
      </div>
      <span style="font-weight:700; color:<?= $s['sisa_hari'] <= 3 ? 'var(--danger)' : 'var(--accent, #b8860b)' ?>; white-space:nowrap;">
        ~<?= round($s['sisa_hari']) ?> hari lagi
      </span>
    </div>
  <?php endforeach; ?>
  <div style="font-size:12px; color:var(--muted); margin-top:10px;">💡 Saatnya restock lewat <a href="keuangan_pembelian.php?tambah=1" style="color:var(--primary); font-weight:600;">Catat Pembelian</a>.</div>
</div>
<?php endif; ?>

<?php if ($netArusKas < 0): ?>
<div class="card card-pad" style="margin-bottom:16px; border-left:4px solid var(--danger);">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">💵 Arus Kas 7 Hari Terakhir Minus</div>
  <div style="font-size:13px;">Kas keluar <b style="color:var(--danger);"><?= rupiah($arusKas7hari['keluar']) ?></b> lebih besar dari kas masuk <b><?= rupiah($arusKas7hari['masuk']) ?></b> — selisih <b style="color:var(--danger);"><?= rupiah(abs($netArusKas)) ?></b>.</div>
  <div style="font-size:12px; color:var(--muted); margin-top:8px;">💡 Cek <a href="keuangan_aruskas.php" style="color:var(--primary); font-weight:600;">Arus Kas</a> buat lihat kas keluar itu buat apa aja, mungkin ada pengeluaran yang bisa ditunda.</div>
</div>
<?php endif; ?>

<?php if (!empty($piutangNunggak)): ?>
<div class="card card-pad" style="margin-bottom:16px; border-left:4px solid var(--danger);">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">⏰ Piutang Nunggak Lebih dari 14 Hari (<?= count($piutangNunggak) ?>)</div>
  <div style="font-size:13px; margin-bottom:10px;">Total <b style="color:var(--danger);"><?= rupiah($totalPiutangNunggak) ?></b> belum dibayar, udah lewat 2 minggu.</div>
  <?php foreach ($piutangNunggak as $p): ?>
    <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border); font-size:13px;">
      <span><?= h($p['invoice_no']) ?> — <?= h($p['sppg_nama']) ?></span>
      <span><b><?= rupiah($p['total']) ?></b> <span style="color:var(--danger); font-size:11.5px;">(<?= $p['umur_hari'] ?> hari)</span></span>
    </div>
  <?php endforeach; ?>
  <div style="font-size:12px; color:var(--muted); margin-top:10px;">💡 Mungkin waktunya follow-up ke SPPG-nya buat nagih.</div>
</div>
<?php endif; ?>

<?php if ($marginTurun): ?>
<div class="card card-pad" style="margin-bottom:16px; border-left:4px solid var(--danger);">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">📉 Margin % Bulan Ini Lebih Rendah</div>
  <div style="font-size:13px;">Margin bulan ini <b><?= number_format($persenBulanIni, 1) ?>%</b> dari omzet, dibanding rata-rata 3 bulan sebelumnya <b><?= number_format($persen3BulanLalu, 1) ?>%</b>.</div>
  <div style="font-size:12px; color:var(--muted); margin-top:8px;">💡 Cek harga modal produk yang sering dijual, mungkin ada yang naik tapi harga jualnya belum disesuaikan.</div>
</div>
<?php endif; ?>

<?php if ($pettyCashAkanJebol): ?>
<div class="card card-pad" style="margin-bottom:16px; border-left:4px solid var(--danger);">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">💰 Budget Petty Cash Bakal Kelebihan</div>
  <div style="font-size:13px;">Baru tanggal <?= $hariBerjalan ?> dari <?= $hariDalamBulan ?>, udah kepake <b><?= rupiah($terpakaiBulanIni) ?></b>. Kalau rate-nya segini terus, proyeksi akhir bulan <b style="color:var(--danger);"><?= rupiah($proyeksiPettyCash) ?></b> — lebih dari budget <b><?= rupiah($budgetPettyCash) ?></b>.</div>
  <div style="font-size:12px; color:var(--muted); margin-top:8px;">💡 Kurangin pengeluaran kecil bulan ini, atau naikin budget kalau memang wajar segitu.</div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>