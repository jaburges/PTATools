<?php
/**
 * Order-scope product fields ("Order (no profile)"): plain inputs with no
 * child picker, never read from or written to a profile, but validated,
 * shown in the cart and saved to the order line like every other scope.
 * Also covers labels with apostrophes surviving the admin save unescaped.
 *
 * Run: php tests/test-product-fields-order-scope.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_URL')) {
    define('AZURE_PLUGIN_URL', 'https://example.test/wp-content/plugins/azure-plugin/');
}

class OS_State {
    public static $user_id = 0;
    public static $user_meta = array();
    public static $meta_writes = array();
    public static $notices = array();
    public static $orders = array();
}

class OS_Json_Exit extends Exception {
    public $ok; public $data;
    public function __construct($ok, $data) { parent::__construct('json'); $this->ok = $ok; $this->data = $data; }
}

function wc_get_product_term_ids($id, $tax) { return $tax === 'product_cat' ? array(900) : array(); }
function get_post_type($id) { return 'product'; }
function wc_get_product($id) { return $GLOBALS['product'] ?? false; }
function wc_get_order($id) { return OS_State::$orders[$id] ?? false; }
function wc_add_notice($msg, $type = 'success') { OS_State::$notices[] = $msg; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function esc_html_e($t, $d = null) { echo esc_html($t); }
function esc_attr_e($t, $d = null) { echo esc_attr($t); }
function wp_create_nonce($a) { return 'nonce'; }
function get_current_user_id() { return OS_State::$user_id; }
function get_user_meta($id, $key, $single = false) { return OS_State::$user_meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { OS_State::$meta_writes[] = array($id, $key, $value); return true; }
function wp_is_serving_rest_request() { return false; }
function wp_doing_ajax() { return false; }
function check_ajax_referer($a, $b) { return true; }
function current_user_can($cap) { return true; }
function wp_send_json_success($data = null) { throw new OS_Json_Exit(true, $data); }
function wp_send_json_error($data = null) { throw new OS_Json_Exit(false, $data); }

class WC_Product {
    public function get_id() { return 4242; }
    public function get_name() { return 'Spring Show Ticket'; }
    public function get_parent_id() { return 0; }
}

class OS_Item {
    public $meta = array();
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
}

class OS_Order {
    public $items; public $user_id;
    public function __construct($user_id, $items) { $this->user_id = $user_id; $this->items = $items; }
    public function get_user_id() { return $this->user_id; }
    public function get_items() { return $this->items; }
}

/** Answers the product-field and children queries by table name. */
class OS_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $groups = array();
    public $fields = array();
    public $children = array();
    public $child_meta = array();
    public $inserts = array();
    public $updates = array();
    private $args = array();
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function get_results($sql) {
        $args = $this->args;
        if (strpos($sql, 'azure_product_field_groups') !== false) {
            return array_map(function ($g) { return (object) $g; }, $this->groups);
        }
        if (strpos($sql, 'azure_product_fields') !== false) {
            return array_values(array_map(function ($f) { return (object) $f; }, array_filter($this->fields, function ($f) use ($args) {
                return $f['group_id'] === (int) $args[0];
            })));
        }
        if (strpos($sql, 'azure_user_children_meta') !== false) {
            $out = array();
            foreach ($this->child_meta[(int) $args[0]] ?? array() as $k => $v) {
                $out[] = (object) array('meta_key' => $k, 'meta_value' => $v);
            }
            return $out;
        }
        if (strpos($sql, 'azure_user_children') !== false && strpos($sql, 'INNER JOIN') !== false) {
            return array_map(function ($c) { return (object) $c; }, $this->children);
        }
        return array();
    }
    public function get_row($sql) { return null; }
    public function get_var($sql) {
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $sql, $m)) {
            return $m[1];
        }
        return 0;
    }
    public function delete($table, $where) { return 1; }
    public function insert($table, $data) { $this->inserts[] = array($table, $data); $this->insert_id = 77; return 1; }
    public function update($table, $data, $where) { $this->updates[] = array($table, $data, $where); return 1; }
}

$db = new OS_WPDB();
$GLOBALS['wpdb'] = $db;

