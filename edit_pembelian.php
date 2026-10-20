<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';

/** Harga modal stock waktu ada Pembelian:
 *  - stock lama masih ada (>0)  -> rata-rata tertimbang (stok lama x harga lama + qty baru x harga baru) / total qty
 *  - stock kosong / belum ada   -> pakai harga pembelian terbaru
 *  Master Produk tidak disentuh sama sekali. */
if (!function_exists('hitung_modal_rata2')) {
    function hitung_modal_rata2($stokLama, $hargaLama, $qtyBaru, $hargaBaru) {
        if ($stokLama > 0 && $qtyBaru > 0) {
            return round((($stokLama * $hargaLama) + ($qtyBaru * $hargaBaru)) / ($stokLama + $qtyBaru), 2);
        }
        return $hargaBaru;
    }
}
$active = 'keuangan';
$page_title = 'Edit Pembelian';

$pembelianId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM pembelian WHERE id = ?");
$stmt->execute([$pembelianId]);
$pembelian = $stmt->fetch();
if (!$pembelian) { redirect('keuangan_pembelian.php'); }

$error = '';

/** Balikin/potong stock buat 1 baris item pembelian. Pembelian NAMBAHIN stock, jadi
 *  $sign = -1 buat mbalikin (reverse qty pembelian LAMA, dipake pas edit), +1 buat
 *  nambahin lagi (apply qty pembelian BARU yang abis diupdate).
 *
 *  CATATAN PERBAIKAN (ikut ketauan pas nambahin fitur Riwayat Stock): sebelumnya
 *  tanda ini KEBALIK (reverse=+1, apply=-1) - akibatnya tiap kali Pembelian yang
 *  nyentuh stock diedit, stock fisiknya malah KEBALIK arahnya (mestinya nambah pas
 *  qty-nya dinaikin, tapi yang kejadian malah berkurang). Ketauan konkret dari test:
 *  edit qty 10->15 harusnya stock +5, tapi sebelum fix ini hasilnya malah -5.
 */
