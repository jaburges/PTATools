<?php
/**
 * Newsletter designer: single-level columns, no preset dupes, divider.
 *
 * Run: php tests/test-newsletter-designer-layout.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-email-css.php';

$t = new TestRunner('Newsletter designer layout');

$js = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/newsletter-editor.js');
$t->check($js !== false && strlen($js) > 100, 'newsletter-editor.js is readable');

$t->check(strpos($js, "'sect100'") !== false, 'removes preset 1-column block id');
$t->check(strpos($js, "'sect50'") !== false, 'removes preset 2-column block id');
$t->check(strpos($js, "'sect30'") !== false, 'removes preset 3-column block id');
$t->check(strpos($js, "bm.add('columns-1'") !== false, 'registers 1 Column block');
$t->check(strpos($js, "bm.add('columns-2'") !== false, 'registers 2 Columns block');
$t->check(strpos($js, "bm.add('columns-3'") !== false, 'registers 3 Columns block');
$t->check(strpos($js, "bm.add('divider'") !== false, 'registers Divider block');
$t->check(strpos($js, "class=\"nl-divider\"") !== false || strpos($js, "class='nl-divider'") !== false || strpos($js, 'nl-divider') !== false, 'divider markup is present');
$t->check(strpos($js, "addType('nl-columns'") !== false, 'registers nl-columns component');
$t->check(strpos($js, 'function isNewsletterButtonTable') !== false, 'button tables are detected without requiring Click Here');
$t->check(strpos($js, 'function syncButtonFromLink') !== false, 'button URL is synced from the saved <a href>');
$t->check(strpos($js, 'function syncAllEmailButtons') !== false, 'all buttons are re-synced after load');
$t->check(strpos($js, 'class="nl-button"') !== false, 'button block is marked nl-button');
$t->check(strpos($js, "el.innerHTML.indexOf('Click Here')") === false, 'button detection no longer depends on the default label');
$t->check(strpos($js, "addType('nl-column'") !== false, 'registers nl-column component');
$t->check(strpos($js, "droppable: ':not(.nl-stack-cols):not(.nl-section)'") !== false, 'column cells reject nested column rows and sections');
$t->check(strpos($js, 'hoistNestedColumns') !== false, 'nested column rows are hoisted');
$t->check(strpos($js, "bm.add('section-group'") !== false, 'registers Section group block');
$t->check(strpos($js, "addType('nl-section'") !== false, 'registers nl-section component');
$t->check(strpos($js, "addType('nl-section-body'") !== false, 'registers nl-section-body component');
$t->check(strpos($js, 'function findAncestorSection') !== false, 'finds the enclosing section for move/up down');
$t->check(strpos($js, 'function hoistNestedSection') !== false, 'nested sections are hoisted to siblings');
$t->check(strpos($js, 'function stripSectionHints') !== false, 'export strips section placeholder hints');
$t->check(strpos($js, 'function sectionGroupHtml') !== false, 'section group markup helper exists');
$t->check(strpos($js, 'class="nl-section') !== false, 'section wrapper class is present');
$t->check(strpos($js, "addType('column-widths'") !== false, 'column width slider trait exists');
$t->check(strpos($js, 'redistributeColumnWidths') !== false, 'width slider redistributes leftover space');
$t->check(strpos($js, "property: 'font-family'") !== false, 'font-family is an explicit select');
$t->check(strpos($js, 'Arial, Helvetica, sans-serif') !== false, 'email-safe Arial option exists');
$t->check(strpos($js, 'avoidInlineStyle: false') !== false, 'styles write inline for email');
$t->check(strpos($js, 'setupStyleApply') !== false, 'typography apply hook is registered');
$t->check(!preg_match("/bm\\.add\\('section'\\s*,/", $js), 'generic Section block is gone (Section group + 1 Column replace it)');
$t->check(strpos($js, "addType('nl-row-gap'") !== false, 'registers between-row drop rails');
$t->check(strpos($js, 'function syncRowGaps') !== false, 'keeps drop rails between top-level rows');
$t->check(strpos($js, 'function stripRowGaps') !== false, 'export strips drop rails from email HTML');
$t->check(strpos($js, 'emptyGapsIntoWrapper') !== false, 'drops on a rail become wrapper siblings');
$t->check(strpos($js, 'function syncGapsInContainer') !== false, 'canvas drop rails stay between top-level rows');
$t->check(strpos($js, 'function findAllSectionBodies') !== false, 'section bodies get their own drop rails');
$t->check(strpos($js, 'parentIsSectionChrome') !== false, 'drops on a section table land in the body');
$t->check(strpos($js, 'pta-delete-section') !== false, 'section delete command is registered');
$t->check(strpos($js, 'function deleteSelectedSection') !== false, 'section delete helper exists');
$t->check(strpos($js, 'Drop here for full width') !== false, 'drop rails are labeled in the canvas');
$t->check(strpos($js, 'td.nl-section-body > .nl-row-gap{display:none;}') !== false, 'section bodies do not show extra Drop here rails');
$t->check(strpos($js, 'Drop heading, columns, and a button here') === false, 'section well has no instructional copy');
$t->check(strpos($js, 'td.nl-section-body > .nl-section-hint::after{content:"Drop here";}') !== false, 'empty section has one Drop here label in the middle');
$t->check(strpos($js, 'table.nl-section-empty{height:260px !important;}') !== false, 'empty section table is 260px tall');
$t->check(strpos($js, 'nl-section-empty') !== false, 'empty section uses an explicit height class');
$t->check(strpos($js, 'height="260"') !== false, 'empty section HTML carries a table height');
$t->check(strpos($js, 'padding:56px 24px') !== false, 'empty section pads so the drop well sits in the middle');
$t->check(strpos($js, 'function isSectionInternal') !== false, 'section table rows are not hoisted into the cell');
$t->check(strpos($js, '!isSectionInternal(component)') !== false, 'only dropped content is hoisted into a section');
$t->check(strpos($js, 'function stripEmptySections') !== false, 'export strips empty section frames');
$t->check(strpos($js, 'function extractBalancedTable') !== false, 'empty-section strip walks nested tables');
$t->check(strpos($js, 'function sectionTableIsEmpty') !== false, 'empty-section strip has an explicit emptiness check');
$t->check(strpos($js, 'nl-divider-rule') !== false, 'divider uses a bgcolor rule instead of a bare hr');
$t->check(strpos($js, 'bgcolor="#dddddd"') !== false, 'divider rule is a 2px colored cell');
$t->check(strpos($js, 'dividerCss') !== false, 'export includes divider CSS');
$t->check(strpos($js, 'function applySectionFrame') !== false, 'filled sections drop the empty-frame height');
$t->check(strpos($js, 'min-height:140px') !== false, 'centered drop well is a distinct middle target');
$t->check(strpos($js, "class=\"nl-section-hint\"") !== false, 'empty section ships a centered hint well');
$t->check(strpos($js, "addType('nl-section-handle'") !== false, 'section handle is a designer component');
$t->check(strpos($js, 'function isSectionHandle') !== false, 'section handle is detected');
$t->check(strpos($js, 'function syncSectionHandles') !== false, 'saved sections get a select handle');
$t->check(strpos($js, 'function stripSectionHandles') !== false, 'export strips the section handle');
$t->check(strpos($js, 'isSectionHandle(component)') !== false, 'clicking the handle selects the section');
$t->check(strpos($js, '.nl-section-handle{') !== false, 'section handle is a full-height rail');
$t->check(strpos($js, "addType('nl-section-hint'") !== false, 'section hint well is a droppable component');
$t->check(strpos($js, 'isSectionHint(parent)') !== false, 'drops on the hint well are hoisted into the section');
$t->check(strpos($js, '<(p|div)[^>]*class="[^"]*nl-section-hint') !== false, 'export strips p or div section hints');
$t->check(strpos($js, 'function moveSelectedRow') !== false, 'row move helper exists');
$t->check(strpos($js, 'pta-move-row-up') !== false, 'move-up command is registered');
$t->check(strpos($js, 'pta-move-row-down') !== false, 'move-down command is registered');
$t->check(strpos($js, '#btn-row-up') !== false, 'toolbar up button is wired');
$t->check(strpos($js, '#btn-row-down') !== false, 'toolbar down button is wired');
$t->check(strpos($js, 'function rotateListLeft') !== false, 'column swap uses a left-rotate helper');
$t->check(strpos($js, 'function cycleColumnContents') !== false, 'column contents cycle as whole cells');
$t->check(strpos($js, 'function columnChildrenJson') === false, 'swap does not rebuild cells from toJSON');
$t->check(strpos($js, 'setColumnChildren') === false, 'swap does not reset cells via components(json)');
$t->check(strpos($js, 'function getSwappableColumnRow') !== false, 'swap finds the enclosing 2/3 column row');
$t->check(strpos($js, 'pta-swap-columns') !== false, 'swap-columns command is registered');
$t->check(strpos($js, '#btn-swap-cols') !== false, 'toolbar swap button is wired');
$t->check(strpos($js, '#btn-delete-section') !== false, 'toolbar delete-section button is wired');
$t->check(strpos($js, '#btn-format-text') !== false, 'toolbar Format text button is wired');
$t->check(strpos($js, 'function formatTextToDefault') !== false, 'Format text helper exists');
$t->check(strpos($js, 'function findTextStyleHost') !== false, 'typography targets the text block or column cell');
$t->check(strpos($js, 'function normalizeTextHost') !== false, 'Format text resets body font and size');
$t->check(strpos($js, "NL_DEFAULT_FONT = 'Arial, Helvetica, sans-serif'") !== false, 'default body font is Arial');
$t->check(strpos($js, "NL_DEFAULT_SIZE = '14px'") !== false, 'default body size is 14px');
$t->check(strpos($js, 'lastTextStyleHost') !== false, 'sidebar font changes keep the last text block');
$t->check(strpos($js, 'bindTextHostDblClick') !== false, 'double-click edits words without needing a tight text selection');
$t->check(strpos($js, 'function isSettingsUi') !== false, 'Settings sidebar clicks are recognized');
$t->check(strpos($js, 'function restoreStyleHost') !== false, 'Settings can re-select the last text block');
$t->check(strpos($js, 'holdingSettingsSelection') !== false, 'clicking Settings does not steal the canvas selection');
$t->check(strpos($js, "addEventListener('pointerdown'") !== false, 'Settings pointerdown restores selection before GrapesJS drops it');
$t->check(strpos($js, 'function undoManagerBusy') !== false, 'style/selection hooks do not run during undo');
$t->check(strpos($js, 'function selectQuiet') !== false, 'promoting a text click to the block is not an undo step');
$t->check(strpos($js, "runCommand('core:undo')") !== false, 'toolbar Undo uses the GrapesJS undo command');
$t->check(strpos($js, "bm.add('now-next'") !== false, 'registers Now and Next block');
$t->check(strpos($js, '[nl-now-next]') !== false, 'Now and Next placeholder uses nl-now-next');
$t->check(strpos($js, 'enable_links="false"') !== false, 'Now and Next default shortcode turns event links off');
$t->check(preg_match("/bm\\.add\\('now-next'[\\s\\S]*?bm\\.add\\('shortcode-block'/", $js, $nn_block) === 1, 'Now and Next block definition is isolated');
$t->check(isset($nn_block[0]) && strpos($nn_block[0], 'dashed') === false, 'Now and Next is not a dashed shortcode tile');
$t->check(isset($nn_block[0]) && strpos($nn_block[0], '#f0f6fc') === false, 'Now and Next has no special background');
$t->check(strpos($js, 'function stripNowNextDesignerChrome') !== false, 'saved Now and Next tiles drop the dashed chrome on load');
$t->check(!preg_match("/bm\\.add\\('section'\\s*,/", $js), 'Now and Next is not registered as a generic section id');
$t->check(strpos($js, 'function findEmailCanvasCell') !== false, 'finds the 600px email content cell');
$t->check(strpos($js, 'function findEmailCanvasTable') !== false, 'finds the 600px inner table');
$t->check(strpos($js, 'function hoistEscapedBlocksIntoCanvas') !== false, 'sections that escape the 600px table are hoisted back');
$t->check(strpos($js, 'function syncCanvasBlockGaps') !== false, 'drop rails sit between sections inside the 600px cell');
$t->check(strpos($js, 'function pinEmailCanvasWidth') !== false, 'pins the inner table at 600px');
$t->check(strpos($js, 'function hoistNowNext') !== false, 'Now and Next is hoisted out of a column cell');
$t->check(strpos($js, 'hoistEscapedBlocksIntoCanvas()') !== false, 'export hoists escaped blocks before getHtml');
$t->check(strpos($js, 'table[width="600"]{width:600px !important;max-width:600px !important;}') !== false, 'canvas CSS keeps the inner table at 600px');

$editor_php = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/newsletter-editor.php');
$t->check(strpos($editor_php, 'id="btn-row-up"') !== false, 'designer toolbar has Move row up');
$t->check(strpos($editor_php, 'id="btn-row-down"') !== false, 'designer toolbar has Move row down');
$t->check(strpos($editor_php, 'id="btn-swap-cols"') !== false, 'designer toolbar has Swap columns');
$t->check(strpos($editor_php, 'id="btn-delete-section"') !== false, 'designer toolbar has Delete section');
$t->check(strpos($editor_php, 'id="btn-format-text"') !== false, 'designer toolbar has Format text');
$t->check(strpos($editor_php, 'left bar on a Section') !== false, 'help bar explains the section select handle');
$t->check(strpos($editor_php, 'text block to style it') !== false, 'help bar explains block-level typography');
$t->check(strpos($editor_php, 'Double-click to edit') !== false, 'help bar explains double-click to edit words');
$t->check(strpos($editor_php, 'Format text') !== false, 'help bar mentions Format text');
$t->check(strpos($editor_php, 'data-panel="styles"') === false, 'Styles tab is merged into Settings');
$t->check(strpos($editor_php, 'id="styles-panel"') === false, 'separate styles panel is gone');
$t->check(strpos($editor_php, 'id="styles-container"') !== false, 'style manager still mounts in Settings');
$t->check(strpos($editor_php, 'id="traits-container"') !== false, 'trait manager still mounts in Settings');

$t->check(strpos($js, 'updateStyleManager: false') !== false, 'preset is not allowed to replace our style sectors');
$t->check(strpos($js, 'function stripGrapesPanels') !== false, 'GrapesJS device/options/views chrome is removed');
$t->check(strpos($js, 'function setupStyleApply') !== false, 'style changes are pushed onto the block');
$t->check(strpos($js, 'function findTextBlockStyleRoot') !== false, 'spacing targets the text-block cell');
$t->check(strpos($js, 'padding: 10px; width:') !== false, 'column cells keep a 10px gap to the text block');
$t->check(strpos($js, 'constrainColumnImage') !== false, 'images dropped in a column are constrained to the cell');
$t->check(strpos($js, 'function applyImageLink') !== false, 'image Link URL wraps the <img> in an <a>');
$t->check(strpos($js, 'function applyAllImageLinks') !== false, 'all image links are applied before export');
$t->check(strpos($js, 'function wrapImgHrefInHtml') !== false, 'export HTML wraps leftover img[href]');
$t->check(strpos($js, 'applyAllImageLinks()') !== false, 'getEmailReadyHtml applies image links before getHtml');
$t->check(strpos($js, "width=\"100%\" style=\"display: block; width: 100%; max-width: 100%") !== false, 'image block default is 100% not a 600px overflow');
$t->check(strpos($js, 'columnGapCss') !== false, 'export includes the shared column-gap CSS');
$t->check(strpos($js, '<td style="padding: 0; font-family: Arial, sans-serif; font-size: 14px;') !== false, 'default text block has no inner padding');
$t->check(strpos($js, '<td style="padding: 0;">') !== false, 'default heading block has no inner padding');

$sample = '<div class="nl-row-gap" aria-hidden="true"></div><table class="nl-stack-cols"></table><div class="nl-row-gap"></div>';
$stripped = preg_replace('/<div[^>]*class="[^"]*nl-row-gap[^"]*"[^>]*>[\s\S]*?<\/div>/i', '', $sample);
$t->check(strpos($stripped, 'nl-row-gap') === false, 'row-gap markup is removed for send');
$t->check(strpos($stripped, 'nl-stack-cols') !== false, 'real rows survive gap stripping');

$css = Azure_Newsletter_Email_Css::column_stack_css();
$t->check(strpos($css, '.nl-column') !== false, 'mobile stack targets .nl-column after slider widths change');

/**
 * Mirror of redistributeColumnWidths() for the math the sliders use.
 */
