<?php
/**
 * PTSA Member badge markup for profiles, avatars, and the directory.
 *
 * Run: php tests/test-membership-badge.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('PTSA Member badge');

$t->equals('PTSA Member', Azure_Membership_Module::member_badge_label(), 'badge label is PTSA Member');

$pill = Azure_Membership_Module::render_member_badge('pill');
$t->check(strpos($pill, 'PTSA Member') !== false, 'pill shows the full label');
$t->check(strpos($pill, 'pta-member-badge--pill') !== false, 'pill uses the pill class');

$mark = Azure_Membership_Module::render_member_badge('avatar');
$t->check(strpos($mark, 'PTSA Member') !== false, 'avatar mark keeps the full label for screen readers');
$t->check(strpos($mark, '>PTSA<') !== false, 'avatar mark uses a short visible label');
$t->check(strpos($mark, 'pta-member-badge--mark') !== false, 'avatar mark uses the mark class');

$t->equals('', Azure_Membership_Module::member_badge_html(0), 'no badge without a user');
$t->equals('<img>', Azure_Membership_Module::decorate_avatar_html('<img>', false), 'non-members keep a plain avatar');

$wrapped = Azure_Membership_Module::decorate_avatar_html('<img class="avatar">', true);
$t->check(strpos($wrapped, 'pta-member-avatar') !== false, 'member avatars are wrapped');
$t->check(strpos($wrapped, 'pta-member-badge--mark') !== false, 'member avatars get the mark');
$t->check(strpos($wrapped, '<img class="avatar">') !== false, 'the original avatar html is kept');

$t->equals(12, Azure_Membership_Module::user_id_from_avatar_id(12), 'numeric avatar id is a user id');
$t->equals(0, Azure_Membership_Module::user_id_from_avatar_id(''), 'blank avatar id is not a user');

$comment = (object) array('user_id' => 44, 'comment_ID' => 9);
$t->equals(44, Azure_Membership_Module::user_id_from_avatar_id($comment), 'comment avatars resolve to the author');

$user = (object) array('ID' => 18);
$t->equals(18, Azure_Membership_Module::user_id_from_avatar_id($user), 'WP_User-like objects resolve to ID');

exit($t->finish() === 0 ? 0 : 1);
