<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/jurnal_helper.php';
$active = 'keuangan';
$page_title = 'Hutang';

// Tabel pembayaran hutang dibikin otomatis kalau belum ada (gak perlu jalanin SQL manual)
$pdo->exec("CREATE TABLE IF NOT EXISTS pembelian_bayar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pembelian_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  dari_akun VARCHAR(10) NOT NULL DEFAULT '1-101',
  catatan VARCHAR(255) DEFAULT '',
  jurnal_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pembelian (pembelian_id)
) ENGINE=InnoDB");

// Tabel talangan (uang pribadi yang dipake buat bayar biaya usaha) - dibikin otomatis
$pdo->exec("CREATE TABLE IF NOT EXISTS talangan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  pihak VARCHAR(100) NOT NULL,
  keterangan VARCHAR(255) NOT NULL,
  kategori VARCHAR(60) NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  jurnal_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS talangan_bayar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  talangan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  dari_akun VARCHAR(10) NOT NULL DEFAULT '1-101',
  catatan VARCHAR(255) DEFAULT '',
  jurnal_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_talangan (talangan_id)
) ENGINE=InnoDB");

// Pastikan kolom "sumber" di jurnal_umum nerima 'Bayar Hutang' & 'Talangan'.
// Kalau gagal (misal gak ada izin ALTER), dicatat sebagai 'Manual' - angka jurnalnya tetep bener.
function pastikan_sumber_jurnal($pdo, $nilai) {
    try {
        $q = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jurnal_umum' AND COLUMN_NAME = 'sumber'";
        $colType = $pdo->query($q)->fetchColumn();
        if (!$colType) return 'Manual';
        if (stripos($colType, 'enum(') !== 0) return $nilai; // VARCHAR, bebas
        if (stripos($colType, "'" . $nilai . "'") === false) {
            $newType = substr($colType, 0, -1) . ",'" . $nilai . "')";
            $pdo->exec("ALTER TABLE jurnal_umum MODIFY sumber $newType NOT NULL DEFAULT 'Manual'");
            $colType = $pdo->query($q)->fetchColumn();
        }
        return stripos($colType, "'" . $nilai . "'") !== false ? $nilai : 'Manual';
    } catch (Exception $e) {
        return 'Manual';
    }
}
$sumberBayar = pastikan_sumber_jurnal($pdo, 'Bayar Hutang');
$sumberTalangan = pastikan_sumber_jurnal($pdo, 'Talangan');

function talangan_kategori_ke_akun($kategori) {
    $map = [
        'Ongkos Kirim (Lalamove dll)' => '6-102',
        'Gaji Karyawan' => '6-103',
        'Lain-lain' => '6-104',
        'Listrik & Utilitas' => '6-106',
        'Maintenance & Perawatan' => '6-107',
    ];
    return $map[$kategori] ?? '6-101';
}
$kategoriTalangan = ['Ongkos Kirim (Lalamove dll)', 'Gaji Karyawan', 'Listrik & Utilitas', 'Maintenance & Perawatan', 'Lain-lain'];

$error = '';
$ok = '';

