<?php
/**
 * includes/xlsx_writer.php
 *
 * A minimal, dependency-free .xlsx (Office Open XML spreadsheet) writer.
 * Requires the php-zip extension (bundled/commonly enabled in most PHP
 * distributions). No Composer/third-party library needed.
 *
 * Every cell is written as an inline string (t="inlineStr"), which keeps
 * the implementation simple (no shared-strings table to manage) and is a
 * fully valid part of the OOXML spec — Excel, LibreOffice, and Google
 * Sheets all read it correctly.
 */

declare(strict_types=1);

/**
 * Stream a single-sheet .xlsx file to the browser as a download.
 *
 * @param string $filename   Suggested download filename (with .xlsx extension)
 * @param string $sheetTitle Title shown in a merged header row at the top of the sheet
 * @param array  $metaLines  Extra lines shown under the title (e.g. "Generated: ...")
 * @param array  $headers    Column header labels
 * @param array  $rows       Array of associative or indexed arrays; each inner value becomes one cell
 */
function stream_xlsx(string $filename, string $sheetTitle, array $metaLines, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('The php-zip extension is required to export Excel files. Please enable it on the server.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>'
    );

    $safeSheetName = substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheetTitle) ?: 'Report', 0, 31);
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . xlsx_escape($safeSheetName) . '" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    // ---- Build sheet rows: title, meta lines, blank row, header row, data rows ----
    $colCount = max(count($headers), 1);
    $sheetRows = [];
    $sheetRows[] = [$sheetTitle];
    foreach ($metaLines as $line) {
        $sheetRows[] = [$line];
    }
    $sheetRows[] = [];
    $sheetRows[] = $headers;
    foreach ($rows as $row) {
        $sheetRows[] = array_values($row);
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<cols>';
    for ($c = 1; $c <= $colCount; $c++) {
        $xml .= '<col min="' . $c . '" max="' . $c . '" width="22" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    foreach ($sheetRows as $rowIndex => $row) {
        $r = $rowIndex + 1;
        $xml .= '<row r="' . $r . '">';
        foreach ($row as $colIndex => $value) {
            $cellRef = xlsx_col_letter($colIndex + 1) . $r;
            $text = $value === null ? '' : (string)$value;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape($text) . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

/** Escape text for safe inclusion in XML content (XLSX cell text). */
function xlsx_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Convert a 1-based column index to its spreadsheet letter (1 -> A, 27 -> AA). */
function xlsx_col_letter(int $index): string
{
    $letter = '';
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }
    return $letter;
}
