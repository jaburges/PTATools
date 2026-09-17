<?php
/**
 * Class competitions: kids and percent, never dollars.
 *
 * Run: php tests/test-class-competitions.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) {
        echo esc_html(__($text, $domain));
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-class-competitions.php';

$t = new TestRunner('Class competitions');

$teachers = array('Ms. Rivera', 'Mr. Chen');
$t->equals(
    array('Ms. Rivera' => 24, 'Mr. Chen' => 0),
    Azure_Class_Competitions::sanitize_class_sizes(
        array('ms. rivera' => 24, 'Unknown' => 99, 'Mr. Chen' => -3),
        $teachers
    ),
    'sizes keep Child Info teachers only and clamp below zero'
);

$t->equals(
    array('Ms. Rivera' => 500, 'Mr. Chen' => 0),
    Azure_Class_Competitions::sanitize_class_sizes(
        array('Ms. Rivera' => 900),
        $teachers
    ),
    'class size max is 500'
);

$comps = Azure_Class_Competitions::sanitize_competitions(array(
    array('id' => 0, 'name' => 'WAG classrooms', 'source_type' => 'campaign', 'source_id' => 3),
    array('id' => 1, 'name' => 'Skip me', 'source_type' => 'campaign', 'source_id' => 0),
    array('name' => 'Gift product', 'source_type' => 'product', 'source_id' => 88, 'show_count' => false, 'show_percent' => true),
));
$t->equals(2, count($comps), 'incomplete rows are dropped');
$t->equals(1, $comps[0]['id'], 'first competition gets id 1');
$t->equals(2, $comps[1]['id'], 'second competition gets next id');
$t->equals('product', $comps[1]['source_type'], 'product source is kept');
$t->check($comps[1]['show_percent'] && !$comps[1]['show_count'], 'percent-only board is allowed');

$t->check(
    Azure_Class_Competitions::child_participates(array(10, 11), array(11, 99)),
    'child counts when any family parent donated'
);
$t->check(
    !Azure_Class_Competitions::child_participates(array(10), array(11)),
    'child does not count without a donor parent'
);
$t->check(
    !Azure_Class_Competitions::child_participates(array(0), array(0)),
    'guest user id 0 does not count'
);

$t->equals(50, Azure_Class_Competitions::percent(12, 24), 'percent is kids over class size');
$t->equals(null, Azure_Class_Competitions::percent(12, 0), 'percent is blank when class size is unknown');
$t->equals(100, Azure_Class_Competitions::percent(30, 24), 'percent caps at 100');

$children = array(
    array('id' => 1, 'teacher' => 'Ms. Rivera', 'grade' => '3', 'parent_ids' => array(10)),
    array('id' => 2, 'teacher' => 'Ms. Rivera', 'grade' => '3', 'parent_ids' => array(11)),
    array('id' => 3, 'teacher' => 'Mr. Chen', 'grade' => '4', 'parent_ids' => array(12)),
    array('id' => 3, 'teacher' => 'Mr. Chen', 'grade' => '4', 'parent_ids' => array(12)),
);
$rows = Azure_Class_Competitions::build_rows(
    $teachers,
    array('Ms. Rivera' => 20, 'Mr. Chen' => 10),
    $children,
    array(10, 12),
    true,
    true
);
$t->equals(2, count($rows), 'one row per teacher');
$t->equals('Mr. Chen', $rows[0]['teacher'], 'higher percent sorts first');
$t->equals(1, $rows[0]['count'], 'duplicate child id is counted once');
$t->equals(1, $rows[1]['count'], 'only donor families count');
$t->equals(5, $rows[1]['percent'], 'Rivera is 1 of 20');
$encoded = json_encode($rows);
$t->check(strpos($encoded, 'amount') === false, 'rows have no amount field');
$t->check(strpos($encoded, 'raised') === false, 'rows have no raised field');
$t->check(!isset($rows[0]['dollars']) && !isset($rows[0]['money']), 'rows have no money fields');

$html = Azure_Class_Competitions::render_table(
    array('name' => 'WAG classrooms', 'show_count' => true, 'show_percent' => true),
    $rows
);
$t->check(strpos($html, '$') === false, 'board HTML never includes a dollar sign');
$t->check(strpos($html, 'Participating kids') !== false, 'count column is labeled as kids');
$t->check(strpos($html, '% of class') !== false, 'percent column is present');
$t->check(strpos($html, 'Mr. Chen') !== false, 'teacher names render');

exit($t->finish() === 0 ? 0 : 1);