// ---- Bayar hutang ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bayar') {
    $pembelianId = (int)($_POST['pembelian_id'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $jumlah = clean_number($_POST['jumlah'] ?? 0);
    $dariAkun = ($_POST['dari_akun'] ?? '1-101') === '1-102' ? '1-102' : '1-101';
    $catatan = trim($_POST['catatan'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM pembelian WHERE id = ? AND metode_bayar = 'Hutang'");
    $stmt->execute([$pembelianId]);
    $pb = $stmt->fetch();

    if (!$pb) {
        $error = 'Pembelian hutang gak ditemukan.';
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) FROM pembelian_bayar WHERE pembelian_id = ?");
        $stmt->execute([$pembelianId]);
        $sudahBayar = (float)$stmt->fetchColumn();
        $sisa = (float)$pb['total'] - $sudahBayar;

        if ($jumlah <= 0) {
            $error = 'Jumlah bayar harus lebih dari 0.';
        } elseif ($jumlah > $sisa + 0.001) {
            $error = 'Jumlah bayar (' . rupiah($jumlah) . ') lebih besar dari sisa hutang (' . rupiah($sisa) . ').';
        } else {
            try {
                $pdo->beginTransaction();
                $userId = current_user()['id'] ?? null;
                $ins = $pdo->prepare("INSERT INTO pembelian_bayar (pembelian_id, tanggal, jumlah, dari_akun, catatan, created_by) VALUES (?,?,?,?,?,?)");
                $ins->execute([$pembelianId, $tanggal, $jumlah, $dariAkun, $catatan, $userId]);
                $bayarId = (int)$pdo->lastInsertId();

                $ket = 'Bayar hutang ' . $pb['no_bukti'] . ($pb['supplier'] ? ' - ' . $pb['supplier'] : '') . ($catatan ? ' (' . $catatan . ')' : '');
                $jurnalId = post_jurnal($pdo, $tanggal, $ket, [
                    ['akun_id' => akun_id($pdo, '2-101'), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => 'Pelunasan ' . $pb['no_bukti']],
                    ['akun_id' => akun_id($pdo, $dariAkun), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => 'Bayar hutang ' . $pb['no_bukti']],
                ], $sumberBayar, $bayarId, $userId, 'BH-' . $bayarId);

                if (!$jurnalId) throw new Exception('Jurnal gagal diposting (gak balance atau akun gak ketemu).');
                $pdo->prepare("UPDATE pembelian_bayar SET jurnal_id = ? WHERE id = ?")->execute([$jurnalId, $bayarId]);
                $pdo->commit();
                redirect('keuangan_hutang.php?ok=1');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal menyimpan pembayaran: ' . $e->getMessage();
            }
        }
    }
}

// ---- Batalin 1 pembayaran (salah input) ----
if (isset($_GET['hapus_bayar'])) {
    $bayarId = (int)$_GET['hapus_bayar'];
    $stmt = $pdo->prepare("SELECT jurnal_id FROM pembelian_bayar WHERE id = ?");
    $stmt->execute([$bayarId]);
    $row = $stmt->fetch();
    if ($row) {
        try {
            $pdo->beginTransaction();
            if ($row['jurnal_id']) {
                $pdo->prepare("DELETE FROM jurnal_detail WHERE jurnal_id = ?")->execute([$row['jurnal_id']]);
                $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ?")->execute([$row['jurnal_id']]);
            }
            $pdo->prepare("DELETE FROM pembelian_bayar WHERE id = ?")->execute([$bayarId]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    redirect('keuangan_hutang.php?batal=1');
}

// ---- Catat talangan baru (biaya usaha dibayar pake uang pribadi) ----
// Jurnal: Debit Beban, Kredit Hutang Usaha. Kas gak berkurang karena yang keluar uang pribadi.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tambah_talangan') {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $pihak = trim($_POST['pihak'] ?? '');
    $ket = trim($_POST['keterangan'] ?? '');
    $kategori = $_POST['kategori'] ?? '';
    $jumlah = clean_number($_POST['jumlah'] ?? 0);

    if ($pihak === '' || $ket === '' || $jumlah <= 0 || !in_array($kategori, $kategoriTalangan, true)) {
        $error = 'Lengkapi nama yang nalangin, keterangan, kategori, dan jumlah (harus lebih dari 0).';
    } else {
        try {
            $pdo->beginTransaction();
            $userId = current_user()['id'] ?? null;
            $pdo->prepare("INSERT INTO talangan (tanggal, pihak, keterangan, kategori, jumlah, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$tanggal, $pihak, $ket, $kategori, $jumlah, $userId]);
            $tid = (int)$pdo->lastInsertId();
            $jurnalId = post_jurnal($pdo, $tanggal, 'Talangan ' . $pihak . ': ' . $ket, [
                ['akun_id' => akun_id($pdo, talangan_kategori_ke_akun($kategori)), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => $ket],
                ['akun_id' => akun_id($pdo, '2-101'), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => 'Hutang ke ' . $pihak],
            ], $sumberTalangan, $tid, $userId, 'TL-' . $tid);
            if (!$jurnalId) throw new Exception('Jurnal gagal diposting.');
            $pdo->prepare("UPDATE talangan SET jurnal_id = ? WHERE id = ?")->execute([$jurnalId, $tid]);
            $pdo->commit();
            redirect('keuangan_hutang.php?ok_tl=1');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Gagal menyimpan talangan: ' . $e->getMessage();
        }
    }
}

// ---- Ganti uang talangan (usaha bayar balik ke yang nalangin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bayar_talangan') {
    $tid = (int)($_POST['talangan_id'] ?? 0);
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $jumlah = clean_number($_POST['jumlah'] ?? 0);
    $dariAkun = ($_POST['dari_akun'] ?? '1-101') === '1-102' ? '1-102' : '1-101';
    $catatan = trim($_POST['catatan'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM talangan WHERE id = ?");
    $stmt->execute([$tid]);
    $tl = $stmt->fetch();
    if (!$tl) {
        $error = 'Data talangan gak ditemukan.';
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) FROM talangan_bayar WHERE talangan_id = ?");
        $stmt->execute([$tid]);
        $sisa = (float)$tl['jumlah'] - (float)$stmt->fetchColumn();
        if ($jumlah <= 0) {
            $error = 'Jumlah harus lebih dari 0.';
        } elseif ($jumlah > $sisa + 0.001) {
            $error = 'Jumlah (' . rupiah($jumlah) . ') lebih besar dari sisa talangan (' . rupiah($sisa) . ').';
        } else {
            try {
                $pdo->beginTransaction();
                $userId = current_user()['id'] ?? null;
                $pdo->prepare("INSERT INTO talangan_bayar (talangan_id, tanggal, jumlah, dari_akun, catatan, created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$tid, $tanggal, $jumlah, $dariAkun, $catatan, $userId]);
                $bid = (int)$pdo->lastInsertId();
                $jurnalId = post_jurnal($pdo, $tanggal, 'Ganti talangan ' . $tl['pihak'] . ': ' . $tl['keterangan'] . ($catatan ? ' (' . $catatan . ')' : ''), [
                    ['akun_id' => akun_id($pdo, '2-101'), 'debit' => $jumlah, 'kredit' => 0, 'keterangan' => 'Pelunasan hutang ke ' . $tl['pihak']],
                    ['akun_id' => akun_id($pdo, $dariAkun), 'debit' => 0, 'kredit' => $jumlah, 'keterangan' => 'Ganti talangan ' . $tl['pihak']],
                ], $sumberBayar, $bid, $userId, 'BT-' . $bid);
                if (!$jurnalId) throw new Exception('Jurnal gagal diposting.');
                $pdo->prepare("UPDATE talangan_bayar SET jurnal_id = ? WHERE id = ?")->execute([$jurnalId, $bid]);
                $pdo->commit();
                redirect('keuangan_hutang.php?ok=1');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal menyimpan pembayaran: ' . $e->getMessage();
            }
        }
    }
}

// ---- Batalin 1 pembayaran talangan ----
if (isset($_GET['hapus_bayar_tl'])) {
    $bid = (int)$_GET['hapus_bayar_tl'];
    $stmt = $pdo->prepare("SELECT jurnal_id FROM talangan_bayar WHERE id = ?");
    $stmt->execute([$bid]);
    $row = $stmt->fetch();
    if ($row) {
        try {
            $pdo->beginTransaction();
            if ($row['jurnal_id']) {
                $pdo->prepare("DELETE FROM jurnal_detail WHERE jurnal_id = ?")->execute([$row['jurnal_id']]);
                $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ?")->execute([$row['jurnal_id']]);
            }
            $pdo->prepare("DELETE FROM talangan_bayar WHERE id = ?")->execute([$bid]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    redirect('keuangan_hutang.php?batal=1');
}

// ---- Hapus talangan (salah catat) - cuma boleh kalau belum ada pembayaran ----
if (isset($_GET['hapus_talangan'])) {
    $tid = (int)$_GET['hapus_talangan'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM talangan_bayar WHERE talangan_id = ?");
    $stmt->execute([$tid]);
    if ((int)$stmt->fetchColumn() > 0) {
        redirect('keuangan_hutang.php?err_tl=1');
    }
    $stmt = $pdo->prepare("SELECT jurnal_id FROM talangan WHERE id = ?");
    $stmt->execute([$tid]);
    $row = $stmt->fetch();
    if ($row) {
        try {
            $pdo->beginTransaction();
            if ($row['jurnal_id']) {
                $pdo->prepare("DELETE FROM jurnal_detail WHERE jurnal_id = ?")->execute([$row['jurnal_id']]);
                $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ?")->execute([$row['jurnal_id']]);
            }
            $pdo->prepare("DELETE FROM talangan WHERE id = ?")->execute([$tid]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    redirect('keuangan_hutang.php?batal_tl=1');
}

if (isset($_GET['ok'])) $ok = 'Pembayaran hutang tersimpan, Kas udah berkurang & Hutang Usaha udah turun.';
if (isset($_GET['batal'])) $ok = 'Pembayaran dibatalin, hutang balik seperti sebelumnya.';
if (isset($_GET['ok_tl'])) $ok = 'Talangan tercatat: biaya masuk ke Beban, dan jadi Hutang Usaha ke yang nalangin. Kas gak berkurang.';
if (isset($_GET['batal_tl'])) $ok = 'Talangan dihapus beserta jurnalnya.';
if (isset($_GET['err_tl'])) $error = 'Talangan yang udah ada pembayarannya gak bisa dihapus. Batalin dulu pembayarannya.';

$filter = $_GET['filter'] ?? 'belum';
if (!in_array($filter, ['belum', 'lunas', 'semua'])) $filter = 'belum';

$rows = $pdo->query("
    SELECT p.*, COALESCE((SELECT SUM(b.jumlah) FROM pembelian_bayar b WHERE b.pembelian_id = p.id), 0) AS sudah_bayar
    FROM pembelian p WHERE p.metode_bayar = 'Hutang'
    ORDER BY p.tanggal DESC, p.id DESC
")->fetchAll();

$totalHutangBelum = 0;
foreach ($rows as &$r) {
    $r['sisa'] = (float)$r['total'] - (float)$r['sudah_bayar'];
    if ($r['sisa'] > 0.001) $totalHutangBelum += $r['sisa'];
}
unset($r);

$tampil = array_filter($rows, function ($r) use ($filter) {
    if ($filter === 'belum') return $r['sisa'] > 0.001;
    if ($filter === 'lunas') return $r['sisa'] <= 0.001;
    return true;
});

$talanganRows = $pdo->query("
    SELECT t.*, COALESCE((SELECT SUM(b.jumlah) FROM talangan_bayar b WHERE b.talangan_id = t.id), 0) AS sudah_bayar
    FROM talangan t ORDER BY t.tanggal DESC, t.id DESC
")->fetchAll();
$totalTalanganBelum = 0;
foreach ($talanganRows as &$t) {
    $t['sisa'] = (float)$t['jumlah'] - (float)$t['sudah_bayar'];
    if ($t['sisa'] > 0.001) $totalTalanganBelum += $t['sisa'];
}
unset($t);
$talanganTampil = array_filter($talanganRows, function ($t) use ($filter) {
    if ($filter === 'belum') return $t['sisa'] > 0.001;
    if ($filter === 'lunas') return $t['sisa'] <= 0.001;
    return true;
});
$tlBayarStmt = $pdo->prepare("SELECT * FROM talangan_bayar WHERE talangan_id = ? ORDER BY tanggal ASC, id ASC");

$bayarStmt = $pdo->prepare("SELECT * FROM pembelian_bayar WHERE pembelian_id = ? ORDER BY tanggal ASC, id ASC");

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Keuangan</div>
    <div class="page-sub">Hutang dari pembelian yang belum dibayar (misal belanja pake uang orang lain)</div>
  </div>
</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
  <a href="keuangan.php" class="btn btn-subtle">Ringkasan</a>
  <a href="keuangan_jurnal.php" class="btn btn-subtle">Jurnal Umum</a>
  <a href="keuangan_bukubesar.php" class="btn btn-subtle">Buku Besar</a>
  <a href="keuangan_neracasaldo.php" class="btn btn-subtle">Neraca Saldo</a>
  <a href="keuangan_neraca.php" class="btn btn-subtle">Neraca</a>
  <a href="keuangan_aruskas.php" class="btn btn-subtle">Arus Kas</a>
  <a href="keuangan_hutang.php" class="btn btn-primary">Hutang</a>
  <a href="keuangan_pembelian.php" class="btn btn-subtle">Pembelian</a>
  <a href="riwayat.php" class="btn btn-subtle">Penjualan</a>
  <a href="keuangan_petty_cash.php" class="btn btn-subtle">Petty Cash</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-ok"><?= h($ok) ?></div><?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
  <div>
    <div style="font-size:12px; color:var(--muted);">Total hutang belum dibayar (pembelian + talangan)</div>
    <div style="font-size:22px; font-weight:700; font-family:var(--font-mono); color:var(--danger);"><?= rupiah($totalHutangBelum + $totalTalanganBelum) ?></div>
    <div style="font-size:11.5px; color:var(--muted);">Pembelian <?= rupiah($totalHutangBelum) ?> · Talangan <?= rupiah($totalTalanganBelum) ?></div>
  </div>
  <div style="display:flex; gap:8px;">
    <a href="keuangan_hutang.php?filter=belum" class="btn btn-sm <?= $filter === 'belum' ? 'btn-primary' : 'btn-subtle' ?>">Belum Lunas</a>
    <a href="keuangan_hutang.php?filter=lunas" class="btn btn-sm <?= $filter === 'lunas' ? 'btn-primary' : 'btn-subtle' ?>">Sudah Lunas</a>
    <a href="keuangan_hutang.php?filter=semua" class="btn btn-sm <?= $filter === 'semua' ? 'btn-primary' : 'btn-subtle' ?>">Semua</a>
  </div>
</div>

<div style="font-weight:700; font-size:15px; margin:6px 0 10px;">Hutang dari Pembelian</div>
<?php if (empty($tampil)): ?>
  <div class="card"><div class="empty-state"><div class="icon">✅</div><div class="title">Gak ada hutang di filter ini</div></div></div>
<?php else: foreach ($tampil as $p): ?>
  <?php $bayarStmt->execute([$p['id']]); $bayars = $bayarStmt->fetchAll(); $lunas = $p['sisa'] <= 0.001; ?>
  <div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
      <div>
        <div style="font-weight:700;"><?= h($p['no_bukti']) ?> <?= $p['supplier'] ? '· ' . h($p['supplier']) : '' ?></div>
        <div style="font-size:12px; color:var(--muted);"><?= fmt_tanggal($p['tanggal']) ?><?= $p['keterangan'] ? ' · ' . h($p['keterangan']) : '' ?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:12px; color:var(--muted);">Total <?= rupiah($p['total']) ?> · Terbayar <?= rupiah($p['sudah_bayar']) ?></div>
        <?php if ($lunas): ?>
          <span class="badge badge-ok">Lunas</span>
        <?php else: ?>
          <div style="font-weight:700; font-family:var(--font-mono); color:var(--danger);">Sisa <?= rupiah($p['sisa']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($bayars)): ?>
      <div style="margin-top:10px; font-size:12.5px;">
        <?php foreach ($bayars as $b): ?>
          <div style="display:flex; justify-content:space-between; padding:4px 0; border-top:1px dashed var(--border);">
            <span>✓ <?= fmt_tanggal($b['tanggal']) ?> · <?= $b['dari_akun'] === '1-102' ? 'Kas Kecil' : 'Kas' ?><?= $b['catatan'] ? ' · ' . h($b['catatan']) : '' ?></span>
            <span>
              <span style="font-family:var(--font-mono);"><?= rupiah($b['jumlah']) ?></span>
              <a href="keuangan_hutang.php?hapus_bayar=<?= $b['id'] ?>" style="color:var(--danger); margin-left:8px;" onclick="return confirm('Batalin pembayaran ini? Kas bakal balik naik & hutangnya muncul lagi.')">batalin</a>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$lunas): ?>
      <form method="POST" style="margin-top:12px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="action" value="bayar">
        <input type="hidden" name="pembelian_id" value="<?= $p['id'] ?>">
        <div><label class="field-label">Tanggal bayar</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required></div>
        <div><label class="field-label">Jumlah (Rp)</label><input type="number" step="any" name="jumlah" value="<?= round($p['sisa'], 2) ?>" required style="width:140px;"></div>
        <div>
          <label class="field-label">Dibayar dari</label>
          <select name="dari_akun"><option value="1-101">Kas</option><option value="1-102">Kas Kecil</option></select>
        </div>
        <div><label class="field-label">Catatan</label><input type="text" name="catatan" placeholder="misal: ganti uang petugas belanja" style="width:190px;"></div>
        <button type="submit" class="btn btn-primary">Bayar Hutang</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>


<div style="font-weight:700; font-size:15px; margin:26px 0 4px;">Talangan Uang Pribadi</div>
<div style="font-size:12.5px; color:var(--muted); margin-bottom:10px;">Biaya usaha (misal Lalamove) yang dibayar pake uang pribadi karena kas gak cukup. Dicatat sebagai Beban dan Hutang Usaha ke yang nalangin, bukan modal.</div>

<div class="card card-pad" style="margin-bottom:14px;">
  <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="action" value="tambah_talangan">
    <div><label class="field-label">Tanggal</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required></div>
    <div><label class="field-label">Yang nalangin</label><input type="text" name="pihak" placeholder="misal: Pik" required style="width:130px;"></div>
    <div><label class="field-label">Kategori beban</label>
      <select name="kategori"><?php foreach ($kategoriTalangan as $k): ?><option value="<?= h($k) ?>"><?= h($k) ?></option><?php endforeach; ?></select>
    </div>
    <div><label class="field-label">Keterangan</label><input type="text" name="keterangan" placeholder="misal: Lalamove 1 Okt" required style="width:200px;"></div>
    <div><label class="field-label">Jumlah (Rp)</label><input type="number" step="any" name="jumlah" required style="width:130px;"></div>
    <button type="submit" class="btn btn-primary">+ Catat Talangan</button>
  </form>
</div>

<?php if (empty($talanganTampil)): ?>
  <div class="card"><div class="empty-state"><div class="icon">✅</div><div class="title">Gak ada talangan di filter ini</div></div></div>
<?php else: foreach ($talanganTampil as $t): ?>
  <?php $tlBayarStmt->execute([$t['id']]); $tlBayars = $tlBayarStmt->fetchAll(); $tlLunas = $t['sisa'] <= 0.001; ?>
  <div class="card card-pad" style="margin-bottom:14px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
      <div>
        <div style="font-weight:700;"><?= h($t['keterangan']) ?> <span class="badge badge-muted"><?= h($t['kategori']) ?></span></div>
        <div style="font-size:12px; color:var(--muted);">Ditalangin <?= h($t['pihak']) ?> · <?= fmt_tanggal($t['tanggal']) ?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:12px; color:var(--muted);">Total <?= rupiah($t['jumlah']) ?> · Sudah diganti <?= rupiah($t['sudah_bayar']) ?></div>
        <?php if ($tlLunas): ?>
          <span class="badge badge-ok">Sudah diganti</span>
        <?php else: ?>
          <div style="font-weight:700; font-family:var(--font-mono); color:var(--danger);">Sisa <?= rupiah($t['sisa']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($tlBayars)): ?>
      <div style="margin-top:10px; font-size:12.5px;">
        <?php foreach ($tlBayars as $b): ?>
          <div style="display:flex; justify-content:space-between; padding:4px 0; border-top:1px dashed var(--border);">
            <span>✓ Diganti <?= fmt_tanggal($b['tanggal']) ?> · <?= $b['dari_akun'] === '1-102' ? 'Kas Kecil' : 'Kas' ?><?= $b['catatan'] ? ' · ' . h($b['catatan']) : '' ?></span>
            <span>
              <span style="font-family:var(--font-mono);"><?= rupiah($b['jumlah']) ?></span>
              <a href="keuangan_hutang.php?hapus_bayar_tl=<?= $b['id'] ?>" style="color:var(--danger); margin-left:8px;" onclick="return confirm('Batalin pembayaran ini? Kas bakal balik naik & talangannya muncul lagi.')">batalin</a>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$tlLunas): ?>
      <form method="POST" style="margin-top:12px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="action" value="bayar_talangan">
        <input type="hidden" name="talangan_id" value="<?= $t['id'] ?>">
        <div><label class="field-label">Tanggal ganti</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required></div>
        <div><label class="field-label">Jumlah (Rp)</label><input type="number" step="any" name="jumlah" value="<?= round($t['sisa'], 2) ?>" required style="width:140px;"></div>
        <div><label class="field-label">Dibayar dari</label><select name="dari_akun"><option value="1-101">Kas</option><option value="1-102">Kas Kecil</option></select></div>
        <div><label class="field-label">Catatan</label><input type="text" name="catatan" placeholder="opsional" style="width:160px;"></div>
        <button type="submit" class="btn btn-primary">Ganti Uang</button>
        <?php if (empty($tlBayars)): ?>
          <a href="keuangan_hutang.php?hapus_talangan=<?= $t['id'] ?>" class="btn btn-sm" style="color:var(--danger);" onclick="return confirm('Hapus talangan ini beserta jurnalnya?')">Hapus</a>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>