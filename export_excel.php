<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$type = $_GET['type'] ?? 'riwayat';
$filename = $type . '-' . date('Y-m-d') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
echo "\xEF\xBB\xBF"; // BOM biar karakter Indonesia kebaca benar di Excel

function table_open($title = null) {
    if ($title) echo '<h3>' . htmlspecialchars($title) . '</h3>';
    echo '<table border="1"><tr>';
}
function table_close() {
    echo '</table><br>';
}

if ($type === 'susut') {
    table_open();
    echo '<th>Tanggal</th><th>Nama Barang</th><th>Qty</th><th>Satuan</th><th>Alasan</th><th>Nilai Kerugian</th></tr>';
    $rows = $pdo->query("SELECT * FROM stock_susut ORDER BY tanggal ASC")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['nama']) . '</td><td>' . $r['qty'] . '</td><td>' . h($r['satuan']) . '</td><td>' . h($r['alasan']) . '</td><td>' . $r['nilai_kerugian'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'produk') {
    table_open();
    echo '<th>Kategori</th><th>Nama</th><th>Satuan</th><th>Harga Modal</th><th>Harga Jual</th><th>Margin</th></tr>';
    $rows = $pdo->query("SELECT * FROM produk ORDER BY kategori ASC, nama ASC")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['kategori']) . '</td><td>' . h($r['nama']) . '</td><td>' . h($r['satuan']) . '</td><td>' . $r['harga_modal'] . '</td><td>' . $r['harga_jual'] . '</td><td>' . ($r['harga_jual'] - $r['harga_modal']) . '</td></tr>';
    }
    table_close();

} elseif ($type === 'sppg') {
    table_open();
    echo '<th>Nama</th><th>Alamat</th><th>Kontak</th><th>Status</th></tr>';
    $rows = $pdo->query("SELECT * FROM sppg ORDER BY status ASC, nama ASC")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['nama']) . '</td><td>' . h($r['alamat']) . '</td><td>' . h($r['kontak']) . '</td><td>' . h($r['status']) . '</td></tr>';
    }
    table_close();

} elseif ($type === 'stock') {
    table_open();
    echo '<th>Nama</th><th>Satuan</th><th>Stock Fisik</th><th>Harga Modal</th><th>Harga Jual</th><th>Keuntungan/Item</th></tr>';
    $rows = $pdo->query("SELECT * FROM stock ORDER BY nama ASC")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['nama']) . '</td><td>' . h($r['satuan']) . '</td><td>' . $r['stock_fisik'] . '</td><td>' . $r['harga_modal'] . '</td><td>' . $r['harga_jual'] . '</td><td>' . ($r['harga_jual'] - $r['harga_modal']) . '</td></tr>';
    }
    table_close();

} elseif ($type === 'petty_cash') {
    table_open();
    echo '<th>Tanggal</th><th>Akun</th><th>Kategori</th><th>Catatan</th><th>Jumlah</th></tr>';
    $rows = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Petty Cash' ORDER BY tanggal ASC")->fetchAll();
    foreach ($rows as $e) {
        echo '<tr><td>' . fmt_tanggal($e['tanggal']) . '</td><td>' . h($e['akun']) . '</td><td>' . h($e['kategori']) . '</td><td>' . h($e['catatan']) . '</td><td>' . $e['jumlah'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'beban_operasional') {
    table_open();
    echo '<th>Tanggal</th><th>Akun</th><th>Kategori</th><th>Catatan</th><th>Jumlah</th></tr>';
    $rows = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Operasional' ORDER BY tanggal ASC")->fetchAll();
    foreach ($rows as $e) {
        echo '<tr><td>' . fmt_tanggal($e['tanggal']) . '</td><td>' . h($e['akun']) . '</td><td>' . h($e['kategori']) . '</td><td>' . h($e['catatan']) . '</td><td>' . $e['jumlah'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'keuangan') {
    table_open();
    echo '<th>Jenis</th><th>Tanggal</th><th>Keterangan</th><th>Jumlah</th></tr>';
    $masuk = $pdo->query("SELECT * FROM orders WHERE status='Lunas' ORDER BY tanggal ASC")->fetchAll();
    foreach ($masuk as $o) {
        echo '<tr><td>Masuk</td><td>' . fmt_tanggal($o['tanggal']) . '</td><td>' . h($o['sppg_nama'] . ' (' . $o['invoice_no'] . ')') . '</td><td>' . $o['total'] . '</td></tr>';
    }
    $keluar = $pdo->query("SELECT * FROM expenses ORDER BY tanggal ASC")->fetchAll();
    foreach ($keluar as $e) {
        echo '<tr><td>Keluar</td><td>' . fmt_tanggal($e['tanggal']) . '</td><td>' . h($e['catatan'] . ' (' . $e['kategori'] . ')') . '</td><td>-' . $e['jumlah'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'jurnal') {
    table_open();
    echo '<th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th>Sumber</th><th>Akun</th><th>Debit</th><th>Kredit</th></tr>';
    $rows = $pdo->query("
        SELECT ju.tanggal, ju.no_bukti, ju.keterangan, ju.sumber, a.kode, a.nama AS akun_nama, jd.debit, jd.kredit
        FROM jurnal_detail jd
        JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        JOIN akun a ON a.id = jd.akun_id
        ORDER BY ju.tanggal ASC, ju.id ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['no_bukti']) . '</td><td>' . h($r['keterangan']) . '</td><td>' . h($r['sumber']) . '</td><td>' . h($r['kode'] . ' - ' . $r['akun_nama']) . '</td><td>' . $r['debit'] . '</td><td>' . $r['kredit'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'pembelian') {
    table_open();
    echo '<th>No Bukti</th><th>Tanggal</th><th>Supplier</th><th>Barang</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Subtotal</th><th>Metode Bayar</th></tr>';
    $pembelians = $pdo->query("SELECT * FROM pembelian ORDER BY tanggal ASC")->fetchAll();
    $dtStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ?");
    foreach ($pembelians as $p) {
        $dtStmt->execute([$p['id']]);
        foreach ($dtStmt->fetchAll() as $i) {
            echo '<tr><td>' . h($p['no_bukti']) . '</td><td>' . fmt_tanggal($p['tanggal']) . '</td><td>' . h($p['supplier']) . '</td><td>' . h($i['nama_barang']) . '</td><td>' . $i['qty'] . '</td><td>' . h($i['satuan']) . '</td><td>' . $i['harga_satuan'] . '</td><td>' . $i['subtotal'] . '</td><td>' . h($p['metode_bayar']) . '</td></tr>';
        }
    }
    table_close();

} elseif ($type === 'labarugi') {
    $dari = $_GET['dari'] ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    table_open();
    echo '<th>Tipe</th><th>Akun</th><th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th>Debit</th><th>Kredit</th></tr>';
    $stmt = $pdo->prepare("
        SELECT a.tipe, a.kode, a.nama AS akun_nama, jd.debit, jd.kredit, jd.keterangan, ju.tanggal, ju.no_bukti
        FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        WHERE ju.tanggal BETWEEN ? AND ? AND a.tipe IN ('Pendapatan','HPP','Beban')
        ORDER BY a.kode ASC, ju.tanggal ASC
    ");
    $stmt->execute([$dari, $sampai]);
    foreach ($stmt->fetchAll() as $r) {
        echo '<tr><td>' . h($r['tipe']) . '</td><td>' . h($r['kode'] . ' - ' . $r['akun_nama']) . '</td><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['no_bukti']) . '</td><td>' . h($r['keterangan']) . '</td><td>' . $r['debit'] . '</td><td>' . $r['kredit'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'lengkap') {
    // Export gabungan semua laporan keuangan dalam 1 file (beberapa tabel berurutan)
    table_open('JURNAL UMUM');
    echo '<th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th>Sumber</th><th>Akun</th><th>Debit</th><th>Kredit</th></tr>';
    $rows = $pdo->query("
        SELECT ju.tanggal, ju.no_bukti, ju.keterangan, ju.sumber, a.kode, a.nama AS akun_nama, jd.debit, jd.kredit
        FROM jurnal_detail jd JOIN jurnal_umum ju ON ju.id = jd.jurnal_id JOIN akun a ON a.id = jd.akun_id
        ORDER BY ju.tanggal ASC, ju.id ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        echo '<tr><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['no_bukti']) . '</td><td>' . h($r['keterangan']) . '</td><td>' . h($r['sumber']) . '</td><td>' . h($r['kode'] . ' - ' . $r['akun_nama']) . '</td><td>' . $r['debit'] . '</td><td>' . $r['kredit'] . '</td></tr>';
    }
    table_close();

    table_open('PEMBELIAN');
    echo '<th>No Bukti</th><th>Tanggal</th><th>Supplier</th><th>Barang</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Subtotal</th><th>Metode Bayar</th></tr>';
    $pembelians = $pdo->query("SELECT * FROM pembelian ORDER BY tanggal ASC")->fetchAll();
    $dtStmt = $pdo->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id = ?");
    foreach ($pembelians as $p) {
        $dtStmt->execute([$p['id']]);
        foreach ($dtStmt->fetchAll() as $i) {
            echo '<tr><td>' . h($p['no_bukti']) . '</td><td>' . fmt_tanggal($p['tanggal']) . '</td><td>' . h($p['supplier']) . '</td><td>' . h($i['nama_barang']) . '</td><td>' . $i['qty'] . '</td><td>' . h($i['satuan']) . '</td><td>' . $i['harga_satuan'] . '</td><td>' . $i['subtotal'] . '</td><td>' . h($p['metode_bayar']) . '</td></tr>';
        }
    }
    table_close();

    table_open('PENJUALAN (INVOICE)');
    echo '<th>Invoice</th><th>Tanggal</th><th>SPPG</th><th>Item</th><th>Qty</th><th>Satuan</th><th>Harga Satuan</th><th>Subtotal</th><th>Margin</th><th>Status</th></tr>';
    $orders = $pdo->query("SELECT * FROM orders ORDER BY tanggal ASC")->fetchAll();
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    foreach ($orders as $o) {
        $itemStmt->execute([$o['id']]);
        foreach ($itemStmt->fetchAll() as $i) {
            echo '<tr><td>' . h($o['invoice_no']) . '</td><td>' . fmt_tanggal($o['tanggal']) . '</td><td>' . h($o['sppg_nama']) . '</td><td>' . h($i['nama']) . '</td><td>' . $i['qty'] . '</td><td>' . h($i['satuan']) . '</td><td>' . $i['harga_jual'] . '</td><td>' . $i['subtotal'] . '</td><td>' . $i['margin'] . '</td><td>' . h($o['status']) . '</td></tr>';
        }
    }
    table_close();

    table_open('PETTY CASH');
    echo '<th>Tanggal</th><th>Akun</th><th>Kategori</th><th>Catatan</th><th>Jumlah</th></tr>';
    $expenses = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Petty Cash' ORDER BY tanggal ASC")->fetchAll();
    foreach ($expenses as $e) {
        echo '<tr><td>' . fmt_tanggal($e['tanggal']) . '</td><td>' . h($e['akun']) . '</td><td>' . h($e['kategori']) . '</td><td>' . h($e['catatan']) . '</td><td>' . $e['jumlah'] . '</td></tr>';
    }
    table_close();

    table_open('BEBAN OPERASIONAL');
    echo '<th>Tanggal</th><th>Akun</th><th>Kategori</th><th>Catatan</th><th>Jumlah</th></tr>';
    $bebanOp = $pdo->query("SELECT * FROM expenses WHERE jenis = 'Operasional' ORDER BY tanggal ASC")->fetchAll();
    foreach ($bebanOp as $e) {
        echo '<tr><td>' . fmt_tanggal($e['tanggal']) . '</td><td>' . h($e['akun']) . '</td><td>' . h($e['kategori']) . '</td><td>' . h($e['catatan']) . '</td><td>' . $e['jumlah'] . '</td></tr>';
    }
    table_close();

    $dari = $_GET['dari'] ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    table_open('LABA RUGI (' . fmt_tanggal($dari) . ' s.d ' . fmt_tanggal($sampai) . ')');
    echo '<th>Tipe</th><th>Akun</th><th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th>Debit</th><th>Kredit</th></tr>';
    $stmt = $pdo->prepare("
        SELECT a.tipe, a.kode, a.nama AS akun_nama, jd.debit, jd.kredit, jd.keterangan, ju.tanggal, ju.no_bukti
        FROM jurnal_detail jd JOIN akun a ON a.id = jd.akun_id JOIN jurnal_umum ju ON ju.id = jd.jurnal_id
        WHERE ju.tanggal BETWEEN ? AND ? AND a.tipe IN ('Pendapatan','HPP','Beban')
        ORDER BY a.kode ASC, ju.tanggal ASC
    ");
    $stmt->execute([$dari, $sampai]);
    foreach ($stmt->fetchAll() as $r) {
        echo '<tr><td>' . h($r['tipe']) . '</td><td>' . h($r['kode'] . ' - ' . $r['akun_nama']) . '</td><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['no_bukti']) . '</td><td>' . h($r['keterangan']) . '</td><td>' . $r['debit'] . '</td><td>' . $r['kredit'] . '</td></tr>';
    }
    table_close();

} elseif ($type === 'bukubesar') {
    require_once __DIR__ . '/includes/jurnal_helper.php';
    $akunId = (int)($_GET['akun_id'] ?? 0);
    $dari = $_GET['dari'] ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    $akunStmt = $pdo->prepare("SELECT * FROM akun WHERE id = ?");
    $akunStmt->execute([$akunId]);
    $akun = $akunStmt->fetch();
    table_open($akun ? $akun['kode'] . ' - ' . $akun['nama'] : 'Buku Besar');
    echo '<th>Tanggal</th><th>No Bukti</th><th>Keterangan</th><th>Debit</th><th>Kredit</th><th>Saldo</th></tr>';
    if ($akun) {
        $saldoJalan = get_saldo_akun($pdo, $akunId, $akun['saldo_normal'], date('Y-m-d', strtotime($dari . ' -1 day')));
        echo '<tr><td colspan="5"><b>Saldo Awal</b></td><td><b>' . $saldoJalan . '</b></td></tr>';
        $stmt = $pdo->prepare("SELECT jd.debit, jd.kredit, jd.keterangan, ju.tanggal, ju.no_bukti FROM jurnal_detail jd JOIN jurnal_umum ju ON ju.id=jd.jurnal_id WHERE jd.akun_id=? AND ju.tanggal BETWEEN ? AND ? ORDER BY ju.tanggal ASC, ju.id ASC");
        $stmt->execute([$akunId, $dari, $sampai]);
        foreach ($stmt->fetchAll() as $r) {
            $mutasi = $akun['saldo_normal'] === 'Debit' ? ($r['debit'] - $r['kredit']) : ($r['kredit'] - $r['debit']);
            $saldoJalan += $mutasi;
            echo '<tr><td>' . fmt_tanggal($r['tanggal']) . '</td><td>' . h($r['no_bukti']) . '</td><td>' . h($r['keterangan']) . '</td><td>' . $r['debit'] . '</td><td>' . $r['kredit'] . '</td><td>' . $saldoJalan . '</td></tr>';
        }
    }
    table_close();

} elseif ($type === 'neracasaldo') {
    require_once __DIR__ . '/includes/jurnal_helper.php';
    $sampai = $_GET['sampai'] ?? date('Y-m-d');
    table_open();
    echo '<th>Kode</th><th>Nama Akun</th><th>Debit</th><th>Kredit</th></tr>';
    $akunList = $pdo->query("SELECT * FROM akun ORDER BY kode ASC")->fetchAll();
    foreach ($akunList as $a) {
        $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $sampai);
        if (abs($saldo) < 0.005) continue;
        $debit = $a['saldo_normal'] === 'Debit' ? $saldo : 0;
        $kredit = $a['saldo_normal'] === 'Kredit' ? $saldo : 0;
        echo '<tr><td>' . h($a['kode']) . '</td><td>' . h($a['nama']) . '</td><td>' . $debit . '</td><td>' . $kredit . '</td></tr>';
    }
    table_close();

} elseif ($type === 'neraca') {
    require_once __DIR__ . '/includes/jurnal_helper.php';
    $per = $_GET['per'] ?? date('Y-m-d');
    table_open('ASET');
    echo '<th>Nama Akun</th><th>Saldo</th></tr>';
    foreach ($pdo->query("SELECT * FROM akun WHERE tipe='Aset' ORDER BY kode ASC")->fetchAll() as $a) {
        echo '<tr><td>' . h($a['nama']) . '</td><td>' . get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per) . '</td></tr>';
    }
    table_close();
    table_open('KEWAJIBAN');
    echo '<th>Nama Akun</th><th>Saldo</th></tr>';
    foreach ($pdo->query("SELECT * FROM akun WHERE tipe='Kewajiban' ORDER BY kode ASC")->fetchAll() as $a) {
        echo '<tr><td>' . h($a['nama']) . '</td><td>' . get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per) . '</td></tr>';
    }
    table_close();
    table_open('MODAL');
    echo '<th>Nama Akun</th><th>Saldo</th></tr>';
    foreach ($pdo->query("SELECT * FROM akun WHERE tipe='Modal' ORDER BY kode ASC")->fetchAll() as $a) {
        $saldo = get_saldo_akun($pdo, $a['id'], $a['saldo_normal'], $per);
        $nilai = $a['kode'] === '3-102' ? -$saldo : $saldo;
        echo '<tr><td>' . h($a['nama']) . '</td><td>' . $nilai . '</td></tr>';
    }
    echo '<tr><td>Laba Ditahan/Berjalan</td><td>' . get_laba_berjalan($pdo, $per) . '</td></tr>';
    table_close();

} else {
    table_open();
    echo '<th>Invoice</th><th>Tanggal</th><th>SPPG</th><th>Item</th><th>Qty</th><th>Satuan</th><th>Harga Satuan</th><th>Subtotal</th><th>Margin</th><th>Status</th></tr>';
    $orders = $pdo->query("SELECT * FROM orders ORDER BY tanggal DESC")->fetchAll();
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    foreach ($orders as $o) {
        $itemStmt->execute([$o['id']]);
        foreach ($itemStmt->fetchAll() as $i) {
            echo '<tr><td>' . h($o['invoice_no']) . '</td><td>' . fmt_tanggal($o['tanggal']) . '</td><td>' . h($o['sppg_nama']) . '</td><td>' . h($i['nama']) . '</td><td>' . $i['qty'] . '</td><td>' . h($i['satuan']) . '</td><td>' . $i['harga_jual'] . '</td><td>' . $i['subtotal'] . '</td><td>' . $i['margin'] . '</td><td>' . h($o['status']) . '</td></tr>';
        }
    }
    table_close();
}