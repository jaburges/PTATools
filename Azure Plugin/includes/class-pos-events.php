<?php
/**
 * Taking payments at an event: a PTSA calendar event can open Tap to Pay
 * sales on volunteers' phones (PTSA Board app).
 *
 * The event says which Tap to Pay products are sold there (or all of
 * them), optionally which show performance's tickets, and the PIN
 * volunteers type. A PIN opens its event from OPENS_BEFORE its start
 * until CLOSES_AFTER its end, and is exchanged for a signed payment
 * token that only works for that event and only until then. Changing or
 * clearing the PIN ends every session that used the old one.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tickets-door.php';

class Azure_POS_Events {

    const POST_TYPE = 'pta_event';

    const META_ENABLED = '_pta_pos_enabled';
    const META_PIN = '_pta_pos_pin';
    const META_PRODUCTS = '_pta_pos_products';
    const META_PERFORMANCE = '_pta_pos_performance';

    const OPENS_BEFORE = 3 * HOUR_IN_SECONDS;
    const CLOSES_AFTER = 2 * HOUR_IN_SECONDS;
    /** An event without an end time runs this long. */
    const DEFAULT_LENGTH = 4 * HOUR_IN_SECONDS;
    /** Events that started longer ago than this aren't looked at. */
    const LONGEST_EVENT = 3 * DAY_IN_SECONDS;
    /** Sync tokens keep working this long after the window closes. */
    const SYNC_LIFETIME = 14 * DAY_IN_SECONDS;

    const NONCE = 'pta_pos_event_nonce';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'register_metabox'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_metabox'), 20, 2);
    }

    public static function enabled($event_id) {
        return get_post_meta((int) $event_id, self::META_ENABLED, true) === 'yes';
    }

    public static function pin($event_id) {
        return Azure_Tickets_Door::clean_pin(get_post_meta((int) $event_id, self::META_PIN, true));
    }

    /** @return int[] Product IDs chosen for the event; empty means every Tap to Pay product. */
    public static function product_ids($event_id) {
        $ids = get_post_meta((int) $event_id, self::META_PRODUCTS, true);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : array();
    }

    /** The show performance whose tickets are sold at the event, or 0. */
    public static function performance($event_id) {
        return (int) get_post_meta((int) $event_id, self::META_PERFORMANCE, true);
    }

    /**
     * @return array{0:int,1:int}|null [start, end] as Unix times; null without a start.
     */
    public static function times($event_id) {
        $start = self::local_ts(get_post_meta((int) $event_id, '_EventStartDate', true));
        if (!$start) {
            return null;
        }
        $end = self::local_ts(get_post_meta((int) $event_id, '_EventEndDate', true));
        return array($start, $end > $start ? $end : $start + self::DEFAULT_LENGTH);
    }

    /**
     * When the PIN works, as [opens, closes]; null when the event has no date.
     *
     * @return array{0:int,1:int}|null
     */
    public static function window($event_id) {
        $times = self::times($event_id);
        return $times ? array($times[0] - self::OPENS_BEFORE, $times[1] + self::CLOSES_AFTER) : null;
    }

    /** Taking payments, with a PIN, and open right now. */
    public static function is_open($event_id) {
        $window = self::window($event_id);
        $now = time();
        return self::enabled($event_id) && self::pin($event_id) !== '' && $window && $now >= $window[0] && $now <= $window[1];
    }

    /**
     * @return int[] Events taking payments right now, soonest first.
     */
    public static function open_events() {
        if (!function_exists('get_posts')) {
            return array();
        }
        $tz = wp_timezone();
        $now = time();
        $ids = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => self::META_ENABLED, 'value' => 'yes'),
                array(
                    'key'     => '_EventStartDate',
                    'value'   => array(wp_date('Y-m-d H:i:s', $now - self::LONGEST_EVENT, $tz), wp_date('Y-m-d H:i:s', $now + self::OPENS_BEFORE, $tz)),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
            'orderby'        => 'meta_value',
            'meta_key'       => '_EventStartDate',
            'order'          => 'ASC',
        ));
        return array_values(array_filter(array_map('intval', (array) $ids), array(__CLASS__, 'is_open')));
    }

    /**
     * A payment token for the event, valid until its window closes.
     *
     * @return array{token:string,expires_at:int}|null
     */
    public static function issue($event_id) {
        $window = self::window($event_id);
        $pin = self::pin($event_id);
        if (!$window || $pin === '' || !self::enabled($event_id)) {
            return null;
        }
        $body = self::b64(json_encode(array('e' => (int) $event_id, 'exp' => $window[1])));
        return array('token' => $body . '.' . self::sign($body, $pin), 'expires_at' => $window[1]);
    }

    /**
     * A sync token for the event: it only uploads sales the app saved while
     * the website was out of reach, and lasts SYNC_LIFETIME past the window
     * so a till that comes back online the next day can still send them.
     *
     * @return array{token:string,expires_at:int}|null
     */
    public static function issue_sync($event_id) {
        $window = self::window($event_id);
        $pin = self::pin($event_id);
        if (!$window || $pin === '' || !self::enabled($event_id)) {
            return null;
        }
        $exp = $window[1] + self::SYNC_LIFETIME;
        $body = self::b64(json_encode(array('e' => (int) $event_id, 'exp' => $exp, 's' => 1)));
        return array('token' => $body . '.' . self::sign($body, $pin), 'expires_at' => $exp);
    }

    /**
     * @return int The event the token is for, or 0 if it is forged, expired,
     *             a sync token, or its PIN has since been changed or payments
     *             turned off.
     */
    public static function verify($token) {
        $claims = self::claims($token);
        if (!$claims || $claims['sync'] || !self::enabled($claims['e'])) {
            return 0;
        }
        return $claims['e'];
    }

    /**
     * @return int The event a sync token is for, or 0. Payments may have been
     *             turned off since: the sales were made while they were on.
     */
    public static function verify_sync($token) {
        $claims = self::claims($token);
        return $claims && $claims['sync'] ? $claims['e'] : 0;
    }

    /** @return array{e:int,sync:bool}|null A token signed with the event's current PIN and not expired. */
    private static function claims($token) {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        $id = (int) ($claims['e'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        $pin = $id ? self::pin($id) : '';
        if ($pin === '' || $exp < time() || !hash_equals(self::sign($parts[0], $pin), $parts[1])) {
            return null;
        }
        return array('e' => $id, 'sync' => !empty($claims['s']));
    }

    /**
     * @return array{id:int,name:string,starts_at:int,ends_at:int,when:string,venue:string}
     */
    public static function payload($event_id) {
        $times = self::times($event_id) ?: array(0, 0);
        return array(
            'id'        => (int) $event_id,
            'name'      => html_entity_decode((string) get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
            'starts_at' => $times[0],
            'ends_at'   => $times[1],
            'when'      => $times[0] ? wp_date('D j M, g:ia', $times[0]) : '',
            'venue'     => (string) get_post_meta((int) $event_id, '_EventVenue', true),
        );
    }

    private static function local_ts($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        try {
            return (new DateTimeImmutable($value, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function sign($body, $pin) {
        return self::b64(hash_hmac('sha256', $body, wp_salt('auth') . '|pos|' . $pin, true));
    }

    private static function b64($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    // ── Event editor ──────────────────────────────────────────────────

    public function register_metabox() {
        add_meta_box(
            'pta_pos_event',
            __('Take payments in the app (Tap to Pay)', 'azure-plugin'),
            array($this, 'render_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_metabox($post) {
        wp_nonce_field('pta_pos_event_save', self::NONCE);
        $enabled = self::enabled($post->ID);
        $chosen = self::product_ids($post->ID);
        $performance = self::performance($post->ID);

        echo '<p><label><input type="checkbox" name="pta_pos_enabled" value="1"' . checked($enabled, true, false) . '> ' . esc_html__('Volunteers take payments for this event', 'azure-plugin') . '</label></p>';

        echo '<p><label for="pta_pos_pin"><strong>' . esc_html__('Volunteer PIN', 'azure-plugin') . '</strong></label><br>';
        echo '<input type="text" id="pta_pos_pin" name="pta_pos_pin" inputmode="numeric" autocomplete="off" pattern="[0-9]{6,8}" maxlength="8" class="widefat" value="' . esc_attr(self::pin($post->ID)) . '"></p>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: hours before, 2: hours after */
            __('6 to 8 digits. Volunteers choose this event in the PTSA Board app and type the PIN. It works from %1$d hours before the start until %2$d hours after the end. Changing it signs every volunteer out.', 'azure-plugin'),
            self::OPENS_BEFORE / HOUR_IN_SECONDS,
            self::CLOSES_AFTER / HOUR_IN_SECONDS
        )) . '</p>';

        $products = self::pos_products_for_editor();
        echo '<p><strong>' . esc_html__('Products', 'azure-plugin') . '</strong></p>';
        if (!$products) {
            echo '<p class="description">' . esc_html__('No products are set to Tap to Pay yet. Set a product\'s Sales channel to "Online and Tap to Pay" or "Tap to Pay only".', 'azure-plugin') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Leave all unticked to sell every Tap to Pay product.', 'azure-plugin') . '</p>';
            echo '<div style="max-height:180px;overflow:auto">';
            foreach ($products as $id => $name) {
                echo '<label style="display:block"><input type="checkbox" name="pta_pos_products[]" value="' . (int) $id . '"' . checked(in_array($id, $chosen, true), true, false) . '> ' . esc_html($name) . '</label>';
            }
            echo '</div>';
        }

        $performances = self::performances_for_editor();
        if ($performances) {
            echo '<p><label for="pta_pos_performance"><strong>' . esc_html__('Show tickets', 'azure-plugin') . '</strong></label><br>';
            echo '<select id="pta_pos_performance" name="pta_pos_performance" class="widefat"><option value="0">' . esc_html__('— None —', 'azure-plugin') . '</option>';
            foreach ($performances as $id => $name) {
                echo '<option value="' . (int) $id . '"' . selected($performance, $id, false) . '>' . esc_html($name) . '</option>';
            }
            echo '</select></p>';
            echo '<p class="description">' . esc_html__('Volunteers can sell tickets for this performance. They are checked in as they are sold.', 'azure-plugin') . '</p>';
        }
    }

    public function save_metabox($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], 'pta_pos_event_save')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!empty($_POST['pta_pos_enabled'])) {
            update_post_meta($post_id, self::META_ENABLED, 'yes');
        } else {
            delete_post_meta($post_id, self::META_ENABLED);
        }
        $pin = Azure_Tickets_Door::clean_pin(wp_unslash($_POST['pta_pos_pin'] ?? ''));
        if ($pin !== '') {
            update_post_meta($post_id, self::META_PIN, $pin);
        } else {
            delete_post_meta($post_id, self::META_PIN);
        }
        $products = array_values(array_filter(array_map('intval', (array) ($_POST['pta_pos_products'] ?? array()))));
        if ($products) {
            update_post_meta($post_id, self::META_PRODUCTS, $products);
        } else {
            delete_post_meta($post_id, self::META_PRODUCTS);
        }
        $performance = (int) ($_POST['pta_pos_performance'] ?? 0);
        if ($performance) {
            update_post_meta($post_id, self::META_PERFORMANCE, $performance);
        } else {
            delete_post_meta($post_id, self::META_PERFORMANCE);
        }
    }

    /** @return array<int,string> */
    private static function pos_products_for_editor() {
        if (!function_exists('wc_get_products')) {
            return array();
        }
        $out = array();
        foreach (wc_get_products(array('status' => 'publish', 'limit' => 200, 'meta_key' => Azure_POS_Channel::META, 'meta_compare' => 'EXISTS')) as $product) {
            if (Azure_POS_Channel::sells_in_pos($product)) {
                $out[(int) $product->get_id()] = $product->get_name();
            }
        }
        asort($out);
        return $out;
    }

    /** @return array<int,string> Performances of published ticket products, by name and date. */
    private static function performances_for_editor() {
        if (!class_exists('Azure_Tickets_Config') || !function_exists('wc_get_products')) {
            return array();
        }
        $out = array();
        foreach ((array) wc_get_products(array('type' => 'ticket', 'status' => 'publish', 'limit' => 50, 'return' => 'ids')) as $id) {
            foreach (Azure_Tickets_Config::performances((int) $id) as $performance) {
                $product = wc_get_product($performance);
                $ts = Azure_Tickets_Config::event_ts($performance);
                if ($product) {
                    $out[(int) $performance] = $product->get_name() . ($ts ? ' — ' . wp_date('D j M, g:ia', $ts) : '');
                }
            }
        }
        return $out;
    }
}
