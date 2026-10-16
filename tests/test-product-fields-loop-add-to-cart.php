<?php
/**
 * Products whose category or tag maps to product fields must collect them
 * on the product page: loop, block, shortcode and Store API buttons link
 * there as "Select options", and a refused background add is sent there.
 *
 * Run: php tests/test-product-fields-loop-add-to-cart.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class PF_State {
    /** product id => [taxonomy => term ids] */
    public static $terms = array();
    public static $post_types = array();
    public static $parents = array();
    public static $products = array();
    public static $notices = array();
    public static $rest = false;
}

function wc_get_product_term_ids($id, $tax) { return PF_State::$terms[$id][$tax] ?? array(); }
function get_post_type($id) { return PF_State::$post_types[$id] ?? 'product'; }
function wp_get_post_parent_id($id) { return PF_State::$parents[$id] ?? 0; }
function wc_get_product($id) { return PF_State::$products[(int) $id] ?? false; }
function get_permalink($id) { return 'https://example.test/product/p' . (int) $id . '/'; }
function wc_add_notice($msg, $type = 'success') { PF_State::$notices[] = array($type, $msg); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_is_serving_rest_request() { return PF_State::$rest; }
function wp_doing_ajax() { return false; }
function get_current_user_id() { return 0; }

class PF_Product {
    public $id; public $name; public $type; public $purchasable; public $in_stock;
    public function __construct($id, $name, $type = 'simple', $purchasable = true, $in_stock = true) {
        $this->id = $id; $this->name = $name; $this->type = $type; $this->purchasable = $purchasable; $this->in_stock = $in_stock;
        PF_State::$products[$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_parent_id() { return PF_State::$parents[$this->id] ?? 0; }
    public function get_permalink() { return get_permalink($this->id); }
    public function is_type($t) { return $this->type === $t; }
    public function is_purchasable() { return $this->purchasable; }
    public function is_in_stock() { return $this->in_stock; }
}

/** Answers the two queries get_groups_for_product() runs. */
class PF_WPDB {
    public $prefix = 'wp_';
    public $groups = array();
    public $fields = array();
    public $map = array();
    public $queries = 0;
    private $args = array();
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function get_results($sql) {
        $this->queries++;
        $args = $this->args;
        if (strpos($sql, 'INNER JOIN') !== false) {
            $out = array();
            foreach ($this->groups as $g) {
                if (empty($g['is_active'])) {
                    continue;
                }
                foreach ($this->map as $m) {
                    if ($m['group_id'] === $g['id'] && in_array($m['term_id'], $args, true)) {
                        $out[$g['id']] = (object) $g;
                    }
                }
            }
            return array_values($out);
        }
        $out = array();
        foreach ($this->fields as $f) {
            if ($f['group_id'] === (int) $args[0]) {
                $out[] = (object) $f;
            }
        }
        return $out;
    }
}

$db = new PF_WPDB();
$GLOBALS['wpdb'] = $db;

// Category 178 (Yearbook) → child details; tag 300 → shirt size; tag 301 → an empty group; tag 302 → inactive group.
$db->groups = array(
    array('id' => 1, 'name' => 'Child details', 'is_active' => 1),
    array('id' => 2, 'name' => 'Shirt size', 'is_active' => 1),
    array('id' => 3, 'name' => 'Empty', 'is_active' => 1),
    array('id' => 4, 'name' => 'Old', 'is_active' => 0),
);
$db->fields = array(
    array('id' => 1, 'group_id' => 1, 'label' => 'Child Name', 'field_key' => 'child_name', 'required' => 1, 'scope' => 'child'),
    array('id' => 2, 'group_id' => 1, 'label' => 'Childs Grade', 'field_key' => 'childsgrade', 'required' => 1, 'scope' => 'child'),
    array('id' => 10, 'group_id' => 1, 'label' => 'Child Teacher', 'field_key' => 'child_teacher', 'required' => 1, 'scope' => 'child'),
    array('id' => 20, 'group_id' => 2, 'label' => 'Size', 'field_key' => 'shirt_size', 'required' => 1, 'scope' => 'parent'),
    array('id' => 30, 'group_id' => 4, 'label' => 'Old field', 'field_key' => 'old', 'required' => 1, 'scope' => 'parent'),
);
$db->map = array(
    array('group_id' => 1, 'term_id' => 178),
    array('group_id' => 2, 'term_id' => 300),
    array('group_id' => 3, 'term_id' => 301),
    array('group_id' => 4, 'term_id' => 302),
);

$yearbook = new PF_Product(32954, '2026-27 Yearbook');
PF_State::$terms[32954] = array('product_cat' => array(178), 'product_tag' => array(177));
$shirt = new PF_Product(500, 'Spirit Shirt');
PF_State::$terms[500] = array('product_cat' => array(15), 'product_tag' => array(300));
$mug = new PF_Product(600, 'Mug');
PF_State::$terms[600] = array('product_cat' => array(15), 'product_tag' => array(301, 302));
$variation = new PF_Product(501, 'Spirit Shirt - Large', 'variation');
PF_State::$post_types[501] = 'product_variation';
PF_State::$parents[501] = 500;
$family = new PF_Product(700, 'Family PTSA Membership');
$external = new PF_Product(800, 'Yearbook ad', 'external');
PF_State::$terms[800] = array('product_cat' => array(178));
$sold_out = new PF_Product(801, 'Old yearbook', 'simple', true, false);
PF_State::$terms[801] = array('product_cat' => array(178));

require_once AZURE_PLUGIN_PATH . 'includes/class-express-checkout.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-module.php';

$t = new TestRunner('Product fields: loop and block add-to-cart');
$M = 'Azure_Product_Fields_Module';
$module = (new ReflectionClass($M))->newInstanceWithoutConstructor();

// ── Mapping ────────────────────────────────────────────────────────────
$groups = $M::get_groups_for_product(32954);
$t->equals(array(1), array_map(function ($g) { return (int) $g->id; }, $groups), 'the yearbook gets the group mapped to its Yearbook category');
$t->equals(array('Child Name', 'Childs Grade', 'Child Teacher'), array_map(function ($f) { return $f->label; }, $groups[0]->fields), 'with Child Name, Grade and Teacher');
$t->equals(array(178, 177), $M::product_term_ids(32954), 'categories and tags are both looked up');
$t->equals(array(2), array_map(function ($g) { return (int) $g->id; }, $M::get_groups_for_product(500)), 'a group mapped to a tag applies');
$t->equals(array(2), array_map(function ($g) { return (int) $g->id; }, $M::get_groups_for_product(501)), 'a variation uses its parent\'s categories and tags');
$t->equals(array(3), array_map(function ($g) { return (int) $g->id; }, $M::get_groups_for_product(600)), 'inactive groups are ignored');
$before = $db->queries;
$M::get_groups_for_product(32954);
$t->equals($before, $db->queries, 'groups are looked up once per product per request');

$t->check($M::product_needs_product_page(32954), 'the yearbook needs its product page');
$t->check($M::product_needs_product_page(500), 'a tag-mapped product needs its product page');
$t->check(!$M::product_needs_product_page(600), 'a group with no fields does not');
$t->check($M::product_needs_product_page(700), 'family membership (child roster) does');
$t->check(!$M::product_needs_product_page(0), 'no product, no page');

// ── Buttons ────────────────────────────────────────────────────────────
$t->equals(false, $module->filter_product_supports(true, 'ajax_add_to_cart', $yearbook), 'the yearbook does not add in the background');
$t->equals(true, $module->filter_product_supports(true, 'ajax_add_to_cart', $mug), 'unmapped products still add in the background');
$t->equals(true, $module->filter_product_supports(true, 'ajax_add_to_cart', $shirt) === false, 'tag-mapped products do not add in the background');
$t->equals(true, $module->filter_product_supports(true, 'ajax_add_to_cart', $sold_out), 'sold-out products are left alone');
$t->equals(true, $module->filter_product_supports(true, 'other_feature', $yearbook), 'other features are untouched');
$t->equals('https://example.test/product/p32954/', $module->filter_add_to_cart_url('?add-to-cart=32954', $yearbook), 'the button links to the product page');
$t->equals('?add-to-cart=600', $module->filter_add_to_cart_url('?add-to-cart=600', $mug), 'unmapped products keep their add link');
$t->equals('https://ads.example/', $module->filter_add_to_cart_url('https://ads.example/', $external), 'external products keep their link');
$t->equals('Select options', $module->filter_add_to_cart_text('Add to cart', $yearbook), 'the button says Select options');
$t->equals('Add to cart', $module->filter_add_to_cart_text('Add to cart', $mug), 'unmapped products still say Add to cart');
$t->equals('Read more', $module->filter_add_to_cart_text('Read more', $sold_out), 'a sold-out product keeps Read more');
$t->check(strpos($module->filter_add_to_cart_description('Add to cart: x', $yearbook), 'Select options for') === 0, 'the screen-reader label matches');

// Store API (JS product blocks): has_options only inside /wc/store requests.
$t->equals(false, $module->filter_has_options(false, $yearbook), 'outside the Store API has_options is untouched (block product form stays valid)');
$req = new class { public function get_route() { return '/wc/store/v1/products'; } };
$module->store_api_request_started(null, null, $req);
$t->equals(true, $module->filter_has_options(false, $yearbook), 'Store API reports options for the yearbook, so blocks link to the page');
$t->equals(false, $module->filter_has_options(false, $mug), 'Store API leaves unmapped products addable');
$module->store_api_request_finished(null, null, $req);
$t->equals(false, $module->filter_has_options(false, $yearbook), 'the flag clears after the Store API request');
$other = new class { public function get_route() { return '/wp/v2/posts'; } };
$module->store_api_request_started(null, null, $other);
$t->equals(false, $module->filter_has_options(false, $yearbook), 'other REST routes do not set it');
$module->store_api_request_finished(null, null, $other);

$block_button = '<button class="wp-block-button__link add_to_cart_button ajax_add_to_cart product_type_simple" data-product_id="32954" data-wp-on--click="actions.addCartItem">Add to cart</button>';
$link = '<a href="/order-a-yearbook/?add-to-cart=32954" data-quantity="1" class="button product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="32954">Add to cart</a>';
$fixed = $module->filter_loop_add_to_cart_link($link, $yearbook);
$t->check(strpos($fixed, 'ajax_add_to_cart') === false, 'a rebuilt loop link loses the ajax class');
$t->check(strpos($fixed, 'href="https://example.test/product/p32954/"') !== false && strpos($fixed, 'add-to-cart=') === false, 'and links to the product page');
$t->check(strpos($fixed, 'class="button product_type_simple add_to_cart_button"') !== false, 'other classes are kept');
$fixed_btn = $module->filter_loop_add_to_cart_link($block_button, $yearbook);
$t->check(strpos($fixed_btn, 'addCartItem') === false && strpos($fixed_btn, 'ajax_add_to_cart') === false, 'a forced block button loses its background-add action');
$t->equals($link, $module->filter_loop_add_to_cart_link($link, $mug), 'unmapped loop links are untouched');
$t->check(strpos($module->filter_grid_item_html($link, array(), $yearbook), 'add-to-cart=') === false, 'legacy grid block items are fixed too');
$t->check(strpos($M::loop_link_html('<a href="/?foo=1&#038;add-to-cart=5" class="x">', 'https://e.test/p/'), 'href="https://e.test/p/"') !== false, 'encoded &add-to-cart links are rewritten');

// ── Refused background adds ────────────────────────────────────────────
$_POST = array();
$_GET = array();
PF_State::$notices = array();
$M::reset_redirect();
$t->equals(false, $module->validate_fields(true, 32954, 1), 'a background yearbook add is refused');
$t->equals(1, count(PF_State::$notices), 'with a single notice');
$t->equals('“2026-27 Yearbook” needs a few details first. Fill them in below, then add it to your cart.', PF_State::$notices[0][1], 'that tells them to fill in the details');
$t->equals(32954, $M::redirect_product_id(), 'and the product page redirect is queued');
$t->equals('https://example.test/product/p32954/', $module->redirect_after_error('https://example.test/shop/', 32954), 'wc-ajax add_to_cart sends them to the product page');
$t->equals('https://example.test/shop/', $module->redirect_after_error('https://example.test/shop/', 600), 'other products keep WooCommerce\'s redirect');

PF_State::$notices = array();
PF_State::$rest = true;
$M::reset_redirect();
$t->equals(false, $module->validate_fields(true, 32954, 1), 'a Store API add is refused');
$t->check(strpos(PF_State::$notices[0][1], 'Open its product page') !== false, 'with a message pointing at the product page');
PF_State::$rest = false;

PF_State::$notices = array();
$M::reset_redirect();
$_POST = array('azure_pf_form' => '1', 'azure_pf_1' => '', 'azure_pf_2' => 'K', 'azure_pf_10' => '');
$t->equals(false, $module->validate_fields(true, 32954, 1), 'the product form with blanks is refused');
$t->equals(2, count(PF_State::$notices), 'naming each missing field');
$t->equals(0, $M::redirect_product_id(), 'without a redirect (they are on the page)');

PF_State::$notices = array();
$_POST = array('azure_pf_form' => '1', 'azure_pf_1' => 'Ada', 'azure_pf_2' => 'K', 'azure_pf_10' => 'Congdon');
$t->equals(true, $module->validate_fields(true, 32954, 1), 'the filled-in product form passes');
$t->equals(0, count(PF_State::$notices), 'with no notices');

$_POST = array('azure_pf_1' => 'Ada', 'azure_pf_2' => 'K', 'azure_pf_10' => 'Congdon');
$t->equals(true, $module->validate_fields(true, 32954, 1), 'a form without the marker but with the fields still passes');

$_POST = array();
PF_State::$notices = array();
$t->equals(true, $module->validate_fields(true, 600, 1), 'unmapped products add from anywhere');
$_POST = array('pta_donated_product' => '1');
$t->equals(true, $module->validate_fields(true, 32954, 1), 'donated products are exempt');
$_POST = array();
$_GET = array('order_again' => '12');
$M::reset_redirect();
$module->validate_fields(true, 32954, 1);
$t->equals(0, $M::redirect_product_id(), 'Order again is not redirected');
$_GET = array();

$t->check($M::request_has_field_form(array('azure_pf_children' => array())), 'the family roster counts as the product form');
$t->check(!$M::request_has_field_form(array('quantity' => 1, 'add-to-cart' => 5)), 'a plain add-to-cart post does not');

// ── Express checkout stays hidden ──────────────────────────────────────
$t->check($M::product_has_required_fields(500), 'tag-mapped required fields count as required');
$t->equals(true, Azure_Express_Checkout::hide_stripe_product_page_wallet(false, $M::product_has_required_fields(32954)), 'product-page wallets stay hidden on the yearbook');
$t->equals(true, Azure_Express_Checkout::hide_stripe_product_page_wallet(false, $M::product_has_required_fields(500)), 'and on tag-mapped products');
$t->equals(false, Azure_Express_Checkout::hide_stripe_product_page_wallet(false, $M::product_has_required_fields(600)), 'unmapped products keep them');

// ── Wiring ─────────────────────────────────────────────────────────────
$src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-product-fields-module.php');
foreach (array(
    "add_filter('woocommerce_product_supports', array(\$this, 'filter_product_supports'), 20, 3)",
    "add_filter('woocommerce_product_add_to_cart_url', array(\$this, 'filter_add_to_cart_url'), 20, 2)",
    "add_filter('woocommerce_product_add_to_cart_text', array(\$this, 'filter_add_to_cart_text'), 20, 2)",
    "add_filter('woocommerce_loop_add_to_cart_link', array(\$this, 'filter_loop_add_to_cart_link'), 20, 3)",
    "add_filter('woocommerce_blocks_product_grid_item_html', array(\$this, 'filter_grid_item_html'), 20, 3)",
    "add_filter('woocommerce_product_has_options', array(\$this, 'filter_has_options'), 20, 2)",
    "add_filter('woocommerce_cart_redirect_after_error', array(\$this, 'redirect_after_error'), 20, 2)",
    "add_action('wp_loaded', array(\$this, 'maybe_redirect_failed_add'), 21)",
    "add_action('woocommerce_before_add_to_cart_button', array(\$this, 'render_product_fields'))",
    "add_filter('wc_stripe_hide_payment_request_on_product_page', array(\$this, 'hide_stripe_product_page_wallet'), 20, 2)",
    "add_filter('wc_stripe_show_payment_request_on_product_page', array(\$this, 'hide_product_page_express_pay'), 20)",
) as $hook) {
    $t->check(strpos($src, $hook) !== false, 'hooked: ' . substr($hook, 0, 70));
}
$t->check(strpos($src, '<input type="hidden" name="azure_pf_form" value="1" />') !== false, 'the product form carries its marker');
$admin = file_get_contents(AZURE_PLUGIN_PATH . 'admin/product-fields-page.php');
$t->check(strpos($admin, "'taxonomy'   => 'product_tag'") !== false, 'groups can be assigned to product tags in the admin');

exit($t->finish() === 0 ? 0 : 1);