function test_redistribute($widths, $index, $new_val) {
    $n = count($widths);
    if ($n < 1) {
        return array();
    }
    if ($n === 1) {
        return array(100);
    }
    $min = 15;
    $max = 100 - $min * ($n - 1);
    $new_val = max($min, min($max, (float) $new_val));
    $next = $widths;
    $old = $next[$index];
    $delta = $new_val - $old;
    $next[$index] = $new_val;
    $others = array();
    $other_sum = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i !== $index) {
            $others[] = $i;
            $other_sum += $next[$i];
        }
    }
    if ($other_sum <= 0) {
        $even = (100 - $new_val) / count($others);
        foreach ($others as $oi) {
            $next[$oi] = $even;
        }
    } else {
        foreach ($others as $oi) {
            $next[$oi] = $next[$oi] - $delta * ($next[$oi] / $other_sum);
        }
    }
    for ($i = 0; $i < $n; $i++) {
        if ($i !== $index && $next[$i] < $min) {
            $next[$i] = $min;
        }
    }
    $sum = array_sum($next);
    if (abs($sum - 100) > 0.01) {
        $fix = 100 - $sum;
        $grow = $index === 0 ? 1 : 0;
        $next[$grow] = max($min, min($max, $next[$grow] + $fix));
    }
    $rounded = array();
    $rounded_sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $rounded[$i] = (int) round($next[$i]);
        $rounded_sum += $rounded[$i];
    }
    $rounded[$n - 1] += (100 - $rounded_sum);
    if ($rounded[$n - 1] < $min) {
        $deficit = $min - $rounded[$n - 1];
        $rounded[$n - 1] = $min;
        for ($i = 0; $i < $n - 1 && $deficit > 0; $i++) {
            $take = min($deficit, $rounded[$i] - $min);
            $rounded[$i] -= $take;
            $deficit -= $take;
        }
    }
    return $rounded;
}

