<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';
$active = 'sppg';
$page_title = 'Import SPPG';

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

            $headerAliases = [
                'nama' => ['nama', 'nama sppg', 'sppg', 'nama dapur', 'dapur'],
                'alamat' => ['alamat', 'address'],
                'kontak' => ['kontak', 'no hp', 'no. hp', 'telepon', 'whatsapp', 'wa'],
                'status' => ['status'],
            ];
            $items = map_xlsx_rows_by_header($rows, $headerAliases);

            $inserted = 0; $updated = 0; $skipped = 0;

            $findStmt = $pdo->prepare("SELECT id FROM sppg WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT INTO sppg (nama, alamat, kontak, status) VALUES (?,?,?,?)");
            $updateStmt = $pdo->prepare("UPDATE sppg SET alamat=?, kontak=?, status=? WHERE id=?");

            foreach ($items as $it) {
                $nama = trim($it['nama']);
                if ($nama === '') { $skipped++; continue; }

                $alamat = trim($it['alamat']);
                $kontak = trim($it['kontak']);
                $statusRaw = strtolower(trim($it['status']));
                $status = (strpos($statusRaw, 'tidak') !== false) ? 'Tidak Aktif' : 'Aktif';

                $findStmt->execute([$nama]);
                $existing = $findStmt->fetch();

                if ($existing) {
                    $updateStmt->execute([$alamat, $kontak, $status, $existing['id']]);
                    $updated++;
                } else {
                    $insertStmt->execute([$nama, $alamat, $kontak, $status]);
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
    <div class="page-title">Import SPPG dari Excel</div>
    <div class="page-sub">Update massal daftar SPPG / dapur.</div>
  </div>
  <a href="master_sppg.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($result): ?>
  <div class="alert alert-ok">
    <b>Import selesai.</b> Dari <?= $result['total'] ?> baris data:
    <?= $result['inserted'] ?> SPPG baru ditambahkan,
    <?= $result['updated'] ?> SPPG diperbarui (dicocokkan dari nama yang sama),
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
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Format File yang Dibutuhkan</div>
    <div style="font-size:13px; line-height:1.7; color:var(--text);">
      File harus <b>.xlsx</b>, data di <b>sheet pertama</b>, baris pertama = judul kolom:
    </div>
    <table class="data-table" style="margin-top:10px;">
      <thead><tr><th>Nama</th><th>Alamat</th><th>Kontak</th><th>Status</th></tr></thead>
      <tbody><tr><td>SPPG Ciater</td><td>Jl. Contoh No.1, Serpong</td><td>0812xxxxxxx</td><td>Aktif</td></tr></tbody>
    </table>
    <div style="font-size:12px; color:var(--muted); margin-top:10px; line-height:1.6;">
      • SPPG dengan <b>nama yang sama persis</b> (gak peduli huruf besar/kecil) dengan yang sudah ada akan <b>diperbarui datanya</b>, bukan dobel.<br>
      • SPPG dengan nama baru otomatis ditambahkan dengan status default <b>Aktif</b>.<br>
      • Kolom Status boleh dikosongkan (default Aktif). Isi "Tidak Aktif" kalau mau langsung nonaktif.<br>
      • Kolom nama boleh "Nama", "Nama SPPG", "SPPG", atau "Dapur" — sistem otomatis mengenali.
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>