<?php
declare(strict_types=1);

/**
 * Membuat template import siswa (.xlsx dan .csv) untuk halaman ?page=import.
 * Jalankan dari root proyek: php database/build-students-template.php
 *
 * Semua sel ditulis sebagai teks (shared string + number format "@") supaya
 * username seperti 1921 atau password berawalan nol tidak diubah Excel menjadi angka.
 */

const IMPORT_HEADERS = ['nama', 'kelas', 'username', 'password'];

const IMPORT_EXAMPLES = [
    ['Budi Santoso', 'X IPA 1', 'budi01', 'Rahasia123'],
    ['Siti Aminah', 'X IPA 1', 'siti01', 'Rahasia456'],
    ['Andi Pratama', '', 'andi01', 'KkaAndi2026'],
];

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function column_letter(int $number): string
{
    $letters = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $number = intdiv($number - $remainder, 26);
    }
    return $letters;
}

function build_xlsx(array $headers, array $examples): string
{
    // Kumpulkan seluruh teks ke sharedStrings agar semua sel bertipe teks.
    $shared = [];
    $index = static function (string $value) use (&$shared): int {
        $found = array_search($value, $shared, true);
        if ($found === false) {
            $shared[] = $value;
            return count($shared) - 1;
        }
        return $found;
    };

    $sheetRows = '';
    $rowNumber = 0;
    foreach (array_merge([$headers], $examples) as $row) {
        $rowNumber++;
        $isHeader = $rowNumber === 1;
        $cells = '';
        foreach (array_values($row) as $column => $value) {
            $reference = column_letter($column + 1) . $rowNumber;
            $style = $isHeader ? 1 : 2;
            $cells .= '<c r="' . $reference . '" s="' . $style . '" t="s"><v>' . $index((string) $value) . '</v></c>';
        }
        $sheetRows .= '<row r="' . $rowNumber . '"' . ($isHeader ? ' ht="24" customHeight="1"' : '') . '>' . $cells . '</row>';
    }

    $lastColumn = column_letter(count($headers));
    $dimension = 'A1:' . $lastColumn . $rowNumber;

    $sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $value) {
        $sharedStrings .= '<si><t xml:space="preserve">' . xml_escape($value) . '</t></si>';
    }
    $sharedStrings .= '</sst>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>'
        . '<col min="1" max="1" width="30" customWidth="1"/>'
        . '<col min="2" max="2" width="14" customWidth="1"/>'
        . '<col min="3" max="3" width="18" customWidth="1"/>'
        . '<col min="4" max="4" width="22" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . '1"/>'
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';


    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF4C1D95"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right>'
        . '<top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
        . '<alignment vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '<dxfs count="0"/>'
        . '<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
        . '</styleSheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Siswa" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $temporary = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($temporary === false) {
        throw new RuntimeException('Tidak dapat membuat berkas sementara.');
    }
    @unlink($temporary);

    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZipArchive tidak dapat membuat file XLSX.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $binary = (string) file_get_contents($temporary);
    @unlink($temporary);
    return $binary;
}

$projectRoot = dirname(__DIR__);
$xlsx = build_xlsx(IMPORT_HEADERS, IMPORT_EXAMPLES);

$targets = [
    __DIR__ . '/students-template.xlsx',
    $projectRoot . '/public/template-import-siswa.xlsx',
];
foreach ($targets as $target) {
    if (file_put_contents($target, $xlsx) === false) {
        throw new RuntimeException('Gagal menulis ' . $target);
    }
    printf("XLSX  %-56s %6d bytes\n", $target, strlen($xlsx));
}

// Template CSV dibuat dari data yang sama agar isi kedua format selalu identik.
$csvHandle = fopen('php://temp', 'wb');
foreach (array_merge([IMPORT_HEADERS], IMPORT_EXAMPLES) as $row) {
    fputcsv($csvHandle, $row, ',', '"', '\\');
}
rewind($csvHandle);
$csv = (string) stream_get_contents($csvHandle);
fclose($csvHandle);
foreach ([__DIR__ . '/students-template.csv', $projectRoot . '/public/template-import-siswa.csv'] as $target) {
    file_put_contents($target, $csv);
    printf("CSV   %-56s %6d bytes\n", $target, strlen($csv));
}

/**
 * Template import peserta ujian (?page=exam_manage&id=X -> panel Peserta ujian).
 * Cukup satu kolom pengenal per baris: username, nama, atau kelas.
 */
$participantHeaders = ['username', 'nama', 'kelas'];
$participantExamples = [
    ['budi01', 'Budi Santoso', 'X IPA 1'],
    ['siti01', 'Siti Aminah', 'X IPA 1'],
    ['', '', 'X IPA 2'],
];

$participantXlsx = build_xlsx($participantHeaders, $participantExamples);
foreach ([__DIR__ . '/participants-template.xlsx', $projectRoot . '/public/template-import-peserta.xlsx'] as $target) {
    if (file_put_contents($target, $participantXlsx) === false) {
        throw new RuntimeException('Gagal menulis ' . $target);
    }
    printf("XLSX  %-56s %6d bytes\n", $target, strlen($participantXlsx));
}

$participantCsvHandle = fopen('php://temp', 'wb');
foreach (array_merge([$participantHeaders], $participantExamples) as $row) {
    fputcsv($participantCsvHandle, $row, ',', '"', '\\');
}
rewind($participantCsvHandle);
$participantCsv = (string) stream_get_contents($participantCsvHandle);
fclose($participantCsvHandle);
foreach ([__DIR__ . '/participants-template.csv', $projectRoot . '/public/template-import-peserta.csv'] as $target) {
    file_put_contents($target, $participantCsv);
    printf("CSV   %-56s %6d bytes\n", $target, strlen($participantCsv));
}

