<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'order';
$page_title = 'Buat Order';

$error = '';

ensure_invoice_jenis_schema($pdo);
$jenis = normalize_jenis($_POST['jenis'] ?? $_GET['jenis'] ?? 'utama');
$jenisCfg = invoice_jenis_config()[$jenis];

// ---------------------------------------------------------
// HANDLE SUBMIT (bikin order resmi ATAU simpan draft)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_order';
    $sppg_id = (int)($_POST['sppg_id'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $items = $_POST['item'] ?? [];
    $draftId = (int)($_POST['draft_id'] ?? 0);

    if ($sppg_id <= 0) {
        $error = 'Pilih SPPG dulu ya.';
    } else {
        $sppgStmt = $pdo->prepare("SELECT * FROM sppg WHERE id = ?");
        $sppgStmt->execute([$sppg_id]);
        $sppg = $sppgStmt->fetch();

        if (!$sppg) {
            $error = 'SPPG tidak ditemukan.';
        } elseif ($action === 'save_draft') {
            $itemsClean = [];
            foreach ($items as $it) {
                $qty = clean_number($it['qty'] ?? 0);
                if ($qty <= 0) continue;
                $itemsClean[] = [
                    'produk_id' => (int)($it['produk_id'] ?? 0) ?: null,
                    'nama' => $it['nama'] ?? '',
                    'satuan' => $it['satuan'] ?? '',
                    'qty' => $qty,
                    'harga_jual' => clean_number($it['harga_jual'] ?? 0),
                    'harga_modal' => clean_number($it['harga_modal'] ?? 0),
                ];
            }
            $itemsJson = json_encode($itemsClean, JSON_UNESCAPED_UNICODE);
            $userId = current_user()['id'] ?? null;

            if ($draftId > 0) {
                $stmt = $pdo->prepare("UPDATE order_drafts SET sppg_id=?, sppg_nama=?, tanggal=?, items_json=?, jenis=? WHERE id=?");
                $stmt->execute([$sppg_id, $sppg['nama'], $tanggal, $itemsJson, $jenis, $draftId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO order_drafts (sppg_id, sppg_nama, tanggal, items_json, created_by, jenis) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$sppg_id, $sppg['nama'], $tanggal, $itemsJson, $userId, $jenis]);
                $draftId = $pdo->lastInsertId();
            }

            if (!empty($_POST['redirect_after_draft'])) {
                $namaBaru = trim($_POST['produk_nama_baru'] ?? '');
                redirect('master_produk.php?tambah=1&nama=' . urlencode($namaBaru) . '&return_draft=' . $draftId);
            } else {
                redirect('order.php?draft_id=' . $draftId . '&saved=1&jenis=' . $jenis);
            }
        } else {
            // ---- BIKIN ORDER RESMI (INVOICE) ----
            if (empty($items)) {
                $error = 'Tambahkan minimal 1 item dulu ya.';
            } else {
                $pdo->beginTransaction();
                try {
                    $total = 0;
                    $totalMargin = 0;
                    $validItems = [];
                    foreach ($items as $it) {
                        $qty = clean_number($it['qty'] ?? 0);
                        if ($qty <= 0) continue;
                        $hargaJual = clean_number($it['harga_jual'] ?? 0);
                        $hargaModal = clean_number($it['harga_modal'] ?? 0);
                        $subtotal = $qty * $hargaJual;
                        $margin = $qty * ($hargaJual - $hargaModal);
                        $total += $subtotal;
                        $totalMargin += $margin;
                        $validItems[] = [
                            'produk_id' => (int)($it['produk_id'] ?? 0) ?: null,
                            'nama' => $it['nama'] ?? '',
                            'qty' => $qty,
                            'satuan' => $it['satuan'] ?? '',
                            'harga_jual' => $hargaJual,
                            'harga_modal' => $hargaModal,
                            'subtotal' => $subtotal,
                            'margin' => $margin,
                        ];
                    }

                    if (empty($validItems)) {
                        throw new Exception('Semua item qty-nya 0, gak ada yang bisa disimpan.');
                    }

                    $invoiceNo = generate_invoice_no($pdo, $tanggal, $sppg_id, $jenisCfg['prefix']);
                    $userId = current_user()['id'] ?? null;

                    $stmt = $pdo->prepare("INSERT INTO orders (invoice_no, jenis, sppg_id, sppg_nama, tanggal, total, total_margin, status, created_by) VALUES (?,?,?,?,?,?,?, 'Belum Bayar', ?)");
                    $stmt->execute([$invoiceNo, $jenis, $sppg_id, $sppg['nama'], $tanggal, $total, $totalMargin, $userId]);
                    $orderId = $pdo->lastInsertId();

                    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, produk_id, nama, qty, satuan, harga_jual, harga_modal, subtotal, margin) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmtStockFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
                    $stmtStockUpdate = $pdo->prepare("UPDATE stock SET stock_fisik = GREATEST(0, stock_fisik - ?) WHERE id = ?");
                    $stmtSatuanAlt = $pdo->prepare("INSERT INTO produk_satuan_alt (produk_id, satuan, harga_jual, harga_modal) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE harga_jual = VALUES(harga_jual), harga_modal = VALUES(harga_modal)");
                    $stmtHargaSppg = $pdo->prepare("INSERT INTO produk_harga_sppg (produk_id, sppg_id, harga_jual, harga_modal) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE harga_jual = VALUES(harga_jual), harga_modal = VALUES(harga_modal)");

                    foreach ($validItems as $vi) {
                        $stmtItem->execute([$orderId, $vi['produk_id'], $vi['nama'], $vi['qty'], $vi['satuan'], $vi['harga_jual'], $vi['harga_modal'], $vi['subtotal'], $vi['margin']]);

                        // Inget harga per satuan ini buat produk yang sama (lintas SPPG),
                        // dan inget juga harga khusus buat kombinasi produk+SPPG ini
                        // (misal Bawang Merah beda harga di tiap SPPG).
                        if ($vi['produk_id']) {
                            $stmtSatuanAlt->execute([$vi['produk_id'], $vi['satuan'], $vi['harga_jual'], $vi['harga_modal']]);
                            $stmtHargaSppg->execute([$vi['produk_id'], $sppg_id, $vi['harga_jual'], $vi['harga_modal']]);
                        }

                        $stmtStockFind->execute([$vi['nama']]);
                        $st = $stmtStockFind->fetch();
                        if ($st) {
                            $satuanStock = strtolower(trim($st['satuan']));
                            $satuanOrder = strtolower(trim($vi['satuan']));
                            $konversiBerat = ['gr' => 1, 'ons' => 100, 'kg' => 1000];
                            $stockSebelum = (float)$st['stock_fisik'];
                            $ketStockLog = 'Penjualan ' . $invoiceNo . ' - ' . $sppg['nama'];

                            if ($satuanStock === $satuanOrder) {
                                $stmtStockUpdate->execute([$vi['qty'], $st['id']]);
                                log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, max(0, $stockSebelum - $vi['qty']), $ketStockLog, 'Penjualan', $orderId, $userId, $tanggal);
                            } elseif (isset($konversiBerat[$satuanStock]) && isset($konversiBerat[$satuanOrder])) {
                                $qtyDikonversi = $vi['qty'] * $konversiBerat[$satuanOrder] / $konversiBerat[$satuanStock];
                                $stmtStockUpdate->execute([$qtyDikonversi, $st['id']]);
                                log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, max(0, $stockSebelum - $qtyDikonversi), $ketStockLog, 'Penjualan', $orderId, $userId, $tanggal);
                            }
                            // satuan lain yang gak ada rumus konversinya - biarin, gak dipotong,
                            // biar gak salah kurang - update manual lewat Stock Barang.
                        }
                    }

                    $totalHpp = $total - $totalMargin;
                    $jurnalLines = [
                        ['akun_id' => akun_id($pdo, '1-110'), 'debit' => $total, 'kredit' => 0, 'keterangan' => 'Piutang ' . $sppg['nama']],
                        ['akun_id' => akun_id($pdo, '4-101'), 'debit' => 0, 'kredit' => $total, 'keterangan' => 'Penjualan ' . $invoiceNo],
                    ];
                    if ($totalHpp > 0) {
                        $jurnalLines[] = ['akun_id' => akun_id($pdo, '5-101'), 'debit' => $totalHpp, 'kredit' => 0, 'keterangan' => 'HPP ' . $invoiceNo];
                        $jurnalLines[] = ['akun_id' => akun_id($pdo, '1-120'), 'debit' => 0, 'kredit' => $totalHpp, 'keterangan' => 'Pengurangan persediaan ' . $invoiceNo];
                    }
                    post_jurnal($pdo, $tanggal, 'Penjualan ' . $invoiceNo . ' - ' . $sppg['nama'], $jurnalLines, 'Penjualan', $orderId, $userId, $invoiceNo);

                    if ($draftId > 0) {
                        $pdo->prepare("DELETE FROM order_drafts WHERE id = ?")->execute([$draftId]);
                    }

                    $pdo->commit();
                    redirect('invoice.php?id=' . $orderId);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Gagal menyimpan order: ' . $e->getMessage();
                }
            }
        }
    }
}

