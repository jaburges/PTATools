<?php
/**
 * Selling seats through WooCommerce.
 *
 * The seat map posts a selection; prepare() checks it against the
 * performance's sections, limits and eligible-family rules, holds the seats
 * and returns one cart line per section. Each line carries its seats and
 * hold key, its quantity can't be edited, and its price is set here from
 * the section, never from the browser.
 *
 * Holds are refreshed whenever the cart is checked (cart page, checkout,
 * placing the order), move onto the order when it is placed, become sold
 * when it is paid, and are released if it fails, is cancelled or refunded.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Cart {

    const ITEM_SEATS = '_ticket_seats';
    const ITEM_HOLD = '_ticket_hold';
    const ITEM_SECTION = '_ticket_section';
    const ITEM_KIND = '_ticket_kind';
    const ITEM_ATTEST = '_ticket_accessible_attested';
    const ORDER_ATTACHED = '_ticket_holds_attached';

    /** @var self|null */
    private static $instance = null;
    /** @var bool Set while this class adds a line, so WooCommerce's own add-to-cart can't add seatless tickets. */
    private static $adding = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_azure_tickets_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_azure_tickets_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_azure_tickets_seat_map', array($this, 'ajax_seat_map'));
        add_action('wp_ajax_nopriv_azure_tickets_seat_map', array($this, 'ajax_seat_map'));

        add_filter('woocommerce_add_to_cart_validation', array($this, 'block_plain_add_to_cart'), 10, 2);
        add_filter('woocommerce_is_purchasable', array($this, 'is_purchasable'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'set_prices'), 20);
        add_action('woocommerce_cart_loaded_from_session', array($this, 'set_prices'), 20);
        add_filter('woocommerce_cart_item_quantity', array($this, 'fixed_quantity'), 10, 3);
        add_filter('woocommerce_update_cart_validation', array($this, 'refuse_quantity_change'), 10, 4);
        add_filter('woocommerce_store_api_product_quantity_editable', array($this, 'store_api_quantity_editable'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'item_data'), 10, 2);
        add_action('woocommerce_cart_item_removed', array($this, 'line_removed'), 10, 2);
        add_action('woocommerce_check_cart_items', array($this, 'check_cart'));
        add_filter('woocommerce_get_price_html', array($this, 'price_html'), 10, 2);

        add_action('woocommerce_checkout_create_order_line_item', array($this, 'copy_to_order_item'), 10, 3);
        add_action('woocommerce_checkout_order_processed', array($this, 'attach_order'), 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'attach_order'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'order_paid'), 5);
        add_action('woocommerce_order_status_completed', array($this, 'order_paid'), 5);
        foreach (array('cancelled', 'failed', 'refunded') as $status) {
            add_action('woocommerce_order_status_' . $status, array($this, 'order_released'));
        }

        add_filter('woocommerce_variation_is_purchasable', array($this, 'vip_purchasable'), 20, 2);
        add_filter('wc_stripe_payment_request_supported_types', array($this, 'stripe_supported_types'));
        add_filter('render_block_woocommerce/cart', array($this, 'cart_block_extras'), 10, 1);
        add_action('woocommerce_after_cart', array($this, 'classic_cart_extras'));
    }

    // ── Selection → cart lines ─────────────────────────────────────────

    /**
     * @param array $request seats: list of {section_id, seat_key, name}; general: list of {section_id, count, names}; accessible_ok: bool
     * @param int $in_cart Tickets for this performance already in the cart.
     * @return array{ok:bool, error?:string, taken?:array, lines?:array}
     */
    public static function prepare($product_id, array $request, $user_id, $hold_key, $in_cart = 0) {
        if (Azure_Tickets_Config::sales_closed($product_id)) {
            return self::fail(__('Online sales for this performance have closed.', 'azure-plugin'));
        }
        $sections = Azure_Tickets_Config::sections($product_id);
        if (!$sections) {
            return self::fail(__('Seating isn\'t set up for this performance yet.', 'azure-plugin'));
        }

        $places = array();
        $wanted = array();
        $accessible = 0;
        $companions = 0;
        foreach ((array) ($request['seats'] ?? array()) as $s) {
            $sid = (string) ($s['section_id'] ?? '');
            $key = (string) ($s['seat_key'] ?? '');
            $section = $sections[$sid] ?? null;
            if (!$section || $section['type'] !== 'rectangle' || isset($places[$sid . '|' . $key])) {
                return self::fail(__('That seat selection isn\'t valid any more. Please reload the seat map.', 'azure-plugin'));
            }
            $type = Azure_Tickets_Layout::seat_type($section['block'], $key);
            if ($type === null || !$section['on_sale']) {
                return self::fail(sprintf(__('%s isn\'t available. Please reload the seat map.', 'azure-plugin'), Azure_Tickets_Layout::seat_label($section['block'], $key)));
            }
            $accessible += $type === Azure_Tickets_Layout::SEAT_ACCESSIBLE ? 1 : 0;
            $companions += $type === Azure_Tickets_Layout::SEAT_COMPANION ? 1 : 0;
            $places[$sid . '|' . $key] = array('section_id' => $sid, 'seat_key' => $key, 'name' => self::name($s['name'] ?? ''));
            $wanted[$sid] = ($wanted[$sid] ?? 0) + 1;
        }
        $general = array();
        foreach ((array) ($request['general'] ?? array()) as $g) {
            $sid = (string) ($g['section_id'] ?? '');
            $count = (int) ($g['count'] ?? 0);
            $section = $sections[$sid] ?? null;
            if ($count < 1) {
                continue;
            }
            if (!$section || $section['type'] !== 'general_admission' || !$section['on_sale'] || isset($general[$sid])) {
                return self::fail(__('That selection isn\'t valid any more. Please reload the seat map.', 'azure-plugin'));
            }
            $general[$sid] = array('count' => $count, 'names' => array_map(array(__CLASS__, 'name'), array_values((array) ($g['names'] ?? array()))));
            $wanted[$sid] = ($wanted[$sid] ?? 0) + $count;
        }

        $total = array_sum($wanted);
        if ($total < 1) {
            return self::fail(__('Choose at least one seat.', 'azure-plugin'));
        }
        $max = Azure_Tickets_Config::order_limit($product_id);
        if ($total + (int) $in_cart > $max) {
            return self::fail(sprintf(_n('You can buy up to %d ticket for this performance.', 'You can buy up to %d tickets for this performance.', $max, 'azure-plugin'), $max));
        }
        $released = Azure_Tickets_Config::release_accessible($product_id);
        if ($accessible && empty($request['accessible_ok']) && !$released) {
            return self::fail(__('Please confirm that someone in your party needs a wheelchair space.', 'azure-plugin'));
        }
        if ($companions > $accessible && !$released) {
            return self::fail(__('Companion seats are for the companion of someone in a wheelchair space. Choose them together with a wheelchair space, or pick other seats.', 'azure-plugin'));
        }
        if (Azure_Tickets_Config::require_names($product_id)) {
            $names = array_column($places, 'name');
            foreach ($general as $g) {
                $names = array_merge($names, array_pad(array_slice($g['names'], 0, $g['count']), $g['count'], ''));
            }
            if (in_array('', $names, true)) {
                return self::fail(__('Please enter a name for each ticket.', 'azure-plugin'));
            }
        }

        $family_sections = array_keys(array_filter($sections, function ($s) {
            return $s['access'] === Azure_Tickets_Config::ACCESS_FAMILIES;
        }));
        $family_wanted = array_sum(array_intersect_key($wanted, array_flip($family_sections)));
        if ($family_wanted > 0) {
            $names = implode(', ', array_map(function ($sid) use ($sections) { return $sections[$sid]['name']; }, array_keys(array_intersect_key($wanted, array_flip($family_sections)))));
            if ($user_id <= 0) {
                return self::fail(sprintf(__('Please sign in to buy seats in %s.', 'azure-plugin'), $names), array('login' => true));
            }
            if (!Azure_Tickets_Access::is_family($user_id, $product_id)) {
                return self::fail(sprintf(__('Seats in %s are for cast and crew families. If you think that includes you, please contact the PTSA.', 'azure-plugin'), $names));
            }
            $limit = Azure_Tickets_Config::family_rules($product_id)['limit'];
            if ($limit > 0) {
                $have = Azure_Tickets_Inventory::count_taken($product_id, $family_sections, '', $user_id);
                if ($have + $family_wanted > $limit) {
                    return self::fail(sprintf(
                        __('Each family can have up to %1$d seats in %2$s for this performance, and you already have %3$d.', 'azure-plugin'),
                        $limit, $names, $have
                    ));
                }
            }
        }

        foreach ($wanted as $sid => $n) {
            $limit = $sections[$sid]['limit'];
            if ($limit > 0) {
                $left = max(0, $limit - Azure_Tickets_Inventory::count_taken($product_id, array($sid)));
                if ($n > $left) {
                    return self::fail(sprintf(_n('Only %1$d seat is left in %2$s.', 'Only %1$d seats are left in %2$s.', $left, 'azure-plugin'), $left, $sections[$sid]['name']));
                }
            }
        }

        $claim = Azure_Tickets_Inventory::claim($product_id, array_values($places), $hold_key, $user_id);
        if (!$claim['ok']) {
            $labels = array_map(function ($p) use ($sections) {
                return Azure_Tickets_Layout::seat_label($sections[$p['section_id']]['block'], $p['seat_key']);
            }, $claim['taken']);
            return self::fail(sprintf(__('Sorry, someone else just took %s. Please choose another seat.', 'azure-plugin'), implode('; ', $labels)), array('taken' => $claim['taken']));
        }
        $claimed_general = array();
        foreach ($general as $sid => $g) {
            $keys = Azure_Tickets_Inventory::claim_general($product_id, $sid, $g['count'], $sections[$sid]['capacity'], $hold_key, $user_id);
            if ($keys === false) {
                Azure_Tickets_Inventory::release($hold_key, $product_id, array_values($places));
                foreach ($claimed_general as $done_sid => $done_keys) {
                    Azure_Tickets_Inventory::release($hold_key, $product_id, self::places_for($done_sid, $done_keys));
                }
                return self::fail(sprintf(__('There aren\'t enough places left in %s.', 'azure-plugin'), $sections[$sid]['name']));
            }
            $claimed_general[$sid] = $keys;
        }

        // One cart line per section and ticket type (Regular / ADA).
        $lines = array();
        foreach ($places as $p) {
            $section = $sections[$p['section_id']];
            $kind = Azure_Tickets_Config::seat_kind($section['block'], $p['seat_key']);
            $lines[$p['section_id'] . '|' . $kind]['seats'][] = array(
                'section_id' => $p['section_id'],
                'seat_key'   => $p['seat_key'],
                'label'      => Azure_Tickets_Layout::seat_label($section['block'], $p['seat_key']),
                'name'       => $p['name'],
            );
        }
        foreach ($claimed_general as $sid => $keys) {
            foreach ($keys as $i => $key) {
                $lines[$sid . '|' . Azure_Tickets_Config::KIND_REGULAR]['seats'][] = array(
                    'section_id' => $sid,
                    'seat_key'   => $key,
                    'label'      => $sections[$sid]['name'],
                    'name'       => $general[$sid]['names'][$i] ?? '',
                );
            }
        }
        $attested = ($accessible && !$released) ? current_time('mysql') : '';
        foreach ($lines as $ref => &$line) {
            list($sid, $kind) = explode('|', $ref);
            $line['section_id'] = $sid;
            $line['kind'] = $kind;
            $line['price'] = Azure_Tickets_Config::seat_price($product_id, $sections[$sid], $kind);
            $line['attested'] = $kind === Azure_Tickets_Config::KIND_ADA ? $attested : '';
        }
        unset($line);
        return array('ok' => true, 'lines' => array_values($lines));
    }

    /**
     * Re-check a cart line and keep its seats held.
     *
     * @return string '' when fine, otherwise why the line has to go.
     */
    public static function refresh($product_id, array $seats, $hold_key, $user_id) {
        if (Azure_Tickets_Config::sales_closed($product_id)) {
            Azure_Tickets_Inventory::release($hold_key, $product_id, self::places_of($seats));
            return __('Online sales for this performance have closed, so its tickets were removed from your cart.', 'azure-plugin');
        }
        $sections = Azure_Tickets_Config::sections($product_id);
        $places = array();
        foreach ($seats as $s) {
            $section = $sections[$s['section_id']] ?? null;
            $valid = $section && $section['on_sale'] && ($section['type'] === 'general_admission'
                ? ctype_digit((string) $s['seat_key']) && (int) $s['seat_key'] <= $section['capacity']
                : Azure_Tickets_Layout::seat_type($section['block'], $s['seat_key']) !== null);
            if (!$valid) {
                return sprintf(__('%s is no longer on sale, so it was removed from your cart.', 'azure-plugin'), $s['label'] ?? '');
            }
            if ($section['access'] === Azure_Tickets_Config::ACCESS_FAMILIES && !Azure_Tickets_Access::is_family($user_id, $product_id)) {
                return sprintf(__('Seats in %s are for cast and crew families, so they were removed from your cart.', 'azure-plugin'), $section['name']);
            }
            $places[] = array('section_id' => $s['section_id'], 'seat_key' => (string) $s['seat_key']);
        }
        $claim = Azure_Tickets_Inventory::claim($product_id, $places, $hold_key, $user_id);
        if (!$claim['ok']) {
            Azure_Tickets_Inventory::release($hold_key, $product_id, $places);
            return __('Your seat hold ran out and someone else took one of the seats, so they were removed from your cart. Please choose again.', 'azure-plugin');
        }
        return '';
    }

    private static function fail($message, array $extra = array()) {
        return array('ok' => false, 'error' => $message) + $extra;
    }

    private static function name($v) {
        return function_exists('mb_substr') ? mb_substr(sanitize_text_field((string) $v), 0, 80) : substr(sanitize_text_field((string) $v), 0, 80);
    }

    private static function places_for($section_id, array $keys) {
        return array_map(function ($k) use ($section_id) {
            return array('section_id' => $section_id, 'seat_key' => (string) $k);
        }, $keys);
    }

    private static function places_of(array $seats) {
        return array_map(function ($s) {
            return array('section_id' => (string) $s['section_id'], 'seat_key' => (string) $s['seat_key']);
        }, $seats);
    }

    public static function is_ticket($product) {
        return $product && is_object($product) && method_exists($product, 'get_type') && $product->get_type() === 'ticket';
    }

    // ── AJAX ───────────────────────────────────────────────────────────

    private static function hold_key() {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        return 'wc:' . WC()->session->get_customer_id();
    }

    private static function in_cart($product_id) {
        $n = 0;
        foreach (WC()->cart ? WC()->cart->get_cart() : array() as $item) {
            if ((int) $item['product_id'] === (int) $product_id && !empty($item[self::ITEM_SEATS])) {
                $n += count($item[self::ITEM_SEATS]);
            }
        }
        return $n;
    }

    /**
     * Everything the seat map needs for one performance and this visitor.
     */
    public static function seat_map_data($product_id, $user_id, $hold_key) {
        $layout = Azure_Tickets_Config::layout($product_id);
        $sections = Azure_Tickets_Config::sections($product_id);
        $family = $user_id > 0 && Azure_Tickets_Access::is_family($user_id, $product_id);
        $taken = Azure_Tickets_Inventory::taken($product_id, $hold_key);
        $config = array();
        foreach ($sections as $sid => $s) {
            $sold = count($taken[$sid] ?? array());
            $left = $s['capacity'] - $sold;
            if ($s['limit'] > 0) {
                $left = min($left, $s['limit'] - $sold);
            }
            $config[$sid] = array(
                'price'      => $s['price'],
                'ada_price'  => Azure_Tickets_Config::seat_price($product_id, $s, Azure_Tickets_Config::KIND_ADA),
                'on_sale'    => $s['on_sale'],
                'opens'      => $s['opens_at'] && $s['enabled'] ? wp_date('D j M, g:ia', $s['opens_at']) : '',
                'access'     => $s['access'],
                'locked'     => $s['access'] === Azure_Tickets_Config::ACCESS_FAMILIES && !$family,
                'left'       => max(0, $left),
            );
        }
        return array(
            'layout'             => $layout,
            'sections'           => $config,
            'taken'              => $taken,
            'max'                => Azure_Tickets_Config::order_limit($product_id) - self::in_cart_safe($product_id),
            'release_accessible' => Azure_Tickets_Config::release_accessible($product_id),
            'signed_in'          => $user_id > 0,
            'family'             => $family,
            'extras'             => (bool) Azure_Tickets_Config::extras($product_id),
        );
    }

    private static function in_cart_safe($product_id) {
        return function_exists('WC') && WC()->cart ? self::in_cart($product_id) : 0;
    }

    public function ajax_seat_map() {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        if (!self::is_ticket(wc_get_product($product_id))) {
            wp_send_json_error(array('message' => __('Tickets aren\'t available for this item.', 'azure-plugin')));
        }
        nocache_headers();
        wp_send_json_success(self::seat_map_data($product_id, get_current_user_id(), self::hold_key()));
    }

    public function ajax_add_to_cart() {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $product = wc_get_product($product_id);
        if (!self::is_ticket($product) || !$product->is_purchasable()) {
            wp_send_json_error(array('message' => __('Tickets aren\'t on sale for this performance.', 'azure-plugin')));
        }
        $request = array(
            'seats'         => json_decode(wp_unslash((string) ($_POST['seats'] ?? '[]')), true),
            'general'       => json_decode(wp_unslash((string) ($_POST['general'] ?? '[]')), true),
            'accessible_ok' => !empty($_POST['accessible_ok']),
        );
        $hold_key = self::hold_key();
        if ($hold_key === '') {
            wp_send_json_error(array('message' => __('Your cart isn\'t available right now. Please reload the page.', 'azure-plugin')));
        }
        $result = self::prepare($product_id, $request, get_current_user_id(), $hold_key, self::in_cart($product_id));
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['error'], 'login' => !empty($result['login']), 'reload' => !empty($result['taken'])));
        }
        self::$adding = true;
        foreach ($result['lines'] as $line) {
            $key = WC()->cart->add_to_cart($product_id, count($line['seats']), 0, array(), array(
                self::ITEM_SEATS   => $line['seats'],
                self::ITEM_HOLD    => $hold_key,
                self::ITEM_SECTION => $line['section_id'],
                self::ITEM_KIND    => $line['kind'],
                self::ITEM_ATTEST  => $line['attested'],
            ));
            if (!$key) {
                Azure_Tickets_Inventory::release($hold_key, $product_id, self::places_of($line['seats']));
            }
        }
        self::$adding = false;
        wp_send_json_success(array('cart_url' => wc_get_cart_url()));
    }

    // ── Cart ───────────────────────────────────────────────────────────

    public function block_plain_add_to_cart($ok, $product_id) {
        if ($ok && !self::$adding && self::is_ticket(wc_get_product($product_id))) {
            wc_add_notice(__('Please choose your seats on the performance page.', 'azure-plugin'), 'error');
            return false;
        }
        return $ok;
    }

    public function is_purchasable($purchasable, $product) {
        if (!self::is_ticket($product)) {
            return $purchasable && !self::vip_closed($product);
        }
        return $product->get_status() === 'publish' && Azure_Tickets_Config::price_range($product->get_id()) !== null;
    }

    public function vip_purchasable($purchasable, $variation) {
        return $purchasable && !self::vip_closed($variation);
    }

    /**
     * A seat-assignment (VIP) product stops selling when the sections held
     * for it open to everyone.
     */
    private static function vip_closed($product) {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return false;
        }
        $parent = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $close = Azure_Tickets_Config::vip_close_ts($product->get_id(), $parent);
        return $close > 0 && Azure_Tickets_Config::now() >= $close;
    }

    public function set_prices($cart) {
        foreach ($cart->get_cart() as $item) {
            if (empty($item[self::ITEM_SEATS]) || !self::is_ticket($item['data'])) {
                continue;
            }
            $sections = Azure_Tickets_Config::sections($item['product_id']);
            $sid = $item[self::ITEM_SECTION] ?? '';
            if (isset($sections[$sid])) {
                $kind = ($item[self::ITEM_KIND] ?? '') === Azure_Tickets_Config::KIND_ADA ? Azure_Tickets_Config::KIND_ADA : Azure_Tickets_Config::KIND_REGULAR;
                $item['data']->set_price(Azure_Tickets_Config::seat_price($item['product_id'], $sections[$sid], $kind));
            }
        }
    }

    /**
     * Stripe hides Apple Pay / Google Pay for carts with product types it
     * doesn't list, which is every ticket cart. Listing tickets only on the
     * checkout page keeps wallets off the product and cart pages (where they
     * would skip seat choice and the extras) and offers them at checkout.
     */
    public function stripe_supported_types($types) {
        $checkout = function_exists('is_checkout') && is_checkout() && !(function_exists('is_cart') && is_cart());
        if ($checkout && is_array($types) && !in_array('ticket', $types, true)) {
            $types[] = 'ticket';
        }
        return $types;
    }

    /**
     * @return int A ticket product in the cart that has extras, or 0.
     */
    private static function cart_extras_product() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item[self::ITEM_SEATS]) && Azure_Tickets_Config::extras($item['product_id'])) {
                return (int) $item['product_id'];
            }
        }
        return 0;
    }

    public function cart_block_extras($html) {
        $pid = self::cart_extras_product();
        return $pid && function_exists('azure_tickets_extras_html') ? $html . azure_tickets_extras_html($pid, 'cart') : $html;
    }

    public function classic_cart_extras() {
        $pid = self::cart_extras_product();
        if ($pid && function_exists('azure_tickets_extras_html')) {
            echo azure_tickets_extras_html($pid, 'cart'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
        }
    }

    public function fixed_quantity($html, $key, $item) {
        return empty($item[self::ITEM_SEATS]) ? $html : (string) count($item[self::ITEM_SEATS]);
    }

    public function refuse_quantity_change($ok, $key, $item, $quantity) {
        if (!empty($item[self::ITEM_SEATS]) && (int) $quantity !== count($item[self::ITEM_SEATS])) {
            wc_add_notice(__('To change tickets, remove them and choose seats again.', 'azure-plugin'), 'error');
            return false;
        }
        return $ok;
    }

    public function store_api_quantity_editable($editable, $product, $item) {
        return empty($item[self::ITEM_SEATS]) ? $editable : false;
    }

    public function item_data($data, $item) {
        if (empty($item[self::ITEM_SEATS])) {
            return $data;
        }
        $date = get_post_meta($item['product_id'], Azure_Tickets_Config::META_EVENT_DATE, true);
        if ($date) {
            $data[] = array('name' => __('Performance', 'azure-plugin'), 'value' => date_i18n('D j M, g:ia', strtotime($date)));
        }
        $data[] = array('name' => __('Ticket type', 'azure-plugin'), 'value' => self::kind_label($item[self::ITEM_KIND] ?? ''));
        foreach ($item[self::ITEM_SEATS] as $s) {
            $data[] = array('name' => __('Seat', 'azure-plugin'), 'value' => $s['label'] . ($s['name'] !== '' ? ' — ' . $s['name'] : ''));
        }
        return $data;
    }

    public function line_removed($key, $cart) {
        $item = $cart->removed_cart_contents[$key] ?? null;
        if ($item && !empty($item[self::ITEM_SEATS])) {
            Azure_Tickets_Inventory::release($item[self::ITEM_HOLD], $item['product_id'], self::places_of($item[self::ITEM_SEATS]));
        }
    }

    public function check_cart() {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item[self::ITEM_SEATS])) {
                continue;
            }
            $problem = self::refresh($item['product_id'], $item[self::ITEM_SEATS], $item[self::ITEM_HOLD], get_current_user_id());
            if ($problem !== '') {
                $cart->remove_cart_item($key);
                wc_add_notice($problem, 'error');
            }
        }
    }

    public static function kind_label($kind) {
        return $kind === Azure_Tickets_Config::KIND_ADA ? __('ADA (wheelchair space)', 'azure-plugin') : __('Regular', 'azure-plugin');
    }

    public function price_html($html, $product) {
        if (!self::is_ticket($product)) {
            return $html;
        }
        $range = Azure_Tickets_Config::listed_price_range($product->get_id());
        if ($range === null) {
            return '';
        }
        return $range[0] === $range[1] ? wc_price($range[0]) : wc_format_price_range($range[0], $range[1]);
    }

    // ── Orders ─────────────────────────────────────────────────────────

    public function copy_to_order_item($item, $key, $values) {
        if (empty($values[self::ITEM_SEATS])) {
            return;
        }
        $item->add_meta_data(self::ITEM_SEATS, $values[self::ITEM_SEATS], true);
        $item->add_meta_data(self::ITEM_HOLD, $values[self::ITEM_HOLD], true);
        $item->add_meta_data(self::ITEM_SECTION, $values[self::ITEM_SECTION] ?? '', true);
        $item->add_meta_data(self::ITEM_KIND, $values[self::ITEM_KIND] ?? Azure_Tickets_Config::KIND_REGULAR, true);
        $labels = array_map(function ($s) {
            return $s['label'] . ($s['name'] !== '' ? ' — ' . $s['name'] : '');
        }, $values[self::ITEM_SEATS]);
        $item->add_meta_data(__('Ticket type', 'azure-plugin'), self::kind_label($values[self::ITEM_KIND] ?? ''), true);
        $item->add_meta_data(__('Seats', 'azure-plugin'), implode("\n", $labels), true);
        if (!empty($values[self::ITEM_ATTEST])) {
            $item->add_meta_data(self::ITEM_ATTEST, $values[self::ITEM_ATTEST], true);
            $item->add_meta_data(
                __('Accessibility', 'azure-plugin'),
                sprintf(__('Buyer confirmed a wheelchair space is needed (%s)', 'azure-plugin'), date_i18n('j M Y, g:ia', strtotime($values[self::ITEM_ATTEST]))),
                true
            );
        }
    }

    public static function order_places($order) {
        $by_product = array();
        foreach ($order->get_items() as $item) {
            $seats = $item->get_meta(self::ITEM_SEATS);
            if (is_array($seats) && $seats) {
                $pid = (int) $item->get_product_id();
                $by_product[$pid] = array_merge($by_product[$pid] ?? array(), self::places_of($seats));
            }
        }
        return $by_product;
    }

    public function attach_order($order) {
        $order = is_object($order) ? $order : wc_get_order($order);
        if (!$order || $order->get_meta(self::ORDER_ATTACHED)) {
            return;
        }
        $short = 0;
        foreach ($order->get_items() as $item) {
            $seats = $item->get_meta(self::ITEM_SEATS);
            if (!is_array($seats) || !$seats) {
                continue;
            }
            $places = self::places_of($seats);
            $moved = Azure_Tickets_Inventory::attach_order($order->get_id(), (string) $item->get_meta(self::ITEM_HOLD), $item->get_product_id(), $places);
            $short += count($places) - $moved;
        }
        $order->update_meta_data(self::ORDER_ATTACHED, 1);
        $order->save();
        if ($short > 0) {
            $order->add_order_note(sprintf('%d seat hold(s) had lapsed when this order was placed; they are re-claimed if still free when it is paid.', $short));
        }
    }

    public function order_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $places = self::order_places($order);
        if (!$places) {
            return;
        }
        $this->attach_order($order);
        $lost = Azure_Tickets_Inventory::mark_sold($order_id, $places);
        if ($lost) {
            $labels = array();
            foreach ($lost as $p) {
                $sections = Azure_Tickets_Config::sections($p['product_id']);
                $labels[] = isset($sections[$p['section_id']]) ? Azure_Tickets_Layout::seat_label($sections[$p['section_id']]['block'], $p['seat_key']) : $p['section_id'] . ' ' . $p['seat_key'];
            }
            $order->add_order_note('Double-booked: these seats were sold to someone else while this order was unpaid — please contact the buyer: ' . implode('; ', $labels));
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Tickets: order ' . $order_id . ' paid for seats already sold: ' . implode('; ', $labels), array('module' => 'Tickets'));
            }
        }
    }

    public function order_released($order_id) {
        Azure_Tickets_Inventory::release_order($order_id);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}azure_tickets SET status = 'cancelled' WHERE order_id = %d AND status = 'active'",
            (int) $order_id
        ));
    }

    /**
     * For Azure_Tickets_Inventory::cleanup().
     */
    public static function order_state($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return 'gone';
        }
        if ($order->is_paid()) {
            return 'paid';
        }
        return $order->get_status() === 'on-hold' ? 'open' : 'unpaid';
    }
}
