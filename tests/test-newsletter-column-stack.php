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

$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('1.6', 'font-size: 14px'), 22, 'unitless 1.6 at 14px becomes 22px');
$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('1.45', 'font-size: 13px'), 19, 'unitless 1.45 at 13px becomes 19px');
$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('22', ''), 22, 'unitless 22 is treated as already-px');
$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('2px', ''), null, 'divider 2px line-height is left alone');
$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('100%', ''), null, 'image reset 100% line-height is left alone');

$overlap = '<p style="font-family:Arial;font-size:14px;line-height:1.6;color:#333">Hello</p>';
$fixed = Azure_Newsletter_Email_Css::ensure_column_stack_style($overlap);
$t->check(strpos($fixed, 'line-height: 22px') !== false, 'send path converts unitless 1.6 to 22px');
$t->check(strpos($fixed, 'mso-line-height-rule: exactly') !== false, 'send path pins Outlook to the px height on text');
$t->check(strpos($fixed, 'line-height:1.6') === false, 'send path does not leave unitless 1.6 for Word Outlook');

$td_lh = Azure_Newsletter_Email_Css::ensure_column_stack_style(
    '<td style="font-family:Arial;font-size:14px;line-height:1.6;color:#333">Hello</td>'
);
$t->check(strpos($td_lh, 'line-height: 22px') !== false, 'unitless 1.6 on a cell becomes 22px');
$t->check(strpos($td_lh, 'mso-line-height-rule') === false, 'cells do not get mso-line-height-rule:exactly (that stacks child lines in Word)');

$hairline = Azure_Newsletter_Email_Css::outlook_safe_line_heights(
    '<td style="line-height: 2px; font-size: 1px; height: 2px;">&nbsp;</td>'
);
$t->check(strpos($hairline, 'line-height: 2px') !== false, 'divider hairline line-height stays 2px');
$t->check(strpos($hairline, 'mso-line-height-rule') === false, 'divider hairline does not get mso-line-height-rule');

$sheet = '<html><head><style type="text/css">#iabc{font-size:14px;line-height:1.6;color:#333}</style></head>'
    . '<body><td id="iabc">Hello</td></body></html>';
$sheet_fixed = Azure_Newsletter_Email_Css::outlook_safe_line_heights($sheet);
$t->check(strpos($sheet_fixed, 'line-height: 22px') !== false, 'GrapesJS stylesheet 1.6 becomes 22px for Outlook mobile');
$t->check(strpos($sheet_fixed, 'line-height:1.6') === false, 'stylesheet does not keep unitless 1.6');

$ensured_sheet = Azure_Newsletter_Email_Css::ensure_column_stack_style($sheet);
$t->check(strpos($ensured_sheet, Azure_Newsletter_Email_Css::OUTLOOK_LH_MARKER) !== false, 'send path injects Outlook block-flow CSS');
$t->check(strpos($ensured_sheet, 'div { display: block; }') !== false, 'Outlook mobile keeps wrapping divs in block flow');

$img_reset = Azure_Newsletter_Email_Css::rewrite_line_height_blocks('img { border: 0; line-height: 100%; }');
$t->check(strpos($img_reset, 'line-height: 100%') !== false, 'image reset 100% in a stylesheet is left alone');

$t->equals(Azure_Newsletter_Email_Css::line_height_to_px('inherit', 'font-size: 12pt'), 26, 'Outlook inherit at 12pt becomes 26px');

$pasted = '<td width="50%" class="nl-column">'
    . '<span data-olk-copy-source="MessageBody" style="font-family:Arial, Helvetica, sans-serif;font-size:14px;">'
    . '<b>When:</b> 8AM Tuesday<br/>'
    . '<div data-olk-copy-source="MessageBody" style="font-size:12pt;line-height:inherit;font-family:Aptos, Arial, sans-serif;">'
    . 'Step into the magical land.</div>'
    . '</span></td>';
