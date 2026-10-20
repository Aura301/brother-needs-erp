<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/selisih_helper.php';
ensure_invoice_jenis_schema($pdo);
ensure_selisih_schema($pdo);
$active = 'selisih';
$page_title = 'Invoice Selisih';
$jenisCfgAll = invoice_jenis_config();

// Hapus dokumen selisih (cuma dokumen - invoice asli & pembukuan gak tersentuh)
if (isset($_GET['hapus'])) {
    $hid = (int)$_GET['hapus'];
    foreach (['selisih_dok_item', 'selisih_dok_invoice'] as $t) $pdo->prepare("DELETE FROM $t WHERE dok_id = ?")->execute([$hid]);
    $pdo->prepare("DELETE FROM selisih_dok WHERE id = ?")->execute([$hid]);
    header('Location: selisih.php?dihapus=1');
    exit;
}

$sppgFilter = trim($_GET['sppg'] ?? '');
$cari = trim($_GET['cari'] ?? '');
$semuaWaktu = !empty($_GET['semua']);

$tampil = ($sppgFilter !== '' || $cari !== '');   // daftar invoice baru muncul setelah pilih SPPG / cari nomor
$orders = [];
if ($tampil) {
    $sql = "SELECT id, invoice_no, tanggal, sppg_nama, total, jenis FROM orders WHERE 1=1";
    $params = [];
    if (!$semuaWaktu) { $sql .= " AND tanggal >= ?"; $params[] = date('Y-m-d', strtotime('-30 days')); }
    if ($sppgFilter !== '') { $sql .= " AND sppg_nama = ?"; $params[] = $sppgFilter; }
    if ($cari !== '') { $sql .= " AND invoice_no LIKE ?"; $params[] = '%' . $cari . '%'; }
    $sql .= " ORDER BY tanggal DESC, id DESC LIMIT 80";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
}
$daftarSppg = $pdo->query("SELECT DISTINCT sppg_nama FROM orders ORDER BY sppg_nama ASC")->fetchAll(PDO::FETCH_COLUMN);

