<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/selisih_helper.php';
ensure_invoice_jenis_schema($pdo);
ensure_selisih_schema($pdo);
$active = 'selisih';
$page_title = 'Buat Selisih';
$jenisCfgAll = invoice_jenis_config();

function selisih_back($msg) { header('Location: selisih.php?err=' . urlencode($msg)); exit; }

$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$dok = null; $sections = []; $sppgId = null; $sppgNama = '';

if ($editId) {
    $loaded = selisih_load($pdo, $editId);
    if (!$loaded) selisih_back('Dokumen selisih gak ditemukan.');
    $dok = $loaded['dok'];
    $sppgId = $dok['sppg_id']; $sppgNama = $dok['sppg_nama'];
    foreach ($loaded['invoices'] as $inv) {
        $sec = ['order_id' => $inv['order_id'], 'invoice_no' => $inv['invoice_no'], 'jenis' => $inv['jenis'], 'label' => $inv['label'], 'total_awal' => (float)$inv['total_awal'], 'items' => []];
        foreach ($inv['items'] as $i) {
            $sec['items'][] = ['produk_id' => $i['produk_id'], 'nama' => $i['nama'], 'satuan' => $i['satuan'], 'harga' => (float)$i['harga'],
                               'qty_awal' => (float)$i['qty_awal'], 'qty_setelah' => (float)$i['qty_setelah'], 'baru' => (float)$i['qty_awal'] == 0 ? 1 : 0];
        }
        $sections[] = $sec;
    }
} else {
    // Daftar order dari halaman pilih (order_ids[]=1&order_ids[]=2), dari link invoice (order_id=1), atau csv
    $ids = $_GET['order_ids'] ?? $_POST['order_ids'] ?? ($_GET['order_id'] ?? $_POST['order_id'] ?? []);
    if (!is_array($ids)) $ids = explode(',', (string)$ids);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids) && !empty($_POST['sec'])) {
        foreach ($_POST['sec'] as $s) if (!empty($s['order_id'])) $ids[] = (int)$s['order_id'];
    }
    if (empty($ids) && $_SERVER['REQUEST_METHOD'] !== 'POST') selisih_back('Centang minimal 1 invoice dulu.');
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM orders WHERE id IN ($in) ORDER BY tanggal ASC, id ASC");
        $st->execute($ids);
        $orders = $st->fetchAll();
        if (empty($orders)) selisih_back('Invoice gak ditemukan.');
        $sppgId = $orders[0]['sppg_id']; $sppgNama = $orders[0]['sppg_nama'];
        foreach ($orders as $o) {
            if ($o['sppg_nama'] !== $sppgNama) selisih_back('Invoice yang dicentang harus dari SPPG yang sama.');
        }
        foreach ($orders as $o) {
            $jk = normalize_jenis($o['jenis'] ?? 'utama');
            $its = $pdo->prepare("SELECT produk_id, nama, qty, satuan, harga_jual FROM order_items WHERE order_id = ? ORDER BY id ASC");
            $its->execute([$o['id']]);
            $sec = ['order_id' => (int)$o['id'], 'invoice_no' => $o['invoice_no'], 'jenis' => $jk, 'label' => selisih_label_default($jk), 'total_awal' => (float)$o['total'], 'items' => []];
            foreach ($its->fetchAll() as $i) {
                $sec['items'][] = ['produk_id' => $i['produk_id'], 'nama' => $i['nama'], 'satuan' => $i['satuan'], 'harga' => (float)$i['harga_jual'],
                                   'qty_awal' => (float)$i['qty'], 'qty_setelah' => (float)$i['qty'], 'baru' => 0];
            }
            $sections[] = $sec;
        }
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $ket = trim($_POST['ket_header'] ?? '');
    $catatan = trim($_POST['catatan'] ?? '');
    $postSec = $_POST['sec'] ?? [];
    $produkRows = $pdo->query("SELECT id, nama FROM produk")->fetchAll();
    $produkByName = [];
    foreach ($produkRows as $p) $produkByName[strtolower(trim($p['nama']))] = (int)$p['id'];

    $save = []; $adaPerubahan = false; $totalSelisih = 0;
    foreach ($postSec as $s) {
        $row = ['order_id' => (int)($s['order_id'] ?? 0) ?: null, 'invoice_no' => trim($s['invoice_no'] ?? ''), 'jenis' => normalize_jenis($s['jenis'] ?? 'utama'),
                'label' => trim($s['label'] ?? '') ?: 'ITEM', 'total_awal' => (float)($s['total_awal'] ?? 0), 'items' => []];
        foreach (($s['items'] ?? []) as $i) {
            $nm = trim($i['nama'] ?? '');
            $qa = (float)str_replace(',', '.', $i['qty_awal'] ?? 0);
            $qs = (float)str_replace(',', '.', $i['qty_setelah'] ?? 0);
            $hg = (float)str_replace(',', '.', $i['harga'] ?? 0);
            $sat = trim($i['satuan'] ?? '') ?: 'pcs';
            if ($nm === '' || ($qa == 0 && $qs == 0)) continue;
            if (abs($qs - $qa) > 0.0001) $adaPerubahan = true;
            $totalSelisih += ($qs - $qa) * $hg;
            $pid = !empty($i['produk_id']) ? (int)$i['produk_id'] : ($produkByName[strtolower($nm)] ?? null);
            $row['items'][] = ['produk_id' => $pid, 'nama' => $nm, 'satuan' => $sat, 'harga' => $hg, 'qty_awal' => $qa, 'qty_setelah' => $qs];
        }
        $save[] = $row;
    }
    if (!$adaPerubahan) {
        $error = 'Belum ada yang berubah. Ubah "Qty Setelah Tambahan" atau tambah barang baru dulu.';
        $sections = array_map(function ($row, $k) use ($postSec) {
            $row['items'] = [];
            foreach (($postSec[$k]['items'] ?? []) as $i) {
                $row['items'][] = ['produk_id' => $i['produk_id'] ?? null, 'nama' => $i['nama'] ?? '', 'satuan' => $i['satuan'] ?? '', 'harga' => (float)($i['harga'] ?? 0),
                                   'qty_awal' => (float)($i['qty_awal'] ?? 0), 'qty_setelah' => (float)($i['qty_setelah'] ?? 0), 'baru' => !empty($i['baru']) ? 1 : 0];
            }
            return $row;
        }, $save, array_keys($save));
    } else {
        $pdo->beginTransaction();
        try {
            if ($dok) {
                $pdo->prepare("UPDATE selisih_dok SET tanggal=?, ket_header=?, catatan=?, total_selisih=? WHERE id=?")->execute([$tanggal, $ket, $catatan, $totalSelisih, $dok['id']]);
                $did = (int)$dok['id'];
                foreach (['selisih_dok_item', 'selisih_dok_invoice'] as $t) $pdo->prepare("DELETE FROM $t WHERE dok_id = ?")->execute([$did]);
            } else {
                $pdo->prepare("INSERT INTO selisih_dok (sppg_id, sppg_nama, tanggal, ket_header, catatan, total_selisih) VALUES (?,?,?,?,?,?)")
                    ->execute([$sppgId, $sppgNama, $tanggal, $ket, $catatan, $totalSelisih]);
                $did = (int)$pdo->lastInsertId();
            }
            $insInv = $pdo->prepare("INSERT INTO selisih_dok_invoice (dok_id, order_id, invoice_no, jenis, label, total_awal, urut) VALUES (?,?,?,?,?,?,?)");
            $insIt = $pdo->prepare("INSERT INTO selisih_dok_item (dok_id, inv_id, produk_id, nama, satuan, harga, qty_awal, qty_setelah, urut) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($save as $si => $row) {
                $insInv->execute([$did, $row['order_id'], $row['invoice_no'], $row['jenis'], $row['label'], $row['total_awal'], $si]);
                $invId = (int)$pdo->lastInsertId();
                foreach ($row['items'] as $ii => $it) {
                    $insIt->execute([$did, $invId, $it['produk_id'], $it['nama'], $it['satuan'], $it['harga'], $it['qty_awal'], $it['qty_setelah'], $ii]);
                }
            }
            $pdo->commit();
            header('Location: selisih_invoice.php?id=' . $did . '&saved=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal nyimpen: ' . $e->getMessage();
        }
    }
}

