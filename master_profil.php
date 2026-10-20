<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$active = 'master';
$page_title = 'Profil Usaha';

ensure_invoice_jenis_schema($pdo);
$profilId = (int)($_POST['profil_id'] ?? $_GET['profil'] ?? 1);
if (!in_array($profilId, [1, 2, 3], true)) $profilId = 1;
$profilTabs = [1 => 'Brother Needs (Utama)', 2 => 'Primova Buah', 3 => 'Frozen'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE company_profile SET brand_name=?, legal_name=?, alamat=?, whatsapp=?, instagram=?, bank_name=?, account_name=?, account_number=?, signer_name=?, notif_email=?, ntfy_topic=?, deskripsi=? WHERE id = ?");
    $stmt->execute([
        trim($_POST['brand_name']), trim($_POST['legal_name']), trim($_POST['alamat']), trim($_POST['whatsapp']), trim($_POST['instagram']),
        trim($_POST['bank_name']), trim($_POST['account_name']), trim($_POST['account_number']), trim($_POST['signer_name']),
        trim($_POST['notif_email']), trim($_POST['ntfy_topic']), trim($_POST['deskripsi']), $profilId,
    ]);
    redirect('master_profil.php?profil=' . $profilId . '&saved=1');
}

$company = get_company($pdo, $profilId);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Master Data</div>
    <div class="page-sub">Kelola daftar produk, harga, SPPG, dan profil usaha.</div>
  </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px;">
  <a href="master_produk.php" class="btn btn-subtle">Produk &amp; Harga</a>
  <a href="master_profil.php" class="btn btn-primary">Profil Usaha &amp; Invoice</a>
  <a href="master_users.php" class="btn btn-subtle">Kelola User</a>
</div>

<div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
  <?php foreach ($profilTabs as $pid => $plabel): ?>
    <a href="master_profil.php?profil=<?= $pid ?>" class="btn btn-sm <?= $profilId === $pid ? 'btn-primary' : 'btn-subtle' ?>"><?= h($plabel) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($profilId !== 1): ?>
  <div class="alert alert-info">Ini kop invoice buat <b><?= h($profilTabs[$profilId]) ?></b> (menu invoice terpisah). Isi nama usaha, alamat, dan rekening yang mau tampil di invoice itu. Logo dan tanda tangan khusus bisa ditaruh sebagai <code>assets/img/logo_<?= $profilId === 2 ? 'primova_buah' : 'frozen' ?>.png</code> dan <code>assets/img/ttd_<?= $profilId === 2 ? 'primova_buah' : 'frozen' ?>.png</code>. Kalau nggak ada, dipakai logo dan tanda tangan utama.</div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-ok">Tersimpan. Perubahan langsung kepakai di invoice berikutnya.</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:4px;">Data yang tampil di Nota / Invoice</div>
    <div style="font-size:12px; color:var(--muted); margin-bottom:16px;">Semua invoice yang dibuat akan otomatis pakai data ini.</div>

    <form method="POST">
      <input type="hidden" name="profil_id" value="<?= $profilId ?>">
      <input type="hidden" name="notif_email" value="<?= h($company['notif_email']) ?>">
      <input type="hidden" name="ntfy_topic" value="<?= h($company['ntfy_topic']) ?>">
      <div class="form-row">
        <label class="field-label">Nama Usaha (Legal)</label>
        <input type="text" name="legal_name" value="<?= h($company['legal_name']) ?>">
      </div>
      <div class="form-row">
        <label class="field-label">Nama Brand</label>
        <input type="text" name="brand_name" value="<?= h($company['brand_name']) ?>">
      </div>
      <div class="form-row">
        <label class="field-label">Alamat Usaha</label>
        <input type="text" name="alamat" value="<?= h($company['alamat']) ?>" placeholder="Muncul di header invoice">
      </div>
      <div class="form-row">
        <label class="field-label">Deskripsi Usaha (buat halaman profil publik)</label>
        <textarea name="deskripsi" rows="4" placeholder="Ceritain tentang usaha kamu, komitmen, dll - ini muncul di halaman daftar.php"><?= h($company['deskripsi']) ?></textarea>
      </div>
      <div class="form-row grid-2">
        <div><label class="field-label">WhatsApp</label><input type="text" name="whatsapp" value="<?= h($company['whatsapp']) ?>"></div>
        <div><label class="field-label">Instagram</label><input type="text" name="instagram" value="<?= h($company['instagram']) ?>"></div>
      </div>
      <div class="form-row grid-2">
        <div><label class="field-label">Bank</label><input type="text" name="bank_name" value="<?= h($company['bank_name']) ?>"></div>
        <div><label class="field-label">No. Rekening</label><input type="text" name="account_number" value="<?= h($company['account_number']) ?>"></div>
      </div>
      <div class="form-row">
        <label class="field-label">Nama Pemilik Rekening</label>
        <input type="text" name="account_name" value="<?= h($company['account_name']) ?>">
      </div>
      <div class="form-row">
        <label class="field-label">Nama Penanda Tangan Invoice</label>
        <input type="text" name="signer_name" value="<?= h($company['signer_name']) ?>">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Simpan Perubahan</button>
    </form>
  </div>

  <div class="card card-pad">
    <div style="font-weight:700; font-size:15px; margin-bottom:12px;">Logo Usaha</div>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:24px; display:flex; align-items:center; justify-content:center; margin-bottom:12px;">
      <img src="assets/img/logo.png" alt="Logo" style="max-width:220px; object-fit:contain;">
    </div>
    <a href="assets/img/logo.png" download="logo-brother-needs.png" class="btn btn-accent" style="width:100%; justify-content:center;">⬇ Download Logo (PNG)</a>
    <div style="font-size:12px; color:var(--muted); margin-top:10px; line-height:1.5;">
      Logo ini otomatis nempel di setiap Nota/Invoice yang dicetak. Kalau mau kirim logo terpisah ke customer (misal buat profil WA Business), tinggal download dari sini lalu kirim manual sebagai gambar.
    </div>

    <div style="font-weight:700; font-size:15px; margin:20px 0 12px;">Tanda Tangan</div>
    <div style="background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:24px; display:flex; align-items:center; justify-content:center;">
      <img src="assets/img/ttd.png" alt="Tanda tangan" style="max-width:260px; object-fit:contain;">
    </div>
    <div style="font-size:12px; color:var(--muted); margin-top:10px;">
      Untuk ganti gambar tanda tangan, replace file <code>assets/img/ttd.png</code> di folder aplikasi.
    </div>
  </div>
