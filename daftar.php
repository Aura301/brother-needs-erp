<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifikasi.php';

$company = get_company($pdo);
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) {
        $success = true;
    } else {
        $namaUsaha = trim($_POST['nama_usaha'] ?? '');
        $namaPic = trim($_POST['nama_pic'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $kebutuhan = trim($_POST['kebutuhan'] ?? '');

        if ($namaUsaha === '' || $namaPic === '' || $whatsapp === '') {
            $error = 'Nama usaha, nama PIC, dan WhatsApp wajib diisi ya.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO pendaftaran (nama_usaha, nama_pic, whatsapp, alamat, kebutuhan) VALUES (?,?,?,?,?)");
            $stmt->execute([$namaUsaha, $namaPic, $whatsapp, $alamat, $kebutuhan]);
            notify_pendaftaran_baru($pdo, $namaUsaha, $namaPic, $whatsapp);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($company['brand_name']) ?> - <?= h($company['legal_name']) ?></title>
<meta name="description" content="<?= h(mb_substr($company['deskripsi'], 0, 155)) ?>">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .lp-eyebrow { color: var(--primary); font-weight: 700; font-size: 12.5px; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 8px; }
  .lp-section { max-width: 1020px; margin: 0 auto; padding: 60px 20px; }
  .lp-section.center { text-align: center; }
  .lp-title { font-family: var(--font-display); font-size: 28px; font-weight: 700; margin-bottom: 14px; }
  .lp-sub { color: var(--muted); font-size: 14.5px; max-width: 560px; margin: 0 auto 32px; line-height: 1.6; }
  .lp-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
  .lp-product-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; text-align: left; }
  .lp-product-card .photo { width: 100%; height: 170px; object-fit: cover; display: block; }
  .lp-product-card .body { padding: 20px 20px 22px; }
  .lp-product-card .title { font-weight: 700; font-size: 16px; margin-bottom: 8px; }
  .lp-product-card .desc { font-size: 13px; color: var(--muted); line-height: 1.6; margin-bottom: 14px; }
  .lp-product-card a { color: var(--primary); font-weight: 700; font-size: 12.5px; }
  .lp-value-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px 18px; text-align: center; }
  .lp-value-card .icon { font-size: 30px; margin-bottom: 10px; }
  .lp-value-card .title { font-weight: 700; font-size: 14px; margin-bottom: 6px; }
  .lp-value-card .desc { font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .lp-cta-btn { display: inline-block; background: var(--lime); color: var(--sidebar); font-weight: 700; padding: 13px 30px; border-radius: 999px; margin-top: 24px; font-size: 14px; }
  .lp-footer-col h5 { font-size: 13px; font-weight: 700; margin-bottom: 14px; color: #fff; }
  .lp-footer-col a, .lp-footer-col li { color: var(--sidebar-text); font-size: 12.5px; line-height: 2; list-style: none; }

  .lp-hero-grid { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 24px; align-items: center; max-width: 1020px; margin: 0 auto; text-align: left; }
  .lp-hero-photo { border-radius: 20px; overflow: hidden; }
  .lp-hero-photo img { width: 100%; height: 100%; object-fit: cover; display: block; max-height: 400px; }
  .lp-badge-fresh {
    display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.1);
    border: 1px solid rgba(175,208,65,0.5); color: var(--lime); border-radius: 999px;
    padding: 6px 14px; font-size: 11.5px; font-weight: 700; margin-bottom: 16px;
  }
  .lp-navbar { display: flex; align-items: center; justify-content: space-between; gap: 24px; max-width: 1100px; margin: 0 auto; padding: 18px 20px; position: relative; z-index: 2; }
  .lp-navbar img { height: 34px; background: #fff; padding: 5px 10px; border-radius: 8px; margin-right: 24px; }
  .lp-navbar-links { display: flex; align-items: center; gap: 26px; }
  .lp-navbar-links a { color: #fff; font-size: 13.5px; font-weight: 600; }
  .lp-navbar-links .lp-nav-cta { background: var(--lime); color: var(--sidebar); padding: 9px 18px; border-radius: 999px; font-weight: 700; }
  .lp-hero-full {
    position: relative; min-height: 560px; display: flex; flex-direction: column;
    background-image: linear-gradient(100deg, rgba(6,20,3,0.88) 0%, rgba(6,20,3,0.55) 45%, rgba(6,20,3,0.2) 75%), url('assets/img/landing/veg_basket.jpg');
    background-size: cover; background-position: center;
  }
  .lp-hero-full-content { max-width: 1020px; margin: 0 auto; padding: 60px 20px 70px; flex: 1; display: flex; align-items: center; width: 100%; }
  .lp-outline-btn {
    display: inline-block; border: 2px solid #fff; color: #fff; font-weight: 700;
    padding: 11px 28px; border-radius: 999px; margin-top: 24px; font-size: 14px;
  }
  .lp-outline-btn:hover { background: #fff; color: var(--sidebar); }

  @media (max-width: 760px) {
    .lp-grid-3 { grid-template-columns: 1fr; }
    .lp-navbar-links a:not(.lp-nav-cta) { display: none; }
    .lp-hero-full { min-height: 480px; }
    .lp-hero-full-content { padding: 40px 20px 50px; }
    .lp-tentang-grid { grid-template-columns: 1fr !important; text-align: center; }
    .lp-footer-grid { grid-template-columns: 1fr !important; text-align: center; gap: 28px !important; }
    .lp-footer-col ul { display: flex; flex-direction: column; align-items: center; }
  }

  @media (max-width: 420px) {
    .lp-navbar img { margin-right: 10px; }
    .lp-nav-cta { padding: 8px 14px !important; font-size: 12.5px !important; }
    .lp-title { font-size: 22px; }
  }
</style>
</head>
<body style="background:var(--bg);">

<!-- HERO -->
<div class="lp-hero-full">
  <div class="lp-navbar">
    <img src="assets/img/logo.png" alt="<?= h($company['brand_name']) ?>">
    <div class="lp-navbar-links">
      <a href="#tentang">Tentang</a>
      <a href="#produk">Produk</a>
      <a href="#layanan">Layanan</a>
      <a href="#daftar" class="lp-nav-cta">Daftar Sekarang</a>
    </div>
  </div>
  <div class="lp-hero-full-content">
    <div style="color:#fff; max-width:560px;">
      <div class="lp-badge-fresh">✓ 100% Fresh Guarantee</div>
      <div style="font-size:12.5px; letter-spacing:.1em; text-transform:uppercase; color:var(--lime); font-weight:700; margin-bottom:10px;"><?= h($company['legal_name']) ?></div>
      <h1 style="font-family:var(--font-display); font-size:clamp(28px,4.2vw,42px); line-height:1.2; margin:0 0 16px;">
        Pasokan Buah, Sayur &amp; Bahan Dapur Segar untuk Dapur Anda
      </h1>
      <p style="color:rgba(255,255,255,0.85); font-size:15px; line-height:1.6; max-width:440px;">
        <?= h($company['tagline']) ?>
      </p>
      <a href="#daftar" class="lp-outline-btn">Berlangganan Sekarang →</a>
    </div>
  </div>
</div>

<!-- KATEGORI PRODUK -->
<div class="lp-section center" id="produk" style="margin-top:-56px;">
  <div class="lp-grid-3">
    <div class="lp-product-card">
      <img src="assets/img/landing/fruit_card.jpg" alt="Buah segar" class="photo">
      <div class="body">
        <div class="title">🍎 Buah Segar</div>
        <div class="desc">Aneka buah pilihan, segar sampai ke dapur — dari lokal sampai import, tersedia sesuai musim.</div>
        <a href="#daftar">Berlangganan →</a>
      </div>
    </div>
    <div class="lp-product-card">
      <img src="assets/img/landing/veg_card.jpg" alt="Sayur segar" class="photo">
      <div class="body">
        <div class="title">🥬 Sayur &amp; Bahan Dapur</div>
        <div class="desc">Sayur segar, bumbu, dan bahan baku dapur lengkap buat kebutuhan masak harian dalam jumlah besar.</div>
        <a href="#daftar">Berlangganan →</a>
      </div>
    </div>
    <div class="lp-product-card">
      <img src="assets/img/landing/meat_card.jpg" alt="Daging & frozen food" class="photo">
      <div class="body">
        <div class="title">🧊 Daging &amp; Frozen Food</div>
        <div class="desc">Daging, frozen vegetables, dan seafood — kualitas terjaga, siap simpan lebih lama.</div>
        <a href="#daftar">Berlangganan →</a>
      </div>
    </div>
  </div>
</div>

<!-- TENTANG KAMI -->
<div class="lp-section" id="tentang">
  <div class="lp-tentang-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:40px; align-items:center;">
    <div>
      <div class="lp-eyebrow"><?= h($company['brand_name']) ?></div>
      <div class="lp-title">Tentang Kami</div>
      <div style="font-size:14px; line-height:1.8; color:var(--text); white-space:pre-line; margin-bottom:16px;"><?= h($company['deskripsi']) ?></div>
      <div style="color:var(--ok); font-weight:700; font-size:13px;">✓ Good Quality · Best Product · On Time Guarantee</div>
    </div>
    <div style="border-radius:18px; overflow:hidden; box-shadow:0 12px 28px rgba(0,0,0,0.12);">
      <img src="assets/img/landing/hero_woman.jpg" alt="Tim <?= h($company['brand_name']) ?>" style="width:100%; display:block;">
    </div>
  </div>
</div>

<!-- KENAPA PILIH KAMI -->
<div class="lp-section center" id="layanan" style="background:var(--surface);">
  <div class="lp-eyebrow">Layanan Kami</div>
  <div class="lp-title">Kenapa Pilih Kami</div>
  <div class="lp-sub">Tim kami siap mendukung kebutuhan supply harian dapur kamu, sebesar apapun skalanya.</div>
  <div class="lp-grid-3">
    <div class="lp-value-card">
      <div class="icon">✅</div>
      <div class="title">Kualitas Terjamin</div>
      <div class="desc">Produk segar pilihan, disortir sebelum dikirim ke dapur kamu.</div>
    </div>
    <div class="lp-value-card">
      <div class="icon">⏱️</div>
      <div class="title">Tepat Waktu</div>
      <div class="desc">Pengiriman terjadwal, gak bikin operasional dapur kamu terhambat.</div>
    </div>
    <div class="lp-value-card">
      <div class="icon">💰</div>
      <div class="title">Harga Bersaing</div>
      <div class="desc">Harga wajar &amp; transparan, cocok buat kebutuhan skala dapur besar.</div>
    </div>
  </div>
</div>

<!-- FORM DAFTAR -->
<div class="lp-section" id="daftar">
  <div class="card card-pad" style="max-width:560px; margin:0 auto; box-shadow:0 8px 24px rgba(0,0,0,0.1);">

    <?php if ($success): ?>
      <div style="text-align:center; padding:20px 0;">
        <div style="font-size:44px; margin-bottom:12px;">✅</div>
        <div style="font-weight:700; font-size:18px; margin-bottom:8px;">Pendaftaran Berhasil Dikirim!</div>
        <div style="color:var(--muted); font-size:13.5px; line-height:1.6;">
          Terima kasih udah daftar. Tim kami bakal segera menghubungi kamu lewat WhatsApp
          buat proses selanjutnya.
        </div>
        <a href="daftar.php" class="btn btn-subtle" style="margin-top:16px;">← Kembali</a>
      </div>
    <?php else: ?>

      <div style="margin-bottom:20px; text-align:center;">
        <div class="lp-eyebrow" style="justify-content:center;">Mulai Berlangganan</div>
        <div style="font-weight:700; font-size:20px; margin-bottom:6px;">Daftar Jadi Mitra Kami</div>
        <div style="color:var(--muted); font-size:13.5px;">
          Mau order buah, sayur, frozen food, atau bahan baku dapur dari kami? Isi form di bawah,
          tim kami akan segera menghubungi kamu.
        </div>
      </div>

      <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

      <form method="POST">
        <div style="position:absolute; left:-9999px;" aria-hidden="true">
          <label>Website</label>
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-row">
          <label class="field-label">Nama Usaha / Dapur *</label>
          <input type="text" name="nama_usaha" placeholder="Nama usaha / dapur kamu" required>
        </div>
        <div class="form-row grid-2">
          <div><label class="field-label">Nama PIC *</label><input type="text" name="nama_pic" placeholder="Nama kamu" required></div>
          <div><label class="field-label">No. WhatsApp *</label><input type="text" name="whatsapp" placeholder="08xxxxxxxxxx" required></div>
        </div>
        <div class="form-row">
          <label class="field-label">Alamat</label>
          <input type="text" name="alamat" placeholder="Alamat lengkap">
        </div>
        <div class="form-row">
          <label class="field-label">Kebutuhan (opsional)</label>
          <textarea name="kebutuhan" rows="3" placeholder="Ceritain kebutuhan kamu, misal: butuh supply buah & sayur harian buat 200 porsi"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px 0;">Kirim Pendaftaran</button>
      </form>

    <?php endif; ?>
  </div>
</div>

<!-- FOOTER -->
<div style="background:var(--sidebar); color:#fff; padding:48px 20px 24px;">
  <div class="lp-footer-grid" style="max-width:1020px; margin:0 auto; display:grid; grid-template-columns:1.3fr 1fr 1fr; gap:32px; margin-bottom:32px;">
    <div>
      <img src="assets/img/logo.png" alt="<?= h($company['brand_name']) ?>" style="height:36px; background:#fff; padding:6px 12px; border-radius:8px; margin-bottom:14px;">
      <p style="color:var(--sidebar-text); font-size:12.5px; line-height:1.7; max-width:260px;"><?= h($company['tagline']) ?></p>
    </div>
    <div class="lp-footer-col">
      <h5>Kontak</h5>
      <ul style="padding:0;">
        <li>📍 <?= h($company['alamat']) ?></li>
        <li>💬 <?= h($company['whatsapp']) ?></li>
        <li>📷 <?= h($company['instagram']) ?></li>
        <?php if (!empty($company['notif_email'])): ?><li>✉️ <?= h($company['notif_email']) ?></li><?php endif; ?>
      </ul>
    </div>
    <div class="lp-footer-col">
      <h5>Produk Kami</h5>
      <ul style="padding:0;">
        <li>Buah Segar</li>
        <li>Sayur &amp; Bahan Dapur</li>
        <li>Frozen Food</li>
        <li>Daging &amp; Seafood</li>
      </ul>
    </div>
  </div>
  <div style="max-width:1020px; margin:0 auto; border-top:1px solid rgba(255,255,255,0.12); padding-top:18px; font-size:11.5px; color:#5F7A64; text-align:center;">
    <?= h($company['legal_name']) ?> &copy; <?= date('Y') ?>
  </div>
</div>

</body>
</html>