if (isset($_GET['delete_draft'])) {
    $stmt = $pdo->prepare("DELETE FROM order_drafts WHERE id = ?");
    $stmt->execute([(int)$_GET['delete_draft']]);
    redirect('order.php' . ($jenis !== 'utama' ? '?jenis=' . $jenis : ''));
}

// ---------------------------------------------------------
// Load draft (kalau lagi lanjutin draft lewat ?draft_id=X)
// ---------------------------------------------------------
$loadedDraft = null;
$draftId = (int)($_GET['draft_id'] ?? 0);
if ($draftId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM order_drafts WHERE id = ?");
    $stmt->execute([$draftId]);
    $loadedDraft = $stmt->fetch();
    if ($loadedDraft) {
        // draft yang dilanjutin nentuin jenis invoice-nya
        $jenis = normalize_jenis($loadedDraft['jenis'] ?? 'utama');
        $jenisCfg = invoice_jenis_config()[$jenis];
    }
}
$active = $jenis === 'utama' ? 'order' : 'order_' . $jenis;
$page_title = $jenis === 'utama' ? 'Buat Order' : $jenisCfg['label'];

$sppgs = $pdo->query("SELECT * FROM sppg WHERE status = 'Aktif' ORDER BY nama ASC")->fetchAll();
$produk = $pdo->query("SELECT * FROM produk ORDER BY nama ASC")->fetchAll();

