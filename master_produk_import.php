<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';
$active = 'master';
$page_title = 'Import Produk';

$result = null;
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    $file = $_FILES['file_excel'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Upload gagal, coba lagi.';
    } elseif (!preg_match('/\.xlsx$/i', $file['name'])) {
        $errorMsg = 'File harus format .xlsx (Excel). Kalau file kamu .xls / .csv, buka dulu di Excel lalu "Save As" ke .xlsx.';
    } else {
        try {
            $rows = read_xlsx($file['tmp_name']);

            // Coba format khusus Brother Needs dulu (kategori berjalan per blok berdampingan)
            $items = parse_pricelist_blocks($rows);

            if ($items === null) {
                // Fallback: format tabel rata biasa (satu header row, satu produk per baris)
                $headerAliases = [
                    'kategori' => ['kategori', 'category'],
                    'nama' => ['nama', 'nama produk', 'nama barang', 'produk'],
                    'satuan' => ['satuan', 'unit'],
                    'harga_modal' => ['harga modal', 'harga_modal', 'modal', 'hpp'],
                    'harga_jual' => ['harga jual', 'harga_jual', 'jual', 'harga'],
                ];
                $items = map_xlsx_rows_by_header($rows, $headerAliases);
            } else {
                // rapikan nama kategori (BUAH -> Buah, dst)
                foreach ($items as &$it) {
                    $it['kategori'] = normalize_kategori($it['kategori']);
                }
                unset($it);
            }

            $inserted = 0; $updated = 0; $skipped = 0;

            $findStmt = $pdo->prepare("SELECT id FROM produk WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT INTO produk (kategori, nama, satuan, harga_modal, harga_jual) VALUES (?,?,?,?,?)");
            $updateStmt = $pdo->prepare("UPDATE produk SET kategori=?, satuan=?, harga_modal=?, harga_jual=? WHERE id=?");

            foreach ($items as $it) {
                $nama = trim($it['nama']);
                if ($nama === '') { $skipped++; continue; }

                $kategori = trim($it['kategori']) ?: 'Lainnya';
                $satuan = trim($it['satuan']) ?: 'pcs';
                $hargaModal = clean_number($it['harga_modal']);
                $hargaJual = clean_number($it['harga_jual']);

                $findStmt->execute([$nama]);
                $existing = $findStmt->fetch();

                if ($existing) {
                    $updateStmt->execute([$kategori, $satuan, $hargaModal, $hargaJual, $existing['id']]);
                    $updated++;
                } else {
                    $insertStmt->execute([$kategori, $nama, $satuan, $hargaModal, $hargaJual]);
                    $inserted++;
                }
            }

            $result = ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($items)];
        } catch (Exception $e) {
            $errorMsg = 'Gagal memproses file: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Import Produk dari Excel</div>
    <div class="page-sub">Update massal daftar produk &amp; pricelist.</div>
  </div>
  <a href="master_produk.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($result): ?>
  <div class="alert alert-ok">
    <b>Import selesai.</b> Dari <?= $result['total'] ?> baris data:
    <?= $result['inserted'] ?> produk baru ditambahkan,
    <?= $result['updated'] ?> produk diperbarui (dicocokkan dari nama yang sama),
    <?php if ($result['skipped'] > 0): ?> <?= $result['skipped'] ?> baris dilewati (nama kosong).<?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
  <div class="alert alert-danger"><?= h($errorMsg) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Upload File Excel</div>
    <form method="POST" enctype="multipart/form-data">
      <div class="form-row">
        <input type="file" name="file_excel" accept=".xlsx" required>
      </div>
      <button type="submit" class="btn btn-accent" style="width:100%; justify-content:center;">⬆ Import Sekarang</button>
    </form>
  </div>

  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Format File yang Didukung</div>
    <div style="font-size:13px; line-height:1.7; color:var(--text);">
      File harus <b>.xlsx</b> (bukan .xls / .csv), data di <b>sheet pertama</b>. Sistem otomatis mengenali 2 format:
    </div>

    <div style="font-weight:700; font-size:12.5px; margin-top:14px; margin-bottom:6px;">Format 1 — kayak pricelist Brother Needs (kategori berjalan per kolom)</div>
    <div style="font-size:12.5px; color:var(--muted); line-height:1.6;">
      Baris judul kolom "NO / PRODUCT / HARGA MODAL / HARGA / SATUAN" bisa diulang berdampingan
      buat beberapa kategori sekaligus (misal Buah di kolom B-F, Frozen di kolom H-L), dan nama
      kategori (BUAH, SAYUR, dst) ditulis sebagai baris tersendiri di tengah data. Ini otomatis
      dikenali persis seperti file pricelist yang biasa kamu pakai — gak perlu diubah formatnya.
    </div>

    <div style="font-weight:700; font-size:12.5px; margin-top:14px; margin-bottom:6px;">Format 2 — tabel rata sederhana</div>
    <table class="data-table" style="margin-top:4px;">
      <thead><tr><th>Kategori</th><th>Nama</th><th>Satuan</th><th>Harga Modal</th><th>Harga Jual</th></tr></thead>
      <tbody><tr><td>Buah</td><td>Melon Rock</td><td>kg</td><td>15000</td><td>22000</td></tr></tbody>
    </table>

    <div style="font-size:12px; color:var(--muted); margin-top:12px; line-height:1.6;">
      • Produk dengan <b>nama yang sama persis</b> (gak peduli huruf besar/kecil) dengan yang sudah ada akan <b>di-update harganya</b>, bukan dobel.<br>
      • Produk dengan nama baru otomatis ditambahkan.<br>
      • Sel harga yang isinya teks seperti "kosong sementara" otomatis dianggap 0, gak bikin import gagal.
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>