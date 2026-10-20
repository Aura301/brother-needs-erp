<?php
/* Invoice versi SETELAH TAMBAHAN (qty baru, total baru) buat dikirim bareng Excel selisih.
   Cuma tampilan/cetak - invoice asli di database TIDAK diubah. */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/selisih_helper.php';
ensure_invoice_jenis_schema($pdo);
ensure_selisih_schema($pdo);
$active = 'selisih';
$page_title = 'Invoice Setelah Tambahan';

$data = selisih_load($pdo, (int)($_GET['id'] ?? 0));
$inv = null;
if ($data) {
    foreach ($data['invoices'] as $x) if ((int)$x['id'] === (int)($_GET['inv'] ?? 0)) $inv = $x;
}
if (!$data || !$inv) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Invoice selisih tidak ditemukan.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$dok = $data['dok'];

$jenisKey = normalize_jenis($inv['jenis'] ?? 'utama');
$jenisCfg = invoice_jenis_config()[$jenisKey];
$company = get_company($pdo, $jenisCfg['company_id']);

// Item = qty setelah tambahan (barang yang qty-nya 0 gak ikut tampil)
$items = []; $total = 0;
foreach ($inv['items'] as $i) {
    $q = (float)$i['qty_setelah'];
    if ($q <= 0) continue;
    $sub = round($q * (float)$i['harga'], 2);
    $total += $sub;
    $items[] = ['nama' => $i['nama'], 'qty' => $q, 'satuan' => $i['satuan'], 'harga_jual' => (float)$i['harga'], 'subtotal' => $sub];
}

// Tanggal & nomor invoice tetap ikut invoice aslinya
$tglInvoice = $dok['tanggal'];
$orderId = (int)($inv['order_id'] ?? 0);
if ($orderId) {
    $os = $pdo->prepare("SELECT tanggal FROM orders WHERE id = ?");
    $os->execute([$orderId]);
    $t = $os->fetchColumn();
    if ($t) $tglInvoice = $t;
}
$order = ['id' => $orderId, 'invoice_no' => $inv['invoice_no'], 'tanggal' => $tglInvoice, 'sppg_id' => $dok['sppg_id'],
          'sppg_nama' => $dok['sppg_nama'], 'total' => $total, 'jenis' => $jenisKey];
$invTitle = 'Invoice';
$invRefLine = '';

$namaBulanSingkat = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$tsInvoice = strtotime($tglInvoice);
$page_title = date('j', $tsInvoice) . ' ' . $namaBulanSingkat[(int)date('n', $tsInvoice)] . ' ' . $dok['sppg_nama'] . ($jenisKey !== 'utama' ? ' - ' . $jenisCfg['short'] : '');
$no_title_suffix = true;
$logoSrc = file_exists(__DIR__ . '/assets/img/logo_' . $jenisKey . '.png') ? 'assets/img/logo_' . $jenisKey . '.png' : 'assets/img/logo.png';
$ttdSrc = file_exists(__DIR__ . '/assets/img/ttd_' . $jenisKey . '.png') ? 'assets/img/ttd_' . $jenisKey . '.png' : 'assets/img/ttd.png';
$isCompactInvoice = count($items) <= 10;

$sppgAlamat = '';
if (!empty($dok['sppg_id'])) {
    $sa = $pdo->prepare("SELECT alamat FROM sppg WHERE id = ?");
    $sa->execute([$dok['sppg_id']]);
    $sppgAlamat = $sa->fetchColumn();
}

$extra_head = '<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">';

include __DIR__ . '/includes/header.php';
?>

<div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:8px;">
  <a href="selisih_invoice.php?id=<?= $dok['id'] ?>" class="btn btn-ghost">← Kembali</a>
  <div style="display:flex; gap:8px;">
    <a href="selisih_excel.php?id=<?= $dok['id'] ?>" class="btn btn-subtle">⬇ Excel Selisih</a>
    <button onclick="window.print()" class="btn btn-accent">🖨 Download PDF</button>
  </div>
</div>

<div class="no-print alert alert-info" style="max-width:640px; margin-left:auto; margin-right:auto;">
  Ini invoice <b><?= h($inv['invoice_no']) ?></b> versi <b>setelah tambahan</b> (total <b><?= rupiah($total) ?></b>). Invoice aslinya di sistem gak berubah. Klik <b>Download PDF</b> → pilih "Save as PDF", lalu kirim bareng Excel selisihnya.
</div>

<?php include __DIR__ . '/includes/invoice_template.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