</div>

<?php if ($profilId === 1): ?>
<div class="card card-pad" style="margin-top:16px; max-width:600px;">
  <div style="font-weight:700; font-size:15px; margin-bottom:4px;">🔔 Notifikasi Pendaftaran Baru</div>
  <div style="font-size:12px; color:var(--muted); margin-bottom:16px;">
    Begitu ada yang isi form di halaman publik (<code>daftar.php</code>), notifikasi otomatis dikirim ke sini.
  </div>
  <form method="POST">
    <input type="hidden" name="profil_id" value="1">
    <input type="hidden" name="brand_name" value="<?= h($company['brand_name']) ?>">
    <input type="hidden" name="legal_name" value="<?= h($company['legal_name']) ?>">
    <input type="hidden" name="alamat" value="<?= h($company['alamat']) ?>">
    <input type="hidden" name="whatsapp" value="<?= h($company['whatsapp']) ?>">
    <input type="hidden" name="instagram" value="<?= h($company['instagram']) ?>">
    <input type="hidden" name="bank_name" value="<?= h($company['bank_name']) ?>">
    <input type="hidden" name="account_name" value="<?= h($company['account_name']) ?>">
    <input type="hidden" name="account_number" value="<?= h($company['account_number']) ?>">
    <input type="hidden" name="signer_name" value="<?= h($company['signer_name']) ?>">
    <input type="hidden" name="deskripsi" value="<?= h($company['deskripsi']) ?>">

    <div class="form-row">
      <label class="field-label">Email Notifikasi</label>
      <input type="text" name="notif_email" value="<?= h($company['notif_email']) ?>" placeholder="emailkamu@gmail.com">
    </div>
    <div class="form-row">
      <label class="field-label">Topic ntfy.sh (buat notif HP)</label>
      <input type="text" name="ntfy_topic" value="<?= h($company['ntfy_topic'] ?: 'brotherneeds-' . substr(md5(uniqid()), 0, 8)) ?>" placeholder="misal: brotherneeds-a1b2c3d4">
      <div style="font-size:11.5px; color:var(--muted); margin-top:4px;">
        Simpan topic ini, terus di HP: install app <b>ntfy</b> (Play Store/App Store) → buka app → tambah subscription →
        masukin topic yang sama persis kayak di atas. Setelah itu HP kamu bakal langsung dapet notif tiap ada pendaftaran baru.
        <b>Jaga kerahasiaan topic ini</b> (jangan share ke orang lain), soalnya siapa aja yang tau nama topic-nya bisa ikut lihat notifnya.
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Simpan Pengaturan Notifikasi</button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>