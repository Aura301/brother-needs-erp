<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Modal & Kas';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $tanggal = $_POST['tanggal'];
    $jenis = $_POST['jenis']; // 'setor' atau 'prive'
    $jumlah = clean_number($_POST['jumlah']);
    $keterangan = trim($_POST['keterangan']);
    $userId = current_user()['id'] ?? null;

    if ($jumlah <= 0) {
        $error = 'Jumlah harus lebih dari 0.';
    } else {
        if ($jenis === 'setor') {
            $ket = 'Setor Modal' . ($keterangan ? ': ' . $keterangan : '');
            post_jurnal($pdo, $tanggal, $ket, [
                ['akun_id' => akun_id($pdo, '1-101'), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $ket],
                ['akun_id' => akun_id($pdo, '3-101'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $ket],
            ], 'Modal', null, $userId);
        } else {
            $ket = 'Prive (ambil dari usaha)' . ($keterangan ? ': ' . $keterangan : '');
            post_jurnal($pdo, $tanggal, $ket, [
                ['akun_id' => akun_id($pdo, '3-102'), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $ket],
                ['akun_id' => akun_id($pdo, '1-101'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => $ket],
            ], 'Modal', null, $userId);
        }
        redirect('keuangan_modal.php');
    }
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ? AND sumber = 'Modal'");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('keuangan_modal.php');
}

// Saldo Kas & Modal saat ini, dihitung langsung dari jurnal (akun Kas & Modal Pemilik)
$saldoKas = $pdo->query("
    SELECT COALESCE(SUM(jd.debit) - SUM(jd.kredit), 0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode IN ('1-101','1-102')
")->fetch()['t'];

$totalModalDisetor = $pdo->query("
    SELECT COALESCE(SUM(jd.kredit) - SUM(jd.debit), 0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode = '3-101'
")->fetch()['t'];

$totalPrive = $pdo->query("
    SELECT COALESCE(SUM(jd.debit) - SUM(jd.kredit), 0) t FROM jurnal_detail jd
    JOIN akun a ON a.id = jd.akun_id WHERE a.kode = '3-102'
")->fetch()['t'];

$riwayat = $pdo->query("SELECT * FROM jurnal_umum WHERE sumber = 'Modal' ORDER BY tanggal DESC, id DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Modal &amp; Kas</div>
    <div class="page-sub">Setor modal awal, tambahan modal, atau ambil prive (uang pribadi dari usaha).</div>
  </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-primary">Modal &amp; Kas</a>
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

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="stat-grid cols-3">
  <div class="stat-card">
    <div class="stat-icon ok">$</div>
    <div class="stat-label">Saldo Kas Sekarang</div>
    <div class="stat-value"><?= rupiah($saldoKas) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon accent">↑</div>
    <div class="stat-label">Total Modal Disetor</div>
    <div class="stat-value"><?= rupiah($totalModalDisetor) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">↓</div>
    <div class="stat-label">Total Prive Diambil</div>
    <div class="stat-value"><?= rupiah($totalPrive) ?></div>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1.4fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Catat Setor Modal / Prive</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-row">
        <label class="field-label">Jenis</label>
        <select name="jenis">
          <option value="setor">Setor Modal (uang masuk ke usaha)</option>
          <option value="prive">Ambil Prive (uang keluar buat pribadi)</option>
        </select>
      </div>
      <div class="form-row">
        <label class="field-label">Tanggal</label>
        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-row">
        <label class="field-label">Jumlah</label>
        <input type="number" step="any" name="jumlah" placeholder="misal 5000000" required>
      </div>
      <div class="form-row">
        <label class="field-label">Keterangan (opsional)</label>
        <input type="text" name="keterangan" placeholder="misal: Modal awal usaha">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan</button>
    </form>
  </div>

  <div class="card" style="overflow:hidden;">
    <?php if (empty($riwayat)): ?>
      <div class="empty-state">
        <div class="icon">💰</div>
        <div class="title">Belum ada catatan modal</div>
        <div>Kalau baru mulai pakai aplikasi ini, catat dulu modal awal usaha kamu di sini.</div>
      </div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($riwayat as $r):
          $detailStmt = $pdo->prepare("SELECT jd.*, a.kode FROM jurnal_detail jd JOIN akun a ON a.id=jd.akun_id WHERE jd.jurnal_id=?");
          $detailStmt->execute([$r['id']]);
          $lines = $detailStmt->fetchAll();
          $isSetor = false; $jumlah = 0;
          foreach ($lines as $l) { if ($l['kode'] === '1-101') { $isSetor = $l['debit'] > 0; $jumlah = max($l['debit'], $l['kredit']); } }
        ?>
        <tr>
          <td><?= fmt_tanggal($r['tanggal']) ?></td>
          <td><?= h($r['keterangan']) ?></td>
          <td style="font-family:var(--font-mono); font-weight:700; color:<?= $isSetor ? 'var(--ok)' : 'var(--danger)' ?>;">
            <?= $isSetor ? '+' : '-' ?><?= rupiah($jumlah) ?>
          </td>
          <td style="text-align:right;"><a href="keuangan_modal.php?delete=<?= $r['id'] ?>" style="color:var(--danger);" onclick="return confirm('Hapus catatan ini?')">🗑</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>