<?php
/**
 * Parser XLSX minimalis pakai ZipArchive bawaan PHP (gak butuh library composer apapun).
 * Cukup buat baca data tabular sederhana dari sheet pertama sebuah file .xlsx.
 *
 * Return: array baris, tiap baris = array asosiatif kolom A,B,C,... => value (string)
 */
function read_xlsx($filepath) {
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new Exception('File .xlsx tidak bisa dibuka (mungkin rusak atau bukan file Excel).');
    }

    // Langkah 1: baca shared strings (kalau ada)
    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = simplexml_load_string($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    // rich text -> gabung semua <r><t>
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string)$r->t; }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // Langkah 2: cari sheet pertama
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('Sheet pertama tidak ditemukan di file ini.');
    }
    $zip->close();

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new Exception('Gagal membaca isi sheet.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowXml) {
        $rowData = [];
        foreach ($rowXml->c as $cellXml) {
            $ref = (string)$cellXml['r']; // contoh: "B4"
            preg_match('/([A-Z]+)(\d+)/', $ref, $m);
            $col = $m[1] ?? '';
            $type = (string)$cellXml['t'];

            if ($type === 's') {
                $idx = (int)$cellXml->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cellXml->is->t;
            } elseif ($type === 'str') {
                $value = (string)$cellXml->v;
            } else {
                $value = (string)$cellXml->v; // angka
            }

            $rowData[$col] = trim($value);
        }
        if (!empty($rowData)) {
            $rows[] = $rowData;
        }
    }

    return $rows;
}

/** Ubah huruf kolom (A, B, ..., Z, AA, ...) jadi angka (1, 2, ...) */
function col_to_num($col) {
    $num = 0;
    foreach (str_split($col) as $c) {
        $num = $num * 26 + (ord($c) - 64);
    }
    return $num;
}

/** Kebalikan dari col_to_num() */
function num_to_col($num) {
    $col = '';
    while ($num > 0) {
        $rem = ($num - 1) % 26;
        $col = chr(65 + $rem) . $col;
        $num = intdiv($num - 1, 26);
    }
    return $col;
}

/**
 * Parser khusus buat sheet PRICELIST PRODUCT versi Brother Needs: beberapa blok kategori
 * disusun BERDAMPINGAN (misal blok "Buah" di kolom B-F, blok "Frozen" di kolom H-L), dan
 * nama kategori (BUAH, SAYUR, dst) muncul sebagai baris tersendiri di tengah-tengah data,
 * berjalan independen per blok (satu blok bisa lanjut kategori lama sementara blok
 * sebelahnya udah pindah ke kategori baru).
 *
 * Return: array item ['kategori','nama','satuan','harga_modal','harga_jual'], atau null
 * kalau sheet ini bukan format dua-blok (biar caller bisa fallback ke parser tabel biasa).
 */
