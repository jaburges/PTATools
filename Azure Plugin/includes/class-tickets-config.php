<?php
/**
 * What a ticket product (one performance) sells from its venue layout:
 * per-section price, limit, on/off sale and who may buy, plus the
 * accessible-seat release switch and the eligible-family rules.
 *
 * The layout supplies each section's default price; a blank price here
 * means "use the layout's".
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Config {

    const META_VENUE = '_ticket_venue_id';
    const META_SECTIONS = '_ticket_sections';
    const META_RELEASE_ACCESSIBLE = '_ticket_release_accessible';
    const META_FAMILY_EMAILS = '_ticket_family_emails';
    const META_FAMILY_PRODUCTS = '_ticket_family_products';
    const META_FAMILY_SINCE = '_ticket_family_since';
    const META_FAMILY_LIMIT = '_ticket_family_limit';
    const META_ORDER_LIMIT = '_tickets_per_order_limit';
    const META_EVENT_DATE = '_ticket_event_date';
    const META_EVENT_ID = '_ticket_event_id';
    const META_REQUIRE_NAMES = '_ticket_require_names';

    const ACCESS_ALL = 'all';
    const ACCESS_FAMILIES = 'families';
    const DEFAULT_ORDER_LIMIT = 10;

    /** @var array<int,array> */
    private static $layouts = array();

    public static function venue_id($product_id) {
        return (int) get_post_meta((int) $product_id, self::META_VENUE, true);
    }

    public static function layout($product_id) {
        $product_id = (int) $product_id;
        if (!isset(self::$layouts[$product_id])) {
            $venue = self::venue_id($product_id);
            self::$layouts[$product_id] = $venue ? Azure_Tickets_Layout::for_venue($venue) : Azure_Tickets_Layout::empty_layout();
        }
        return self::$layouts[$product_id];
    }

    public static function flush() {
        self::$layouts = array();
    }

    /**
     * @return array<string,array{id:string,type:string,name:string,price:float,default_price:float,limit:int,on_sale:bool,access:string,capacity:int,block:array}>
     */
    public static function sections($product_id) {
        $stored = json_decode((string) get_post_meta((int) $product_id, self::META_SECTIONS, true), true);
        $stored = is_array($stored) ? $stored : array();
        $out = array();
        foreach (Azure_Tickets_Layout::sections(self::layout($product_id)) as $id => $block) {
            $s = is_array($stored[$id] ?? null) ? $stored[$id] : array();
            $price = (isset($s['price']) && $s['price'] !== '' && $s['price'] !== null) ? (float) $s['price'] : (float) $block['price'];
            $out[$id] = array(
                'id'            => $id,
                'type'          => $block['type'],
                'name'          => $block['name'],
                'price'         => round(max(0, $price), 2),
                'default_price' => (float) $block['price'],
                'limit'         => max(0, (int) ($s['limit'] ?? 0)),
                'on_sale'       => !array_key_exists('on_sale', $s) || !empty($s['on_sale']),
                'access'        => ($s['access'] ?? '') === self::ACCESS_FAMILIES ? self::ACCESS_FAMILIES : self::ACCESS_ALL,
                'capacity'      => Azure_Tickets_Layout::section_capacity($block),
                'block'         => $block,
            );
        }
        return $out;
    }

    /**
     * Product edit form rows → stored JSON, keeping only sections in the layout.
     */
    public static function sanitize_sections($posted, array $layout) {
        $posted = is_array($posted) ? $posted : array();
        $out = array();
        foreach (array_keys(Azure_Tickets_Layout::sections($layout)) as $id) {
            $row = is_array($posted[$id] ?? null) ? $posted[$id] : array();
            $price = trim((string) ($row['price'] ?? ''));
            $out[$id] = array(
                'price'   => $price === '' ? '' : round(max(0, (float) $price), 2),
                'limit'   => max(0, (int) ($row['limit'] ?? 0)),
                'on_sale' => !empty($row['on_sale']),
                'access'  => ($row['access'] ?? '') === self::ACCESS_FAMILIES ? self::ACCESS_FAMILIES : self::ACCESS_ALL,
            );
        }
        return wp_json_encode($out);
    }

    public static function release_accessible($product_id) {
        return get_post_meta((int) $product_id, self::META_RELEASE_ACCESSIBLE, true) === 'yes';
    }

    public static function require_names($product_id) {
        return get_post_meta((int) $product_id, self::META_REQUIRE_NAMES, true) === 'yes';
    }

    public static function order_limit($product_id) {
        $n = (int) get_post_meta((int) $product_id, self::META_ORDER_LIMIT, true);
        return $n > 0 ? $n : self::DEFAULT_ORDER_LIMIT;
    }

    /**
     * @return array{emails:string[],products:int[],since:string,limit:int}
     */
    public static function family_rules($product_id) {
        $product_id = (int) $product_id;
        $since = (string) get_post_meta($product_id, self::META_FAMILY_SINCE, true);
        return array(
            'emails'   => self::parse_emails((string) get_post_meta($product_id, self::META_FAMILY_EMAILS, true)),
            'products' => array_values(array_filter(array_map('intval', (array) get_post_meta($product_id, self::META_FAMILY_PRODUCTS, true)))),
            'since'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : self::school_year_start(),
            'limit'    => max(0, (int) get_post_meta($product_id, self::META_FAMILY_LIMIT, true)),
        );
    }

    /**
     * Email addresses from pasted text or a CSV export: any separator, any
     * column, header rows ignored.
     *
     * @return string[] Lowercase, unique, in first-seen order.
     */
    public static function parse_emails($text) {
        preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', (string) $text, $m);
        $out = array();
        foreach ($m[0] as $email) {
            $email = strtolower(trim($email, '.'));
            if (is_email($email)) {
                $out[$email] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * 1 July of the current school year, in the site's timezone.
     */
    public static function school_year_start($now = null) {
        $now = $now ?? current_time('timestamp');
        $year = (int) gmdate('Y', $now);
        if ((int) gmdate('n', $now) < 7) {
            $year--;
        }
        return sprintf('%04d-07-01', $year);
    }

    /**
     * @return float[]|null [lowest, highest] price on sale, or null when nothing is.
     */
    public static function price_range($product_id) {
        $prices = array();
        foreach (self::sections($product_id) as $s) {
            if ($s['on_sale']) {
                $prices[] = $s['price'];
            }
        }
        return $prices ? array(min($prices), max($prices)) : null;
    }

    /**
     * Other performances: ticket products linked to the same event.
     *
     * @return int[] Product ids, earliest performance first, including $product_id.
     */
    public static function performances($product_id) {
        $event = (int) get_post_meta((int) $product_id, self::META_EVENT_ID, true);
        if (!$event || !function_exists('wc_get_products')) {
            return array((int) $product_id);
        }
        $ids = wc_get_products(array(
            'type'       => 'ticket',
            'status'     => 'publish',
            'limit'      => 20,
            'return'     => 'ids',
            'meta_key'   => self::META_EVENT_ID,
            'meta_value' => (string) $event,
        ));
        $ids = array_values(array_unique(array_merge(array((int) $product_id), array_map('intval', (array) $ids))));
        usort($ids, function ($a, $b) {
            return strcmp((string) get_post_meta($a, self::META_EVENT_DATE, true), (string) get_post_meta($b, self::META_EVENT_DATE, true)) ?: $a - $b;
        });
        return $ids;
    }

    /**
     * "Parents · Row A, Seat 3", or the section name for general admission.
     *
     * @param object $ticket Row from the azure_tickets table.
     */
    public static function ticket_seat_label($ticket) {
        $layout = self::layout((int) $ticket->product_id);
        foreach (Azure_Tickets_Layout::sections($layout) as $block) {
            if ($block['id'] === (string) $ticket->section_id) {
                $key = $ticket->row_letter ? $ticket->row_letter . '-' . (int) $ticket->seat_number : '';
                return Azure_Tickets_Layout::seat_label($block, $key);
            }
        }
        return $ticket->row_letter
            ? sprintf(__('Row %1$s, Seat %2$d', 'azure-plugin'), $ticket->row_letter, (int) $ticket->seat_number)
            : __('General Admission', 'azure-plugin');
    }
}
