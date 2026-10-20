<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'laporan_harian';
$page_title = 'Laporan Harian';

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

$penjualan = $pdo->prepare("SELECT * FROM orders WHERE tanggal = ? ORDER BY id ASC");
$penjualan->execute([$tanggal]);
$penjualan = $penjualan->fetchAll();
$totalOmzet = array_sum(array_column($penjualan, 'total'));
$totalMargin = array_sum(array_column($penjualan, 'total_margin'));

$pelunasan = $pdo->prepare("SELECT * FROM orders WHERE DATE(lunas_at) = ? ORDER BY lunas_at ASC");
$pelunasan->execute([$tanggal]);
$pelunasan = $pelunasan->fetchAll();
$totalPelunasan = array_sum(array_column($pelunasan, 'total'));

$pembelian = $pdo->prepare("SELECT * FROM pembelian WHERE tanggal = ? ORDER BY id ASC");
$pembelian->execute([$tanggal]);
$pembelian = $pembelian->fetchAll();
$totalPembelian = array_sum(array_column($pembelian, 'total'));

$pengeluaran = $pdo->prepare("SELECT * FROM expenses WHERE tanggal = ? ORDER BY jenis ASC, id ASC");
$pengeluaran->execute([$tanggal]);
$pengeluaran = $pengeluaran->fetchAll();
$totalPengeluaran = array_sum(array_column($pengeluaran, 'jumlah'));

$pendaftaranBaru = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM pendaftaran WHERE DATE(created_at) = ? ORDER BY created_at ASC");
    $stmt->execute([$tanggal]);
    $pendaftaranBaru = $stmt->fetchAll();
} catch (Exception $e) { $pendaftaranBaru = []; }

// Saldo Kas = Kas (1-101) + Kas Kecil (1-102), sama persis dengan Ringkasan Keuangan.
$saldoKasStmt = $pdo->prepare("
    SELECT COALESCE(SUM(jd.debit),0)-COALESCE(SUM(jd.kredit),0) t
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE a.kode IN ('1-101','1-102') AND ju.tanggal <= ?
");
$saldoKasStmt->execute([$tanggal]);
$saldoKasAkhirHari = (float)$saldoKasStmt->fetch()['t'];

$saldoAwalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(jd.debit),0)-COALESCE(SUM(jd.kredit),0) t
    FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
    WHERE a.kode IN ('1-101','1-102') AND ju.tanggal < ?
");
$saldoAwalStmt->execute([$tanggal]);
$saldoKasAwalHari = (float)$saldoAwalStmt->fetch()['t'];

// Arus kas hari ini dihitung PER JURNAL (efek bersih ke Kas + Kas Kecil). Jadi pindah uang antar
// Kas <-> Kas Kecil (Isi Ulang Kas Kecil) otomatis gak dihitung sebagai uang masuk/keluar,
// karena uangnya cuma pindah tempat. Pembelian Hutang juga gak muncul di sini sampai dibayar.
$kasRowsStmt = $pdo->prepare("
    SELECT ju.id, ju.sumber, ju.keterangan, SUM(jd.debit) AS d, SUM(jd.kredit) AS k,
           pb.keterangan AS pb_ket,
           (SELECT GROUP_CONCAT(pd.nama_barang SEPARATOR ', ') FROM pembelian_detail pd WHERE pd.pembelian_id = pb.id) AS pb_item
    FROM jurnal_umum ju
    JOIN jurnal_detail jd ON jd.jurnal_id = ju.id
    JOIN akun a ON a.id = jd.akun_id
    LEFT JOIN pembelian pb ON ju.sumber = 'Pembelian' AND pb.id = ju.ref_id
    WHERE ju.tanggal = ? AND a.kode IN ('1-101','1-102')
    GROUP BY ju.id, ju.sumber, ju.keterangan, pb.id, pb.keterangan
    HAVING SUM(jd.debit) <> SUM(jd.kredit)
    ORDER BY ju.id ASC
");
$kasRowsStmt->execute([$tanggal]);
$rincianKas = [];
$kasHariIni = ['masuk' => 0, 'keluar' => 0];
foreach ($kasRowsStmt->fetchAll() as $r) {
    $net = round((float)$r['d'] - (float)$r['k'], 2);
    $masuk = $net > 0 ? $net : 0;
    $keluar = $net < 0 ? -$net : 0;
    $kasHariIni['masuk'] += $masuk;
    $kasHariIni['keluar'] += $keluar;
    // Pembelian: tambahin keterangan yang diisi pas input pembelian (kalau kosong, pakai nama barangnya)
    $ketTampil = $r['keterangan'];
    if ($r['sumber'] === 'Pembelian') {
        $tambahan = trim((string)($r['pb_ket'] ?? ''));
        if ($tambahan === '') $tambahan = trim((string)($r['pb_item'] ?? ''));
        if ($tambahan !== '') $ketTampil .= ' (' . $tambahan . ')';
    }
    $rincianKas[] = ['sumber' => $r['sumber'], 'keterangan' => $ketTampil, 'masuk' => $masuk, 'keluar' => $keluar];
}

// Pembelian: pisahin yang beneran dibayar (Tunai / Kas Kecil) dari yang masih Hutang.
$totalPembelianBayar = 0; $totalPembelianHutang = 0;
foreach ($pembelian as $pb) {
    if ($pb['metode_bayar'] === 'Hutang') $totalPembelianHutang += (float)$pb['total'];
    else $totalPembelianBayar += (float)$pb['total'];
}

// Talangan (biaya yang dibayarin orang lain dulu, belum tentu udah diganti)
$talanganHari = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM talangan WHERE tanggal = ? ORDER BY id ASC");
    $stmt->execute([$tanggal]);
    $talanganHari = $stmt->fetchAll();
} catch (Exception $e) { $talanganHari = []; }
$totalTalangan = array_sum(array_column($talanganHari, 'jumlah'));

