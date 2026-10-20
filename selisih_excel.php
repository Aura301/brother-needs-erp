<?php
/* Download Excel "Selisih Order Tambahan" (Qty Awal vs Qty Setelah Tambahan), formatnya ngikutin contoh dari user. */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/selisih_helper.php';
ensure_selisih_schema($pdo);

$data = selisih_load($pdo, (int)($_GET['id'] ?? 0));
if (!$data) { http_response_code(404); echo 'Dokumen selisih tidak ditemukan.'; exit; }
$dok = $data['dok'];

/* Style id:
   0 normal | 1 judul | 2 subjudul | 3 header tabel | 4 label bagian (bold, hijau muda) | 5 isi bagian (hijau muda, kosong)
   6 teks bawah-garis | 7 harga | 8 qty biru (input) | 9 qty hitung | 10 uang hitung | 11 subtotal teks (bold abu)
   12 subtotal uang | 13 total teks (bold hijau) | 14 total uang | 15 catatan judul | 16 catatan isi */
$cells = [];    // [row][colIndex] = [kind, value, style, formula]
$rowH = [];
$put = function ($r, $c, $kind, $val, $style, $formula = null) use (&$cells) { $cells[$r][$c] = [$kind, $val, $style, $formula]; };
$colL = ['A','B','C','D','E','F','G','H','I','J','K'];
$fill = function ($r, $style) use (&$put) { for ($c = 0; $c < 11; $c++) $put($r, $c, 's', '', $style); };

$put(1, 0, 's', 'SELISIH ORDER TAMBAHAN - ' . $dok['sppg_nama'], 1);
$put(2, 0, 's', (string)$dok['ket_header'], 2);
$heads = ['No','Barang','Satuan','Harga Satuan','Qty Awal','Qty Setelah Tambahan','Selisih Qty','Total Awal','Total Setelah','Selisih (Rp)','Keterangan'];
foreach ($heads as $c => $t) $put(4, $c, 's', $t, 3);
$rowH[4] = 31.5;

$r = 5; $subRows = []; $notes = [];
foreach ($data['invoices'] as $inv) {
    $fill($r, 5);
    $put($r, 0, 's', $inv['label'] . ' (Invoice ' . $inv['invoice_no'] . ')', 4);
    $r++;
    $first = $r; $sumA = 0; $sumS = 0; $sumJ = 0; $no = 1;
    foreach ($inv['items'] as $i) {
        $qa = (float)$i['qty_awal']; $qs = (float)$i['qty_setelah']; $hg = (float)$i['harga'];
        $ta = $hg * $qa; $ts = $hg * $qs;
        $put($r, 0, 'n', $no++, 6);
        $put($r, 1, 's', $i['nama'], 6);
        $put($r, 2, 's', $i['satuan'], 6);
        $put($r, 3, 'n', $hg, 7);
        $put($r, 4, 'n', $qa, 8);
        $put($r, 5, 'n', $qs, 8);
        $put($r, 6, 'f', $qs - $qa, 9, "F$r-E$r");
        $put($r, 7, 'f', $ta, 10, "D$r*E$r");
        $put($r, 8, 'f', $ts, 10, "D$r*F$r");
        $put($r, 9, 'f', $ts - $ta, 10, "I$r-H$r");
        $put($r, 10, 'fs', selisih_keterangan($qa, $qs), 6, "IF(E$r=0,\"Barang baru\",IF(G$r=0,\"Tetap\",IF(G$r>0,\"Ditambah\",\"Dikurangi\")))");
        $sumA += $ta; $sumS += $ts; $sumJ += $ts - $ta;
        $r++;
    }
    $last = $r - 1;
    $fill($r, 11);
    $put($r, 1, 's', 'Subtotal ' . mb_convert_case(mb_strtolower($inv['label']), MB_CASE_TITLE), 11);
    if ($last >= $first) {
        $put($r, 7, 'f', $sumA, 12, "SUM(H$first:H$last)");
        $put($r, 8, 'f', $sumS, 12, "SUM(I$first:I$last)");
        $put($r, 9, 'f', $sumJ, 12, "SUM(J$first:J$last)");
    }
    $subRows[] = [$r, $sumA, $sumS, $sumJ];
    $notes[] = 'Qty Awal = invoice ' . $inv['invoice_no'] . ' versi awal (Rp' . number_format($sumA, 0, ',', '.') . '). Qty Setelah Tambahan = versi setelah tambahan (Rp' . number_format($sumS, 0, ',', '.') . ').';
    $r += 2;
}
// TOTAL SEMUA
$fill($r, 13);
$put($r, 1, 's', 'TOTAL SEMUA', 13);
foreach ([[7, 1, 'H'], [8, 2, 'I'], [9, 3, 'J']] as [$ci, $k, $L]) {
    $val = array_sum(array_column($subRows, $k));
    $f = implode('+', array_map(fn($sr) => $L . $sr[0], $subRows));
    $put($r, $ci, 'f', $val, 14, $f ?: null);
}
$r += 2;
$put($r, 0, 's', 'Catatan:', 15); $r++;
foreach ($notes as $n) { $put($r, 0, 's', $n, 16); $r++; }
foreach (preg_split('/\R/', (string)$dok['catatan']) as $line) {
    $line = trim($line);
    if ($line !== '') { $put($r, 0, 's', $line, 16); $r++; }
}
$put($r, 0, 's', 'Teks biru = angka input dari invoice; hitungan lainnya pakai rumus.', 16);

