<?php
/**
 * Newsletters keep a 5px buffer between their content and the edge of the
 * email box, and between the box and the screen edge on phones.
 *
 * Run: php tests/test-newsletter-edge-buffer.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-email-css.php';

$t = new TestRunner('Newsletter edge buffer');
$C = 'Azure_Newsletter_Email_Css';

// Same shape as the editor's saved campaigns: grey wrapper > centred cell > 600px box.
$box = '<table role="presentation" width="600" cellspacing="0" cellpadding="0" id="i4ap" style="width:600px;max-width:600px;" class="c1708">'
    . '<!-- Header Image --><tbody><tr><td id="iq0h">'
    . '<a href="https://example.test"><img src="logo.png" alt="Header" width="600" id="ixpdt"/></a>'
    . '<table width="100%" class="nl-section"><tbody><tr><td style="padding:0;" class="nl-section-body"><table width="100%"><tbody><tr><td><h1>Howl Away Hunger Food Drive</h1></td></tr></tbody></table>'
    . '<table class="nl-stack-cols"><tr><td class="nl-column" width="50%"><img src="wolf.png" width="254"></td><td class="nl-column" width="50%">When: Mon-Fri</td></tr></table></td></tr></tbody></table>'
    . '</td></tr><!-- Footer --><tr><td id="in6cl"><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p></td></tr></tbody></table>';
$doc = '<!DOCTYPE html><html><head><style type="text/css">#iwif{padding:20px 0;}#in6cl{padding:30px;background-color:#333333;}</style></head>'
    . '<body style="margin:0;padding:0;"><table role="presentation" width="100%" id="itsi"><tbody><tr><td align="center" id="iwif">'
    . $box
    . '</td></tr></tbody></table><p>after</p></body></html>';

$out = $C::ensure_edge_buffer($doc);

$t->check(strpos($out, '<td align="center" class="nl-edge-buffer-outer" style="padding: 0 5px;"><table role="presentation" width="600"') !== false, 'the box sits in a cell with 5px either side (screen edge on phones)');
$t->check(strpos($out, 'class="c1708"><!-- Header Image --><tbody><tr><td class="nl-edge-buffer" style="padding: 5px;"><table role="presentation" width="100%"') !== false, 'the box keeps its own tag and tbody, and gets one 5px padded cell');
$t->check(strpos($out, '</td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table></td></tr></tbody></table><p>after</p>') !== false, 'every table closes where it should and content after the box is untouched');
$t->equals(substr_count($doc, '<table') + 2, substr_count($out, '<table'), 'exactly two tables are added');
$t->equals(substr_count($out, '<table'), substr_count($out, '</table>'), 'tables stay balanced');
$t->check(strpos($out, '<td id="in6cl">') !== false && strpos($out, '<td id="iq0h">') !== false, 'existing cells keep their ids, so their own padding rules still apply');
$t->check(strpos($out, 'id="iq0h" style') === false && strpos($out, 'id="in6cl" style') === false, 'no inline padding is written over existing cells');
$t->check(strpos($out, 'alt="Header" width="590"') !== false, 'a full-width 600px image is resized to fit inside the buffer');
$t->check(strpos($out, 'width="254"') !== false && strpos($out, 'width="50%"') !== false, 'other widths are left alone');
$t->check(strpos($out, '{{unsubscribe_url}}') !== false && strpos($out, 'Howl Away Hunger Food Drive') !== false, 'content and merge tags are kept');
$strip = function ($html) { return preg_replace('/\s+/', ' ', strip_tags($html)); };
$t->equals($strip($doc), $strip($out), 'no text is added or lost');
$t->equals($out, $C::ensure_edge_buffer($out), 'running it twice changes nothing');

$bare = '<table width="600"><tr><td>Hi</td></tr></table>';
$t->check(strpos($C::ensure_edge_buffer($bare), '<table width="600"><tr><td class="nl-edge-buffer" style="padding: 5px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%;"><tbody><tr><td>Hi</td></tr></tbody></table></td></tr></table>') !== false, 'a box without tbody gets its rows wrapped the same way');
$styled = '<table cellpadding="0" style="width: 600px; background:#fff"><tr><td>Hi</td></tr></table>';
$t->check(strpos($C::ensure_edge_buffer($styled), 'nl-edge-buffer') !== false, 'a box sized by inline style is found too');

$t->equals('<p>No box</p>', $C::ensure_edge_buffer('<p>No box</p>'), 'emails without a 600px box are unchanged');
$t->equals('<table width="100%"><tr><td>x</td></tr></table>', $C::ensure_edge_buffer('<table width="100%"><tr><td>x</td></tr></table>'), 'full-width tables are not mistaken for the box');
$broken = '<table width="600"><tr><td>unclosed';
$t->equals($broken, $C::ensure_edge_buffer($broken), 'an unclosed box is left alone');

$sent = $C::ensure_column_stack_style($doc);
$t->check(strpos($sent, 'class="nl-edge-buffer" style="padding: 5px;"') !== false, 'the send path (test sends and campaigns) adds the buffer');
$t->equals(1, substr_count($C::ensure_column_stack_style($sent), 'class="nl-edge-buffer"'), 'and adds it once even if run again');

$js = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/newsletter-editor.js');
$t->check(strpos($js, 'html = ensurePreviewEdgeBuffer(ensurePreviewFluidCss(html));') !== false, 'the Review preview adds the same buffer');

exit($t->finish() > 0 ? 1 : 0);
