<?php
/**
 * Product fields inline on block product cards: the Product Collection
 * card renders the product's fields with a real form, posts them through
 * WooCommerce's add-to-cart validation and cart-item-data path, and shows
 * errors under the card.
 *
 * Run: php tests/test-product-fields-inline-card.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_URL')) {
    define('AZURE_PLUGIN_URL', 'https://example.test/wp-content/plugins/azure-plugin/');
}

class IC_State {
    public static $terms = array();
    public static $products = array();
    public static $notices = array();
    public static $enqueued = array();
    public static $referer = '';
    public static $cart = array();
}

class IC_Json_Exit extends Exception {
    public $ok; public $data;
    public function __construct($ok, $data) { parent::__construct('json'); $this->ok = $ok; $this->data = $data; }
}

function wc_get_product_term_ids($id, $tax) { return IC_State::$terms[$id][$tax] ?? array(); }
function get_post_type($id) { return 'product'; }
function wc_get_product($id) { return IC_State::$products[(int) $id] ?? false; }
function get_permalink($id) { return 'https://example.test/product/p' . (int) $id . '/'; }
function wc_add_notice($msg, $type = 'success') { IC_State::$notices[] = array('type' => $type, 'notice' => $msg); }
function wc_get_notices($type = '') {
    $out = array();
    foreach (IC_State::$notices as $n) {
        if ($type === '' || $n['type'] === $type) {
            $out[] = array('notice' => $n['notice'], 'data' => array());
        }
    }
    return $out;
}
function wc_clear_notices() { IC_State::$notices = array(); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return $v; }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function add_query_arg($k, $v, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $k . '=' . $v; }
function remove_query_arg($k, $url) { return preg_replace('/[?&]' . preg_quote($k, '/') . '=[^&#]*/', '', (string) $url); }
function wp_get_raw_referer() { return IC_State::$referer; }
function wp_validate_redirect($url, $fallback = '') { return strpos((string) $url, 'https://example.test/') === 0 ? $url : $fallback; }
function wp_doing_ajax() { return false; }
function wp_is_serving_rest_request() { return false; }
function get_current_user_id() { return 0; }
function get_user_meta($id, $key, $single = false) { return ''; }
function wp_enqueue_style($h) { IC_State::$enqueued[] = $h; }
function wp_enqueue_script($h) { IC_State::$enqueued[] = $h; }
function wp_localize_script($h, $name, $data) { IC_State::$enqueued[] = $name; }
function wc_get_cart_url() { return 'https://example.test/cart/'; }
function wc_stock_amount($q) { return (int) $q; }
function absint($v) { return abs((int) $v); }
function do_action($hook) { return null; }
function woocommerce_mini_cart() {
    foreach (IC_State::$cart as $item) {
        echo '<li>' . esc_html($item['name']) . '</li>';
    }
}
function wp_send_json_success($data = null) { throw new IC_Json_Exit(true, $data); }
function wp_send_json_error($data = null) { throw new IC_Json_Exit(false, $data); }

$GLOBALS['ic_module'] = null;
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'woocommerce_add_to_cart_validation') {
        return $GLOBALS['ic_module']->validate_fields($value, $args[0], $args[1]);
    }
    if ($hook === 'woocommerce_add_to_cart_fragments') {
        $value['a.pta-header-cart'] = '<a class="pta-header-cart">' . count(IC_State::$cart) . '</a>';
    }
    return $value;
}

class IC_Cart {
    public function add_to_cart($product_id, $qty) {
        $data = $GLOBALS['ic_module']->add_cart_item_data(array(), $product_id, 0);
        IC_State::$cart[] = array('id' => $product_id, 'qty' => $qty, 'name' => wc_get_product($product_id)->get_name(), 'data' => $data);
        return 'key' . count(IC_State::$cart);
    }
    public function get_cart_contents_count() { return count(IC_State::$cart); }
    public function get_cart_hash() { return md5(json_encode(IC_State::$cart)); }
}
class IC_WC { public $cart; public function __construct() { $this->cart = new IC_Cart(); } }
function WC() { static $wc = null; return $wc ?: ($wc = new IC_WC()); }
class WC_AJAX { public static function get_endpoint($r) { return '/?wc-ajax=' . $r; } }

