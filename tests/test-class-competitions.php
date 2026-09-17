<?php
/**
 * Class competitions: purchases and percent, never dollars.
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
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) {
        return ((int) $number === 1) ? $single : $plural;
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

$from_pta = Azure_Class_Competitions::purchase_from_item_meta(array(
    '_pta_child_teacher' => 'Ms. Rivera',
    '_pta_childsgrade'   => '3',
));
$t->equals('Ms. Rivera', $from_pta['teacher'], 'teacher from _pta_child_teacher');
$t->equals('3', $from_pta['grade'], 'grade from _pta_childsgrade');

$from_raw = Azure_Class_Competitions::purchase_from_item_meta(array(
    '_azure_product_fields_raw' => array(
        array('field_key' => 'child_teacher', 'label' => 'Teacher', 'value' => 'Mr. Chen'),
        array('field_key' => 'childsgrade', 'label' => 'Grade', 'value' => '4'),
    ),
));
$t->equals('Mr. Chen', $from_raw['teacher'], 'teacher from product-fields raw');
$t->equals('4', $from_raw['grade'], 'grade from product-fields raw');

$from_children = Azure_Class_Competitions::purchase_from_item_meta(array(
    '_azure_pf_children' => array(
        array('name' => 'Sam', 'grade' => '2', 'teacher' => 'Ms. Rivera'),
    ),
));
$t->equals('Ms. Rivera', $from_children['teacher'], 'teacher from azure_pf_children');

$t->equals(
    null,
    Azure_Class_Competitions::purchase_from_item_meta(array('Color' => 'Blue')),
    'line item without a teacher is skipped'
);

$t->equals(50, Azure_Class_Competitions::percent(12, 24), 'percent is purchases over class size');
$t->equals(null, Azure_Class_Competitions::percent(12, 0), 'percent is blank when class size is unknown');
$t->equals(100, Azure_Class_Competitions::percent(30, 24), 'percent caps at 100');

$purchases = array(
    array('teacher' => 'Ms. Rivera', 'grade' => '3'),
    array('teacher' => 'Ms. Rivera', 'grade' => '3'),
    array('teacher' => 'Mr. Chen', 'grade' => '4'),
    array('teacher' => '', 'grade' => '4'),
);
$rows = Azure_Class_Competitions::build_rows(
    $teachers,
    array('Ms. Rivera' => 20, 'Mr. Chen' => 10),
    $purchases,
    true,
    true
);
$t->equals(2, count($rows), 'one row per teacher');
$t->equals('Ms. Rivera', $rows[0]['teacher'], 'higher purchase percent sorts first');
$t->equals(2, $rows[0]['count'], 'two line items on Rivera count as two purchases');
$t->equals(1, $rows[1]['count'], 'one line item on Chen');
$t->equals(10, $rows[0]['percent'], 'Rivera is 2 of 20');
$t->equals(10, $rows[1]['percent'], 'Chen is 1 of 10');
$encoded = json_encode($rows);
$t->check(strpos($encoded, 'amount') === false, 'rows have no amount field');
$t->check(strpos($encoded, 'raised') === false, 'rows have no raised field');
$t->check(!isset($rows[0]['dollars']) && !isset($rows[0]['money']), 'rows have no money fields');

$html = Azure_Class_Competitions::render_table(
    array('name' => 'WAG classrooms', 'show_count' => true, 'show_percent' => true),
    $rows
);
$t->check(strpos($html, '$') === false, 'board HTML never includes a dollar sign');
$t->check(strpos($html, '2 Donations') !== false, 'count is labeled as Donations');
$t->check(strpos($html, 'purchase') === false, 'board no longer says purchases');
$t->check(strpos($html, 'pta-class-race') !== false, 'board uses the race layout');
$t->check(strpos($html, 'pta-class-race-wolf') !== false, 'each lane has a wolf');
$t->check(strpos($html, 'Ms. Rivera') !== false, 'teacher names render');
$t->check(strpos($html, '--sweater:') !== false, 'lanes get a sweater color');
$t->check(strpos($html, 'pta-class-race-num') !== false, 'lanes are numbered like a track');
$t->check(strpos($html, 'pta-class-race-finish') !== false, 'track has a finish line');
$t->check(strpos($html, 'assets/race/wolf-') !== false, 'each lane uses a rendered wolf marker');
$t->check(strpos($html, 'left: calc(10px + (100% - 124px) * 10 / 100)') !== false, 'wolf marker is placed by percent');
$t->check(strpos($html, 'pta-class-race-trail') !== false, 'each lane has a trail');
$t->check(strpos($html, 'width: calc(62px + (100% - 124px) * 10 / 100)') !== false, 'trail reaches the wolf');
$t->check(strpos($html, 'pta-class-race-grade') === false, 'grade line is gone from the lane');
$t->check(strpos($html, 'Distance is % of class donated') !== false, 'kicker copy updated');
$t->check(strpos($html, 'pta-class-race-link') === false, 'board is not a link by default');

$t->equals('https://wilderptsa.net', Azure_Class_Competitions::sanitize_link('https://wilderptsa.net'), 'https link is kept');
$t->equals('', Azure_Class_Competitions::sanitize_link(''), 'empty link is dropped');
$t->equals('', Azure_Class_Competitions::sanitize_link('javascript:alert(1)'), 'javascript link is dropped');
$t->equals('', Azure_Class_Competitions::sanitize_link('wilderptsa.net'), 'bare host without scheme is dropped');

$linked = Azure_Class_Competitions::render_table(
    array('name' => 'WAG classrooms', 'show_count' => true, 'show_percent' => true),
    $rows,
    'https://wilderptsa.net/giving/'
);
$t->check(strpos($linked, '<a class="pta-class-race-link" href="https://wilderptsa.net/giving/"') === 0, 'enable_link wraps the board in an anchor');
$t->check(substr(trim($linked), -4) === '</a>', 'anchor is closed after the board');
$t->check(strpos($linked, 'pta-class-race--linked') !== false, 'linked board gets a hover class');
$t->check(strpos($html, '10%') !== false, 'percent renders in the score block');

exit($t->finish() === 0 ? 0 : 1);
