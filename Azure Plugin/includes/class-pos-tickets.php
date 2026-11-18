<?php
/**
 * Tickets at the Tap to Pay till: the event's linked performance only.
 *
 * Seats and general-admission places are held under the sale's own hold
 * key while the volunteer collects payment, move onto the order with it,
 * and are sold by the tickets module's own paid / cancelled hooks, which
 * also create (and cancel) the tickets. Once paid, the sale's tickets are
 * checked in: the buyer is standing at the door.
 *
 * Unlike online sales, the till ignores when online sales close and the
 * per-order and name rules, but never sells sections kept for cast and
 * crew families, and never shows who bought which seat.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-pos-events.php';
require_once __DIR__ . '/class-pos-stripe.php';

class Azure_POS_Tickets {

    const MAX_TICKETS = 20;
    const HOLD_PREFIX = 'pos:';

    /** Seat states guests may see; the app reads states it doesn't know as free, so taken ones are "sold". */
    const FREE = 'free';
    const TAKEN = 'sold';
    const CLOSED = 'closed';

    public static function available() {
        return class_exists('Azure_Tickets_Config') && class_exists('Azure_Tickets_Inventory') && class_exists('Azure_Tickets_Cart');
    }

    /** The event's performance when the till can sell its tickets, else 0. */
    public static function performance($event_id) {
        if (!self::available()) {
            return 0;
        }
        $id = Azure_POS_Events::performance($event_id);
        return $id && Azure_Tickets_Config::is_performance($id) ? $id : 0;
    }

    public static function hold_key() {
        return self::HOLD_PREFIX . bin2hex(random_bytes(8));
    }

    /** Sections the till sells: switched on, and open to everyone. */
    private static function sections($performance) {
        return array_filter(Azure_Tickets_Config::sections($performance), function ($s) {
            return $s['enabled'] && $s['access'] !== Azure_Tickets_Config::ACCESS_FAMILIES
                && in_array($s['type'], array('rectangle', 'general_admission'), true);
        });
    }

    private static function left(array $section, $taken) {
        $left = $section['capacity'] - $taken;
        if ($section['limit'] > 0) {
            $left = min($left, $section['limit'] - $taken);
        }
        return max(0, $left);
    }

    /**
     * The catalog's tickets: prices and places left per section.
     *
     * @return array|null null when the event sells no tickets.
     */
    public static function summary($event_id, $currency) {
        $performance = self::performance($event_id);
        if (!$performance) {
            return null;
        }
        $taken = Azure_Tickets_Inventory::taken($performance);
        $sections = array();
        foreach (self::sections($performance) as $sid => $s) {
            $ada = Azure_Tickets_Config::seat_price($performance, $s, Azure_Tickets_Config::KIND_ADA);
            $sections[] = array(
                'id'              => (string) $sid,
                'name'            => html_entity_decode((string) $s['name'], ENT_QUOTES, 'UTF-8'),
                'kind'            => $s['type'] === 'rectangle' ? 'seats' : 'general',
                'price'           => (string) $s['price'],
                'price_cents'     => Azure_POS_Stripe::to_minor($s['price'], $currency),
                'ada_price_cents' => Azure_POS_Stripe::to_minor($ada, $currency),
                'left'            => self::left($s, count($taken[$sid] ?? array())),
            );
        }
        $product = wc_get_product($performance);
        return array(
            'performance_id' => $performance,
            'name'           => $product ? html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8') : '',
            'starts_at'      => (int) Azure_Tickets_Config::event_ts($performance),
            'has_seats'      => in_array('seats', array_column($sections, 'kind'), true),
            'max'            => self::MAX_TICKETS,
            'sections'       => $sections,
        );
    }

    /**
     * The performance's seat map, laid out like the Visualizer's for the app,
     * but with every seat only free, taken or closed: no names, orders or
     * check-in times.
     *
     * @return array|WP_Error
     */
    public static function seat_map($event_id) {
        $performance = self::performance($event_id);
        if (!$performance) {
            return new WP_Error('ptsa_pos_no_tickets', 'Tickets aren\'t sold at this event.', array('status' => 404));
        }
        if (!class_exists('Azure_Tickets_Visualizer')) {
            require_once __DIR__ . '/class-tickets-visualizer.php';
        }
        $snap = Azure_Tickets_Visualizer::snapshot($performance);
        if (!$snap) {
            return new WP_Error('ptsa_pos_no_seats', 'This performance has no seating plan.', array('status' => 404));
        }
        $map = Azure_Tickets_Visualizer::app_map($snap);
        $sellable = self::sections($performance);

        $seats = array();
        foreach ((array) $map['seats'] as $seat) {
            $state = (string) ($seat['st'] ?? '');
            if (!isset($sellable[$seat['s']]) || $state === self::CLOSED) {
                $state = self::CLOSED;
            } elseif ($state !== self::FREE) {
                $state = self::TAKEN;
            }
            $seats[] = array(
                's'     => (string) $seat['s'],
                'k'     => (string) $seat['k'],
                'x'     => $seat['x'],
                'y'     => $seat['y'],
                'w'     => $seat['w'],
                'h'     => $seat['h'],
                'a'     => $seat['a'],
                'n'     => $seat['n'],
                't'     => (string) $seat['t'],
                'st'    => $state,
                'label' => (string) $seat['label'],
                'color' => (string) ($seat['color'] ?? ''),
            );
        }
        $shapes = array();
        foreach ((array) $map['shapes'] as $shape) {
            if (($shape['kind'] ?? '') === 'ga') {
                $ga = (array) ($shape['ga'] ?? array());
                $capacity = (int) ($ga['capacity'] ?? 0);
                $free = (int) ($ga['free'] ?? 0);
                $shape['ga'] = array(
                    'capacity' => $capacity,
                    'sold'     => max(0, $capacity - $free),
                    'free'     => $free,
                    'enabled'  => !empty($ga['enabled']),
                );
            }
            $shapes[] = $shape;
        }
        return array(
            'width'       => $map['width'],
            'height'      => $map['height'],
            'venue'       => (string) ($map['venue'] ?? ''),
            'shapes'      => $shapes,
            'rows'        => $map['rows'],
            'seats'       => $seats,
            'performance' => self::performance_payload($performance),
        );
    }

    /** What the app needs to title the map; unlike the board's, never the door PIN. */
    private static function performance_payload($performance) {
        $product = wc_get_product($performance);
        $ts = (int) Azure_Tickets_Config::event_ts($performance);
        return array(
            'id'        => (int) $performance,
            'name'      => $product ? html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8') : '',
            'starts_at' => $ts,
            'when'      => $ts ? wp_date('D j M, g:ia', $ts) : '',
        );
    }

    /**
     * Hold a sale's tickets under $hold_key.
     *
     * @param array $request {seats: [{section_id, seat_key}], general: [{section_id, count}]}
     * @return array|WP_Error Lines: [{section_id, kind, price, seats: [{section_id, seat_key, label, name}]}];
     *                        empty when the sale has no tickets.
     */
    public static function reserve($event_id, $request, $hold_key) {
        $request = is_array($request) ? $request : array();
        $raw_seats = array_slice((array) ($request['seats'] ?? array()), 0, self::MAX_TICKETS + 1);
        $raw_general = array_slice((array) ($request['general'] ?? array()), 0, 20);
        if (!$raw_seats && !$raw_general) {
            return array();
        }
        $performance = self::performance($event_id);
        if (!$performance) {
            return new WP_Error('ptsa_pos_no_tickets', 'Tickets aren\'t sold at this event.', array('status' => 409));
        }
        $sections = self::sections($performance);

        $places = array();
        foreach ($raw_seats as $s) {
            $sid = (string) ($s['section_id'] ?? '');
            $key = (string) ($s['seat_key'] ?? '');
            $section = $sections[$sid] ?? null;
            if (!$section || $section['type'] !== 'rectangle' || isset($places[$sid . '|' . $key]) || Azure_Tickets_Layout::seat_type($section['block'], $key) === null) {
                return new WP_Error('ptsa_pos_seat', 'A seat in the cart can\'t be sold here. Refresh the seat map and choose again.', array('status' => 409));
            }
            $places[$sid . '|' . $key] = array('section_id' => $sid, 'seat_key' => $key);
        }
        $general = array();
        foreach ($raw_general as $g) {
            $sid = (string) ($g['section_id'] ?? '');
            $count = (int) ($g['count'] ?? 0);
            if ($count < 1) {
                continue;
            }
            $section = $sections[$sid] ?? null;
            if (!$section || $section['type'] !== 'general_admission' || isset($general[$sid])) {
                return new WP_Error('ptsa_pos_seat', 'A ticket in the cart can\'t be sold here. Refresh the products and choose again.', array('status' => 409));
            }
            $general[$sid] = $count;
        }

        $wanted = $general;
        foreach ($places as $p) {
            $wanted[$p['section_id']] = ($wanted[$p['section_id']] ?? 0) + 1;
        }
        $total = array_sum($wanted);
        if ($total < 1) {
            return array();
        }
        if ($total > self::MAX_TICKETS) {
            return new WP_Error('ptsa_pos_too_many', sprintf('Sell up to %d tickets at a time.', self::MAX_TICKETS), array('status' => 400));
        }
        foreach ($wanted as $sid => $n) {
            $left = self::left($sections[$sid], Azure_Tickets_Inventory::count_taken($performance, array($sid)));
            if ($n > $left) {
                return new WP_Error('ptsa_pos_sold_out', $left > 0
                    ? sprintf('Only %d left in %s.', $left, $sections[$sid]['name'])
                    : sprintf('%s is sold out.', $sections[$sid]['name']), array('status' => 409));
            }
        }

        $claim = Azure_Tickets_Inventory::claim($performance, array_values($places), $hold_key);
        if (!$claim['ok']) {
            $labels = array_map(function ($p) use ($sections) {
                return Azure_Tickets_Layout::seat_label($sections[$p['section_id']]['block'], $p['seat_key']);
            }, $claim['taken']);
            return new WP_Error('ptsa_pos_seat_taken', sprintf('Someone just took %s. Choose another seat.', implode('; ', $labels)), array('status' => 409, 'taken' => $claim['taken']));
        }
        $claimed = array();
        foreach ($general as $sid => $count) {
            $keys = Azure_Tickets_Inventory::claim_general($performance, $sid, $count, $sections[$sid]['capacity'], $hold_key);
            if ($keys === false) {
                self::release($performance, $hold_key);
                return new WP_Error('ptsa_pos_sold_out', sprintf('There aren\'t enough places left in %s.', $sections[$sid]['name']), array('status' => 409));
            }
            $claimed[$sid] = $keys;
        }

        $lines = array();
        foreach ($places as $p) {
            $section = $sections[$p['section_id']];
            $kind = Azure_Tickets_Config::seat_kind($section['block'], $p['seat_key']);
            $lines[$p['section_id'] . '|' . $kind]['seats'][] = array(
                'section_id' => $p['section_id'],
                'seat_key'   => $p['seat_key'],
                'label'      => Azure_Tickets_Layout::seat_label($section['block'], $p['seat_key']),
                'name'       => '',
            );
        }
        foreach ($claimed as $sid => $keys) {
            foreach ($keys as $key) {
                $lines[$sid . '|' . Azure_Tickets_Config::KIND_REGULAR]['seats'][] = array(
                    'section_id' => $sid,
                    'seat_key'   => (string) $key,
                    'label'      => $sections[$sid]['name'],
                    'name'       => '',
                );
            }
        }
        $out = array();
        foreach ($lines as $ref => $line) {
            list($sid, $kind) = explode('|', $ref);
            $out[] = array(
                'performance' => $performance,
                'section_id'  => $sid,
                'kind'        => $kind,
                'price'       => (float) Azure_Tickets_Config::seat_price($performance, $sections[$sid], $kind),
                'seats'       => $line['seats'],
            );
        }
        return $out;
    }

    /** Let go of everything a sale holds that isn't on an order yet. */
    public static function release($performance, $hold_key) {
        if ($performance && $hold_key !== '') {
            Azure_Tickets_Inventory::release($hold_key, $performance, Azure_Tickets_Inventory::held_by($performance, $hold_key));
        }
    }

    /** Ticket lines on the order, carrying the same item meta as online sales. */
    public static function add_to_order($order, array $lines, $hold_key) {
        foreach ($lines as $line) {
            $product = wc_get_product($line['performance']);
            $count = count($line['seats']);
            $item_id = $product ? $order->add_product($product, $count, array('subtotal' => $line['price'] * $count, 'total' => $line['price'] * $count)) : 0;
            $item = $item_id ? $order->get_item($item_id) : null;
            if (!$item) {
                return false;
            }
            $item->add_meta_data(Azure_Tickets_Cart::ITEM_SEATS, $line['seats'], true);
            $item->add_meta_data(Azure_Tickets_Cart::ITEM_HOLD, $hold_key, true);
            $item->add_meta_data(Azure_Tickets_Cart::ITEM_SECTION, $line['section_id'], true);
            $item->add_meta_data(Azure_Tickets_Cart::ITEM_KIND, $line['kind'], true);
            $item->add_meta_data(__('Ticket type', 'azure-plugin'), Azure_Tickets_Cart::kind_label($line['kind']), true);
            $item->add_meta_data(__('Seats', 'azure-plugin'), implode("\n", array_column($line['seats'], 'label')), true);
            $item->save();
        }
        return true;
    }

    /** The held places move onto the order, so they last until it is paid or cancelled. */
    public static function attach($order, array $lines, $hold_key) {
        foreach ($lines as $line) {
            $places = array_map(function ($s) {
                return array('section_id' => $s['section_id'], 'seat_key' => $s['seat_key']);
            }, $line['seats']);
            Azure_Tickets_Inventory::attach_order($order->get_id(), $hold_key, $line['performance'], $places);
        }
        $order->update_meta_data(Azure_Tickets_Cart::ORDER_ATTACHED, 1);
    }

    /**
     * Check in the order's tickets.
     *
     * @return int Tickets checked in.
     */
    public static function check_in_order($order) {
        global $wpdb;
        if (!self::available() || !class_exists('Azure_Tickets_Checkin') || !isset($wpdb)) {
            return 0;
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ticket_code, product_id FROM {$wpdb->prefix}azure_tickets WHERE order_id = %d AND status = 'active'",
            (int) $order->get_id()
        ));
        if (!$rows) {
            return 0;
        }
        $checkin = new Azure_Tickets_Checkin();
        $done = 0;
        foreach ($rows as $row) {
            $result = $checkin->check_in((string) $row->ticket_code, get_current_user_id(), (int) $row->product_id);
            $done += empty($result['success']) ? 0 : 1;
        }
        if ($done) {
            $order->add_order_note(sprintf('%d ticket(s) checked in at the till.', $done));
        }
        return $done;
    }
}
