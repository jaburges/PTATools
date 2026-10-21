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
            "SELECT i.order_id, SUM(q.meta_value) AS qty FROM {$items} i
             JOIN {$meta} q ON q.order_item_id = i.order_item_id AND q.meta_key = '_qty'
             WHERE i.order_item_type = 'line_item' AND EXISTS (
                 SELECT 1 FROM {$meta} p WHERE p.order_item_id = i.order_item_id
                 AND p.meta_key IN ('_product_id', '_variation_id') AND p.meta_value IN ({$in})
             )
             GROUP BY i.order_id ORDER BY i.order_id",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $r) {
            $order = wc_get_order((int) $r['order_id']);
            if ($order && in_array($order->get_status(), self::PAID, true)) {
                $out[(int) $r['order_id']] = (int) $r['qty'];
            }
        }
        return $out;
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