$stockRows = $pdo->query("SELECT nama, satuan, stock_fisik FROM stock")->fetchAll();
$stockMap = [];
foreach ($stockRows as $s) {
    $stockMap[strtolower(trim($s['nama']))] = ['stock' => (float)$s['stock_fisik'], 'satuan' => $s['satuan']];
}
foreach ($produk as &$p) {
    $key = strtolower(trim($p['nama']));
    $p['stock'] = array_key_exists($key, $stockMap) ? $stockMap[$key]['stock'] : null;
    $p['stock_satuan'] = array_key_exists($key, $stockMap) ? $stockMap[$key]['satuan'] : null;
}
unset($p);

// Menu Primova Buah / Frozen: cuma tampilin produk kategori yang sesuai (bisa dibuka semua lewat link).
$tampilSemua = !empty($_GET['semua']);
$produkTotalSemua = count($produk);
if (!empty($jenisCfg['kategori']) && !$tampilSemua) {
    $produk = array_values(array_filter($produk, fn($p) => in_array($p['kategori'], $jenisCfg['kategori'], true)));
}

// Harga per satuan alternatif yang pernah dipakai sebelumnya (misal Brokoli per pcs Rp2.000),
// biar gak perlu ketik ulang manual tiap kali ganti satuan buat produk yang sama.
$satuanAltRows = $pdo->query("SELECT produk_id, satuan, harga_jual, harga_modal FROM produk_satuan_alt")->fetchAll();
$satuanAltMap = [];
foreach ($satuanAltRows as $sa) {
    $satuanAltMap[$sa['produk_id']][$sa['satuan']] = ['harga_jual' => (float)$sa['harga_jual'], 'harga_modal' => (float)$sa['harga_modal']];
}

