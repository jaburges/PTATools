<?php
/**
 * show-PTSA-memberships hides the member pill on a roles view.
 *
 * Run: php tests/test-pta-membership-attr.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $cb) { return true; }
}

class Azure_Membership_Module {
    public static function member_badge_html($user_id, $variant = 'pill') {
        return '<span class="pta-member-badge">PTSA Member</span>';
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-pta-shortcode.php';

$t = new TestRunner('PTSA membership shortcode attribute');

$sc = new Azure_PTA_Shortcode();
$method = new ReflectionMethod($sc, 'membership_badge');

$badge = '<span class="pta-member-badge">PTSA Member</span>';
$t->equals($badge, $method->invoke($sc, 1, array()), 'memberships show when the attribute is omitted');
$t->equals($badge, $method->invoke($sc, 1, array('show-ptsa-memberships' => 'true')), 'show-PTSA-memberships true keeps the pill');
$t->equals('', $method->invoke($sc, 1, array('show-ptsa-memberships' => 'false')), 'show-PTSA-memberships false hides the pill');
$t->equals('', $method->invoke($sc, 1, array('show-PTSA-memberships' => 'false')), 'the attribute is recognized in the written casing');

exit($t->finish() === 0 ? 0 : 1);
