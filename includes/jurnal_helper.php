<?php
/**
 * Cari id akun berdasarkan kode (kode dari database/upgrade_v2.sql).
 * Di-cache statis biar gak query berkali-kali dalam satu request.
 */
/** Ambil id akun berdasarkan kode. */
function akun_id($pdo, $kode) {
    static $cache = [];
    if (isset($cache[$kode])) return $cache[$kode];
    $stmt = $pdo->prepare("SELECT id FROM akun WHERE kode = ?");
    $stmt->execute([$kode]);
    $row = $stmt->fetch();
    $cache[$kode] = $row ? (int)$row['id'] : null;
    return $cache[$kode];
}

/**
 * Posting satu entri jurnal umum (bisa multi baris debit/kredit).
 * $lines = [ ['akun_id'=>X, 'debit'=>0, 'kredit'=>0, 'keterangan'=>''], ... ]
 * Baris dengan debit & kredit 0 otomatis dilewati.
 * Return: id jurnal_umum yang baru dibuat, atau null kalau gak ada baris valid / gak balance.
 */
function post_jurnal($pdo, $tanggal, $keterangan, $lines, $sumber = 'Manual', $ref_id = null, $created_by = null, $no_bukti = null) {
    $validLines = array_filter($lines, fn($l) => ((float)$l['debit'] > 0 || (float)$l['kredit'] > 0));
    if (empty($validLines)) return null;

    // Validasi akun_id - kalau ada baris yang kodenya gak ketemu di tabel akun (akun_id null),
    // JANGAN lanjut insert sama sekali. Dulu ini kelewat, jadi kalau kode akun salah ketik/belum
    // ada, baris pertama tetep coba di-insert, gagal kena constraint database, tapi baris
    // transaksi utamanya (expenses/stock_susut/dll) di luar sini kadung kesimpen duluan -
    // hasilnya: pengeluaran tercatat di modulnya, tapi gak pernah masuk jurnal -> Neraca jadi
    // gak nyambung sama laporan lain walau tiap entri jurnal yang KEBENTUK selalu balance.
    foreach ($validLines as $l) {
        if (empty($l['akun_id'])) {
            throw new Exception('Kode akun tidak ditemukan di Chart of Accounts (akun_id kosong). Jurnal dibatalkan, gak ada yang disimpan: ' . $keterangan);
        }
    }

    $totalDebit = array_sum(array_column($validLines, 'debit'));
    $totalKredit = array_sum(array_column($validLines, 'kredit'));
    if (abs(round($totalDebit - $totalKredit, 2)) > 0.01) {
        return null; // jurnal harus balance, kalau tidak, jangan diposting
    }

    if (!$no_bukti) {
        $no_bukti = 'JU-' . date('Ymd', strtotime($tanggal)) . '-' . substr(uniqid(), -5);
    }

    $stmt = $pdo->prepare("INSERT INTO jurnal_umum (tanggal, no_bukti, keterangan, sumber, ref_id, created_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$tanggal, $no_bukti, $keterangan, $sumber, $ref_id, $created_by]);
    $jurnalId = $pdo->lastInsertId();

    $stmtDetail = $pdo->prepare("INSERT INTO jurnal_detail (jurnal_id, akun_id, debit, kredit, keterangan) VALUES (?,?,?,?,?)");
    foreach ($validLines as $l) {
        $stmtDetail->execute([$jurnalId, $l['akun_id'], $l['debit'], $l['kredit'], $l['keterangan'] ?? '']);
    }

    return $jurnalId;
}

/**
 * Akun lawan (sisi Kredit) buat jurnal Pembelian berdasarkan metode bayarnya:
 * Tunai -> Kas (1-101), Kas Kecil -> Kas Kecil (1-102, belanja pake kas bon yang dipegang
 * petugas belanja), selain itu (Hutang) -> Hutang Usaha (2-101). Dipusatin di sini biar 3 tempat yang
 * posting jurnal Pembelian (Catat, Edit, Lengkapi Stock) gak bisa beda-beda aturannya.
 */
function akun_pembelian_lawan($metode) {
    if ($metode === 'Tunai') return '1-101';
    if ($metode === 'Kas Kecil') return '1-102';
    return '2-101';
}

/** Hapus semua jurnal otomatis yang terkait 1 transaksi sumber (dipanggil saat transaksi asal dihapus/diubah) */
function delete_jurnal_by_ref($pdo, $sumber, $ref_id) {
    $stmt = $pdo->prepare("DELETE FROM jurnal_umum WHERE sumber = ? AND ref_id = ?");
    $stmt->execute([$sumber, $ref_id]);
}

/**
 * Hitung saldo 1 akun sampai tanggal tertentu (buat Buku Besar, Neraca Saldo, Neraca).
 * Hasilnya udah disesuaikan sama saldo normal akun itu (Debit atau Kredit),
 * jadi angka positif = saldo wajar, negatif = kebalikan dari wajar (perlu dicek).
 */
function get_saldo_akun($pdo, $akun_id, $saldo_normal, $sampai_tanggal, $dari_tanggal = null) {
    $sql = "SELECT COALESCE(SUM(jd.debit),0) d, COALESCE(SUM(jd.kredit),0) k
            FROM jurnal_detail jd JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
            WHERE jd.akun_id = ? AND ju.tanggal <= ?";
    $params = [$akun_id, $sampai_tanggal];
    if ($dari_tanggal) { $sql .= " AND ju.tanggal >= ?"; $params[] = $dari_tanggal; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $saldo_normal === 'Debit' ? ((float)$row['d'] - (float)$row['k']) : ((float)$row['k'] - (float)$row['d']);
}

/** Total laba/rugi (Pendapatan - HPP - Beban) dari awal sampai tanggal tertentu - buat bagian Modal di Neraca. */
function get_laba_berjalan($pdo, $sampai_tanggal) {
    $stmt = $pdo->prepare("
        SELECT a.tipe, COALESCE(SUM(jd.debit),0) d, COALESCE(SUM(jd.kredit),0) k
        FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        WHERE a.tipe IN ('Pendapatan','HPP','Beban') AND ju.tanggal <= ?
        GROUP BY a.tipe
    ");
    $stmt->execute([$sampai_tanggal]);
    $pendapatan = 0; $hpp = 0; $beban = 0;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['tipe'] === 'Pendapatan') $pendapatan = $r['k'] - $r['d'];
        if ($r['tipe'] === 'HPP') $hpp = $r['d'] - $r['k'];
        if ($r['tipe'] === 'Beban') $beban = $r['d'] - $r['k'];
    }
    return $pendapatan - $hpp - $beban;
}