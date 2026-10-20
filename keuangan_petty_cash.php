<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Petty Cash';

function kategori_ke_akun_kode($kategori) {
    if ($kategori === 'Parkir & Transport') return '6-102';
    if ($kategori === 'Konsumsi Dadakan') return '6-105';
    if ($kategori === 'Lain-lain Kecil') return '6-104';
    return '6-101'; // ATK & Perlengkapan Kecil / default
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    $tanggal = $_POST['tanggal'];
    $akun = trim($_POST['akun']);
    $kategori = $_POST['kategori'];
    $catatan = trim($_POST['catatan']);
    $jumlah = clean_number($_POST['jumlah']);
    $userId = current_user()['id'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO expenses (tanggal, akun, kategori, catatan, jumlah, jenis, created_by) VALUES (?,?,?,?,?, 'Petty Cash', ?)");
    $stmt->execute([$tanggal, $akun, $kategori, $catatan, $jumlah, $userId]);
    $expenseId = $pdo->lastInsertId();

    $bebanKode = kategori_ke_akun_kode($kategori);
    post_jurnal($pdo, $tanggal, 'Petty Cash: ' . $catatan, [
        ['akun_id' => akun_id($pdo, $bebanKode), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $catatan],
        ['akun_id' => akun_id($pdo, '1-102'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $catatan],
    ], 'Petty Cash', $expenseId, $userId);

    redirect('keuangan_petty_cash.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_budget') {
    $stmt = $pdo->prepare("UPDATE company_profile SET petty_cash_budget = ? WHERE id = 1");
    $stmt->execute([clean_number($_POST['budget'])]);
    redirect('keuangan_petty_cash.php?budget_saved=1');
}

// Isi ulang Kas Kecil dari Kas utama - transaksi BENERAN (bukan angka ngarang),
// otomatis motong Kas (1-101) & nambah Kas Kecil (1-102).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'topup') {
    $tanggal = $_POST['tanggal_topup'];
    $jumlah = clean_number($_POST['jumlah_topup']);
    $keterangan = trim($_POST['keterangan_topup']) ?: 'Isi ulang Kas Kecil';
    $userId = current_user()['id'] ?? null;

    if ($jumlah > 0) {
        $stmt = $pdo->prepare("INSERT INTO kas_kecil_topup (tanggal, jumlah, keterangan, created_by) VALUES (?,?,?,?)");
        $stmt->execute([$tanggal, $jumlah, $keterangan, $userId]);
        $topupId = $pdo->lastInsertId();

        post_jurnal($pdo, $tanggal, 'Isi ulang Kas Kecil: ' . $keterangan, [
            ['akun_id' => akun_id($pdo, '1-102'), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $keterangan],
            ['akun_id' => akun_id($pdo, '1-101'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $keterangan],
        ], 'Petty Cash', -$topupId, $userId); // ref_id negatif biar beda dari ref_id expenses (positif)
    }
    redirect('keuangan_petty_cash.php');
}

if (isset($_GET['delete_expense'])) {
    $id = (int)$_GET['delete_expense'];
    delete_jurnal_by_ref($pdo, 'Petty Cash', $id);
    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND jenis = 'Petty Cash'");
    $stmt->execute([$id]);
    redirect('keuangan_petty_cash.php');
}

if (isset($_GET['delete_topup'])) {
    $id = (int)$_GET['delete_topup'];
    delete_jurnal_by_ref($pdo, 'Petty Cash', -$id);
    $stmt = $pdo->prepare("DELETE FROM kas_kecil_topup WHERE id = ?");
    $stmt->execute([$id]);
    redirect('keuangan_petty_cash.php');
}

$expenses = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Petty Cash' ORDER BY tanggal DESC, id DESC")->fetchAll();
$topups = $pdo->query("SELECT * FROM kas_kecil_topup ORDER BY tanggal DESC, id DESC")->fetchAll();
$totalKeluar = array_sum(array_column($expenses, 'jumlah'));
$kategoriList = ['ATK & Perlengkapan Kecil', 'Parkir & Transport', 'Konsumsi Dadakan', 'Lain-lain Kecil'];

$bulanIni = date('Y-m');
$totalBulanIni = array_sum(array_map(fn($e) => date('Y-m', strtotime($e['tanggal'])) === $bulanIni ? (float)$e['jumlah'] : 0, $expenses));

$company = get_company($pdo);
$budget = (float)($company['petty_cash_budget'] ?? 0);
$sisaBudget = $budget - $totalBulanIni;
$persenTerpakai = $budget > 0 ? min(100, ($totalBulanIni / $budget) * 100) : 0;
$overBudget = $budget > 0 && $totalBulanIni > $budget;

// Sisa Kas Kecil dihitung LANGSUNG dari jurnal (semua isi ulang - semua pengeluaran),
// bukan lagi angka manual yang bisa ngaco - konsisten sama cara Saldo Kas di halaman lain.
$saldoKasKecilStmt = $pdo->query("
    SELECT COALESCE(SUM(jd.debit) - SUM(jd.kredit), 0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode = '1-102'
");
$sisaKasKecil = (float)$saldoKasKecilStmt->fetch()['t'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Petty Cash</div>
    <div class="page-sub">Kas kecil buat pengeluaran kecil &amp; dadakan (ATK, parkir, konsumsi mendadak).</div>
  </div>
  <a href="export_excel.php?type=petty_cash" class="btn btn-ghost">⬇ Export Excel</a>
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
  <a href="keuangan_petty_cash.php" class="btn btn-primary">Petty Cash</a>
  <a href="keuangan_beban.php" class="btn btn-subtle">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<div class="alert alert-info">
  💡 Petty Cash ini khusus buat pengeluaran <b>kecil &amp; dadakan</b> (ATK, parkir, konsumsi mendadak).
  Buat pengeluaran rutin yang lebih besar kayak gaji, listrik, Lalamove, atau maintenance,
  catat lewat menu <a href="keuangan_beban.php" style="color:var(--primary); font-weight:700;">Beban Operasional</a> ya.
</div>

<?php if (isset($_GET['budget_saved'])): ?>
  <div class="alert alert-ok">Budget bulanan tersimpan.</div>
<?php endif; ?>

<div class="stat-grid cols-4">
  <div class="stat-card">
    <div class="stat-icon accent">$</div>
    <div class="stat-label">Sisa Kas Kecil (Sekarang)</div>
    <div class="stat-value"><?= rupiah($sisaKasKecil) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Terpakai (Sepanjang Waktu)</div>
    <div class="stat-value"><?= rupiah($totalKeluar) ?></div>
  </div>
  <div class="stat-card"><div class="stat-label" style="margin-bottom:10px;">Jumlah Transaksi</div><div class="stat-value"><?= count($expenses) ?></div></div>
  <div class="stat-card"><div class="stat-label" style="margin-bottom:10px;">Terpakai Bulan Ini</div><div class="stat-value"><?= rupiah($totalBulanIni) ?></div></div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:14px; margin-bottom:2px;">💰 Isi Ulang Kas Kecil dari Kas Utama</div>
    <div style="font-size:11.5px; color:var(--muted); margin-bottom:12px;">Ini transaksi BENERAN — otomatis motong Kas utama, nambah Kas Kecil.</div>
    <form method="POST">
      <input type="hidden" name="action" value="topup">
      <div class="form-row"><input type="date" name="tanggal_topup" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-row"><input type="number" step="any" name="jumlah_topup" placeholder="misal 500000" required></div>
      <div class="form-row"><input type="text" name="keterangan_topup" placeholder="Keterangan (opsional)"></div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">+ Isi Ulang</button>
    </form>
  </div>

  <div class="card card-pad">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
      <div>
        <div style="font-weight:700; font-size:14px;">Budget Petty Cash Bulan Ini</div>
        <div style="font-size:11.5px; color:var(--muted);"><?= h(['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)date('n')]) ?> <?= date('Y') ?></div>
      </div>
      <form method="POST" style="display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="action" value="set_budget">
        <input type="number" step="any" name="budget" value="<?= $budget ?: '' ?>" placeholder="misal 500000" style="width:140px;">
        <button type="submit" class="btn btn-subtle btn-sm">Simpan</button>
      </form>
    </div>
    <?php if ($budget > 0): ?>
      <div style="background:var(--bg); border-radius:999px; height:12px; overflow:hidden; margin-bottom:8px;">
        <div style="width:<?= $persenTerpakai ?>%; height:100%; background:<?= $overBudget ? 'var(--danger)' : 'var(--primary)' ?>; border-radius:999px;"></div>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px;">
        <span>Terpakai: <b style="font-family:var(--font-mono);"><?= rupiah($totalBulanIni) ?></b> (<?= round($persenTerpakai) ?>%)</span>
        <span style="color:<?= $overBudget ? 'var(--danger)' : 'var(--ok)' ?>; font-weight:700;">
          <?= $overBudget ? 'Kelebihan ' . rupiah(abs($sisaBudget)) : 'Sisa ' . rupiah($sisaBudget) ?>
        </span>
      </div>
    <?php else: ?>
      <div style="font-size:12px; color:var(--muted);">Belum ada budget di-set.</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($topups)): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <div style="font-weight:700; font-size:13px; margin-bottom:10px;">Riwayat Isi Ulang Kas Kecil</div>
  <table class="data-table">
    <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($topups as $t): ?>
      <tr>
        <td><?= fmt_tanggal($t['tanggal']) ?></td>
        <td><?= h($t['keterangan']) ?></td>
        <td style="font-family:var(--font-mono); color:var(--ok); font-weight:700;">+<?= rupiah($t['jumlah']) ?></td>
        <td style="text-align:right;"><a href="keuangan_petty_cash.php?delete_topup=<?= $t['id'] ?>" style="color:var(--muted);" onclick="return confirm('Hapus catatan isi ulang ini?')">🗑</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Catat Pengeluaran Kecil</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_expense">
      <div class="form-row grid-2">
        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
        <input type="text" name="akun" placeholder="Akun (nama admin)">
      </div>
      <div class="form-row">
        <select name="kategori">
          <?php foreach ($kategoriList as $k): ?><option value="<?= h($k) ?>"><?= h($k) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row"><input type="text" name="catatan" placeholder="Catatan pengeluaran apa?" required></div>
      <div class="form-row"><input type="number" step="any" name="jumlah" placeholder="Jumlah (Rp)" required></div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">+ Tambah Pengeluaran</button>
    </form>
  </div>

  <div class="card" style="overflow:hidden;">
    <?php if (empty($expenses)): ?>
      <div class="empty-state"><div class="icon">💵</div><div class="title">Belum ada catatan</div></div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Tanggal</th><th>Catatan</th><th>Kategori</th><th>Jumlah</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($expenses as $e): ?>
        <tr>
          <td><?= fmt_tanggal($e['tanggal']) ?></td>
          <td><?= h($e['catatan']) ?><?= $e['akun'] ? '<div style="font-size:11px;color:var(--muted);">'.h($e['akun']).'</div>' : '' ?></td>
          <td><span class="badge badge-muted"><?= h($e['kategori']) ?></span></td>
          <td style="font-family:var(--font-mono); color:var(--danger); font-weight:700;">-<?= rupiah($e['jumlah']) ?></td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="keuangan_petty_cash_edit.php?id=<?= $e['id'] ?>" style="color:var(--muted); margin-right:8px;">✎</a>
            <a href="keuangan_petty_cash.php?delete_expense=<?= $e['id'] ?>" style="color:var(--muted);" onclick="return confirm('Hapus catatan ini?')">🗑</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>