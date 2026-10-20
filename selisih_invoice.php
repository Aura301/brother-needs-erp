<?php
/* Halaman lihat hasil Selisih Order Tambahan (tampilan sama kayak isi file Excel-nya). */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/selisih_helper.php';
ensure_selisih_schema($pdo);
$active = 'selisih';
$page_title = 'Selisih Order Tambahan';

$data = selisih_load($pdo, (int)($_GET['id'] ?? 0));
if (!$data) {
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Dokumen selisih tidak ditemukan.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$dok = $data['dok'];
$fmtQ = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ','); };
$fmtR = function ($n) { return $n == 0 ? '-' : ($n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.')); };
$totAwal = $totSetelah = 0;

include __DIR__ . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:8px;">
  <a href="selisih.php" class="btn btn-ghost">← Kembali</a>
  <div style="display:flex; gap:8px;">
    <a href="selisih_buat.php?id=<?= $dok['id'] ?>" class="btn btn-subtle">✎ Edit</a>
    <a href="selisih_excel.php?id=<?= $dok['id'] ?>" class="btn btn-accent">⬇ Download Excel</a>
  </div>
</div>

<div class="card card-pad" style="margin-bottom:14px;">
  <div style="font-weight:700; font-size:13.5px; margin-bottom:8px;">Yang dikirim ke SPPG</div>
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <a href="selisih_excel.php?id=<?= $dok['id'] ?>" class="btn btn-accent btn-sm">⬇ Excel Selisih</a>
    <?php foreach ($data['invoices'] as $inv): ?>
      <a href="selisih_cetak.php?id=<?= $dok['id'] ?>&inv=<?= $inv['id'] ?>" class="btn btn-primary btn-sm">🧾 Invoice <?= h($inv['invoice_no']) ?> (setelah tambahan)</a>
    <?php endforeach; ?>
  </div>
  <div style="font-size:11.5px; color:var(--muted); margin-top:8px;">Tiap invoice dibuka dulu, lalu klik <b>Download PDF</b> (Save as PDF). Invoice asli di sistem gak berubah.</div>
</div>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-ok">✓ Tersimpan. Invoice asli dan pembukuan gak berubah. Download Excel dan invoice versi setelah tambahan di kotak di bawah ini.</div>
<?php endif; ?>

<div class="card card-pad" style="overflow-x:auto;">
  <div style="font-weight:700; font-size:16px;">SELISIH ORDER TAMBAHAN - <?= h($dok['sppg_nama']) ?></div>
  <div style="font-size:12px; font-style:italic; color:var(--muted); margin-bottom:14px;"><?= h($dok['ket_header']) ?></div>

  <table style="border-collapse:collapse; font-size:12.5px; min-width:900px; width:100%;">
    <thead>
      <tr style="background:#2F5D3A; color:#fff;">
        <?php foreach (['No','Barang','Satuan','Harga Satuan','Qty Awal','Qty Setelah Tambahan','Selisih Qty','Total Awal','Total Setelah','Selisih (Rp)','Keterangan'] as $hd): ?>
          <th style="padding:8px 8px; text-align:center;"><?= $hd ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($data['invoices'] as $inv):
        $sAwal = $sSet = 0; ?>
      <tr style="background:#E8F0E6; font-weight:700;"><td colspan="11" style="padding:6px 8px;"><?= h($inv['label']) ?> (Invoice <?= h($inv['invoice_no']) ?>)</td></tr>
      <?php $no = 1; foreach ($inv['items'] as $i):
          $qa = (float)$i['qty_awal']; $qs = (float)$i['qty_setelah']; $hg = (float)$i['harga'];
          $ta = $hg * $qa; $ts = $hg * $qs; $sAwal += $ta; $sSet += $ts; ?>
      <tr style="border-bottom:1px solid #ccc;">
        <td style="padding:5px 8px; text-align:right;"><?= $no++ ?></td>
        <td style="padding:5px 8px;"><?= h($i['nama']) ?></td>
        <td style="padding:5px 8px;"><?= h($i['satuan']) ?></td>
        <td style="padding:5px 8px; text-align:right;"><?= number_format($hg, 0, ',', '.') ?></td>
        <td style="padding:5px 8px; text-align:right; color:#0000FF;"><?= $fmtQ($qa) ?: '0' ?></td>
        <td style="padding:5px 8px; text-align:right; color:#0000FF;"><?= $fmtQ($qs) ?: '0' ?></td>
        <td style="padding:5px 8px; text-align:right;"><?= $fmtQ($qs - $qa) ?: '0' ?></td>
        <td style="padding:5px 8px; text-align:right;"><?= $fmtR($ta) ?></td>
        <td style="padding:5px 8px; text-align:right;"><?= $fmtR($ts) ?></td>
        <td style="padding:5px 8px; text-align:right;"><?= $fmtR($ts - $ta) ?></td>
        <td style="padding:5px 8px;"><?= h(selisih_keterangan($qa, $qs)) ?></td>
      </tr>
      <?php endforeach; $totAwal += $sAwal; $totSetelah += $sSet; ?>
      <tr style="background:#F3F3F3; font-weight:700; border-bottom:1px solid #ccc;">
        <td></td><td colspan="6" style="padding:6px 8px;">Subtotal <?= h(mb_convert_case(mb_strtolower($inv['label']), MB_CASE_TITLE)) ?></td>
        <td style="padding:6px 8px; text-align:right;"><?= $fmtR($sAwal) ?></td>
        <td style="padding:6px 8px; text-align:right;"><?= $fmtR($sSet) ?></td>
        <td style="padding:6px 8px; text-align:right;"><?= $fmtR($sSet - $sAwal) ?></td><td></td>
      </tr>
      <tr><td colspan="11" style="height:10px;"></td></tr>
    <?php endforeach; ?>
      <tr style="background:#D9E8D5; font-weight:700;">
        <td></td><td colspan="6" style="padding:7px 8px;">TOTAL SEMUA</td>
        <td style="padding:7px 8px; text-align:right;"><?= $fmtR($totAwal) ?></td>
        <td style="padding:7px 8px; text-align:right;"><?= $fmtR($totSetelah) ?></td>
        <td style="padding:7px 8px; text-align:right;"><?= $fmtR($totSetelah - $totAwal) ?></td><td></td>
      </tr>
    </tbody>
  </table>
  <div style="font-size:11.5px; color:var(--muted); margin-top:12px;">Teks biru = angka input dari invoice; hitungan lainnya pakai rumus (di file Excel).</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