$two = test_redistribute(array(50, 50), 0, 70);
$t->equals(array(70, 30), $two, '2-column slider 70/30 still adds to 100');
$t->equals(100, array_sum($two), '2-column sum is 100');

$three = test_redistribute(array(33, 34, 33), 0, 50);
$t->equals(100, array_sum($three), '3-column sum stays 100 after widening col 1');
$t->equals(50, $three[0], '3-column first slider value is kept');
$t->check($three[1] >= 15 && $three[2] >= 15, 'other 3-column cells stay at least 15%');

$clamped = test_redistribute(array(50, 50), 0, 99);
$t->equals(array(85, 15), $clamped, '2-column cannot shrink the other cell below 15%');

/**
 * Mirror of rotateListLeft() — 2-col swap and 3-col cycle.
 */
function test_rotate_list_left($items) {
    if (!is_array($items) || count($items) < 2) {
        return $items;
    }
    $first = array_shift($items);
    $items[] = $first;
    return $items;
}

$t->equals(array('text', 'image'), test_rotate_list_left(array('image', 'text')), '2-column swap flips left and right');
$t->equals(array('B', 'C', 'A'), test_rotate_list_left(array('A', 'B', 'C')), '3-column cycle keeps each cell together');
$t->equals(array('A', 'B', 'C'), test_rotate_list_left(test_rotate_list_left(test_rotate_list_left(array('A', 'B', 'C')))), '3-column cycle returns to original after three clicks');
$t->equals(array('only'), test_rotate_list_left(array('only')), '1-column row is left alone');

