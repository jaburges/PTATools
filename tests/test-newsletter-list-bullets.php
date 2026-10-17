<?php
/**
 * #17: bullets and numbers in a newsletter survive the send path.
 *
 * The send/test-send path used to inject `li { display: block; }`, which
 * removes the list marker in every client that reads <style> (Gmail
 * included), while the editor canvas, which never gets that CSS, still
 * showed the bullets.
 *
 * Run: php tests/test-newsletter-list-bullets.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-email-css.php';

$t = new TestRunner('Newsletter list bullets');
$css = 'Azure_Newsletter_Email_Css';

$block = $css::outlook_block_css();
$t->check(!preg_match('/(^|[\s,}])li\s*[,{][^}]*display:\s*block/i', $block), 'the Outlook block-flow CSS no longer makes <li> display: block');
$t->check(strpos($block, 'li { display: list-item;') !== false, '<li> stays a list item in <style>');
$t->check(strpos($block, 'ul { list-style-type: disc; }') !== false, 'bulleted lists get a disc marker');
$t->check(strpos($block, 'ol { list-style-type: decimal; }') !== false, 'numbered lists get numbers');

// The WAG final-hours email: a GrapesJS text cell with a plain bulleted list.
$draft = '<!DOCTYPE html><html><head><style type="text/css">body { margin: 0 !important; padding: 0 !important; }'
    . '#i3kd{padding:10px;font-family:Arial, sans-serif;font-size:14px;line-height:1.5;color:#333333;}</style></head>'
    . '<body style="margin:0;padding:0;"><table width="600"><tr><td id="i3kd">'
    . '<p>Final hours to give:</p>'
    . '<ul><li>Every gift counts</li><li>Matching ends at midnight</li></ul>'
    . '<ol><li>Pick a level</li><li>Check out</li></ol>'
    . '</td></tr></table></body></html>';
$sent = $css::ensure_column_stack_style($draft);

$t->check(substr_count($sent, '<li') === 4, 'every list item is still there');
$t->check(preg_match('/<ul\b[^>]*style="[^"]*list-style-type: disc/', $sent) === 1, 'the <ul> carries an inline disc marker for clients that strip <style>');
$t->check(preg_match('/<ol\b[^>]*style="[^"]*list-style-type: decimal/', $sent) === 1, 'the <ol> carries inline numbers');
$t->check(preg_match('/<ul\b[^>]*style="[^"]*padding-left: 24px/', $sent) === 1, 'the list is indented so the marker has room');
$t->equals(4, preg_match_all('/<li\b[^>]*style="[^"]*display: list-item/', $sent), 'each <li> is inline display: list-item');
$t->check(strpos($sent, 'li { display: block; }') === false, 'the sent HTML has no li { display: block } rule');
$t->check(strpos($sent, 'Every gift counts') !== false && strpos($sent, 'Matching ends at midnight') !== false, 'the list text is unchanged');

$again = $css::ensure_column_stack_style($sent);
$t->equals($sent, $again, 'running the send path twice changes nothing more');

// HTML saved by an older release already contains the bad rule.
$old_marker = Azure_Newsletter_Email_Css::OUTLOOK_LH_MARKER;
$legacy = '<html><head><style type="text/css">' . $old_marker . ' p, h1, h2, h3, h4, h5, h6, li { display: block; } div { display: block; }</style></head>'
    . '<body><ul><li>One</li></ul></body></html>';
$fixed = $css::ensure_column_stack_style($legacy);
$t->check(strpos($fixed, 'li { display: block; }') === false, 'the old rule in saved HTML is rewritten');
$t->check(strpos($fixed, Azure_Newsletter_Email_Css::LIST_MARKER) !== false, 'and the list CSS is added');

// Designer choices are kept unless they hide the marker.
$styled = $css::ensure_list_styles('<ul style="list-style-type: square; padding-left: 30px; color: #c00"><li style="color: #c00">A</li></ul>');
$t->check(strpos($styled, 'list-style-type: square') !== false, 'a square marker the designer chose is kept');
$t->check(strpos($styled, 'padding-left: 30px') !== false, 'the designer indent is kept');
$t->check(strpos($styled, 'padding-left: 24px') === false, 'no second indent is added');
$t->check(strpos($styled, 'color: #c00') !== false, 'other styles are kept');

$hidden = $css::ensure_list_styles('<ul style="list-style: none; padding: 0; margin: 0"><li style="display: block">A</li></ul>');
$t->check(strpos($hidden, 'list-style: none') === false, 'list-style: none from a reset is removed');
$t->check(strpos($hidden, 'list-style-type: disc') !== false, 'and the disc marker restored');
$t->check(strpos($hidden, 'padding: 0') === false && strpos($hidden, 'padding-left: 24px') !== false, 'a zero list padding is replaced with an indent');
$t->check(strpos($hidden, 'margin: 0') !== false, 'the designer margin is kept');
$t->check(strpos($hidden, 'display: block') === false && strpos($hidden, 'display: list-item') !== false, 'an <li> forced to display: block becomes a list item again');

$quoted = $css::ensure_list_styles("<ul class='x' style='color: red'><li>A</li></ul>");
$t->check(strpos($quoted, "class='x'") !== false && substr_count($quoted, 'style=') === 2, 'single-quoted attributes are rewritten, not duplicated');

$t->equals('<p>No lists</p>', $css::ensure_list_styles('<p>No lists</p>'), 'HTML without lists is untouched');
$t->equals('<link rel="x"><ul-ish>', $css::ensure_list_styles('<link rel="x"><ul-ish>'), 'tags that only start with ul/li are untouched');

// Both send paths run the helper.
$queue = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php');
$ajax = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-newsletter-ajax.php');
$t->check(strpos($queue, 'Azure_Newsletter_Email_Css::ensure_column_stack_style') !== false, 'campaign sends run the list fix');
$t->check(strpos($ajax, 'Azure_Newsletter_Email_Css::ensure_column_stack_style') !== false, 'test sends run the list fix');

// The unused inliner no longer maps a selector it cannot parse onto every
// element of the leading tag (e.g. `ul.menu > li` restyling every <ul>).
$inlined = $css::inline_keeping_media('<html><head><style>ul.menu > li { list-style: none; padding: 0; }</style></head><body><ul><li>A</li></ul></body></html>');
$t->check(preg_match('/<ul\b[^>]*style=/', $inlined) === 0, 'an unparsed selector is skipped, not applied to every <ul>');

exit($t->finish() > 0 ? 1 : 0);