$db->groups = array(array('id' => 5, 'name' => 'Performer details', 'is_active' => 1));
$order_fields = array(
    array('id' => 51, 'group_id' => 5, 'label' => "Actor's Name", 'field_key' => 'actor_name', 'scope' => 'order', 'field_type' => 'text', 'placeholder' => 'First and last', 'options_json' => '', 'required' => 1, 'save_to_profile' => 1, 'user_meta_key' => 'pta_pf_actor_name'),
    array('id' => 52, 'group_id' => 5, 'label' => 'Role', 'field_key' => 'show_role', 'scope' => 'order', 'field_type' => 'select', 'placeholder' => '-- Pick a role --', 'options_json' => '["Lead","Chorus","Crew"]', 'required' => 1, 'save_to_profile' => 0, 'user_meta_key' => ''),
    array('id' => 53, 'group_id' => 5, 'label' => 'Contact email', 'field_key' => 'show_email', 'scope' => 'order', 'field_type' => 'email', 'placeholder' => 'you@example.com', 'options_json' => '', 'required' => 1, 'save_to_profile' => 0, 'user_meta_key' => ''),
    array('id' => 54, 'group_id' => 5, 'label' => "Child's Name on the programme", 'field_key' => 'programme_name', 'scope' => 'order', 'field_type' => 'text', 'placeholder' => '', 'options_json' => '', 'required' => 1, 'save_to_profile' => 0, 'user_meta_key' => ''),
);
$db->fields = $order_fields;
$db->children = array(array('id' => 9, 'child_name' => 'Ada', 'family_id' => 1, 'is_active' => 1));
$db->child_meta = array(9 => array('pta_pf_actor_name' => 'Saved Child Value', 'pta_pf_programme_name' => 'Saved Child Value'));

require_once AZURE_PLUGIN_PATH . 'includes/class-user-children.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-module.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-admin.php';

$t = new TestRunner('Product fields: Order (no profile) scope');
$M = 'Azure_Product_Fields_Module';
$module = (new ReflectionClass($M))->newInstanceWithoutConstructor();

// ── Scope helpers ──────────────────────────────────────────────────────
$t->equals(array('child', 'parent', 'family', 'order'), array_keys($M::scope_labels()), 'four scopes, existing ones first');
$t->equals('Order (no profile)', $M::scope_labels()['order'], 'the new scope is labelled Order (no profile)');
$t->equals('child', $M::field_scope((object) array('scope' => '')), 'an empty scope still means child');
$t->equals('child', $M::field_scope(array()), 'a raw entry without a scope still means child');
$t->check($M::is_order_scope(array('scope' => 'order')), 'raw cart entries are recognised too');

$order_child_label = (object) $order_fields[3];
$t->check(!$M::is_child_name_field($order_child_label), "an Order field labelled Child's Name does not identify a child");
$t->check($M::is_child_name_field((object) array('field_key' => 'child_name', 'label' => "Child's Name", 'scope' => 'child')), 'the child-scope Child Name field still does');
$t->check(!$M::is_family_child_core_field($order_child_label), 'and it is not pulled into the family roster');

$order_group = (object) array('fields' => array_map(function ($f) { return (object) $f; }, $order_fields));
$t->check(!$M::groups_need_child_selector(array($order_group)), 'four Order fields need no child picker');
$mixed = (object) array('fields' => array_merge($order_group->fields, array((object) array('field_key' => 'allergies', 'label' => 'Allergies', 'scope' => 'child'))));
$t->check($M::groups_need_child_selector(array($mixed)), 'one Child-scope field brings the picker back');

// ── Product page, logged in with a child on the profile ────────────────
OS_State::$user_id = 7;
OS_State::$user_meta[7] = array('pta_pf_actor_name' => 'Saved Parent Value', 'pta_pf_show_email' => 'saved@example.com');
$GLOBALS['product'] = new WC_Product();
ob_start();
$module->render_product_fields();
$html = ob_get_clean();

