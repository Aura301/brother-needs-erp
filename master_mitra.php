<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'mitra';
$page_title = 'Mitra';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $userId = current_user()['id'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO mitra (nama, jenis_kerjasama, kontak, alamat, tanggal_mulai, status, catatan, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        trim($_POST['nama']), trim($_POST['jenis_kerjasama']), trim($_POST['kontak']),
        trim($_POST['alamat']), $_POST['tanggal_mulai'] ?: null, $_POST['status'], trim($_POST['catatan']), $userId,
    ]);
    redirect('master_mitra.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM mitra WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('master_mitra.php');
}

$showAdd = isset($_GET['tambah']);
$filterStatus = $_GET['status'] ?? '';

$sql = "SELECT * FROM mitra WHERE 1=1";
$params = [];
if ($filterStatus !== '') { $sql .= " AND status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY nama ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mitras = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Mitra</div>
    <div class="page-sub">Daftar pihak yang menjalin kerja sama dengan Brother Needs.</div>
  </div>
  <a href="master_mitra.php?tambah=1" class="btn btn-accent">+ Tambah Mitra</a>
</div>

<form method="GET" class="card card-pad" style="margin-bottom:16px; max-width:280px;">
  <select name="status" onchange="this.form.submit()">
    <option value="">Semua Status</option>
    <option value="Aktif" <?= $filterStatus === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
    <option value="Tidak Aktif" <?= $filterStatus === 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
  </select>
</form>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-row grid-2">
      <div><label class="field-label">Nama Mitra</label><input type="text" name="nama" required></div>
      <div><label class="field-label">Jenis Kerjasama</label><input type="text" name="jenis_kerjasama" placeholder="Supplier / Distributor / SPPG / dll"></div>
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Kontak</label><input type="text" name="kontak" placeholder="No. HP / WhatsApp"></div>
      <div><label class="field-label">Tanggal Mulai Kerjasama</label><input type="date" name="tanggal_mulai"></div>
    </div>
    <div class="form-row">
      <label class="field-label">Alamat</label>
      <input type="text" name="alamat">
    </div>
    <div class="form-row grid-2">
      <div><label class="field-label">Status</label>
        <select name="status"><option value="Aktif">Aktif</option><option value="Tidak Aktif">Tidak Aktif</option></select>
      </div>
      <div></div>
    </div>
    <div class="form-row">
      <label class="field-label">Catatan</label>
      <textarea name="catatan" rows="2"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Simpan Mitra</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <?php if (empty($mitras)): ?>
    <div class="empty-state">
      <div class="icon">🤝</div>
      <div class="title">Belum ada mitra</div>
      <div>Tambahkan pihak yang bekerja sama dengan usaha kamu.</div>
    </div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Jenis Kerjasama</th><th>Kontak</th><th>Mulai</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($mitras as $m): ?>
      <tr>
        <td style="font-weight:600;"><?= h($m['nama']) ?></td>
        <td><?= h($m['jenis_kerjasama']) ?: '-' ?></td>
        <td><?= h($m['kontak']) ?: '-' ?></td>
        <td><?= $m['tanggal_mulai'] ? fmt_tanggal($m['tanggal_mulai']) : '-' ?></td>
        <td><span class="badge <?= $m['status']==='Aktif'?'badge-ok':'badge-muted' ?>"><?= h($m['status']) ?></span></td>
        <td style="text-align:right; white-space:nowrap;">
          <a href="master_mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-subtle btn-sm">✎ Edit</a>
          <a href="master_mitra.php?delete=<?= $m['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus mitra ini?')">🗑</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
