<?php
/**
 * Tap to Pay: the Sales channel, event PINs and payment tokens, the Stripe
 * client, and a sale from the PTSA Board app end to end (catalog, order,
 * tap, complete, cancel, and the clean-up of abandoned sales).
 *
 * Run: php tests/test-pos.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) { unset(WP_Shim::$post_meta[$post_id][$key]); return true; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
function rest_ensure_response($data) { return $data; }
function register_rest_route($ns, $route, $args) { FX::$routes[$route] = $args; return true; }
function current_user_can($cap) { return in_array($cap, FX::$caps, true); }
function get_current_user_id() { return FX::$user; }
function wp_get_current_user() { return (object) array('display_name' => 'Board Bea'); }
function get_the_title($id) { return FX::$titles[$id] ?? ''; }
function get_posts($args) { FX::$post_queries[] = $args; return FX::$events; }
function get_woocommerce_currency() { return 'USD'; }
function wc_get_product($id) { return FX::$products[(int) $id] ?? null; }
function wc_get_products($args) {
    $out = array();
    foreach (FX::$products as $p) {
        if ($p->get_parent_id()) continue;
        if (!empty($args['include']) && !in_array($p->get_id(), $args['include'], true)) continue;
        if (!empty($args['type']) && !in_array($p->get_type(), (array) $args['type'], true)) continue;
        if (!empty($args['status']) && $p->get_status() !== $args['status']) continue;
        $out[] = $p;
    }
    return $out;
}
function wc_get_price_to_display($p) { return (float) $p->get_price(); }
function wc_get_formatted_variation($v, $flat = false, $label = true, $link = false) { return $v->get_name(); }
function wp_get_attachment_image_url($id, $size) { return $id ? 'https://example.test/img/' . $id . '.jpg' : false; }
function wc_create_order($args) { $o = new FO($args); FX::$orders[$o->get_id()] = $o; return $o; }
function wc_get_order($id) { return FX::$orders[(int) $id] ?? false; }
function wc_get_orders($args) {
    FX::$order_queries[] = $args;
    if (isset($args['meta_key'])) {
        return array_values(array_filter(FX::$orders, function ($o) use ($args) {
            return $o->get_created_via() === $args['created_via'] && $o->get_meta($args['meta_key']) === $args['meta_value'];
        }));
    }
    $cutoff = (int) substr($args['date_created'], 1);
    return array_values(array_filter(FX::$orders, function ($o) use ($args, $cutoff) {
        return in_array($o->get_status(), $args['status'], true) && $o->get_created_via() === $args['created_via'] && $o->created < $cutoff;
    }));
}

class WP_REST_Request implements ArrayAccess {
    private $params; private $json; private $headers;
    public function __construct(array $params = array(), array $json = array(), array $headers = array()) { $this->params = $params; $this->json = $json; $this->headers = $headers; }
    public function get_json_params() { return $this->json; }
    public function get_header($k) { return $this->headers[strtolower($k)] ?? null; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}

class FP {
    public $id; public $name; public $type; public $parent = 0; public $status = 'publish'; public $price;
    public $meta = array(); public $visibility = 'visible'; public $stock = null; public $in_stock = true;
    public $backorders = false; public $children = array(); public $image = 0;
    public function __construct($id, $name, $price, $type = 'simple') { $this->id = $id; $this->name = $name; $this->price = (string) $price; $this->type = $type; }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_type() { return $this->type; }
    public function is_type($t) { return $this->type === $t; }
    public function get_parent_id() { return $this->parent; }
    public function get_status() { return $this->status; }
    public function get_price() { return $this->price; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function get_catalog_visibility() { return $this->visibility; }
    public function set_catalog_visibility($v) { $this->visibility = $v; }
    public function is_in_stock() { return $this->in_stock; }
    public function managing_stock() { return $this->stock !== null; }
    public function backorders_allowed() { return $this->backorders; }
    public function get_stock_quantity() { return $this->stock; }
    public function get_children() { return $this->children; }
    public function get_image_id() { return $this->image; }
    public function save() { return $this->id; }
}

class FO {
    private static $next = 500;
    public $id; public $status; public $created_via; public $customer; public $items = array(); public $meta = array();
    public $notes = array(); public $total = 0.0; public $method = ''; public $method_title = ''; public $transaction = '';
    public $created; public $deleted = false;
    public function __construct($args) { $this->id = self::$next++; $this->status = $args['status']; $this->created_via = $args['created_via']; $this->customer = $args['customer_id']; $this->created = time(); }
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_status() { return $this->status; }
    public function get_created_via() { return $this->created_via; }
    public function add_product($p, $qty, $args = array()) {
        $id = count($this->items) + 1;
        $this->items[$id] = new FI($p->get_id(), $qty);
        $this->total += isset($args['total']) ? (float) $args['total'] : (float) $p->get_price() * $qty;
        return $id;
    }
    public function get_item($id) { return $this->items[$id] ?? false; }
    public function get_items() { return $this->items; }
    public function set_payment_method($m) { $this->method = $m; }
    public function set_payment_method_title($t) { $this->method_title = $t; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function calculate_totals() { return $this->total; }
    public function get_currency() { return 'USD'; }
    public function get_total() { return number_format($this->total, 2, '.', ''); }
    public function delete($force) { $this->deleted = true; unset(FX::$orders[$this->id]); }
    public function save() { return $this->id; }
    public function update_status($s, $note = '') { $this->status = $s; if ($note) $this->notes[] = $note; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function payment_complete($tx) { $this->transaction = $tx; $this->status = 'processing'; }
}

class FI {
    public $product_id; public $qty; public $meta = array();
    public function __construct($product_id, $qty) { $this->product_id = $product_id; $this->qty = $qty; }
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return 0; }
    public function get_quantity() { return $this->qty; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function add_meta_data($k, $v, $unique = false) { $this->meta[$k] = $v; }
    public function save() { return true; }
}

// The tickets module, as far as the till uses it: one performance (700) with
// a seated section, a general-admission section and a cast-families section.
class Azure_Tickets_Config {
    const KIND_REGULAR = 'regular';
    const KIND_ADA = 'ada';
    const ACCESS_ALL = 'all';
    const ACCESS_FAMILIES = 'families';
    public static $sections = array();
    public static function is_performance($id) { return (int) $id === 700; }
    public static function sections($id) { return self::$sections; }
    public static function seat_price($id, array $s, $kind) { return $kind === self::KIND_ADA ? 4.0 : $s['price']; }
    public static function seat_kind(array $block, $key) { return substr($key, -1) === 'W' ? self::KIND_ADA : self::KIND_REGULAR; }
    public static function event_ts($id) { return 1767225600; }
}
class Azure_Tickets_Layout {
    public static function seat_type(array $block, $key) { return in_array($key, $block['seats'] ?? array(), true) ? 'normal' : null; }
    public static function seat_label(array $block, $key) { return $block['name'] . ' ' . $key; }
}
class Azure_Tickets_Cart {
    const ITEM_SEATS = '_ticket_seats';
    const ITEM_HOLD = '_ticket_hold';
    const ITEM_SECTION = '_ticket_section';
    const ITEM_KIND = '_ticket_kind';
    const ORDER_ATTACHED = '_ticket_holds_attached';
    public static function kind_label($kind) { return $kind === 'ada' ? 'ADA (wheelchair space)' : 'Regular'; }
}
class Azure_Tickets_Inventory {
    /** @var array "perf|section|seat" => [status, hold_key, order_id] */
    public static $rows = array();
    public static function claim($pid, array $places, $hold, $user = 0) {
        $taken = array();
        foreach ($places as $p) {
            $row = self::$rows[$pid . '|' . $p['section_id'] . '|' . $p['seat_key']] ?? null;
            if ($row && $row[1] !== $hold) $taken[] = $p;
        }
        if ($taken) return array('ok' => false, 'taken' => $taken, 'renewed' => 0);
        foreach ($places as $p) self::$rows[$pid . '|' . $p['section_id'] . '|' . $p['seat_key']] = array('held', $hold, 0);
        return array('ok' => true, 'taken' => array(), 'renewed' => count($places));
    }
    public static function claim_general($pid, $sid, $count, $capacity, $hold, $user = 0) {
        $got = array();
        for ($n = 1; $n <= $capacity && count($got) < $count; $n++) {
            if (!isset(self::$rows["$pid|$sid|$n"])) $got[] = (string) $n;
        }
        if (count($got) < $count) return false;
        foreach ($got as $k) self::$rows["$pid|$sid|$k"] = array('held', $hold, 0);
        return $got;
    }
    public static function held_by($pid, $hold) {
        $out = array();
        foreach (self::$rows as $ref => $row) {
            list($p, $s, $k) = explode('|', $ref);
            if ((int) $p === (int) $pid && $row[0] === 'held' && $row[1] === $hold) $out[] = array('section_id' => $s, 'seat_key' => $k);
        }
        return $out;
    }
    public static function release($hold, $pid, array $places) {
        foreach ($places as $p) {
            $ref = $pid . '|' . $p['section_id'] . '|' . $p['seat_key'];
            if (isset(self::$rows[$ref]) && self::$rows[$ref][0] === 'held' && self::$rows[$ref][1] === $hold) unset(self::$rows[$ref]);
        }
    }
    public static function attach_order($order_id, $hold, $pid, array $places) {
        $moved = 0;
        foreach ($places as $p) {
            $ref = $pid . '|' . $p['section_id'] . '|' . $p['seat_key'];
            if (isset(self::$rows[$ref]) && self::$rows[$ref][1] === $hold) { self::$rows[$ref] = array('ordered', $hold, $order_id); $moved++; }
        }
        return $moved;
    }
    public static function taken($pid, $hold = '') {
        $out = array();
        foreach (self::$rows as $ref => $row) {
            list($p, $s, $k) = explode('|', $ref);
            if ((int) $p === (int) $pid) $out[$s][$k] = 'taken';
        }
        return $out;
    }
    public static function count_taken($pid, array $sids, $exclude = '', $user = 0) {
        $n = 0;
        foreach (self::taken($pid) as $s => $keys) if (in_array((string) $s, $sids, true)) $n += count($keys);
        return $n;
    }
}
class Azure_Tickets_Checkin {
    public static $checked = array();
    public function check_in($code, $user = null, $pid = 0) { self::$checked[] = array($code, $pid); return array('success' => true); }
}
class Azure_Tickets_Visualizer {
    public static function snapshot($pid) { return array('performance' => $pid); }
    public static function app_map(array $snap) {
        $seat = function ($s, $k, $st, $name = '') {
            return array('s' => $s, 'k' => $k, 'x' => 1, 'y' => 2, 'w' => 10, 'h' => 10, 'a' => 0, 'n' => 1, 't' => 'normal', 'st' => $st, 'label' => "$s $k",
                'name' => $name, 'order' => $name ? 812 : 0, 'g' => $name ? 'o812' : '', 'sides' => 'tb', 'in' => '', 'until' => '', 'who' => $name ? 'Board Bea' : '', 'color' => '#123456');
        };
        return array(
            'width' => 400, 'height' => 300, 'stats' => array('sold' => 1), 'venue' => 'Wilder Gym',
            'shapes' => array(array('kind' => 'ga', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 50, 'text' => 'Lawn', 'color' => '', 'ga' => array('capacity' => 3, 'sold' => 1, 'checked_in' => 1, 'free' => 2, 'enabled' => true))),
            'rows' => array(array('text' => 'A', 'x' => 0, 'y' => 0, 'a' => 0, 'color' => '')),
            'seats' => array($seat('floor', 'A-1', 'free'), $seat('floor', 'A-2', 'sold', 'Pat Buyer'), $seat('floor', 'A-3', 'held'), $seat('cast', 'C-1', 'free')),
            'orders' => array(array('id' => 812, 'name' => 'Pat Buyer', 'email' => 'pat@example.test')),
        );
    }
}
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $tickets = array();
    private $args = array();
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function get_results($sql) { return $this->tickets[(int) $this->args[0]] ?? array(); }
};