// Data produk buat auto-isi satuan & harga barang baru
$produk = $pdo->query("SELECT id, nama, satuan, harga_jual FROM produk ORDER BY nama ASC")->fetchAll();
$alt = [];
foreach ($pdo->query("SELECT produk_id, satuan, harga_jual FROM produk_satuan_alt")->fetchAll() as $a) $alt[$a['produk_id']][$a['satuan']] = (float)$a['harga_jual'];
$sppgHarga = [];
if (!empty($sppgId)) {
    $hs = $pdo->prepare("SELECT produk_id, harga_jual FROM produk_harga_sppg WHERE sppg_id = ?");
    $hs->execute([$sppgId]);
    foreach ($hs->fetchAll() as $x) $sppgHarga[$x['produk_id']] = (float)$x['harga_jual'];
}
$produkJs = []; $satuanSet = [];
foreach ($produk as $p) {
    $produkJs[strtolower(trim($p['nama']))] = ['id' => (int)$p['id'], 'nama' => $p['nama'], 'satuan' => $p['satuan'],
        'harga' => $sppgHarga[$p['id']] ?? (float)$p['harga_jual'], 'alt' => $alt[$p['id']] ?? new stdClass()];
    $satuanSet[$p['satuan']] = true;
    foreach (($alt[$p['id']] ?? []) as $s => $_) $satuanSet[$s] = true;
}
ksort($satuanSet);

