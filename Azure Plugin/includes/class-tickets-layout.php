<?php
/**
 * Venue seating layouts: the JSON drawn in the venue designer, stored in
 * the venue's `_azure_seating_layout` meta.
 *
 * Block types:
 *   rectangle          reserved seats in rows (A, B, ...) numbered
 *                      startSeat..startSeat+seatsPerRow-1, so the two halves
 *                      of a row split by an aisle can carry on one numbering
 *   general_admission  unnumbered places up to a capacity
 *   area               drawn on the map, never sold (e.g. a gym mat)
 *   stage, label, aisle  decoration
 *
 * A rectangle's `seatTypes` map marks single seats, keyed "<row>-<number>":
 * "accessible" (wheelchair space), "companion" (the seat beside one) or
 * "none" (no seat there). `angle`, `stagger` and `hideNumbers` (no seat
 * numbers above the first row, for a block continuing the one above) only
 * change the drawing.
 *
 * Section ids are part of every hold, sale and ticket, so sanitize() keeps
 * them exactly as saved and only invents one for a block that has none.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Layout {

    const SELLABLE = array('rectangle', 'general_admission');
    const TYPES = array('rectangle', 'general_admission', 'area', 'stage', 'label', 'aisle');
    const SEAT_ACCESSIBLE = 'accessible';
    const SEAT_COMPANION = 'companion';
    const SEAT_NONE = 'none';
    const MAX_ROWS = 26;
    const MAX_SEATS_PER_ROW = 100;
    const MAX_SEAT_NUMBER = 999;
    const MAX_ANGLE = 45;
    const MAX_GA_CAPACITY = 10000;

    public static function empty_layout() {
        return array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array());
    }

    /**
     * @param string|array|null $raw Stored JSON or an already-decoded layout.
     */
    public static function decode($raw) {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? self::sanitize($raw) : self::empty_layout();
    }

    public static function for_venue($venue_id) {
        return self::decode(get_post_meta((int) $venue_id, '_azure_seating_layout', true));
    }

    /**
     * Whitelist fields and clamp numbers. Text is stored as plain text; every
     * renderer escapes it.
     */
    public static function sanitize(array $in) {
        $canvas = is_array($in['canvas'] ?? null) ? $in['canvas'] : array();
        $out = array(
            'canvas' => array(
                'width'  => self::clamp($canvas['width'] ?? 800, 300, 3000),
                'height' => self::clamp($canvas['height'] ?? 600, 200, 3000),
            ),
            'blocks' => array(),
        );
        $used = array();
        foreach ((array) ($in['blocks'] ?? array()) as $i => $b) {
            if (!is_array($b) || !in_array($b['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($b['id'] ?? ''));
            $id = substr($id, 0, 40);
            if ($id === '' || isset($used[$id])) {
                $n = (int) $i + 1;
                do {
                    $id = 'b' . $n++;
                } while (isset($used[$id]));
            }
            $used[$id] = true;

            $block = array(
                'id'     => $id,
                'type'   => $b['type'],
                'x'      => self::clamp($b['x'] ?? 0, 0, 3000),
                'y'      => self::clamp($b['y'] ?? 0, 0, 3000),
                'width'  => self::clamp($b['width'] ?? 200, 10, 3000),
                'height' => self::clamp($b['height'] ?? 80, 10, 3000),
                'color'  => self::color($b['color'] ?? ''),
            );
            switch ($b['type']) {
                case 'rectangle':
                    $block['name'] = self::text($b['name'] ?? 'Section');
                    $block['price'] = self::money($b['price'] ?? 0);
                    $block['startRow'] = self::row_letter($b['startRow'] ?? 'A');
                    $max_rows = self::MAX_ROWS - (ord($block['startRow']) - ord('A'));
                    $block['rowCount'] = self::clamp($b['rowCount'] ?? 2, 1, $max_rows);
                    $block['seatsPerRow'] = self::clamp($b['seatsPerRow'] ?? 10, 1, self::MAX_SEATS_PER_ROW);
                    $block['startSeat'] = self::clamp($b['startSeat'] ?? 1, 1, self::MAX_SEAT_NUMBER - $block['seatsPerRow'] + 1);
                    $block['angle'] = self::clamp($b['angle'] ?? 0, -self::MAX_ANGLE, self::MAX_ANGLE);
                    $block['stagger'] = !empty($b['stagger']);
                    $block['hideNumbers'] = !empty($b['hideNumbers']);
                    $block['seatTypes'] = self::seat_types($b['seatTypes'] ?? array(), $block);
                    break;
                case 'general_admission':
                    $block['name'] = self::text($b['name'] ?? 'General Admission');
                    $block['price'] = self::money($b['price'] ?? 0);
                    $block['capacity'] = self::clamp($b['capacity'] ?? 100, 1, self::MAX_GA_CAPACITY);
                    break;
                case 'area':
                case 'stage':
                    $block['label'] = self::text($b['label'] ?? ($b['type'] === 'stage' ? 'STAGE' : 'Area'));
                    break;
                case 'label':
                    $block['text'] = self::text($b['text'] ?? 'Label');
                    $block['fontSize'] = self::clamp($b['fontSize'] ?? 14, 8, 72);
                    break;
                case 'aisle':
                    $block['orientation'] = ($b['orientation'] ?? '') === 'vertical' ? 'vertical' : 'horizontal';
                    break;
            }
            $out['blocks'][] = $block;
        }
        return $out;
    }

    /**
     * @return array<string,array> Sellable blocks keyed by section id, in drawing order.
     */
    public static function sections(array $layout) {
        $out = array();
        foreach ($layout['blocks'] as $b) {
            if (in_array($b['type'], self::SELLABLE, true)) {
                $out[$b['id']] = $b;
            }
        }
        return $out;
    }

    public static function row_labels(array $block) {
        $start = ord($block['startRow'] ?? 'A');
        $rows = array();
        for ($i = 0; $i < (int) ($block['rowCount'] ?? 0); $i++) {
            $rows[] = chr($start + $i);
        }
        return $rows;
    }

    /**
     * @return int[] The seat numbers along each row of a rectangle.
     */
    public static function seat_numbers(array $block) {
        $first = max(1, (int) ($block['startSeat'] ?? 1));
        return range($first, $first + max(1, (int) $block['seatsPerRow']) - 1);
    }

    /**
     * @return array<string,string> seat key ("B-7") => "normal" | "accessible" | "companion".
     */
    public static function seats(array $block) {
        if (($block['type'] ?? '') !== 'rectangle') {
            return array();
        }
        $types = $block['seatTypes'] ?? array();
        $out = array();
        foreach (self::row_labels($block) as $row) {
            foreach (self::seat_numbers($block) as $n) {
                $key = $row . '-' . $n;
                $type = $types[$key] ?? '';
                if ($type === self::SEAT_NONE) {
                    continue;
                }
                $out[$key] = in_array($type, array(self::SEAT_ACCESSIBLE, self::SEAT_COMPANION), true) ? $type : 'normal';
            }
        }
        return $out;
    }

    /**
     * @return string|null "normal" / "accessible" / "companion", or null when the key is not a seat in this block.
     */
    public static function seat_type(array $block, $seat_key) {
        $seats = self::seats($block);
        return $seats[(string) $seat_key] ?? null;
    }

    public static function section_capacity(array $block) {
        if ($block['type'] === 'general_admission') {
            return (int) $block['capacity'];
        }
        return count(self::seats($block));
    }

    public static function capacity(array $layout) {
        $total = 0;
        foreach (self::sections($layout) as $block) {
            $total += self::section_capacity($block);
        }
        return $total;
    }

    /**
     * "Row B, Seat 7" style label for tickets, carts and emails.
     */
    public static function seat_label(array $block, $seat_key) {
        if ($block['type'] === 'general_admission') {
            return $block['name'];
        }
        $parts = explode('-', (string) $seat_key, 2);
        /* translators: 1: section name, 2: row letter, 3: seat number */
        $label = sprintf(__('%1$s · Row %2$s, Seat %3$s', 'azure-plugin'), $block['name'], $parts[0], $parts[1] ?? '');
        $type = self::seat_type($block, $seat_key);
        if ($type === self::SEAT_ACCESSIBLE) {
            $label .= ' ' . __('(wheelchair space)', 'azure-plugin');
        } elseif ($type === self::SEAT_COMPANION) {
            $label .= ' ' . __('(companion seat)', 'azure-plugin');
        }
        return $label;
    }

    private static function seat_types($raw, array $block) {
        if (!is_array($raw)) {
            return array();
        }
        $rows = array_flip(self::row_labels($block));
        $first = (int) $block['startSeat'];
        $last = $first + (int) $block['seatsPerRow'] - 1;
        $out = array();
        foreach ($raw as $key => $type) {
            if (!in_array($type, array(self::SEAT_ACCESSIBLE, self::SEAT_COMPANION, self::SEAT_NONE), true)) {
                continue;
            }
            if (!preg_match('/^([A-Z])-(\d{1,3})$/', (string) $key, $m)) {
                continue;
            }
            if (!isset($rows[$m[1]]) || (int) $m[2] < $first || (int) $m[2] > $last) {
                continue;
            }
            $out[$m[1] . '-' . (int) $m[2]] = $type;
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    private static function clamp($v, $min, $max) {
        return max($min, min($max, (int) round((float) $v)));
    }

    private static function money($v) {
        return round(max(0, min(100000, (float) $v)), 2);
    }

    private static function text($v) {
        $v = trim(wp_strip_all_tags((string) $v));
        return function_exists('mb_substr') ? mb_substr($v, 0, 80) : substr($v, 0, 80);
    }

    private static function color($v) {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower($v) : '';
    }

    private static function row_letter($v) {
        $v = strtoupper(substr((string) $v, 0, 1));
        return ($v >= 'A' && $v <= 'Z') ? $v : 'A';
    }
}
