<?php
/**
 * Parse the district three-column volunteer workbook without a spreadsheet library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Lwsd_Volunteer_Xlsx {

    /**
     * @param string $path Path to an .xlsx file.
     * @return array<int,array{first:string,last:string,expires_on:string}>
     */
    public static function parse_file($path) {
        $path = (string) $path;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('LWSD volunteer file is missing or unreadable.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('LWSD volunteer file is not a zip workbook.');
        }

        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheet_xml === false || $sheet_xml === '') {
            throw new InvalidArgumentException('LWSD volunteer workbook has no worksheet.');
        }

        $strings = self::parse_shared_strings($shared_xml === false ? '' : $shared_xml);
        $grid = self::sheet_rows($sheet_xml, $strings);
        if ($grid === array()) {
            throw new InvalidArgumentException('LWSD volunteer workbook is empty.');
        }

        $map = self::header_map($grid[0]);
        if ($map === null) {
            throw new InvalidArgumentException('LWSD volunteer workbook is missing First / Last / Expiration Date headers.');
        }

        $out = array();
        $count = count($grid);
        for ($i = 1; $i < $count; $i++) {
            $row = $grid[$i];
            $first = isset($row[$map['first']]) ? $row[$map['first']] : '';
            $last = isset($row[$map['last']]) ? $row[$map['last']] : '';
            $expires_raw = isset($row[$map['expires']]) ? $row[$map['expires']] : '';

            if (Azure_Lwsd_Volunteer::normalize_name($first) === '' || Azure_Lwsd_Volunteer::normalize_name($last) === '') {
                continue;
            }

            $out[] = array(
                'first'      => trim((string) $first),
                'last'       => trim((string) $last),
                'expires_on' => self::cell_expiry($expires_raw),
            );
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function cell_expiry($value) {
        $parsed = Azure_Lwsd_Volunteer::parse_expiry($value);
        if ($parsed !== '') {
            return $parsed;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return self::excel_serial_to_ymd((float) $value);
        }

        return '';
    }

    /**
     * Excel's date serial origin (1899-12-30) as used by xlsx.
     */
    private static function excel_serial_to_ymd($serial) {
        $days = (int) floor((float) $serial);
        $epoch = new DateTimeImmutable('1899-12-30');
        return $epoch->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /**
     * @return array<int,string>
     */
    private static function parse_shared_strings($xml_string) {
        if ($xml_string === '') {
            return array();
        }

        $xml = self::load_xml($xml_string);
        if ($xml === false) {
            return array();
        }

        $nodes = $xml->xpath('//si');
        if ($nodes === false) {
            return array();
        }

        $out = array();
        foreach ($nodes as $si) {
            $texts = $si->xpath('.//t');
            $buf = '';
            if ($texts !== false) {
                foreach ($texts as $t) {
                    $buf .= (string) $t;
                }
            }
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * @param array<int,string> $strings
     * @return array<int,array<int,string>>
     */
    private static function sheet_rows($sheet_xml, array $strings) {
        $xml = self::load_xml($sheet_xml);
        if ($xml === false) {
            return array();
        }

        $row_nodes = $xml->xpath('//sheetData/row');
        if ($row_nodes === false) {
            return array();
        }

        $rows = array();
        foreach ($row_nodes as $row_node) {
            $cells = $row_node->xpath('./c');
            $row = array();
            if ($cells === false) {
                $rows[] = $row;
                continue;
            }
            foreach ($cells as $cell) {
                $ref = (string) $cell['r'];
                $col = self::column_index($ref);
                if ($col < 0) {
                    continue;
                }
                $row[$col] = self::cell_text($cell, $strings);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int,string> $strings
     */
    private static function cell_text(SimpleXMLElement $cell, array $strings) {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $vs = $cell->xpath('./v');
            $idx = ($vs !== false && isset($vs[0])) ? (int) (string) $vs[0] : -1;
            return isset($strings[$idx]) ? $strings[$idx] : '';
        }

        if ($type === 'inlineStr') {
            $ts = $cell->xpath('.//t');
            $buf = '';
            if ($ts !== false) {
                foreach ($ts as $t) {
                    $buf .= (string) $t;
                }
            }
            return $buf;
        }

        $vs = $cell->xpath('./v');
        if ($vs !== false && isset($vs[0])) {
            return (string) $vs[0];
        }

        return '';
    }

    /**
     * @param array<int,string> $header_row
     * @return array{first:int,last:int,expires:int}|null
     */
    private static function header_map(array $header_row) {
        $first = null;
        $last = null;
        $expires = null;

        foreach ($header_row as $col => $label) {
            $role = self::header_role($label);
            if ($role === 'first') {
                $first = (int) $col;
            } elseif ($role === 'last') {
                $last = (int) $col;
            } elseif ($role === 'expires') {
                $expires = (int) $col;
            }
        }

        if ($first === null || $last === null || $expires === null) {
            return null;
        }

        return array(
            'first'   => $first,
            'last'    => $last,
            'expires' => $expires,
        );
    }

    /**
     * @param string $label
     */
    private static function header_role($label) {
        $h = Azure_Lwsd_Volunteer::normalize_name($label);
        if ($h === 'first name') {
            return 'first';
        }
        if ($h === 'last name') {
            return 'last';
        }
        if ($h === 'expiration date' || $h === 'expiry') {
            return 'expires';
        }
        return '';
    }

    /**
     * Strip default OOXML namespaces so child xpath works without prefixes.
     *
     * @return SimpleXMLElement|false
     */
    private static function load_xml($xml_string) {
        $xml_string = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', (string) $xml_string);
        return simplexml_load_string($xml_string);
    }

    /**
     * @param string $ref Cell reference like C12.
     */
    private static function column_index($ref) {
        if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
            return -1;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = ($n * 26) + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }
}
