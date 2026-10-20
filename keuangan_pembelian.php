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
$page_title = 'Pembelian';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $tanggal = $_POST['tanggal'];
    $supplier = trim($_POST['supplier']);
    $keterangan = trim($_POST['keterangan']);
    $metode = $_POST['metode_bayar'];
    if (!in_array($metode, ['Tunai', 'Kas Kecil', 'Hutang'], true)) $metode = 'Tunai';
    $namaBarang = $_POST['nama_barang'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $satuans = $_POST['satuan'] ?? [];
    $hargas = $_POST['harga_satuan'] ?? [];
    $excludeFlags = $_POST['exclude_stock'] ?? [];
    $totalOverride = clean_number($_POST['total_override'] ?? 0);

    $items = [];
    $totalHitung = 0;
    foreach ($namaBarang as $i => $nama) {
        $nama = trim($nama);
        if ($nama === '') continue;
        $qty = clean_number($qtys[$i] ?? 0); // boleh 0/kosong kalau jumlahnya belum diketahui
        $harga = clean_number($hargas[$i] ?? 0); // boleh 0 kalau belum tau harga satuannya
        $subtotal = $qty * $harga;
        $totalHitung += $subtotal;
        $items[] = ['nama' => $nama, 'qty' => $qty, 'satuan' => trim($satuans[$i] ?? 'kg'), 'harga' => $harga, 'subtotal' => $subtotal, 'exclude' => !empty($excludeFlags[$i])];
    }

    // Kalau diisi manual "Total Keseluruhan", itu yang dipakai (buat kondisi belum tau
    // harga per barang, cuma tau total nota-nya doang).
    $total = $totalOverride > 0 ? $totalOverride : $totalHitung;

    if (empty($items)) {
        $error = 'Tambahkan minimal 1 barang yang dibeli.';
    } else {
        $userId = current_user()['id'] ?? null;
        $noBukti = 'PB-' . date('Ymd', strtotime($tanggal)) . '-' . substr(uniqid(), -4);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO pembelian (no_bukti, tanggal, supplier, keterangan, total, metode_bayar, created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$noBukti, $tanggal, $supplier, $keterangan, $total, $metode, $userId]);
            $pembelianId = $pdo->lastInsertId();

            $detailStmt = $pdo->prepare("INSERT INTO pembelian_detail (pembelian_id, nama_barang, qty, satuan, harga_satuan, subtotal) VALUES (?,?,?,?,?,?)");
            $stockFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
            $stockUpdateWithHarga = $pdo->prepare("UPDATE stock SET stock_fisik = stock_fisik + ?, harga_modal = ? WHERE id = ?");
            $stockUpdateQtyOnly = $pdo->prepare("UPDATE stock SET stock_fisik = stock_fisik + ? WHERE id = ?");
            $stockInsert = $pdo->prepare("INSERT INTO stock (nama, satuan, stock_fisik, harga_modal, harga_jual) VALUES (?,?,?,?,0)");
            $excludeCheck = $pdo->prepare("
                SELECT 1 FROM stock_exclude WHERE LOWER(nama) = LOWER(?)
                UNION SELECT 1 FROM produk WHERE LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND kategori IN ('Buah','Sayur')
            ");
            $excludeInsert = $pdo->prepare("INSERT IGNORE INTO stock_exclude (nama, keterangan) VALUES (?, 'Ditandai manual pas input Pembelian')");

            foreach ($items as $it) {
                $detailStmt->execute([$pembelianId, $it['nama'], $it['qty'], $it['satuan'], $it['harga'], $it['subtotal']]);

                if ($it['exclude']) {
                    $excludeInsert->execute([$it['nama']]);
                    continue; // gak usah di-stock, transaksi tetep kecatat di Pembelian & jurnal
                }

                if ($it['qty'] <= 0) continue; // jumlah belum diketahui - skip stock, transaksi tetep kecatat

                $excludeCheck->execute([$it['nama'], $it['nama']]);
                if ($excludeCheck->fetch()) continue; // barang beli-pas-order (manual ATAU kategori Buah/Sayur), sengaja gak di-stock

                $stockFind->execute([$it['nama']]);
                $st = $stockFind->fetch();
                $ketPembelian = 'Pembelian ' . $noBukti . ($supplier ? ' - ' . $supplier : '');
                if ($st) {
                    $stockSebelum = (float)$st['stock_fisik'];
                    if ($it['harga'] > 0) {
                        $hargaBaruStock = hitung_modal_rata2($stockSebelum, (float)$st['harga_modal'], $it['qty'], $it['harga']);
                        $stockUpdateWithHarga->execute([$it['qty'], $hargaBaruStock, $st['id']]);
                    } else {
                        $stockUpdateQtyOnly->execute([$it['qty'], $st['id']]);
                    }
                    log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, $stockSebelum + $it['qty'], $ketPembelian, 'Pembelian', $pembelianId, $userId, $tanggal);
                } else {
                    $stockInsert->execute([$it['nama'], $it['satuan'], $it['qty'], $it['harga']]);
                    $newStockId = $pdo->lastInsertId();
                    log_stock($pdo, $newStockId, $it['nama'], $it['satuan'], 0, $it['qty'], $ketPembelian, 'Pembelian', $pembelianId, $userId, $tanggal);
                }
            }

            $akunLawan = akun_pembelian_lawan($metode); // Kas, Kas Kecil, atau Hutang Usaha
            post_jurnal($pdo, $tanggal, 'Pembelian ' . $noBukti . ($supplier ? ' - ' . $supplier : ''), [
                ['akun_id' => akun_id($pdo, '1-120'), 'debit' => $total, 'kredit' => 0, 'keterangan' => 'Persediaan dari ' . $noBukti],
                ['akun_id' => akun_id($pdo, $akunLawan), 'debit' => 0, 'kredit' => $total, 'keterangan' => $noBukti],
            ], 'Pembelian', $pembelianId, $userId, $noBukti);

            $pdo->commit();
            redirect('keuangan_pembelian.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan pembelian: ' . $e->getMessage();
        }
    }
}

