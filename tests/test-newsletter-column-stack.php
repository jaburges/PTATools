<?php
/**
 * Newsletter 2/3-column blocks stack on narrow viewports.
 *
 * Run: php tests/test-newsletter-column-stack.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-email-css.php';

$t = new TestRunner('Newsletter column stack CSS');

$css = Azure_Newsletter_Email_Css::column_stack_css();
$t->check(strpos($css, '@media') !== false, 'stack CSS is a media query');
$t->check(strpos($css, 'display: block') !== false, 'stack CSS forces block cells');
$t->check(strpos($css, 'width: 100%') !== false, 'stack CSS makes cells full width');

$html = '<html><head><style type="text/css">p { color: red; } @media only screen and (max-width: 600px) { td { display: block !important; } }</style></head><body><p class="intro">Hi</p></body></html>';
$out = Azure_Newsletter_Email_Css::inline_keeping_media($html);
$t->check(strpos($out, '@media only screen and (max-width: 600px)') !== false, 'inlining keeps the mobile media query');
$t->check(preg_match('/<p[^>]*style="[^"]*color:\s*red/i', $out), 'inlining still applies regular rules');

$bare = '<html><head></head><body><table><tr><td width="50%">Left</td><td width="50%">Right</td></tr></table></body></html>';
$ensured = Azure_Newsletter_Email_Css::ensure_column_stack_style($bare);
$t->check(strpos($ensured, '/* pta-nl-stack-cols */') !== false, 'missing stack CSS is injected');
$t->equals(
    $ensured,
    Azure_Newsletter_Email_Css::ensure_column_stack_style($ensured),
    'injecting twice does not duplicate the stack CSS'
);

exit($t->finish() === 0 ? 0 : 1);
