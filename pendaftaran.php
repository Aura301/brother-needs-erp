<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'pendaftaran';
$page_title = 'Pendaftaran Masuk';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("UPDATE pendaftaran SET status = ?, catatan_admin = ? WHERE id = ?");
    $stmt->execute([$_POST['status'], trim($_POST['catatan_admin']), $id]);
    redirect('pendaftaran.php');
}

if (isset($_GET['jadikan_sppg'])) {
    $id = (int)$_GET['jadikan_sppg'];
    $stmt = $pdo->prepare("SELECT * FROM pendaftaran WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if ($p) {
        $ins = $pdo->prepare("INSERT INTO sppg (nama, alamat, kontak, status) VALUES (?,?,?, 'Aktif')");
        $ins->execute([$p['nama_usaha'], $p['alamat'], $p['whatsapp']]);
        $upd = $pdo->prepare("UPDATE pendaftaran SET status = 'Disetujui' WHERE id = ?");
        $upd->execute([$id]);
        redirect('master_sppg.php?dari_pendaftaran=1');
    }
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM pendaftaran WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('pendaftaran.php');
}

$filterStatus = $_GET['status'] ?? '';
$sql = "SELECT * FROM pendaftaran WHERE 1=1";
$params = [];
if ($filterStatus !== '') { $sql .= " AND status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$jumlahBaru = (int)$pdo->query("SELECT COUNT(*) c FROM pendaftaran WHERE status = 'Baru'")->fetch()['c'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Pendaftaran Masuk</div>
    <div class="page-sub">Daftar mitra/SPPG yang kirim form dari halaman publik <code>daftar.php</code>.</div>
  </div>
</div>

<?php if ($jumlahBaru > 0): ?>
  <div class="alert alert-info">🔔 Ada <b><?= $jumlahBaru ?> pendaftaran baru</b> yang belum ditindaklanjuti.</div>
<?php endif; ?>

<form method="GET" class="card card-pad" style="margin-bottom:16px; max-width:280px;">
  <select name="status" onchange="this.form.submit()">
    <option value="">Semua Status</option>
    <option value="Baru" <?= $filterStatus === 'Baru' ? 'selected' : '' ?>>Baru</option>
    <option value="Dihubungi" <?= $filterStatus === 'Dihubungi' ? 'selected' : '' ?>>Dihubungi</option>
    <option value="Disetujui" <?= $filterStatus === 'Disetujui' ? 'selected' : '' ?>>Disetujui</option>
    <option value="Ditolak" <?= $filterStatus === 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
  </select>
</form>

<?php if (empty($list)): ?>
  <div class="card"><div class="empty-state"><div class="icon">📋</div><div class="title">Belum ada pendaftaran</div></div></div>
<?php else: foreach ($list as $p): $fid = 'f' . $p['id']; ?>
<div class="card card-pad" style="margin-bottom:14px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
    <div>
      <div style="font-weight:700; font-size:15px;"><?= h($p['nama_usaha']) ?></div>
      <div style="font-size:12.5px; color:var(--muted);">PIC: <?= h($p['nama_pic']) ?> · WA: <?= h($p['whatsapp']) ?></div>
      <?php if ($p['alamat']): ?><div style="font-size:12.5px; color:var(--muted);">📍 <?= h($p['alamat']) ?></div><?php endif; ?>
    </div>
    <span class="badge <?= $p['status']==='Baru'?'badge-danger':($p['status']==='Disetujui'?'badge-ok':'badge-muted') ?>"><?= h($p['status']) ?></span>
  </div>

  <?php if ($p['kebutuhan']): ?>
    <div style="background:var(--bg); border-radius:8px; padding:10px 12px; font-size:13px; margin-bottom:10px;"><?= nl2br(h($p['kebutuhan'])) ?></div>
  <?php endif; ?>

  <div style="font-size:11.5px; color:var(--muted); margin-bottom:10px;">Masuk: <?= date('d M Y H:i', strtotime($p['created_at'])) ?></div>

  <form method="POST" id="<?= $fid ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="id" value="<?= $p['id'] ?>">
    <div style="flex:1; min-width:160px;">
      <label class="field-label">Status</label>
      <select name="status">
        <option value="Baru" <?= $p['status']==='Baru'?'selected':'' ?>>Baru</option>
        <option value="Dihubungi" <?= $p['status']==='Dihubungi'?'selected':'' ?>>Dihubungi</option>
        <option value="Disetujui" <?= $p['status']==='Disetujui'?'selected':'' ?>>Disetujui</option>
        <option value="Ditolak" <?= $p['status']==='Ditolak'?'selected':'' ?>>Ditolak</option>
      </select>
    </div>
    <div style="flex:2; min-width:200px;">
      <label class="field-label">Catatan Admin</label>
      <input type="text" name="catatan_admin" value="<?= h($p['catatan_admin']) ?>" placeholder="opsional">
    </div>
    <button type="submit" class="btn btn-subtle btn-sm">💾 Simpan</button>
  </form>

  <div style="display:flex; gap:8px; margin-top:10px;">
    <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $p['whatsapp']) ?>" target="_blank" class="btn btn-subtle btn-sm">💬 Chat WA</a>
    <a href="pendaftaran.php?jadikan_sppg=<?= $p['id'] ?>" class="btn btn-primary btn-sm" onclick="return confirm('Jadikan SPPG aktif? Bakal langsung muncul di pilihan Buat Order.')">✓ Jadikan SPPG</a>
    <a href="pendaftaran.php?delete=<?= $p['id'] ?>" class="btn btn-sm" style="color:var(--danger); margin-left:auto;" onclick="return confirm('Hapus pendaftaran ini?')">🗑 Hapus</a>
  </div>
</div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>