$showAdd = isset($_GET['tambah']);
$prefillItem = trim($_GET['item'] ?? '');
$prefillSatuan = trim($_GET['satuan'] ?? '');

// Filter riwayat: Hari Ini / Bulan Ini / Semua
$filter = $_GET['filter'] ?? 'semua';
$sql = "SELECT * FROM pembelian WHERE 1=1";
if ($filter === 'harian') {
    $sql .= " AND tanggal = CURDATE()";
} elseif ($filter === 'bulanan') {
    $sql .= " AND DATE_FORMAT(tanggal, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
}
$sql .= " ORDER BY tanggal DESC, id DESC";
$pembelians = $pdo->query($sql)->fetchAll();
$detailStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ?");

$totalPeriode = array_sum(array_column($pembelians, 'total'));

// Gabungan Stock + Master Produk buat pilihan nama barang (dropdown wajib pilih,
// biar gak ada lagi typo yang bikin barang dobel kayak "Labu Siem" vs "Labu Siam")
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
    <div class="page-title">Pembelian</div>
    <div class="page-sub">Belanja ke supplier — otomatis nambah stock & posting ke jurnal.</div>
  </div>
  <a href="export_excel.php?type=pembelian" class="btn btn-ghost">⬇ Export Excel</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_modal.php" class="btn btn-subtle">Modal &amp; Kas</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-subtle">Neraca</a>
  <a href="keuangan_aruskas.php" class="btn btn-subtle">Arus Kas</a>
  <a href="keuangan_hutang.php" class="btn btn-subtle">Hutang</a>
  <a href="keuangan_pembelian.php" class="btn btn-primary">Pembelian</a>
  <a href="riwayat.php" class="btn btn-subtle">Penjualan</a>
  <a href="keuangan_petty_cash.php" class="btn btn-subtle">Petty Cash</a>
  <a href="keuangan_beban.php" class="btn btn-subtle">Beban Operasional</a>
  <a href="keuangan_labarugi.php" class="btn btn-subtle">Laba Rugi</a>
  <a href="keuangan_tren.php" class="btn btn-subtle">Tren &amp; Perbandingan</a>
</div>

<?php if (!$showAdd): ?>
<a href="keuangan_pembelian.php?tambah=1" class="btn btn-primary" style="margin-bottom:16px;">+ Catat Pembelian</a>
<?php endif; ?>

