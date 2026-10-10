<?php
/**
 * Illustrated campaign meters: style names, valid SVG at every stage, and
 * the growth milestones each style promises.
 *
 * Run: php tests/test-donation-progress-art.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-donation-progress-art.php';

$t = new TestRunner('Donation progress art');

$t->equals('bar', Azure_Donation_Progress_Art::normalize_style(''), 'empty style is the bar');
$t->equals('bar', Azure_Donation_Progress_Art::normalize_style('sparkly'), 'unknown style falls back to the bar');
$t->equals('bulb', Azure_Donation_Progress_Art::normalize_style(' Bulb '), 'style ignores case and spaces');
$t->equals('bulb', Azure_Donation_Progress_Art::normalize_style('tulip'), 'tulip alias');
$t->equals('tree', Azure_Donation_Progress_Art::normalize_style('acorn'), 'acorn alias');
$t->equals('garden', Azure_Donation_Progress_Art::normalize_style('flower_bed'), 'flower bed alias with underscore');
$t->check(Azure_Donation_Progress_Art::is_vertical('thermometer'), 'thermometer is vertical');
$t->check(!Azure_Donation_Progress_Art::is_vertical('garden'), 'garden is horizontal');
$t->equals('', Azure_Donation_Progress_Art::render_svg('bar', 50), 'bar has no SVG');

$t->equals(0.0, Azure_Donation_Progress_Art::ramp(5, 10, 20), 'ramp before start');
$t->equals(0.5, Azure_Donation_Progress_Art::ramp(15, 10, 20), 'ramp midway');
$t->equals(1.0, Azure_Donation_Progress_Art::ramp(25, 10, 20), 'ramp after end');

foreach (array('bulb', 'tree', 'garden', 'thermometer') as $style) {
    foreach (array(-5, 0, 3, 10, 37, 55, 85, 99, 100, 140) as $pct) {
        $svg = Azure_Donation_Progress_Art::render_svg($style, $pct, 40000);
        libxml_use_internal_errors(true);
        $ok = simplexml_load_string($svg) !== false;
        $t->check($ok, "{$style} at {$pct}% is well-formed SVG");
        $t->check(strpos($svg, 'NAN') === false && strpos($svg, 'INF') === false, "{$style} at {$pct}% has finite numbers");
    }
}

$bulb0 = Azure_Donation_Progress_Art::render_svg('bulb', 0);
$bulb40 = Azure_Donation_Progress_Art::render_svg('bulb', 40);
$bulb90 = Azure_Donation_Progress_Art::render_svg('bulb', 90);
$bulb100 = Azure_Donation_Progress_Art::render_svg('bulb', 100);
$t->check(strpos($bulb0, 'pta-art-sway') === false, 'bulb at 0% has no stem yet');
$t->check(strpos($bulb40, 'pta-art-sway') !== false && strpos($bulb40, 'pta-art-bloom') === false, 'bulb at 40% has a stem but no flower');
$t->check(strpos($bulb90, 'pta-art-bloom') !== false && strpos($bulb90, 'pta-art-sparkles') === false, 'bulb at 90% is flowering, not yet celebrating');
$t->check(strpos($bulb100, 'pta-art-flutter') !== false && strpos($bulb100, 'pta-art-sparkles') !== false, 'bulb at 100% gets the butterfly and sparkles');

$tree5 = Azure_Donation_Progress_Art::render_svg('tree', 5);
$tree60 = Azure_Donation_Progress_Art::render_svg('tree', 60);
$t->check(strpos($tree5, '-nut)') !== false && strpos($tree5, 'pta-art-sway') === false, 'tree at 5% is still an acorn');
$t->check(strpos($tree60, '<circle') !== false && strpos($tree60, '-nut)') === false, 'tree at 60% has a canopy and the shell is gone');

$t->equals(0, substr_count(Azure_Donation_Progress_Art::render_svg('garden', 0), 'pta-art-bloom'), 'garden at 0% has no flowers');
$t->equals(3, substr_count(Azure_Donation_Progress_Art::render_svg('garden', 50), 'pta-art-bloom'), 'garden at 50% has three of six flowers');
$t->check(strpos(Azure_Donation_Progress_Art::render_svg('garden', 0), 'pta-art-sway') === false, 'garden at 0% has no sprouts');
$t->equals(6, substr_count(Azure_Donation_Progress_Art::render_svg('garden', 100), 'pta-art-bloom'), 'garden at 100% is in full bloom');

$thermo = Azure_Donation_Progress_Art::render_svg('thermometer', 25, 40000);
$t->check(strpos($thermo, '$10,000') !== false && strpos($thermo, '$40,000') !== false, 'thermometer labels quarter marks in dollars');
$t->check(strpos(Azure_Donation_Progress_Art::render_svg('thermometer', 25, 0), '50%') !== false, 'thermometer without a goal labels percent');

$a = Azure_Donation_Progress_Art::render_svg('bulb', 50);
$b = Azure_Donation_Progress_Art::render_svg('bulb', 50);
preg_match('/id="([^"]+)-soil"/', $a, $ma);
preg_match('/id="([^"]+)-soil"/', $b, $mb);
$t->check(!empty($ma[1]) && $ma[1] !== $mb[1], 'two meters on one page get distinct gradient ids');

exit($t->finish() === 0 ? 0 : 1);
