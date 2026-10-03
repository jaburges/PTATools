<?php
/**
 * Membership CSV is sold orders (family + individual + staff), including guests.
 *
 * Run: php tests/test-membership-csv-export.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('Membership CSV export');

WP_Shim::reset();
WP_Shim::$settings['membership_family_product_ids'] = array(23233);
WP_Shim::$settings['membership_individual_product_ids'] = array();

$t->equals('family', Azure_Membership_Module::classify_membership_name('PTSA Family Membership'), 'family title');
$t->equals('individual', Azure_Membership_Module::classify_membership_name('PTSA Individual Membership'), 'individual title without picker');
$t->equals('staff', Azure_Membership_Module::classify_membership_name('PTSA Membership - Wilder Staff'), 'staff title');
$t->equals('', Azure_Membership_Module::classify_membership_name('Spirit Wear Tee'), 'non-membership title');
$t->equals('individual', Azure_Membership_Module::classify_membership_product(23231, 0, 'PTSA Individual Membership'), 'individual by name when ids unset');
$t->equals('family', Azure_Membership_Module::classify_membership_product(23233, 0, 'Something else'), 'configured family id wins');

class Azure_Test_Membership_Item {
    public $product_id;
    public $name;
    public $meta = array();
    public $variation_id = 0;

    public function __construct($product_id, $name, array $meta = array()) {
        $this->product_id = (int) $product_id;
        $this->name = $name;
        $this->meta = $meta;
    }

    public function get_product_id() {
        return $this->product_id;
    }

    public function get_variation_id() {
        return $this->variation_id;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_meta($key) {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }
}

class Azure_Test_Membership_Order {
    public $items = array();
    public $user_id = 0;
    public $first = '';
    public $last = '';
    public $email = '';
    public $paid_at = '2026-08-27 18:00:00';

    public function get_items() {
        return $this->items;
    }

    public function get_user_id() {
        return $this->user_id;
    }

    public function get_billing_first_name() {
        return $this->first;
    }

    public function get_billing_last_name() {
        return $this->last;
    }

    public function get_billing_email() {
        return $this->email;
    }

    public function get_date_paid() {
        return $this->paid_at;
    }

    public function get_date_created() {
        return $this->paid_at;
    }
}

$family = new Azure_Test_Membership_Order();
$family->first = 'Ada';
$family->last = 'Lovelace';
$family->email = 'ada@example.com';
$family->items[] = new Azure_Test_Membership_Item(23233, 'PTSA Family Membership', array(
    '_pta_child_name' => 'Aarin Bommineni, Anika Bommineni',
    '_pta_childsgrade' => '3, K',
    '_pta_parent_2_name' => 'William King',
    '_pta_parent_2_email' => 'william@example.com',
));

$guest_indiv = new Azure_Test_Membership_Order();
$guest_indiv->user_id = 0;
$guest_indiv->first = 'Melanie';
$guest_indiv->last = 'Modrell';
$guest_indiv->email = 'guest@example.com';
$guest_indiv->paid_at = '2026-08-27 19:00:00';
$guest_indiv->items[] = new Azure_Test_Membership_Item(23231, 'PTSA Individual Membership', array(
    '_pta_child_name' => 'Mallory Payne',
    '_pta_childsgrade' => '5',
));

$staff = new Azure_Test_Membership_Order();
$staff->first = 'Pat';
$staff->last = 'Teacher';
$staff->email = 'pat@wilderptsa.net';
$staff->paid_at = '2026-08-26 12:00:00';
$staff->items[] = new Azure_Test_Membership_Item(32959, 'PTSA Membership - Wilder Staff');

$donated = new Azure_Test_Membership_Order();
$donated->first = 'Skip';
$donated->last = 'Me';
$donated->email = 'skip@example.com';
$donated->items[] = new Azure_Test_Membership_Item(23231, 'PTSA Individual Membership', array(
    '_pta_donated_product' => '1',
));

$t->equals('individual', Azure_Membership_Module::item_membership_type($guest_indiv->items[0]), 'guest individual line is a membership');
$t->equals('', Azure_Membership_Module::item_membership_type($donated->items[0]), 'donated line is skipped');
$t->equals(
    'Mallory Payne (5)',
    Azure_Membership_Module::children_label_from_item($guest_indiv->items[0]),
    'child name and grade from item meta'
);
$t->equals(
    'Aarin Bommineni (3); Anika Bommineni (K)',
    Azure_Membership_Module::children_label_from_item($family->items[0]),
    'family roster children concatenate'
);

$rows = Azure_Membership_Module::sold_membership_rows_from_orders(array($family, $guest_indiv, $staff, $donated));
$types = array();
$emails = array();
foreach ($rows as $row) {
    $types[] = $row['membership'];
    $emails[] = $row['email'];
}
sort($types);
$t->equals(array('family', 'individual', 'staff'), $types, 'CSV includes family, individual, and staff');
$t->check(in_array('guest@example.com', $emails, true), 'guest individual checkout is exported');
$t->check(!in_array('skip@example.com', $emails, true), 'donated membership is not exported');

$guest_row = null;
foreach ($rows as $row) {
    if ($row['email'] === 'guest@example.com') {
        $guest_row = $row;
        break;
    }
}
$t->check($guest_row && $guest_row['name'] === 'Melanie Modrell', 'guest billing name is used');
$t->check($guest_row && $guest_row['children'] === 'Mallory Payne (5)', 'guest child comes from the order line');
$t->check($guest_row && $guest_row['parent_2_name'] === '' && $guest_row['parent_2_email'] === '', 'individual has no parent 2');

$family_row = null;
foreach ($rows as $row) {
    if ($row['membership'] === 'family') {
        $family_row = $row;
        break;
    }
}
$t->check($family_row && $family_row['parent_2_name'] === 'William King', 'family CSV includes parent 2 name from checkout');
$t->check($family_row && $family_row['parent_2_email'] === 'william@example.com', 'family CSV includes parent 2 email from checkout');

$raw_item = new Azure_Test_Membership_Item(23233, 'PTSA Family Membership', array(
    '_azure_product_fields_raw' => array(
        array('field_key' => 'parent_2_name', 'label' => 'Parent 2 Name', 'value' => 'Ada King'),
        array('field_key' => 'parent_2_email', 'label' => 'Parent 2 Email', 'value' => 'ada.king@example.com'),
    ),
));
$from_raw = Azure_Membership_Module::parent_2_from_order_item($family, $raw_item, 0);
$t->equals('Ada King', $from_raw['name'], 'parent 2 name from product-fields payload');
$t->equals('ada.king@example.com', $from_raw['email'], 'parent 2 email from product-fields payload');

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        if ((int) $user_id === 44 && $key === Azure_Membership_Module::META_P2_NAME) {
            return 'Charles Babbage';
        }
        if ((int) $user_id === 44 && $key === Azure_Membership_Module::META_P2_EMAIL) {
            return 'charles@example.com';
        }
        return '';
    }
}
$profile_item = new Azure_Test_Membership_Item(23233, 'PTSA Family Membership');
$from_profile = Azure_Membership_Module::parent_2_from_order_item($family, $profile_item, 44);
$t->equals('Charles Babbage', $from_profile['name'], 'parent 2 name falls back to Family Info meta');
$t->equals('charles@example.com', $from_profile['email'], 'parent 2 email falls back to Family Info meta');

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($url, $action = -1, $name = '_wpnonce') {
        $sep = strpos($url, '?') === false ? '?' : '&';
        return $url . $sep . $name . '=nonce-' . $action;
    }
}

$export_url = Azure_Membership_Module::export_csv_url();
$t->check(strpos($export_url, 'page=azure-plugin-membership') !== false, 'export URL stays on the Membership page handler');
$t->check(strpos($export_url, 'export=csv') !== false, 'export URL requests the sold-memberships CSV');
$t->check(strpos($export_url, 'nonce-azure_membership_admin') !== false, 'export URL carries the Membership admin nonce');

$gb_url = Azure_Membership_Module::givebacks_csv_url();
$t->check(strpos($gb_url, 'export=givebacks') !== false, 'GiveBacks URL requests the GiveBacks export');
$t->check(strpos($gb_url, 'nonce-azure_membership_admin') !== false, 'GiveBacks URL carries the Membership admin nonce');

$t->equals('Parent/Guardian', Azure_Membership_Module::givebacks_member_type('family'), 'family is Parent/Guardian');
$t->equals('Parent/Guardian', Azure_Membership_Module::givebacks_member_type('individual'), 'individual is Parent/Guardian');
$t->equals('Faculty/Staff', Azure_Membership_Module::givebacks_member_type('staff'), 'staff is Faculty/Staff');

$repeat = new Azure_Test_Membership_Order();
$repeat->first = 'Melanie';
$repeat->last = 'Modrell';
$repeat->email = 'guest@example.com';
$repeat->items[] = new Azure_Test_Membership_Item(23231, 'PTSA Individual Membership');

$solo_family = new Azure_Test_Membership_Order();
$solo_family->first = 'Grace';
$solo_family->last = 'Hopper';
$solo_family->email = 'grace@example.com';
$solo_family->items[] = new Azure_Test_Membership_Item(23233, 'PTSA Family Membership');

$gb = Azure_Membership_Module::givebacks_rows_from_orders(array($family, $guest_indiv, $staff, $donated, $repeat, $solo_family), 2027);
$t->equals(5, count($gb), 'GiveBacks has one row per member: both family parents, donated and repeat buyers excluded');
$by_email = array();
foreach ($gb as $r) {
    $by_email[$r[2]] = $r;
}
$t->equals(array('Ada', 'Lovelace', 'ada@example.com', '', 'Parent/Guardian', '2027'), $by_email['ada@example.com'] ?? null, 'family purchaser row');
$t->equals(array('William', 'King', 'william@example.com', '', 'Parent/Guardian', '2027'), $by_email['william@example.com'] ?? null, 'family parent 2 gets its own Parent/Guardian row');
$t->check(isset($by_email['grace@example.com']), 'family with no parent 2 on file still exports the purchaser');
$t->equals('Faculty/Staff', $by_email['pat@wilderptsa.net'][4] ?? '', 'staff row is Faculty/Staff');
$t->check(!isset($by_email['skip@example.com']), 'donated membership excluded from GiveBacks');
$t->equals('Hopper', $gb[0][1], 'rows sorted by last name');

$t->equals(array('Yuxun (Mingming)', 'Lei'), Azure_Membership_Module::split_full_name('Yuxun (Mingming) Lei'), 'last word is the last name');
$t->equals(array('Mary Ann', 'Smith'), Azure_Membership_Module::split_full_name('  Mary  Ann Smith '), 'extra spaces collapse');
$t->equals(array('Cher', ''), Azure_Membership_Module::split_full_name('Cher'), 'single name is a first name');

$same_p2 = new Azure_Test_Membership_Order();
$same_p2->first = 'Ada';
$same_p2->last = 'Lovelace';
$same_p2->email = 'ada@example.com';
$same_p2->items[] = new Azure_Test_Membership_Item(23233, 'PTSA Family Membership', array(
    '_pta_parent_2_name' => 'Ada Lovelace',
    '_pta_parent_2_email' => 'ADA@example.com',
));
$t->equals(1, count(Azure_Membership_Module::givebacks_rows_from_orders(array($same_p2), 2027)), 'parent 2 matching the purchaser is not duplicated');

$t->equals(
    "\"First Name\",\"Last Name\",\"Email\",\"Phone Number\",\"Member Type\",\"School Year Ending\"\n",
    Azure_Membership_Module::givebacks_csv_line(array('First Name', 'Last Name', 'Email', 'Phone Number', 'Member Type', 'School Year Ending')),
    'header matches the GiveBacks template byte for byte'
);
$t->equals("\"O\"\"Brien\",\"+1 425 555 0100\",\"'=SUM(A1)\"\n", Azure_Membership_Module::givebacks_csv_line(array('O"Brien', '+1 425 555 0100', '=SUM(A1)')), 'quotes escaped, phone kept, formulas neutralised');

$year = Azure_Membership_Module::school_year_ending();
$now = new DateTimeImmutable('now', wp_timezone());
$expected_year = ((int) $now->format('n') >= 8) ? (int) $now->format('Y') + 1 : (int) $now->format('Y');
$t->equals($expected_year, $year, 'school year ending follows the Aug 1 school year');

$widget = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php');
$t->check(strpos($widget, 'export_csv_url()') !== false, 'Membership dashboard widget uses the same CSV URL');
$t->check(strpos($widget, "esc_html_e('Export'") !== false, 'Membership dashboard widget has an Export button');

exit($t->finish() === 0 ? 0 : 1);
