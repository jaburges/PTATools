<?php
/**
 * What a ticket product sells from its venue layout: per-section price,
 * limit, on/off sale, opening time and who may buy, plus the accessible-seat
 * release switch, the ADA price, the eligible-family rules, seat-assignment
 * (VIP) products and the extras offered after choosing seats.
 *
 * A ticket product is a variable product and each variation is one
 * performance, with its own linked event, date and online sales close. Every
 * other setting is on the product and shared by its performances. Seats,
 * holds and tickets belong to the performance, so methods here take a
 * performance id: a variation, or the product itself for a ticket product
 * without variations (one performance, as they were before variations).
 *
 * The layout supplies each section's default price; a blank price here
 * means "use the layout's". Times are entered in the site's timezone.
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
    const META_ADA_PRICE = '_ticket_ada_price';
    const META_SALES_CLOSE = '_ticket_sales_close';
    const META_VIP_PRODUCTS = '_ticket_vip_products';
    const META_VIP_CLOSE = '_ticket_vip_close';
    const META_EXTRAS = '_ticket_extras';
    const META_EXTRAS_HEADING = '_ticket_extras_heading';
    const META_HAS_SPONSORS = '_ticket_has_sponsors';
    const VIP_CLOSE_OPTION = 'azure_tickets_vip_close';

    const ACCESS_ALL = 'all';
    const ACCESS_FAMILIES = 'families';
    const DEFAULT_ORDER_LIMIT = 10;
    const KIND_REGULAR = 'regular';
    const KIND_ADA = 'ada';

    /** @var array<int,array> */
    private static $layouts = array();
    /** @var array<int,int> performance id => product holding its settings */
    private static $owners = array();
    /** @var int|null Fixed "now" for tests. */
    public static $now = null;

    public static function now() {
        return self::$now ?? time();
    }

    /**
     * The product holding a performance's shared settings: the parent of a
     * variation, otherwise the id itself.
     */
    public static function owner($performance_id) {
        $id = (int) $performance_id;
        if (!isset(self::$owners[$id])) {
            $parent = (function_exists('get_post_type') && get_post_type($id) === 'product_variation') ? (int) wp_get_post_parent_id($id) : 0;
            self::$owners[$id] = $parent ?: $id;
        }
        return self::$owners[$id];
    }

    /**
     * An Event Ticket product, or a performance (variation) of one.
     *
     * @param WC_Product|false|null $product
     */
    public static function is_ticket_product($product) {
        if (!$product || !is_object($product) || !method_exists($product, 'get_type')) {
            return false;
        }
        if ($product->get_type() === 'ticket') {
            return true;
        }
        if ($product->get_type() !== 'variation' || !method_exists($product, 'get_parent_id')) {
            return false;
        }
        $parent = (int) $product->get_parent_id();
        if (class_exists('WC_Product_Factory') && method_exists('WC_Product_Factory', 'get_product_type')) {
            return WC_Product_Factory::get_product_type($parent) === 'ticket';
        }
        $p = function_exists('wc_get_product') ? wc_get_product($parent) : null;
        return $p && $p->get_type() === 'ticket';
    }

    /**
     * The performances of a ticket product, earliest first. With variations
     * these are its enabled variations; without, the product itself, plus any
     * other ticket products linked to the same event (how several
     * performances were set up before variations).
     *
     * @param int $id The product or any of its performances.
     * @return int[]
     */
    public static function performances($id) {
        $owner = self::owner($id);
        $product = function_exists('wc_get_product') ? wc_get_product($owner) : null;
        $ids = ($product && method_exists($product, 'get_visible_children')) ? array_map('intval', (array) $product->get_visible_children()) : array();
        if (!$ids) {
            $ids = self::linked_products($owner);
        }
        usort($ids, function ($a, $b) {
            $da = self::event_date($a);
            $db = self::event_date($b);
            if ($da === '' || $db === '') {
                return ($da === '') <=> ($db === '') ?: $a - $b;
            }
            return strcmp($da, $db) ?: $a - $b;
        });
        return $ids;
    }

    /**
     * Whether this id is something seats are sold for: a performance
     * variation, or a ticket product without variations.
     */
    public static function is_performance($id) {
        $id = (int) $id;
        $product = $id > 0 && function_exists('wc_get_product') ? wc_get_product($id) : null;
        return self::is_ticket_product($product) && in_array($id, self::performances($id), true);
    }

    /**
     * @return int[] Performances whose online sales are still open.
     */
    public static function open_performances($id) {
        return array_values(array_filter(self::performances($id), function ($p) {
            return !self::sales_closed($p);
        }));
    }

    /**
     * "2026-11-13T19:00" as entered for the performance, or ''.
     */
    public static function event_date($performance_id) {
        return (string) get_post_meta((int) $performance_id, self::META_EVENT_DATE, true);
    }

    public static function event_ts($performance_id) {
        return self::local_ts(self::event_date($performance_id));
    }

    /**
     * @return int The calendar event the performance is linked to, or 0.
     */
    public static function event_id($performance_id) {
        return (int) get_post_meta((int) $performance_id, self::META_EVENT_ID, true);
    }

    /**
     * @return int[] A product without variations and the other ticket products linked to its event.
     */
    private static function linked_products($product_id) {
        $event = self::event_id($product_id);
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
        return array_values(array_unique(array_merge(array((int) $product_id), array_map('intval', (array) $ids))));
    }

    /**
     * "2026-10-30T09:00" (or with a space) in the site's timezone → Unix time; 0 when blank or invalid.
     */
    public static function local_ts($value) {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?)?$/', $value)) {
            return 0;
        }
        try {
            $dt = new DateTime(str_replace('T', ' ', $value), wp_timezone());
        } catch (Exception $e) {
            return 0;
        }
        return $dt->getTimestamp();
    }

    /**
     * Normalise an entered date-time to "Y-m-d\TH:i" (what datetime-local inputs use), or ''.
     */
    public static function clean_local($value) {
        $ts = self::local_ts($value);
        return $ts ? wp_date('Y-m-d\TH:i', $ts) : '';
    }

    public static function venue_id($product_id) {
        return (int) get_post_meta(self::owner($product_id), self::META_VENUE, true);
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
        self::$owners = array();
    }

    /**
     * `enabled` is the on-sale switch; `on_sale` is whether it can be bought
     * right now (switched on, past its opening time, and online sales for the
     * performance not closed). `opens_at` is the opening time while it is
     * still ahead, else 0.
     *
     * @return array<string,array{id:string,type:string,name:string,price:float,default_price:float,limit:int,enabled:bool,on_sale:bool,from:string,opens_at:int,access:string,capacity:int,block:array}>
     */
    public static function sections($product_id) {
        $stored = json_decode((string) get_post_meta(self::owner($product_id), self::META_SECTIONS, true), true);
        $stored = is_array($stored) ? $stored : array();
        $closed = self::sales_closed($product_id);
        $now = self::now();
        $out = array();
        foreach (Azure_Tickets_Layout::sections(self::layout($product_id)) as $id => $block) {
            $s = is_array($stored[$id] ?? null) ? $stored[$id] : array();
            $price = (isset($s['price']) && $s['price'] !== '' && $s['price'] !== null) ? (float) $s['price'] : (float) $block['price'];
            $enabled = !array_key_exists('on_sale', $s) || !empty($s['on_sale']);
            $from = self::clean_local($s['from'] ?? '');
            $opens = $from !== '' ? self::local_ts($from) : 0;
            $out[$id] = array(
                'id'            => $id,
                'type'          => $block['type'],
                'name'          => $block['name'],
                'price'         => round(max(0, $price), 2),
                'default_price' => (float) $block['price'],
                'limit'         => max(0, (int) ($s['limit'] ?? 0)),
                'enabled'       => $enabled,
                'on_sale'       => $enabled && !$closed && $opens <= $now,
                'from'          => $from,
                'opens_at'      => $opens > $now ? $opens : 0,
                'access'        => ($s['access'] ?? '') === self::ACCESS_FAMILIES ? self::ACCESS_FAMILIES : self::ACCESS_ALL,
                'capacity'      => Azure_Tickets_Layout::section_capacity($block),
                'block'         => $block,
            );
        }
        return $out;
    }

    /**
     * Price of one seat: wheelchair spaces use the ADA price when one is set.
     */
    public static function seat_price($product_id, array $section, $kind) {
        if ($kind === self::KIND_ADA) {
            $ada = self::ada_price($product_id);
            if ($ada !== null) {
                return $ada;
            }
        }
        return (float) $section['price'];
    }

    public static function ada_price($product_id) {
        $v = get_post_meta(self::owner($product_id), self::META_ADA_PRICE, true);
        return ($v === '' || $v === null || $v === false) ? null : round(max(0, (float) $v), 2);
    }

    public static function seat_kind(array $block, $seat_key) {
        return Azure_Tickets_Layout::seat_type($block, $seat_key) === Azure_Tickets_Layout::SEAT_ACCESSIBLE ? self::KIND_ADA : self::KIND_REGULAR;
    }

    /**
     * When online sales stop: the time set on the performance, else midnight at
     * the start of the performance day, else never (0).
     */
    public static function sales_close_ts($product_id) {
        $set = self::local_ts(get_post_meta((int) $product_id, self::META_SALES_CLOSE, true));
        if ($set) {
            return $set;
        }
        $date = self::event_date($product_id);
        return $date !== '' ? self::local_ts(substr(str_replace('T', ' ', $date), 0, 10)) : 0;
    }

    public static function sales_closed($product_id) {
        $close = self::sales_close_ts($product_id);
        return $close > 0 && self::now() >= $close;
    }

    /**
     * Products and variations whose orders get seats assigned by hand on the
     * Assign seats screen (e.g. a hidden VIP cast & crew ticket).
     *
     * @return int[]
     */
    public static function vip_products($product_id) {
        return array_values(array_filter(array_map('intval', (array) get_post_meta(self::owner($product_id), self::META_VIP_PRODUCTS, true))));
    }

    /**
     * The earliest section opening time, when VIP sales should stop; 0 for none.
     */
    public static function first_opening($product_id) {
        $times = array();
        foreach (self::sections($product_id) as $s) {
            if ($s['enabled'] && $s['from'] !== '') {
                $times[] = self::local_ts($s['from']);
            }
        }
        return $times ? min($times) : 0;
    }

    /**
     * Rebuilds the VIP product → close time map read by is_purchasable on
     * every page, so that check doesn't have to search ticket products.
     */
    public static function rebuild_vip_close_map() {
        if (!function_exists('wc_get_products')) {
            return;
        }
        $map = array();
        foreach ((array) wc_get_products(array('type' => 'ticket', 'status' => array('publish', 'private', 'draft'), 'limit' => 200, 'return' => 'ids')) as $pid) {
            if (get_post_meta($pid, self::META_VIP_CLOSE, true) !== 'yes') {
                continue;
            }
            $close = self::first_opening($pid);
            if (!$close) {
                continue;
            }
            foreach (self::vip_products($pid) as $vip) {
                $map[$vip] = isset($map[$vip]) ? min($map[$vip], $close) : $close;
            }
        }
        update_option(self::VIP_CLOSE_OPTION, $map, false);
    }

    /**
     * @return int Close time for a VIP product or variation, 0 when it doesn't close.
     */
    public static function vip_close_ts($product_id, $parent_id = 0) {
        $map = (array) get_option(self::VIP_CLOSE_OPTION, array());
        $times = array_filter(array((int) ($map[(int) $product_id] ?? 0), (int) ($map[(int) $parent_id] ?? 0)));
        return $times ? min($times) : 0;
    }

    /**
     * @return int[] Products offered on the extras step after choosing seats.
     */
    public static function extras($product_id) {
        return array_values(array_filter(array_map('intval', (array) get_post_meta(self::owner($product_id), self::META_EXTRAS, true))));
    }

    public static function extras_heading($product_id) {
        $h = trim((string) get_post_meta(self::owner($product_id), self::META_EXTRAS_HEADING, true));
        return $h !== '' ? $h : __('Jazz up your theater experience', 'azure-plugin');
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
                'from'    => self::clean_local($row['from'] ?? ''),
                'access'  => ($row['access'] ?? '') === self::ACCESS_FAMILIES ? self::ACCESS_FAMILIES : self::ACCESS_ALL,
            );
        }
        return wp_json_encode($out);
    }

    public static function release_accessible($product_id) {
        return get_post_meta(self::owner($product_id), self::META_RELEASE_ACCESSIBLE, true) === 'yes';
    }

    public static function require_names($product_id) {
        return get_post_meta(self::owner($product_id), self::META_REQUIRE_NAMES, true) === 'yes';
    }

    public static function has_sponsors($product_id) {
        return get_post_meta(self::owner($product_id), self::META_HAS_SPONSORS, true) === 'yes';
    }

    public static function order_limit($product_id) {
        $n = (int) get_post_meta(self::owner($product_id), self::META_ORDER_LIMIT, true);
        return $n > 0 ? $n : self::DEFAULT_ORDER_LIMIT;
    }

    /**
     * @return array{emails:string[],products:int[],since:string,limit:int}
     */
    public static function family_rules($product_id) {
        $product_id = self::owner($product_id);
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
     * Price range for the shop listing: switched-on sections, including
     * those that open later, while online sales for any performance are open.
     *
     * @return float[]|null
     */
    public static function listed_price_range($product_id) {
        if (!self::open_performances($product_id)) {
            return null;
        }
        $prices = array();
        foreach (self::sections($product_id) as $s) {
            if ($s['enabled']) {
                $prices[] = $s['price'];
            }
        }
        return $prices ? array(min($prices), max($prices)) : null;
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
