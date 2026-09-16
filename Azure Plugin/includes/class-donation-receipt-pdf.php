<?php
/**
 * Minimal PDF writer for donation receipts. Helvetica / WinAnsi only —
 * no TCPDF/Dompdf dependency.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Donation_Receipt_Pdf {

    public static function build($data) {
        $pdf = new Azure_Simple_Pdf();
        $org = isset($data['org']) ? (string) $data['org'] : '';
        $pdf->heading($org !== '' ? $org : 'Donation Receipt');
        if ($org !== '') {
            $pdf->subtitle('Donation Receipt');
        }
        $pdf->spacer(10);
        $pdf->kv('Order', isset($data['order_number']) ? '#' . ltrim((string) $data['order_number'], '#') : '');
        $pdf->kv('Date', isset($data['date']) ? (string) $data['date'] : '');
        $pdf->kv('Donor', isset($data['donor']) ? (string) $data['donor'] : '');
        $pdf->kv('Email', isset($data['email']) ? (string) $data['email'] : '');
        $pdf->spacer(14);
        $pdf->section_label('Donation');
        $lines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : array();
        foreach ($lines as $line) {
            $name = isset($line['name']) ? (string) $line['name'] : '';
            $total = isset($line['total']) ? (float) $line['total'] : 0.0;
            $pdf->money_row($name, self::money($total));
        }
        $pdf->rule();
        $total = isset($data['total']) ? (float) $data['total'] : 0.0;
        $pdf->money_row('Donation total', self::money($total), true);
        $pdf->spacer(18);
        $footer = isset($data['footer']) ? (string) $data['footer'] : '';
        if ($footer !== '') {
            $pdf->body($footer);
        }
        return $pdf->output();
    }

    private static function money($amount) {
        if (class_exists('Azure_Donations_Module')) {
            return Azure_Donations_Module::format_receipt_money($amount);
        }
        return '$' . number_format((float) $amount, 2);
    }
}

class Azure_Simple_Pdf {
    private $objects = array();
    private $y = 730;
    private $commands = '';
    private $page_w = 612;
    private $page_h = 792;
    private $margin = 54;
    private $content_w = 504;

    public function heading($text) {
        $this->text($text, 18, true, 22);
    }

    public function subtitle($text) {
        $this->text($text, 13, false, 18);
    }

    public function section_label($text) {
        $this->text($text, 11, true, 16);
    }

    public function kv($label, $value) {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }
        $this->ensure_space(14);
        $this->commands .= "BT /F2 10 Tf " . $this->margin . " " . $this->y . " Td (" . $this->escape($label . ':') . ") Tj ET\n";
        $this->commands .= "BT /F1 10 Tf " . ($this->margin + 70) . " " . $this->y . " Td (" . $this->escape($value) . ") Tj ET\n";
        $this->y -= 14;
    }

    public function money_row($name, $amount, $bold = false) {
        $this->ensure_space(16);
        $font = $bold ? '/F2' : '/F1';
        $size = $bold ? 11 : 10;
        $name_width = $this->content_w - 80;
        $lines = $this->wrap($name, $name_width, $size);
        $first = true;
        foreach ($lines as $line) {
            $this->ensure_space(14);
            $this->commands .= "BT {$font} {$size} Tf " . $this->margin . " " . $this->y . " Td (" . $this->escape($line) . ") Tj ET\n";
            if ($first) {
                $aw = $this->string_width($amount, $size);
                $ax = $this->margin + $this->content_w - $aw;
                $this->commands .= "BT {$font} {$size} Tf {$ax} " . $this->y . " Td (" . $this->escape($amount) . ") Tj ET\n";
                $first = false;
            }
            $this->y -= 14;
        }
    }

    public function rule() {
        $this->ensure_space(10);
        $y = $this->y + 4;
        $x2 = $this->margin + $this->content_w;
        $this->commands .= $this->margin . " {$y} m {$x2} {$y} l S\n";
        $this->y -= 6;
    }

    public function body($text) {
        $size = 10;
        $paragraphs = preg_split("/\n\s*\n/", str_replace(array("\r\n", "\r"), "\n", (string) $text));
        foreach ($paragraphs as $i => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $lines = $this->wrap($para, $this->content_w, $size);
            foreach ($lines as $line) {
                $this->ensure_space(14);
                $this->commands .= "BT /F1 {$size} Tf " . $this->margin . " " . $this->y . " Td (" . $this->escape($line) . ") Tj ET\n";
                $this->y -= 13;
            }
            if ($i < count($paragraphs) - 1) {
                $this->y -= 8;
            }
        }
    }

    public function spacer($px) {
        $this->y -= (float) $px;
    }

    public function output() {
        $content = "q\n0.15 0.15 0.15 RG\n0.15 0.15 0.15 rg\n1 w\n" . $this->commands . "Q\n";
        $stream = "<<" . "/Length " . strlen($content) . ">>\nstream\n" . $content . "endstream";

        $this->objects = array();
        $this->objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $this->objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $this->objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->page_w} {$this->page_h}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>";
        $this->objects[] = $stream;
        $this->objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $this->objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $out = "%PDF-1.4\n";
        $offsets = array(0);
        foreach ($this->objects as $i => $obj) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($this->objects) + 1;
        $out .= "xref\n0 {$count}\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }

    private function text($text, $size, $bold, $advance) {
        $this->ensure_space($advance);
        $font = $bold ? '/F2' : '/F1';
        $this->commands .= "BT {$font} {$size} Tf " . $this->margin . " " . $this->y . " Td (" . $this->escape($text) . ") Tj ET\n";
        $this->y -= $advance;
    }

    private function ensure_space($needed) {
        if ($this->y - $needed < 54) {
            $this->y = 54;
        }
    }

    private function wrap($text, $max_width, $size) {
        $text = trim(preg_replace('/[ \t]+/', ' ', (string) $text));
        if ($text === '') {
            return array('');
        }
        $words = explode(' ', $text);
        $lines = array();
        $current = '';
        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current . ' ' . $word;
            if ($this->string_width($trial, $size) <= $max_width) {
                $current = $trial;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    private function string_width($text, $size) {
        $text = $this->to_winansi($text);
        $w = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $w += $this->char_width(ord($text[$i]));
        }
        return $w * $size / 1000;
    }

    private function char_width($code) {
        static $widths = null;
        if ($widths === null) {
            $widths = array_fill(0, 256, 556);
            $map = array(
                32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
                39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
                46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556,
                53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278,
                60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667,
                67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
                74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
                81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
                88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469,
                95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
                102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222,
                109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500,
                116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
            );
            foreach ($map as $k => $v) {
                $widths[$k] = $v;
            }
        }
        return isset($widths[$code]) ? $widths[$code] : 556;
    }

    private function escape($text) {
        $text = $this->to_winansi($text);
        return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
    }

    private function to_winansi($text) {
        $text = (string) $text;
        $map = array(
            "\xC2\xA0" => ' ',
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
        );
        $text = strtr($text, $map);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
    }
}
