<?php
/**
 * Products sold in packs (10 concession tickets at a time).
 *
 * Run: php tests/test-product-packs.php
 */

require_once __DIR__ . '/wp-shim.php';

class Pack_Test_Product {
    public $id;
    public $type;
    public $meta;
    public function __construct($id, $type, array $meta) {
        $this->id = $id;
        $this->type = $type;
        $this->meta = $meta;
    }
    public function get_id() { return $this->id; }
    public function is_type($type) { return $this->type === $type; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
}

$GLOBALS['pack_products'] = array();
if (!function_exists('wc_get_product')) {
    function wc_get_product($id) { return $GLOBALS['pack_products'][$id] ?? false; }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-product-packs.php';

$t = new TestRunner('Products sold in packs');

$pack = new Pack_Test_Product(5, 'simple', array(
    Azure_Product_Packs::META_SIZE => '10',
    Azure_Product_Packs::META_BUTTON => 'Add 10 tickets',
    Azure_Product_Packs::META_TIP => 'We recommend around 10 tickets per person',
));
$plain = new Pack_Test_Product(6, 'simple', array());
$variable = new Pack_Test_Product(7, 'variable', array(Azure_Product_Packs::META_SIZE => '10'));
$GLOBALS['pack_products'] = array(5 => $pack, 6 => $plain, 7 => $variable);
$packs = Azure_Product_Packs::get_instance();

$t->equals(10, Azure_Product_Packs::size($pack), 'a pack product is sold in tens');
$t->equals(1, Azure_Product_Packs::size($plain), 'other products are sold one at a time');
$t->equals(1, Azure_Product_Packs::size($variable), 'packs apply to simple products only');

$t->equals(10, Azure_Product_Packs::round_up(1, 10), 'one ticket rounds up to a pack');
$t->equals(10, Azure_Product_Packs::round_up(10, 10), 'a whole pack stays');
$t->equals(20, Azure_Product_Packs::round_up(11, 10), 'part of a second pack rounds up to two packs');
$t->equals(10, Azure_Product_Packs::round_up(0, 10), 'never less than one pack');

$t->equals(10, $packs->store_api_minimum(1, $pack), 'cart blocks: the smallest line is one pack');
$t->equals(10, $packs->store_api_multiple_of(1, $pack), 'cart blocks: the quantity picker steps by a pack');
$t->equals(1, $packs->store_api_minimum(1, $plain), 'cart blocks: other products keep their minimum');
$t->equals(1, $packs->store_api_multiple_of(1, $plain), 'cart blocks: other products keep their step');

$args = $packs->quantity_input_args(array('min_value' => 1, 'step' => 1, 'input_value' => 1), $pack);
$t->equals(array(10, 10, 10), array($args['min_value'], $args['step'], $args['input_value']), 'product page: the quantity box starts at 10 and steps by 10');
$args = $packs->quantity_input_args(array('min_value' => 0, 'step' => 1, 'input_value' => 30), $pack);
$t->equals(30, $args['input_value'], 'cart page: an existing 30 stays 30');
$t->equals(array('min_value' => 1, 'step' => 1, 'input_value' => 1), $packs->quantity_input_args(array('min_value' => 1, 'step' => 1, 'input_value' => 1), $plain), 'other products keep their quantity box');

$t->equals(10, $packs->add_to_cart_quantity(1, 5), 'an add of one becomes a pack (upsell button, second click)');
$t->equals(20, $packs->add_to_cart_quantity(15, 5), 'a part pack rounds up');
$t->equals(3, $packs->add_to_cart_quantity(3, 6), 'other products add as asked');

$t->equals(10, $packs->loop_add_to_cart_args(array('quantity' => 1), $pack)['quantity'], 'upsell and shop buttons add a pack');
$t->equals(1, $packs->loop_add_to_cart_args(array('quantity' => 1), $plain)['quantity'], 'other shop buttons add one');

$t->equals('Add 10 tickets', $packs->add_to_cart_text('Add to cart', $pack), 'the button says "Add 10 tickets"');
$t->equals('Add to cart', $packs->add_to_cart_text('Add to cart', $plain), 'other buttons keep their text');

$t->equals('<p class="pta-pack-tip">We recommend around 10 tickets per person</p>', Azure_Product_Packs::tip_html($pack), 'the tip is shown under the button');
$t->equals('', Azure_Product_Packs::tip_html($plain), 'no tip, no line');
$GLOBALS['pack_products'][8] = new Pack_Test_Product(8, 'simple', array(Azure_Product_Packs::META_TIP => '<b>10</b> & more'));
$t->equals('<p class="pta-pack-tip">&lt;b&gt;10&lt;/b&gt; &amp; more</p>', Azure_Product_Packs::tip_html($GLOBALS['pack_products'][8]), 'the tip is escaped');

$fields = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-product-fields-module.php');
$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-product-packs.php');
$t->check(
    strpos($fields, "add_filter('woocommerce_product_add_to_cart_text', array(\$this, 'filter_add_to_cart_text'), 20, 2)") !== false
        && strpos($src, "add_filter('woocommerce_product_add_to_cart_text', array(\$this, 'add_to_cart_text'), 30, 2)") !== false,
    'the pack button text runs after the product-fields "Select options" text'
);
$block = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-pta-products-block.php');
$t->check(strpos($block, 'Azure_Product_Packs::size($product)') !== false && strpos($block, 'Azure_Product_Packs::tip_html($product)') !== false, 'PTA product cards add a pack and show the tip');

exit($t->finish() === 0 ? 0 : 1);
