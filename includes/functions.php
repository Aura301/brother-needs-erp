<?php
/** Format angka ke Rupiah, misal 15000 -> "Rp15.000" */
function rupiah($angka) {
    return 'Rp' . number_format((float)$angka, 0, ',', '.');
}

/** Format tanggal Y-m-d ke "30 Agu 2026" */
function fmt_tanggal($tanggal) {
    if (empty($tanggal)) return '-';
    $bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($tanggal);
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Generate nomor invoice baru, format BN08-26 (urut PER SPPG, reset tiap ganti tahun) */
function generate_invoice_no($pdo, $tanggal, $sppg_id, $prefix = 'BN') {
    $year = date('y', strtotime($tanggal)); // 2 digit, misal "26"
    $stmt = $pdo->prepare("SELECT invoice_no FROM orders WHERE sppg_id = ? AND invoice_no LIKE ?");
    $stmt->execute([$sppg_id, $prefix . '%-' . $year]);
    $maxUrut = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)-/', $row['invoice_no'], $m)) {
            $maxUrut = max($maxUrut, (int)$m[1]);
        }
    }
    $urut = str_pad($maxUrut + 1, 2, '0', STR_PAD_LEFT);
    return $prefix . $urut . '-' . $year;
}

/** Jenis invoice: utama (BN), Primova Buah (PV), Frozen (FZ). Tiap jenis punya seri nomor
 *  sendiri, profil usaha (kop invoice) sendiri, dan - buat Primova/Frozen - filter kategori produk. */
function invoice_jenis_config() {
    return [
        'utama' => ['label' => 'Invoice Utama', 'short' => 'Utama', 'prefix' => 'BN', 'company_id' => 1, 'kategori' => null, 'icon' => '🧾'],
        'primova_buah' => ['label' => 'Invoice Primofa Buah', 'short' => 'Primofa Buah', 'prefix' => 'PV', 'company_id' => 2, 'kategori' => ['Buah'], 'icon' => '🍊'],
        'frozen' => ['label' => 'Invoice Frozen', 'short' => 'Frozen', 'prefix' => 'FZ', 'company_id' => 3, 'kategori' => ['Frozen', 'Daging & Seafood'], 'icon' => '🧊'],
    ];
}

function normalize_jenis($jenis) {
    $cfg = invoice_jenis_config();
    return isset($cfg[$jenis]) ? $jenis : 'utama';
}

/** Pastiin kolom `jenis` di orders & order_drafts dan profil usaha Primova/Frozen udah ada.
 *  Aman dipanggil berkali-kali (cuma ngubah sekali, sisanya ngecek doang). */
function ensure_invoice_jenis_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        foreach (['orders', 'order_drafts'] as $t) {
            $has = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'jenis'")->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN `jenis` VARCHAR(20) NOT NULL DEFAULT 'utama'");
            }
        }
        $ids = $pdo->query("SELECT id FROM company_profile")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array(1, $ids)) {
            if (!in_array(2, $ids)) {
                $pdo->exec("INSERT INTO company_profile (id, brand_name, legal_name, alamat, whatsapp, instagram, bank_name, account_name, account_number, signer_name, tagline, petty_cash_budget, notif_email, ntfy_topic, deskripsi)
                            SELECT 2, 'Primofa', 'PRIMOFA BUAH', alamat, whatsapp, instagram, '', 'Nama Pemilik Demo', '1234567890', signer_name, 'Supplier Buah Lokal & Import', 0, '', '', deskripsi FROM company_profile WHERE id = 1");
            }
            if (!in_array(3, $ids)) {
                $pdo->exec("INSERT INTO company_profile (id, brand_name, legal_name, alamat, whatsapp, instagram, bank_name, account_name, account_number, signer_name, tagline, petty_cash_budget, notif_email, ntfy_topic, deskripsi)
                            SELECT 3, 'Frozen', 'FROZEN', alamat, whatsapp, instagram, bank_name, account_name, account_number, signer_name, 'Frozen Food, Daging & Seafood', 0, '', '', deskripsi FROM company_profile WHERE id = 1");
            }
        }
    } catch (Exception $e) {
        // diam aja - kalau gagal, jenis invoice cuma jatuh ke 'utama'
    }
}
/** Ambil 1 baris profil usaha (untuk kop invoice, dsb) */
function get_company($pdo, $id = 1) {
    $stmt = $pdo->prepare("SELECT * FROM company_profile WHERE id = ?");
    $stmt->execute([(int)$id]);
    $company = $stmt->fetch();
    if (!$company && (int)$id !== 1) {
        $stmt = $pdo->query("SELECT * FROM company_profile WHERE id = 1");
        $company = $stmt->fetch();
    }
    if (!$company) {
        // fallback kalau baris profil belum ada
        $company = [
            'brand_name' => 'Brother Needs', 'legal_name' => 'UD CONTOH SEJAHTERA', 'alamat' => '',
            'whatsapp' => '', 'instagram' => '', 'bank_name' => '', 'account_name' => '',
            'account_number' => '', 'signer_name' => '', 'tagline' => '', 'petty_cash_budget' => 0,
            'notif_email' => '', 'ntfy_topic' => '', 'deskripsi' => '',
        ];
    }
    return $company;
}

/** Bersihin input angka dari user (hapus titik ribuan, dll) */
function clean_number($val) {
    return (float)str_replace([',', ' '], ['.', ''], $val);
}

/** Redirect helper */
function redirect($url) {
    header("Location: $url");
    exit;
}

/** Escape output HTML singkat */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Catet 1 baris riwayat perubahan stock (masuk/keluar) ke tabel stock_log.
 * Dipanggil SETELAH stock_fisik beneran keubah di DB, dengan nilai $sebelum
 * & $sesudah yang udah pasti (bukan dihitung ulang manual) - biar otomatis
 * akurat walaupun ada pembulatan GREATEST(0, ...) dkk.
 *
 * $refTipe dipakai buat nandain transaksi ini asalnya dari mana (mis.
 * 'Penjualan', 'Pembelian', 'Stock Edit', 'Susut Barang', dst) ditambah
 * $refId (id baris sumbernya) - biar bisa ditelusuri baliknya kalau perlu.
 */
function log_stock($pdo, $stockId, $nama, $satuan, $sebelum, $sesudah, $keterangan, $refTipe = null, $refId = null, $userId = null, $tanggal = null) {
    $sebelum = round((float)$sebelum, 2);
    $sesudah = round((float)$sesudah, 2);
    $perubahan = round($sesudah - $sebelum, 2);
    if (abs($perubahan) < 0.001) return; // gak ada perubahan qty beneran, gak usah dicatet

    $stmt = $pdo->prepare("INSERT INTO stock_log (stock_id, nama, satuan, tanggal, perubahan, stock_sebelum, stock_sesudah, keterangan, ref_tipe, ref_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$stockId ?: null, $nama, $satuan, $tanggal ?: date('Y-m-d'), $perubahan, $sebelum, $sesudah, $keterangan, $refTipe, $refId, $userId]);
}