class FX {
    public static $routes = array(); public static $caps = array(); public static $user = 0; public static $titles = array();
    public static $events = array(); public static $post_queries = array(); public static $products = array(); public static $orders = array();
    public static $order_queries = array(); public static $signed_in = true;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-door.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-pos-channel.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-pos-events.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-pos-stripe.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ptsa-rest-pos.php';

$pass = 0;
$fail = 0;
function check($label, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; }
}
function stripe_reply($body, $code = 200) { return array('code' => $code, 'body' => json_encode($body)); }
/** The shim answers with the first matching needle, so the most specific URLs go first. */
function stub($needle, $reply) {
    WP_Shim::$http_responses[$needle] = array($reply);
    uksort(WP_Shim::$http_responses, function ($a, $b) { return strlen($b) - strlen($a); });
}
function intents_created() {
    return count(array_filter(WP_Shim::$http_calls, function ($c) { return substr($c['url'], -strlen('/v1/payment_intents')) === '/v1/payment_intents'; }));
}
function last_stripe_call($needle) {
    foreach (array_reverse(WP_Shim::$http_calls) as $call) {
        if (strpos($call['url'], $needle) !== false) return $call;
    }
    return null;
}
function local($ts) { return wp_date('Y-m-d H:i:s', $ts); }

// ── Sales channel ────────────────────────────────────────────────────
echo "Sales channel\n";
$muffin = new FP(11, 'Muffin', '2.50');
$muffin->visibility = 'search';
check('a product is online only until set', Azure_POS_Channel::channel($muffin) === 'online' && !Azure_POS_Channel::sells_in_pos($muffin));
check('unknown values fall back to online only', Azure_POS_Channel::clean('everywhere') === 'online');

Azure_POS_Channel::apply($muffin, 'both');
check('online and Tap to Pay keeps it in the shop', Azure_POS_Channel::sells_in_pos($muffin) && $muffin->visibility === 'search');

Azure_POS_Channel::apply($muffin, 'pos');
check('Tap to Pay only hides it from the shop', $muffin->visibility === 'hidden' && Azure_POS_Channel::online_stopped($muffin));
check('...remembering how it was shown', $muffin->get_meta(Azure_POS_Channel::META_PREVIOUS_VISIBILITY) === 'search');
check('...and the online cart refuses it', Azure_POS_Channel::get_instance()->filter_purchasable(true, $muffin) === false);
Azure_POS_Channel::apply($muffin, 'pos');
check('saving it again keeps the remembered visibility', $muffin->get_meta(Azure_POS_Channel::META_PREVIOUS_VISIBILITY) === 'search');