function adjustStockPembelian($pdo, $nama, $qty, $sign, $hargaModalBaru = null, $keterangan = '', $refTipe = null, $refId = null, $userId = null, $tanggal = null) {
    if ($qty <= 0) return;
    $stmtFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
    $stmtFind->execute([$nama]);
    $st = $stmtFind->fetch();
    if (!$st) return;
    $stockSebelum = (float)$st['stock_fisik'];
    $stockSesudah = max(0, $stockSebelum + $sign * $qty);
    if ($hargaModalBaru !== null && $hargaModalBaru > 0) {
        // rata-rata tertimbang kalau stock masih ada, kalau kosong pakai harga terbaru
        $hargaFinal = hitung_modal_rata2($stockSebelum, (float)$st['harga_modal'], $qty, $hargaModalBaru);
        $pdo->prepare("UPDATE stock SET stock_fisik = GREATEST(0, stock_fisik + ?), harga_modal = ? WHERE id = ?")
            ->execute([$sign * $qty, $hargaFinal, $st['id']]);
    } else {
        $pdo->prepare("UPDATE stock SET stock_fisik = GREATEST(0, stock_fisik + ?) WHERE id = ?")
            ->execute([$sign * $qty, $st['id']]);
    }
    log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, $stockSesudah, $keterangan, $refTipe, $refId, $userId, $tanggal);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_edit') {
    $tanggal = $_POST['tanggal'];
    $supplier = trim($_POST['supplier']);
    $keterangan = trim($_POST['keterangan']);
    $metode = $_POST['metode_bayar'];
    if (!in_array($metode, ['Tunai', 'Kas Kecil', 'Hutang'], true)) $metode = 'Tunai';
    $namaBarang = $_POST['nama_barang'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $satuans = $_POST['satuan'] ?? [];
    $hargas = $_POST['harga_satuan'] ?? [];
    $totalOverride = clean_number($_POST['total_override'] ?? 0);

    $items = [];
    $totalHitung = 0;
    foreach ($namaBarang as $i => $nama) {
        $nama = trim($nama);
        if ($nama === '') continue;
        $qty = clean_number($qtys[$i] ?? 0);
        $harga = clean_number($hargas[$i] ?? 0);
        $subtotal = $qty * $harga;
        $totalHitung += $subtotal;
        $items[] = ['nama' => $nama, 'qty' => $qty, 'satuan' => trim($satuans[$i] ?? 'kg'), 'harga' => $harga, 'subtotal' => $subtotal];
    }
    $total = $totalOverride > 0 ? $totalOverride : $totalHitung;

    if (empty($items)) {
        $error = 'Minimal harus ada 1 barang.';
    } else {
        $pdo->beginTransaction();
        try {
            $userId = current_user()['id'] ?? null;

            $ketEditPembelian = 'Edit Pembelian ' . $pembelian['no_bukti'] . ($supplier ? ' - ' . $supplier : '');
            $oldItemsStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ?");
            $oldItemsStmt->execute([$pembelianId]);
            foreach ($oldItemsStmt->fetchAll() as $oi) {
                adjustStockPembelian($pdo, $oi['nama_barang'], $oi['qty'], -1, null, $ketEditPembelian, 'Edit Pembelian', $pembelianId, $userId, $tanggal);
            }

            $pdo->prepare("DELETE FROM pembelian_detail WHERE pembelian_id = ?")->execute([$pembelianId]);
            $detailStmt = $pdo->prepare("INSERT INTO pembelian_detail (pembelian_id, nama_barang, qty, satuan, harga_satuan, subtotal) VALUES (?,?,?,?,?,?)");
            $stockInsert = $pdo->prepare("INSERT INTO stock (nama, satuan, stock_fisik, harga_modal, harga_jual) VALUES (?,?,?,?,0)");
            $stockFind = $pdo->prepare("SELECT id FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            $excludeCheck = $pdo->prepare("
                SELECT 1 FROM stock_exclude WHERE LOWER(nama) = LOWER(?)
                UNION SELECT 1 FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND kategori IN ('Buah','Sayur')
            ");

            foreach ($items as $it) {
                $detailStmt->execute([$pembelianId, $it['nama'], $it['qty'], $it['satuan'], $it['harga'], $it['subtotal']]);
                if ($it['qty'] <= 0) continue;

                $excludeCheck->execute([$it['nama'], $it['nama']]);
                if ($excludeCheck->fetch()) continue; // barang beli-pas-order (manual ATAU kategori Buah/Sayur), sengaja gak di-stock

                $stockFind->execute([$it['nama']]);
                if ($stockFind->fetch()) {
                    adjustStockPembelian($pdo, $it['nama'], $it['qty'], +1, $it['harga'], $ketEditPembelian, 'Edit Pembelian', $pembelianId, $userId, $tanggal);
                } else {
                    $stockInsert->execute([$it['nama'], $it['satuan'], $it['qty'], $it['harga']]);
                    $newStockId = $pdo->lastInsertId();
                    log_stock($pdo, $newStockId, $it['nama'], $it['satuan'], 0, $it['qty'], $ketEditPembelian, 'Edit Pembelian', $pembelianId, $userId, $tanggal);
                }
            }

            $pdo->prepare("UPDATE pembelian SET tanggal=?, supplier=?, keterangan=?, total=?, metode_bayar=? WHERE id=?")
                ->execute([$tanggal, $supplier, $keterangan, $total, $metode, $pembelianId]);

            delete_jurnal_by_ref($pdo, 'Pembelian', $pembelianId);
            $akunLawan = akun_pembelian_lawan($metode);
            post_jurnal($pdo, $tanggal, 'Pembelian ' . $pembelian['no_bukti'] . ($supplier ? ' - ' . $supplier : ''), [
                ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $total, 'kredit' => 0, 'keterangan' => 'Persediaan dari ' . $pembelian['no_bukti']],
                ['akun_id' => akun_id($pdo, $akunLawan), 'debit' => 0, 'kredit' => $total, 'keterangan' => $pembelian['no_bukti']],
            ], 'Pembelian', $pembelianId, $userId, $pembelian['no_bukti']);

            $pdo->commit();
            redirect('keuangan_pembelian.php?edited=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan perubahan: ' . $e->getMessage();
        }
    }
}

$detailStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ?");
$detailStmt->execute([$pembelianId]);
$existingItems = $detailStmt->fetchAll();

$stockItems = $pdo->query("SELECT nama, satuan FROM stock ORDER BY nama ASC")->fetchAll();
$produkItems = $pdo->query("SELECT nama, satuan FROM produk ORDER BY nama ASC")->fetchAll();
$gabunganMap = [];
foreach (array_merge($stockItems, $produkItems) as $it) {
    $key = strtolower(trim($it['nama']));
    if (!isset($gabunganMap[$key])) $gabunganMap[$key] = $it;
}
$semuaBarang = array_values($gabunganMap);
usort($semuaBarang, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$satuanOptions = ['kg', 'gr', 'ons', 'pcs', 'buah', 'ikat', 'pack', 'peti', 'krat', 'dus', 'bungkus', 'liter', 'derigen'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Pembelian <?= h($pembelian['no_bukti']) ?></div>
    <div class="page-sub">Betulkan barang, qty, atau harga yang salah catat.</div>
  </div>
  <a href="keuangan_pembelian.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<div class="alert alert-info">💡 Perubahan di sini otomatis nyesuain ulang stock &amp; jurnal keuangannya juga.</div>

<div class="card card-pad" style="margin-bottom:16px;">
  <form method="POST" id="edit-form">
    <input type="hidden" name="action" value="save_edit">
    <div class="form-row grid-3">
      <div><label class="field-label">Tanggal</label><input type="date" name="tanggal" value="<?= h($pembelian['tanggal']) ?>" required></div>
      <div><label class="field-label">Supplier</label><input type="text" name="supplier" value="<?= h($pembelian['supplier']) ?>" placeholder="Nama supplier (opsional)"></div>
      <div><label class="field-label">Metode Bayar</label>
        <select name="metode_bayar">
          <option value="Tunai" <?= $pembelian['metode_bayar'] === 'Tunai' ? 'selected' : '' ?>>Tunai (dari Kas/Bank)</option>
          <option value="Kas Kecil" <?= $pembelian['metode_bayar'] === 'Kas Kecil' ? 'selected' : '' ?>>Kas Kecil (kas bon petugas belanja)</option>
          <option value="Hutang" <?= $pembelian['metode_bayar'] === 'Hutang' ? 'selected' : '' ?>>Hutang</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <label class="field-label">Keterangan</label>
      <input type="text" name="keterangan" value="<?= h($pembelian['keterangan']) ?>" placeholder="Catatan pembelian (opsional)">
    </div>

    <table class="data-table" id="item-table" style="margin-bottom:6px;">
      <thead><tr><th>Nama Barang</th><th>Qty</th><th>Satuan</th><th>Harga Satuan</th><th></th></tr></thead>
      <tbody id="item-rows"></tbody>
    </table>
    <button type="button" class="btn btn-subtle btn-sm" onclick="addRow()" style="margin-bottom:12px;">+ Tambah Baris</button>

    <div class="form-row" style="max-width:320px;">
      <label class="field-label">Total Keseluruhan (opsional)</label>
      <input type="number" step="any" name="total_override" id="total-override" placeholder="Isi kalau belum tau harga per barang" oninput="recalc()">
    </div>
    <div style="font-weight:700; margin-bottom:12px;">Total: <span id="total-display" style="font-family:var(--font-mono);">Rp0</span></div>
    <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
    <a href="keuangan_pembelian.php" class="btn btn-ghost">Batal</a>
  </form>
</div>

<script>
const BARANG_LIST = <?= json_encode($semuaBarang, JSON_UNESCAPED_UNICODE) ?>;
const SATUAN_OPTIONS = <?= json_encode($satuanOptions, JSON_UNESCAPED_UNICODE) ?>;
const EXISTING_ITEMS = <?= json_encode(array_map(fn($d) => ['nama' => $d['nama_barang'], 'qty' => (float)$d['qty'], 'satuan' => $d['satuan'], 'harga' => (float)$d['harga_satuan']], $existingItems), JSON_UNESCAPED_UNICODE) ?>;

function buildNamaOptions(selected) {
  let html = '<option value="">— Pilih barang —</option>';
  BARANG_LIST.forEach(b => {
    html += `<option value="${b.nama}" data-satuan="${b.satuan}" ${b.nama === selected ? 'selected' : ''}>${b.nama}</option>`;
  });
  const isKnown = BARANG_LIST.some(b => b.nama === selected);
  html += `<option value="__new__" ${(!isKnown && selected) ? 'selected' : ''} style="font-weight:bold;">+ Barang Baru (belum ada di daftar)</option>`;
  return html;
}
function buildSatuanOptions(selected) {
  let opts = SATUAN_OPTIONS.slice();
  if (selected && !opts.includes(selected)) opts.unshift(selected);
  return opts.map(s => `<option value="${s}" ${s === selected ? 'selected' : ''}>${s}</option>`).join('');
}

function addRow(nama, qty, satuan, harga) {
  const tbody = document.getElementById('item-rows');
  const tr = document.createElement('tr');
  const namaVal = nama || '';
  const isNew = namaVal && !BARANG_LIST.some(b => b.nama.toLowerCase() === namaVal.toLowerCase());

  tr.innerHTML = `
    <td>
      <select class="nama-select" onchange="onNamaChange(this)" style="${isNew ? 'display:none;' : ''}">${buildNamaOptions(isNew ? '__new__' : namaVal)}</select>
      <input type="text" class="nama-baru-input" placeholder="Ketik nama barang baru" value="${isNew ? namaVal : ''}" style="${isNew ? '' : 'display:none;'}" oninput="syncNamaBaru(this)">
      <a href="#" class="nama-baru-back" style="${isNew ? '' : 'display:none;'} font-size:11px;" onclick="backToSelect(this); return false;">← balik ke pilihan</a>
      <input type="hidden" name="nama_barang[]" class="nama-hidden" value="${namaVal}">
    </td>
    <td><input type="number" step="any" name="qty[]" value="${qty ?? ''}" placeholder="opsional" style="width:80px;" oninput="recalc()"></td>
    <td><select name="satuan[]" class="satuan-select" style="width:90px;">${buildSatuanOptions(satuan || 'kg')}</select></td>
    <td><input type="number" step="any" name="harga_satuan[]" value="${harga ?? ''}" placeholder="opsional" style="width:120px;" oninput="recalc()"></td>
    <td><button type="button" onclick="this.closest('tr').remove(); recalc();" style="background:none;border:none;color:var(--danger);cursor:pointer;">✕</button></td>
  `;
  tbody.appendChild(tr);
}

function onNamaChange(select) {
  const tr = select.closest('tr');
  const hidden = tr.querySelector('.nama-hidden');
  if (select.value === '__new__') {
    select.style.display = 'none';
    tr.querySelector('.nama-baru-input').style.display = '';
    tr.querySelector('.nama-baru-back').style.display = '';
    tr.querySelector('.nama-baru-input').value = '';
    hidden.value = '';
    tr.querySelector('.nama-baru-input').focus();
  } else {
    hidden.value = select.value;
    const opt = select.options[select.selectedIndex];
    const satuanIkut = opt.dataset.satuan;
    if (satuanIkut) tr.querySelector('.satuan-select').innerHTML = buildSatuanOptions(satuanIkut);
  }
}
function syncNamaBaru(input) { input.closest('tr').querySelector('.nama-hidden').value = input.value.trim(); }
function backToSelect(link) {
  const tr = link.closest('tr');
  tr.querySelector('.nama-select').style.display = '';
  tr.querySelector('.nama-baru-input').style.display = 'none';
  tr.querySelector('.nama-baru-back').style.display = 'none';
}

function recalc() {
  let total = 0;
  document.querySelectorAll('#item-rows tr').forEach(tr => {
    const qty = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    const harga = parseFloat(tr.querySelector('[name="harga_satuan[]"]').value) || 0;
    total += qty * harga;
  });
  const override = parseFloat(document.getElementById('total-override').value) || 0;
  const totalTampil = override > 0 ? override : total;
  document.getElementById('total-display').textContent = 'Rp' + Math.round(totalTampil).toLocaleString('id-ID') + (override > 0 ? ' (dari Total Keseluruhan)' : '');
}

EXISTING_ITEMS.forEach(it => addRow(it.nama, it.qty, it.satuan, it.harga));
if (EXISTING_ITEMS.length === 0) addRow();
recalc();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>