<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Beban Operasional';

function kategori_beban_ke_akun_kode($kategori) {
    if ($kategori === 'Gaji Karyawan') return '6-103';
    if ($kategori === 'Listrik & Utilitas') return '6-106';
    if ($kategori === 'Ongkos Kirim (Lalamove dll)') return '6-102';
    if ($kategori === 'Maintenance & Perawatan') return '6-107';
    if ($kategori === 'Lain-lain') return '6-104';
    return '6-101'; // Beban Operasional (umum)
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_beban') {
    $tanggal = $_POST['tanggal'];
    $akun = trim($_POST['akun']);
    $kategori = $_POST['kategori'];
    $catatan = trim($_POST['catatan']);
    $jumlah = clean_number($_POST['jumlah']);
    $userId = current_user()['id'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO expenses (tanggal, akun, kategori, catatan, jumlah, jenis, created_by) VALUES (?,?,?,?,?, 'Operasional', ?)");
    $stmt->execute([$tanggal, $akun, $kategori, $catatan, $jumlah, $userId]);
    $expenseId = $pdo->lastInsertId();

    $bebanKode = kategori_beban_ke_akun_kode($kategori);
    post_jurnal($pdo, $tanggal, 'Beban Operasional: ' . $catatan, [
        ['akun_id' => akun_id($pdo, $bebanKode), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $catatan],
        ['akun_id' => akun_id($pdo, '1-101'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $catatan],
    ], 'Beban Operasional', $expenseId, $userId);

    redirect('keuangan_beban.php');
}

if (isset($_GET['delete_beban'])) {
    $id = (int)$_GET['delete_beban'];
    delete_jurnal_by_ref($pdo, 'Beban Operasional', $id);
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND jenis = 'Operasional'");
    $stmt->execute([$id]);
    redirect('keuangan_beban.php');
}

$bebanList = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Operasional' ORDER BY tanggal DESC, id DESC")->fetchAll();
$totalKeluar = array_sum(array_column($bebanList, 'jumlah'));
$kategoriList = ['Gaji Karyawan', 'Listrik & Utilitas', 'Ongkos Kirim (Lalamove dll)', 'Maintenance & Perawatan', 'Lain-lain'];

$bulanIni = date('Y-m');
$totalBulanIni = array_sum(array_map(fn($e) => date('Y-m', strtotime($e['tanggal'])) === $bulanIni ? (float)$e['jumlah'] : 0, $bebanList));

// breakdown per kategori bulan ini, biar keliatan gaji vs listrik vs dll
$breakdownBulanIni = [];
foreach ($bebanList as $e) {
    if (date('Y-m', strtotime($e['tanggal'])) !== $bulanIni) continue;
    $breakdownBulanIni[$e['kategori']] = ($breakdownBulanIni[$e['kategori']] ?? 0) + (float)$e['jumlah'];
}
arsort($breakdownBulanIni);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Beban Operasional</div>
    <div class="page-sub">Pengeluaran rutin: gaji, listrik, ongkos kirim, maintenance, dll — dipotong dari Kas utama.</div>
  </div>
  <a href="export_excel.php?type=beban_operasional" class="btn btn-ghost">⬇ Export Excel</a>
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
  <a href="keuangan_beban.php" class="btn btn-primary">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<div class="alert alert-info">
  💡 Ini buat pengeluaran <b>rutin &amp; lebih besar</b> yang dibayar langsung dari Kas utama
  (gaji, listrik, sewa jasa kirim, maintenance). Kalau cuma pengeluaran kecil dadakan
  (ATK, parkir, dll), catat di <a href="keuangan_petty_cash.php" style="color:var(--primary); font-weight:700;">Petty Cash</a> aja.
</div>

<div class="stat-grid cols-3">
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Terpakai (Sepanjang Waktu)</div>
    <div class="stat-value"><?= rupiah($totalKeluar) ?></div>
  </div>
  <div class="stat-card"><div class="stat-label" style="margin-bottom:10px;">Jumlah Transaksi</div><div class="stat-value"><?= count($bebanList) ?></div></div>
  <div class="stat-card"><div class="stat-label" style="margin-bottom:10px;">Bulan Ini</div><div class="stat-value"><?= rupiah($totalBulanIni) ?></div></div>
</div>

<?php if (!empty($breakdownBulanIni)): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <div style="font-weight:700; font-size:14px; margin-bottom:10px;">Breakdown Bulan Ini</div>
  <?php foreach ($breakdownBulanIni as $kat => $jml): ?>
    <div style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px solid var(--border);">
      <span><?= h($kat) ?></span>
      <span style="font-family:var(--font-mono); font-weight:600;"><?= rupiah($jml) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Catat Beban Operasional</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_beban">
      <div class="form-row grid-2">
        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
        <input type="text" name="akun" placeholder="Akun (nama admin)">
      </div>
      <div class="form-row">
        <select name="kategori">
          <?php foreach ($kategoriList as $k): ?><option value="<?= h($k) ?>"><?= h($k) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><input type="text" name="catatan" placeholder="Catatan (misal: Gaji Agustus - Budi)" required></div>
      <div class="form-row"><input type="number" step="any" name="jumlah" placeholder="Jumlah (Rp)" required></div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">+ Tambah Beban</button>
    </form>
  </div>

  <div class="card" style="overflow:hidden;">
    <?php if (empty($bebanList)): ?>
      <div class="empty-state"><div class="icon">🧾</div><div class="title">Belum ada catatan</div></div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Tanggal</th><th>Catatan</th><th>Kategori</th><th>Jumlah</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($bebanList as $e): ?>
        <tr>
          <td><?= fmt_tanggal($e['tanggal']) ?></td>
          <td><?= h($e['catatan']) ?><?= $e['akun'] ? '<div style="font-size:11px;color:var(--muted);">'.h($e['akun']).'</div>' : '' ?></td>
          <td><span class="badge badge-muted"><?= h($e['kategori']) ?></span></td>
          <td style="font-family:var(--font-mono); color:var(--danger); font-weight:700;">-<?= rupiah($e['jumlah']) ?></td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="keuangan_beban_edit.php?id=<?= $e['id'] ?>" style="color:var(--muted); margin-right:8px;">✎</a>
            <a href="keuangan_beban.php?delete_beban=<?= $e['id'] ?>" style="color:var(--muted);" onclick="return confirm('Hapus catatan ini?')">🗑</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>