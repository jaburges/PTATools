<?php
/**
 * Parse the three-column LWSD volunteer xlsx (First / Last / Expiration Date).
 *
 * Run: php tests/test-lwsd-volunteer-xlsx.php
 */

require_once __DIR__ . '/wp-shim.php';
if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-lwsd-volunteer.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-lwsd-volunteer-xlsx.php';

/**
 * Write a minimal OOXML workbook with sharedStrings + sheet1.
 *
 * @param string $path Destination .xlsx path.
 * @param array  $rows Roster-shaped rows: first, last, expires_on (string or Excel serial number).
 *                     Optional header overrides via $rows['_headers'].
 */
function lwsd_write_sample_xlsx($path, $rows) {
    $headers = array('First Name', 'Last Name', 'Expiration Date');
    if (isset($rows['_headers']) && is_array($rows['_headers'])) {
        $headers = $rows['_headers'];
        unset($rows['_headers']);
    }

    $strings = array();
    $string_index = function ($text) use (&$strings) {
        $text = (string) $text;
        $idx = array_search($text, $strings, true);
        if ($idx === false) {
            $idx = count($strings);
            $strings[] = $text;
        }
        return $idx;
    };

    foreach ($headers as $header) {
        $string_index($header);
    }

    $sheet_rows = array();
    $header_cells = '';
    $cols = array('A', 'B', 'C');
    foreach ($headers as $i => $header) {
        $idx = $string_index($header);
        $ref = $cols[$i] . '1';
        $header_cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
    }
    $sheet_rows[] = '<row r="1">' . $header_cells . '</row>';

    $r = 2;
    foreach (array_values($rows) as $row) {
        $first = isset($row['first']) ? $row['first'] : '';
        $last = isset($row['last']) ? $row['last'] : '';
        $expires = isset($row['expires_on']) ? $row['expires_on'] : '';

        $a = $string_index($first);
        $b = $string_index($last);
        if (is_int($expires) || is_float($expires)) {
            $c_cell = '<c r="C' . $r . '"><v>' . $expires . '</v></c>';
        } else {
            $c = $string_index($expires);
            $c_cell = '<c r="C' . $r . '" t="s"><v>' . $c . '</v></c>';
        }

        $sheet_rows[] = '<row r="' . $r . '">'
            . '<c r="A' . $r . '" t="s"><v>' . $a . '</v></c>'
            . '<c r="B' . $r . '" t="s"><v>' . $b . '</v></c>'
            . $c_cell
            . '</row>';
        $r++;
    }

    $sst_items = '';
    foreach ($strings as $text) {
        $sst_items .= '<si><t>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }

    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . count($strings) . '" uniqueCount="' . count($strings) . '">'
        . $sst_items
        . '</sst>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $sheet_rows) . '</sheetData>'
        . '</worksheet>';

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (file_exists($path)) {
        unlink($path);
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not write sample xlsx: ' . $path);
    }
    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

$t = new TestRunner('LWSD volunteer xlsx parser');

$fixture_dir = __DIR__ . '/fixtures';
$sample = $fixture_dir . '/lwsd-volunteer-sample.xlsx';
lwsd_write_sample_xlsx($sample, array(
    array('first' => 'steven', 'last' => 'alvarez', 'expires_on' => '2027-10-30'),
    array('first' => 'Lindsay', 'last' => 'Allan', 'expires_on' => '2028-08-21'),
    array('first' => '', 'last' => 'Skipme', 'expires_on' => '2027-01-01'),
    array('first' => 'Skipme', 'last' => '', 'expires_on' => '2027-01-01'),
));

$rows = Azure_Lwsd_Volunteer_Xlsx::parse_file($sample);
$t->equals(2, count($rows), 'two named data rows; empty first/last skipped');
$t->equals('steven', $rows[0]['first'], 'first row first name');
$t->equals('alvarez', $rows[0]['last'], 'first row last name');
$t->equals('2027-10-30', $rows[0]['expires_on'], 'normalized expiry from date string');
$t->equals('Lindsay', $rows[1]['first'], 'second row first name');
$t->equals('Allan', $rows[1]['last'], 'second row last name');
$t->equals('2028-08-21', $rows[1]['expires_on'], 'second expiry Y-m-d');

$missing_threw = false;
try {
    Azure_Lwsd_Volunteer_Xlsx::parse_file($fixture_dir . '/does-not-exist-lwsd.xlsx');
} catch (InvalidArgumentException $e) {
    $missing_threw = true;
}
$t->check($missing_threw, 'missing file throws InvalidArgumentException');

$headerless = $fixture_dir . '/lwsd-headerless.zip';
if (file_exists($headerless)) {
    unlink($headerless);
}
$zip = new ZipArchive();
$zip->open($headerless, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('readme.txt', 'not an xlsx with headers');
$zip->close();

$headerless_threw = false;
try {
    Azure_Lwsd_Volunteer_Xlsx::parse_file($headerless);
} catch (InvalidArgumentException $e) {
    $headerless_threw = true;
}
$t->check($headerless_threw, 'headerless zip throws InvalidArgumentException');

$epoch = new DateTimeImmutable('1899-12-30');
$target = new DateTimeImmutable('2026-09-13');
$serial = (int) round(($target->getTimestamp() - $epoch->getTimestamp()) / 86400);
$serial_path = $fixture_dir . '/lwsd-serial.xlsx';
lwsd_write_sample_xlsx($serial_path, array(
    '_headers' => array('first name', 'last name', 'Expiry'),
    array('first' => 'Pat', 'last' => 'Smith', 'expires_on' => $serial),
));
$serial_rows = Azure_Lwsd_Volunteer_Xlsx::parse_file($serial_path);
$t->equals(1, count($serial_rows), 'Expiry alias header accepted');
$t->equals('2026-09-13', $serial_rows[0]['expires_on'], 'Excel serial uses 1899-12-30 epoch');

exit($t->finish());
