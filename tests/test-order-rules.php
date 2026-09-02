<?php
/**
 * Order-rule helpers: recipients, tokens, product match.
 *
 * Run: php tests/test-order-rules.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return strtolower(trim((string) $email));
    }
}
if (!function_exists('is_email')) {
    function is_email($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return trim(strip_tags((string) $string));
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-order-rules-module.php';

$t = new TestRunner('Order rules');

$parsed = Azure_Order_Rules_Module::parse_to_emails("librarian@school.org, bad@@x, volunteer@example.org\nparent@example.org");
$t->equals(array('librarian@school.org', 'volunteer@example.org', 'parent@example.org'), $parsed['emails'], 'valid emails are kept, de-duped, ordered');
$t->equals(array('bad@@x'), $parsed['errors'], 'invalid addresses are reported');

$empty = Azure_Order_Rules_Module::parse_to_emails('   ,;;  ');
$t->equals(array(), $empty['emails'], 'whitespace-only input yields no emails');

$t->equals('order_info', Azure_Order_Rules_Module::normalize_token_key('{order info}'), 'spaces become underscores');
$t->equals('order_name', Azure_Order_Rules_Module::normalize_token_key('{{order_name}}'), 'braces stripped');

$ctx = array(
    'order_number'  => '1042',
    'customer_name' => 'Jane Doe',
    'product_name'  => 'Celebration Book',
    'order_info'    => "Order #1042\nCustomer: Jane Doe",
);
$out = Azure_Order_Rules_Module::replace_tokens(
    'New {product_name} #{order_number} for {customer_name}. {order info} leftover {unknown}',
    $ctx
);
$t->check(strpos($out, 'Celebration Book') !== false, 'product_name is replaced');
$t->check(strpos($out, '#1042') !== false, 'order_number is replaced');
$t->check(strpos($out, 'Jane Doe') !== false, 'customer_name is replaced');
$t->check(strpos($out, 'Order #1042') !== false, '{order info} with a space maps to order_info');
$t->check(strpos($out, '{unknown}') !== false, 'unknown tokens stay in the body');

$html = Azure_Order_Rules_Module::default_email_html();
$t->check(strpos($html, '{order_info}') !== false, 'default template includes {order_info}');
$t->check(strpos($html, '{product_name}') !== false, 'default template includes {product_name}');

class Azure_Test_Order_Item {
    public $product_id;
    public $variation_id;
    public $name;
    public $qty;
    public $meta;
    public function __construct($product_id, $name, $qty = 1, $variation_id = 0, $meta = array()) {
        $this->product_id = $product_id;
        $this->variation_id = $variation_id;
        $this->name = $name;
        $this->qty = $qty;
        $this->meta = $meta;
    }
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return $this->variation_id; }
    public function get_name() { return $this->name; }
    public function get_quantity() { return $this->qty; }
    public function get_meta_data() {
        $out = array();
        foreach ($this->meta as $key => $value) {
            $out[] = (object) array('key' => $key, 'value' => $value);
        }
        return $out;
    }
}

class Azure_Test_Order {
    public $items = array();
    public function get_id() { return 88; }
    public function get_order_number() { return '1042'; }
    public function get_billing_first_name() { return 'Jane'; }
    public function get_billing_last_name() { return 'Doe'; }
    public function get_billing_email() { return 'jane@example.org'; }
    public function get_status() { return 'processing'; }
    public function get_total() { return '25.00'; }
    public function get_formatted_order_total() { return '$25.00'; }
    public function get_date_created() { return null; }
    public function get_formatted_billing_address() { return ''; }
    public function get_items() { return $this->items; }
}

$item = new Azure_Test_Order_Item(17, 'Celebration Book', 1, 0, array(
    '_pta_child_name' => 'Sam Lee',
    '_pta_child_grade' => '3',
    '_hidden' => 'nope',
));
$other = new Azure_Test_Order_Item(99, 'Spirit Wear', 2);
$order = new Azure_Test_Order();
$order->items = array($item, $other);

$t->check(Azure_Order_Rules_Module::order_contains_product($order, 17), 'matches the celebration book product id');
$t->check(!Azure_Order_Rules_Module::order_contains_product($order, 55), 'does not match a different product');

$variation = new Azure_Test_Order_Item(17, 'Yearbook — hardcover', 1, 501);
$var_order = new Azure_Test_Order();
$var_order->items = array($variation);
$t->check(Azure_Order_Rules_Module::order_contains_product($var_order, 17), 'parent id matches a variation line');
$t->check(Azure_Order_Rules_Module::order_contains_product($var_order, 501), 'variation id also matches');

$fields = Azure_Order_Rules_Module::item_product_fields($item);
$t->check(isset($fields['Child Name']) && $fields['Child Name'] === 'Sam Lee', 'pta field becomes a readable label');
$t->check(!isset($fields['Hidden']) && !in_array('nope', $fields, true), 'non-pta underscore meta is skipped');

$tokens = Azure_Order_Rules_Module::build_token_context($order, 17);
$t->equals('Jane Doe', $tokens['customer_name'], 'customer_name from billing');
$t->equals('jane@example.org', $tokens['customer_email'], 'customer_email from billing');
$t->equals('1042', $tokens['order_number'], 'order number');
$t->equals('Celebration Book', $tokens['product_name'], 'matched product name only');
$t->equals('1', $tokens['product_qty'], 'matched qty ignores other items');
$t->equals('Sam Lee', $tokens['child_name'], 'child name from product fields');
$t->check(strpos($tokens['order_items'], 'Spirit Wear × 2') !== false, 'order_items lists every line');
$t->check(strpos($tokens['order_info'], 'Celebration Book') !== false, 'order_info includes matched product');
$t->check(strpos($tokens['product_fields'], 'Child Grade: 3') !== false, 'product_fields lists matched pta values');

$clean = Azure_Order_Rules_Module::sanitize_rule_input(array(
    'name'          => '<b>Librarian</b>',
    'enabled'       => '1',
    'trigger_type'  => 'product_ordered',
    'trigger_value' => '17',
    'action_type'   => 'send_email',
    'to_emails'     => 'librarian@school.org, nope',
    'email_subject' => 'Book #{order_number}',
));
$t->equals('Librarian', $clean['name'], 'rule name strips tags');
$t->equals('17', $clean['trigger_value'], 'product id stored as string of int');
$t->equals(array('librarian@school.org'), $clean['to_email_list'], 'only valid To addresses kept');
$t->equals(array('nope'), $clean['to_errors'], 'bad To reported');

exit($t->finish() === 0 ? 0 : 1);
