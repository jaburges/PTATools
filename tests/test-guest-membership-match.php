<?php
/**
 * Guest membership → parent account matching.
 *
 * Run: php tests/test-guest-membership-match.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('Guest membership matching');

$t->equals('ada@example.com', Azure_Membership_Module::normalize_email('  Ada@Example.com '), 'email is trimmed and lowercased');
$t->check(Azure_Membership_Module::emails_are_same('rjbaummer@gmail.com', 'RJBaummer@gmail.com'), 'email match is case-insensitive');
$t->check(!Azure_Membership_Module::emails_are_same('a@x.com', 'b@x.com'), 'different emails do not match');

$t->equals(
    array('first' => 'Rebecca J', 'last' => 'Baummer'),
    Azure_Membership_Module::split_person_name('Rebecca J Baummer'),
    'display name splits last token as last name'
);

$t->check(
    Azure_Membership_Module::names_are_close('Rebecca', 'Baummer', 'Rebecca', 'Baummer', ''),
    'exact first+last matches'
);
$t->check(
    Azure_Membership_Module::names_are_close('Rob', 'Baummer', 'Robert', 'Baummer', ''),
    'short first name prefix of the account first name is close'
);
$t->check(
    Azure_Membership_Module::names_are_close('Nick', 'Mellors', 'Nicholas', 'Mellors', ''),
    'Nick and Nicholas are the same first name'
);
$t->check(
    Azure_Membership_Module::names_are_close('Becky', 'Grandmont', 'Rebecca', 'Grandmont', ''),
    'Becky and Rebecca are the same first name'
);
$t->check(
    Azure_Membership_Module::names_are_close('Rebecca', 'O Brien', 'Rebecca', 'O-Brien', ''),
    'hyphen vs space in the last name is close'
);
$t->check(
    Azure_Membership_Module::names_are_close('Olivia', 'Botello Fowler', 'Olivia', 'Fowler', ''),
    'compound last name shares a token with the account last name'
);
$t->check(
    Azure_Membership_Module::names_are_close('Katy', 'J Kaiser', 'Katy', 'Kaiser', ''),
    'middle initial in the last-name field still matches Kaiser'
);
$t->check(
    !Azure_Membership_Module::names_are_close('Jane', 'Doe', 'John', 'Smith', ''),
    'unrelated names do not match'
);
$t->check(
    Azure_Membership_Module::names_conflict('Jane', 'Doe', 'John', 'Smith', ''),
    'Jane Doe vs John Smith is a conflict'
);
$t->check(
    !Azure_Membership_Module::names_conflict('Rebecca', 'Baummer', '', '', ''),
    'missing account name is not a conflict'
);

$rebecca = array(
    'user_id' => 88,
    'email'   => 'rebecca.baummer@outlook.com',
    'first'   => 'Rebecca',
    'last'    => 'Baummer',
    'display' => 'Rebecca Baummer',
);
$other = array(
    'user_id' => 89,
    'email'   => 'other@example.com',
    'first'   => 'Sam',
    'last'    => 'Other',
    'display' => 'Sam Other',
);
$identities = array($rebecca, $other);

$email_and_name = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'rebecca.baummer@outlook.com',
    'first' => 'Rebecca',
    'last'  => 'Baummer',
), $identities);
$t->equals('confident', $email_and_name['status'], 'same email and name is confident');
$t->equals(88, $email_and_name['user_id'], 'email+name attaches to Rebecca');
$t->equals('email_and_name', $email_and_name['reason'], 'reason records both signals');

$stripe_email = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'rjbaummer@gmail.com',
    'first' => 'Rebecca',
    'last'  => 'Baummer',
), $identities);
$t->equals('confident', $stripe_email['status'], 'Stripe email can differ when first+last match one parent');
$t->equals(88, $stripe_email['user_id'], 'name-only unique hit is Rebecca');
$t->equals('name', $stripe_email['reason'], 'reason is name when emails differ');

$email_only = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'rebecca.baummer@outlook.com',
    'first' => '',
    'last'  => '',
), $identities);
$t->equals('confident', $email_only['status'], 'email match with no checkout name is still confident');
$t->equals('email', $email_only['reason'], 'reason is email when name is absent');

$conflict = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'rebecca.baummer@outlook.com',
    'first' => 'John',
    'last'  => 'Smith',
), $identities);
$t->equals('uncertain', $conflict['status'], 'email match with a conflicting last name is not auto-attached');
$t->equals('email_name_conflict', $conflict['reason'], 'conflict reason is recorded');

$divya = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'yd.vineela@gmail.com',
    'first' => 'Divya',
    'last'  => 'Vineela Yarlagadda',
), array(
    array('user_id' => 723, 'email' => 'yd.vineela@gmail.com', 'first' => 'Vineela', 'last' => 'Yarlagadda', 'display' => 'Vineela Yarlagadda'),
));
$t->equals('confident', $divya['status'], 'same email and last name still match when the first name differs');
$t->equals(723, $divya['user_id'], '#33463 attaches to Vineela');
$t->equals('email', $divya['reason'], 'reason is email when first names are not close');

$nobody = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'nobody@example.com',
    'first' => 'Nobody',
    'last'  => 'Here',
), $identities);
$t->equals('none', $nobody['status'], 'unknown guest stays unmatched');
$t->equals(0, $nobody['user_id'], 'unmatched has no user id');

$twins = array(
    $rebecca,
    array(
        'user_id' => 90,
        'email'   => 'becca.work@example.com',
        'first'   => 'Rebecca',
        'last'    => 'Baummer',
        'display' => 'Rebecca Baummer',
    ),
);
$dup = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'guest@example.com',
    'first' => 'Rebecca',
    'last'  => 'Baummer',
), $twins);
$t->equals('uncertain', $dup['status'], 'two parents with the same name are not auto-attached');
$t->equals('multiple_name_matches', $dup['reason'], 'duplicate-name reason is recorded');

$katherine_ids = array(
    array('user_id' => 663, 'email' => 'KatherineR@wilderptsa.net', 'first' => 'Katherine', 'last' => 'Rawstron', 'display' => 'Katherine Rawstron'),
    array('user_id' => 740, 'email' => 'kathrawstron@outlook.com', 'first' => 'Katherine', 'last' => 'Rawstron', 'display' => 'Katherine Rawstron'),
);
$kath = Azure_Membership_Module::match_checkout_party(array(
    'email' => 'katherinemcphee@yahoo.com.au',
    'first' => 'Katherine',
    'last'  => 'Rawstron',
), $katherine_ids);
$t->equals('confident', $kath['status'], '#33529 matches Katherine across her SSO and personal accounts');
$t->equals(740, $kath['user_id'], 'the personal mailbox is preferred for the order link');
$t->equals('name_same_person_accounts', $kath['reason'], 'duplicate Katherine accounts are treated as one person');
$t->equals(1, count($kath['extra_user_ids']), 'the SSO account is kept as an extra membership hit');
$t->equals(663, $kath['extra_user_ids'][0]['user_id'], 'SSO Katherine is the extra user');

$nick = Azure_Membership_Module::match_checkout_party(array(
    'email' => '',
    'first' => 'Nick',
    'last'  => 'Mellors',
), array(
    array('user_id' => 822, 'email' => 'joriemellors@gmail.com', 'first' => 'Jorie', 'last' => 'Mellors', 'display' => 'Jorie Mellors'),
    array('user_id' => 963, 'email' => 'nicholasmellors@gmail.com', 'first' => 'NICHOLAS', 'last' => 'MELLORS', 'display' => 'NICHOLAS MELLORS'),
));
$t->equals('confident', $nick['status'], 'Nick Mellors matches Nicholas Mellors');
$t->equals(963, $nick['user_id'], 'Parent 2 Nick attaches to Nicholas');

$mail = Azure_Membership_Module::build_guest_account_email(array(
    'site_name'     => 'Wilder PTSA',
    'first_name'    => 'Robert',
    'username'      => 'rjbaummer@gmail.com',
    'password'      => 'Sample-Pass-9k2',
    'login_url'     => 'https://wilderptsa.net/my-account/',
    'support_email' => 'info@wilderptsa.net',
    'preview'       => true,
));
$t->check(strpos($mail['subject'], 'PREVIEW') !== false, 'preview subject is marked as a preview');
$t->check(strpos($mail['html'], 'rjbaummer@gmail.com') !== false, 'preview email includes the username');
$t->check(strpos($mail['html'], 'Sample-Pass-9k2') !== false, 'preview email includes the unique password');
$t->check(strpos($mail['html'], 'Save your family profile') !== false, 'preview email lists faster checkout');
$t->check(strpos($mail['html'], 'member pricing') !== false, 'preview email lists store discounts');
$t->check(strpos($mail['html'], 'parent directory') !== false, 'preview email lists the directory');
$t->check(strpos($mail['html'], 'Preview only') !== false, 'preview email says no account was created');

$plan = Azure_Membership_Module::validate_guest_account_candidates(array(
    array('email' => 'rjbaummer@gmail.com', 'first' => 'Robert', 'last' => 'Baummer', 'order_id' => 33491),
    array('email' => 'reinliebm@Gmail.com', 'first' => 'Michelle', 'last' => 'Reinlieb', 'order_id' => 33458),
    array('email' => '', 'first' => 'Alex', 'last' => 'Reinlieb', 'order_id' => 33458),
    array('email' => 'rjbaummer@gmail.com', 'first' => 'Rob', 'last' => 'Baummer', 'order_id' => 9),
), array('janet.vuong01@gmail.com'));
$t->equals(2, count($plan['ready']), 'valid unique emails are ready to create');
$t->equals(1, count($plan['skipped']), 'parent 2 without an email is skipped');
$t->equals('missing_or_invalid_email', $plan['skipped'][0]['reason'], 'skip reason is missing email');
$t->check(!$plan['ok'], 'duplicate email in the batch is an error');
$t->equals('duplicate_email_in_batch', $plan['errors'][0]['reason'], 'duplicate-email reason is recorded');

$already = Azure_Membership_Module::validate_guest_account_candidates(array(
    array('email' => 'janet.vuong01@gmail.com', 'first' => 'Janet', 'last' => 'Vuong'),
), array('janet.vuong01@gmail.com'));
$t->equals(0, count($already['ready']), 'existing WordPress emails are not recreated');
$t->equals('email_already_has_account', $already['skipped'][0]['reason'], 'existing-email reason is recorded');

$passes = Azure_Membership_Module::generate_unique_passwords(6);
$t->equals(6, count($passes), 'six passwords are generated');
$t->equals(6, count(array_unique($passes)), 'generated passwords are unique');

$cred_ok = Azure_Membership_Module::assert_unique_credentials(array(
    array('email' => 'a@x.com', 'password' => 'one-password'),
    array('email' => 'b@x.com', 'password' => 'two-password'),
));
$t->check($cred_ok['ok'] && $cred_ok['unique_emails'] && $cred_ok['unique_passwords'], 'distinct emails and passwords pass uniqueness');

$cred_bad = Azure_Membership_Module::assert_unique_credentials(array(
    array('email' => 'a@x.com', 'password' => 'same-password'),
    array('email' => 'b@x.com', 'password' => 'same-password'),
));
$t->check(!$cred_bad['ok'] && !$cred_bad['unique_passwords'], 'repeated passwords fail uniqueness');

$real_mail = Azure_Membership_Module::build_guest_account_email(array(
    'site_name'     => 'Wilder PTSA',
    'first_name'    => 'Robert',
    'username'      => 'rjbaummer@gmail.com',
    'password'      => 'Live-Pass-1',
    'login_url'     => 'https://wilderptsa.net/my-account/',
    'support_email' => 'info@wilderptsa.net',
    'preview'       => false,
));
$t->check(strpos($real_mail['subject'], 'PREVIEW') === false, 'live subject is not marked preview');
$t->check(strpos($real_mail['html'], 'Preview only') === false, 'live email has no preview banner');

$t->equals('rjbaummer', Azure_Membership_Module::guest_account_user_login('rjbaummer@gmail.com'), 'user_login is derived from the email local part');

class Azure_Test_Guest_Item {
    public $product_id;
    public $name;
    public $meta = array();
    public function __construct($product_id, $name, array $meta = array()) {
        $this->product_id = (int) $product_id;
        $this->name = $name;
        $this->meta = $meta;
    }
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return 0; }
    public function get_name() { return $this->name; }
    public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
}

class Azure_Test_Guest_Order {
    public $id = 33491;
    public $user_id = 0;
    public $first = 'Rebecca';
    public $last = 'Baummer';
    public $email = 'rjbaummer@gmail.com';
    public $paid_at = '2026-08-28 04:06:01';
    public $items = array();
    public $linked_to = 0;
    public function get_id() { return $this->id; }
    public function get_user_id() { return $this->user_id; }
    public function get_items() { return $this->items; }
    public function get_billing_first_name() { return $this->first; }
    public function get_billing_last_name() { return $this->last; }
    public function get_billing_email() { return $this->email; }
    public function get_date_paid() { return $this->paid_at; }
    public function get_date_created() { return $this->paid_at; }
    public function set_customer_id($id) { $this->linked_to = (int) $id; $this->user_id = (int) $id; }
    public function save() { return true; }
}

WP_Shim::reset();
WP_Shim::$settings['membership_family_product_ids'] = array(23233);
WP_Shim::$settings['membership_individual_product_ids'] = array(23231);

$family = new Azure_Test_Guest_Order();
$family->id = 100;
$family->email = 'ada.stripe@gmail.com';
$family->first = 'Ada';
$family->last = 'Lovelace';
$family->items[] = new Azure_Test_Guest_Item(23233, 'PTSA Family Membership', array(
    '_pta_parent_2_name' => 'William King',
    '_pta_parent_2_email' => 'william@example.com',
));

$parties = Azure_Membership_Module::guest_order_parties($family, 'family');
$t->equals(2, count($parties), 'family guest order yields parent 1 and parent 2');
$t->equals('parent_1', $parties[0]['slot'], 'first party is parent 1');
$t->equals('ada.stripe@gmail.com', $parties[0]['email'], 'parent 1 uses billing/stripe email');
$t->equals('parent_2', $parties[1]['slot'], 'second party is parent 2');
$t->equals('william@example.com', $parties[1]['email'], 'parent 2 uses checkout field email');
$t->equals('William', $parties[1]['first'], 'parent 2 first name is split from the checkout name');

$family_ids = array(
    array('user_id' => 11, 'email' => 'ada@home.com', 'first' => 'Ada', 'last' => 'Lovelace', 'display' => 'Ada Lovelace'),
    array('user_id' => 12, 'email' => 'william@example.com', 'first' => 'William', 'last' => 'King', 'display' => 'William King'),
);
$review = Azure_Membership_Module::review_one_guest_order($family, 'family', $family_ids);
$t->equals(2, count($review['matched']), 'family guest matches both parents');
$t->equals(0, count($review['unmatched']), 'family guest has no unmatched adult');
$matched_ids = array();
foreach ($review['matched'] as $hit) {
    $matched_ids[] = (int) $hit['user_id'];
}
sort($matched_ids);
$t->equals(array(11, 12), $matched_ids, 'Ada (name, different email) and William (email) both attach');

$orphan = new Azure_Test_Guest_Order();
$orphan->id = 33491;
$orphan->items[] = new Azure_Test_Guest_Item(23233, 'PTSA Family Membership');
$orphan_review = Azure_Membership_Module::review_one_guest_order($orphan, 'family', $family_ids);
$t->equals(0, count($orphan_review['matched']), 'unknown family guest is not attached');
$t->equals(1, count($orphan_review['unmatched']), 'unknown family guest is listed for account creation');
$t->equals('rjbaummer@gmail.com', $orphan_review['unmatched'][0]['email'], 'unmatched row keeps the checkout email');
$t->equals('Rebecca Baummer', $orphan_review['unmatched'][0]['name'], 'unmatched row keeps the checkout name');

$t->check(
    Azure_Membership_Module::maybe_link_guest_order($family, 11),
    'confident parent 1 can be written onto the guest order'
);
$t->equals(11, $family->linked_to, 'guest order customer_id becomes parent 1');
$t->check(
    !Azure_Membership_Module::maybe_link_guest_order($family, 12),
    'already-linked order is not rewritten'
);

$staff = new Azure_Test_Guest_Order();
$staff->id = 9;
$staff->email = 'pat@wilderptsa.net';
$staff->first = 'Pat';
$staff->last = 'Teacher';
$staff->items[] = new Azure_Test_Guest_Item(32959, 'PTSA Membership - Wilder Staff');
$all = Azure_Membership_Module::review_guest_memberships(array($family, $orphan, $staff), $family_ids);
$t->check(count($all['unmatched']) >= 1, 'review lists unmatched guests');
$staff_seen = false;
foreach (array_merge($all['matched'], $all['unmatched'], $all['uncertain']) as $row) {
    if ($row['type'] === 'staff') {
        $staff_seen = true;
    }
}
$t->check(!$staff_seen, 'staff guest orders are ignored');

exit($t->finish() === 0 ? 0 : 1);
