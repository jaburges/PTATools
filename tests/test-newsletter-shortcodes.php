<?php
/**
 * Newsletter send-time shortcode expansion.
 *
 * Run: php tests/test-newsletter-shortcodes.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

$GLOBALS['test_shortcodes'] = array();

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        $GLOBALS['test_shortcodes'][$tag] = $callback;
    }
}
if (!function_exists('shortcode_exists')) {
    function shortcode_exists($tag) {
        return isset($GLOBALS['test_shortcodes'][$tag]);
    }
}
if (!function_exists('do_shortcode')) {
    function do_shortcode($content) {
        return preg_replace_callback(
            '/\[([a-zA-Z][a-zA-Z0-9_-]+)(\s[^\]]*)?\]/',
            function ($m) {
                $tag = $m[1];
                if (!isset($GLOBALS['test_shortcodes'][$tag])) {
                    return $m[0];
                }
                return call_user_func($GLOBALS['test_shortcodes'][$tag], array(), null, $tag);
            },
            $content
        );
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-shortcodes.php';

$t = new TestRunner('Newsletter shortcode expansion');

add_shortcode('up-next', function () {
    return '<div class="upcoming-events">Science Fair Friday</div>';
});
add_shortcode('nl-now-next', function () {
    return '<table class="nl-now-next"><tr><td>This Week</td><td>Next Week</td></tr></table>';
});
add_shortcode('pta-roles-directory', function () {
    return '<div class="pta-roles-directory">President: Jane</div>';
});

$placeholder = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
    . '<tr><td style="padding: 20px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">'
    . '<p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">[up-next]</p>'
    . '<p style="margin: 10px 0 0; font-size: 12px; color: #666;">Shortcode will be rendered when email is sent</p>'
    . '</td></tr></table>';

$out = Azure_Newsletter_Shortcodes::expand($placeholder);
$t->check(strpos($out, 'Science Fair Friday') !== false, 'placeholder shortcode is rendered');
$t->check(strpos($out, 'Shortcode will be rendered when email is sent') === false, 'editor helper copy is not sent');
$t->check(strpos($out, '2px dashed') === false, 'dashed designer chrome is not sent');
$t->check(strpos($out, '[up-next]') === false, 'raw [up-next] token is gone');

$unused = str_replace('[up-next]', '[your_shortcode]', $placeholder);
$t->equals($unused, Azure_Newsletter_Shortcodes::expand($unused), 'default [your_shortcode] placeholder is left alone');

$unknown = str_replace('[up-next]', '[not_a_real_tag]', $placeholder);
$t->equals($unknown, Azure_Newsletter_Shortcodes::expand($unknown), 'unregistered shortcode placeholder is left alone');

$nested = '<table class="nl-stack-cols"><tr><td class="nl-column">' . $placeholder . '</td></tr></table>';
$nested_out = Azure_Newsletter_Shortcodes::expand($nested);
$t->check(strpos($nested_out, 'nl-stack-cols') !== false, 'parent column table survives expansion');
$t->check(strpos($nested_out, 'Science Fair Friday') !== false, 'shortcode inside a column still renders');
$t->check(strpos($nested_out, 'Shortcode will be rendered when email is sent') === false, 'helper copy is stripped inside a column');

$text = '<p>See this week: [up-next] thanks</p>';
$text_out = Azure_Newsletter_Shortcodes::expand($text);
$t->equals(
    '<p>See this week: <div class="upcoming-events">Science Fair Friday</div> thanks</p>',
    $text_out,
    'shortcode typed into a text block is expanded'
);

$directory = '<table><tr><td style="border: 2px dashed #2271b1;">'
    . '<p>[pta-roles-directory columns="2"]</p>'
    . '<p>Full PTA directory - all departments and roles</p>'
    . '</td></tr></table>';
$dir_out = Azure_Newsletter_Shortcodes::expand($directory);
$t->check(strpos($dir_out, 'President: Jane') !== false, 'PTA directory placeholder is rendered');
$t->check(strpos($dir_out, 'Full PTA directory') === false, 'PTA directory helper copy is not sent');

$plain = '<p>No tokens here</p>';
$t->equals($plain, Azure_Newsletter_Shortcodes::expand($plain), 'HTML without shortcodes is unchanged');
$t->equals('', Azure_Newsletter_Shortcodes::expand(''), 'empty HTML is unchanged');

$doc = '<!DOCTYPE html><html><body>' . $placeholder . '</body></html>';
$doc_out = Azure_Newsletter_Shortcodes::expand($doc);
$t->check(strpos($doc_out, '<!DOCTYPE html>') !== false, 'full email document structure is kept');
$t->check(strpos($doc_out, 'Science Fair Friday') !== false, 'shortcode inside a full document is rendered');

$encoded = '<p>[up-next columns=&quot;2&quot;]</p>';
$enc_out = Azure_Newsletter_Shortcodes::expand($encoded);
$t->check(strpos($enc_out, 'Science Fair Friday') !== false, 'HTML-encoded shortcode attributes still expand');

$now_next = '<table class="nl-now-next" width="100%" cellpadding="0" cellspacing="0" border="0">'
    . '<tr><td style="padding: 16px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">'
    . '<p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">[nl-now-next]</p>'
    . '<p style="margin: 10px 0 0; font-size: 12px; color: #666;">This Week and Next Week events — compact 2-column list</p>'
    . '</td></tr></table>';
$nn_out = Azure_Newsletter_Shortcodes::expand($now_next);
$t->check(strpos($nn_out, 'This Week') !== false && strpos($nn_out, 'Next Week') !== false, 'Now and Next placeholder expands');
$t->check(strpos($nn_out, '[nl-now-next]') === false, 'raw [nl-now-next] token is gone');
$t->check(strpos($nn_out, '2px dashed') === false, 'Now and Next designer chrome is not sent');

exit($t->finish() === 0 ? 0 : 1);