<?php if (isset($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (isset($_GET['edited'])): ?><div class="alert alert-ok">✓ Perubahan pembelian tersimpan.</div><?php endif; ?>
<?php if (isset($_GET['dihapus'])): ?><div class="alert alert-ok">✓ Pembelian dihapus. Stock dan jurnalnya udah ikut dibalikin.</div><?php endif; ?>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:16px;">
  <?php if ($prefillItem): ?>
    <div class="alert alert-info">📥 Restock <b><?= h($prefillItem) ?></b> — tinggal isi jumlah &amp; harga belinya di bawah.</div>
  <?php endif; ?>

  <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Catat Pembelian Baru</div>
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-row grid-3">
      <div><label class="field-label">Tanggal</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required></div>
      <div><label class="field-label">Supplier</label><input type="text" name="supplier" placeholder="Nama supplier (opsional)"></div>
      <div><label class="field-label">Metode Bayar</label>
        <select name="metode_bayar"><option value="Tunai">Tunai (dari Kas/Bank)</option><option value="Kas Kecil">Kas Kecil (kas bon petugas belanja)</option><option value="Hutang">Hutang</option></select>
      </div>
    </div>
    <div class="form-row">
      <label class="field-label">Keterangan</label>
      <input type="text" name="keterangan" placeholder="Catatan pembelian (opsional)">
    </div>

    <table class="data-table" id="item-table" style="margin-bottom:6px;">
      <thead><tr><th>Nama Barang</th><th>Qty</th><th>Satuan</th><th>Harga Satuan (opsional)</th><th style="white-space:nowrap;">Gak Usah Distock</th><th></th></tr></thead>
      <tbody id="item-rows"></tbody>
    </table>
    <button type="button" class="btn btn-subtle btn-sm" onclick="addRow()" style="margin-bottom:6px;">+ Tambah Baris</button>
    <div style="font-size:11.5px; color:var(--muted); margin-bottom:12px;">
      Kosongin <b>Qty</b> kalau belum tau jumlahnya — transaksi tetep kecatat, cuma stock gak ke-update (biar gak ngarang angka).<br>
      <b>Nama Barang wajib pilih dari daftar</b> yang udah ada — kalau beneran barang baru, pilih "+ Barang Baru" di paling bawah dropdown.
    </div>

    <div class="form-row" style="max-width:320px;">
      <label class="field-label">Total Keseluruhan (opsional)</label>
      <input type="number" step="any" name="total_override" id="total-override" placeholder="Isi kalau belum tau harga per barang" oninput="recalc()">
      <div style="font-size:11.5px; color:var(--muted); margin-top:4px;">Kalau diisi, ini yang dipakai sebagai total pembelian — gak perlu isi Harga Satuan per barang.</div>
    </div>

    <div style="font-weight:700; margin-bottom:12px;">Total: <span id="total-display" style="font-family:var(--font-mono);">Rp0</span></div>
    <button type="submit" class="btn btn-primary">Simpan Pembelian</button>
    <a href="keuangan_pembelian.php" class="btn btn-ghost">Batal</a>
  </form>
</div>

<script>
const BARANG_LIST = <?= json_encode($semuaBarang, JSON_UNESCAPED_UNICODE) ?>;
const SATUAN_OPTIONS = <?= json_encode($satuanOptions, JSON_UNESCAPED_UNICODE) ?>;
const PREFILL_NAMA = <?= json_encode($prefillItem, JSON_UNESCAPED_UNICODE) ?>;
const PREFILL_SATUAN = <?= json_encode($prefillSatuan, JSON_UNESCAPED_UNICODE) ?>;

function buildNamaOptions(selected) {
  let html = '<option value="">— Pilih barang —</option>';
  BARANG_LIST.forEach(b => {
    html += `<option value="${b.nama}" data-satuan="${b.satuan}" ${b.nama === selected ? 'selected' : ''}>${b.nama}</option>`;
  });
  html += `<option value="__new__" ${selected === '__new__' ? 'selected' : ''} style="font-weight:bold;">+ Barang Baru (belum ada di daftar)</option>`;
  return html;
}

function buildSatuanOptions(selected) {
  let opts = SATUAN_OPTIONS.slice();
  if (selected && !opts.includes(selected)) opts.unshift(selected);
  return opts.map(s => `<option value="${s}" ${s === selected ? 'selected' : ''}>${s}</option>`).join('');
}

function addRow(prefillNama, prefillSatuan) {
  const tbody = document.getElementById('item-rows');
  const tr = document.createElement('tr');
  const isNew = prefillNama && !BARANG_LIST.some(b => b.nama.toLowerCase() === prefillNama.toLowerCase());
  const satuan = prefillSatuan || 'kg';

  tr.innerHTML = `
    <td>
      <select class="nama-select" onchange="onNamaChange(this)" style="${isNew ? 'display:none;' : ''}">${buildNamaOptions(isNew ? '__new__' : (prefillNama || ''))}</select>
      <input type="text" class="nama-baru-input" placeholder="Ketik nama barang baru" value="${isNew ? prefillNama : ''}" style="${isNew ? '' : 'display:none;'}" oninput="syncNamaBaru(this)">
      <a href="#" class="nama-baru-back" style="${isNew ? '' : 'display:none;'} font-size:11px;" onclick="backToSelect(this); return false;">← balik ke pilihan</a>
      <input type="hidden" name="nama_barang[]" class="nama-hidden" value="${prefillNama || ''}">
    </td>
    <td><input type="number" step="any" name="qty[]" placeholder="opsional" style="width:80px;" oninput="recalc()"></td>
    <td><select name="satuan[]" class="satuan-select" style="width:90px;">${buildSatuanOptions(satuan)}</select></td>
    <td><input type="number" step="any" name="harga_satuan[]" placeholder="opsional" style="width:110px;" oninput="recalc()"></td>
    <td style="text-align:center;">
      <input type="checkbox" onchange="this.nextElementSibling.value = this.checked ? '1' : '0'" title="Beli pas ada order, langsung kepake hari itu - gak usah ditrack di Stock">
      <input type="hidden" name="exclude_stock[]" value="0">
    </td>
    <td><button type="button" onclick="this.closest('tr').remove(); recalc();" style="background:none;border:none;color:var(--danger);cursor:pointer;">✕</button></td>
  `;
  tbody.appendChild(tr);
  if (prefillNama) tr.querySelector('[name="qty[]"]').focus();
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
    if (satuanIkut) {
      const satuanSelect = tr.querySelector('.satuan-select');
      satuanSelect.innerHTML = buildSatuanOptions(satuanIkut);
    }
  }
}

function syncNamaBaru(input) {
  input.closest('tr').querySelector('.nama-hidden').value = input.value.trim();
}

function backToSelect(link) {
  const tr = link.closest('tr');
  tr.querySelector('.nama-select').style.display = '';
  tr.querySelector('.nama-baru-input').style.display = 'none';
  tr.querySelector('.nama-baru-back').style.display = 'none';
  tr.querySelector('.nama-select').value = '';
  tr.querySelector('.nama-hidden').value = '';
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

addRow(PREFILL_NAMA, PREFILL_SATUAN);
addRow();
if (PREFILL_NAMA) recalc();
</script>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:14px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; gap:8px;">
      <a href="keuangan_pembelian.php?filter=harian<?= $showAdd ? '&tambah=1' : '' ?>" class="btn <?= $filter === 'harian' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Hari Ini</a>
      <a href="keuangan_pembelian.php?filter=bulanan<?= $showAdd ? '&tambah=1' : '' ?>" class="btn <?= $filter === 'bulanan' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Bulan Ini</a>
      <a href="keuangan_pembelian.php?filter=semua<?= $showAdd ? '&tambah=1' : '' ?>" class="btn <?= $filter === 'semua' ? 'btn-primary' : 'btn-subtle' ?> btn-sm">Semua</a>
    </div>
    <div style="font-size:13px; color:var(--muted);">Total periode ini: <b style="color:var(--text); font-family:var(--font-mono);"><?= rupiah($totalPeriode) ?></b> (<?= count($pembelians) ?> transaksi)</div>
  </div>
</div>

<?php if (empty($pembelians)): ?>
  <div class="card"><div class="empty-state"><div class="icon">🛍️</div><div class="title">Belum ada pembelian di periode ini</div></div></div>
<?php else: foreach ($pembelians as $p):
  $detailStmt->execute([$p['id']]);
  $details = $detailStmt->fetchAll();
?>
<div class="card card-pad" style="margin-bottom:14px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
    <div>
      <span style="font-weight:700; font-family:var(--font-mono);"><?= h($p['no_bukti']) ?></span>
      <?php if ($p['supplier']): ?><span style="color:var(--muted);"> — <?= h($p['supplier']) ?></span><?php endif; ?>
      <span class="badge badge-muted"><?= h($p['metode_bayar']) ?></span>
    </div>
    <div style="text-align:right;">
      <div style="font-size:11.5px; color:var(--muted);"><?= fmt_tanggal($p['tanggal']) ?></div>
      <div style="font-weight:700; font-family:var(--font-mono);"><?= rupiah($p['total']) ?></div>
      <a href="edit_pembelian.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">✎ Edit</a>
      <a href="hapus_pembelian.php?id=<?= $p['id'] ?>" class="btn btn-sm" style="color:var(--danger);">🗑 Hapus</a>
    </div>
  </div>
  <table class="data-table">
    <thead><tr><th>Barang</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($details as $d): ?>
      <tr>
        <td><?= h($d['nama_barang']) ?></td>
        <td><?= $d['qty'] > 0 ? h(rtrim(rtrim(number_format($d['qty'], 2, ',', '.'), '0'), ',') . ' ' . $d['satuan']) : '<span style="color:var(--muted);">Qty belum diketahui</span>' ?></td>
        <td><?= $d['harga_satuan'] > 0 ? rupiah($d['harga_satuan']) : '-' ?></td>
        <td><?= rupiah($d['subtotal']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>