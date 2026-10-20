<?php
/* Helper menu Invoice Selisih (Selisih Order Tambahan).
   Cuma DOKUMEN perbandingan (Qty Awal vs Qty Setelah Tambahan) buat dikirim ke SPPG dalam bentuk Excel.
   TIDAK ngubah invoice asli, TIDAK masuk jurnal / piutang / stock. */

function ensure_selisih_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS selisih_dok (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sppg_id INT NULL,
            sppg_nama VARCHAR(150) NOT NULL,
            tanggal DATE NOT NULL,
            ket_header VARCHAR(400) NULL,
            catatan TEXT NULL,
            total_selisih DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS selisih_dok_invoice (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dok_id INT NOT NULL,
            order_id INT NULL,
            invoice_no VARCHAR(50) NOT NULL,
            jenis VARCHAR(20) NOT NULL DEFAULT 'utama',
            label VARCHAR(80) NOT NULL,
            total_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
            urut INT NOT NULL DEFAULT 0,
            KEY idx_dok (dok_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS selisih_dok_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dok_id INT NOT NULL,
            inv_id INT NOT NULL,
            produk_id INT NULL,
            nama VARCHAR(150) NOT NULL,
            satuan VARCHAR(30) NOT NULL,
            harga DECIMAL(14,2) NOT NULL DEFAULT 0,
            qty_awal DECIMAL(12,2) NOT NULL DEFAULT 0,
            qty_setelah DECIMAL(12,2) NOT NULL DEFAULT 0,
            urut INT NOT NULL DEFAULT 0,
            KEY idx_dok (dok_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // diam - kalau gagal, halaman nampilin error query biasa
    }
}

/** Label bagian default per jenis invoice (bisa diedit di form). */
function selisih_label_default($jenis) {
    return ['utama' => 'SAYUR & BAHAN', 'primova_buah' => 'BUAH', 'frozen' => 'FROZEN'][$jenis] ?? 'ITEM';
}

/** Ambil dokumen selisih lengkap: ['dok'=>..., 'invoices'=>[ [inv + 'items'=>[...]] ]]. */
function selisih_load($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM selisih_dok WHERE id = ?");
    $st->execute([$id]);
    $dok = $st->fetch();
    if (!$dok) return null;
    $st = $pdo->prepare("SELECT * FROM selisih_dok_invoice WHERE dok_id = ? ORDER BY urut ASC, id ASC");
    $st->execute([$id]);
    $invs = $st->fetchAll();
    $st = $pdo->prepare("SELECT * FROM selisih_dok_item WHERE dok_id = ? ORDER BY urut ASC, id ASC");
    $st->execute([$id]);
    $items = $st->fetchAll();
    foreach ($invs as &$inv) {
        $inv['items'] = array_values(array_filter($items, fn($i) => (int)$i['inv_id'] === (int)$inv['id']));
    }
    unset($inv);
    return ['dok' => $dok, 'invoices' => $invs];
}

/** Keterangan baris, sama kayak rumus di Excel. */
function selisih_keterangan($awal, $setelah) {
    if ((float)$awal == 0) return 'Barang baru';
    $s = round($setelah - $awal, 4);
    if ($s == 0) return 'Tetap';
    return $s > 0 ? 'Ditambah' : 'Dikurangi';
}

/** Tanggal pendek Indonesia: 5 Okt 2026 */
function selisih_tgl($tanggal) {
    $b = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($tanggal);
    return date('j', $ts) . ' ' . $b[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
