<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'riwayat';
$page_title = 'Edit Order';

$orderId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
ensure_invoice_jenis_schema($pdo);
if (!$order) { redirect('riwayat.php'); }
$jenisAsal = normalize_jenis($order['jenis'] ?? 'utama');
$jenisConfigAll = invoice_jenis_config();

if ($order['status'] === 'Lunas') {
    redirect('invoice.php?id=' . $orderId . '&edit_blocked=1');
}

$error = '';

/** Tambah/kurangin stock buat 1 item, ngikutin aturan konversi gr/ons/kg yang sama
 *  kayak pas bikin order. $sign = +1 buat nambahin balik (reverse), -1 buat motong. */
function adjustStock($pdo, $nama, $qty, $satuanItem, $sign, $keterangan = '', $refTipe = null, $refId = null, $userId = null, $tanggal = null) {
    if ($qty <= 0) return;
    $stmtFind = $pdo->prepare("SELECT * FROM stock WHERE LOWER(nama) = LOWER(?) LIMIT 1");
    $stmtFind->execute([$nama]);
    $st = $stmtFind->fetch();
    if (!$st) return;
    $konversiBerat = ['gr' => 1, 'ons' => 100, 'kg' => 1000];
    $satuanStock = strtolower(trim($st['satuan']));
    $satuanOrder = strtolower(trim($satuanItem));
    $delta = null;
    if ($satuanStock === $satuanOrder) {
        $delta = $sign * $qty;
    } elseif (isset($konversiBerat[$satuanStock]) && isset($konversiBerat[$satuanOrder])) {
        $delta = $sign * $qty * $konversiBerat[$satuanOrder] / $konversiBerat[$satuanStock];
    }
    if ($delta !== null) {
        $stockSebelum = (float)$st['stock_fisik'];
        $stockSesudah = max(0, $stockSebelum + $delta);
        $pdo->prepare("UPDATE stock SET stock_fisik = GREATEST(0, stock_fisik + ?) WHERE id = ?")->execute([$delta, $st['id']]);
        log_stock($pdo, $st['id'], $st['nama'], $st['satuan'], $stockSebelum, $stockSesudah, $keterangan, $refTipe, $refId, $userId, $tanggal);
    }
}

/** Posting ulang jurnal Penjualan (+ HPP kalau ada) buat 1 order, dengan total/margin terbaru. */
function repostJurnalPenjualan($pdo, $order, $total, $totalMargin, $userId) {
    delete_jurnal_by_ref($pdo, 'Penjualan', $order['id']);
    $totalHpp = $total - $totalMargin;
    $lines = [
        ['akun_id' => akun_id($pdo, '1-110'), 'debit' => $total, 'kredit' => 0, 'keterangan' => 'Piutang ' . $order['sppg_nama']],
        ['akun_id' => akun_id($pdo, '4-101'), 'debit' => 0, 'kredit' => $total, 'keterangan' => 'Penjualan ' . $order['invoice_no']],
    ];
    if ($totalHpp > 0) {
        $lines[] = ['akun_id' => akun_id($pdo, '5-101'), 'debit' => $totalHpp, 'kredit' => 0, 'keterangan' => 'HPP ' . $order['invoice_no']];
        $lines[] = ['akun_id' => akun_id($pdo, '1-120'), 'debit' => 0, 'kredit' => $totalHpp, 'keterangan' => 'Pengurangan persediaan ' . $order['invoice_no']];
    }
    post_jurnal($pdo, $order['tanggal'], 'Penjualan ' . $order['invoice_no'] . ' - ' . $order['sppg_nama'], $lines, 'Penjualan', $order['id'], $userId, $order['invoice_no']);
}