// Nilai default form
$tglDefault = $dok['tanggal'] ?? ($_POST['tanggal'] ?? null);
if (!$tglDefault) {
    $tglDefault = '';
    if (!empty($ids)) { foreach ($orders as $o) { $tglDefault = $o['tanggal']; break; } }
    if ($tglDefault === '') $tglDefault = date('Y-m-d');
}
if (isset($_POST['ket_header'])) {
    $ketDefault = $_POST['ket_header'];
} elseif ($dok) {
    $ketDefault = $dok['ket_header'];
} else {
    $parts = [];
    foreach ($sections as $s) {
        $co = get_company($pdo, $jenisCfgAll[$s['jenis']]['company_id']);
        $parts[] = 'Invoice ' . mb_strtolower($s['label']) . ' ' . $s['invoice_no'] . ' (' . ($co['legal_name'] ?? '') . ')';
    }
    $ketDefault = 'Tanggal ' . selisih_tgl($tglDefault) . ' | ' . implode(' + ', $parts);
}
$catatanDefault = $_POST['catatan'] ?? ($dok['catatan'] ?? '');

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title"><?= $dok ? 'Edit Selisih' : 'Buat Selisih Order Tambahan' ?></div>
    <div class="page-sub"><?= h($sppgNama) ?>. Isi <b>Qty Setelah Tambahan</b> tiap barang, atau tambah barang baru. Invoice asli gak diubah dan pembukuan gak tersentuh.</div>
  </div>
  <a href="selisih.php" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="POST" id="selisih-form">
  <?php if ($dok): ?><input type="hidden" name="id" value="<?= (int)$dok['id'] ?>"><?php endif; ?>
  <datalist id="produkList"><?php foreach ($produk as $p): ?><option value="<?= h($p['nama']) ?>"></option><?php endforeach; ?></datalist>
  <datalist id="satuanList"><?php foreach (array_keys($satuanSet) as $s): ?><option value="<?= h($s) ?>"></option><?php endforeach; ?></datalist>

  <div class="card card-pad" style="margin-bottom:16px;">
    <div class="form-row grid-2">
      <div>
        <label class="field-label">Tanggal</label>
        <input type="date" name="tanggal" id="tgl" value="<?= h($tglDefault) ?>" required>
      </div>
      <div>
        <label class="field-label">Keterangan di bawah judul Excel</label>
        <input type="text" name="ket_header" value="<?= h($ketDefault) ?>">
      </div>
    </div>
  </div>

  <div id="sections"></div>

  <div class="card card-pad" style="margin-bottom:16px;">
    <label class="field-label">Catatan tambahan di Excel (opsional, satu baris = satu catatan)</label>
    <textarea name="catatan" rows="3" placeholder="Misal: Buah: melon madu awal 125 kg, tambahan 15 kg, jadi 140 kg x 19.000"><?= h($catatanDefault) ?></textarea>
    <div style="font-size:11.5px; color:var(--muted); margin-top:6px;">Catatan standar (Qty Awal = invoice versi awal, dst) otomatis ditambahin di Excel.</div>
  </div>

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; gap:8px;">
      <button type="submit" class="btn btn-primary">💾 Simpan &amp; Lihat Hasil</button>
      <a href="selisih.php" class="btn btn-ghost">Batal</a>
    </div>
    <div style="font-size:13px;">Total selisih semua: <b id="grand" style="font-family:var(--font-mono); font-size:15px;">Rp0</b></div>
  </div>