class IC_Product {
    public $id; public $name; public $type; public $in_stock;
    public function __construct($id, $name, $type = 'simple', $in_stock = true) {
        $this->id = $id; $this->name = $name; $this->type = $type; $this->in_stock = $in_stock;
        IC_State::$products[$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_parent_id() { return 0; }
    public function get_permalink() { return get_permalink($this->id); }
    public function is_type($t) { return $this->type === $t; }
    public function is_purchasable() { return true; }
    public function is_in_stock() { return $this->in_stock; }
}

class IC_WPDB {
    public $prefix = 'wp_';
    private $args = array();
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function get_results($sql) {
        $args = $this->args;
        if (strpos($sql, 'INNER JOIN') !== false) {
            return in_array(178, $args, true) ? array((object) array('id' => 1, 'name' => 'Child details', 'is_active' => 1)) : array();
        }
        return array(
            (object) array('id' => 1, 'group_id' => 1, 'label' => 'Child Name', 'field_key' => 'child_name', 'field_type' => 'text', 'placeholder' => '', 'required' => 1, 'scope' => 'child', 'save_to_profile' => 0),
            (object) array('id' => 2, 'group_id' => 1, 'label' => 'Childs Grade', 'field_key' => 'childsgrade', 'field_type' => 'text', 'placeholder' => '', 'required' => 1, 'scope' => 'child', 'save_to_profile' => 0),
            (object) array('id' => 10, 'group_id' => 1, 'label' => 'Child Teacher', 'field_key' => 'child_teacher', 'field_type' => 'text', 'placeholder' => '', 'required' => 1, 'scope' => 'child', 'save_to_profile' => 0),
        );
    }
}
$GLOBALS['wpdb'] = new IC_WPDB();

$yearbook = new IC_Product(32954, '2026-27 Yearbook');
IC_State::$terms[32954] = array('product_cat' => array(178), 'product_tag' => array(177));
$second = new IC_Product(32955, 'Yearbook ad');
IC_State::$terms[32955] = array('product_cat' => array(178));
$mug = new IC_Product(600, 'Mug');
$variable = new IC_Product(900, 'Yearbook bundle', 'variable');
IC_State::$terms[900] = array('product_cat' => array(178));
$sold_out = new IC_Product(901, 'Old yearbook', 'simple', false);
IC_State::$terms[901] = array('product_cat' => array(178));
$family = new IC_Product(700, 'Family PTSA Membership');
IC_State::$terms[700] = array('product_cat' => array(178));

require_once AZURE_PLUGIN_PATH . 'includes/class-express-checkout.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-module.php';

$t = new TestRunner('Product fields inline on block cards');
$M = 'Azure_Product_Fields_Module';
$module = (new ReflectionClass($M))->newInstanceWithoutConstructor();
$GLOBALS['ic_module'] = $module;

$block = function ($product_id, $extra = array()) {
    return (object) array('context' => array('postId' => $product_id) + $extra);
};
$original = '<div class="wp-block-button"><button class="ajax_add_to_cart" data-wp-on--click="actions.addCartItem">Add to cart</button></div>';

// ── Which cards get fields ─────────────────────────────────────────────
$t->check($M::product_supports_inline_fields($yearbook), 'the yearbook card collects its fields inline');
$t->check(!$M::product_supports_inline_fields($mug), 'products without fields keep the normal button');
$t->check(!$M::product_supports_inline_fields($variable), 'variable products keep the product-page link');
$t->check(!$M::product_supports_inline_fields($sold_out), 'sold-out products keep their button');
$t->check(!$M::product_supports_inline_fields($family), 'family membership (child roster) keeps the product-page link');

$t->equals($original, $module->render_inline_product_button($original, array(), $block(600)), 'an unmapped card is untouched');
$t->equals($original, $module->render_inline_product_button($original, array(), $block(32954, array('woocommerce/isDescendantOfAddToCartWithOptions' => true))), 'the single-product add-to-cart block keeps its own form');

// ── The card form ──────────────────────────────────────────────────────
$_SERVER['REQUEST_URI'] = '/order-a-yearbook/';
$_POST = array();
$_GET = array();
$html = $module->render_inline_product_button($original, array(), $block(32954));
$t->check(strpos($html, 'ajax_add_to_cart') === false && strpos($html, 'addCartItem') === false, 'the background-add button is gone');
$t->check(strpos($html, '<form class="cart azure-pf-inline-form" method="post" action="/order-a-yearbook/#azure-pf-card-32954"') !== false, 'the card has a real form that posts back to the page');
$t->check(strpos($html, 'novalidate') !== false, 'browser bubbles are off so errors show under the card');
foreach (array(1 => 'Child Name', 2 => 'Childs Grade', 10 => 'Child Teacher') as $id => $label) {
    $t->check(strpos($html, 'name="azure_pf_' . $id . '"') !== false && strpos($html, '>' . $label . ' <span class="required">*</span></label>') !== false, 'the card shows ' . $label);
    $t->check(strpos($html, 'id="azure_pf_p32954_' . $id . '"') !== false && strpos($html, 'for="azure_pf_p32954_' . $id . '"') !== false, $label . ' has a card-specific id');
}
$t->check(strpos($html, 'name="azure_pf_form" value="1"') !== false, 'the form carries the product-fields marker');
$t->check(strpos($html, 'name="azure_pf_inline" value="32954"') !== false, 'and names its product');
$t->check(strpos($html, '<button type="submit" name="add-to-cart" value="32954"') !== false, 'the add button submits the form');
$t->check(strpos($html, 'name="quantity" value="1"') !== false, 'with a quantity of one');
$t->check(strpos($html, 'class="azure-pf-inline-errors" role="alert" hidden') !== false, 'the error area waits under the card');
$t->check(in_array('azure-product-fields-inline', IC_State::$enqueued, true) && in_array('azurePfInline', IC_State::$enqueued, true), 'the card script and styles load with it');
$other = $module->render_inline_product_button($original, array(), $block(32955));
$t->check(strpos($other, 'id="azure_pf_p32955_1"') !== false && strpos($other, 'id="azure_pf_p32954_1"') === false, 'two cards never share an id');

// ── No-JS post that failed ─────────────────────────────────────────────
$_POST = array('azure_pf_form' => '1', 'azure_pf_inline' => '32954', 'azure_pf_1' => 'Sam', 'azure_pf_2' => '', 'azure_pf_10' => '', 'add-to-cart' => '32954');
IC_State::$notices = array();
$t->equals(false, $module->validate_fields(true, 32954, 1), 'a card post with blanks fails validation');
$html = $module->render_inline_product_button($original, array(), $block(32954));
$t->check(strpos($html, '<li>&quot;Childs Grade&quot; is a required field.</li>') !== false && strpos($html, 'Child Teacher') !== false, 'the errors show under that card');
$t->check(strpos($html, 'role="alert" hidden') === false, 'and are visible');
$t->check(strpos($html, 'value="Sam"') !== false, 'what they typed is kept');
$t->equals(array(), IC_State::$notices, 'the errors are not repeated elsewhere on the page');
$plain = $module->render_inline_product_button($original, array(), $block(32955));
$t->check(strpos($plain, 'role="alert" hidden') !== false, 'other cards stay clean');

$_POST = array();
$_GET = array('azure_pf_added' => '32954');
$html = $module->render_inline_product_button($original, array(), $block(32954));
$t->check(strpos($html, 'Added to your cart. <a href="https://example.test/cart/">View cart</a>') !== false, 'after a no-JS add the card says it was added');
$_GET = array();

// No-JS success redirects back to the card.
$_POST = array('azure_pf_inline' => '32954');
IC_State::$referer = 'https://example.test/order-a-yearbook/';
$t->equals('https://example.test/order-a-yearbook/?azure_pf_added=32954#azure-pf-card-32954', $module->inline_add_redirect(false, null), 'a no-JS add returns to the card');
IC_State::$referer = 'https://evil.example/';
$t->equals(false, $module->inline_add_redirect(false, null), 'an off-site referrer is ignored');
IC_State::$referer = 'https://example.test/order-a-yearbook/';
WP_Shim::$options['woocommerce_cart_redirect_after_add'] = 'yes';
$t->equals(false, $module->inline_add_redirect(false, null), '"Redirect to the cart" still wins');
unset(WP_Shim::$options['woocommerce_cart_redirect_after_add']);
$_POST = array();
$t->equals('https://x/', $module->inline_add_redirect('https://x/', null), 'other adds are untouched');

// ── AJAX add from the card ─────────────────────────────────────────────
$call = function (array $post) use ($module) {
    $_POST = $post;
    IC_State::$notices = array();
    try {
        $module->ajax_inline_add_to_cart();
    } catch (IC_Json_Exit $e) {
        return $e;
    }
    return null;
};

$r = $call(array('azure_pf_product_id' => '32954', 'azure_pf_form' => '1', 'azure_pf_inline' => '32954', 'quantity' => '1', 'azure_pf_1' => '', 'azure_pf_2' => '', 'azure_pf_10' => ''));
$t->check($r && !$r->ok, 'blank fields: the AJAX add is refused');
$t->equals(array('"Child Name" is a required field.', '"Childs Grade" is a required field.', '"Child Teacher" is a required field.'), $r->data['errors'], 'with each missing field for the card');
$t->equals(0, count(IC_State::$cart), 'and nothing is added');
$t->equals(array(), IC_State::$notices, 'the notices are not left for another page');

$r = $call(array('azure_pf_product_id' => '32954', 'quantity' => '1'));
$t->check($r && !$r->ok && count(IC_State::$cart) === 0, 'a post without the fields at all is refused too');

$r = $call(array('azure_pf_product_id' => '32954', 'azure_pf_form' => '1', 'azure_pf_inline' => '32954', 'quantity' => '1', 'azure_pf_1' => 'Ada', 'azure_pf_2' => 'K', 'azure_pf_10' => 'Congdon'));
$t->check($r && $r->ok, 'filled fields: the AJAX add succeeds');
$t->equals(1, count(IC_State::$cart), 'one item is in the cart');
$values = array();
foreach (IC_State::$cart[0]['data']['azure_product_fields'] as $f) {
    $values[$f['label']] = $f['value'];
}
$t->equals(array('Child Name' => 'Ada', 'Childs Grade' => 'K', 'Child Teacher' => 'Congdon'), $values, 'with the values from the card');
$t->equals(1, $r->data['cart_count'], 'the reply has the new cart count');
$t->check(strpos($r->data['fragments']['div.widget_shopping_cart_content'], '2026-27 Yearbook') !== false, 'and a fresh mini-cart');
$t->equals('<a class="pta-header-cart">1</a>', $r->data['fragments']['a.pta-header-cart'], 'and the header cart fragment');
$t->equals('', $r->data['redirect'], 'it stays on the page');

$r = $call(array('azure_pf_product_id' => '600', 'quantity' => '1'));
$t->check($r && !$r->ok && !empty($r->data['product_url']), 'the card endpoint only adds products that collect fields');
$r = $call(array('azure_pf_product_id' => '900', 'quantity' => '1'));
$t->check($r && !$r->ok, 'variable products are sent to their page');
$t->equals(1, count(IC_State::$cart), 'neither was added');

// ── Express checkout and wiring ────────────────────────────────────────
$t->equals(true, Azure_Express_Checkout::hide_stripe_product_page_wallet(false, $M::product_has_required_fields(32954)), 'product-page wallets stay hidden for the yearbook');
$src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-product-fields-module.php');
foreach (array(
    "add_filter('render_block_woocommerce/product-button', array(\$this, 'render_inline_product_button'), 20, 3)",
    "add_action('wc_ajax_azure_pf_add_to_cart', array(\$this, 'ajax_inline_add_to_cart'))",
    "add_filter('woocommerce_add_to_cart_redirect', array(\$this, 'inline_add_redirect'), 20, 2)",
    "add_filter('wc_stripe_hide_payment_request_on_product_page', array(\$this, 'hide_stripe_product_page_wallet'), 20, 2)",
) as $hook) {
    $t->check(strpos($src, $hook) !== false, 'hooked: ' . substr($hook, 0, 72));
}
$js = file_get_contents(AZURE_PLUGIN_PATH . 'js/product-fields-inline.js');
$t->check(strpos($js, "data.push({ name: 'azure_pf_product_id'") !== false && strpos($js, "name: 'add-to-cart'") === false, 'the AJAX post never sends add-to-cart (WooCommerce would add it a second time)');
$t->check(strpos($js, "trigger('added_to_cart'") !== false, 'mini-carts hear about the add');
$css = file_get_contents(AZURE_PLUGIN_PATH . 'css/product-fields-inline.css');
$t->check(strpos($css, 'font-size: 16px;') !== false, 'card inputs are 16px so iPhones do not zoom');

exit($t->finish() === 0 ? 0 : 1);
