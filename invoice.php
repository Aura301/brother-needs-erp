<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'riwayat';
$page_title = 'Invoice';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
ensure_invoice_jenis_schema($pdo);

if (!$order) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Invoice tidak ditemukan.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Judul halaman dibuat "29 Sep Jurang Mangu Timur" biar pas di-Print/Save as PDF,
// nama file yang disaranin browser otomatis ngikutin format ini.
$namaBulanSingkat = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$tsInvoice = strtotime($order['tanggal']);
$jenisKey = normalize_jenis($order['jenis'] ?? 'utama');
$jenisCfg = invoice_jenis_config()[$jenisKey];
$page_title = date('j', $tsInvoice) . ' ' . $namaBulanSingkat[(int)date('n', $tsInvoice)] . ' ' . $order['sppg_nama'] . ($jenisKey !== 'utama' ? ' - ' . $jenisCfg['short'] : '');
$backUrl = 'riwayat.php' . ($jenisKey !== 'utama' ? '?jenis=' . $jenisKey : '');
// Logo & tanda tangan khusus jenis invoice (opsional): taruh file assets/img/logo_primova_buah.png / ttd_primova_buah.png
$logoSrc = file_exists(__DIR__ . '/assets/img/logo_' . $jenisKey . '.png') ? 'assets/img/logo_' . $jenisKey . '.png' : 'assets/img/logo.png';
$ttdSrc = file_exists(__DIR__ . '/assets/img/ttd_' . $jenisKey . '.png') ? 'assets/img/ttd_' . $jenisKey . '.png' : 'assets/img/ttd.png';
$no_title_suffix = true;

$itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$sppgStmt = $pdo->prepare("SELECT alamat FROM sppg WHERE id = ?");
$sppgStmt->execute([$order['sppg_id']]);
$sppgAlamat = $sppgStmt->fetchColumn();

// Efek "narik penuh 1 halaman" cuma aman buat order pendek - kalau item-nya banyak
// dan otomatis lanjut ke halaman berikutnya, flex-stretch malah bikin browser gagal
// mecah konten antar halaman dengan benar. Jadi cuma dipasang kalau item dikit.
$isCompactInvoice = count($items) <= 10;

$lunasByUser = null;
if ($order['lunas_by']) {
    $uStmt = $pdo->prepare("SELECT nama FROM users WHERE id = ?");
    $uStmt->execute([$order['lunas_by']]);
    $lunasByUser = $uStmt->fetchColumn();
}

$company = get_company($pdo, $jenisCfg['company_id']);

$extra_head = '<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">';

// teks siap kirim ke WhatsApp
$lines = [];
$lines[] = "*INVOICE {$company['legal_name']}*";
$lines[] = "No: {$order['invoice_no']}";
$lines[] = "Tanggal: " . fmt_tanggal($order['tanggal']);
$lines[] = "Kepada: {$order['sppg_nama']}";
$lines[] = "";
foreach ($items as $i) {
    $lines[] = "- {$i['nama']} {$i['qty']} {$i['satuan']} x " . rupiah($i['harga_jual']) . " = " . rupiah($i['subtotal']);
}
$lines[] = "";
$lines[] = "*Total: " . rupiah($order['total']) . "*";
$lines[] = "Status: {$order['status']}";
$lines[] = "";
$lines[] = "Pembayaran ke " . trim($company['bank_name'] . ' ' . $company['account_number']) . " a.n {$company['account_name']}";
$waText = urlencode(implode("\n", $lines));

include __DIR__ . '/includes/header.php';
?>

<div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
  <a href="<?= h($backUrl) ?>" class="btn btn-ghost">← Kembali</a>
  <div style="display:flex; gap:8px;">
    <?php if ($order['status'] !== 'Lunas'): ?>
      <a href="edit_order.php?id=<?= $order['id'] ?>" class="btn btn-subtle">✎ Edit Order</a>
      <a href="tandai_lunas.php?id=<?= $order['id'] ?>" class="btn btn-primary">✓ Tandai Lunas</a>
    <?php else: ?>
      <a href="riwayat.php?set_status=belum_bayar&id=<?= $order['id'] ?>" class="btn btn-ghost" onclick="return confirm('Batalkan status Lunas? Ini bakal ngehapus juga catatan bukti transfernya.')">↩ Batalkan Lunas</a>
    <?php endif; ?>
    <a href="selisih_buat.php?order_id=<?= $order['id'] ?>" class="btn btn-subtle">➕ Invoice Selisih</a>
    <a href="https://wa.me/?text=<?= $waText ?>" target="_blank" class="btn btn-subtle">💬 Siapkan Teks WhatsApp</a>
    <button onclick="window.print()" class="btn btn-accent">🖨 Download PDF</button>
  </div>
</div>

<?php if (isset($_GET['lunas_confirmed'])): ?>
  <div class="no-print alert alert-ok" style="max-width:640px; margin-left:auto; margin-right:auto;">
    ✓ Pembayaran udah dikonfirmasi Lunas, bukti transfer tersimpan.
  </div>
<?php endif; ?>
<?php if (isset($_GET['edited'])): ?>
  <div class="no-print alert alert-ok" style="max-width:640px; margin-left:auto; margin-right:auto;">
    ✓ Perubahan invoice tersimpan.
  </div>
<?php endif; ?>
<?php if (isset($_GET['split_created'])): ?>
  <div class="no-print alert alert-ok" style="max-width:640px; margin-left:auto; margin-right:auto;">
    ✓ Invoice baru berhasil dibuat dari hasil pemisahan item.
  </div>
<?php endif; ?>
<?php if (isset($_GET['edit_blocked'])): ?>
  <div class="no-print alert alert-danger" style="max-width:640px; margin-left:auto; margin-right:auto;">
    ⚠ Invoice yang udah Lunas gak bisa diedit/dipisah lagi.
  </div>
<?php endif; ?>

<?php if ($order['status'] === 'Lunas' && $order['bukti_bayar']): ?>
  <div class="no-print card card-pad" style="max-width:640px; margin:0 auto 14px; display:flex; justify-content:space-between; align-items:center;">
    <div style="font-size:12.5px;">
      <div style="font-weight:700; margin-bottom:2px;">🧾 Bukti transfer tersimpan</div>
      <div style="color:var(--muted);">Dikonfirmasi oleh <?= h($lunasByUser ?: '-') ?> · <?= $order['lunas_at'] ? date('d M Y H:i', strtotime($order['lunas_at'])) : '-' ?></div>
    </div>
    <a href="assets/uploads/bukti_bayar/<?= h($order['bukti_bayar']) ?>" target="_blank" class="btn btn-subtle btn-sm">Lihat Bukti →</a>
  </div>
<?php endif; ?>

<div class="no-print alert alert-info" style="max-width:640px; margin-left:auto; margin-right:auto;">
  <b>Cara kirim ke customer:</b> klik <b>Download PDF</b> → di kotak print, pilih "Save as PDF" untuk simpan filenya. Lalu buka WhatsApp, lampirkan file PDF itu, dan tempel teks dari tombol <b>Siapkan Teks WhatsApp</b>. Browser belum bisa lampirkan file ke WA secara otomatis, jadi 2 langkah ini caranya.
</div>

<?php include __DIR__ . '/includes/invoice_template.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>