// ---------------------------------------------------------
// SIMPAN PERUBAHAN (edit biasa, tetap 1 invoice yang sama)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_edit') {
    $tanggalBaru = $_POST['tanggal'] ?? $order['tanggal'];
    $items = $_POST['item'] ?? [];
    $validItems = [];
    $total = 0; $totalMargin = 0;
    foreach ($items as $it) {
        $qty = clean_number($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $hargaJual = clean_number($it['harga_jual'] ?? 0);
        $hargaModal = clean_number($it['harga_modal'] ?? 0);
        $subtotal = $qty * $hargaJual;
        $margin = $qty * ($hargaJual - $hargaModal);
        $total += $subtotal; $totalMargin += $margin;
        $validItems[] = [
            'produk_id' => (int)($it['produk_id'] ?? 0) ?: null, 'nama' => $it['nama'] ?? '',
            'qty' => $qty, 'satuan' => $it['satuan'] ?? '', 'harga_jual' => $hargaJual,
            'harga_modal' => $hargaModal, 'subtotal' => $subtotal, 'margin' => $margin,
        ];
    }

    if (empty($validItems)) {
        $error = 'Order minimal harus punya 1 item.';
    } else {
        $pdo->beginTransaction();
        try {
            $userId = current_user()['id'] ?? null;

            // balikin stock dari item LAMA, potong lagi pakai item BARU
            $ketEditOrder = 'Edit Order ' . $order['invoice_no'] . ' - ' . $order['sppg_nama'];
            $oldItemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $oldItemsStmt->execute([$orderId]);
            foreach ($oldItemsStmt->fetchAll() as $oi) {
                adjustStock($pdo, $oi['nama'], $oi['qty'], $oi['satuan'], +1, $ketEditOrder, 'Edit Order', $orderId, $userId, $tanggalBaru);
            }
            foreach ($validItems as $vi) {
                adjustStock($pdo, $vi['nama'], $vi['qty'], $vi['satuan'], -1, $ketEditOrder, 'Edit Order', $orderId, $userId, $tanggalBaru);
            }

            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);
            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, produk_id, nama, qty, satuan, harga_jual, harga_modal, subtotal, margin) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($validItems as $vi) {
                $stmtItem->execute([$orderId, $vi['produk_id'], $vi['nama'], $vi['qty'], $vi['satuan'], $vi['harga_jual'], $vi['harga_modal'], $vi['subtotal'], $vi['margin']]);
            }

            $pdo->prepare("UPDATE orders SET total = ?, total_margin = ?, tanggal = ? WHERE id = ?")->execute([$total, $totalMargin, $tanggalBaru, $orderId]);

            $order['total'] = $total;
            $order['tanggal'] = $tanggalBaru;
            repostJurnalPenjualan($pdo, $order, $total, $totalMargin, $userId);

            $pdo->commit();
            redirect('invoice.php?id=' . $orderId . '&edited=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal menyimpan perubahan: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// PISAHKAN ITEM TERPILIH KE INVOICE BARU
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'split') {
    $splitIds = array_map('intval', $_POST['split_item'] ?? []);

    if (empty($splitIds)) {
        $error = 'Centang minimal 1 item yang mau dipisah.';
    } else {
        $pdo->beginTransaction();
        try {
            $userId = current_user()['id'] ?? null;

            $checkStmt = $pdo->prepare("SELECT COUNT(*) c FROM order_items WHERE order_id = ?");
            $checkStmt->execute([$orderId]);
            $totalItemCount = (int)$checkStmt->fetch()['c'];
            if (count($splitIds) >= $totalItemCount) {
                throw new Exception('Gak bisa misahin SEMUA item - invoice asli harus tetap punya minimal 1 item.');
            }

            $placeholders = implode(',', array_fill(0, count($splitIds), '?'));
            $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE id IN ($placeholders) AND order_id = ?");
            $itemsStmt->execute(array_merge($splitIds, [$orderId]));
            $itemsToSplit = $itemsStmt->fetchAll();

            if (empty($itemsToSplit)) {
                throw new Exception('Item yang dipilih tidak ditemukan.');
            }

            $targetJenis = normalize_jenis($_POST['target_jenis'] ?? $jenisAsal);
            $newInvoiceNo = generate_invoice_no($pdo, $order['tanggal'], $order['sppg_id'], $jenisConfigAll[$targetJenis]['prefix']);
            $totalBaru = array_sum(array_column($itemsToSplit, 'subtotal'));
            $marginBaru = array_sum(array_column($itemsToSplit, 'margin'));

            $insOrder = $pdo->prepare("INSERT INTO orders (invoice_no, jenis, sppg_id, sppg_nama, tanggal, total, total_margin, status, created_by) VALUES (?,?,?,?,?,?,?, 'Belum Bayar', ?)");
            $insOrder->execute([$newInvoiceNo, $targetJenis, $order['sppg_id'], $order['sppg_nama'], $order['tanggal'], $totalBaru, $marginBaru, $userId]);
            $newOrderId = $pdo->lastInsertId();

            $moveStmt = $pdo->prepare("UPDATE order_items SET order_id = ? WHERE id = ?");
            foreach ($itemsToSplit as $it) {
                $moveStmt->execute([$newOrderId, $it['id']]);
            }

            $sisaStmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) t, COALESCE(SUM(margin),0) m FROM order_items WHERE order_id = ?");
            $sisaStmt->execute([$orderId]);
            $sisa = $sisaStmt->fetch();
            $pdo->prepare("UPDATE orders SET total = ?, total_margin = ? WHERE id = ?")->execute([$sisa['t'], $sisa['m'], $orderId]);

            $order['total'] = $sisa['t'];
            repostJurnalPenjualan($pdo, $order, $sisa['t'], $sisa['m'], $userId);

            $newOrder = ['id' => $newOrderId, 'invoice_no' => $newInvoiceNo, 'sppg_nama' => $order['sppg_nama'], 'tanggal' => $order['tanggal']];
            repostJurnalPenjualan($pdo, $newOrder, $totalBaru, $marginBaru, $userId);

            $pdo->commit();
            redirect('invoice.php?id=' . $newOrderId . '&split_created=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal memisahkan item: ' . $e->getMessage();
        }
    }
}

$itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$itemsStmt->execute([$orderId]);
$existingItems = $itemsStmt->fetchAll();

$produk = $pdo->query("SELECT * FROM produk ORDER BY nama ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Edit Order <?= h($order['invoice_no']) ?></div>
    <div class="page-sub"><?= h($order['sppg_nama']) ?> · <?= fmt_tanggal($order['tanggal']) ?></div>
  </div>
  <a href="invoice.php?id=<?= $orderId ?>" class="btn btn-ghost">← Kembali ke Invoice</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="alert alert-info">
  💡 Centang item di kanan buat <b>dipisah jadi invoice baru</b> (buat SPPG yang sama), atau ubah
  qty/hapus/tambah item terus klik <b>Simpan Perubahan</b> buat update invoice ini langsung.
</div>

<form method="POST" id="edit-form">
  <div class="card card-pad" style="margin-bottom:16px; max-width:280px;">
    <label class="field-label">Tanggal Order</label>
    <input type="date" name="tanggal" value="<?= h($order['tanggal']) ?>" required>
  </div>

  <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:16px;">
    <div class="card card-pad">
      <label class="field-label">Cari Produk (buat nambah item baru)</label>
      <input type="search" id="search-produk" placeholder="Ketik nama produk..." style="margin-bottom:10px;">
      <div class="search-results" id="search-results" style="display:none;"></div>
    </div>

    <div class="card card-pad">
      <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Item Order (<span id="cart-count">0</span>)</div>
      <div id="cart-list"></div>
      <div style="display:flex; justify-content:space-between; font-size:17px; font-weight:700; margin:14px 0;">
        <span>Total</span>
        <span id="cart-total" style="font-family:var(--font-mono);">Rp0</span>
      </div>
      <button type="submit" name="action" value="save_edit" class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:8px;">💾 Simpan Perubahan</button>
      <div style="margin-bottom:8px;">
        <label class="field-label" style="margin-bottom:4px;">Invoice baru hasil pemisahan jadi jenis</label>
        <select name="target_jenis" style="width:100%;">
          <?php foreach ($jenisConfigAll as $jk => $jc): ?>
            <option value="<?= $jk ?>" <?= $jk === $jenisAsal ? 'selected' : '' ?>><?= h($jc['icon'] . ' ' . $jc['short']) ?> (seri <?= h($jc['prefix']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" name="action" value="split" class="btn btn-accent" style="width:100%; justify-content:center;" onclick="return confirm('Pisahkan item yang dicentang jadi invoice baru?')">✂️ Pisahkan yang Dicentang ke Invoice Baru</button>
    </div>
  </div>
</form>

<script>
const PRODUK = <?= json_encode($produk, JSON_UNESCAPED_UNICODE) ?>;
let cart = <?= json_encode(array_map(fn($i) => [
    'db_id' => $i['id'], 'produk_id' => $i['produk_id'], 'nama' => $i['nama'], 'satuan' => $i['satuan'],
    'qty' => (float)$i['qty'], 'harga_jual' => (float)$i['harga_jual'], 'harga_modal' => (float)$i['harga_modal'],
], $existingItems), JSON_UNESCAPED_UNICODE) ?>;

function formatRupiah(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }

const searchInput = document.getElementById('search-produk');
const resultsBox = document.getElementById('search-results');
searchInput.addEventListener('input', () => {
  const q = searchInput.value.trim().toLowerCase();
  if (!q) { resultsBox.style.display = 'none'; return; }
  const matches = PRODUK.filter(p => p.nama.toLowerCase().includes(q)).slice(0, 20);
  resultsBox.style.display = 'block';
  resultsBox.innerHTML = matches.map(p => `<div class="item" data-id="${p.id}"><span>${p.nama}</span><span style="font-family:var(--font-mono);">${formatRupiah(p.harga_jual)}/${p.satuan}</span></div>`).join('');
  resultsBox.querySelectorAll('.item').forEach(el => {
    el.addEventListener('click', () => {
      const p = PRODUK.find(x => x.id == el.dataset.id);
      cart.push({ db_id: null, produk_id: p.id, nama: p.nama, satuan: p.satuan, qty: 1, harga_jual: parseFloat(p.harga_jual), harga_modal: parseFloat(p.harga_modal) });
      searchInput.value = ''; resultsBox.style.display = 'none';
      renderCart();
    });
  });
});

function removeFromCart(idx) { cart.splice(idx, 1); renderCart(); }
function updateQty(idx, val) { cart[idx].qty = parseFloat(val) || 0; renderCart(); }
function updateHarga(idx, val) { cart[idx].harga_jual = parseFloat(val) || 0; renderCart(); }

function renderCart() {
  document.getElementById('cart-count').textContent = cart.length;
  document.getElementById('cart-list').innerHTML = cart.map((i, idx) => `
    <div class="cart-item">
      <div class="row1">
        <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:13px;">
          ${i.db_id ? `<input type="checkbox" name="split_item[]" value="${i.db_id}">` : '<span style="width:14px;"></span>'}
          ${i.nama}
        </label>
        <button type="button" onclick="removeFromCart(${idx})" style="background:none; border:none; cursor:pointer; color:var(--danger);">✕</button>
      </div>
      <div class="row2">
        <input type="number" min="0" step="any" value="${i.qty}" onchange="updateQty(${idx}, this.value)" style="width:70px;">
        <span style="font-size:12px; color:var(--muted);">${i.satuan}</span>
        <input type="number" min="0" step="any" value="${i.harga_jual}" onchange="updateHarga(${idx}, this.value)" style="width:100px; font-family:var(--font-mono);">
        <span style="margin-left:auto; font-family:var(--font-mono); font-weight:700;">${formatRupiah(i.qty * i.harga_jual)}</span>
      </div>
      <input type="hidden" name="item[${idx}][produk_id]" value="${i.produk_id || ''}">
      <input type="hidden" name="item[${idx}][nama]" value="${i.nama}">
      <input type="hidden" name="item[${idx}][satuan]" value="${i.satuan}">
      <input type="hidden" name="item[${idx}][qty]" value="${i.qty}">
      <input type="hidden" name="item[${idx}][harga_jual]" value="${i.harga_jual}">
      <input type="hidden" name="item[${idx}][harga_modal]" value="${i.harga_modal}">
    </div>
  `).join('');
  const total = cart.reduce((s, i) => s + i.qty * i.harga_jual, 0);
  document.getElementById('cart-total').textContent = formatRupiah(total);
}
renderCart();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>