</form>

<script>
var PRODUK = <?= json_encode($produkJs, JSON_UNESCAPED_UNICODE) ?>;
var SECTIONS = <?= json_encode($sections, JSON_UNESCAPED_UNICODE) ?>;
var counters = {};
function fmtRp(n) { var s = Math.round(Math.abs(n)).toLocaleString('id-ID'); return (n < 0 ? '-Rp' : 'Rp') + s; }
function num(v) { return parseFloat(v) || 0; }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

function buildSections() {
  var wrap = document.getElementById('sections');
  SECTIONS.forEach(function (s, si) {
    counters[si] = 0;
    var card = document.createElement('div');
    card.className = 'card card-pad';
    card.style.marginBottom = '16px';
    card.innerHTML =
      '<input type="hidden" name="sec[' + si + '][order_id]" value="' + esc(s.order_id || '') + '">' +
      '<input type="hidden" name="sec[' + si + '][invoice_no]" value="' + esc(s.invoice_no) + '">' +
      '<input type="hidden" name="sec[' + si + '][jenis]" value="' + esc(s.jenis) + '">' +
      '<input type="hidden" name="sec[' + si + '][total_awal]" value="' + esc(s.total_awal) + '">' +
      '<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">' +
        '<div style="font-weight:700; font-size:14px;">Invoice <span style="font-family:var(--font-mono);">' + esc(s.invoice_no) + '</span> <span style="font-weight:400; color:var(--muted); font-size:12px;">(total awal ' + fmtRp(s.total_awal) + ')</span></div>' +
        '<div style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted);">Nama bagian di Excel: <input type="text" name="sec[' + si + '][label]" value="' + esc(s.label) + '" style="width:170px;"></div>' +
      '</div>' +
      '<div style="overflow-x:auto;"><table class="data-table" style="min-width:720px;">' +
        '<thead><tr><th>Barang</th><th style="width:80px;">Satuan</th><th style="width:100px;">Harga</th><th style="width:80px;">Qty Awal</th><th style="width:110px;">Qty Setelah</th><th style="width:80px;">Selisih</th><th style="width:110px; text-align:right;">Selisih (Rp)</th><th style="width:30px;"></th></tr></thead>' +
        '<tbody id="body-' + si + '"></tbody>' +
      '</table></div>' +
      '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">' +
        '<button type="button" class="btn btn-subtle btn-sm" onclick="addItem(' + si + ')">+ Tambah Barang Baru</button>' +
        '<div style="font-size:12.5px;">Subtotal selisih: <b id="sub-' + si + '" style="font-family:var(--font-mono);">Rp0</b></div>' +
      '</div>';
    wrap.appendChild(card);
    s.items.forEach(function (it) { addItem(si, it); });
  });
  hitung();
}

