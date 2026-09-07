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
$t->check(strpos($css, 'padding-left: 0') === false, 'stack CSS does not zero horizontal padding');
$t->check(strpos($css, 'padding-right: 0') === false, 'stack CSS does not zero horizontal padding (right)');

$gap_css = Azure_Newsletter_Email_Css::column_gap_css();
$t->check(strpos($gap_css, 'padding: 10px') !== false, 'gap CSS uses the designer 10px gutter');
$t->check(strpos($gap_css, 'max-width: 100%') !== false, 'gap CSS keeps column images inside the cell');

$merged = Azure_Newsletter_Email_Css::merge_inline_style('padding: 10px; width: 50%', 'padding: 0; margin: 0');
$t->check(strpos($merged, 'padding: 10px') !== false, 'inliner keeps designer padding');
$t->check(strpos($merged, 'padding: 0') === false, 'inliner does not append padding: 0 over existing padding');
$t->check(strpos($merged, 'margin: 0') !== false, 'inliner still adds new non-padding properties');
$t->check(strpos($merged, 'width: 50%') !== false, 'inliner keeps existing width');

$flush = '<table class="nl-stack-cols"><tr><td class="nl-column" width="50%" style="width: 50%;">Img</td><td class="nl-column" width="50%">Text</td></tr></table>';
$padded = Azure_Newsletter_Email_Css::ensure_column_cell_padding($flush);
$t->check(substr_count($padded, 'padding: 10px') >= 2, 'bare column cells get a 10px gutter before send');

$kept = Azure_Newsletter_Email_Css::ensure_column_cell_padding(
    '<td class="nl-column" style="padding: 16px; width: 50%;">x</td>'
);
$t->check(strpos($kept, 'padding: 16px') !== false, 'custom column padding is left alone');
$t->check(strpos($kept, 'padding: 10px') === false, 'custom column padding is not replaced with 10px');

$reset = '<html><head><style type="text/css">td { padding: 0; margin: 0; }</style></head><body>'
    . '<table class="nl-stack-cols"><tr><td class="nl-column" style="padding: 10px; width: 50%;">Left</td></tr></table>'
    . '</body></html>';
$inlined = Azure_Newsletter_Email_Css::inline_keeping_media($reset);
$t->check(preg_match('/<td[^>]*style="[^"]*padding:\s*10px/i', $inlined), 'full inliner keeps 10px column gutter over td { padding: 0 }');
$t->check(!preg_match('/<td[^>]*style="[^"]*padding:\s*0/i', $inlined), 'full inliner does not leave a trailing padding: 0 on the column');

$linked = Azure_Newsletter_Email_Css::wrap_image_hrefs(
    '<img src="photo.jpg" href="https://example.com/page" alt="Photo" width="100%">'
);
$t->check(strpos($linked, '<a href="https://example.com/page"') !== false, 'image href becomes a wrapping <a>');
$t->check(preg_match('/<a[^>]*>\s*<img[^>]+>\s*<\/a>/', $linked), 'the <img> is inside the new <a>');
$t->check(strpos($linked, '<img href=') === false, 'href is removed from the <img> after wrap');

$plain = Azure_Newsletter_Email_Css::wrap_image_hrefs('<img src="photo.jpg" alt="Photo">');
$t->equals($plain, '<img src="photo.jpg" alt="Photo">', 'images without href are unchanged');

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

$divider_css = Azure_Newsletter_Email_Css::divider_css();
$t->check(strpos($divider_css, 'nl-divider') !== false, 'divider CSS targets the divider block');
$t->check(strpos($divider_css, 'border-top: 2px solid #dddddd') !== false, 'saved <hr> dividers get a visible rule');
$t->check(strpos($divider_css, 'nl-divider-rule') !== false, 'divider CSS paints the 2px bgcolor row');

$with_div = Azure_Newsletter_Email_Css::ensure_column_stack_style('<table class="nl-divider"><tr><td><hr></td></tr></table>');
$t->check(strpos($with_div, Azure_Newsletter_Email_Css::DIVIDER_MARKER) !== false, 'send path injects divider CSS');

exit($t->finish() === 0 ? 0 : 1);