Azure_POS_Channel::apply($muffin, 'both');
check('back online, it is shown as before', $muffin->visibility === 'search' && $muffin->get_meta(Azure_POS_Channel::META_PREVIOUS_VISIBILITY) === '');
check('...and can be bought online again', Azure_POS_Channel::get_instance()->filter_purchasable(true, $muffin) === true);
check('...not purchasable stays not purchasable', Azure_POS_Channel::get_instance()->filter_purchasable(false, $muffin) === false);

$shirt = new FP(20, 'T-shirt', '15', 'variable');
$shirt_m = new FP(21, 'T-shirt – M', '15', 'variation');
$shirt_m->parent = 20;
$shirt->children = array(21);
FX::$products = array(20 => $shirt, 21 => $shirt_m);
Azure_POS_Channel::apply($shirt, 'pos');
check('a variation follows its product', Azure_POS_Channel::channel($shirt_m) === 'pos' && Azure_POS_Channel::get_instance()->filter_purchasable(true, $shirt_m) === false);

$online = new FP(30, 'Online only', '5');
$hidden = new FP(31, 'Already hidden', '5');
$hidden->visibility = 'hidden';
Azure_POS_Channel::apply($hidden, 'pos');
Azure_POS_Channel::apply($hidden, 'online');
check('a product hidden before stays hidden when it comes back', $hidden->visibility === 'hidden');
check('...and nothing is left behind', $hidden->get_meta(Azure_POS_Channel::META) === '' && $hidden->get_meta(Azure_POS_Channel::META_PREVIOUS_VISIBILITY) === '');

// ── Events and payment tokens ────────────────────────────────────────
echo "Events and payment tokens\n";
$now = time();
$fair = 900;
$gala = 901;
$past = 902;
FX::$titles = array($fair => 'Fall Fair &amp; Bake Sale', $gala => 'Spring Gala', $past => 'Last week');
WP_Shim::$post_meta[$fair] = array('_EventStartDate' => local($now + HOUR_IN_SECONDS), '_EventEndDate' => local($now + 4 * HOUR_IN_SECONDS), '_EventVenue' => 'Wilder Gym',
    Azure_POS_Events::META_ENABLED => 'yes', Azure_POS_Events::META_PIN => '246810');
WP_Shim::$post_meta[$gala] = array('_EventStartDate' => local($now - HOUR_IN_SECONDS), Azure_POS_Events::META_ENABLED => 'yes', Azure_POS_Events::META_PIN => '135790');
WP_Shim::$post_meta[$past] = array('_EventStartDate' => local($now - DAY_IN_SECONDS), '_EventEndDate' => local($now - DAY_IN_SECONDS + HOUR_IN_SECONDS),
    Azure_POS_Events::META_ENABLED => 'yes', Azure_POS_Events::META_PIN => '111222');

$window = Azure_POS_Events::window($fair);
check('the PIN opens 3 hours before the start', $window[0] === $now + HOUR_IN_SECONDS - 3 * HOUR_IN_SECONDS);
check('...and closes 2 hours after the end', $window[1] === $now + 4 * HOUR_IN_SECONDS + 2 * HOUR_IN_SECONDS);
check('an event without an end runs 4 hours', Azure_POS_Events::times($gala)[1] === $now - HOUR_IN_SECONDS + 4 * HOUR_IN_SECONDS);
check('open events are taking payments now', Azure_POS_Events::is_open($fair) && Azure_POS_Events::is_open($gala) && !Azure_POS_Events::is_open($past));

$payload = Azure_POS_Events::payload($fair);
check('the event shows its name, time and venue', $payload['name'] === 'Fall Fair & Bake Sale' && $payload['venue'] === 'Wilder Gym' && $payload['starts_at'] === $now + HOUR_IN_SECONDS);

$session = Azure_POS_Events::issue($fair);
check('a token lasts until the window closes', $session['expires_at'] === $window[1]);
check('the token opens its own event', Azure_POS_Events::verify($session['token']) === $fair);
check('a tampered token is refused', Azure_POS_Events::verify(substr($session['token'], 0, -2) . 'xx') === 0);
check('an empty token is refused', Azure_POS_Events::verify('') === 0);
$forged_body = rtrim(strtr(base64_encode(json_encode(array('e' => $gala, 'exp' => $now + 9999))), '+/', '-_'), '=');
$sig = explode('.', $session['token'])[1];
check('a token can\'t be moved to another event', Azure_POS_Events::verify($forged_body . '.' . $sig) === 0);
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PIN] = '999888';
check('changing the PIN ends the session', Azure_POS_Events::verify($session['token']) === 0);
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PIN] = '246810';
check('...putting it back restores it', Azure_POS_Events::verify($session['token']) === $fair);
unset(WP_Shim::$post_meta[$fair][Azure_POS_Events::META_ENABLED]);
check('turning payments off ends the session', Azure_POS_Events::verify($session['token']) === 0 && Azure_POS_Events::issue($fair) === null);
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_ENABLED] = 'yes';

// ── Stripe client ────────────────────────────────────────────────────
echo "Stripe client\n";
check('form encoding nests metadata and lists', Azure_POS_Stripe::encode(array('amount' => 500, 'payment_method_types' => array('card_present'), 'metadata' => array('order_id' => 7)))
    === 'amount=500&payment_method_types%5B%5D=card_present&metadata%5Border_id%5D=7');
check('dollars become cents', Azure_POS_Stripe::to_minor('12.5', 'USD') === 1250 && Azure_POS_Stripe::to_minor('0.1', 'usd') === 10);
check('zero-decimal currencies stay whole', Azure_POS_Stripe::to_minor('500', 'JPY') === 500);

update_option('woocommerce_stripe_settings', array('testmode' => 'yes', 'test_secret_key' => 'sk_test_abc', 'secret_key' => 'sk_live_xyz'));
check('test mode uses the gateway\'s test key', Azure_POS_Stripe::secret_key() === 'sk_test_abc' && Azure_POS_Stripe::test_mode());
check('not ready without a Terminal location', !Azure_POS_Stripe::ready());
update_option(Azure_POS_Stripe::OPTION_LOCATION, 'not-a-location');
check('a mistyped location is ignored', Azure_POS_Stripe::location_id() === '');
update_option(Azure_POS_Stripe::OPTION_LOCATION, 'tml_Fair123');
check('ready with a key and a location', Azure_POS_Stripe::ready());

stub('terminal/connection_tokens', stripe_reply(array('secret' => 'pst_test_1')));
$token = Azure_POS_Stripe::connection_token();
$call = last_stripe_call('terminal/connection_tokens');
check('connection tokens are for the location', $token['secret'] === 'pst_test_1' && $call['args']['body'] === 'location=tml_Fair123');
check('...sent with the gateway key and a pinned API version', $call['args']['headers']['Authorization'] === 'Bearer sk_test_abc' && $call['args']['headers']['Stripe-Version'] === Azure_POS_Stripe::API_VERSION);
stub('payment_intents/pi_bad', stripe_reply(array('error' => array('message' => 'No such payment_intent')), 404));
$err = Azure_POS_Stripe::retrieve_intent('pi_bad');
check('Stripe errors carry Stripe\'s message', is_wp_error($err) && $err->get_error_message() === 'No such payment_intent');
check('retrieving expands the charge for the card details', strpos(last_stripe_call('payment_intents/pi_bad')['url'], 'expand%5B%5D=latest_charge') !== false);