function addItem(si, it) {
  var isNew = !it || it.baru == 1;
  it = it || {produk_id: '', nama: '', satuan: '', harga: '', qty_awal: 0, qty_setelah: '', baru: 1};
  var j = counters[si]++;
  var p = 'sec[' + si + '][items][' + j + ']';
  var tr = document.createElement('tr');
  var ro = isNew ? '' : ' readonly style="width:100%; background:var(--bg);"';
  var rw = ' style="width:100%;"';
  tr.innerHTML =
    '<td><input type="hidden" name="' + p + '[produk_id]" value="' + esc(it.produk_id || '') + '"><input type="hidden" name="' + p + '[baru]" value="' + (isNew ? 1 : 0) + '">' +
      '<input type="text" name="' + p + '[nama]" value="' + esc(it.nama) + '"' + (isNew ? ' list="produkList" autocomplete="off" placeholder="Nama barang"' + rw : ro) + '></td>' +
    '<td><input type="text" name="' + p + '[satuan]" value="' + esc(it.satuan) + '"' + (isNew ? ' list="satuanList"' + rw : ro) + '></td>' +
    '<td><input type="number" step="any" min="0" name="' + p + '[harga]" value="' + esc(it.harga) + '"' + (isNew ? rw : ro) + '></td>' +
    '<td><input type="number" step="any" name="' + p + '[qty_awal]" value="' + esc(it.qty_awal) + '" readonly style="width:100%; background:var(--bg);"></td>' +
    '<td><input type="number" step="any" min="0" name="' + p + '[qty_setelah]" value="' + esc(it.qty_setelah) + '"' + rw + '></td>' +
    '<td class="sel" style="font-family:var(--font-mono);">0</td>' +
    '<td class="selrp" style="text-align:right; font-family:var(--font-mono);">Rp0</td>' +
    '<td>' + (isNew ? '<button type="button" onclick="this.closest(\'tr\').remove(); hitung();" style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:16px;">✕</button>' : '') + '</td>';
  var inp = tr.querySelectorAll('input[type=text], input[type=number]');
  // inp: [0]=nama [1]=satuan [2]=harga [3]=qty_awal [4]=qty_setelah
  if (isNew) {
    inp[0].addEventListener('change', function () {
      var pr = PRODUK[inp[0].value.trim().toLowerCase()];
      if (pr) { inp[0].value = pr.nama; inp[1].value = pr.satuan; inp[2].value = pr.harga; tr.querySelector('input[name$="[produk_id]"]').value = pr.id; hitung(); }
    });
    inp[1].addEventListener('change', function () {
      var pr = PRODUK[inp[0].value.trim().toLowerCase()];
      if (!pr) return;
      var s = inp[1].value.trim();
      if (s === pr.satuan) inp[2].value = pr.harga; else if (pr.alt && pr.alt[s] !== undefined) inp[2].value = pr.alt[s];
      hitung();
    });
  }
  tr.addEventListener('input', hitung);
  document.getElementById('body-' + si).appendChild(tr);
}

function hitung() {
  var grand = 0;
  Object.keys(counters).forEach(function (si) {
    var sub = 0;
    document.querySelectorAll('#body-' + si + ' tr').forEach(function (tr) {
      var i = tr.querySelectorAll('input[type=text], input[type=number]');
      var selisih = num(i[4].value) - num(i[3].value);
      var rp = selisih * num(i[2].value);
      tr.querySelector('.sel').textContent = (Math.round(selisih * 100) / 100);
      tr.querySelector('.selrp').textContent = fmtRp(rp);
      tr.querySelector('.selrp').style.color = rp < 0 ? 'var(--danger)' : '';
      sub += rp;
    });
    document.getElementById('sub-' + si).textContent = fmtRp(sub);
    grand += sub;
  });
  document.getElementById('grand').textContent = fmtRp(grand);
}
buildSections();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
