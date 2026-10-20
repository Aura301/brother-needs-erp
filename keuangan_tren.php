<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'keuangan';
$page_title = 'Tren & Perbandingan';

$bulanNama = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

$granularitas = $_GET['g'] ?? 'bulanan';
if (!in_array($granularitas, ['harian', 'mingguan', 'bulanan', 'tahunan'], true)) $granularitas = 'bulanan';

// default rentang tanggal, beda-beda enaknya tergantung granularitas
$today = date('Y-m-d');
if ($granularitas === 'harian') {
    $defaultDari = date('Y-m-d', strtotime('-29 days'));
} elseif ($granularitas === 'mingguan') {
    $defaultDari = date('Y-m-d', strtotime('-83 days')); // ~12 minggu
} elseif ($granularitas === 'tahunan') {
    $defaultDari = date('Y-m-d', strtotime('-4 years'));
} else {
    $defaultDari = date('Y-01-01'); // bulanan (default)
}
$dari = $_GET['dari'] ?? $defaultDari;
$sampai = $_GET['sampai'] ?? $today;

$stmt = $pdo->prepare("
    SELECT ju.tanggal, a.tipe, jd.debit, jd.kredit
    FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id
    JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE ju.tanggal BETWEEN ? AND ? AND a.tipe IN ('Pendapatan','HPP','Beban')
    ORDER BY ju.tanggal ASC
");
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

function periode_key_label($tanggal, $granularitas, $bulanNama) {
    $ts = strtotime($tanggal);
    if ($granularitas === 'harian') {
        return [$tanggal, date('d', $ts) . ' ' . $bulanNama[(int)date('n', $ts)] . ' ' . date('Y', $ts)];
    }
    if ($granularitas === 'mingguan') {
        $monday = date('Y-m-d', strtotime('monday this week', $ts));
        $sunday = date('Y-m-d', strtotime('sunday this week', $ts));
        $label = date('d', strtotime($monday)) . '-' . date('d M', strtotime($sunday));
        return [$monday, $label];
    }
    if ($granularitas === 'tahunan') {
        return [date('Y', $ts), date('Y', $ts)];
    }
    // bulanan (default)
    return [date('Y-m', $ts), $bulanNama[(int)date('n', $ts)] . ' ' . date('Y', $ts)];
}

$periods = []; // key => ['label'=>.., 'pendapatan'=>0, 'hpp'=>0, 'beban'=>0]
foreach ($rows as $r) {
    [$key, $label] = periode_key_label($r['tanggal'], $granularitas, $bulanNama);
    if (!isset($periods[$key])) {
        $periods[$key] = ['label' => $label, 'pendapatan' => 0, 'hpp' => 0, 'beban' => 0];
    }
    if ($r['tipe'] === 'Pendapatan') $periods[$key]['pendapatan'] += $r['kredit'] - $r['debit'];
    if ($r['tipe'] === 'HPP') $periods[$key]['hpp'] += $r['debit'] - $r['kredit'];
    if ($r['tipe'] === 'Beban') $periods[$key]['beban'] += $r['debit'] - $r['kredit'];
}
ksort($periods);

$maxOmzet = 0;
foreach ($periods as $p) { $maxOmzet = max($maxOmzet, $p['pendapatan']); }

$totalOmzet = array_sum(array_column($periods, 'pendapatan'));
$totalPengeluaran = array_sum(array_column($periods, 'hpp')) + array_sum(array_column($periods, 'beban'));
$totalLaba = $totalOmzet - $totalPengeluaran;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Tren &amp; Perbandingan</div>
    <div class="page-sub">Bandingin performa antar periode, biar keliatan trennya naik atau turun.</div>
  </div>
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
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-primary">Tren &amp; Perbandingan</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
  <?php foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan', 'tahunan' => 'Tahunan'] as $g => $label): ?>
    <a href="keuangan_tren.php?g=<?= $g ?>" class="btn <?= $granularitas === $g ? 'btn-primary' : 'btn-subtle' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end;">
  <input type="hidden" name="g" value="<?= h($granularitas) ?>">
  <div><label class="field-label">Dari Tanggal</label><input type="date" name="dari" value="<?= h($dari) ?>"></div>
  <div><label class="field-label">Sampai Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="stat-grid cols-3">
  <div class="stat-card">
    <div class="stat-icon ok">↑</div>
    <div class="stat-label">Total Omzet</div>
    <div class="stat-value"><?= rupiah($totalOmzet) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Pengeluaran (HPP+Beban)</div>
    <div class="stat-value"><?= rupiah($totalPengeluaran) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $totalLaba >= 0 ? 'ok' : 'danger' ?>">Σ</div>
    <div class="stat-label">Total Laba Bersih</div>
    <div class="stat-value"><?= rupiah($totalLaba) ?></div>
  </div>
</div>

<div class="card" style="overflow:hidden;">
  <?php if (empty($periods)): ?>
    <div class="empty-state"><div class="icon">📈</div><div class="title">Belum ada transaksi di periode ini</div></div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Periode</th><th style="text-align:right;">Omzet</th><th style="text-align:right;">Pengeluaran</th><th style="text-align:right;">Laba Bersih</th><th style="width:180px;">Grafik Omzet</th></tr></thead>
    <tbody>
      <?php foreach ($periods as $p):
        $laba = $p['pendapatan'] - $p['hpp'] - $p['beban'];
        $barWidth = $maxOmzet > 0 ? round(($p['pendapatan'] / $maxOmzet) * 100) : 0;
      ?>
      <tr>
        <td style="font-weight:600;"><?= h($p['label']) ?></td>
        <td style="text-align:right; font-family:var(--font-mono);"><?= rupiah($p['pendapatan']) ?></td>
        <td style="text-align:right; font-family:var(--font-mono); color:var(--danger);"><?= rupiah($p['hpp'] + $p['beban']) ?></td>
        <td style="text-align:right; font-family:var(--font-mono); font-weight:700; color:<?= $laba >= 0 ? 'var(--ok)' : 'var(--danger)' ?>;"><?= rupiah($laba) ?></td>
        <td>
          <div style="background:var(--bg); border-radius:4px; height:10px; overflow:hidden;">
            <div style="width:<?= $barWidth ?>%; height:100%; background:var(--primary); border-radius:4px;"></div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>