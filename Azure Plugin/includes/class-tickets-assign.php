<?php
/**
 * Seats given by hand to cast & crew (VIP) orders.
 *
 * Families buy a hidden VIP product with no seat; an admin then pastes the
 * seat list provided outside the website. Assigned seats are inventory rows
 * sold to the VIP order plus ticket rows on it, so check-in, the ticket email
 * and cancelling the order all work as for a normal purchase.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Assign {

    const PAID = array('processing', 'completed');

    /**
     * One seat per line: "D4 Jane Smith", "D-4, Jane", "d 12".
     *
     * @return array{seats: array<string,string>, errors: string[]} seat key => name
     */
    public static function parse($text) {
        $seats = array();
        $errors = array();
        foreach (preg_split('/\R/', (string) $text) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z])\s*-?\s*(\d{1,3})(?!\d)[\s,;:\x{2013}\x{2014}-]*(.*)$/u', $line, $m)) {
                /* translators: %d: line number */
                $errors[] = sprintf(__('Line %d: start with a seat, like D4.', 'azure-plugin'), $i + 1);
                continue;
            }
            $key = strtoupper($m[1]) . '-' . (int) $m[2];
            if (isset($seats[$key])) {
                /* translators: 1: line number, 2: seat such as D4 */
                $errors[] = sprintf(__('Line %1$d: %2$s is listed twice.', 'azure-plugin'), $i + 1, str_replace('-', '', $key));
                continue;
            }
            $seats[$key] = trim(strip_tags($m[3]));
        }
        return array('seats' => $seats, 'errors' => $errors);
    }

    /**
     * @return string Section holding this seat, '' when it isn't a seat.
     */
    public static function locate(array $sections, $seat_key) {
        foreach ($sections as $sid => $s) {
            if ($s['type'] === 'rectangle' && Azure_Tickets_Layout::seat_type($s['block'], $seat_key) !== null) {
                return (string) $sid;
            }
        }
        return '';
    }

    /**
     * Paid orders containing this performance's VIP products.
     *
     * @return array<int,int> order id => VIP tickets bought
     */
    public static function vip_orders($product_id) {
        global $wpdb;
        $ids = Azure_Tickets_Config::vip_products($product_id);
        if (!$ids) {
            return array();
        }
        $in = implode(',', array_map('intval', $ids));
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $rows = (array) $wpdb->get_results(
            "SELECT i.order_id,
                    MAX(CASE WHEN m.meta_key = '_qty' THEN m.meta_value END) AS qty,
                    MAX(CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END) AS variation_id
             FROM {$items} i
             JOIN {$meta} m ON m.order_item_id = i.order_item_id AND m.meta_key IN ('_qty', '_variation_id')
             WHERE i.order_item_type = 'line_item' AND EXISTS (
                 SELECT 1 FROM {$meta} p WHERE p.order_item_id = i.order_item_id
                 AND p.meta_key IN ('_product_id', '_variation_id') AND p.meta_value IN ({$in})
             )
             GROUP BY i.order_item_id, i.order_id ORDER BY i.order_id",
            ARRAY_A
        );
        $performances = Azure_Tickets_Config::performances($product_id);
        $out = array();
        foreach ($rows as $r) {
            if (!in_array((int) $product_id, self::line_performances((int) $r['variation_id'], $performances), true)) {
                continue;
            }
            $order = wc_get_order((int) $r['order_id']);
            if ($order && in_array($order->get_status(), self::PAID, true)) {
                $out[(int) $r['order_id']] = ($out[(int) $r['order_id']] ?? 0) + (int) $r['qty'];
            }
        }
        return $out;
    }

    /**
     * Which performances a VIP line is for. A VIP variation goes to the
     * performances with the same option (both "Friday"), else to those on
     * the weekday its option names ("Friday 7pm"); a VIP product without
     * variations, or an option that matches none, goes to all of them.
     *
     * @param int[] $performances
     * @return int[]
     */
    public static function line_performances($vip_variation_id, array $performances) {
        $mine = $vip_variation_id ? self::options($vip_variation_id) : array();
        if (!$mine) {
            return $performances;
        }
        $exact = array_values(array_filter($performances, function ($p) use ($mine) {
            return (bool) array_intersect($mine, self::options($p));
        }));
        if ($exact) {
            return $exact;
        }
        $words = array();
        foreach ($mine as $option) {
            $words = array_merge($words, explode('-', $option));
        }
        $by_day = array_values(array_filter($performances, function ($p) use ($words) {
            $ts = Azure_Tickets_Config::event_ts($p);
            return $ts && in_array(strtolower(wp_date('l', $ts)), $words, true);
        }));
        return $by_day ?: $performances;
    }

    /**
     * @return string[] A variation's attribute values as slugs; empty for anything else.
     */
    private static function options($variation_id) {
        if (!function_exists('wc_get_product_variation_attributes') || get_post_type((int) $variation_id) !== 'product_variation') {
            return array();
        }
        return array_values(array_filter(array_map(function ($v) {
            return sanitize_title((string) $v);
        }, (array) wc_get_product_variation_attributes((int) $variation_id))));
    }

    /**
     * @return array<string,array> seat key => ticket row (id, section_id, attendee_name)
     */
    public static function assigned($order_id, $product_id) {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, section_id, row_letter, seat_number, attendee_name FROM {$wpdb->prefix}azure_tickets
             WHERE order_id = %d AND product_id = %d AND status = 'active' AND row_letter IS NOT NULL
             ORDER BY row_letter, seat_number",
            (int) $order_id, (int) $product_id
        ), ARRAY_A);
        $out = array();
        foreach ($rows as $r) {
            $out[$r['row_letter'] . '-' . (int) $r['seat_number']] = $r;
        }
        return $out;
    }

    /**
     * Text for the order's box: one "D4 Name" line per assigned seat.
     */
    public static function as_text(array $assigned) {
        $lines = array();
        foreach ($assigned as $key => $r) {
            $lines[] = trim(str_replace('-', '', $key) . ' ' . $r['attendee_name']);
        }
        return implode("\n", $lines);
    }

    /**
     * Make the order's seats for this performance match the pasted list.
     *
     * @param WC_Order $order
     * @return array{ok:bool, errors?:string[], added?:int, removed?:int, renamed?:int}
     */
    public static function save($order, $product_id, $text, $paid_for) {
        global $wpdb;
        $parsed = self::parse($text);
        $errors = $parsed['errors'];
        if (count($parsed['seats']) > $paid_for) {
            /* translators: 1: tickets bought, 2: seats listed */
            $errors[] = sprintf(__('This order paid for %1$d tickets but %2$d seats are listed.', 'azure-plugin'), $paid_for, count($parsed['seats']));
        }
        $sections = Azure_Tickets_Config::sections($product_id);
        $want = array();
        foreach ($parsed['seats'] as $key => $name) {
            $sid = self::locate($sections, $key);
            if ($sid === '') {
                /* translators: %s: seat such as D4 */
                $errors[] = sprintf(__('%s isn\'t a seat in this venue.', 'azure-plugin'), str_replace('-', '', $key));
                continue;
            }
            $want[$key] = array('section_id' => $sid, 'seat_key' => $key, 'name' => $name);
        }
        if ($errors) {
            return array('ok' => false, 'errors' => $errors);
        }

        $order_id = $order->get_id();
        $have = self::assigned($order_id, $product_id);
        $add = array_diff_key($want, $have);
        $remove = array_diff_key($have, $want);

        $r = Azure_Tickets_Inventory::assign($order_id, $product_id, array_values($add));
        if (!$r['ok']) {
            $labels = array_map(function ($p) use ($sections) {
                return Azure_Tickets_Layout::seat_label($sections[$p['section_id']]['block'], $p['seat_key']);
            }, $r['taken']);
            /* translators: %s: list of seats */
            return array('ok' => false, 'errors' => array(sprintf(__('Already taken by someone else: %s', 'azure-plugin'), implode('; ', $labels))));
        }

        foreach ($add as $seat) {
            Azure_Tickets_Module::insert_ticket($order, $product_id, $seat);
        }
        $tickets = $wpdb->prefix . 'azure_tickets';
        foreach ($remove as $key => $row) {
            Azure_Tickets_Inventory::unassign($order_id, $product_id, array(array('section_id' => $row['section_id'], 'seat_key' => $key)));
            $wpdb->update($tickets, array('status' => 'cancelled'), array('id' => (int) $row['id']));
        }
        $renamed = 0;
        foreach (array_intersect_key($want, $have) as $key => $seat) {
            if ($seat['name'] !== (string) $have[$key]['attendee_name']) {
                $wpdb->update($tickets, array('attendee_name' => $seat['name']), array('id' => (int) $have[$key]['id']));
                $renamed++;
            }
        }
        return array('ok' => true, 'added' => count($add), 'removed' => count($remove), 'renamed' => $renamed);
    }

    // ── CSV ───────────────────────────────────────────────────────────

    /**
     * @return array<int,array> order id => number, family, paid, assigned
     */
    public static function order_rows($product_id) {
        $out = array();
        foreach (self::vip_orders($product_id) as $oid => $qty) {
            $o = wc_get_order($oid);
            $out[$oid] = array(
                'number'   => (string) $o->get_order_number(),
                'family'   => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
                'paid'     => $qty,
                'assigned' => self::assigned($oid, $product_id),
            );
        }
        return $out;
    }

    /** admin-post.php?action=azure_tickets_assign_csv&product=… */
    public static function download_csv() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You can\'t download seat assignments.', 'azure-plugin'), '', array('response' => 403));
        }
        $product_id = absint($_GET['product'] ?? 0);
        check_admin_referer('pta_assign_csv_' . $product_id);
        $ts = Azure_Tickets_Config::event_ts($product_id);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cast-crew-seats-' . ($ts ? wp_date('D-j-M', $ts) : $product_id) . '.csv"');
        echo self::csv_template(self::order_rows($product_id));
        exit;
    }

    const CSV_HEADER = array('Order', 'Family', 'Seat', 'Name');

    /**
     * The template: one row per ticket bought, the seats already assigned
     * filled in, then blank seats for the rest. Uploading it unchanged
     * changes nothing, so it doubles as an export.
     *
     * @param array[] $orders each: number, family, paid, assigned (as from assigned())
     */
    public static function csv_template(array $orders) {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::CSV_HEADER, ',', '"', '');
        foreach ($orders as $o) {
            $n = 0;
            foreach ($o['assigned'] as $key => $r) {
                fputcsv($out, array_map(array(__CLASS__, 'csv_cell'), array($o['number'], $o['family'], str_replace('-', '', $key), $r['attendee_name'])), ',', '"', '');
                $n++;
            }
            for (; $n < $o['paid']; $n++) {
                fputcsv($out, array_map(array(__CLASS__, 'csv_cell'), array($o['number'], $o['family'], '', $o['family'])), ',', '"', '');
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return "\xEF\xBB\xBF" . $csv;
    }

    /** Spreadsheets run a cell starting with = + - @ as a formula. */
    private static function csv_cell($value) {
        $value = (string) $value;
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /**
     * Reads an uploaded sheet: Order and Seat columns, Name optional, any
     * other columns ignored. Rows with no seat are skipped.
     *
     * @param array<string,int> $numbers order number as shown => order id
     * @return array{orders: array<int, array<string,string>>, errors: string[]} order id => seat key => name
     */
    public static function parse_csv($csv, array $numbers) {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', (string) $csv);
        $lines = preg_split('/\R/', $csv);
        $errors = array();
        $orders = array();
        $cols = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, ',', '"', ''));
            if ($cols === null) {
                $cols = array();
                foreach ($cells as $c => $h) {
                    $h = strtolower($h);
                    if (strpos($h, 'order') !== false) {
                        $cols['order'] = $c;
                    } elseif (strpos($h, 'seat') !== false) {
                        $cols['seat'] = $c;
                    } elseif ($h === 'name' || strpos($h, 'attendee') !== false) {
                        $cols['name'] = $c;
                    }
                }
                if (!isset($cols['order'], $cols['seat'])) {
                    return array('orders' => array(), 'errors' => array(__('The first row must have "Order" and "Seat" columns. Download the template to start from.', 'azure-plugin')));
                }
                continue;
            }
            $row = $i + 1;
            $seat = $cells[$cols['seat']] ?? '';
            if ($seat === '') {
                continue;
            }
            $number = ltrim($cells[$cols['order']] ?? '', '#');
            if (!isset($numbers[$number])) {
                /* translators: 1: row number, 2: order number */
                $errors[] = sprintf(__('Row %1$d: #%2$s isn\'t a paid cast & crew order for this performance.', 'azure-plugin'), $row, $number);
                continue;
            }
            $name = isset($cols['name']) ? (string) ($cells[$cols['name']] ?? '') : '';
            if (preg_match('/^\'[=+\-@]/', $name)) {
                $name = substr($name, 1);
            }
            $parsed = self::parse($seat);
            $key = key($parsed['seats']);
            if ($key === null || count($parsed['seats']) !== 1 || $parsed['seats'][$key] !== '') {
                /* translators: 1: row number, 2: what was in the seat column */
                $errors[] = sprintf(__('Row %1$d: "%2$s" isn\'t a seat. Write it like D4.', 'azure-plugin'), $row, $seat);
                continue;
            }
            $oid = $numbers[$number];
            if (isset($orders[$oid][$key])) {
                /* translators: 1: row number, 2: seat such as D4, 3: order number */
                $errors[] = sprintf(__('Row %1$d: %2$s is listed twice for #%3$s.', 'azure-plugin'), $row, str_replace('-', '', $key), $number);
                continue;
            }
            $orders[$oid][$key] = trim(strip_tags($name));
        }
        return array('orders' => $orders, 'errors' => $errors);
    }

    /**
     * Gives each order in the file exactly the seats listed, checking the
     * whole file first so that nothing is saved unless all of it can be.
     * Seats may move between orders in the same file. Never emails.
     *
     * @param array<int, array<string,string>> $wanted order id => seat key => name
     * @param array<int,int> $paid order id => tickets bought, for every cast & crew order of the performance
     * @param callable $get_order order id => WC_Order
     * @param array<int,string> $numbers order id => number as shown
     * @return array{ok:bool, partial?:bool, errors?:string[], orders?:int, added?:int, removed?:int, renamed?:int}
     *         partial: the file passed its checks but a save then failed part-way.
     */
    public static function import($product_id, array $wanted, array $paid, callable $get_order, array $numbers = array()) {
        $show = function ($oid) use ($numbers) {
            return '#' . ($numbers[$oid] ?? $oid);
        };
        $seat = function ($key) {
            return str_replace('-', '', $key);
        };
        $sections = Azure_Tickets_Config::sections($product_id);
        $have = array();
        $owner = array();
        foreach (array_keys($paid) as $oid) {
            $have[$oid] = self::assigned($oid, $product_id);
            foreach ($have[$oid] as $key => $r) {
                $owner[$key] = $oid;
            }
        }
        $errors = array();
        $claimed = array();
        foreach ($wanted as $oid => $seats) {
            if (!isset($paid[$oid])) {
                /* translators: %s: order number */
                $errors[] = sprintf(__('%s isn\'t a paid cast & crew order for this performance.', 'azure-plugin'), $show($oid));
                continue;
            }
            if (count($seats) > $paid[$oid]) {
                /* translators: 1: order number, 2: tickets bought, 3: seats listed */
                $errors[] = sprintf(__('%1$s paid for %2$d tickets but %3$d seats are listed.', 'azure-plugin'), $show($oid), $paid[$oid], count($seats));
            }
            foreach (array_keys($seats) as $key) {
                if (isset($claimed[$key])) {
                    /* translators: 1: seat such as D4, 2: order number, 3: order number */
                    $errors[] = sprintf(__('%1$s is listed for both %2$s and %3$s.', 'azure-plugin'), $seat($key), $show($claimed[$key]), $show($oid));
                    continue;
                }
                $claimed[$key] = $oid;
                if (self::locate($sections, $key) === '') {
                    /* translators: 1: seat such as D4, 2: order number */
                    $errors[] = sprintf(__('%1$s (%2$s) isn\'t a seat in this venue.', 'azure-plugin'), $seat($key), $show($oid));
                }
            }
        }
        $taken = Azure_Tickets_Inventory::taken($product_id);
        foreach ($claimed as $key => $oid) {
            $sid = self::locate($sections, $key);
            if ($sid === '' || !isset($taken[$sid][$key])) {
                continue;
            }
            $holder = $owner[$key] ?? 0;
            if ($holder === $oid || ($holder && isset($wanted[$holder]) && !isset($wanted[$holder][$key]))) {
                continue;
            }
            $errors[] = $holder
                /* translators: 1: seat such as D4, 2: order number wanting it, 3: order number holding it */
                ? sprintf(__('%1$s (%2$s) is already assigned to %3$s.', 'azure-plugin'), $seat($key), $show($oid), $show($holder))
                /* translators: 1: seat such as D4, 2: order number */
                : sprintf(__('%1$s (%2$s) has already been bought or is in someone\'s cart.', 'azure-plugin'), $seat($key), $show($oid));
        }
        if ($errors) {
            return array('ok' => false, 'errors' => $errors);
        }

        $as_text = function (array $seats) {
            $lines = array();
            foreach ($seats as $key => $name) {
                $lines[] = trim(str_replace('-', '', $key) . ' ' . $name);
            }
            return implode("\n", $lines);
        };
        $totals = array('added' => 0, 'removed' => 0, 'renamed' => 0);
        $tally = function ($r) use (&$totals) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $r[$k] ?? 0;
            }
        };
        // Let go of seats first, so a seat moving to another order in the file is free when it gets there.
        foreach ($wanted as $oid => $seats) {
            if ($have[$oid]) {
                $tally(self::save($get_order($oid), $product_id, $as_text(array_intersect_key($seats, $have[$oid])), $paid[$oid]));
            }
        }
        foreach ($wanted as $oid => $seats) {
            $r = self::save($get_order($oid), $product_id, $as_text($seats), $paid[$oid]);
            if (!$r['ok']) {
                $errors[] = $show($oid) . ': ' . implode(' ', $r['errors']);
                continue;
            }
            $tally($r);
        }
        if ($errors) {
            return array('ok' => false, 'partial' => true, 'errors' => array_merge(array(__('Some orders were saved before this went wrong; the rest were not:', 'azure-plugin')), $errors));
        }
        return array('ok' => true, 'orders' => count($wanted)) + $totals;
    }

    /**
     * @return array<string, array<string, int[]>> section id => row letter => free seat numbers
     */
    public static function free_seats($product_id) {
        $taken = Azure_Tickets_Inventory::taken($product_id);
        $out = array();
        foreach (Azure_Tickets_Config::sections($product_id) as $sid => $s) {
            if ($s['type'] !== 'rectangle') {
                continue;
            }
            foreach (Azure_Tickets_Layout::seats($s['block']) as $key => $type) {
                if (isset($taken[$sid][$key])) {
                    continue;
                }
                list($row, $num) = explode('-', $key);
                $out[$sid][$row][] = (int) $num;
            }
        }
        return $out;
    }
}
