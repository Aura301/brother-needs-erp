<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'riwayat';
$page_title = 'Konfirmasi Lunas';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) { redirect('riwayat.php'); }

$error = '';

if ($order['status'] === 'Lunas') {
    // udah lunas duluan, gak perlu proses lagi
    redirect('invoice.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $catatan = trim($_POST['catatan'] ?? '');

    if (empty($_FILES['bukti']['name'])) {
        $error = 'Upload bukti transfer dulu ya, wajib.';
    } else {
        $file = $_FILES['bukti'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload gagal, coba lagi.';
        } elseif (!in_array($ext, $allowedExt, true)) {
            $error = 'Format file harus JPG, PNG, WEBP, atau PDF.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Ukuran file maksimal 5MB.';
        } else {
            $uploadDir = __DIR__ . '/assets/uploads/bukti_bayar/';
            $filename = 'bukti-' . $order['invoice_no'] . '-' . time() . '.' . $ext;
            $filename = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $filename);

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $userId = current_user()['id'] ?? null;

                // Guard anti-dobel: UPDATE cuma jalan kalau masih 'Belum Bayar'.
                // Kalau 2 request masuk barengan / tab lama, yang kedua rowCount = 0 -> stop, jurnal gak dobel.
                $upd = $pdo->prepare("UPDATE orders SET status='Lunas', bukti_bayar=?, lunas_by=?, lunas_at=NOW() WHERE id=? AND status='Belum Bayar'");
                $upd->execute([$filename, $userId, $id]);
                if ($upd->rowCount() === 0) {
                    @unlink($uploadDir . $filename);
                    redirect('invoice.php?id=' . $id);
                }
                // Guard kedua: kalau jurnal pelunasan order ini sudah ada, jangan posting lagi
                $cek = $pdo->prepare("SELECT COUNT(*) FROM jurnal_umum WHERE sumber='Penjualan' AND ref_id = ?");
                $cek->execute([-$id]);
                if ((int)$cek->fetchColumn() > 0) {
                    redirect('invoice.php?id=' . $id);
                }

                $ket = 'Pelunasan ' . $order['invoice_no'] . ' - ' . $order['sppg_nama'] . ($catatan ? ' (' . $catatan . ')' : '');
                post_jurnal($pdo, date('Y-m-d'), $ket, [
                    ['akun_id' => akun_id($pdo, '1-101'), 'debit' => $order['total'], 'kredit' => 0, 'keterangan' => $ket],
                    ['akun_id' => akun_id($pdo, '1-110'), 'debit' => 0, 'kredit' => $order['total'], 'keterangan' => $ket],
                ], 'Penjualan', -$id, $userId);

                redirect('invoice.php?id=' . $id . '&lunas_confirmed=1');
            } else {
                $error = 'Gagal simpan file, coba lagi.';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Konfirmasi Pembayaran Lunas</div>
    <div class="page-sub">Wajib upload bukti transfer biar gak asal tandai lunas.</div>
  </div>
  <a href="invoice.php?id=<?= $id ?>" class="btn btn-ghost">← Kembali</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-pad" style="max-width:480px;">
  <div style="background:var(--bg); border-radius:10px; padding:14px; margin-bottom:16px;">
    <div style="font-size:11px; color:var(--muted); text-transform:uppercase; font-weight:700;">Invoice</div>
    <div style="font-weight:700; font-family:var(--font-mono); margin-bottom:6px;"><?= h($order['invoice_no']) ?></div>
    <div style="font-size:13px;"><?= h($order['sppg_nama']) ?></div>
    <div style="font-family:var(--font-mono); font-weight:700; font-size:16px; margin-top:4px;"><?= rupiah($order['total']) ?></div>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-row">
      <label class="field-label">Bukti Transfer (wajib)</label>
      <input type="file" name="bukti" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
      <div style="font-size:11.5px; color:var(--muted); margin-top:4px;">Format JPG/PNG/WEBP/PDF, maks 5MB.</div>
    </div>
    <div class="form-row">
      <label class="field-label">Catatan (opsional)</label>
      <input type="text" name="catatan" placeholder="misal: transfer via BCA a.n Budi">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">✓ Konfirmasi Lunas</button>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>