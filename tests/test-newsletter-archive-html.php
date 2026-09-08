<?php
/**
 * Newsletter archive pages: strip leftover CTA bars and empty dividers.
 *
 * Run: php tests/test-newsletter-archive-html.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-page.php';

$t = new TestRunner('Newsletter archive HTML');

$t->check(
    Azure_Newsletter_Page::content_looks_like_email('<table class="nl-divider"></table>'),
    'nl-divider marks a newsletter page'
);
$t->check(
    !Azure_Newsletter_Page::content_looks_like_email('<p>Hello families</p>'),
    'plain copy is not treated as a newsletter'
);

$html = '<p>Intro</p>'
    . '<table cellpadding="0" cellspacing="0" border="0" align="center" class="c119594">'
    . '<tbody><tr><td align="center" bgcolor="#2271b1" class="c119603"></td></tr></tbody></table>'
    . '<table class="nl-button c95981"><tbody><tr><td align="center" bgcolor="#2271b1"></td></tr></tbody></table>'
    . '<a href="https://wilderptsa.net/theater/" class="c2328"></a>'
    . '<table width="100%" class="nl-divider"><tbody><tr></tr></tbody></table>'
    . '<table width="100%" class="nl-divider"><tbody><tr><td><hr class="c1" /></td></tr></tbody></table>'
    . '<p><a href="{{view_in_browser_url}}">View in browser</a> • <a href="{{unsubscribe_url}}">Unsubscribe</a></p>'
    . '<table class="real"><tbody><tr><td align="center" bgcolor="#2271b1"><a href="/carnival/">Carnival Page</a></td></tr></tbody></table>';

$clean = Azure_Newsletter_Page::clean_archive_html($html);
$t->check(strpos($clean, 'c119603') === false, 'empty leftover CTA table is removed');
$t->check(strpos($clean, 'nl-button') === false, 'empty nl-button table is removed');
$t->check(strpos($clean, 'c2328') === false, 'empty image/link leftover is removed');
$t->check(substr_count($clean, 'nl-divider') === 1, 'empty divider tables are dropped, real ones stay');
$t->check(strpos($clean, 'view_in_browser_url') === false, 'view-in-browser merge tag is not shown on the site');
$t->check(strpos($clean, 'unsubscribe_url') === false, 'unsubscribe merge tag is not shown on the site');
$t->check(strpos($clean, 'Carnival Page') !== false, 'real CTA text is kept');

$css = file_get_contents(dirname(__DIR__) . '/Azure Plugin/css/newsletter-page.css');
$t->check(strpos($css, 'border: 0 !important') !== false, 'page CSS zeros theme table borders');
$t->check(strpos($css, 'background-color: transparent') !== false, 'page CSS clears theme hr fill');

exit($t->finish() === 0 ? 0 : 1);