// ── The app ──────────────────────────────────────────────────────────
echo "Routes and access\n";
$signed_in = function ($request) { return FX::$signed_in ? true : new WP_Error('ptsa_unauthorized', 'Sign in', array('status' => 401)); };
Azure_PTSA_REST_POS::register('ptsa/v1', $signed_in);
check('ten routes', count(FX::$routes) === 10);
check('only the event list and PIN entry are open to everyone', FX::$routes['/pos/events']['permission_callback'] === '__return_true' && FX::$routes['/pos/open']['permission_callback'] === '__return_true'
    && FX::$routes['/pos/orders']['permission_callback'] !== '__return_true');

FX::$events = array($past, $fair, $gala);
$events = Azure_PTSA_REST_POS::list_events(new WP_REST_Request());
check('the event list shows only events taking payments now', array_column($events, 'id') === array($fair, $gala));
check('...without their PINs', !isset($events[0]['pin']) && strpos(json_encode($events), '246810') === false);

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$wrong = Azure_PTSA_REST_POS::open(new WP_REST_Request(array(), array('event_id' => $fair, 'pin' => '135790')));
check('another event\'s PIN is refused', is_wp_error($wrong) && $wrong->get_error_data()['status'] === 401);
$ended = Azure_PTSA_REST_POS::open(new WP_REST_Request(array(), array('event_id' => $past, 'pin' => '111222')));
check('an event that has ended is refused', is_wp_error($ended));
$opened = Azure_PTSA_REST_POS::open(new WP_REST_Request(array(), array('event_id' => $fair, 'pin' => '246 810')));
check('the right PIN opens the event', !is_wp_error($opened) && Azure_POS_Events::verify($opened['token']) === $fair && $opened['event']['id'] === $fair);
$pos = array('authorization' => 'POS ' . $opened['token']);
$gala_pos = array('authorization' => 'POS ' . Azure_POS_Events::issue($gala)['token']);

check('a payment token gets in without signing in', Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request(array(), array(), $pos)) === true);
$expired = Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request(array(), array(), array('authorization' => 'POS nope.nope')));
check('a bad token gets 401', is_wp_error($expired) && $expired->get_error_code() === 'ptsa_pos_expired');
FX::$caps = array();
$no_cap = Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request());
check('signed in without shop access gets 403', is_wp_error($no_cap) && $no_cap->get_error_data()['status'] === 403);
FX::$signed_in = false;
check('not signed in and no token gets the sign-in error', Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request())->get_error_code() === 'ptsa_unauthorized');
FX::$signed_in = true;

echo "Catalog\n";
$muffin = new FP(11, 'Muffin', '2.50');
$muffin->image = 77;
$cookie = new FP(12, 'Cookie', '1.00');
$cookie->stock = 3;
$soldout = new FP(13, 'Brownie', '3.00');
$soldout->in_stock = false;
$tote = new FP(14, 'Tote bag', '12.00');
$draft = new FP(15, 'Draft', '1');
$draft->status = 'draft';
$raffle = new FP(16, 'Raffle', '5', 'ticket');
$shirt_l = new FP(22, 'T-shirt – L', '17', 'variation');
$shirt_l->parent = 20;
$shirt_l->stock = 0;
$shirt->children = array(21, 22);
FX::$products = array(11 => $muffin, 12 => $cookie, 13 => $soldout, 14 => $tote, 15 => $draft, 16 => $raffle, 20 => $shirt, 21 => $shirt_m, 22 => $shirt_l, 30 => $online);
foreach (array($muffin, $cookie, $soldout, $draft, $raffle) as $p) Azure_POS_Channel::apply($p, 'both');
Azure_POS_Channel::apply($tote, 'pos');
Azure_POS_Channel::apply($shirt, 'pos');

authorize_as($pos);
function authorize_as(array $headers) {
    global $signed_in;
    return Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request(array(), array(), $headers));
}
$catalog = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $fair)));
$ids = array_column($catalog['products'], 'id');
sort($ids);
check('the till sells published Tap to Pay products only', $ids === array(11, 12, 13, 14, 20));
$by_id = array_column($catalog['products'], null, 'id');
check('prices are in cents', $by_id[11]['price_cents'] === 250 && $by_id[11]['price'] === '2.5');
check('stock: counted, sold out, or unlimited', $by_id[12]['stock'] === 3 && $by_id[13]['stock'] === 0 && $by_id[11]['stock'] === null);
check('pictures come along', $by_id[11]['image'] === 'https://example.test/img/77.jpg' && $by_id[14]['image'] === '');
check('variable products list their options', array_column($by_id[20]['options'], 'id') === array(21, 22) && $by_id[20]['options'][1]['stock'] === 0);
check('the catalog says where to connect the reader', $catalog['location_id'] === 'tml_Fair123' && $catalog['currency'] === 'USD' && $catalog['test_mode'] === true);

WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PRODUCTS] = array(11, 14);
$catalog = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $fair)));
check('an event can limit what it sells', array_column($catalog['products'], 'id') === array(11, 14));
unset(WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PRODUCTS]);

$other = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $gala)));
check('a token can\'t open another event\'s till', is_wp_error($other) && $other->get_error_code() === 'ptsa_pos_scope');

echo "A sale\n";
function sale(array $lines, $event = null) {
    global $fair;
    return Azure_PTSA_REST_POS::create_order(new WP_REST_Request(array(), array('event_id' => $event ?? $fair, 'lines' => $lines)));
}
check('an empty cart is refused', sale(array())->get_error_code() === 'ptsa_pos_empty');
check('a product not sold here is refused', sale(array(array('product_id' => 30, 'quantity' => 1)))->get_error_code() === 'ptsa_pos_not_sold');
check('more than in stock is refused', sale(array(array('product_id' => 12, 'quantity' => 2), array('product_id' => 12, 'quantity' => 2)))->get_error_message() === 'Only 3 of Cookie left.');
check('sold out is refused', sale(array(array('product_id' => 13, 'quantity' => 1)))->get_error_message() === 'Brownie is sold out.');
check('a variable product needs its option', sale(array(array('product_id' => 20, 'quantity' => 1)))->get_error_code() === 'ptsa_pos_option');
check('...from that product', sale(array(array('product_id' => 20, 'variation_id' => 12, 'quantity' => 1)))->get_error_code() === 'ptsa_pos_option');
check('a silly quantity is refused', sale(array(array('product_id' => 11, 'quantity' => 500)))->get_error_code() === 'ptsa_pos_line');
check('Stripe isn\'t asked to charge refused carts', intents_created() === 0);

stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_1', 'client_secret' => 'pi_1_secret_x', 'status' => 'requires_payment_method')));
$sale = sale(array(array('product_id' => 11, 'quantity' => 2), array('product_id' => 20, 'variation_id' => 21, 'quantity' => 1), array('product_id' => 12, 'quantity' => 1)));
$order = FX::$orders[$sale['order']['id']];
check('the website prices the sale itself', $sale['order']['total_cents'] === 2100 && $sale['order']['total'] === '21.00');
check('the phone gets the payment to collect', $sale['client_secret'] === 'pi_1_secret_x' && $sale['intent_id'] === 'pi_1');
$create = last_stripe_call('/v1/payment_intents');
parse_str($create['args']['body'], $sent);
check('Stripe is asked for a card-present payment of the total', $sent['amount'] === '2100' && $sent['currency'] === 'usd' && $sent['payment_method_types'] === array('card_present'));
check('...tagged with the order and event', $sent['metadata']['order_id'] === (string) $order->id && $sent['metadata']['event_id'] === (string) $fair);
check('the order waits for the tap', $order->status === 'pending' && $order->created_via === 'ptsa-pos' && $order->method === 'ptsa_tap_to_pay' && $order->customer === 0);
check('...knowing its event, payment and seller', $order->get_meta('_pta_pos_event') === $fair && $order->get_meta('_pta_pos_intent') === 'pi_1' && $order->get_meta('_pta_pos_seller') === 'a volunteer (event PIN)');

$complete = function ($order_id) { return Azure_PTSA_REST_POS::complete_order(new WP_REST_Request(array('id' => $order_id))); };
stub('payment_intents/pi_1', stripe_reply(array('id' => 'pi_1', 'status' => 'requires_payment_method', 'amount_received' => 0, 'currency' => 'usd')));
$early = $complete($order->id);
check('completing before the tap says it isn\'t paid', is_wp_error($early) && $early->get_error_data()['status'] === 409 && $order->status === 'pending');

stub('payment_intents/pi_1', stripe_reply(array('id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 2100, 'currency' => 'usd',
    'latest_charge' => array('payment_method_details' => array('card_present' => array('brand' => 'visa', 'last4' => '4242'))))));
$paid = $complete($order->id);
check('after the tap the order is paid and handed over', $paid['status'] === 'paid' && $order->status === 'completed' && $order->transaction === 'pi_1');
check('...with the card for the screen', $paid['card'] === array('brand' => 'visa', 'last4' => '4242'));
check('...and a note for the shop', (bool) array_filter($order->notes, function ($n) { return strpos($n, 'Visa •••• 4242') !== false; }));
$calls = count(WP_Shim::$http_calls);
$again = $complete($order->id);
check('completing again is safe and asks Stripe nothing', $again['status'] === 'paid' && count(WP_Shim::$http_calls) === $calls);
$cancel = Azure_PTSA_REST_POS::cancel_order(new WP_REST_Request(array('id' => $order->id)));
check('a paid sale can\'t be cancelled from the till', is_wp_error($cancel) && $cancel->get_error_code() === 'ptsa_pos_paid');

stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_2', 'client_secret' => 's2', 'status' => 'requires_payment_method')));
$short = sale(array(array('product_id' => 11, 'quantity' => 1)));
stub('payment_intents/pi_2', stripe_reply(array('id' => 'pi_2', 'status' => 'succeeded', 'amount_received' => 100, 'currency' => 'usd')));
$mismatch = $complete($short['order']['id']);
check('a payment for the wrong amount isn\'t accepted', is_wp_error($mismatch) && $mismatch->get_error_code() === 'ptsa_pos_mismatch');
check('...and the order is held for a board member', FX::$orders[$short['order']['id']]->status === 'on-hold');

stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_3', 'client_secret' => 's3', 'status' => 'requires_payment_method')));
$given_up = sale(array(array('product_id' => 14, 'quantity' => 1)));
stub('payment_intents/pi_3', stripe_reply(array('id' => 'pi_3', 'status' => 'requires_payment_method', 'amount_received' => 0, 'currency' => 'usd')));
stub('payment_intents/pi_3/cancel', stripe_reply(array('id' => 'pi_3', 'status' => 'canceled')));
$cancelled = Azure_PTSA_REST_POS::cancel_order(new WP_REST_Request(array('id' => $given_up['order']['id'])));
check('a sale given up on is cancelled', $cancelled['status'] === 'cancelled' && FX::$orders[$given_up['order']['id']]->status === 'cancelled');
check('...and so is its payment', last_stripe_call('pi_3/cancel') !== null);

authorize_as($gala_pos);
$foreign = $complete($order->id);
check('another event\'s token can\'t touch the sale', is_wp_error($foreign) && $foreign->get_error_code() === 'ptsa_pos_scope');
check('...or sell for this event', sale(array(array('product_id' => 11, 'quantity' => 1)))->get_error_code() === 'ptsa_pos_scope');

FX::$caps = array('edit_shop_orders');
FX::$user = 3;
check('a shop manager signs in without a PIN', authorize_as(array()) === true);
stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_4', 'client_secret' => 's4', 'status' => 'requires_payment_method')));
$manager = sale(array(array('product_id' => 11, 'quantity' => 1)), $gala);
check('...and sells at any event taking payments', !is_wp_error($manager) && FX::$orders[$manager['order']['id']]->get_meta('_pta_pos_seller') === 'Board Bea');
check('...as themselves', FX::$orders[$manager['order']['id']]->customer === 3);
unset(WP_Shim::$post_meta[$past][Azure_POS_Events::META_ENABLED]);
check('...but not at one that isn\'t', sale(array(array('product_id' => 11, 'quantity' => 1)), $past)->get_error_code() === 'ptsa_pos_event');

echo "Abandoned sales\n";
stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_5', 'client_secret' => 's5', 'status' => 'requires_payment_method')));
$lost_signal = FX::$orders[sale(array(array('product_id' => 11, 'quantity' => 1)), $gala)['order']['id']];
stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_6', 'client_secret' => 's6', 'status' => 'requires_payment_method')));
$walked_away = FX::$orders[sale(array(array('product_id' => 12, 'quantity' => 1)), $gala)['order']['id']];
stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_7', 'client_secret' => 's7', 'status' => 'requires_payment_method')));
$just_now = FX::$orders[sale(array(array('product_id' => 11, 'quantity' => 1)), $gala)['order']['id']];
$lost_signal->created = $walked_away->created = time() - HOUR_IN_SECONDS;
FX::$orders[$manager['order']['id']]->created = time() - HOUR_IN_SECONDS;
stub('payment_intents/pi_4', stripe_reply(array('id' => 'pi_4', 'status' => 'requires_payment_method', 'amount_received' => 0, 'currency' => 'usd')));
stub('payment_intents/pi_5', stripe_reply(array('id' => 'pi_5', 'status' => 'succeeded', 'amount_received' => 250, 'currency' => 'usd')));
stub('payment_intents/pi_6', stripe_reply(array('id' => 'pi_6', 'status' => 'requires_payment_method', 'amount_received' => 0, 'currency' => 'usd')));
Azure_PTSA_REST_POS::reconcile();
check('a sale paid while the phone was offline is completed', $lost_signal->status === 'completed');
check('a sale never paid is cancelled', $walked_away->status === 'cancelled' && FX::$orders[$manager['order']['id']]->status === 'cancelled');
check('a sale still in progress is left alone', $just_now->status === 'pending');

