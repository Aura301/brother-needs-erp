<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'stock';
$page_title = 'Import Stock';

$result = null;
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    $file = $_FILES['file_excel'];
    $resetDulu = isset($_POST['reset_dulu']);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Upload gagal, coba lagi.';
    } elseif (!preg_match('/\.xlsx$/i', $file['name'])) {
        $errorMsg = 'File harus format .xlsx (Excel). Kalau file kamu .xls / .csv, buka dulu di Excel lalu "Save As" ke .xlsx.';
    } else {
        try {
            $rows = read_xlsx($file['tmp_name']);

            $headerAliases = [
                'nama' => ['nama', 'nama barang', 'barang', 'nama stock'],
                'satuan' => ['satuan', 'unit'],
                'stock_fisik' => ['stock fisik', 'stock', 'stok', 'qty', 'jumlah'],
                'harga_modal' => ['harga modal', 'harga_modal', 'modal', 'hpp'],
                'harga_jual' => ['harga jual', 'harga_jual', 'jual', 'harga'],
            ];
            $items = map_xlsx_rows_by_header($rows, $headerAliases);
            $userId = current_user()['id'] ?? null;

            $pdo->beginTransaction();

            // Snapshot nilai total stock SEBELUM apa pun disentuh (sebelum reset/insert/update).
            // Abis semua proses kelar, dihitung lagi nilainya, terus selisihnya diposting SEBAGAI
            // SATU jurnal penyesuaian Persediaan (bukan per-baris, biar gak numpuk ratusan entri
            // jurnal buat 1x import). Ini otomatis nyakup kasus "hapus data lama dulu" juga, karena
            // nilai lama yang kehapus ikut kehitung di selisihnya.
            $nilaiSebelum = (float)$pdo->query("SELECT COALESCE(SUM(stock_fisik * harga_modal),0) t FROM stock")->fetch()['t'];

            // Kalau dicentang "hapus data lama dulu", kosongin tabel stock SETELAH file-nya
            // kebaca valid (biar gak kehapus duluan kalau ternyata filenya rusak/salah format).
            $dihapusDulu = 0;
            if ($resetDulu) {
                $dihapusDulu = (int)$pdo->query("SELECT COUNT(*) c FROM stock")->fetch()['c'];
                $pdo->exec("DELETE FROM stock");
            }

            $inserted = 0; $updated = 0; $skipped = 0;

            $findStmt = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT INTO stock (nama, satuan, stock_fisik, harga_modal, harga_jual, updated_by) VALUES (?,?,?,?,?,?)");
            $updateStmt = $pdo->prepare("UPDATE stock SET satuan=?, stock_fisik=?, harga_modal=?, harga_jual=?, updated_by=? WHERE id=?");

            foreach ($items as $it) {
                $nama = trim($it['nama']);
                if ($nama === '') { $skipped++; continue; }

                $findStmt->execute([$nama]);
                $existing = $findStmt->fetch();

                // Kolom yang dikosongin di Excel gak nimpa data yang udah ada (misal
                // pas import hasil Stock Opname yang cuma isi qty doang, harga lama tetep kepake).
                $satuanRaw = trim((string)($it['satuan'] ?? ''));
                $stockRaw = trim((string)($it['stock_fisik'] ?? ''));
                $modalRaw = trim((string)($it['harga_modal'] ?? ''));
                $jualRaw = trim((string)($it['harga_jual'] ?? ''));

                $satuan = $satuanRaw !== '' ? $satuanRaw : ($existing['satuan'] ?? 'kg');
                $stockFisik = $stockRaw !== '' ? clean_number($stockRaw) : ($existing['stock_fisik'] ?? 0);
                $hargaModal = $modalRaw !== '' ? clean_number($modalRaw) : ($existing['harga_modal'] ?? 0);
                $hargaJual = $jualRaw !== '' ? clean_number($jualRaw) : ($existing['harga_jual'] ?? 0);

                if ($existing) {
                    $stockSebelumImport = (float)$existing['stock_fisik'];
                    $updateStmt->execute([$satuan, $stockFisik, $hargaModal, $hargaJual, $userId, $existing['id']]);
                    log_stock($pdo, $existing['id'], $nama, $satuan, $stockSebelumImport, $stockFisik, 'Import Stock dari Excel', 'Stock Import', null, $userId);
                    $updated++;
                } else {
                    $insertStmt->execute([$nama, $satuan, $stockFisik, $hargaModal, $hargaJual, $userId]);
                    $newStockIdImport = $pdo->lastInsertId();
                    log_stock($pdo, $newStockIdImport, $nama, $satuan, 0, $stockFisik, 'Import Stock dari Excel', 'Stock Import', null, $userId);
                    $inserted++;
                }
            }

            $nilaiSesudah = (float)$pdo->query("SELECT COALESCE(SUM(stock_fisik * harga_modal),0) t FROM stock")->fetch()['t'];
            $selisih = round($nilaiSesudah - $nilaiSebelum, 2);

            if (abs($selisih) > 0.01) {
                // Sama kayak Edit Stock manual - selisih nilai dari Import ini ketemuan
                // lebih/kurang dari pencatatan, sifatnya untung/rugi operasional, BUKAN
                // setoran modal - jadi lawannya ke akun Pendapatan/Beban (biar kehitung di
                // Laba Rugi), bukan ke Modal Pemilik.
                if ($selisih > 0) {
                    post_jurnal($pdo, date('Y-m-d'), 'Penyesuaian Import Stock (' . count($items) . ' baris)', [
                        ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $selisih, 'kredit' => 0, 'keterangan' => 'Import stock'],
                        ['akun_id' => akun_id($pdo, '4-102'), 'debit' => 0, 'kredit' => $selisih, 'keterangan' => 'Keuntungan selisih import stock'],
                    ], 'Stock Import', null, $userId);
                } else {
                    $abs = abs($selisih);
                    post_jurnal($pdo, date('Y-m-d'), 'Penyesuaian Import Stock (' . count($items) . ' baris)', [
                        ['akun_id' => akun_id($pdo, '6-109'), 'debit' => $abs, 'kredit' => 0, 'keterangan' => 'Kerugian selisih import stock'],
                        ['akun_id' => akun_id($pdo, '1-120'), 'debit' => 0, 'kredit' => $abs, 'keterangan' => 'Import stock'],
                    ], 'Stock Import', null, $userId);
                }
            }

            $pdo->commit();
            $result = ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($items), 'dihapus_dulu' => $dihapusDulu];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errorMsg = 'Gagal memproses file: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Import Stock dari Excel</div>
    <div class="page-sub">Update massal jumlah &amp; harga stock barang.</div>
  </div>
  <a href="stock.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($result): ?>
  <div class="alert alert-ok">
    <b>Import selesai.</b>
    <?php if (!empty($result['dihapus_dulu'])): ?>
      <?= $result['dihapus_dulu'] ?> data stock lama dihapus duluan, terus
    <?php endif; ?>
    dari <?= $result['total'] ?> baris data:
    <?= $result['inserted'] ?> barang baru ditambahkan,
    <?= $result['updated'] ?> barang diperbarui (dicocokkan dari nama yang sama),
    <?php if ($result['skipped'] > 0): ?> <?= $result['skipped'] ?> baris dilewati (nama kosong).<?php endif; ?>
    Jurnal penyesuaian Persediaan otomatis ikut diposting kalau ada perubahan nilai total.
  </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
  <div class="alert alert-danger"><?= h($errorMsg) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Upload File Excel</div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return cekReset(this)">
      <div class="form-row">
        <input type="file" name="file_excel" accept=".xlsx" required>
      </div>
      <div class="form-row" style="background:var(--danger-soft); border-radius:8px; padding:10px 12px;">
        <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
          <input type="checkbox" name="reset_dulu" id="reset_dulu" style="margin-top:3px;">
          <span style="font-size:12.5px;">
            <b style="color:var(--danger);">Hapus SEMUA data stock lama dulu</b>, baru import file ini sebagai data baru
            (biar barang yang salah ketik/dobel dari data lama ikut kehapus, ganti total sama data di file ini).
          </span>
        </label>
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
      <thead><tr><th>Nama</th><th>Satuan</th><th>Stock Fisik</th><th>Harga Modal</th><th>Harga Jual</th></tr></thead>
      <tbody><tr><td>Bawang Putih Bubuk</td><td>botol</td><td>5</td><td>15000</td><td>20000</td></tr></tbody>
    </table>
    <div style="font-size:12px; color:var(--muted); margin-top:10px; line-height:1.6;">
      • Barang dengan <b>nama yang sama persis</b> (gak peduli huruf besar/kecil) dengan yang sudah ada akan <b>diperbarui datanya</b> (stock, satuan, harga) — bukan dobel.<br>
      • Barang dengan nama baru otomatis ditambahkan.<br>
      • Kolom Harga Modal/Jual boleh dikosongkan (default 0), bisa diisi belakangan lewat tombol Edit.<br>
      • Kolom nama boleh "Nama", "Nama Barang", atau "Barang" — sistem otomatis mengenali.
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
function cekReset(form) {
  if (form.reset_dulu.checked) {
    return confirm('YAKIN mau hapus SEMUA data stock yang lama sebelum import?\n\nIni gak bisa dibatalin. Data stock lama yang gak ada di file Excel ini bakal HILANG PERMANEN.\n\nKetik OK kalau yakin, Cancel kalau mau import biasa (gabung sama data lama).');
  }
  return true;
}
</script>