function parse_pricelist_blocks($rows) {
    $productLabels = ['product', 'nama', 'nama produk', 'nama barang'];
    $kategoriLabels = ['kategori', 'category'];

    // Kalau ada kolom yang JUDULNYA persis "Kategori"/"Category", ini pasti format
    // tabel rata biasa (1 baris = 1 produk lengkap sama kategorinya) - BUKAN format
    // pricelist lama (yang kategori ditulis sebagai baris pemisah, bukan kolom
    // tersendiri). Serahin ke fallback map_xlsx_rows_by_header biar kolom
    // Kategori-nya kebaca bener, bukan ke-default "Lainnya" semua.
    if (!empty($rows)) {
        foreach ($rows[0] as $val) {
            if (in_array(strtolower(trim($val)), $kategoriLabels, true)) {
                return null;
            }
        }
    }

    // cari baris header (baris yang punya sel bertuliskan "PRODUCT"/"NAMA"/dst)
    $headerRowIdx = null;
    $headerRow = null;
    foreach ($rows as $idx => $row) {
        foreach ($row as $val) {
            if (in_array(strtolower(trim($val)), $productLabels, true)) {
                $headerRowIdx = $idx;
                $headerRow = $row;
                break 2;
            }
        }
    }
    if ($headerRowIdx === null) return null;

    // cari semua kolom "PRODUCT" -> masing-masing jadi 1 blok
    $productCols = [];
    foreach ($headerRow as $col => $val) {
        if (in_array(strtolower(trim($val)), $productLabels, true)) {
            $productCols[] = $col;
        }
    }
    usort($productCols, fn($a, $b) => col_to_num($a) <=> col_to_num($b));
    if (empty($productCols)) return null;

    // susun definisi tiap blok: kolom NO/kategori, PRODUCT, HARGA MODAL, HARGA, SATUAN
    $blocks = [];
    foreach ($productCols as $i => $pcol) {
        $pnum = col_to_num($pcol);
        $noCol = num_to_col(max(1, $pnum - 1));
        $nextPnum = isset($productCols[$i + 1]) ? col_to_num($productCols[$i + 1]) : $pnum + 10;

        $modalCol = $hargaCol = $satuanCol = null;
        for ($c = $pnum + 1; $c < $nextPnum; $c++) {
            $letter = num_to_col($c);
            $label = strtolower(trim($headerRow[$letter] ?? ''));
            if ($label === '') continue;
            if (strpos($label, 'modal') !== false) $modalCol = $letter;
            elseif (strpos($label, 'satuan') !== false || strpos($label, 'unit') !== false) $satuanCol = $letter;
            elseif (strpos($label, 'harga') !== false || strpos($label, 'jual') !== false) $hargaCol = $letter;
        }
        if (!$modalCol) $modalCol = num_to_col($pnum + 1);
        if (!$hargaCol) $hargaCol = num_to_col($pnum + 2);
        if (!$satuanCol) $satuanCol = num_to_col($pnum + 3);

        $blocks[] = ['no' => $noCol, 'product' => $pcol, 'modal' => $modalCol, 'harga' => $hargaCol, 'satuan' => $satuanCol, 'kategori' => 'Lainnya'];
    }

    $items = [];
    for ($idx = $headerRowIdx + 1; $idx < count($rows); $idx++) {
        if (!isset($rows[$idx])) continue;
        $row = $rows[$idx];

        foreach ($blocks as $bi => $block) {
            $productVal = trim($row[$block['product']] ?? '');

            if ($productVal !== '') {
                $modalRaw = trim($row[$block['modal']] ?? '');
                $hargaRaw = trim($row[$block['harga']] ?? '');
                $satuanRaw = trim($row[$block['satuan']] ?? '');
                $items[] = [
                    'kategori' => $blocks[$bi]['kategori'],
                    'nama' => $productVal,
                    'satuan' => $satuanRaw !== '' ? $satuanRaw : 'pcs',
                    'harga_modal' => is_numeric($modalRaw) ? (float)$modalRaw : 0,
                    'harga_jual' => is_numeric($hargaRaw) ? (float)$hargaRaw : 0,
                ];
            } else {
                $noVal = trim($row[$block['no']] ?? '');
                if ($noVal !== '' && !is_numeric($noVal)) {
                    $blocks[$bi]['kategori'] = $noVal; // baris ini adalah judul kategori baru buat blok ini
                }
            }
        }
    }

    return $items;
}

/**
 * Rapikan nama kategori dari huruf kapital semua (BUAH, DAGING & AYAM & SEAFOOD) jadi
 * Title Case yang konsisten sama kategori bawaan aplikasi.
 */
function normalize_kategori($raw) {
    $raw = trim($raw);
    $map = [
        'buah' => 'Buah',
        'sayur' => 'Sayur',
        'bahan baku' => 'Bahan Baku',
        'frozen vegetable & food' => 'Frozen',
        'frozen' => 'Frozen',
        'daging & ayam & seafood' => 'Daging & Seafood',
        'daging & seafood' => 'Daging & Seafood',
        'saus' => 'Saus',
    ];
    $key = strtolower($raw);
    if (isset($map[$key])) return $map[$key];
    if ($raw === '') return 'Lainnya';
    return ucwords(strtolower($raw));
}
function map_xlsx_rows_by_header($rows, $headerAliases) {
    if (empty($rows)) return [];
    $headerRow = array_shift($rows);

    // cari kolom (A, B, C..) mana yang cocok tiap field, berdasarkan alias yang diberikan
    $colFor = [];
    foreach ($headerRow as $col => $label) {
        $labelLower = strtolower(trim($label));
        foreach ($headerAliases as $field => $aliases) {
            if (in_array($labelLower, $aliases, true)) {
                $colFor[$field] = $col;
            }
        }
    }

    $result = [];
    foreach ($rows as $r) {
        $item = [];
        foreach ($headerAliases as $field => $aliases) {
            $col = $colFor[$field] ?? null;
            $item[$field] = $col !== null ? ($r[$col] ?? '') : '';
        }
        $result[] = $item;
    }
    return $result;
}