$namaHari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$namaBulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$ts = strtotime($tanggal);
$tanggalFormatted = $namaHari[(int)date('w', $ts)] . ', ' . date('j', $ts) . ' ' . $namaBulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header no-print">
  <div>
    <div class="page-title">Laporan Harian</div>
    <div class="page-sub">Ringkasan semua aktivitas dalam 1 hari - buat lapor ke owner atau arsip.</div>
  </div>
  <div style="display:flex; gap:8px;">
    <button onclick="window.print()" class="btn btn-accent">Print / Simpan PDF</button>
  </div>
</div>

<form method="GET" class="card card-pad no-print" style="margin-bottom:16px; max-width:320px; display:flex; gap:10px; align-items:end;">
  <div style="flex:1;"><label class="field-label">Pilih Tanggal</label><input type="date" name="tanggal" value="<?= h($tanggal) ?>"></div>
  <button type="submit" class="btn btn-subtle">Tampilkan</button>
</form>

<div class="card card-pad print-section" id="laporan-print">
  <div style="text-align:center; margin-bottom:20px; border-bottom:2px solid var(--border); padding-bottom:14px;">
    <div style="font-weight:700; font-size:20px;">LAPORAN HARIAN</div>
    <div style="color:var(--muted); font-size:14px;">Brother Needs - <?= h($tanggalFormatted) ?></div>
  </div>

  <div class="stat-grid cols-4" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-label" style="margin-bottom:8px;">Omzet Hari Ini</div><div class="stat-value"><?= rupiah($totalOmzet) ?></div><div style="font-size:11px; color:var(--muted);"><?= count($penjualan) ?> invoice</div></div>
    <div class="stat-card"><div class="stat-label" style="margin-bottom:8px;">Margin Hari Ini</div><div class="stat-value"><?= rupiah($totalMargin) ?></div></div>
    <div class="stat-card"><div class="stat-label" style="margin-bottom:8px;">Kas Masuk</div><div class="stat-value" style="color:var(--ok);"><?= rupiah($kasHariIni['masuk']) ?></div></div>
    <div class="stat-card"><div class="stat-label" style="margin-bottom:8px;">Kas Keluar</div><div class="stat-value" style="color:var(--danger);"><?= rupiah($kasHariIni['keluar']) ?></div></div>
  </div>
  <div style="text-align:right; font-size:13px; color:var(--muted); margin-bottom:20px; line-height:1.7;">
    Saldo Kas awal hari: <b style="color:var(--text); font-family:var(--font-mono);"><?= rupiah($saldoKasAwalHari) ?></b>
    + Masuk <?= rupiah($kasHariIni['masuk']) ?> - Keluar <?= rupiah($kasHariIni['keluar']) ?><br>
    Saldo Kas per akhir hari ini (Kas + Kas Kecil): <b style="color:var(--text); font-family:var(--font-mono);"><?= rupiah($saldoKasAkhirHari) ?></b>
  </div>

  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Penjualan (<?= count($penjualan) ?>)</div>
  <?php if (empty($penjualan)): ?>
    <div style="font-size:12.5px; color:var(--muted); padding:8px 0;">Gak ada order hari ini.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Invoice</th><th>SPPG</th><th>Total</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($penjualan as $p): ?>
        <tr>
          <td style="font-family:var(--font-mono);"><?= h($p['invoice_no']) ?></td>
          <td><?= h($p['sppg_nama']) ?></td>
          <td style="font-family:var(--font-mono);"><?= rupiah($p['total']) ?></td>
          <td><span class="badge <?= $p['status']==='Lunas'?'badge-ok':'badge-danger' ?>"><?= h($p['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Pelunasan (<?= count($pelunasan) ?>) - Total <?= rupiah($totalPelunasan) ?></div>
  <?php if (empty($pelunasan)): ?>
    <div style="font-size:12.5px; color:var(--muted); padding:8px 0;">Gak ada pelunasan hari ini.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Invoice</th><th>SPPG</th><th>Jam</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($pelunasan as $p): ?>
        <tr>
          <td style="font-family:var(--font-mono);"><?= h($p['invoice_no']) ?></td>
          <td><?= h($p['sppg_nama']) ?></td>
          <td><?= date('H:i', strtotime($p['lunas_at'])) ?></td>
          <td style="font-family:var(--font-mono);"><?= rupiah($p['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Pembelian (<?= count($pembelian) ?>) - Total <?= rupiah($totalPembelian) ?> <span style="font-weight:400; font-size:12px; color:var(--muted);">(dibayar Kas/Kas Kecil <?= rupiah($totalPembelianBayar) ?> · masih Hutang <?= rupiah($totalPembelianHutang) ?>)</span></div>
  <?php if (empty($pembelian)): ?>
    <div style="font-size:12.5px; color:var(--muted); padding:8px 0;">Gak ada pembelian hari ini.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>No Bukti</th><th>Supplier</th><th>Keterangan</th><th>Metode</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($pembelian as $p): ?>
        <tr>
          <td style="font-family:var(--font-mono);"><?= h($p['no_bukti']) ?></td>
          <td><?= h($p['supplier'] ?: '-') ?></td>
          <td><?= h($p['keterangan'] ?: '-') ?></td>
          <td><?= h($p['metode_bayar']) ?></td>
          <td style="font-family:var(--font-mono);"><?= rupiah($p['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Pengeluaran / Petty Cash &amp; Beban Operasional (<?= count($pengeluaran) ?>) - Total <?= rupiah($totalPengeluaran) ?></div>
  <?php if (empty($pengeluaran)): ?>
    <div style="font-size:12.5px; color:var(--muted); padding:8px 0;">Gak ada pengeluaran hari ini.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Jenis</th><th>Kategori</th><th>Catatan</th><th>Jumlah</th></tr></thead>
      <tbody>
        <?php foreach ($pengeluaran as $e): ?>
        <tr>
          <td><span class="badge badge-muted"><?= h($e['jenis']) ?></span></td>
          <td><?= h($e['kategori']) ?></td>
          <td><?= h($e['catatan']) ?></td>
          <td style="font-family:var(--font-mono);"><?= rupiah($e['jumlah']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!empty($talanganHari)): ?>
  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Talangan (<?= count($talanganHari) ?>) - Total <?= rupiah($totalTalangan) ?> <span style="font-weight:400; font-size:12px; color:var(--muted);">(dibayarin orang lain dulu - jadi Hutang Usaha)</span></div>
  <table class="data-table">
    <thead><tr><th>Pihak</th><th>Keterangan</th><th>Kategori</th><th>Jumlah</th></tr></thead>
    <tbody>
      <?php foreach ($talanganHari as $t): ?>
      <tr>
        <td><?= h($t['pihak']) ?></td>
        <td><?= h($t['keterangan']) ?></td>
        <td><?= h($t['kategori']) ?></td>
        <td style="font-family:var(--font-mono);"><?= rupiah($t['jumlah']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Rincian Arus Kas (Kas + Kas Kecil) - Masuk <?= rupiah($kasHariIni['masuk']) ?> · Keluar <?= rupiah($kasHariIni['keluar']) ?></div>
  <?php if (empty($rincianKas)): ?>
    <div style="font-size:12.5px; color:var(--muted); padding:8px 0;">Gak ada uang masuk atau keluar hari ini.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Sumber</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th></tr></thead>
      <tbody>
        <?php foreach ($rincianKas as $rk): ?>
        <tr>
          <td><span class="badge badge-muted"><?= h($rk['sumber'] ?: 'Manual') ?></span></td>
          <td><?= h($rk['keterangan']) ?></td>
          <td style="font-family:var(--font-mono); color:var(--ok);"><?= $rk['masuk'] > 0 ? rupiah($rk['masuk']) : '-' ?></td>
          <td style="font-family:var(--font-mono); color:var(--danger);"><?= $rk['keluar'] > 0 ? rupiah($rk['keluar']) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="font-size:11.5px; color:var(--muted); padding:6px 0;">Pindah uang antara Kas dan Kas Kecil (Isi Ulang Kas Kecil) gak dihitung karena uangnya cuma pindah tempat.</div>
  <?php endif; ?>

  <?php if (!empty($pendaftaranBaru)): ?>
  <div style="font-weight:700; font-size:14px; margin:18px 0 8px; border-bottom:1px solid var(--border); padding-bottom:6px;">Pendaftaran Baru (<?= count($pendaftaranBaru) ?>)</div>
  <table class="data-table">
    <thead><tr><th>Nama Usaha</th><th>PIC</th><th>WhatsApp</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($pendaftaranBaru as $p): ?>
      <tr>
        <td><?= h($p['nama_usaha']) ?></td>
        <td><?= h($p['nama_pic']) ?></td>
        <td><?= h($p['whatsapp']) ?></td>
        <td><span class="badge badge-muted"><?= h($p['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div style="margin-top:24px; padding-top:12px; border-top:1px solid var(--border); font-size:11px; color:var(--muted); text-align:right;">
    Dicetak dari sistem Brother Needs pada <?= date('d/m/Y H:i') ?>
  </div>
</div>

<style>
  /* Sisa pengaturan layout print (sembunyiin sidebar, kecualiin #laporan-print
     dari "display:none") udah ditangani global lewat class .print-section di
     assets/css/style.css - di sini tinggal urusan khusus halaman ini doang. */
  @media print {
    @page { margin: 12mm; }
  }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>