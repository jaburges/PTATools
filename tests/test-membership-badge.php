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
$t->check(strpos($mark, 'pta-member-badge--star') !== false, 'avatar badge is the star');
$t->check(strpos($mark, 'title="PTSA member"') !== false, 'the star has a PTSA member tooltip');
$t->check(strpos($mark, 'aria-label="PTSA member"') !== false && strpos($mark, 'role="img"') !== false, 'and reads as PTSA member to screen readers');
$t->check(strpos($mark, '<circle') !== false && strpos($mark, 'fill="#1d4ed8"') !== false, 'a blue circle');
$t->check(strpos($mark, '<path fill="#ffffff"') !== false, 'with a white star');
$t->check(strpos($mark, '>PTSA<') === false && strpos($mark, 'PTSA Member<') === false, 'no text on the avatar');

$t->equals('', Azure_Membership_Module::member_badge_html(0), 'no badge without a user');
$t->equals('<img>', Azure_Membership_Module::decorate_avatar_html('<img>', false), 'non-members keep a plain avatar');

$wrapped = Azure_Membership_Module::decorate_avatar_html('<img class="avatar">', true);
$t->check(strpos($wrapped, 'pta-member-avatar') !== false, 'member avatars are wrapped');
$t->check(strpos($wrapped, 'pta-member-badge--star') !== false, 'member avatars get the star');
$t->check(strpos($wrapped, '<img class="avatar">') !== false, 'the original avatar html is kept');

$t->equals(12, Azure_Membership_Module::user_id_from_avatar_id(12), 'numeric avatar id is a user id');
$t->equals(0, Azure_Membership_Module::user_id_from_avatar_id(''), 'blank avatar id is not a user');

$comment = (object) array('user_id' => 44, 'comment_ID' => 9);
$t->equals(44, Azure_Membership_Module::user_id_from_avatar_id($comment), 'comment avatars resolve to the author');

$user = (object) array('ID' => 18);
$t->equals(18, Azure_Membership_Module::user_id_from_avatar_id($user), 'WP_User-like objects resolve to ID');

// #28: the header shows the star on the avatar, never the text pill.
$um = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-user-management-module.php');
$t->check(strpos($um, "member_badge_html((int) \$user->ID, 'pill')") === false, '[pta_user_dropdown] has no pill in the header');
$t->check(strpos($um, "(\$avatar_html === '' && class_exists('Azure_Membership_Module'))") !== false, 'without an avatar the star sits by the name');
$ua = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-user-account-shortcode.php');
$t->check(strpos($ua, "'pill'") === false, '[user-account-dropdown] has no pill in the header');
$t->check(strpos($ua, 'wrap_member_avatar($avatar_img') !== false, 'and wraps the avatar with the star');
$t->check(strpos($ua, "+ '<span class=\"user-account-name\">' + d.display_name") === false, 'the cached-page swap escapes the name');
$css = file_get_contents(dirname(__DIR__) . '/Azure Plugin/css/membership-badge.css');
$t->check(strpos($css, '.pta-member-avatar .pta-member-badge--star') !== false && strpos($css, 'right: -2px') !== false && strpos($css, 'bottom: -2px') !== false, 'the star sits on the avatar corner');
$t->check(strpos($css, 'pta-member-badge--mark') === false, 'the old text mark styles are gone');
foreach (array('pta-user-dropdown.css' => '.pta-user-dropdown__name', 'user-account-dropdown.css' => '.user-account-name') as $file => $sel) {
    $c = file_get_contents(dirname(__DIR__) . '/Azure Plugin/css/' . $file);
    $t->check(preg_match('/' . preg_quote($sel, '/') . '\s*\{[^}]*white-space:\s*nowrap[^}]*text-overflow:\s*ellipsis|' . preg_quote($sel, '/') . '\s*\{[^}]*text-overflow:\s*ellipsis[^}]*white-space:\s*nowrap/s', $c) === 1, $file . ': the name never wraps');
    $t->check(strpos($c, '@media (max-width: 600px)') !== false, $file . ': has a phone layout');
}

exit($t->finish() === 0 ? 0 : 1);
