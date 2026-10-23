<?php
/**
 * Regression guard for the PTA Users tab XSS.
 *
 * The People list in admin/pta-page.php is built by concatenating AJAX data
 * into markup. Display names come from the public newsletter signup and
 * registration forms, and WordPress keeps double quotes in them, so a name like
 *   x" onmouseover="alert(1)
 * broke out of data-user-name="..." and ran script in an administrator's
 * session. These checks assert against the shipped source so the raw
 * concatenation cannot quietly come back.
 *
 * Run: php tests/test-pta-users-tab-escaping.php
 */

require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('PTA Users tab escaping');

$path = dirname(__DIR__) . '/Azure Plugin/admin/pta-page.php';
$src = file_get_contents($path);
if ($src === false) {
    throw new RuntimeException('Expected plugin file is missing: admin/pta-page.php');
}

$t->check(
    preg_match('/function ptaEscHtml\(value\)/', $src) === 1,
    'the page defines a client-side esc_html helper'
);
$t->check(
    strpos($src, ".replace(/\"/g, '&quot;')") !== false && strpos($src, ".replace(/'/g, '&#039;')") !== false,
    'the helper escapes both quote characters, which is what the attribute breakout needs'
);

$raw_sinks = array(
    "data-user-name=\"' + user.display_name"   => 'display name in data-user-name',
    "'<td><strong>' + user.display_name"       => 'display name in the Name cell',
    "'<small>' + user.user_login"              => 'login in the Name cell',
    "'<td>' + user.user_email"                 => 'email cell',
    "data-photo-url=\"' + (user.photo_url"     => 'photo URL attribute',
    "<strong>' + userName + '</strong>"        => 'name in the unassign modal',
    "User Details: ' + userName"               => 'name in the details modal heading',
    "<strong>Email:</strong> ' + userEmail"    => 'email in the details modal',
    "data-role-name=\"' + assignment.role_name" => 'role name attribute in the unassign modal',
    "'<tr><td>' + assignment.role_name"        => 'role name in the details modal',
    "'>' + role.name + statusText"             => 'role name in the bulk role select',
);
foreach ($raw_sinks as $needle => $label) {
    $t->check(strpos($src, $needle) === false, "no raw concatenation: {$label}");
}

$escaped = array(
    'var userName = ptaEscAttr(user.display_name);',
    "'<td><strong>' + ptaEscHtml(user.display_name)",
    "'<td>' + ptaEscHtml(user.user_email)",
    "data-photo-url=\"' + ptaEscUrl(user.photo_url)",
    "<strong>' + ptaEscHtml(userName) + '</strong>",
);
foreach ($escaped as $needle) {
    $t->check(strpos($src, $needle) !== false, 'escaped sink present: ' . $needle);
}

exit($t->finish() > 0 ? 1 : 0);