/**
 * Mirror of the Format text DOM cleanup: drop pasted font-family / font-size
 * but keep bold and links.
 */
function test_strip_inline_fonts($html) {
    return preg_replace_callback('/style="([^"]*)"/i', function ($m) {
        $parts = array_filter(array_map('trim', explode(';', $m[1])), function ($part) {
            if ($part === '') {
                return false;
            }
            return !preg_match('/^font-family\s*:/i', $part) && !preg_match('/^font-size\s*:/i', $part);
        });
        if (!$parts) {
            return '';
        }
        return 'style="' . implode('; ', $parts) . '"';
    }, $html);
}

$pasted = '<span style="font-family:Aptos, sans-serif;font-size:12pt;"><b>When:</b> Friday</span>'
    . '<a href="https://example.com" style="color:#2271b1;font-size:12pt;">Link</a>';
$cleaned = test_strip_inline_fonts($pasted);
$t->check(strpos($cleaned, 'Aptos') === false, 'Format text drops Outlook Aptos');
$t->check(strpos($cleaned, '12pt') === false, 'Format text drops pasted 12pt sizes');
$t->check(strpos($cleaned, '<b>When:</b>') !== false, 'Format text keeps bold');
$t->check(strpos($cleaned, 'https://example.com') !== false, 'Format text keeps links');
$t->check(strpos($cleaned, 'color:#2271b1') !== false, 'Format text keeps link color');