echo "Tickets at the till\n";
$seated = function ($name, array $seats, array $extra = array()) {
    return $extra + array('type' => 'rectangle', 'name' => $name, 'price' => 10.0, 'limit' => 0, 'enabled' => true, 'access' => 'all', 'capacity' => count($seats), 'block' => array('name' => $name, 'seats' => $seats));
};
Azure_Tickets_Config::$sections = array(
    'floor'  => $seated('Floor', array('A-1', 'A-2', 'A-3', 'A-4W')),
    'lawn'   => array('type' => 'general_admission', 'name' => 'Lawn', 'price' => 5.0, 'limit' => 0, 'enabled' => true, 'access' => 'all', 'capacity' => 3, 'block' => array('name' => 'Lawn')),
    'cast'   => $seated('Cast families', array('C-1', 'C-2'), array('access' => 'families')),
    'closed' => $seated('Balcony', array('B-1'), array('enabled' => false)),
);
Azure_Tickets_Inventory::$rows = array('700|floor|A-2' => array('sold', 'wc:abc', 812), '700|lawn|1' => array('sold', 'wc:def', 813));
$show = new FP(700, 'Spring Musical – Friday', '', 'ticket');
FX::$products[700] = $show;
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PERFORMANCE] = 700;
authorize_as($pos);

$catalog = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $fair)));
$tickets = $catalog['tickets'];
check('the catalog sells the event\'s performance', $tickets['performance_id'] === 700 && $tickets['has_seats'] === true && $tickets['name'] === 'Spring Musical – Friday');
check('...from sections open to everyone that are switched on', array_column($tickets['sections'], 'id') === array('floor', 'lawn'));
$by_section = array_column($tickets['sections'], null, 'id');
check('...with places left and prices in cents', $by_section['floor']['left'] === 3 && $by_section['lawn']['left'] === 2 && $by_section['floor']['price_cents'] === 1000 && $by_section['floor']['ada_price_cents'] === 400);
check('an event without a performance sells no tickets', Azure_POS_Tickets::summary($gala, 'USD') === null);
check('...and refuses them', Azure_POS_Tickets::reserve($gala, array('seats' => array(array('section_id' => 'floor', 'seat_key' => 'A-1'))), 'pos:x')->get_error_code() === 'ptsa_pos_no_tickets');

$map = Azure_PTSA_REST_POS::seats(new WP_REST_Request(array('id' => $fair)));
$states = array_column($map['seats'], 'st', 'label');
check('the seat map shows free, taken and closed seats', $states === array('floor A-1' => 'free', 'floor A-2' => 'sold', 'floor A-3' => 'sold', 'cast C-1' => 'closed'));
$json = json_encode($map);
check('...and never who has them', strpos($json, 'Pat Buyer') === false && strpos($json, 'pat@') === false && strpos($json, 'Board Bea') === false
    && !isset($map['orders']) && !array_intersect(array('name', 'order', 'who', 'g', 'sides', 'in', 'until'), array_keys($map['seats'][1])));
check('the map names the performance, without its door PIN', $map['performance']['id'] === 700 && $map['performance']['name'] === 'Spring Musical – Friday' && !isset($map['performance']['door_pin']));
check('...nor how many were checked in', $map['shapes'][0]['ga'] === array('capacity' => 3, 'sold' => 1, 'free' => 2, 'enabled' => true));
check('another event\'s seat map is refused', Azure_PTSA_REST_POS::seats(new WP_REST_Request(array('id' => $gala)))->get_error_code() === 'ptsa_pos_scope');

$ticket_sale = function (array $tickets, array $lines = array()) use ($fair) {
    return Azure_PTSA_REST_POS::create_order(new WP_REST_Request(array(), array('event_id' => $fair, 'lines' => $lines, 'tickets' => $tickets)));
};
$rows_before = Azure_Tickets_Inventory::$rows;
check('cast-family seats aren\'t sold at the till', $ticket_sale(array('seats' => array(array('section_id' => 'cast', 'seat_key' => 'C-1'))))->get_error_code() === 'ptsa_pos_seat');
check('nor seats in a closed section', $ticket_sale(array('seats' => array(array('section_id' => 'closed', 'seat_key' => 'B-1'))))->get_error_code() === 'ptsa_pos_seat');
check('nor seats that don\'t exist', $ticket_sale(array('seats' => array(array('section_id' => 'floor', 'seat_key' => 'Z-9'))))->get_error_code() === 'ptsa_pos_seat');
$taken = $ticket_sale(array('seats' => array(array('section_id' => 'floor', 'seat_key' => 'A-1'), array('section_id' => 'floor', 'seat_key' => 'A-2'))));
check('a seat someone has is refused by name', is_wp_error($taken) && $taken->get_error_message() === 'Someone just took Floor A-2. Choose another seat.');
check('more general admission than is left is refused', $ticket_sale(array('general' => array(array('section_id' => 'lawn', 'count' => 3))))->get_error_message() === 'Only 2 left in Lawn.');
check('more than 20 tickets is refused', $ticket_sale(array('general' => array(array('section_id' => 'lawn', 'count' => 21))))->get_error_code() === 'ptsa_pos_too_many');
check('a bad product line holds no seats', is_wp_error($ticket_sale(array('seats' => array(array('section_id' => 'floor', 'seat_key' => 'A-1'))), array(array('product_id' => 30, 'quantity' => 1)))));
check('refused sales leave every seat as it was', Azure_Tickets_Inventory::$rows === $rows_before);

Azure_Tickets_Config::$sections['lawn']['price'] = 0.0;
$free = $ticket_sale(array('general' => array(array('section_id' => 'lawn', 'count' => 1))));
check('free tickets alone have nothing to charge', is_wp_error($free) && $free->get_error_code() === 'ptsa_pos_free');
check('...and let their places go', Azure_Tickets_Inventory::$rows === $rows_before);
Azure_Tickets_Config::$sections['lawn']['price'] = 5.0;

stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_8', 'client_secret' => 's8', 'status' => 'requires_payment_method')));
$sale = $ticket_sale(
    array('seats' => array(array('section_id' => 'floor', 'seat_key' => 'A-1'), array('section_id' => 'floor', 'seat_key' => 'A-4W')), 'general' => array(array('section_id' => 'lawn', 'count' => 2))),
    array(array('product_id' => 11, 'quantity' => 1))
);
$order = FX::$orders[$sale['order']['id']];
check('a sale can mix a muffin, seats and general admission', $sale['order']['total_cents'] === 2650);
$ticket_items = array_values(array_filter($order->get_items(), function ($i) { return $i->product_id === 700; }));
check('one order line per section and ticket type', count($ticket_items) === 3);
$floor = $ticket_items[0];
check('...carrying the same seat details as online sales', $floor->get_meta('_ticket_seats') === array(array('section_id' => 'floor', 'seat_key' => 'A-1', 'label' => 'Floor A-1', 'name' => ''))
    && $floor->get_meta('_ticket_section') === 'floor' && $floor->get_meta('_ticket_kind') === 'regular' && strpos($floor->get_meta('_ticket_hold'), 'pos:') === 0);
check('wheelchair spaces are sold at the ADA price', $ticket_items[1]->get_meta('_ticket_kind') === 'ada' && $ticket_items[1]->qty === 1);
check('general admission takes the next free places', array_column($ticket_items[2]->get_meta('_ticket_seats'), 'seat_key') === array('2', '3'));
check('the places wait on the order until it is paid', Azure_Tickets_Inventory::$rows['700|floor|A-1'] === array('ordered', $floor->get_meta('_ticket_hold'), $order->id)
    && Azure_Tickets_Inventory::$rows['700|lawn|3'][2] === $order->id && $order->get_meta('_ticket_holds_attached') === 1);
