<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Jurnal Umum';

$error = '';

// Tambah jurnal manual (multi baris debit/kredit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $tanggal = $_POST['tanggal'];
    $keterangan = trim($_POST['keterangan']);
    $akunIds = $_POST['akun_id'] ?? [];
    $debits = $_POST['debit'] ?? [];
    $kredits = $_POST['kredit'] ?? [];

    $lines = [];
    foreach ($akunIds as $i => $akunId) {
        if (!$akunId) continue;
        $lines[] = [
            'akun_id' => (int)$akunId,
            'debit' => clean_number($debits[$i] ?? 0),
            'kredit' => clean_number($kredits[$i] ?? 0),
            'keterangan' => $keterangan,
        ];
    }

    $totalDebit = array_sum(array_column($lines, 'debit'));
    $totalKredit = array_sum(array_column($lines, 'kredit'));

    if (empty($lines) || round($totalDebit, 2) <= 0) {
        $error = 'Isi minimal 2 baris akun dengan nominal debit/kredit.';
    } elseif (round($totalDebit, 2) !== round($totalKredit, 2)) {
        $error = 'Jurnal gak balance — total debit (' . rupiah($totalDebit) . ') harus sama dengan total kredit (' . rupiah($totalKredit) . ').';
    } else {
        $userId = current_user()['id'] ?? null;
        post_jurnal($pdo, $tanggal, $keterangan, $lines, 'Manual', null, $userId);
        redirect('keuangan_jurnal.php');
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // hanya boleh hapus entri manual, entri otomatis harus dihapus dari transaksi asalnya
    $stmt = $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ? AND sumber = 'Manual'");
    $stmt->execute([$id]);
    redirect('keuangan_jurnal.php');
}

$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT * FROM jurnal_umum WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC, id DESC");
$stmt->execute([$dari, $sampai]);
$jurnals = $stmt->fetchAll();

$detailStmt = $pdo->prepare("SELECT jd.*, a.kode, a.nama AS akun_nama FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id WHERE jd.jurnal_id = ?");
$akunList = $pdo->query("SELECT * FROM akun ORDER BY kode ASC")->fetchAll();
$showAdd = isset($_GET['tambah']);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Jurnal Umum</div>
    <div class="page-sub">Semua transaksi tercatat berpasangan (debit = kredit), otomatis maupun manual.</div>
  </div>
  <a href="export_excel.php?type=jurnal" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-primary">Jurnal Umum</a>
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

<?php
$today = date('Y-m-d');
$awalMinggu = date('Y-m-d', strtotime('monday this week'));
$awalBulan = date('Y-m-01');
$awalTahun = date('Y-01-01');
$periodePresets = [
    'Hari Ini' => [$today, $today],
    'Minggu Ini' => [$awalMinggu, $today],
    'Bulan Ini' => [$awalBulan, $today],
    'Tahun Ini' => [$awalTahun, $today],
];
?>
<div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
  <?php foreach ($periodePresets as $label => $range): $aktif = ($dari === $range[0] && $sampai === $range[1]); ?>
    <a href="keuangan_jurnal.php?dari=<?= $range[0] ?>&sampai=<?= $range[1] ?>" class="btn btn-sm <?= $aktif ? 'btn-primary' : 'btn-subtle' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; display:flex; gap:12px; align-items:end;">
  <div><label class="field-label">Dari Tanggal</label><input type="date" name="dari" value="<?= h($dari) ?>"></div>
  <div><label class="field-label">Sampai Tanggal</label><input type="date" name="sampai" value="<?= h($sampai) ?>"></div>
  <button type="submit" class="btn btn-subtle">Filter</button>
  <a href="keuangan_jurnal.php?tambah=1" class="btn btn-accent" style="margin-left:auto;">+ Jurnal Manual</a>
</form>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <div style="font-weight:700; margin-bottom:12px;">Tambah Jurnal Manual</div>
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-row grid-2">
      <div><label class="field-label">Tanggal</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required></div>
      <div><label class="field-label">Keterangan</label><input type="text" name="keterangan" required placeholder="Misal: Setor modal awal"></div>
    </div>

    <table class="data-table" style="margin-bottom:10px;">
      <thead><tr><th>Akun</th><th>Debit</th><th>Kredit</th></tr></thead>
      <tbody>
        <?php for ($i = 0; $i < 4; $i++): ?>
        <tr>
          <td>
            <select name="akun_id[]">
              <option value="">— pilih akun —</option>
              <?php foreach ($akunList as $a): ?>
                <option value="<?= $a['id'] ?>"><?= h($a['kode']) ?> — <?= h($a['nama']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="any" name="debit[]" placeholder="0"></td>
          <td><input type="number" step="any" name="kredit[]" placeholder="0"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
    <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">Total debit harus sama dengan total kredit. Baris yang gak dipakai boleh dikosongkan.</div>
    <button type="submit" class="btn btn-primary">Posting Jurnal</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <?php if (empty($jurnals)): ?>
    <div class="empty-state"><div class="icon">📒</div><div class="title">Belum ada jurnal di periode ini</div></div>
  <?php else: foreach ($jurnals as $j): $detailStmt->execute([$j['id']]); $lines = $detailStmt->fetchAll(); ?>
    <div style="padding:14px 18px; border-top:1px solid var(--border);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <div>
          <span style="font-weight:700; font-size:13.5px;"><?= h($j['keterangan']) ?></span>
          <span class="badge badge-muted" style="margin-left:8px;"><?= h($j['sumber']) ?></span>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <span style="font-size:12px; color:var(--muted); font-family:var(--font-mono);"><?= h($j['no_bukti']) ?> · <?= fmt_tanggal($j['tanggal']) ?></span>
          <?php if ($j['sumber'] === 'Manual'): ?>
            <a href="keuangan_jurnal.php?delete=<?= $j['id'] ?>" style="color:var(--danger);" onclick="return confirm('Hapus jurnal manual ini?')">🗑</a>
          <?php endif; ?>
        </div>
      </div>
      <table class="data-table" style="font-size:12.5px;">
        <thead><tr><th>Akun</th><th style="text-align:right;">Debit</th><th style="text-align:right;">Kredit</th></tr></thead>
        <tbody>
          <?php foreach ($lines as $l): ?>
          <tr>
            <td><?= h($l['kode']) ?> — <?= h($l['akun_nama']) ?></td>
            <td style="text-align:right; font-family:var(--font-mono);"><?= $l['debit'] > 0 ? rupiah($l['debit']) : '-' ?></td>
            <td style="text-align:right; font-family:var(--font-mono);"><?= $l['kredit'] > 0 ? rupiah($l['kredit']) : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>