$namaFile = 'Selisih_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', selisih_tgl($dok['tanggal']) . ' ' . $dok['sppg_nama']);

// ---- Fallback tanpa ZipArchive: tabel HTML .xls ----
if (!class_exists('ZipArchive')) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '.xls"');
    echo "\xEF\xBB\xBF<table border=\"1\">";
    ksort($cells);
    foreach ($cells as $row) { ksort($row); echo '<tr>'; foreach ($row as $c) echo '<td>' . h((string)$c[1]) . '</td>'; echo '</tr>'; }
    echo '</table>';
    exit;
}

function sx($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
  '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" zoomScale="85" zoomScaleNormal="85"/></sheetViews>' .
  '<cols><col min="1" max="1" width="5" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="3" width="9" customWidth="1"/><col min="4" max="4" width="13" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="6" width="14" customWidth="1"/><col min="7" max="7" width="11" customWidth="1"/><col min="8" max="11" width="14" customWidth="1"/></cols><sheetData>';
ksort($cells);
foreach ($cells as $rn => $row) {
    ksort($row);
    $sheet .= '<row r="' . $rn . '"' . (isset($rowH[$rn]) ? ' ht="' . $rowH[$rn] . '" customHeight="1"' : '') . '>';
    foreach ($row as $ci => [$kind, $val, $style, $formula]) {
        $ref = $colL[$ci] . $rn;
        if ($kind === 'n') {
            $sheet .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
        } elseif ($kind === 'f') {
            $sheet .= '<c r="' . $ref . '" s="' . $style . '"><f>' . sx($formula) . '</f><v>' . $val . '</v></c>';
        } elseif ($kind === 'fs') {
            $sheet .= '<c r="' . $ref . '" s="' . $style . '" t="str"><f>' . sx($formula) . '</f><v>' . sx($val) . '</v></c>';
        } else {
            $sheet .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . sx($val) . '</t></is></c>';
        }
    }
    $sheet .= '</row>';
}
$sheet .= '</sheetData><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

$fonts = ['<font><sz val="10"/><name val="Arial"/></font>',
  '<font><b/><sz val="10"/><name val="Arial"/></font>',
  '<font><b/><sz val="13"/><name val="Arial"/></font>',
  '<font><i/><sz val="9"/><color rgb="FF555555"/><name val="Arial"/></font>',
  '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>',
  '<font><sz val="10"/><color rgb="FF0000FF"/><name val="Arial"/></font>',
  '<font><b/><sz val="9"/><name val="Arial"/></font>',
  '<font><i/><sz val="9"/><name val="Arial"/></font>'];
$fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
foreach (['FF2F5D3A','FFE8F0E6','FFF3F3F3','FFD9E8D5'] as $c) $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="' . $c . '"/><bgColor indexed="64"/></patternFill></fill>';
$borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>', '<border><left/><right/><top/><bottom style="thin"><color rgb="FFCCCCCC"/></bottom><diagonal/></border>'];
// [fontId, fillId, borderId, numFmtId, alignment-xml]
$xfs = [
  [0,0,0,0,''], [2,0,0,0,''], [3,0,0,0,''], [4,2,0,0,'<alignment horizontal="center" vertical="center" wrapText="1"/>'],
  [1,3,1,0,''], [0,3,1,0,''], [0,0,1,0,''], [0,0,1,3,''], [5,0,1,164,''], [0,0,1,164,''], [0,0,1,165,''],
  [1,4,1,0,''], [1,4,1,165,''], [1,5,1,0,''], [1,5,1,165,''], [6,0,0,0,''], [7,0,0,0,''],
];
$xml = '';
foreach ($xfs as [$fo, $fi, $bo, $nf, $al]) {
    $xml .= '<xf numFmtId="' . $nf . '" fontId="' . $fo . '" fillId="' . $fi . '" borderId="' . $bo . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
}
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
  '<numFmts count="2"><numFmt numFmtId="164" formatCode="0.##"/><numFmt numFmtId="165" formatCode="#,##0;\(#,##0\);\-"/></numFmts>' .
  '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts><fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>' .
  '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
  '<cellXfs count="' . count($xfs) . '">' . $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

$tmp = tempnam(sys_get_temp_dir(), 'sel');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
$sheetName = sx('Selisih ' . preg_replace('/[\[\]\*\?\/\\\\:]/', '', selisih_tgl($dok['tanggal'])));
$zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $namaFile . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