$t->check(strpos($html, 'azure-pf-select-child') === false, 'no child dropdown');
$t->check(strpos($html, '-- Select child --') === false, 'no "-- Select child --" option');
$t->check(strpos($html, 'azure-pf-add-child') === false, 'no + Child button or modal');
$t->check(strpos($html, 'Saved Parent Value') === false && strpos($html, 'saved@example.com') === false, 'nothing is pre-filled from the parent profile');
$t->check(strpos($html, 'Saved Child Value') === false && strpos($html, '"children":[]') !== false, 'no child profile data is sent to the page');
$t->equals(4, substr_count($html, 'data-field-scope="order"'), 'all four fields render as Order inputs');
$t->equals(4, substr_count($html, ' required'), 'each one is required');
$t->check(strpos($html, '<input type="text" name="azure_pf_51" id="azure_pf_51" value="" placeholder="First and last" required />') !== false, 'text input keeps its type and placeholder');
$t->check(strpos($html, '<input type="email" name="azure_pf_53"') !== false, 'email input keeps its type');
$t->check(strpos($html, '<option value="">-- Pick a role --</option><option value="Lead">Lead</option><option value="Chorus">Chorus</option><option value="Crew">Crew</option>') !== false, 'select keeps its placeholder and options');
$t->check(strpos($html, 'name="azure_pf_54"') !== false, "the Order field labelled Child's Name is still a plain input");
$t->check(strpos($html, 'Actor&#039;s Name') !== false, 'the apostrophe label renders once-escaped for HTML');

// Inline block-card form takes the same path.
$t->check(!$M::groups_need_child_selector($M::get_groups_for_product(4242)), 'the inline card skips the picker for the same groups');

// ── Add to cart validation ─────────────────────────────────────────────
OS_State::$notices = array();
$_POST = array('azure_pf_form' => '1', 'azure_pf_51' => '', 'azure_pf_52' => 'Lead', 'azure_pf_53' => '', 'azure_pf_54' => 'Ada');
$t->equals(false, $module->validate_fields(true, 4242, 1), 'blank Order fields are refused');
$t->equals(array("\"Actor's Name\" is a required field.", '"Contact email" is a required field.'), OS_State::$notices, 'naming each blank field');

OS_State::$notices = array();
$_POST = array('azure_pf_form' => '1', 'azure_pf_51' => "Sam O\\'Brien", 'azure_pf_52' => 'Lead', 'azure_pf_53' => 'sam@example.com', 'azure_pf_54' => 'Sam');
$t->equals(true, $module->validate_fields(true, 4242, 1), 'a filled-in form passes without a child selected');
$t->equals(array(), OS_State::$notices, 'with no notices');

// ── Cart and checkout line-item meta ───────────────────────────────────
$cart_item = $module->add_cart_item_data(array(), 4242, 0);
$fields = $cart_item['azure_product_fields'];
$t->equals(array(51, 52, 53, 54), array_keys($fields), 'all four values go into the cart item');
$t->equals("Sam O'Brien", $fields[51]['value'], 'posted values are unslashed');
$t->equals('order', $fields[51]['scope'], 'the scope travels with the value');
$t->equals(false, $fields[51]['save_to_profile'], 'Order values are never flagged for a profile save, even on legacy rows');
$t->check(!isset($cart_item['azure_pf_child_id']), 'no child is attached to the cart item');

$shown = $module->display_cart_item_data(array(), $cart_item);
$t->equals(array(
    array('key' => "Actor's Name", 'value' => "Sam O'Brien"),
    array('key' => 'Role', 'value' => 'Lead'),
    array('key' => 'Contact email', 'value' => 'sam@example.com'),
    array('key' => "Child's Name on the programme", 'value' => 'Sam'),
), $shown, 'cart and checkout show every Order field');

$item = new OS_Item();
$module->save_order_item_meta($item, 'k', $cart_item, null);
$t->equals("Sam O'Brien", $item->meta["Actor's Name"], 'order item keeps the label meta');
$t->equals('Lead', $item->meta['_pta_show_role'], 'and the stable _pta_<field_key> meta');
$t->equals($fields, $item->meta['_azure_product_fields_raw'], 'and the raw payload');
$t->check(!isset($item->meta['_azure_pf_child_id']), 'and no child id');