$catalog = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $fair)));
check('...and count as taken for the next sale', array_column($catalog['tickets']['sections'], 'left', 'id') === array('floor' => 1, 'lawn' => 0));

$GLOBALS['wpdb']->tickets[$order->id] = array(
    (object) array('ticket_code' => 'TKT1', 'product_id' => 700), (object) array('ticket_code' => 'TKT2', 'product_id' => 700),
    (object) array('ticket_code' => 'TKT3', 'product_id' => 700), (object) array('ticket_code' => 'TKT4', 'product_id' => 700),
);
stub('payment_intents/pi_8', stripe_reply(array('id' => 'pi_8', 'status' => 'succeeded', 'amount_received' => 2650, 'currency' => 'usd')));
$paid = $complete($order->id);
check('once paid the tickets are listed to let them in', $paid['tickets'] === array('Floor A-1', 'Floor A-4W', 'Lawn', 'Lawn'));
check('...and checked in for the performance', array_column(Azure_Tickets_Checkin::$checked, 0) === array('TKT1', 'TKT2', 'TKT3', 'TKT4') && array_unique(array_column(Azure_Tickets_Checkin::$checked, 1)) === array(700));
check('...with a note on the order', in_array('4 ticket(s) checked in at the till.', $order->notes, true));
check('a sale without machine tickets has nothing to dispense', $paid['dispense'] === null);

echo "Ticket dispenser\n";
$secret = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
update_option(Azure_POS_Dispenser::OPTION_SECRET, 'not-a-secret');
$made = Azure_POS_Dispenser::secret();
check('a missing or mistyped secret is replaced with a new one', preg_match('/^[0-9a-f]{64}$/', $made) === 1 && get_option(Azure_POS_Dispenser::OPTION_SECRET) === $made);
update_option(Azure_POS_Dispenser::OPTION_SECRET, strtoupper($secret));
check('a secret set by hand is kept', Azure_POS_Dispenser::secret() === $secret);
// Same values as the dispenser firmware's host tests.
check('vouchers match the dispenser', Azure_POS_Dispenser::voucher(1234, 5) === '6b056292e682559d69fde8e2465f858b06db079c346cdd49961ee54ad4d54da2');
check('offline keys match the dispenser', Azure_POS_Dispenser::offline_key(77) === 'ec5df6170e2084b9a37e3ae7da01c7ebcf8630d754c8e34f3918fb873857d90f');
check('each event has its own offline key', Azure_POS_Dispenser::offline_key($fair) !== Azure_POS_Dispenser::offline_key($gala));

$five = new FP(40, 'Arcade tickets ×5', '4.00');
$five->meta[Azure_POS_Dispenser::META_TICKETS] = 5;
$one = new FP(41, 'Arcade ticket', '1.00');
$one->meta[Azure_POS_Dispenser::META_TICKETS] = '1';
FX::$products[40] = $five;
FX::$products[41] = $one;
Azure_POS_Channel::apply($five, 'pos');
Azure_POS_Channel::apply($one, 'pos');
check('a product says how many tickets it pays out', Azure_POS_Dispenser::tickets($five) === 5 && Azure_POS_Dispenser::tickets($muffin) === 0 && Azure_POS_Dispenser::tickets(null) === 0);
$shirt->meta[Azure_POS_Dispenser::META_TICKETS] = 2;
check('a variation pays out what its product does', Azure_POS_Dispenser::tickets($shirt_m) === 2);
unset($shirt->meta[Azure_POS_Dispenser::META_TICKETS]);

$catalog = Azure_PTSA_REST_POS::catalog(new WP_REST_Request(array('id' => $fair)));
$by_id = array_column($catalog['products'], null, 'id');
check('the catalog says what each product pays out', $by_id[40]['dispense'] === 5 && $by_id[41]['dispense'] === 1 && $by_id[11]['dispense'] === 0);
check('...and gives the till the event\'s offline key', $catalog['dispenser'] === array('offline_key' => Azure_POS_Dispenser::offline_key($fair), 'max_tickets' => 100));

check('more tickets than one payout is refused before the card', sale(array(array('product_id' => 40, 'quantity' => 20), array('product_id' => 41, 'quantity' => 1)))->get_error_code() === 'ptsa_pos_too_many_tickets');
stub('/v1/payment_intents', stripe_reply(array('id' => 'pi_9', 'client_secret' => 's9', 'status' => 'requires_payment_method')));
$arcade = sale(array(array('product_id' => 40, 'quantity' => 2), array('product_id' => 41, 'quantity' => 3), array('product_id' => 11, 'quantity' => 1)));
$arcade_order = FX::$orders[$arcade['order']['id']];
$dispensed = function (array $body, $id = null) use (&$arcade_order) {
    return Azure_PTSA_REST_POS::dispensed(new WP_REST_Request(array('id' => $id ?? $arcade_order->id), $body));
};
check('nothing is recorded as paid out before the card goes through', $dispensed(array('qty' => 13, 'given' => 13))->get_error_code() === 'ptsa_pos_unpaid');
stub('payment_intents/pi_9', stripe_reply(array('id' => 'pi_9', 'status' => 'succeeded', 'amount_received' => 1350, 'currency' => 'usd')));
$paid = $complete($arcade_order->id);
check('once paid, the till gets a voucher for the order\'s tickets', $paid['dispense'] === array('id' => $arcade_order->id, 'qty' => 13, 'voucher' => Azure_POS_Dispenser::voucher($arcade_order->id, 13)));

$notes = count($arcade_order->notes);
$dispensed(array('id' => $arcade_order->id, 'qty' => 13, 'given' => 13, 'reason' => 'none'));
check('a full payout is noted on the order', end($arcade_order->notes) === 'The dispenser paid out 13 machine ticket(s).');
$dispensed(array('id' => $arcade_order->id, 'qty' => 13, 'given' => 13, 'reason' => 'none'));
check('...once, however often the app reports it', count($arcade_order->notes) === $notes + 1);
$dispensed(array('id' => $arcade_order->id, 'qty' => 13, 'given' => 10, 'reason' => 'jam'));
check('a short payout says how many are owed', end($arcade_order->notes) === 'The dispenser paid out 10 of 13 machine ticket(s) (the dispenser jammed): 3 owed, to give by hand.');
$dispensed(array('id' => $arcade_order->id, 'qty' => 13, 'given' => null, 'reason' => 'interrupted'));
check('a payout cut off by a power cut says to check with the buyer', strpos(end($arcade_order->notes), 'can\'t say how many came out') !== false
    && $arcade_order->get_meta(Azure_POS_Dispenser::ORDER_DISPENSED) === array('id' => $arcade_order->id, 'qty' => 13, 'given' => null, 'reason' => 'interrupted'));
$dispensed(array('id' => $arcade_order->id, 'qty' => 13, 'given' => 99, 'reason' => 'gremlins'));
check('more than the sale and unknown reasons are cleaned up', $arcade_order->get_meta(Azure_POS_Dispenser::ORDER_DISPENSED) === array('id' => $arcade_order->id, 'qty' => 13, 'given' => 13, 'reason' => 'none'));

