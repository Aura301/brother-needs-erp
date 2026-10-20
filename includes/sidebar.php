<?php
$me = current_user();
$active = $active ?? ''; // biar VS Code gak nganggep $active undefined (nilainya emang di-set dari header.php)
$jumlahPendaftaranBaru = 0;
$jumlahStockBelumSync = 0;
if (isset($pdo)) {
    try {
        $jumlahPendaftaranBaru = (int)$pdo->query("SELECT COUNT(*) c FROM pendaftaran WHERE status = 'Baru'")->fetch()['c'];
    } catch (Exception $e) {
        $jumlahPendaftaranBaru = 0; // tabelnya belum ada / migration belum jalan
    }
    try {
        $jumlahStockBelumSync = (int)$pdo->query("
            SELECT COUNT(*) c FROM stock s
            WHERE NOT EXISTS (SELECT 1 FROM produk p WHERE LOWER(TRIM(p.nama)) = LOWER(TRIM(s.nama)))
              AND NOT EXISTS (SELECT 1 FROM stock_sync_ignore si WHERE LOWER(TRIM(si.stock_nama)) = LOWER(TRIM(s.nama)))
        ")->fetch()['c'];
    } catch (Exception $e) {
        $jumlahStockBelumSync = 0;
    }
}
?>
<aside class="sidebar">
  <button type="button" class="sidebar-close" onclick="toggleSidebar(false)" aria-label="Tutup menu">✕</button>
  <div class="sidebar-brand">
    <img src="assets/img/logo.png" alt="Brother Needs">
    <div>
      <div class="name">Brother Needs</div>
      <div class="sub">Order · Stock · Keuangan</div>
    </div>
  </div>
  <nav>
    <a href="index.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
    <a href="laporan_harian.php" class="<?= $active === 'laporan_harian' ? 'active' : '' ?>">📋 Laporan Harian</a>
    <a href="insight.php" class="<?= $active === 'insight' ? 'active' : '' ?>">💡 Insight & Rekomendasi</a>
    <a href="order.php" class="<?= $active === 'order' ? 'active' : '' ?>">🛒 Buat Order</a>
    <a href="order.php?jenis=primova_buah" class="<?= $active === 'order_primova_buah' ? 'active' : '' ?>">🍊 Invoice Primofa Buah</a>
    <a href="order.php?jenis=frozen" class="<?= $active === 'order_frozen' ? 'active' : '' ?>">🧊 Invoice Frozen</a>
    <a href="riwayat.php" class="<?= $active === 'riwayat' ? 'active' : '' ?>">🧾 Riwayat &amp; Invoice</a>
    <a href="selisih.php" class="<?= $active === 'selisih' ? 'active' : '' ?>">➕ Invoice Selisih</a>
    <a href="stock.php" class="<?= $active === 'stock' ? 'active' : '' ?>">📦 Stock Barang</a>
    <a href="keuangan.php" class="<?= $active === 'keuangan' ? 'active' : '' ?>">💰 Keuangan</a>
    <a href="master_sppg.php" class="<?= $active === 'sppg' ? 'active' : '' ?>">📋 Daftar SPPG</a>
    <a href="pendaftaran.php" class="<?= $active === 'pendaftaran' ? 'active' : '' ?>" style="display:flex; justify-content:space-between; align-items:center;">
      <span>📝 Pendaftaran</span>
      <?php if ($jumlahPendaftaranBaru > 0): ?>
        <span style="background:var(--danger); color:#fff; font-size:10.5px; font-weight:700; border-radius:999px; padding:1px 7px;"><?= $jumlahPendaftaranBaru ?></span>
      <?php endif; ?>
    </a>
    <a href="master_produk.php" class="<?= $active === 'master' ? 'active' : '' ?>" style="display:flex; justify-content:space-between; align-items:center;">
      <span>⚙️ Master Data</span>
      <?php if ($jumlahStockBelumSync > 0): ?>
        <span style="background:var(--danger); color:#fff; font-size:10.5px; font-weight:700; border-radius:999px; padding:1px 7px;"><?= $jumlahStockBelumSync ?></span>
      <?php endif; ?>
    </a>
  </nav>
  <div class="sidebar-foot">
    <button type="button" class="theme-toggle" onclick="toggleTheme()">
      <span class="theme-icon-light">🌙 Mode Gelap</span>
      <span class="theme-icon-dark">☀️ Mode Terang</span>
    </button>
    <?php if ($me): ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; background:var(--sidebar-soft); border-radius:9px; margin-bottom:8px;">
        <div>
          <div style="color:#fff; font-weight:700; font-size:12.5px;"><?= h($me['nama']) ?></div>
          <div style="font-size:10.5px;"><?= h($me['role']) ?></div>
        </div>
        <a href="logout.php" title="Keluar" style="color:#fff; font-size:16px;">⏻</a>
      </div>
    <?php endif; ?>
    Brother Needs &copy; <?= date('Y') ?>
  </div>
</aside>