// ── Nothing reaches a profile ──────────────────────────────────────────
$legacy_raw = $fields;
$legacy_raw[51]['save_to_profile'] = true;
OS_State::$orders[1001] = new OS_Order(7, array($item));
OS_State::$orders[1002] = new OS_Order(7, array((function () use ($legacy_raw) { $i = new OS_Item(); $i->meta['_azure_product_fields_raw'] = $legacy_raw; return $i; })()));
OS_State::$meta_writes = array();
$module->save_to_user_profile(1001);
$module->save_to_user_profile(1002);
$t->equals(array(), OS_State::$meta_writes, 'no user meta is written, even for a raw entry flagged save_to_profile');

$children = (new ReflectionClass('Azure_User_Children'))->newInstanceWithoutConstructor();
$t->equals('', call_private($children, 'extract_child_name', array($legacy_raw)), "an Order field labelled Child's Name is not used to find or create a child");
$t->equals(array(), call_private($children, 'build_child_meta', array($legacy_raw)), 'no child meta is built');
$t->equals(array(), call_private($children, 'build_family_meta', array($legacy_raw)), 'no family meta is built');
$db->inserts = array();
$db->updates = array();
$children->auto_save_from_order(1002);
$t->equals(array(), array_merge($db->inserts, $db->updates), 'completing the order creates or updates no child profile');

$t->equals('Ada', call_private($children, 'extract_child_name', array(array(
    array('field_key' => 'child_name', 'label' => "Child's Name", 'value' => 'Ada', 'scope' => 'child'),
))), 'child-scope Child Name still resolves the child');
$t->equals('Ada', call_private($children, 'extract_child_name', array(array(
    array('field_key' => 'child_name', 'label' => "Child's Name", 'value' => 'Ada'),
))), 'raw entries from before scopes were stored still resolve the child');

// ── Admin save: scope and apostrophes ──────────────────────────────────
$admin = (new ReflectionClass('Azure_Product_Fields_Admin'))->newInstanceWithoutConstructor();
function os_save_field(array $post) {
    $_POST = $post;
    try {
        $GLOBALS['admin']->ajax_save_field();
    } catch (OS_Json_Exit $e) {
        return $e;
    }
    return null;
}
$GLOBALS['admin'] = $admin;

$db->inserts = array();
$res = os_save_field(array(
    'group_id' => 5, 'label' => "Actor\\'s Name", 'field_key' => '', 'scope' => 'order', 'field_type' => 'select',
    'placeholder' => "Pick the actor\\'s role", 'options' => "Lead\nTeacher\\'s pet", 'required' => 1,
    'save_to_profile' => 1, 'user_meta_key' => 'pta_pf_x',
));
$t->check($res && $res->ok, 'an Order field saves');
$saved = $db->inserts[0][1];
$t->equals("Actor's Name", $saved['label'], 'the label is stored without a backslash');
$t->equals("Pick the actor's role", $saved['placeholder'], 'so is the placeholder');
$t->equals('["Lead","Teacher\'s pet"]', $saved['options_json'], 'and the options');
$t->equals('order', $saved['scope'], 'the Order scope is kept');
$t->equals(0, $saved['save_to_profile'], 'Save to user profile is forced off for Order fields');
$t->equals('', $saved['user_meta_key'], 'and no user meta key is stored');
$t->equals('actorsname', $saved['field_key'], 'the field key is derived from the clean label');

foreach (array('child', 'parent', 'family') as $scope) {
    $db->inserts = array();
    os_save_field(array('group_id' => 5, 'label' => 'Shirt', 'scope' => $scope, 'save_to_profile' => 1));
    $t->equals($scope, $db->inserts[0][1]['scope'], "the {$scope} scope still saves");
    $t->equals(1, $db->inserts[0][1]['save_to_profile'], "{$scope} fields still save to the profile");
}
$db->inserts = array();
os_save_field(array('group_id' => 5, 'label' => 'Shirt', 'scope' => 'bogus'));
$t->equals('child', $db->inserts[0][1]['scope'], 'an unknown scope still falls back to child');

$db->inserts = array();
$_POST = array('name' => "Actor\\'s details", 'description' => "Tonight\\'s show", 'is_active' => 1, 'categories' => array());
try { $admin->ajax_save_group(); } catch (OS_Json_Exit $e) {}
$t->equals("Actor's details", $db->inserts[0][1]['name'], 'group names are stored without a backslash');
$t->equals("Tonight's show", $db->inserts[0][1]['description'], 'and group descriptions');

exit($t->finish() === 0 ? 0 : 1);