echo "Sync tokens\n";
$window = Azure_POS_Events::window($fair);
check('opening the till also gives a sync token', $opened['sync_token'] !== '' && $opened['sync_expires_at'] === $window[1] + Azure_POS_Events::SYNC_LIFETIME);
check('...that can\'t be used to sell', Azure_POS_Events::verify($opened['sync_token']) === 0 && Azure_POS_Events::verify_sync($opened['sync_token']) === $fair);
check('...and a payment token isn\'t a sync token', Azure_POS_Events::verify_sync($opened['token']) === 0);
$sync = array('authorization' => 'POS-Sync ' . $opened['sync_token']);
$sync_request = new WP_REST_Request(array(), array(), $sync);
check('sync tokens are refused by the till\'s other routes', is_wp_error(call_user_func(FX::$routes['/pos/orders']['permission_callback'], $sync_request))
    && is_wp_error(call_user_func(FX::$routes['/pos/events/(?P<id>\d+)/catalog']['permission_callback'], $sync_request)));
check('...but can upload sales and fetch connection tokens', call_user_func(FX::$routes['/pos/offline-sales']['permission_callback'], $sync_request) === true
    && call_user_func(FX::$routes['/pos/connection-token']['permission_callback'], $sync_request) === true
    && call_user_func(FX::$routes['/pos/orders/(?P<id>\d+)/dispensed']['permission_callback'], $sync_request) === true);
unset(WP_Shim::$post_meta[$fair][Azure_POS_Events::META_ENABLED]);
check('turning payments off after the event still lets its sales upload', Azure_POS_Events::verify_sync($opened['sync_token']) === $fair);
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_ENABLED] = 'yes';
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PIN] = '999888';
check('changing the PIN ends sync tokens too', Azure_POS_Events::verify_sync($opened['sync_token']) === 0);
WP_Shim::$post_meta[$fair][Azure_POS_Events::META_PIN] = '246810';

echo "Sales made offline\n";
authorize_as_sync();
function authorize_as_sync() {
    global $signed_in, $sync;
    return Azure_PTSA_REST_POS::authorize($signed_in, new WP_REST_Request(array(), array(), $sync), true);
}
$offline = function (array $body) { return Azure_PTSA_REST_POS::offline_sale(new WP_REST_Request(array(), $body)); };
$saved = array(
    'sale_id' => 'a1b2c3d4-0001', 'event_id' => $fair, 'created_at' => $now, 'currency' => 'USD', 'amount_cents' => 650, 'intent_id' => 'pi_off1',
    'lines' => array(array('product_id' => 40, 'quantity' => 1), array('product_id' => 11, 'quantity' => 1)),
    'dispense' => array('id' => 2147483649, 'qty' => 5, 'given' => 5, 'reason' => 'none'),
);
$orders_before = count(FX::$orders);
stub('payment_intents/pi_off1', stripe_reply(array('id' => 'pi_off1', 'status' => 'succeeded', 'amount_received' => 650, 'currency' => 'usd', 'metadata' => array('offline_sale_id' => 'someone-else'))));
$stolen = $offline($saved);
check('a card payment made for another sale is refused', is_wp_error($stolen) && $stolen->get_error_code() === 'ptsa_pos_offline_intent' && count(FX::$orders) === $orders_before);
check('a sale Stripe doesn\'t have yet is refused', $offline(array('intent_id' => '') + $saved)->get_error_code() === 'ptsa_pos_offline');
check('a sale for another event is refused', $offline(array('event_id' => $gala) + $saved)->get_error_code() === 'ptsa_pos_scope');
check('a sale in another currency is refused', $offline(array('currency' => 'EUR') + $saved)->get_error_code() === 'ptsa_pos_offline');

stub('payment_intents/pi_off1', stripe_reply(array('id' => 'pi_off1', 'status' => 'requires_capture', 'amount_received' => 0, 'currency' => 'usd', 'metadata' => array('offline_sale_id' => 'a1b2c3d4-0001'))));
stub('payment_intents/pi_off1/capture', stripe_reply(array('id' => 'pi_off1', 'status' => 'succeeded', 'amount_received' => 650, 'currency' => 'usd', 'metadata' => array('offline_sale_id' => 'a1b2c3d4-0001'))));
$uploaded = $offline($saved);
$off_order = FX::$orders[$uploaded['order']['id']];
check('a sale made offline becomes a paid order', $uploaded['status'] === 'paid' && $off_order->status === 'completed' && $off_order->transaction === 'pi_off1' && $off_order->total === 6.5);
check('...after capturing the payment Stripe holds', last_stripe_call('pi_off1/capture') !== null);
check('...noting it was made offline, by whom', $off_order->get_meta('_pta_pos_seller') === 'a volunteer (event PIN, offline)' && $off_order->get_meta('_pta_pos_offline_sale') === 'a1b2c3d4-0001'
    && (bool) array_filter($off_order->notes, function ($n) { return strpos($n, 'made without a connection to the website') !== false; }));
check('...and what the dispenser paid out', in_array('The dispenser paid out 5 machine ticket(s).', $off_order->notes, true)
    && $off_order->get_meta(Azure_POS_Dispenser::ORDER_DISPENSED)['id'] === 2147483649);
$calls = count(WP_Shim::$http_calls);
$again = $offline($saved);
check('sending it again answers with the same order and asks Stripe nothing', $again['order']['id'] === $off_order->id && $again['status'] === 'paid' && count(FX::$orders) === $orders_before + 1 && count(WP_Shim::$http_calls) === $calls);
check('the same card payment can\'t pay for a second sale', $offline(array('sale_id' => 'a1b2c3d4-0002') + $saved)->get_error_message() === 'That card payment is already on another order.');

stub('payment_intents/pi_off2', stripe_reply(array('id' => 'pi_off2', 'status' => 'succeeded', 'amount_received' => 500, 'currency' => 'usd', 'metadata' => array('offline_sale_id' => 'a1b2c3d4-0003'))));
$repriced = $offline(array('sale_id' => 'a1b2c3d4-0003', 'intent_id' => 'pi_off2', 'amount_cents' => 500, 'lines' => array(array('product_id' => 40, 'quantity' => 1), array('product_id' => 99, 'quantity' => 2))) + $saved);
$repriced_order = FX::$orders[$repriced['order']['id']];
check('a sale whose prices changed since is still recorded as paid', $repriced['status'] === 'paid');
check('...noting the difference and what is gone from the website', in_array('The card was charged 5.00 USD but the items now come to 4.00 USD (prices changed, or items were removed).', $repriced_order->notes, true)
    && in_array('Items no longer on the website, so not on this order: 2 × product #99.', $repriced_order->notes, true));

$declined = $offline(array('sale_id' => 'a1b2c3d4-0004', 'intent_id' => '', 'failed' => 'Your card was declined.') + $saved);
$declined_order = FX::$orders[$declined['order']['id']];
check('a card declined once it reached Stripe is recorded as a failed order', $declined['status'] === 'failed' && $declined_order->status === 'failed'
    && in_array('Tap to Pay: the card payment was declined when the app sent it to Stripe: Your card was declined.', $declined_order->notes, true));
check('...with what the dispenser paid out regardless', in_array('The dispenser paid out 5 machine ticket(s).', $declined_order->notes, true));
authorize_as($pos);

echo "Not set up\n";
update_option(Azure_POS_Stripe::OPTION_LOCATION, '');
$off = Azure_PTSA_REST_POS::list_events(new WP_REST_Request());
check('without a Terminal location the app is told Tap to Pay isn\'t set up', is_wp_error($off) && $off->get_error_code() === 'ptsa_pos_off');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