/**
 * Mirror of extractBalancedTable + sectionTableIsEmpty + stripEmptySections.
 */
function test_extract_balanced_table($html, $start) {
    $depth = 0;
    if (!preg_match_all('/<\/?table\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    foreach ($matches[0] as $m) {
        if ($m[1] < $start) {
            continue;
        }
        if (isset($m[0][1]) && $m[0][1] === '/') {
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $m[1] + strlen($m[0]) - $start);
            }
        } else {
            $depth++;
        }
    }
    return null;
}

function test_section_table_is_empty($table) {
    if ($table === '' || $table === null) {
        return true;
    }
    if (preg_match('/<hr\b|<img\b|nl-divider|nl-button|nl-now-next|nl-stack-cols/i', $table)) {
        return false;
    }
    $inner = preg_replace('/<(p|div)[^>]*class="[^"]*nl-section-hint[^"]*"[^>]*>.*?<\/\1>/is', '', $table);
    $inner = preg_replace('/<[^>]+>/', '', $inner);
    $inner = str_ireplace('&nbsp;', '', $inner);
    $inner = preg_replace('/\s+/', '', $inner);
    return $inner === '';
}

function test_strip_empty_sections($html) {
    $out = '';
    $i = 0;
    if (!preg_match_all('/<table\b[^>]*nl-section[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
        return $html;
    }
    foreach ($matches[0] as $m) {
        $full = test_extract_balanced_table($html, $m[1]);
        if ($full === null) {
            break;
        }
        $out .= substr($html, $i, $m[1] - $i);
        if (!test_section_table_is_empty($full)) {
            $out .= $full;
        }
        $i = $m[1] + strlen($full);
    }
    $out .= substr($html, $i);
    return $out;
}

$divider_only = '<table class="nl-section"><tr><td class="nl-section-body">'
    . '<table class="nl-divider"><tr><td class="nl-divider-rule" bgcolor="#dddddd">&nbsp;</td></tr></table>'
    . '</td></tr></table>';
$naive = preg_replace('/<table\b[^>]*nl-section[^>]*>[\s\S]*?<\/table>/i', '', $divider_only);
$t->check($naive === '' || strpos($naive, 'nl-divider') === false, 'naive first-</table> strip would delete a divider-only section');
$kept = test_strip_empty_sections($divider_only);
$t->check(strpos($kept, 'nl-divider') !== false, 'a section that only contains a divider is kept');
$t->check(strpos($kept, 'nl-section') !== false, 'the section wrapper around a divider is kept');

$hr_only = '<table class="nl-section"><tr><td class="nl-section-body">'
    . '<table class="nl-divider"><tr><td><hr></td></tr></table>'
    . '</td></tr></table>';
$t->check(strpos(test_strip_empty_sections($hr_only), '<hr') !== false, 'a section that only contains an hr divider is kept');

$hint_only = '<table class="nl-section nl-section-empty"><tr><td class="nl-section-body">'
    . '<div class="nl-section-hint"></div></td></tr></table>';
$t->equals('', test_strip_empty_sections($hint_only), 'a section with only the drop hint is still stripped');

$heading = '<table class="nl-section"><tr><td class="nl-section-body"><h1>Carnival</h1>'
    . '<table class="nl-divider"><tr><td><hr></td></tr></table></td></tr></table>';
$heading_out = test_strip_empty_sections($heading);
$t->check(strpos($heading_out, 'Carnival') !== false && strpos($heading_out, 'nl-divider') !== false, 'heading plus divider stays intact');

exit($t->finish() === 0 ? 0 : 1);