$kept = Azure_Newsletter_Email_Css::ensure_column_stack_style($pasted);
$t->check(strpos($kept, 'data-olk-copy-source') !== false, 'send path keeps pasted Outlook wrappers visible');
$t->check(strpos($kept, 'Aptos') !== false, 'send path does not rewrite pasted font-family');
$t->check(strpos($kept, 'line-height:inherit') === false && strpos($kept, 'line-height: inherit') === false, 'send path still replaces line-height:inherit with px');
$t->check(preg_match('/line-height:\s*\d+px/', $kept), 'Outlook inherit gets a px line-height');
$t->check(strpos($kept, 'When:') !== false && strpos($kept, 'magical land') !== false, 'pasted words survive send-path CSS');
$t->check(strlen($kept) >= strlen($pasted), 'send path does not shrink pasted cells away');
$t->check(strpos($css_src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-email-css.php'), 'function strip_outlook_paste') === false, 'send path no longer unwraps pasted markup');

$ajax_src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-ajax.php');
$t->check(strpos($ajax_src, 'function prepared_html_keeps_content') !== false, 'test send refuses an emptied prepare result');
$t->check(strpos($ajax_src, 'function preg_replace_keep') !== false, 'test send keeps HTML when a preg_replace fails');

$campaign_file = __DIR__ . '/fixtures/sept7-body-snip.html';
$campaign = file_get_contents($campaign_file);
$t->check($campaign !== false && strpos($campaign, 'data-olk-copy-source') !== false, 'Sept 7 campaign fixture is readable');
$campaign_out = Azure_Newsletter_Email_Css::ensure_column_stack_style($campaign);
$t->check(is_string($campaign_out) && strlen($campaign_out) > 200, 'send path does not empty the Sept 7 campaign');
$t->check(strpos($campaign_out, 'data-olk-copy-source') !== false, 'Sept 7 Outlook wrappers survive send');
$t->check(strpos($campaign_out, 'Hi Wilder Wolves Families') !== false, 'Sept 7 greeting survives send');
$t->check(strpos($campaign_out, 'magical land') !== false, 'Sept 7 theater copy survives send');
$t->check(strpos($campaign_out, 'line-height:inherit') === false && strpos($campaign_out, 'line-height: inherit') === false, 'Sept 7 inherit line-height is converted to px');

$stress = $pasted;
for ($i = 0; $i < 200; $i++) {
    $stress .= '<td class="nl-column" style="font-family:Arial;font-size:14px;line-height:inherit;">Block ' . $i . '</td>';
}
$stress_out = Azure_Newsletter_Email_Css::ensure_column_stack_style($stress);
$t->check(is_string($stress_out) && strpos($stress_out, 'Block 199') !== false, 'a large pasted document is not emptied by send-path CSS');
$t->check(strpos($stress_out, 'data-olk-copy-source') !== false, 'Outlook wrappers survive a large send-path rewrite');

$queue_src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-queue.php');
$t->check(strpos($queue_src, 'inline_keeping_media') === false, 'scheduled Mailgun send does not run the DOM CSS inliner');
$t->check(strpos($queue_src, 'ensure_column_stack_style') !== false, 'scheduled send still converts line-heights like the test send');

$grapes = '<html><head><style type="text/css">'
    . '#cell1{font-size:14px;line-height:1.6;}'
    . 'p { line-height: 1.6; }'
    . '</style></head><body>'
    . '<td id="cell1" class="nl-column" style="font-family:Arial;font-size:14px;">When: Friday</td>'
    . '<p style="font-size:14px;line-height:1.6 !important;">Body</p>'
    . '</body></html>';
$test_path = Azure_Newsletter_Email_Css::ensure_column_stack_style($grapes);
$inlined_path = Azure_Newsletter_Email_Css::ensure_column_stack_style(
    Azure_Newsletter_Email_Css::inline_keeping_media($grapes)
);
$t->check(!preg_match('/<td[^>]*line-height/i', $test_path), 'test/queue path leaves stylesheet line-height in <style>, not on the cell');
$t->check(preg_match('/<p[^>]*mso-line-height-rule:\s*exactly/i', $test_path), 'paragraphs with unitless 1.6 still get exactly');
$t->check(strpos($test_path, '1.6 !important') === false, 'line-height: 1.6 !important is converted to px');
$t->check(preg_match('/<td[^>]*line-height:\s*22px/i', $inlined_path), 'DOM inliner copies GrapesJS 1.6 onto the cell — that is why the queue no longer uses it');

exit($t->finish() === 0 ? 0 : 1);
