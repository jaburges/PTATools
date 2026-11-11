<?php
/**
 * Tickets → Visualizer: one performance's seat map showing who has each
 * seat, with an outline around the seats bought together, and a full-size
 * printout tiled across four landscape pages for the door (checking people
 * against their seats, and selling the free ones by hand).
 *
 * Read-only: nothing here changes a hold, a sale or a ticket.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Visualizer {

    /** Buyer names and emails are shown, so shop managers and admins only. */
    const CAP = 'manage_woocommerce';
    const PRINT_ACTION = 'azure_tickets_seat_print';

    /** Landscape sheet sizes in mm. */
    const PAPERS = array(
        'letter'  => array('label' => 'Letter (11 × 8.5″)', 'w' => 279.4, 'h' => 215.9),
        'tabloid' => array('label' => 'Tabloid (17 × 11″)', 'w' => 431.8, 'h' => 279.4),
        'a4'      => array('label' => 'A4', 'w' => 297, 'h' => 210),
        'a3'      => array('label' => 'A3', 'w' => 420, 'h' => 297),
    );
    /** Unprinted border on every sheet, mm; inner edges are trimmed to the corner marks. */
    const MARGIN_MM = 8;
    /** Title band across the top of the assembled poster, mm. */
    const HEADER_MM = 26;
    const PX_PER_MM = 3.7795275591;

    const STATES = array('sold', 'checked_in', 'ordered', 'held');

    // ── Events and performances ─────────────────────────────────────────

    /**
     * Ticket products that have a venue, with their performances, soonest
     * upcoming first and finished ones after (most recent first).
     *
     * @return array<int,array{name:string,performances:array<int,string>}>
     */
    public static function events() {
        if (!function_exists('wc_get_products')) {
            return array();
        }
        $events = array();
        $seen = array();
        $sort = array();
        $now = Azure_Tickets_Config::now();
        foreach ((array) wc_get_products(array('type' => 'ticket', 'status' => array('publish', 'private', 'draft'), 'limit' => 200)) as $p) {
            $id = (int) $p->get_id();
            if (isset($seen[$id]) || !Azure_Tickets_Config::venue_id($id)) {
                continue;
            }
            $performances = array();
            $last = 0;
            $next = 0;
            foreach (Azure_Tickets_Config::performances($id) as $pid) {
                $seen[$pid] = true;
                $performances[$pid] = self::performance_label($pid);
                $ts = Azure_Tickets_Config::event_ts($pid);
                $last = max($last, $ts);
                if ($ts >= $now - 6 * HOUR_IN_SECONDS && (!$next || $ts < $next)) {
                    $next = $ts;
                }
            }
            if (!$performances) {
                continue;
            }
            $events[$id] = array('name' => $p->get_name(), 'performances' => $performances);
            $sort[$id] = $next ? array(0, $next) : array(1, -$last);
        }
        uksort($events, function ($a, $b) use ($sort) {
            return $sort[$a] <=> $sort[$b] ?: $a - $b;
        });
        return $events;
    }

    public static function performance_label($performance_id) {
        $ts = Azure_Tickets_Config::event_ts($performance_id);
        if ($ts) {
            return wp_date('D j M, g:ia', $ts);
        }
        $p = function_exists('wc_get_product') ? wc_get_product($performance_id) : null;
        return $p ? $p->get_name() : '#' . (int) $performance_id;
    }

    /**
     * The performance to show: the one asked for if it is one, else the
     * first upcoming performance of the first event.
     */
    public static function pick(array $events, $wanted) {
        foreach ($events as $event_id => $e) {
            if (isset($e['performances'][(int) $wanted])) {
                return array($event_id, (int) $wanted);
            }
        }
        foreach ($events as $event_id => $e) {
            if ((int) $wanted === (int) $event_id) {
                return array($event_id, self::next_performance($e['performances']));
            }
        }
        if (!$events) {
            return array(0, 0);
        }
        $event_id = (int) array_key_first($events);
        return array($event_id, self::next_performance($events[$event_id]['performances']));
    }

    private static function next_performance(array $performances) {
        $now = Azure_Tickets_Config::now();
        foreach (array_keys($performances) as $pid) {
            if (Azure_Tickets_Config::event_ts($pid) >= $now - 6 * HOUR_IN_SECONDS) {
                return (int) $pid;
            }
        }
        return (int) array_key_first($performances);
    }

    /**
     * @param int[] $product_ids
     * @return array<int,int> performance => seats sold (including checked in)
     */
    public static function sold_counts(array $product_ids) {
        global $wpdb;
        $ids = array_filter(array_map('intval', $product_ids));
        if (!$ids) {
            return array();
        }
        $rows = (array) $wpdb->get_results(
            'SELECT product_id, COUNT(*) AS n FROM ' . Azure_Tickets_Inventory::table()
            . " WHERE status = 'sold' AND product_id IN (" . implode(',', $ids) . ') GROUP BY product_id',
            ARRAY_A
        );
        $out = array_fill_keys($ids, 0);
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = (int) $r['n'];
        }
        return $out;
    }

    // ── Who has each seat ───────────────────────────────────────────────

    /**
     * Inventory and ticket rows for the performance, combined.
     */
    public static function occupancy($product_id, array $sections) {
        global $wpdb;
        $inventory = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT section_id, seat_key, status, hold_key, user_id, order_id, expires_at FROM ' . Azure_Tickets_Inventory::table()
            . " WHERE product_id = %d AND (status IN ('sold', 'ordered') OR (status = 'held' AND expires_at >= %s)) ORDER BY id",
            (int) $product_id, gmdate('Y-m-d H:i:s', Azure_Tickets_Inventory::$now ?? time())
        ), ARRAY_A);
        $tickets = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, section_id, row_letter, seat_number, attendee_name, status, checked_in_at FROM {$wpdb->prefix}azure_tickets
             WHERE product_id = %d AND status IN ('active', 'used') ORDER BY id",
            (int) $product_id
        ), ARRAY_A);
        $general = array();
        foreach ($sections as $sid => $s) {
            if ($s['type'] === 'general_admission') {
                $general[] = (string) $sid;
            }
        }
        return self::merge($inventory, $tickets, $general);
    }

    /**
     * @param array[]  $inventory rows: section_id, seat_key, status, hold_key, user_id, order_id, expires_at (UTC)
     * @param array[]  $tickets   rows: order_id, section_id, row_letter, seat_number, attendee_name, status, checked_in_at
     * @param string[] $general   general admission section ids
     * @return array{seats: array<string,array<string,array>>, general: array<string,array>}
     *         seats: section => seat key => state, order, name, group, until, user, checked_in_at.
     *         group is shared by seats bought (or held) together: "o<order>" or "h<n>".
     */
    public static function merge(array $inventory, array $tickets, array $general = array()) {
        $general = array_flip(array_map('strval', $general));
        $seats = array();
        $ga = array();
        $holds = array();
        foreach ($inventory as $r) {
            $sid = (string) $r['section_id'];
            $status = (string) $r['status'];
            if (!in_array($status, array('sold', 'ordered', 'held'), true)) {
                continue;
            }
            $order = (int) $r['order_id'];
            if ($status === 'held') {
                $hk = (string) $r['hold_key'];
                if (!isset($holds[$hk])) {
                    $holds[$hk] = 'h' . (count($holds) + 1);
                }
                $group = $holds[$hk];
            } else {
                $group = $order ? 'o' . $order : '';
            }
            if (isset($general[$sid])) {
                $g = $ga[$sid] ?? self::empty_general();
                $g[$status]++;
                if ($order && $status !== 'held') {
                    $g['orders'][$order] = ($g['orders'][$order] ?? 0) + 1;
                }
                $ga[$sid] = $g;
                continue;
            }
            $seats[$sid][(string) $r['seat_key']] = array(
                'state'         => $status,
                'order'         => $status === 'held' ? 0 : $order,
                'name'          => '',
                'group'         => $group,
                'until'         => $status === 'held' && $r['expires_at'] ? (int) strtotime($r['expires_at'] . ' UTC') : 0,
                'user'          => (int) $r['user_id'],
                'checked_in_at' => '',
            );
        }
        foreach ($tickets as $t) {
            $sid = (string) $t['section_id'];
            $order = (int) $t['order_id'];
            $used = $t['status'] === 'used';
            if (isset($general[$sid]) || $t['row_letter'] === null || $t['row_letter'] === '') {
                if (isset($general[$sid]) && $used) {
                    $g = $ga[$sid] ?? self::empty_general();
                    $g['checked_in']++;
                    $ga[$sid] = $g;
                }
                continue;
            }
            $key = $t['row_letter'] . '-' . (int) $t['seat_number'];
            $seat = $seats[$sid][$key] ?? null;
            if ($seat && ($seat['order'] !== $order || $seat['name'] !== '')) {
                continue;
            }
            if (!$seat) {
                // A ticket with no inventory row (sold before seats were tracked there).
                $seat = array('state' => 'sold', 'order' => $order, 'name' => '', 'group' => 'o' . $order, 'until' => 0, 'user' => 0, 'checked_in_at' => '');
            }
            $seat['name'] = trim((string) $t['attendee_name']);
            if ($used) {
                $seat['state'] = 'checked_in';
                $seat['checked_in_at'] = (string) $t['checked_in_at'];
            }
            $seats[$sid][$key] = $seat;
        }
        return array('seats' => $seats, 'general' => $ga);
    }

    private static function empty_general() {
        return array('sold' => 0, 'checked_in' => 0, 'ordered' => 0, 'held' => 0, 'orders' => array());
    }

    /**
     * Seat counts for the summary line. "sold" includes the checked-in;
     * "free" is on public sale, "free_closed" is in a section switched off.
     */
    public static function stats(array $sections, array $occ) {
        $s = array('capacity' => 0, 'sold' => 0, 'checked_in' => 0, 'ordered' => 0, 'held' => 0, 'free' => 0, 'free_closed' => 0);
        foreach ($sections as $sid => $sec) {
            if ($sec['type'] === 'general_admission') {
                $g = $occ['general'][$sid] ?? self::empty_general();
                $taken = $g['sold'] + $g['ordered'] + $g['held'];
                $s['capacity'] += (int) $sec['capacity'];
                $s['sold'] += $g['sold'];
                $s['checked_in'] += $g['checked_in'];
                $s['ordered'] += $g['ordered'];
                $s['held'] += $g['held'];
                $s[$sec['enabled'] ? 'free' : 'free_closed'] += max(0, (int) $sec['capacity'] - $taken);
                continue;
            }
            foreach (Azure_Tickets_Layout::seats($sec['block']) as $key => $type) {
                $s['capacity']++;
                $state = $occ['seats'][$sid][$key]['state'] ?? '';
                if ($state === '') {
                    $s[$sec['enabled'] ? 'free' : 'free_closed']++;
                } elseif ($state === 'checked_in') {
                    $s['sold']++;
                    $s['checked_in']++;
                } else {
                    $s[$state]++;
                }
            }
        }
        return $s;
    }

    /**
     * What the tooltip and printout show about each order: buyer, contact,
     * the seats it holds here.
     *
     * @param array<int,bool> $vip order ids of cast & crew orders
     * @return array<int,array>
     */
    public static function orders(array $occ, array $sections, array $vip = array()) {
        $seats = array();
        foreach ($occ['seats'] as $sid => $by_key) {
            foreach ($by_key as $key => $seat) {
                if ($seat['order']) {
                    $seats[$seat['order']][] = str_replace('-', '', $key);
                }
            }
        }
        $ga = array();
        foreach ($occ['general'] as $sid => $g) {
            foreach ($g['orders'] as $oid => $qty) {
                $ga[$oid][] = $qty . ' × ' . ($sections[$sid]['name'] ?? $sid);
            }
        }
        $out = array();
        foreach (array_unique(array_merge(array_keys($seats), array_keys($ga))) as $oid) {
            $o = function_exists('wc_get_order') ? wc_get_order($oid) : null;
            $list = $seats[$oid] ?? array();
            natsort($list);
            $out[$oid] = array(
                'number' => $o ? (string) $o->get_order_number() : (string) $oid,
                'buyer'  => $o ? trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) : '',
                'email'  => $o ? (string) $o->get_billing_email() : '',
                'phone'  => $o ? (string) $o->get_billing_phone() : '',
                'placed' => $o && $o->get_date_created() ? wp_date('D j M, g:ia', $o->get_date_created()->getTimestamp()) : '',
                'status' => $o && function_exists('wc_get_order_status_name') ? wc_get_order_status_name($o->get_status()) : '',
                'url'    => $o ? (string) $o->get_edit_order_url() : '',
                'vip'    => isset($vip[$oid]),
                'seats'  => array_values($list),
                'ga'     => $ga[$oid] ?? array(),
            );
        }
        return $out;
    }

    // ── Drawing ─────────────────────────────────────────────────────────

    /**
     * The cells of one row; a wheelchair space beside a "no seat" is one
     * cell two chairs wide. Same rule as the seat map and the designer.
     *
     * @return array<int,array{key:string,n:int,type:string,span:int}>
     */
    public static function row_cells(array $block, $row) {
        $types = $block['seatTypes'] ?? array();
        $nums = Azure_Tickets_Layout::seat_numbers($block);
        $out = array();
        for ($i = 0, $count = count($nums); $i < $count; $i++) {
            $key = $row . '-' . $nums[$i];
            $t = $types[$key] ?? '';
            $next = $i + 1 < $count ? $row . '-' . $nums[$i + 1] : null;
            $nt = $next !== null ? ($types[$next] ?? '') : null;
            if ($t === 'accessible' && $nt === 'none') {
                $out[] = array('key' => $key, 'n' => $nums[$i], 'type' => $t, 'span' => 2);
                $i++;
            } elseif ($t === 'none' && $nt === 'accessible') {
                $out[] = array('key' => $next, 'n' => $nums[$i + 1], 'type' => 'accessible', 'span' => 2);
                $i++;
            } else {
                $out[] = array('key' => $key, 'n' => $nums[$i], 'type' => $t, 'span' => 1);
            }
        }
        return $out;
    }

    /**
     * Which sides of each seat get the group outline: a side is drawn
     * unless the seat next to it (same row) or in line with it (row in
     * front or behind) is in the same group.
     *
     * @param array<string,string> $groups seat key => group id
     * @return array<string,string> seat key => sides, some of "ltrb"
     */
    public static function outlines(array $block, array $groups) {
        $rows = Azure_Tickets_Layout::row_labels($block);
        $cells = array();
        foreach ($rows as $i => $r) {
            $cells[$i] = self::row_cells($block, $r);
        }
        $same = function ($key, $g) use ($groups) {
            return $key !== null && isset($groups[$key]) && $groups[$key] === $g;
        };
        $out = array();
        foreach ($rows as $i => $r) {
            foreach ($cells[$i] as $j => $c) {
                if ($c['type'] === 'none' || !isset($groups[$c['key']]) || $groups[$c['key']] === '') {
                    continue;
                }
                $g = $groups[$c['key']];
                $left = $j > 0 && $cells[$i][$j - 1]['type'] !== 'none' ? $cells[$i][$j - 1]['key'] : null;
                $right = isset($cells[$i][$j + 1]) && $cells[$i][$j + 1]['type'] !== 'none' ? $cells[$i][$j + 1]['key'] : null;
                $up = $i > 0 ? $rows[$i - 1] . '-' . $c['n'] : null;
                $down = isset($rows[$i + 1]) ? $rows[$i + 1] . '-' . $c['n'] : null;
                $out[$c['key']] = ($same($left, $g) ? '' : 'l') . ($same($up, $g) ? '' : 't') . ($same($right, $g) ? '' : 'r') . ($same($down, $g) ? '' : 'b');
            }
        }
        return $out;
    }

    /**
     * The area the drawn blocks cover, allowing for rotation, plus a margin.
     *
     * @return array{x:int,y:int,w:int,h:int}
     */
    public static function bounds(array $layout, $pad = 12) {
        $x1 = $y1 = PHP_INT_MAX;
        $x2 = $y2 = PHP_INT_MIN;
        foreach ($layout['blocks'] as $b) {
            if ($b['type'] === 'aisle') {
                continue;
            }
            $a = deg2rad((float) ($b['angle'] ?? 0));
            $cos = abs(cos($a));
            $sin = abs(sin($a));
            $w = $b['width'] * $cos + $b['height'] * $sin;
            $h = $b['width'] * $sin + $b['height'] * $cos;
            $cx = $b['x'] + $b['width'] / 2;
            $cy = $b['y'] + $b['height'] / 2;
            $x1 = min($x1, $cx - $w / 2);
            $y1 = min($y1, $cy - $h / 2);
            $x2 = max($x2, $cx + $w / 2);
            $y2 = max($y2, $cy + $h / 2);
        }
        if ($x1 === PHP_INT_MAX) {
            return array('x' => 0, 'y' => 0, 'w' => (int) $layout['canvas']['width'], 'h' => (int) $layout['canvas']['height']);
        }
        $x = (int) floor(max(0, $x1 - $pad));
        $y = (int) floor(max(0, $y1 - $pad));
        return array('x' => $x, 'y' => $y, 'w' => (int) ceil($x2 + $pad) - $x, 'h' => (int) ceil($y2 + $pad) - $y);
    }

    /**
     * The map as HTML, in layout pixels; the caller scales `.ptv-canvas`.
     *
     * @param array $opts names: write a name in each taken seat (print);
     *                    buyers: order id => buyer name, used when a ticket has no name;
     *                    focusable: seats take keyboard focus (screen).
     */
    public static function map_html(array $layout, array $sections, array $occ, array $opts = array()) {
        $box = self::bounds($layout);
        $html = '<div class="ptv-canvas" style="width:' . $box['w'] . 'px;height:' . $box['h'] . 'px">'
            . '<div class="ptv-origin" style="left:' . -$box['x'] . 'px;top:' . -$box['y'] . 'px">';
        foreach ($layout['blocks'] as $b) {
            $html .= self::block_html($b, $sections[$b['id']] ?? null, $occ, $opts);
        }
        return $html . '</div></div>';
    }

    private static function block_html(array $b, $sec, array $occ, array $opts) {
        $style = 'left:' . (int) $b['x'] . 'px;top:' . (int) $b['y'] . 'px;width:' . (int) $b['width'] . 'px;height:' . (int) $b['height'] . 'px;';
        $color = $b['color'] !== '' ? $b['color'] : '#2271b1';
        switch ($b['type']) {
            case 'rectangle':
                return $sec ? self::seats_html($b, $sec, $occ, $opts, $style, $color) : '';
            case 'general_admission':
                if (!$sec) {
                    return '';
                }
                $g = $occ['general'][$b['id']] ?? self::empty_general();
                $free = max(0, (int) $sec['capacity'] - $g['sold'] - $g['ordered'] - $g['held']);
                /* translators: 1: places sold, 2: places free */
                $line = sprintf(__('%1$d sold · %2$d free', 'azure-plugin'), $g['sold'], $free);
                return '<div class="ptv-block ptv-block--ga" data-ga="' . esc_attr($b['id']) . '"' . (!empty($opts['focusable']) ? ' tabindex="0"' : '')
                    . ' style="' . esc_attr($style . 'border-color:' . $color . ';background:' . self::tint($color, 0.18)) . '">'
                    . '<strong>' . esc_html($b['name']) . '</strong><span>' . esc_html($line) . '</span></div>';
            case 'area':
                return '<div class="ptv-block ptv-block--area" style="' . esc_attr($style . 'background-color:' . $color) . '"><span>' . esc_html($b['label']) . '</span></div>';
            case 'stage':
                return '<div class="ptv-block ptv-block--stage" style="' . esc_attr($style . 'background:' . ($b['color'] !== '' ? $b['color'] : '#3c434a')) . '"><span>' . esc_html($b['label']) . '</span></div>';
            case 'label':
                return '<div class="ptv-block ptv-block--label" style="' . esc_attr($style . 'color:' . ($b['color'] !== '' ? $b['color'] : '#50575e') . ';font-size:' . (int) $b['fontSize'] . 'px') . '"><span>' . esc_html($b['text']) . '</span></div>';
        }
        return '';
    }

    private static function seats_html(array $b, array $sec, array $occ, array $opts, $style, $color) {
        $taken = $occ['seats'][$b['id']] ?? array();
        $groups = array();
        foreach ($taken as $key => $seat) {
            $groups[$key] = $seat['group'];
        }
        $sides = self::outlines($b, $groups);
        $rows = Azure_Tickets_Layout::row_labels($b);
        if (!empty($b['angle'])) {
            $style .= 'transform:rotate(' . (int) $b['angle'] . 'deg);';
        }
        $style .= '--seat:' . $color . ';--seat-fill:' . self::tint($color, 0.3) . ';';
        $focus = !empty($opts['focusable']) ? ' tabindex="0"' : '';
        $out = '<div class="ptv-block ptv-block--seats' . ($sec['enabled'] ? '' : ' is-closed') . '" data-block="' . esc_attr($b['id']) . '" style="' . esc_attr($style) . '">'
            . '<div class="ptv-rows" style="--n:' . (int) $b['seatsPerRow'] . '">';
        if (empty($b['hideNumbers'])) {
            $out .= '<div class="ptv-rowline ptv-nums' . (!empty($b['stagger']) ? ' stagger-a' : '') . '" aria-hidden="true"><span class="ptv-row"></span><span class="ptv-cells">';
            foreach (self::row_cells($b, $rows[0]) as $cell) {
                $out .= '<span class="ptv-num" style="flex-grow:' . $cell['span'] . '">' . ($cell['type'] === 'none' ? '' : (int) $cell['n']) . '</span>';
            }
            $out .= '</span></div>';
        }
        foreach ($rows as $i => $r) {
            $stagger = !empty($b['stagger']) ? ($i % 2 ? ' stagger-b' : ' stagger-a') : '';
            $out .= '<div class="ptv-rowline' . $stagger . '"><span class="ptv-row">' . esc_html($r) . '</span><span class="ptv-cells">';
            foreach (self::row_cells($b, $r) as $cell) {
                if ($cell['type'] === 'none') {
                    $out .= '<span class="ptv-gap"></span>';
                    continue;
                }
                $seat = $taken[$cell['key']] ?? null;
                $state = $seat ? str_replace('_', '-', $seat['state']) : ($sec['enabled'] ? 'free' : 'closed');
                $cls = 'ptv-seat is-' . $state
                    . ($cell['type'] === 'accessible' ? ' is-accessible' : '')
                    . ($cell['type'] === 'companion' ? ' is-companion' : '');
                if (isset($sides[$cell['key']])) {
                    $cls .= ' ptv-g';
                    foreach (str_split($sides[$cell['key']]) as $side) {
                        $cls .= $side !== '' ? ' ptv-g' . $side : '';
                    }
                }
                $label = Azure_Tickets_Layout::seat_label($b, $cell['key']);
                $number = ($cell['type'] === 'accessible' ? '♿' : '') . (int) $cell['n'];
                $text = '<b>' . $number . '</b>';
                if (!empty($opts['names']) && $seat) {
                    list($initial, $surname) = self::name_parts($seat['name'] !== '' ? $seat['name'] : (string) ($opts['buyers'][$seat['order']] ?? ''));
                    if ($surname !== '') {
                        $text = '<b>' . $number . ($initial !== '' ? ' <small>' . esc_html($initial) . '</small>' : '') . '</b><i>' . esc_html($surname) . '</i>';
                    }
                }
                $out .= '<span class="' . esc_attr($cls) . '" style="flex-grow:' . $cell['span'] . '" data-s="' . esc_attr($b['id']) . '" data-k="' . esc_attr($cell['key']) . '"'
                    . ($seat && $seat['group'] !== '' ? ' data-g="' . esc_attr($seat['group']) . '"' : '')
                    . ' aria-label="' . esc_attr($label) . '"' . $focus . '>' . $text . '</span>';
            }
            $out .= '</span></div>';
        }
        return $out . '</div></div>';
    }

    /**
     * "Olivia Botello Fowler" → ["O.", "Fowler"]: what fits on a printed
     * seat. The surname gets the seat's width, as it is what people give
     * at the door; the initial goes beside the seat number.
     *
     * @return array{0:string,1:string} initial, surname
     */
    public static function name_parts($name) {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return array('', $words ? $words[0] : '');
        }
        $first = function_exists('mb_substr') ? mb_substr($words[0], 0, 1) : substr($words[0], 0, 1);
        return array($first . '.', end($words));
    }

    public static function tint($hex, $alpha) {
        if (!preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', (string) $hex, $m)) {
            return 'rgba(0,0,0,' . $alpha . ')';
        }
        return 'rgba(' . hexdec($m[1]) . ',' . hexdec($m[2]) . ',' . hexdec($m[3]) . ',' . $alpha . ')';
    }

    // ── One performance, everything the tab and the printout need ──────

    /**
     * @return array|null null when the performance has no venue layout.
     */
    public static function snapshot($product_id) {
        $product_id = (int) $product_id;
        if (!Azure_Tickets_Config::venue_id($product_id)) {
            return null;
        }
        $layout = Azure_Tickets_Config::layout($product_id);
        $sections = Azure_Tickets_Config::sections($product_id);
        $occ = self::occupancy($product_id, $sections);
        $vip = class_exists('Azure_Tickets_Assign') && function_exists('wc_get_order')
            ? array_fill_keys(array_keys(Azure_Tickets_Assign::vip_orders($product_id)), true) : array();
        return array(
            'product_id' => $product_id,
            'layout'     => $layout,
            'sections'   => $sections,
            'occ'        => $occ,
            'orders'     => self::orders($occ, $sections, $vip),
            'stats'      => self::stats($sections, $occ),
            'venue'      => (string) get_the_title(Azure_Tickets_Config::venue_id($product_id)),
        );
    }

    /**
     * What the tab's script reads for tooltips and search. Hold keys never
     * leave the server; holds are only told apart by their group number.
     */
    public static function client_data(array $snap) {
        $seats = array();
        foreach ($snap['occ']['seats'] as $sid => $by_key) {
            foreach ($by_key as $key => $s) {
                $seats[$sid . '|' . $key] = array(
                    'st'    => $s['state'],
                    'o'     => $s['order'],
                    'n'     => $s['name'],
                    'until' => $s['until'] ? wp_date('g:ia', $s['until']) : '',
                    'who'   => $s['user'] ? self::user_name($s['user']) : '',
                    // Check-in stores site-local time (current_time('mysql')).
                    'in'    => $s['checked_in_at'] ? mysql2date('D j M, g:ia', $s['checked_in_at']) : '',
                );
            }
        }
        $sections = array();
        foreach ($snap['sections'] as $sid => $s) {
            $g = $snap['occ']['general'][$sid] ?? null;
            $sections[$sid] = array(
                'name'    => $s['name'],
                'enabled' => (bool) $s['enabled'],
                'ga'      => $s['type'] === 'general_admission' ? array(
                    'capacity' => (int) $s['capacity'],
                    'sold'     => $g ? $g['sold'] : 0,
                    'in'       => $g ? $g['checked_in'] : 0,
                    'ordered'  => $g ? $g['ordered'] : 0,
                    'held'     => $g ? $g['held'] : 0,
                    'orders'   => $g ? $g['orders'] : array(),
                ) : null,
            );
        }
        return array('seats' => $seats, 'orders' => $snap['orders'], 'sections' => $sections);
    }

    // ── The PTSA Board app's seat map ───────────────────────────────────

    /**
     * Where each seat of a rectangle block sits inside the block, in layout
     * pixels before the block's rotation. Follows the tab's CSS: 2/4/4px
     * block padding, an 11px seat-number line, a 12px row-letter column,
     * 2px gaps, a half-seat stagger, and a wheelchair space beside a
     * "no seat" taking both cells.
     *
     * @return array{cells: array<string,array{x:float,y:float,w:float,h:float,n:int,type:string}>, rows: array<int,array{label:string,x:float,y:float}>}
     */
    public static function seat_cells(array $block) {
        $rows = Azure_Tickets_Layout::row_labels($block);
        $out = array('cells' => array(), 'rows' => array());
        if (!$rows) {
            return $out;
        }
        $inner_w = (float) $block['width'] - 8;
        $inner_h = (float) $block['height'] - 6;
        $top = 2.0;
        if (empty($block['hideNumbers'])) {
            $top += 13;
            $inner_h -= 13;
        }
        $count = count($rows);
        $row_h = max(0.0, ($inner_h - 2 * ($count - 1)) / $count);
        $per_row = max(1, (int) $block['seatsPerRow']);
        foreach ($rows as $i => $r) {
            $y = $top + $i * ($row_h + 2);
            $left = 16.0;
            $width = $inner_w - 12;
            if (!empty($block['stagger'])) {
                $pad = 0.5 * $inner_w / $per_row;
                $width -= $pad;
                if ($i % 2) {
                    $left += $pad;
                }
            }
            $cells = self::row_cells($block, $r);
            $unit = max(0.0, ($width - 2 * (count($cells) - 1)) / max(1, array_sum(array_column($cells, 'span'))));
            $x = $left;
            foreach ($cells as $c) {
                $w = $unit * $c['span'];
                if ($c['type'] !== 'none') {
                    $out['cells'][$c['key']] = array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $row_h, 'n' => (int) $c['n'], 'type' => $c['type'] !== '' ? $c['type'] : 'normal');
                }
                $x += $w + 2;
            }
            $out['rows'][] = array('label' => $r, 'x' => 9.0, 'y' => $y + $row_h / 2);
        }
        return $out;
    }

    /**
     * Everything the app draws, already laid out: positions are in layout
     * pixels from the top-left of the drawn area, seats and row letters by
     * their centre (with the block's angle), other shapes by their corner.
     * Same people data as the tab's tooltips; hold keys never leave.
     */
    public static function app_map(array $snap) {
        $box = self::bounds($snap['layout']);
        $round = function ($v) {
            return round((float) $v, 1);
        };
        $shapes = array();
        $seats = array();
        $row_labels = array();
        foreach ($snap['layout']['blocks'] as $b) {
            $sec = $snap['sections'][$b['id']] ?? null;
            $color = ($b['color'] ?? '') !== '' ? $b['color'] : '#2271b1';
            $corner = array(
                'x' => $round($b['x'] - $box['x']),
                'y' => $round($b['y'] - $box['y']),
                'w' => $round($b['width']),
                'h' => $round($b['height']),
            );
            switch ($b['type']) {
                case 'stage':
                case 'area':
                case 'label':
                    $shapes[] = $corner + array(
                        'kind'  => $b['type'],
                        'text'  => (string) ($b['type'] === 'label' ? ($b['text'] ?? '') : ($b['label'] ?? '')),
                        'color' => (string) ($b['color'] ?? ''),
                    );
                    break;
                case 'general_admission':
                    if (!$sec) {
                        break;
                    }
                    $g = $snap['occ']['general'][$b['id']] ?? self::empty_general();
                    $shapes[] = $corner + array(
                        'kind'  => 'ga',
                        'text'  => (string) $b['name'],
                        'color' => $color,
                        'ga'    => array(
                            'capacity'   => (int) $sec['capacity'],
                            'sold'       => $g['sold'],
                            'checked_in' => $g['checked_in'],
                            'free'       => max(0, (int) $sec['capacity'] - $g['sold'] - $g['ordered'] - $g['held']),
                            'enabled'    => (bool) $sec['enabled'],
                        ),
                    );
                    break;
                case 'rectangle':
                    if (!$sec) {
                        break;
                    }
                    $taken = $snap['occ']['seats'][$b['id']] ?? array();
                    $groups = array();
                    foreach ($taken as $key => $seat) {
                        $groups[$key] = $seat['group'];
                    }
                    $sides = self::outlines($b, $groups);
                    $angle = (float) ($b['angle'] ?? 0);
                    $rad = deg2rad($angle);
                    $cos = cos($rad);
                    $sin = sin($rad);
                    $cx = $b['x'] + $b['width'] / 2 - $box['x'];
                    $cy = $b['y'] + $b['height'] / 2 - $box['y'];
                    $place = function ($lx, $ly) use ($b, $cos, $sin, $cx, $cy, $round) {
                        $dx = $lx - $b['width'] / 2;
                        $dy = $ly - $b['height'] / 2;
                        return array($round($cx + $dx * $cos - $dy * $sin), $round($cy + $dx * $sin + $dy * $cos));
                    };
                    $geo = self::seat_cells($b);
                    foreach ($geo['rows'] as $row) {
                        list($x, $y) = $place($row['x'], $row['y']);
                        $row_labels[] = array('text' => $row['label'], 'x' => $x, 'y' => $y, 'a' => $angle, 'color' => $color);
                    }
                    foreach ($geo['cells'] as $key => $c) {
                        list($x, $y) = $place($c['x'] + $c['w'] / 2, $c['y'] + $c['h'] / 2);
                        $seat = $taken[$key] ?? null;
                        $seats[] = array(
                            's'     => (string) $b['id'],
                            'k'     => (string) $key,
                            'x'     => $x,
                            'y'     => $y,
                            'w'     => $round($c['w']),
                            'h'     => $round($c['h']),
                            'a'     => $angle,
                            'n'     => $c['n'],
                            't'     => $c['type'],
                            'st'    => $seat ? $seat['state'] : ($sec['enabled'] ? 'free' : 'closed'),
                            'label' => Azure_Tickets_Layout::seat_label($b, $key),
                            'name'  => $seat ? $seat['name'] : '',
                            'order' => $seat ? (int) $seat['order'] : 0,
                            'g'     => $seat ? (string) $seat['group'] : '',
                            'sides' => (string) ($sides[$key] ?? ''),
                            'in'    => $seat && $seat['checked_in_at'] ? mysql2date('D j M, g:ia', $seat['checked_in_at']) : '',
                            'until' => $seat && $seat['until'] ? wp_date('g:ia', $seat['until']) : '',
                            'who'   => $seat && $seat['user'] ? self::user_name($seat['user']) : '',
                            'color' => $color,
                        );
                    }
                    break;
            }
        }
        $orders = array();
        foreach ($snap['orders'] as $id => $o) {
            $orders[] = array('id' => (int) $id) + $o;
        }
        return array(
            'width'  => (int) $box['w'],
            'height' => (int) $box['h'],
            'stats'  => $snap['stats'],
            'venue'  => $snap['venue'] ?? '',
            'shapes' => $shapes,
            'rows'   => $row_labels,
            'seats'  => $seats,
            'orders' => $orders,
        );
    }

    private static function user_name($user_id) {
        $u = get_user_by('id', (int) $user_id);
        return $u ? (string) $u->display_name : '';
    }

    // ── Printout ────────────────────────────────────────────────────────

    public static function print_url($product_id, $paper = 'letter', $names = true) {
        return wp_nonce_url(add_query_arg(array(
            'action'  => self::PRINT_ACTION,
            'product' => (int) $product_id,
            'paper'   => $paper,
            'names'   => $names ? 1 : 0,
        ), admin_url('admin-post.php')), self::PRINT_ACTION);
    }

    /** admin-post.php?action=azure_tickets_seat_print&product=…&paper=…&names=… */
    public static function serve_print() {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to print seating maps.', 'azure-plugin'), '', array('response' => 403));
        }
        check_admin_referer(self::PRINT_ACTION);
        $product_id = absint($_GET['product'] ?? 0);
        $snap = Azure_Tickets_Config::is_performance($product_id) ? self::snapshot($product_id) : null;
        if (!$snap) {
            wp_die(esc_html__('Choose a performance that has a venue seating layout.', 'azure-plugin'), '', array('response' => 400));
        }
        $paper = sanitize_key($_GET['paper'] ?? 'letter');
        $owner = function_exists('wc_get_product') ? wc_get_product(Azure_Tickets_Config::owner($product_id)) : null;
        $ts = Azure_Tickets_Config::event_ts($product_id);
        $context = array(
            'title'     => $owner ? $owner->get_name() : '',
            'when'      => $ts ? wp_date('l j F Y · g:ia', $ts) : self::performance_label($product_id),
            'printed'   => wp_date('D j M Y, g:ia'),
            'names'     => !empty($_GET['names']),
            'autoprint' => !empty($_GET['autoprint']),
            'css'       => AZURE_PLUGIN_URL . 'css/tickets-visualizer.css?ver=' . AZURE_PLUGIN_VERSION,
        );
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo self::print_html($snap, isset(self::PAPERS[$paper]) ? $paper : 'letter', $context);
        exit;
    }

    /**
     * Sheet and map geometry for the 2 × 2 printout, in mm and layout px.
     *
     * @return array{page_w:float,page_h:float,tile_w:float,tile_h:float,scale:float,left:float,top:float}
     *         left/top: where the scaled map sits on the assembled poster, mm.
     */
    public static function print_geometry(array $box, $paper) {
        $p = self::PAPERS[$paper] ?? self::PAPERS['letter'];
        $tile_w = $p['w'] - 2 * self::MARGIN_MM;
        $tile_h = $p['h'] - 2 * self::MARGIN_MM;
        $area_w = 2 * $tile_w - 6;
        $area_h = 2 * $tile_h - self::HEADER_MM - 6;
        $scale = min($area_w * self::PX_PER_MM / max(1, $box['w']), $area_h * self::PX_PER_MM / max(1, $box['h']));
        $map_w = $box['w'] * $scale / self::PX_PER_MM;
        $map_h = $box['h'] * $scale / self::PX_PER_MM;
        return array(
            'page_w' => $p['w'],
            'page_h' => $p['h'],
            'tile_w' => $tile_w,
            'tile_h' => $tile_h,
            'scale'  => round($scale, 4),
            'left'   => round((2 * $tile_w - $map_w) / 2, 2),
            'top'    => round(self::HEADER_MM + (2 * $tile_h - self::HEADER_MM - $map_h) / 2, 2),
        );
    }

    /**
     * @param array $context title, when, printed, names, autoprint, css
     */
    public static function print_html(array $snap, $paper, array $context) {
        $box = self::bounds($snap['layout']);
        $geo = self::print_geometry($box, $paper);
        $buyers = array();
        foreach ($snap['orders'] as $oid => $o) {
            $buyers[$oid] = $o['buyer'];
        }
        $map = self::map_html($snap['layout'], $snap['sections'], $snap['occ'], array('names' => !empty($context['names']), 'buyers' => $buyers));
        $st = $snap['stats'];
        /* translators: 1: seats sold, 2: checked in, 3: free seats */
        $counts = sprintf(__('%1$d sold · %2$d checked in · %3$d free', 'azure-plugin'), $st['sold'], $st['checked_in'], $st['free'] + $st['free_closed']);
        if ($st['held'] + $st['ordered']) {
            /* translators: %d: seats in carts or awaiting payment */
            $counts .= ' · ' . sprintf(__('%d in carts or awaiting payment', 'azure-plugin'), $st['held'] + $st['ordered']);
        }
        $poster = '<div class="poster" style="width:' . (2 * $geo['tile_w']) . 'mm;height:' . (2 * $geo['tile_h']) . 'mm">'
            . '<header class="head" style="height:' . self::HEADER_MM . 'mm">'
            . '<div class="head-main"><h1>' . esc_html($context['title']) . '</h1><p class="when">' . esc_html($context['when'] . ($snap['venue'] !== '' ? ' · ' . $snap['venue'] : '')) . '</p></div>'
            . '<div class="head-side"><p class="counts">' . esc_html($counts) . '</p>'
            . '<p class="legend">'
            . '<span><i class="ptv-key is-free"></i>' . esc_html__('Free', 'azure-plugin') . '</span>'
            . '<span><i class="ptv-key is-sold"></i>' . esc_html__('Sold', 'azure-plugin') . '</span>'
            . '<span><i class="ptv-key is-checked-in"></i>' . esc_html__('Checked in', 'azure-plugin') . '</span>'
            . '<span><i class="ptv-key is-held"></i>' . esc_html__('In a cart / unpaid', 'azure-plugin') . '</span>'
            . '<span><i class="ptv-key is-closed"></i>' . esc_html__('Not on public sale', 'azure-plugin') . '</span>'
            . '<span><i class="ptv-key is-group"></i>' . esc_html__('Bought together', 'azure-plugin') . '</span>'
            . '</p>'
            /* translators: %s: date and time */
            . '<p class="printed">' . esc_html(sprintf(__('Printed %s. Sales after this are not shown.', 'azure-plugin'), $context['printed'])) . '</p></div>'
            . '</header>'
            . '<div class="ptv-print-map" style="left:' . $geo['left'] . 'mm;top:' . $geo['top'] . 'mm;transform:scale(' . $geo['scale'] . ')">' . $map . '</div>'
            . '</div>';
        $where = array(
            __('top left', 'azure-plugin'),
            __('top right', 'azure-plugin'),
            __('bottom left', 'azure-plugin'),
            __('bottom right', 'azure-plugin'),
        );
        $m = self::MARGIN_MM;
        ob_start();
        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($context['title'] . ' — ' . $context['when']); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($context['css']); ?>">
<style>
@page { size: <?php echo esc_html($geo['page_w']); ?>mm <?php echo esc_html($geo['page_h']); ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; background: #dcdcde; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 10px 16px; background: #1d2327; color: #fff; font-size: 13px; }
.bar button { font: inherit; font-weight: 600; padding: 6px 14px; border-radius: 4px; border: 0; background: #2271b1; color: #fff; cursor: pointer; }
.bar .tip { opacity: .8; }
.sheets { display: grid; grid-template-columns: repeat(2, <?php echo esc_html($geo['page_w']); ?>mm); gap: 6mm; padding: 6mm; width: max-content; margin: 0 auto; }
.page { position: relative; width: <?php echo esc_html($geo['page_w']); ?>mm; height: <?php echo esc_html($geo['page_h']); ?>mm; background: #fff; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.25); }
.tile { position: absolute; left: <?php echo $m; ?>mm; top: <?php echo $m; ?>mm; width: <?php echo esc_html($geo['tile_w']); ?>mm; height: <?php echo esc_html($geo['tile_h']); ?>mm; overflow: hidden; }
.poster { position: absolute; }
.head { position: absolute; left: 0; top: 0; right: 0; display: flex; justify-content: space-between; align-items: flex-start; gap: 10mm; padding: 2mm 3mm 0; border-bottom: 0.4mm solid #1d2327; }
.head h1 { margin: 0; font-size: 11mm; line-height: 1.05; }
.head .when { margin: 1.5mm 0 0; font-size: 5.5mm; font-weight: 600; }
.head-side { text-align: right; }
.head-side p { margin: 0 0 1.3mm; }
.counts { font-size: 4.6mm; font-weight: 700; }
.legend { font-size: 3.4mm; display: flex; gap: 4mm; justify-content: flex-end; flex-wrap: wrap; }
.legend span { display: inline-flex; align-items: center; gap: 1.4mm; }
.legend .ptv-key { width: 5mm; height: 4mm; }
.printed { font-size: 3.2mm; color: #50575e; }
.ptv-print-map { position: absolute; transform-origin: 0 0; }
.tag { position: absolute; right: <?php echo $m; ?>mm; bottom: 1.8mm; font-size: 2.8mm; color: #646970; }
.mark { position: absolute; border: 0 solid #8c8f94; }
@media screen { .sheets { zoom: var(--fit, 1); } }
@media print {
  body { background: #fff; }
  .bar { display: none; }
  .sheets { display: block; padding: 0; gap: 0; zoom: 1; }
  .page { box-shadow: none; break-after: page; page-break-after: always; }
  .page:last-child { break-after: auto; page-break-after: auto; }
}
</style>
</head><body>
<div class="bar">
  <button type="button" onclick="window.print()"><?php esc_html_e('Print', 'azure-plugin'); ?></button>
  <span class="tip"><?php esc_html_e('Four landscape pages. In the print dialog choose your paper size, scale 100% (or "Actual size") and no headers or footers. Trim the inner edges at the corner marks and tape the pages together as shown here.', 'azure-plugin'); ?></span>
</div>
<div class="sheets">
<?php for ($n = 0; $n < 4; $n++):
    $col = $n % 2;
    $row = intdiv($n, 2);
    ?>
  <section class="page">
    <div class="tile"><div class="poster-at" style="position:absolute;left:<?php echo esc_attr(-$col * $geo['tile_w']); ?>mm;top:<?php echo esc_attr(-$row * $geo['tile_h']); ?>mm"><?php echo $poster; // Built from escaped parts above. ?></div></div>
    <?php
    // Corner marks on the edges that join another page, so they can be trimmed and butted.
    $inner_x = $col === 0 ? $m + $geo['tile_w'] : $m;
    $inner_y = $row === 0 ? $m + $geo['tile_h'] : $m;
    foreach (array($m, $m + $geo['tile_h']) as $y) {
        echo '<i class="mark" style="left:' . esc_attr($col === 0 ? $inner_x : 0) . 'mm;top:' . esc_attr($y) . 'mm;width:' . $m . 'mm;border-top-width:0.2mm"></i>';
    }
    foreach (array($m, $m + $geo['tile_w']) as $x) {
        echo '<i class="mark" style="left:' . esc_attr($x) . 'mm;top:' . esc_attr($row === 0 ? $inner_y : 0) . 'mm;height:' . $m . 'mm;border-left-width:0.2mm"></i>';
    }
    ?>
    <div class="tag"><?php
        /* translators: 1: page number, 2: position such as "top left", 3: event title */
        echo esc_html(sprintf(__('Page %1$d of 4 · %2$s · %3$s', 'azure-plugin'), $n + 1, $where[$n], $context['title'] . ', ' . $context['when']));
    ?></div>
  </section>
<?php endfor; ?>
</div>
<script>
(function () {
  var sheets = document.querySelector('.sheets');
  function fit() {
    sheets.style.removeProperty('--fit');
    var w = sheets.getBoundingClientRect().width;
    sheets.style.setProperty('--fit', Math.min(1, (window.innerWidth - 16) / w));
  }
  fit();
  window.addEventListener('resize', fit);
<?php if (!empty($context['autoprint'])): ?>
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
<?php endif; ?>
})();
</script>
</body></html>
<?php
        return (string) ob_get_clean();
    }
}
