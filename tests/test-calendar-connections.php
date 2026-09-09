<?php
/**
 * Multi-mailbox calendar connection helpers.
 *
 * Run: php tests/test-calendar-connections.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        $email = strtolower(trim((string) $email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
if (!function_exists('is_email')) {
    function is_email($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-connections.php';

$t = new TestRunner('Calendar connections');

$t->equals(
    array('calendar@wilderptsa.net', 'math@wilderptsa.net'),
    Azure_Calendar_Connections::normalize_mailboxes("calendar@wilderptsa.net\nMATH@wilderptsa.net"),
    'normalizes and de-dupes mailbox list'
);

$t->equals(
    array('calendar@wilderptsa.net'),
    Azure_Calendar_Connections::normalize_mailboxes(array('calendar@wilderptsa.net', 'not-an-email', '')),
    'drops invalid addresses'
);

$t->equals(
    'math@wilderptsa.net::abc123',
    Azure_Calendar_Connections::embed_key('MATH@wilderptsa.net', 'abc123'),
    'embed key is mailbox-scoped'
);

$parsed = Azure_Calendar_Connections::parse_embed_key('math@wilderptsa.net::abc::def');
$t->equals('math@wilderptsa.net', $parsed['mailbox'], 'parse mailbox before first ::');
$t->equals('abc::def', $parsed['calendar_id'], 'calendar id may contain ::');

$legacy = array('old-cal-id');
$t->equals(true, Azure_Calendar_Connections::is_embed_enabled($legacy, 'calendar@wilderptsa.net', 'old-cal-id'), 'legacy bare calendar id still counts as enabled');

$scoped = Azure_Calendar_Connections::set_embed_enabled(array(), 'math@wilderptsa.net', 'new-id', true);
$t->equals(true, Azure_Calendar_Connections::is_embed_enabled($scoped, 'math@wilderptsa.net', 'new-id'), 'scoped enable works');
$t->equals(false, Azure_Calendar_Connections::is_embed_enabled($scoped, 'calendar@wilderptsa.net', 'new-id'), 'same calendar id on another mailbox is not enabled by scoped key alone when only scoped key stored');

$disabled = Azure_Calendar_Connections::set_embed_enabled($legacy, 'calendar@wilderptsa.net', 'old-cal-id', false);
$t->equals(false, Azure_Calendar_Connections::is_embed_enabled($disabled, 'calendar@wilderptsa.net', 'old-cal-id'), 'disable removes legacy id');

exit($t->finish() === 0 ? 0 : 1);
