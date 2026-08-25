<?php
/**
 * Newsletter archive page title tokens.
 *
 * Run: php tests/test-newsletter-archive-page.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-module.php';

$t = new TestRunner('Newsletter archive page title');

$data = array(
    'subject' => 'Welcome to the Wilder PTSA Newsletter',
    'name'    => 'Welcome Newsletter',
);
$ts = strtotime('2026-09-15 12:00:00 UTC');

$t->equals(
    'Welcome to the Wilder PTSA Newsletter',
    Azure_Newsletter_Module::resolve_archive_page_title('{subject}', $data, $ts),
    'subject token'
);
$t->equals(
    'Welcome Newsletter',
    Azure_Newsletter_Module::resolve_archive_page_title('{name}', $data, $ts),
    'campaign name token'
);
$t->equals(
    'September 2026',
    Azure_Newsletter_Module::resolve_archive_page_title('{month_year}', $data, $ts),
    'month year token'
);
$t->equals(
    'September',
    Azure_Newsletter_Module::resolve_archive_page_title('{month}', $data, $ts),
    'month token'
);
$t->equals(
    '2026',
    Azure_Newsletter_Module::resolve_archive_page_title('{year}', $data, $ts),
    'year token'
);
$t->equals(
    'September 15, 2026',
    Azure_Newsletter_Module::resolve_archive_page_title('{date}', $data, $ts),
    'date token'
);
$t->equals(
    'Welcome to the Wilder PTSA Newsletter',
    Azure_Newsletter_Module::resolve_archive_page_title('', $data, $ts),
    'empty template falls back to subject'
);
$t->equals(
    'Campaign',
    Azure_Newsletter_Module::resolve_archive_page_title('', array('name' => 'Campaign'), $ts),
    'empty template falls back to name'
);
$t->equals(
    'Newsletter',
    Azure_Newsletter_Module::resolve_archive_page_title('', array(), $ts),
    'empty everything falls back to Newsletter'
);
$t->equals(
    'Welcome Newsletter — September 2026',
    Azure_Newsletter_Module::resolve_archive_page_title('{name} — {month_year}', $data, $ts),
    'combined tokens'
);
$t->equals(
    'September 26',
    Azure_Newsletter_Module::resolve_archive_page_title('September 26', $data, $ts),
    'manual title is left as typed'
);

exit($t->finish() === 0 ? 0 : 1);
