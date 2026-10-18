<?php
/**
 * PTA Products block / [pta-products]: product cards for a page or sidebar,
 * with "Show input fields" on (fields + Add to cart on the card) or off
 * (every card opens its product page).
 *
 * Run: php tests/test-pta-products-block.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_URL')) {
    define('AZURE_PLUGIN_URL', 'https://example.test/wp-content/plugins/azure-plugin/');
}

class PB_State {
    public static $products = array();
    public static $category = array();
    public static $queries = array();
    public static $blocks = array();
    public static $shortcodes = array();
    public static $enqueued = array();
}

function add_shortcode($tag, $cb) { PB_State::$shortcodes[$tag] = $cb; }
function shortcode_atts($defaults, $atts, $tag = '') { return array_merge($defaults, array_intersect_key((array) $atts, $defaults)); }
function register_block_type($name, $args) { PB_State::$blocks[$name] = $args; return true; }
function wp_register_style($h, $src, $deps = array(), $ver = false) { return true; }
function wp_register_script($h, $src, $deps = array(), $ver = false, $footer = false) { PB_State::$blocks['_script_deps'] = $deps; return true; }
function wp_enqueue_style($h) { PB_State::$enqueued[] = $h; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return esc_html($t); }
function wp_kses_post($s) { return $s; }
function get_block_wrapper_attributes() { return 'class="wp-block-pta-products"'; }
function wc_get_product($id) { return PB_State::$products[(int) $id] ?? false; }
function wc_get_products($args) {
    PB_State::$queries[] = $args;
    $slug = $args['category'][0] ?? '';
    return array_slice(PB_State::$category[$slug] ?? array(), 0, $args['limit']);
}

class PB_Product {
    public $id; public $name; public $type; public $status; public $fields; public $inline;
    public function __construct($id, $name, $opts = array()) {
        $this->id = $id;
        $this->name = $name;
        $this->type = $opts['type'] ?? 'simple';
        $this->status = $opts['status'] ?? 'publish';
        $this->fields = $opts['fields'] ?? false;
        $this->inline = $opts['inline'] ?? $this->fields;
        PB_State::$products[$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_status() { return $this->status; }
    public function get_permalink() { return 'https://example.test/product/p' . $this->id . '/'; }
    public function get_image($size, $attr = array()) { return '<img src="p' . $this->id . '.jpg" alt="">'; }
    public function get_price_html() { return '<span class="amount">$' . $this->id . '</span>'; }
    public function is_type($t) { return $this->type === $t; }
    public function is_purchasable() { return true; }
    public function is_in_stock() { return true; }
    public function supports($f) { return $f === 'ajax_add_to_cart' && $this->type === 'simple' && !$this->fields; }
    public function add_to_cart_url() { return $this->supports('ajax_add_to_cart') ? '?add-to-cart=' . $this->id : $this->get_permalink(); }
    public function add_to_cart_text() { return $this->supports('ajax_add_to_cart') ? 'Add to cart' : 'Select options'; }
}

class Azure_Product_Fields_Module {
    public static function get_instance() { return new self(); }
    public static function product_supports_inline_fields($p) { return $p->inline; }
    public static function product_needs_product_page($id) { return PB_State::$products[$id]->fields; }
    public function inline_form_html($p) { return '<div class="azure-pf-inline" id="azure-pf-card-' . $p->id . '"><form>fields</form></div>'; }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-pta-products-block.php';

$t = new TestRunner('PTA Products block');

new PB_Product(10, 'Student Membership', array('fields' => true));
new PB_Product(11, 'Staff Membership', array('fields' => true));
new PB_Product(12, 'Family Membership', array('type' => 'variable', 'fields' => true, 'inline' => false));
new PB_Product(13, 'PTSA Sticker');
new PB_Product(14, 'Old Membership', array('status' => 'draft'));
PB_State::$category['membership'] = array(10, 11, 12);

// ── Registration ───────────────────────────────────────────────────────
WP_Shim::$did_actions['init'] = 1;
$block = Azure_PTA_Products_Block::get_instance();
$reg = PB_State::$blocks['pta/products'] ?? null;
$t->check($reg !== null && $reg['category'] === 'widgets' && $reg['title'] === 'PTA Products', 'the block registers during init, in the Widgets category');
$t->check(isset($reg['attributes']['showFields']) && $reg['attributes']['showFields']['default'] === false, 'Show input fields is an attribute and starts off');
$t->check(in_array('wp-server-side-render', PB_State::$blocks['_script_deps'], true), 'the editor previews with the server render');
$t->check(isset(PB_State::$shortcodes['pta-products']), 'the [pta-products] shortcode is registered');

// ── Settings ───────────────────────────────────────────────────────────
$n = Azure_PTA_Products_Block::normalize(array('category' => 'Membership', 'product_ids' => '12, 10,10,abc,-3', 'show_fields' => 'off', 'columns' => 9, 'limit' => 0, 'show_image' => 'no'));
$t->equals('membership', $n['category'], 'category is a slug');
$t->equals(array(12, 10), $n['product_ids'], 'ticked products keep their order, without duplicates or junk');
$t->check($n['show_fields'] === false && $n['show_image'] === false && $n['show_price'] === true, 'on/off values parse');
$t->check($n['columns'] === 4 && $n['limit'] === 1, 'columns and limit are clamped');

// ── Fields off: every card opens its product page ──────────────────────
$html = $block->render_block(array('category' => 'membership'));
$t->check(strpos($html, '<div class="wp-block-pta-products"><ul class="pta-products pta-products--cols-1" style="--pta-products-columns:1;">') === 0, 'a one-column list by default');
$t->equals(3, substr_count($html, 'class="pta-products__item"'), 'one card per category product');
$t->check(strpos($html, '<a class="wp-block-button__link wp-element-button pta-products__button" href="https://example.test/product/p10/">Select options</a>') !== false, 'fields off: the button opens the product page');
$t->check(strpos($html, 'azure-pf-inline') === false && strpos($html, 'add-to-cart') === false && strpos($html, 'ajax_add_to_cart') === false, 'fields off: no fields and nothing added from the card');
$t->check(strpos($html, '<h3 class="pta-products__title"><a href="https://example.test/product/p11/">Staff Membership</a></h3>') !== false, 'the title links to the product');
$t->check(strpos($html, '<img src="p10.jpg"') !== false && strpos($html, '<div class="pta-products__price"><span class="amount">$10</span></div>') !== false, 'image and price show by default');
$t->equals('membership', PB_State::$queries[0]['category'][0], 'the category query uses the chosen category');
$t->check(PB_State::$queries[0]['status'] === 'publish' && PB_State::$queries[0]['visibility'] === 'catalog', 'and only published products shown in the shop');
$t->check(in_array('azure-pta-products', PB_State::$enqueued, true), 'the card styles load when cards render');

$picked = $block->render_block(array('productIds' => array(13, 11, 14)));
$t->check(strpos($picked, 'p13/">View product</a>') !== false, 'a product without fields says View product');
$t->check(strpos($picked, 'p13') < strpos($picked, 'p11'), 'ticked products show in the order they were ticked');
$t->check(strpos($picked, 'Old Membership') === false, 'unpublished products are skipped');

$both = $block->render_block(array('productIds' => array(13, 10), 'category' => 'membership'));
$t->equals(4, substr_count($both, 'class="pta-products__item"'), 'ticked products plus the category, each product once');
$t->check(strpos($both, 'p13') < strpos($both, 'p11'), 'ticked products come first');

$custom = $block->render_block(array('productIds' => array(10), 'buttonText' => 'Join now', 'showImage' => false, 'showPrice' => false, 'columns' => 2));
$t->check(strpos($custom, '>Join now</a>') !== false && strpos($custom, '<img') === false && strpos($custom, 'pta-products__price') === false, 'button text, image and price follow the settings');
$t->check(strpos($custom, '--pta-products-columns:2;') !== false, 'columns follow the setting');

// ── Fields on ──────────────────────────────────────────────────────────
$on = $block->render_block(array('productIds' => array(10, 12, 13), 'showFields' => true));
$t->check(strpos($on, '<div class="azure-pf-inline" id="azure-pf-card-10"><form>fields</form></div>') !== false, 'fields on: a product with fields shows them on the card');
$t->check(strpos($on, 'href="https://example.test/product/p12/">Select options</a>') !== false, 'fields on: Family membership (variable) still opens its page');
$t->check(strpos($on, 'href="?add-to-cart=13" class="wp-block-button__link wp-element-button pta-products__button add_to_cart_button ajax_add_to_cart" data-product_id="13"') !== false, 'fields on: a product without fields gets a normal Add to cart');

// ── Empty and shortcode ────────────────────────────────────────────────
$t->equals('', $block->render_block(array()), 'nothing chosen: nothing on the live page');
define('REST_REQUEST', true);
$t->check(strpos($block->render_block(array()), 'Choose a category or tick products') !== false, 'in the editor preview it says what to do');

$sc = $block->render_shortcode(array('category' => 'membership', 'fields' => 'off', 'columns' => '1'));
$t->check(strpos($sc, '<div class="wp-block-pta-products"><ul class="pta-products') === 0 && substr_count($sc, 'pta-products__item') === 3, 'the shortcode renders the same cards');
$t->check(strpos($block->render_shortcode(array('products' => '10', 'fields' => 'on')), 'azure-pf-card-10') !== false, 'the shortcode takes fields="on" and a product list');

$plugin = file_get_contents(dirname(__DIR__) . '/Azure Plugin/azure-plugin.php');
$t->check(strpos($plugin, "'class-pta-products-block.php',") !== false && strpos($plugin, 'Azure_PTA_Products_Block::get_instance();') !== false, 'the plugin loads the block with Product Fields');

exit($t->finish() > 0 ? 1 : 0);
