<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'master';
$page_title = 'Master Produk';

/** Cek kemiripan 2 nama produk berdasarkan kata yang sama (bukan cuma persis sama),
 *  misal "Gula" vs "Gula Pasir" ke-detect mirip karena kata "gula" muncul di dua-duanya. */
function kata_kunci($nama) {
    $nama = strtolower(trim($nama));
    $kata = preg_split('/[\s\-\(\)\/]+/', $nama, -1, PREG_SPLIT_NO_EMPTY);
    return array_filter($kata, fn($k) => strlen($k) >= 3); // buang kata terlalu pendek (1L, kg, dst)
}

function cari_produk_mirip($namaStock, $semuaProduk) {
    $kataStock = kata_kunci($namaStock);
    if (empty($kataStock)) return null;
    $terbaik = null;
    $skorTerbaik = 0;
    foreach ($semuaProduk as $p) {
        $kataProduk = kata_kunci($p['nama']);
        $irisan = array_intersect($kataStock, $kataProduk);
        if (empty($irisan)) continue;
        $skor = count($irisan) / max(count($kataStock), count($kataProduk));
        if ($skor > $skorTerbaik) {
            $skorTerbaik = $skor;
            $terbaik = $p;
        }
    }
    // ambang batas biar gak asal ketemu 1 kata umum doang
    return ($skorTerbaik >= 0.4) ? $terbaik : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $stmt = $pdo->prepare("INSERT INTO produk (kategori, nama, satuan, harga_modal, harga_jual) VALUES (?,?,?,?,?)");
    $stmt->execute([
        $_POST['kategori'], trim($_POST['nama']), trim($_POST['satuan']),
        clean_number($_POST['harga_modal']), clean_number($_POST['harga_jual']),
    ]);
    $newId = $pdo->lastInsertId();
    if (!empty($_POST['return_draft'])) {
        redirect('order.php?draft_id=' . (int)$_POST['return_draft'] . '&produk_ditambahkan=1');
    }
    redirect('master_produk.php?jump_to=' . $newId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_ignore') {
    $stmt = $pdo->prepare("INSERT IGNORE INTO stock_sync_ignore (stock_nama, catatan) VALUES (?, ?)");
    $stmt->execute([trim($_POST['stock_nama']), 'Sama kayak: ' . trim($_POST['mirip_nama'] ?? '')]);
    redirect('master_produk.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM produk WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    redirect('master_produk.php');
}

$search = $_GET['q'] ?? '';
$showAdd = isset($_GET['tambah']);
$prefillNama = $_GET['nama'] ?? '';
$prefillSatuan = $_GET['satuan'] ?? 'kg';
$prefillModal = $_GET['harga_modal'] ?? 0;
$returnDraft = $_GET['return_draft'] ?? '';

if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM produk WHERE nama LIKE ? ORDER BY nama ASC");
    $stmt->execute(['%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM produk ORDER BY nama ASC");
}
$produk = $stmt->fetchAll();

$kategoriList = ['Buah', 'Sayur', 'Frozen', 'Bahan Baku', 'Daging & Seafood', 'Saus', 'Jasa/Lainnya'];

// Barang yang udah ada di Stock tapi belum ada padanannya di Master Produk - biar
// ketauan kalau ada yang kelewat belum disinkronin (misal barang baru dari gudang).
// Barang yang udah dikonfirmasi "sama kayak yang lain" (stock_sync_ignore) gak dimunculin lagi.
$stockBelumSyncRaw = $pdo->query("
    SELECT s.nama, s.satuan, s.harga_modal FROM stock s
    WHERE NOT EXISTS (SELECT 1 FROM produk p WHERE LOWER(TRIM(p.nama)) = LOWER(TRIM(s.nama)))
      AND NOT EXISTS (SELECT 1 FROM stock_sync_ignore si WHERE LOWER(TRIM(si.stock_nama)) = LOWER(TRIM(s.nama)))
    ORDER BY s.nama ASC
")->fetchAll();

$semuaProdukUntukMatch = $pdo->query("SELECT id, nama FROM produk")->fetchAll();
$stockBelumSync = [];
foreach ($stockBelumSyncRaw as $s) {
    $mirip = cari_produk_mirip($s['nama'], $semuaProdukUntukMatch);
    $s['mirip'] = $mirip;
    $stockBelumSync[] = $s;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Master Data</div>
    <div class="page-sub">Kelola daftar produk, harga, SPPG, dan profil usaha.</div>
  </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px;">
  <a href="master_produk.php" class="btn btn-primary">Produk &amp; Harga</a>
  <a href="master_profil.php" class="btn btn-subtle">Profil Usaha &amp; Invoice</a>
  <a href="master_users.php" class="btn btn-subtle">Kelola User</a>
</div>

<div class="card card-pad" style="margin-bottom:14px;">
  <form method="GET" style="display:flex; gap:10px;">
    <input type="search" name="q" placeholder="Cari produk..." value="<?= h($search) ?>" style="flex:1;">
    <button type="submit" class="btn btn-subtle">Cari</button>
    <a href="master_produk_import.php" class="btn btn-subtle">⬆ Import dari Excel</a>
    <a href="export_excel.php?type=produk" class="btn btn-subtle">⬇ Export Excel</a>
    <a href="master_produk.php?tambah=1" class="btn btn-accent">+ Produk Baru</a>
  </form>
</div>

<?php if (!empty($stockBelumSync)): ?>
<div class="card card-pad" style="margin-bottom:14px; border:1px solid var(--danger);">
  <div style="font-weight:700; font-size:14px; margin-bottom:2px; color:var(--danger);">⚠ Belum Sinkron dengan Stock (<?= count($stockBelumSync) ?>)</div>
  <div style="font-size:11.5px; color:var(--muted); margin-bottom:12px;">
    Barang ini udah ada di Stock Barang, tapi belum ada di Master Produk (pricelist jual ke SPPG).
    Kalau barang ini memang mau dijual langsung, tambahin di bawah — kalau cuma bahan baku internal (kayak Tepung, Garam), boleh diabaikan aja.
  </div>
  <div style="display:flex; flex-direction:column; gap:6px;">
    <?php foreach ($stockBelumSync as $s): ?>
    <div style="padding:10px 12px; background:var(--bg); border-radius:8px;">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span style="font-weight:600; font-size:13px;"><?= h($s['nama']) ?> <span style="font-weight:400; color:var(--muted); font-size:11.5px;">(<?= h($s['satuan']) ?>)</span></span>
        <?php if (!$s['mirip']): ?>
          <a href="master_produk.php?tambah=1&nama=<?= urlencode($s['nama']) ?>&satuan=<?= urlencode($s['satuan']) ?>&harga_modal=<?= (float)$s['harga_modal'] ?>" class="btn btn-subtle btn-sm">+ Tambah ke Master Produk</a>
        <?php endif; ?>
      </div>
      <?php if ($s['mirip']): ?>
        <div style="margin-top:6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; background:var(--surface); border-radius:6px; padding:8px 10px;">
          <span style="font-size:12px; color:var(--muted);">Mirip sama <b style="color:var(--text);"><?= h($s['mirip']['nama']) ?></b> yang udah ada — sama gak?</span>
          <div style="display:flex; gap:6px;">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="sync_ignore">
              <input type="hidden" name="stock_nama" value="<?= h($s['nama']) ?>">
              <input type="hidden" name="mirip_nama" value="<?= h($s['mirip']['nama']) ?>">
              <button type="submit" class="btn btn-subtle btn-sm">✓ Ya, sama</button>
            </form>
            <a href="master_produk.php?tambah=1&nama=<?= urlencode($s['nama']) ?>&satuan=<?= urlencode($s['satuan']) ?>&harga_modal=<?= (float)$s['harga_modal'] ?>" class="btn btn-subtle btn-sm">+ Beda, barang baru</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($showAdd): ?>
<div class="card card-pad" style="margin-bottom:14px;">
  <?php if ($returnDraft): ?>
    <div class="alert alert-info">
      📋 Order kamu udah kesimpen sebagai draft. Tambahin produk baru di bawah, nanti otomatis balik lagi ke order-nya.
    </div>
  <?php endif; ?>
  <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:16px;">
    <div style="font-weight:700; font-size:12.5px; margin-bottom:8px;">🧮 Kalkulator harga per gram (opsional)</div>
    <div style="font-size:12px; color:var(--muted); margin-bottom:10px;">Buat produk yang harganya kamu tau per paket (misal per 100gr atau per 200gr), isi di sini — harga per gram-nya otomatis ke-itung, tinggal disalin ke kolom "Harga Jual" di bawah.</div>
    <div style="display:grid; grid-template-columns:1fr 1fr auto 1fr; gap:10px; align-items:end;">
      <div><label class="field-label">Harga per Paket</label><input type="number" step="any" id="calc-harga-paket" placeholder="misal 2000"></div>
      <div><label class="field-label">Berat Paket (gram)</label><input type="number" step="any" id="calc-berat-paket" placeholder="misal 100"></div>
      <div style="padding-bottom:9px; color:var(--muted);">÷</div>
      <div><label class="field-label">Hasil per Gram</label><input type="text" id="calc-hasil" readonly style="background:var(--surface); font-weight:700; font-family:var(--font-mono);"></div>
    </div>
    <button type="button" class="btn btn-subtle btn-sm" style="margin-top:10px;" onclick="pakaiHasilKalkulator()">↓ Pakai hasil ini ke form di bawah</button>
  </div>

  <form method="POST" style="display:grid; grid-template-columns:1fr 1.5fr 1fr 1fr 1fr auto; gap:10px; align-items:end;">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="return_draft" value="<?= h($returnDraft) ?>">
    <div><label class="field-label">Kategori</label>
      <select name="kategori">
        <?php foreach ($kategoriList as $k): ?><option value="<?= h($k) ?>"><?= h($k) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="field-label">Nama</label><input type="text" name="nama" value="<?= h($prefillNama) ?>" required></div>
    <div><label class="field-label">Satuan</label><input type="text" name="satuan" id="form-satuan" value="<?= h($prefillSatuan) ?>" required></div>
    <div><label class="field-label">Harga Modal</label><input type="number" step="any" name="harga_modal" value="<?= h($prefillModal) ?>"></div>
    <div><label class="field-label">Harga Jual</label><input type="number" step="any" name="harga_jual" id="form-harga-jual" value="0"></div>
    <button type="submit" class="btn btn-primary"><?= $returnDraft ? 'Simpan & Lanjut Order' : 'Simpan' ?></button>
  </form>
</div>
<script>
  function hitungKalkulator() {
    const harga = parseFloat(document.getElementById('calc-harga-paket').value) || 0;
    const berat = parseFloat(document.getElementById('calc-berat-paket').value) || 0;
    const hasilEl = document.getElementById('calc-hasil');
    if (berat > 0) {
      const perGram = harga / berat;
      hasilEl.value = 'Rp' + perGram.toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' /gram';
    } else {
      hasilEl.value = '';
    }
  }
  document.getElementById('calc-harga-paket').addEventListener('input', hitungKalkulator);
  document.getElementById('calc-berat-paket').addEventListener('input', hitungKalkulator);

  function pakaiHasilKalkulator() {
    const harga = parseFloat(document.getElementById('calc-harga-paket').value) || 0;
    const berat = parseFloat(document.getElementById('calc-berat-paket').value) || 0;
    if (berat <= 0) { alert('Isi dulu harga per paket & beratnya (gram).'); return; }
    const perGram = harga / berat;
    document.getElementById('form-harga-jual').value = perGram.toFixed(2);
    document.getElementById('form-satuan').value = 'gram';
  }
</script>
<?php endif; ?>

<div class="card" style="overflow:hidden;">
  <table class="data-table">
    <thead><tr><th>Kategori</th><th>Nama</th><th>Satuan</th><th>Harga Modal</th><th>Harga Jual</th><th>Margin</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($produk as $p): ?>
      <tr id="produk-<?= $p['id'] ?>">
        <td><span class="badge badge-muted"><?= h($p['kategori']) ?></span></td>
        <td style="font-weight:600;"><?= h($p['nama']) ?></td>
        <td><?= h($p['satuan']) ?></td>
        <td style="font-family:var(--font-mono);"><?= rupiah($p['harga_modal']) ?></td>
        <td style="font-family:var(--font-mono);"><?= rupiah($p['harga_jual']) ?></td>
        <td style="font-family:var(--font-mono); color:var(--ok); font-weight:700;"><?= rupiah($p['harga_jual'] - $p['harga_modal']) ?></td>
        <td style="text-align:right; white-space:nowrap;">
          <a href="master_produk_edit.php?id=<?= $p['id'] ?>" class="btn btn-subtle btn-sm">✎ Edit</a>
          <a href="master_produk.php?delete=<?= $p['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus produk ini?')">🗑</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div style="font-size:11.5px; color:var(--muted); margin-top:8px;">
  * Sebagian besar produk buah/sayur belum punya "Harga Modal" karena data awal cuma punya harga jual — isi manual biar perhitungan margin akurat.
</div>

<?php if (isset($_GET['jump_to'])): ?>
<script>
  // Abis edit produk, otomatis scroll balik ke baris yang barusan diedit,
  // biar gak perlu scroll dari atas lagi buat lanjut ke produk berikutnya.
  (function() {
    const row = document.getElementById('produk-<?= (int)$_GET['jump_to'] ?>');
    if (row) {
      row.scrollIntoView({ behavior: 'instant', block: 'center' });
      row.style.transition = 'background-color 0.3s';
      row.style.backgroundColor = 'var(--primary-soft, #d9f0c4)';
      setTimeout(() => { row.style.backgroundColor = ''; }, 1500);
    }
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>