$dokList = $pdo->query("SELECT d.*, (SELECT GROUP_CONCAT(invoice_no ORDER BY urut SEPARATOR ' + ') FROM selisih_dok_invoice WHERE dok_id = d.id) AS inv_list
                        FROM selisih_dok d ORDER BY d.tanggal DESC, d.id DESC LIMIT 100")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">➕ Invoice Selisih</div>
    <div class="page-sub">Bikin Excel <b>Selisih Order Tambahan</b> (Qty Awal vs Qty Setelah Tambahan) dari satu atau beberapa invoice. Invoice asli <b>gak diubah</b> dan gak masuk pembukuan.</div>
  </div>
</div>

<?php if (isset($_GET['dihapus'])): ?>
  <div class="alert alert-ok">Dokumen selisih dihapus. Invoice asli gak berubah.</div>
<?php endif; ?>
<?php if (!empty($_GET['err'])): ?>
  <div class="alert alert-danger"><?= h($_GET['err']) ?></div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px;">
  <div style="font-weight:700; font-size:14px; margin-bottom:4px;">1. Pilih SPPG dulu, baru centang invoice yang mau dibikinin selisih</div>
  <div style="font-size:12px; color:var(--muted); margin-bottom:10px;">Daftar invoice baru muncul setelah lo pilih SPPG (atau cari nomor invoice). Bisa centang lebih dari satu (misal invoice sayur + invoice buah) asal SPPG-nya sama.</div>

  <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
    <select name="sppg" onchange="this.form.submit()" style="min-width:220px;">
      <option value="">— Pilih SPPG dulu —</option>
      <?php foreach ($daftarSppg as $s): ?>
        <option value="<?= h($s) ?>" <?= $sppgFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="cari" value="<?= h($cari) ?>" placeholder="Cari nomor invoice, misal BN10" style="min-width:200px;">
    <label style="font-size:12.5px; color:var(--muted); display:flex; align-items:center; gap:5px;">
      <input type="checkbox" name="semua" value="1" <?= $semuaWaktu ? 'checked' : '' ?> onchange="this.form.submit()"> Semua waktu (default 30 hari terakhir)
    </label>
    <button type="submit" class="btn btn-subtle btn-sm">Tampilkan Invoice</button>
  </form>

  <?php if (!$tampil): ?>
    <div class="empty-state"><div class="icon">👆</div><div class="title">Pilih SPPG di atas dulu</div><div style="font-size:12.5px; color:var(--muted);">Biar gak ada invoice yang ke-centang gak sengaja, daftar invoice disembunyiin sampai SPPG dipilih.</div></div>
  <?php elseif (empty($orders)): ?>
    <div class="empty-state"><div class="icon">🧾</div><div class="title">Gak ada invoice yang cocok</div><div style="font-size:12.5px; color:var(--muted);">Coba centang "Semua waktu" kalau invoicenya lebih lama dari 30 hari.</div></div>
  <?php else: ?>
  <form method="GET" action="selisih_buat.php" id="formPilih">
    <div style="overflow:hidden; border:1px solid var(--border); border-radius:10px;">
    <table class="data-table">
      <thead><tr><th style="width:36px;"></th><th>Invoice</th><th>Jenis</th><th>Tanggal</th><th>SPPG</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td><input type="checkbox" name="order_ids[]" value="<?= $o['id'] ?>" class="cbInv" data-no="<?= h($o['invoice_no']) ?>" data-sppg="<?= h($o['sppg_nama']) ?>" data-total="<?= (float)$o['total'] ?>"></td>
          <td style="font-family:var(--font-mono); font-weight:600;"><?= h($o['invoice_no']) ?></td>
          <td style="font-size:12px; color:var(--muted);"><?= h($jenisCfgAll[$o['jenis'] ?? 'utama']['short'] ?? 'Utama') ?></td>
          <td><?= fmt_tanggal($o['tanggal']) ?></td>
          <td><?= h($o['sppg_nama']) ?></td>
          <td style="font-family:var(--font-mono); font-weight:700;"><?= rupiah($o['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div style="margin-top:12px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary" id="btnLanjut" disabled style="opacity:.5;">Lanjut: isi qty setelah tambahan →</button>
      <span id="infoPilih" style="font-size:13px; color:var(--muted);">Belum ada invoice yang dicentang</span>
    </div>
  </form>
  <script>
  (function(){
    var cbs = document.querySelectorAll('.cbInv'), btn = document.getElementById('btnLanjut'),
        info = document.getElementById('infoPilih'), form = document.getElementById('formPilih');
    function rp(n){ return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
    function pilih(){ return Array.prototype.filter.call(cbs, function(c){ return c.checked; }); }
    function update(){
      var p = pilih(), tot = 0, nos = [];
      p.forEach(function(c){ tot += parseFloat(c.dataset.total) || 0; nos.push(c.dataset.no); });
      btn.disabled = p.length === 0; btn.style.opacity = p.length ? 1 : .5;
      info.innerHTML = p.length ? '<b>' + p.length + ' invoice dipilih</b>: ' + nos.join(' + ') + ' (total ' + rp(tot) + ')' : 'Belum ada invoice yang dicentang';
    }
    cbs.forEach(function(c){ c.addEventListener('change', update); });
    form.addEventListener('submit', function(e){
      var p = pilih();
      if (!p.length) { e.preventDefault(); return; }
      var sp = {}; p.forEach(function(c){ sp[c.dataset.sppg] = 1; });
      if (Object.keys(sp).length > 1) { e.preventDefault(); alert('Invoice yang dipilih harus dari SPPG yang sama.'); return; }
      var msg = 'Bikin selisih untuk:\n' + p.map(function(c){ return '• ' + c.dataset.no + ' (' + rp(parseFloat(c.dataset.total)) + ')'; }).join('\n') + '\n\nSPPG: ' + p[0].dataset.sppg + '\nLanjut?';
      if (!confirm(msg)) e.preventDefault();
    });
    update();
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card" style="overflow:hidden;">
  <div style="padding:14px 18px; font-weight:700; font-size:14px;">Selisih yang Udah Dibuat (<?= count($dokList) ?>)</div>
  <?php if (empty($dokList)): ?>
    <div class="empty-state"><div class="icon">➕</div><div class="title">Belum ada dokumen selisih</div></div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Tanggal</th><th>SPPG</th><th>Invoice</th><th>Total Selisih</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($dokList as $r): ?>
        <tr>
          <td><?= fmt_tanggal($r['tanggal']) ?></td>
          <td><?= h($r['sppg_nama']) ?></td>
          <td style="font-family:var(--font-mono);"><?= h($r['inv_list']) ?></td>
          <td style="font-family:var(--font-mono); font-weight:700;"><?= rupiah($r['total_selisih']) ?></td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="selisih_invoice.php?id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">Lihat →</a>
            <a href="selisih_excel.php?id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">⬇ Excel</a>
            <a href="selisih_buat.php?id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
            <a href="selisih.php?hapus=<?= $r['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus dokumen selisih ini? Invoice asli dan pembukuan gak berubah.')">Hapus</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