// Harga khusus per SPPG (misal Bawang Merah 25rb di SPPG Tangsel, 28rb di SPPG Ciater) -
// otomatis kepake pas SPPG yang sama order produk yang sama lagi.
$hargaSppgRows = $pdo->query("SELECT produk_id, sppg_id, harga_jual, harga_modal FROM produk_harga_sppg")->fetchAll();
$sppgHargaMap = [];
foreach ($hargaSppgRows as $hs) {
    $sppgHargaMap[$hs['sppg_id']][$hs['produk_id']] = ['harga_jual' => (float)$hs['harga_jual'], 'harga_modal' => (float)$hs['harga_modal']];
}

$draftStmt = $pdo->prepare("SELECT * FROM order_drafts WHERE jenis = ? ORDER BY updated_at DESC");
$draftStmt->execute([$jenis]);
$semuaDraft = $draftStmt->fetchAll();
$jenisQuery = $jenis !== 'utama' ? '&jenis=' . $jenis : '';
$jenisQueryFirst = $jenis !== 'utama' ? '?jenis=' . $jenis : '';

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title"><?= $jenis === 'utama' ? 'Buat Order' : h($jenisCfg['icon'] . ' ' . $jenisCfg['label']) ?></div>
    <div class="page-sub"><?= $jenis === 'utama'
        ? 'Pilih SPPG, tambahkan item, lalu terbitkan nota &amp; invoice.'
        : 'Invoice terpisah dengan seri nomor ' . h($jenisCfg['prefix']) . ' dan kop usaha sendiri (atur di Master Data → Profil Usaha).' ?></div>
  </div>
  <div style="display:flex; gap:8px;">
    <?php if (!empty($semuaDraft)): ?>
      <button type="button" class="btn btn-subtle" onclick="toggleDraftList()">📋 Lihat Draft (<?= count($semuaDraft) ?>)</button>
    <?php endif; ?>
    <?php if ($jenis !== 'utama'): ?>
      <a href="riwayat.php?jenis=<?= h($jenis) ?>" class="btn btn-subtle">🧾 Riwayat <?= h($jenisCfg['short']) ?></a>
    <?php endif; ?>
    <a href="order.php<?= $jenisQueryFirst ?>" class="btn btn-ghost" onclick="return confirmOrderBaru()">🆕 Order Baru</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>
<?php if (!empty($jenisCfg['kategori'])): ?>
  <div class="alert alert-info">
    <?php if ($tampilSemua): ?>
      Lagi nampilin <b>semua produk</b> (<?= $produkTotalSemua ?>).
      <a href="order.php?jenis=<?= h($jenis) ?><?= $loadedDraft ? '&draft_id=' . (int)$loadedDraft['id'] : '' ?>">Balik ke kategori <?= h(implode(' + ', $jenisCfg['kategori'])) ?> saja</a>
    <?php else: ?>
      Menampilkan produk kategori <b><?= h(implode(' + ', $jenisCfg['kategori'])) ?></b> saja (<?= count($produk) ?> dari <?= $produkTotalSemua ?> produk).
      <a href="order.php?jenis=<?= h($jenis) ?>&semua=1<?= $loadedDraft ? '&draft_id=' . (int)$loadedDraft['id'] : '' ?>">Tampilkan semua produk</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-ok">💾 Draft tersimpan. Bisa dilanjutin kapan aja dari tombol "Lihat Draft".</div>
<?php endif; ?>
<?php if (isset($_GET['produk_ditambahkan'])): ?>
  <div class="alert alert-ok">✅ Produk baru berhasil ditambahkan. Cari lagi namanya di bawah buat masukin ke order ini.</div>
<?php endif; ?>

