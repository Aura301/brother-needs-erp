<?php
/* Template tampilan invoice (dipakai invoice.php dan selisih_invoice.php).
   Butuh: $jenisKey, $order, $items, $company, $sppgAlamat, $logoSrc, $ttdSrc, $tsInvoice, $isCompactInvoice.
   Opsional: $invTitle (default 'Invoice'), $invRefLine (teks kecil di bawah nomor invoice). */
$invTitle = $invTitle ?? 'Invoice';
$invRefLine = $invRefLine ?? '';
?>
<?php if ($jenisKey === 'primova_buah'): ?>
<?php
$pfTheme = ['#1F6FB2', '#D6EAF3'];
$pfHasLogo = file_exists(dirname(__DIR__) . '/assets/img/logo_' . $jenisKey . '.png');
$hariID = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulanID = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tglPanjang = $hariID[(int)date('w', $tsInvoice)] . ', ' . date('d', $tsInvoice) . ' ' . $bulanID[(int)date('n', $tsInvoice)] . ' ' . date('Y', $tsInvoice);
$pfMinRows = 10;
$pfBlank = max(0, $pfMinRows - count($items));
$pfNum = function ($n) { return number_format((float)$n, 0, ',', '.'); };
?>
<style>
.pf-inv { max-width:640px; margin:0 auto; background:#fff; color:#111; padding:34px 36px 30px; font-family:'Poppins',Calibri,Arial,sans-serif; border:1px solid #e3e6ea; border-radius:10px; }
.pf-inv .pf-head { text-align:center; padding-bottom:12px; border-bottom:1.5px solid var(--pf); }
.pf-inv .pf-head img { height:78px; width:auto; display:block; margin:0 auto 10px; }
.pf-inv .pf-brand { font-size:24px; font-weight:700; color:var(--pf); letter-spacing:.01em; line-height:1.2; }
.pf-inv .pf-tag { font-size:10px; color:#222; margin-top:3px; }
.pf-inv .pf-title { font-size:34px; font-weight:700; color:var(--pf); margin:14px 0 10px; line-height:1.1; }
.pf-inv .pf-meta { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; font-size:10.5px; margin-bottom:14px; }
.pf-inv .pf-meta .pf-k { font-weight:700; }
.pf-inv .pf-to { font-weight:700; color:var(--pf); font-size:11.5px; margin-top:2px; }
.pf-inv .pf-addr { font-size:10px; color:#222; margin-top:2px; max-width:300px; white-space:pre-line; }
.pf-inv .pf-right { text-align:right; }
.pf-inv .pf-date { font-weight:700; color:var(--pf); font-size:11px; margin-top:6px; }
.pf-inv .pf-no { font-weight:700; color:var(--pf); font-size:18px; margin-top:2px; }
.pf-inv table.pf-table { width:100%; border-collapse:collapse; border:1px solid var(--pf); }
.pf-inv .pf-table th { background:var(--pf); color:#fff; font-size:10.5px; font-weight:600; padding:7px 8px; text-align:center; border:1px solid var(--pf); }
.pf-inv .pf-table td { font-size:10.5px; padding:6px 8px; border:1px solid var(--pf); height:20px; }
.pf-inv .pf-table tbody tr:nth-child(even) td { background:var(--pf-alt); }
.pf-inv .pf-c { text-align:center; }
.pf-inv .pf-tot { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
.pf-inv .pf-pay { font-size:10.5px; padding-top:6px; }
.pf-inv .pf-pay .pf-ph { color:var(--pf); font-weight:700; font-size:12.5px; margin-bottom:8px; }
.pf-inv .pf-pay div { margin-bottom:2px; font-weight:700; }
.pf-inv .pf-sum { width:48%; border-collapse:collapse; font-size:10.5px; }
.pf-inv .pf-sum td { border:1px solid var(--pf); border-top:none; padding:6px 8px; }
.pf-inv .pf-sum .pf-sub td { text-align:center; }
.pf-inv .pf-sum .pf-sub td:first-child { color:var(--pf); font-weight:700; }
.pf-inv .pf-sum .pf-grand td { background:var(--pf); color:#fff; font-weight:700; text-align:center; }
@media print {
  .pf-inv { border:none; border-radius:0; padding:14px 18px; max-width:100%; }
  .pf-inv table.pf-table tr { page-break-inside:avoid; }
  .pf-inv .pf-tot { page-break-inside:avoid; }
}
</style>
<div class="pf-inv print-section" id="invoice-print" style="--pf:<?= $pfTheme[0] ?>; --pf-alt:<?= $pfTheme[1] ?>;">
  <div class="pf-head">
    <?php if ($pfHasLogo): ?>
      <img src="<?= h($logoSrc) ?>" alt="<?= h($company['brand_name']) ?>">
    <?php endif; ?>
    <div class="pf-brand"><?= h($company['legal_name']) ?></div>
    <?php if (!empty($company['tagline'])): ?><div class="pf-tag"><?= h($company['tagline']) ?></div><?php endif; ?>
  </div>

  <div class="pf-title"><?= h($invTitle) ?></div>

  <div class="pf-meta">
    <div>
      <div class="pf-k">Kepada :</div>
      <div class="pf-to"><?= h($order['sppg_nama']) ?></div>
      <?php if (!empty($sppgAlamat)): ?><div class="pf-addr"><?= h($sppgAlamat) ?></div><?php endif; ?>
    </div>
    <div class="pf-right">
      <div>Tanggal</div>
      <div class="pf-date"><?= h($tglPanjang) ?></div>
      <div class="pf-no">#<?= h($order['invoice_no']) ?></div>
      <?php if ($invRefLine !== ''): ?><div style="font-size:9.5px; margin-top:3px;"><?= h($invRefLine) ?></div><?php endif; ?>
    </div>
  </div>

  <table class="pf-table">
    <thead>
      <tr>
        <th style="text-align:left; width:36%;">Nama Barang</th>
        <th style="width:12%;">Qty</th>
        <th style="width:12%;">Satuan</th>
        <th style="width:20%;">Harga Satuan</th>
        <th style="width:20%;">Jumlah Harga</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i): ?>
      <tr>
        <td><?= h($i['nama']) ?></td>
        <td class="pf-c"><?= $i['qty'] + 0 ?></td>
        <td class="pf-c"><?= h($i['satuan']) ?></td>
        <td class="pf-c"><?= $pfNum($i['harga_jual']) ?></td>
        <td class="pf-c"><?= $pfNum($i['subtotal']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php for ($b = 0; $b < $pfBlank; $b++): ?>
      <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <div class="pf-tot">
    <div class="pf-pay">
      <div class="pf-ph">Informasi Pembayaran</div>
      <div>No. Rekening : <?= h($company['account_number']) ?></div>
      <div>Nama : <?= h($company['account_name']) ?></div>
    </div>
    <table class="pf-sum">
      <tr class="pf-sub"><td>Sub-total</td><td><?= rupiah($order['total']) ?></td></tr>
      <tr class="pf-grand"><td>Total Harga</td><td><?= rupiah($order['total']) ?></td></tr>
    </table>
  </div>
</div>
<?php elseif ($jenisKey === 'frozen'): ?>
<?php
$hariID = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulanID = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tglPanjang = date('d', $tsInvoice) . ' ' . $bulanID[(int)date('n', $tsInvoice)] . ' ' . date('Y', $tsInvoice);
$fzNum = function ($n) { return number_format((float)$n, 0, ',', '.'); };
$fzHasLogo = file_exists(dirname(__DIR__) . '/assets/img/logo_frozen.png');
$fzHasTtd = file_exists(dirname(__DIR__) . '/assets/img/ttd_frozen.png');
?>
<style>
.fz-inv { max-width:640px; margin:0 auto; background:#fff; color:#000; padding:36px 40px 32px; font-family:'Poppins',Arial,sans-serif; border:1px solid #d5d5d5; border-radius:6px; font-size:11px; }
.fz-inv .fz-top { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; padding-bottom:12px; border-bottom:2px solid #000; }
.fz-inv .fz-co { display:flex; align-items:center; gap:12px; }
.fz-inv .fz-co img { height:48px; width:auto; }
.fz-inv .fz-name { font-size:19px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; line-height:1.2; }
.fz-inv .fz-sub { font-size:10px; color:#444; margin-top:3px; line-height:1.45; }
.fz-inv .fz-title { font-size:24px; font-weight:600; letter-spacing:.2em; text-align:right; }
.fz-inv .fz-meta { display:flex; justify-content:space-between; gap:20px; margin:18px 0 16px; }
.fz-inv .fz-lbl { font-size:9.5px; letter-spacing:.08em; text-transform:uppercase; color:#555; margin-bottom:3px; }
.fz-inv .fz-to { font-weight:700; font-size:12px; }
.fz-inv .fz-addr { font-size:10px; color:#333; margin-top:2px; max-width:300px; }
.fz-inv .fz-r { text-align:right; }
.fz-inv .fz-val { font-weight:600; font-size:11.5px; }
.fz-inv table.fz-table { width:100%; border-collapse:collapse; }
.fz-inv .fz-table th { font-size:10.5px; font-weight:700; padding:8px 6px; border-top:1.5px solid #000; border-bottom:1.5px solid #000; text-align:center; }
.fz-inv .fz-table td { font-size:10.5px; padding:7px 6px; border-bottom:1px solid #c8c8c8; }
.fz-inv .fz-table tbody tr:last-child td { border-bottom:1.5px solid #000; }
.fz-inv .fz-c { text-align:center; }
.fz-inv .fz-rt { text-align:right; }
.fz-inv .fz-bot { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-top:14px; }
.fz-inv .fz-pay { font-size:10.5px; line-height:1.6; }
.fz-inv .fz-pay b { display:block; margin-bottom:2px; }
.fz-inv .fz-sum { width:46%; font-size:11px; }
.fz-inv .fz-sum div { display:flex; justify-content:space-between; padding:4px 0; }
.fz-inv .fz-sum .fz-grand { border-top:1.5px solid #000; margin-top:3px; padding-top:7px; font-weight:700; font-size:12.5px; }
.fz-inv .fz-sign { margin-top:34px; display:flex; justify-content:flex-end; }
.fz-inv .fz-sign > div { text-align:center; min-width:170px; font-size:10.5px; }
.fz-inv .fz-sign .fz-line { border-top:1px solid #000; margin-top:6px; padding-top:4px; font-weight:700; }
@media print {
  .fz-inv { border:none; border-radius:0; padding:14px 18px; max-width:100%; }
  .fz-inv table.fz-table tr, .fz-inv .fz-bot, .fz-inv .fz-sign { page-break-inside:avoid; }
}
</style>
<div class="fz-inv print-section" id="invoice-print">
  <div class="fz-top">
    <div class="fz-co">
      <?php if ($fzHasLogo): ?><img src="assets/img/logo_frozen.png" alt="<?= h($company['brand_name']) ?>"><?php endif; ?>
      <div>
        <div class="fz-name"><?= h($company['legal_name']) ?></div>
        <div class="fz-sub">
          <?php if (!empty($company['tagline'])): ?><?= h($company['tagline']) ?><br><?php endif; ?>
          <?php if (!empty($company['alamat'])): ?><?= h($company['alamat']) ?><br><?php endif; ?>
          <?php if (!empty($company['whatsapp'])): ?>WA: <?= h($company['whatsapp']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="fz-title"<?= $invTitle !== 'Invoice' ? ' style="font-size:15px; letter-spacing:.1em; white-space:nowrap;"' : '' ?>><?= h(strtoupper($invTitle)) ?></div>
  </div>

  <div class="fz-meta">
    <div>
      <div class="fz-lbl">Kepada</div>
      <div class="fz-to"><?= h($order['sppg_nama']) ?></div>
      <?php if (!empty($sppgAlamat)): ?><div class="fz-addr"><?= h($sppgAlamat) ?></div><?php endif; ?>
    </div>
    <div class="fz-r">
      <div class="fz-lbl">No. Invoice</div>
      <div class="fz-val"><?= h($order['invoice_no']) ?></div>
      <?php if ($invRefLine !== ''): ?><div style="font-size:9.5px; color:#444; margin-top:2px;"><?= h($invRefLine) ?></div><?php endif; ?>
      <div class="fz-lbl" style="margin-top:8px;">Tanggal</div>
      <div class="fz-val"><?= h($tglPanjang) ?></div>
    </div>
  </div>

  <table class="fz-table">
    <thead>
      <tr>
        <th style="width:6%;">No</th>
        <th style="text-align:left;">Nama Barang</th>
        <th style="width:9%;">Qty</th>
        <th style="width:10%;">Satuan</th>
        <th style="width:18%;">Harga Satuan</th>
        <th style="width:18%;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      <?php $no = 1; foreach ($items as $i): ?>
      <tr>
        <td class="fz-c"><?= $no++ ?></td>
        <td><?= h($i['nama']) ?></td>
        <td class="fz-c"><?= $i['qty'] + 0 ?></td>
        <td class="fz-c"><?= h($i['satuan']) ?></td>
        <td class="fz-rt"><?= $fzNum($i['harga_jual']) ?></td>
        <td class="fz-rt"><?= $fzNum($i['subtotal']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="fz-bot">
    <div class="fz-pay">
      <b>Informasi Pembayaran</b>
      <?php if (!empty($company['bank_name'])): ?>Bank : <?= h($company['bank_name']) ?><br><?php endif; ?>
      No. Rekening : <?= h($company['account_number']) ?><br>
      Atas Nama : <?= h($company['account_name']) ?>
    </div>
    <div class="fz-sum">
      <div><span>Sub-total</span><span><?= rupiah($order['total']) ?></span></div>
      <div class="fz-grand"><span>Total</span><span><?= rupiah($order['total']) ?></span></div>
    </div>
  </div>

  <div class="fz-sign">
    <div>
      Hormat kami,<br>
      <?php if ($fzHasTtd): ?><img src="assets/img/ttd_frozen.png" alt="Tanda tangan" style="height:54px; margin:4px 0 0;"><?php else: ?><div style="height:58px;"></div><?php endif; ?>
      <div class="fz-line"><?= h($company['signer_name']) ?></div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card invoice-card print-section<?= $isCompactInvoice ? ' invoice-fill-page' : '' ?>" id="invoice-print">
  <div class="invoice-header invoice-header-centered">
    <div class="logo-box"><img src="<?= h($logoSrc) ?>" alt="<?= h($company['brand_name']) ?>"></div>
    <div class="legal-name"><?= h($company['legal_name']) ?></div>
    <?php if (!empty($company['alamat'])): ?>
      <div class="legal-address"><?= h($company['alamat']) ?></div>
    <?php endif; ?>
  </div>

  <div class="invoice-body">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:22px;">
      <div>
        <div style="font-size:10.5px; font-weight:700; color:var(--muted); letter-spacing:.05em; margin-bottom:2px;">KEPADA</div>
        <div style="font-weight:700; font-size:15px;"><?= h($order['sppg_nama']) ?></div>
        <?php if (!empty($sppgAlamat)): ?>
          <div style="font-size:11.5px; color:var(--muted); max-width:280px; margin-top:2px;"><?= h($sppgAlamat) ?></div>
        <?php endif; ?>
      </div>
      <div style="text-align:right;">
        <div class="invoice-title"<?= $invTitle !== 'Invoice' ? ' style="font-size:20px;"' : '' ?>><?= h(strtoupper($invTitle)) ?></div>
        <div style="font-size:11px; color:var(--muted); margin-top:8px;">TANGGAL</div>
        <div style="font-size:12.5px; font-weight:600;"><?= fmt_tanggal($order['tanggal']) ?></div>
        <div style="font-size:11px; color:var(--muted); margin-top:6px;">NO INVOICE</div>
        <div style="font-size:12.5px; font-weight:700; font-family:var(--font-mono); color:var(--primary);"><?= h($order['invoice_no']) ?></div>
        <?php if ($invRefLine !== ''): ?><div style="font-size:10.5px; color:var(--muted); margin-top:3px;"><?= h($invRefLine) ?></div><?php endif; ?>
      </div>
    </div>

    <table class="invoice-table">
      <thead>
        <tr>
          <th style="text-align:center; border-radius:6px 0 0 6px;">No</th>
          <th style="text-align:left;">Keterangan</th>
          <th style="text-align:center;">Harga</th>
          <th style="text-align:center;">Qty</th>
          <th style="text-align:center; border-radius:0 6px 6px 0;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $no = 1; foreach ($items as $i): ?>
        <tr>
          <td style="text-align:center; border-radius:6px 0 0 6px;"><?= $no++ ?></td>
          <td style="font-weight:600;"><?= h($i['nama']) ?></td>
          <td style="text-align:center; font-family:var(--font-mono);"><?= rupiah($i['harga_jual']) ?></td>
          <td style="text-align:center; font-family:var(--font-mono);"><?= $i['qty'] + 0 ?> <?= h($i['satuan']) ?></td>
          <td style="text-align:center; font-family:var(--font-mono); font-weight:700; border-radius:0 6px 6px 0;"><?= rupiah($i['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="display:flex; justify-content:flex-end; margin-bottom:22px; padding-right:10px;">
      <div style="width:240px;">
        <div style="display:flex; justify-content:space-between; font-size:12.5px; padding:6px 2px; color:var(--muted);">
          <span>SUB TOTAL</span><span style="font-family:var(--font-mono); color:var(--text); font-weight:700;"><?= rupiah($order['total']) ?></span>
        </div>
        <div class="invoice-total-badge">
          <span>TOTAL</span><span style="font-family:var(--font-mono);"><?= rupiah($order['total']) ?></span>
        </div>
      </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px;">
      <div>
        <div style="font-size:10.5px; font-weight:700; color:var(--primary); letter-spacing:.05em; margin-bottom:4px;">PEMBAYARAN</div>
        <div style="font-size:12.5px;">Nama : <?= h($company['account_name']) ?></div>
        <div style="font-size:12.5px;">Rekening : <?= h($company['bank_name']) ?> <?= h($company['account_number']) ?></div>
      </div>
      <div style="text-align:center;">
        <img src="<?= h($ttdSrc) ?>" alt="Tanda tangan" class="signature-img"><br>
        <div style="font-size:11px; font-weight:700; border-top:1px solid var(--text); padding-top:4px; margin-top:2px; display:inline-block; min-width:140px;"><?= h($company['signer_name']) ?></div>
      </div>
    </div>
  </div>

  <div class="invoice-footer">
    <span style="display:inline-flex; align-items:center; gap:14px;">
      <span style="display:inline-flex; align-items:center; gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="#fff"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.005c5.46 0 9.91-4.45 9.91-9.92 0-2.65-1.03-5.14-2.9-7.01A9.87 9.87 0 0 0 12.04 2m0 1.67a8.2 8.2 0 0 1 5.83 2.42 8.2 8.2 0 0 1 2.41 5.82c0 4.55-3.7 8.25-8.25 8.25a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.38c0-4.55 3.7-8.25 8.25-8.25M8.53 7.33c-.16 0-.43.06-.66.31s-.87.86-.87 2.07.89 2.39 1.01 2.56c.13.16 1.76 2.69 4.29 3.77.6.26 1.06.41 1.43.53.6.19 1.15.16 1.58.1.48-.07 1.48-.6 1.69-1.19.21-.58.21-1.08.15-1.19s-.24-.16-.5-.29-1.5-.74-1.74-.82c-.23-.08-.4-.13-.58.13-.16.26-.65.82-.8 1-.15.16-.29.19-.55.06-.26-.13-1.08-.4-2.06-1.27-.76-.68-1.28-1.51-1.43-1.77-.15-.26-.02-.4.11-.53.11-.11.26-.29.39-.44.13-.15.17-.26.26-.43.09-.16.04-.31-.02-.44-.06-.13-.58-1.44-.81-1.96-.19-.47-.4-.44-.58-.45z"/></svg>
        <?= h($company['whatsapp']) ?>
      </span>
      <span style="display:inline-flex; align-items:center; gap:5px;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="#fff"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07c-1.28.06-2.15.26-2.91.56a5.9 5.9 0 0 0-2.13 1.39A5.9 5.9 0 0 0 .62 4.14c-.3.76-.5 1.63-.56 2.91C0 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.28.26 2.15.56 2.91.31.79.72 1.47 1.39 2.13.66.67 1.34 1.08 2.13 1.39.76.3 1.63.5 2.91.56C8.33 24 8.74 24 12 24s3.67-.01 4.95-.07c1.28-.06 2.15-.26 2.91-.56a5.9 5.9 0 0 0 2.13-1.39c.67-.66 1.08-1.34 1.39-2.13.3-.76.5-1.63.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.28-.26-2.15-.56-2.91a5.9 5.9 0 0 0-1.39-2.13A5.9 5.9 0 0 0 19.86.63c-.76-.3-1.63-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zm0 10.16A4 4 0 1 1 16 12a4 4 0 0 1-4 4zm6.41-10.4a1.44 1.44 0 1 1-1.44-1.44 1.44 1.44 0 0 1 1.44 1.44z"/></svg>
        <?= h($company['instagram']) ?>
      </span>
    </span>
    <span style="color:var(--lime); font-weight:700;">THANK YOU</span>
  </div>
</div>

<?php endif; ?>
