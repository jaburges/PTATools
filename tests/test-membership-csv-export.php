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

exit($t->finish() === 0 ? 0 : 1);