<?php if (!empty($semuaDraft)): ?>
<div class="card card-pad" style="margin-bottom:16px; <?= $draftId ? '' : 'display:none;' ?>" id="draft-list-panel">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <div style="font-weight:700; font-size:14px;">📋 Draft Tersimpan (<?= count($semuaDraft) ?>)</div>
    <button type="button" onclick="toggleDraftList()" style="background:none; border:none; color:var(--muted); cursor:pointer; font-size:13px;">✕ Tutup</button>
  </div>
  <div style="display:flex; flex-direction:column; gap:8px;">
    <?php foreach ($semuaDraft as $d):
      $ditems = json_decode($d['items_json'], true) ?: [];
      $dtotal = array_sum(array_map(fn($i) => $i['qty'] * $i['harga_jual'], $ditems));
    ?>
    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:var(--bg); border-radius:8px; <?= $d['id'] == $draftId ? 'border:1px solid var(--primary);' : '' ?>">
      <div>
        <span style="font-weight:600; font-size:13px;"><?= h($d['sppg_nama']) ?></span>
        <span style="font-size:11.5px; color:var(--muted);"> · <?= count($ditems) ?> item · <?= rupiah($dtotal) ?> · <?= fmt_tanggal($d['tanggal']) ?></span>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <a href="order.php?draft_id=<?= $d['id'] ?><?= $jenisQuery ?>" class="btn btn-subtle btn-sm">✎ Lanjutkan</a>
        <a href="order.php?delete_draft=<?= $d['id'] ?><?= $jenisQuery ?>" style="color:var(--danger);" onclick="return confirm('Hapus draft ini?')">🗑</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<form method="POST" id="order-form">
  <input type="hidden" name="jenis" value="<?= h($jenis) ?>">
  <input type="hidden" name="draft_id" id="draft_id" value="<?= $loadedDraft ? $loadedDraft['id'] : '' ?>">
  <input type="hidden" name="redirect_after_draft" id="redirect_after_draft" value="">
  <input type="hidden" name="produk_nama_baru" id="produk_nama_baru" value="">
  <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:16px;">

    <div>
      <div class="card card-pad" style="margin-bottom:16px;">
        <div class="form-row grid-2">
          <div>
            <label class="field-label">SPPG / Dapur</label>
            <select name="sppg_id" required onchange="onSppgChange(this)">
              <option value="">— Pilih SPPG —</option>
              <?php foreach ($sppgs as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $loadedDraft && $loadedDraft['sppg_id'] == $s['id'] ? 'selected' : '' ?>><?= h($s['nama']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="field-label">Tanggal Order</label>
            <input type="date" name="tanggal" value="<?= $loadedDraft ? h($loadedDraft['tanggal']) : date('Y-m-d') ?>" required>
          </div>
        </div>
      </div>

      <div class="card card-pad">
        <label class="field-label">Cari Produk</label>
        <input type="search" id="search-produk" placeholder="Ketik nama produk, misal: mangga, melon..." style="margin-bottom:10px;">
        <div class="search-results" id="search-results" style="display:none;"></div>
        <div id="search-hint" style="font-size:12.5px; color:var(--muted);">Ketik untuk mencari dari <?= count($produk) ?> produk di pricelist.</div>
      </div>
    </div>

    <div class="card card-pad" style="height:fit-content;">
      <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Item Order (<span id="cart-count">0</span>)</div>
      <div id="cart-empty" class="empty-state">
        <div class="icon">🛒</div>
        <div class="title">Keranjang kosong</div>
        <div>Cari &amp; klik produk di sebelah kiri untuk menambahkan.</div>
      </div>
      <div id="cart-list"></div>

      <div style="display:flex; justify-content:space-between; font-size:13.5px; margin:10px 0 4px;">
        <span style="color:var(--muted);">Total Margin</span>
        <span id="cart-margin" style="font-family:var(--font-mono);">Rp0</span>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:17px; font-weight:700; margin-bottom:14px;">
        <span>Total</span>
        <span id="cart-total" style="font-family:var(--font-mono);">Rp0</span>
      </div>
      <button type="submit" name="action" value="create_order" class="btn btn-accent" id="submit-btn" style="width:100%; justify-content:center; padding:11px 0; margin-bottom:8px;" disabled>
        🧾 Buat Nota &amp; Invoice
      </button>
      <button type="submit" name="action" value="save_draft" class="btn btn-subtle" id="draft-btn" style="width:100%; justify-content:center;">
        💾 Simpan sebagai Draft
      </button>
    </div>
  </div>
</form>

<script>
const PRODUK = <?= json_encode($produk, JSON_UNESCAPED_UNICODE) ?>;
const SATUAN_ALT = <?= json_encode($satuanAltMap, JSON_UNESCAPED_UNICODE) ?>;
const SPPG_HARGA = <?= json_encode($sppgHargaMap, JSON_UNESCAPED_UNICODE) ?>;
const KONVERSI_BERAT = { gr: 1, ons: 100, kg: 1000 };
let cart = <?= $loadedDraft ? json_encode(json_decode($loadedDraft['items_json'], true), JSON_UNESCAPED_UNICODE) : '[]' ?>;

// samain ulang status "habis" tiap item draft sama data stock TERBARU
cart.forEach(i => {
  const p = PRODUK.find(x => x.id == i.produk_id);
  i.habis = p ? (p.stock !== null && p.stock <= 0) : false;
  i.stock_satuan = p ? p.stock_satuan : null;
});

const searchInput = document.getElementById('search-produk');
const resultsBox = document.getElementById('search-results');
const hintBox = document.getElementById('search-hint');

function formatRupiah(n) {
  return 'Rp' + Math.round(n).toLocaleString('id-ID');
}

function getSppgId() {
  return document.querySelector('[name="sppg_id"]').value;
}

searchInput.addEventListener('input', () => {
  const q = searchInput.value.trim().toLowerCase();
  if (!q) { resultsBox.style.display = 'none'; hintBox.style.display = 'block'; return; }
  const matches = PRODUK.filter(p => p.nama.toLowerCase().includes(q)).slice(0, 30);
  hintBox.style.display = 'none';
  resultsBox.style.display = 'block';

  if (matches.length === 0) {
    const namaBaru = searchInput.value.trim().replace(/'/g, "\\'");
    resultsBox.innerHTML = `
      <div style="padding:14px 12px; font-size:13px;">
        <div style="color:var(--danger); font-weight:700; margin-bottom:6px;">⚠ "${searchInput.value.trim()}" belum terdaftar di Master Produk</div>
        <div style="color:var(--muted); font-size:12px; margin-bottom:10px;">Order yang lagi kamu isi otomatis kesimpen dulu sebagai draft, gak akan hilang.</div>
        <button type="button" onclick="tambahProdukBaru('${namaBaru}')" class="btn btn-accent btn-sm">+ Tambah Produk & Lanjutkan</button>
      </div>`;
    return;
  }

  resultsBox.innerHTML = matches.map(p => {
    const habis = p.stock !== null && p.stock <= 0;
    const stockTag = habis
      ? ' <span style="color:var(--danger); font-weight:700; font-size:11px;">● HABIS</span>'
      : (p.stock !== null && p.stock <= 5 ? ` <span style="color:var(--danger); font-size:11px;">(sisa ${p.stock})</span>` : '');
    return `
    <div class="item" data-id="${p.id}">
      <span>${p.nama}${stockTag} <span style="color:var(--muted); font-size:11.5px;">(${p.kategori})</span></span>
      <span style="font-family:var(--font-mono); font-weight:600;">${formatRupiah(p.harga_jual)}/${p.satuan}</span>
    </div>
  `;
  }).join('');
  resultsBox.querySelectorAll('.item').forEach(el => {
    el.addEventListener('click', () => {
      const p = PRODUK.find(x => x.id == el.dataset.id);
      addToCart(p);
      searchInput.value = '';
      resultsBox.style.display = 'none';
      hintBox.style.display = 'block';
    });
  });
});

function addToCart(p) {
  const habis = p.stock !== null && p.stock <= 0;
  if (habis) {
    const lanjut = confirm(`⚠ Stock "${p.nama}" sudah HABIS (0 ${p.satuan}).\n\nTetap mau ditambahkan ke order ini? (Nanti perlu direstock dulu sebelum dikirim)`);
    if (!lanjut) return;
  }

  // Cek dulu apakah SPPG yang lagi dipilih punya harga khusus buat produk ini
  const sppgId = getSppgId();
  const hargaKhusus = sppgId && SPPG_HARGA[sppgId] && SPPG_HARGA[sppgId][p.id];
  const hargaJualDipakai = hargaKhusus ? hargaKhusus.harga_jual : parseFloat(p.harga_jual);
  const hargaModalDipakai = hargaKhusus ? hargaKhusus.harga_modal : parseFloat(p.harga_modal);

  const existing = cart.find(i => i.produk_id === p.id);
  if (existing) { existing.qty += 1; }
  else {
    cart.push({
      produk_id: p.id, nama: p.nama, satuan: p.satuan, qty: 1,
      harga_jual: hargaJualDipakai, harga_modal: hargaModalDipakai,
      habis: habis, stock_satuan: p.stock_satuan, hargaKhususSppg: !!hargaKhusus,
    });
  }
  renderCart();
}

function removeFromCart(idx) { cart.splice(idx, 1); renderCart(); }

function updateSatuan(idx, val) {
  const satuanLama = cart[idx].satuan;
  cart[idx].satuan = val;
  cart[idx].hargaKhususSppg = false; // ganti satuan = keluar dari basis harga khusus SPPG

  const remembered = SATUAN_ALT[cart[idx].produk_id] && SATUAN_ALT[cart[idx].produk_id][val];
  if (remembered) {
    cart[idx].harga_jual = remembered.harga_jual;
    cart[idx].harga_modal = remembered.harga_modal;
  } else if (KONVERSI_BERAT[satuanLama] && KONVERSI_BERAT[val]) {
    const rasio = KONVERSI_BERAT[val] / KONVERSI_BERAT[satuanLama];
    cart[idx].harga_jual = Math.round(cart[idx].harga_jual * rasio);
    cart[idx].harga_modal = Math.round(cart[idx].harga_modal * rasio);
  }
  renderCart();
}

function updateQty(idx, val) { cart[idx].qty = parseFloat(val) || 0; renderCart(); }
function updateHarga(idx, val) { cart[idx].harga_jual = parseFloat(val) || 0; cart[idx].hargaKhususSppg = false; renderCart(); }
function updateModal(idx, val) { cart[idx].harga_modal = parseFloat(val) || 0; renderCart(); }

function onSppgChange(select) {
  const draftIdField = document.getElementById('draft_id');
  if (draftIdField.value && select.value !== LOADED_DRAFT_SPPG_ID) {
    draftIdField.value = '';
  }
}
const LOADED_DRAFT_SPPG_ID = <?= json_encode($loadedDraft ? (string)$loadedDraft['sppg_id'] : '') ?>;

function tambahProdukBaru(namaBaru) {
  const sppgId = getSppgId();
  if (!sppgId || cart.length === 0) {
    window.location.href = 'master_produk.php?tambah=1&nama=' + encodeURIComponent(namaBaru);
    return;
  }
  document.getElementById('redirect_after_draft').value = '1';
  document.getElementById('produk_nama_baru').value = namaBaru;
  document.getElementById('draft-btn').click();
}

function toggleDraftList() {
  const panel = document.getElementById('draft-list-panel');
  if (panel) panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function confirmOrderBaru() {
  if (cart.length > 0) {
    return confirm('Ada item yang belum disimpan di keranjang ini. Yakin mau mulai order baru? Item yang belum disimpan bakal hilang (kalau mau disimpen dulu, klik "Simpan sebagai Draft" sebelum ini).');
  }
  return true;
}

function renderCart() {
  const listEl = document.getElementById('cart-list');
  const emptyEl = document.getElementById('cart-empty');
  document.getElementById('cart-count').textContent = cart.length;

  if (cart.length === 0) {
    emptyEl.style.display = 'block';
    listEl.innerHTML = '';
  } else {
    emptyEl.style.display = 'none';
    listEl.innerHTML = cart.map((i, idx) => {
      const satuanOptions = ['kg','gr','ons','pcs','buah','ikat','pack','peti','krat','dus','bungkus','liter','derigen'];
      if (!satuanOptions.includes(i.satuan)) satuanOptions.unshift(i.satuan);
      const optionsHtml = satuanOptions.map(s => `<option value="${s}" ${s === i.satuan ? 'selected' : ''}>${s}</option>`).join('');

      const satuanBeda = i.stock_satuan && i.stock_satuan.toLowerCase() !== i.satuan.toLowerCase();
      const bisaDikonversi = satuanBeda && KONVERSI_BERAT[i.satuan] && KONVERSI_BERAT[i.stock_satuan.toLowerCase()];
      const warningHtml = satuanBeda
        ? (bisaDikonversi
            ? `<div style="font-size:10.5px; color:var(--ok); margin-top:2px;">✓ Stock (${i.stock_satuan}) otomatis kepotong sesuai konversi ${i.satuan}→${i.stock_satuan}</div>`
            : `<div style="font-size:10.5px; color:var(--danger); margin-top:2px;">⚠ Satuan beda dari Stock (${i.stock_satuan}) — stock gak otomatis kepotong, update manual ya</div>`)
        : '';

      const adaHargaIngat = SATUAN_ALT[i.produk_id] && SATUAN_ALT[i.produk_id][i.satuan]
        && SATUAN_ALT[i.produk_id][i.satuan].harga_jual === i.harga_jual && !i.hargaKhususSppg;
      let hintHtml = '';
      if (i.hargaKhususSppg) {
        hintHtml = `<div style="font-size:10.5px; color:var(--ok); margin-top:2px;">💡 Harga khusus buat SPPG ini (otomatis dari transaksi sebelumnya)</div>`;
      } else if (adaHargaIngat) {
        hintHtml = `<div style="font-size:10.5px; color:var(--ok); margin-top:2px;">💡 Harga per ${i.satuan} otomatis dari transaksi sebelumnya - ubah manual kalau beda</div>`;
      }

      return `
      <div class="cart-item">
        <div class="row1">
          <span style="font-weight:600; font-size:13px;">${i.nama}${i.habis ? ' <span style="color:var(--danger); font-weight:700; font-size:11px;">⚠ Stock habis</span>' : ''}</span>
          <button type="button" onclick="removeFromCart(${idx})" style="background:none; border:none; cursor:pointer; color:var(--danger);">✕</button>
        </div>
        <div class="row2" style="flex-wrap:wrap;">
          <input type="number" min="0" step="any" value="${i.qty}" onchange="updateQty(${idx}, this.value)" style="width:60px;">
          <select onchange="updateSatuan(${idx}, this.value)" style="width:auto; padding:6px 6px; font-size:12px;">${optionsHtml}</select>
          <span style="font-size:11.5px; color:var(--muted);">@</span>
          <input type="number" min="0" step="any" value="${i.harga_jual}" onchange="updateHarga(${idx}, this.value)" title="Harga jual per satuan" style="width:90px; font-family:var(--font-mono); font-size:12px;">
          <span style="margin-left:auto; font-family:var(--font-mono); font-weight:700; font-size:13px;">${formatRupiah(i.qty * i.harga_jual)}</span>
        </div>
        <div class="row2" style="margin-top:4px;">
          <span style="font-size:11px; color:var(--muted);">Modal/satuan:</span>
          <input type="number" min="0" step="any" value="${i.harga_modal}" onchange="updateModal(${idx}, this.value)" title="Harga modal per satuan - buat akurasi margin & HPP" style="width:90px; font-family:var(--font-mono); font-size:11.5px;">
          <span style="font-size:11px; color:var(--muted); margin-left:auto;">Margin: <b style="color:var(--ok); font-family:var(--font-mono);">${formatRupiah(i.qty * (i.harga_jual - i.harga_modal))}</b></span>
        </div>
        ${warningHtml}
        ${hintHtml}
        <input type="hidden" name="item[${idx}][produk_id]" value="${i.produk_id}">
        <input type="hidden" name="item[${idx}][nama]" value="${i.nama}">
        <input type="hidden" name="item[${idx}][satuan]" value="${i.satuan}">
        <input type="hidden" name="item[${idx}][qty]" value="${i.qty}">
        <input type="hidden" name="item[${idx}][harga_jual]" value="${i.harga_jual}">
        <input type="hidden" name="item[${idx}][harga_modal]" value="${i.harga_modal}">
      </div>
    `;
    }).join('');
  }

  const total = cart.reduce((s, i) => s + i.qty * i.harga_jual, 0);
  const margin = cart.reduce((s, i) => s + i.qty * (i.harga_jual - i.harga_modal), 0);
  document.getElementById('cart-total').textContent = formatRupiah(total);
  document.getElementById('cart-margin').textContent = formatRupiah(margin);
  document.getElementById('submit-btn').disabled = cart.length === 0;
}

renderCart(); // render draft yang udah diload (kalau ada)
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>