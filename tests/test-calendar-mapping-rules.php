<?php
/**
 * Calendar mapping: single category vs term rules.
 *
 * Run: php tests/test-calendar-mapping-rules.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return trim(strip_tags((string) $string));
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-mapping-manager.php';

$t = new TestRunner('Calendar mapping rules');

$t->equals('single', Azure_Calendar_Mapping_Manager::sanitize_mapping_mode(''), 'empty mode is single');
$t->equals('rules', Azure_Calendar_Mapping_Manager::sanitize_mapping_mode('rules'), 'rules mode kept');

$dirty = Azure_Calendar_Mapping_Manager::sanitize_category_rules(array(
    array('term' => 'WAPTA', 'look_in' => 'body', 'category_name' => 'WAPTA Council', 'category_id' => '12'),
    array('term' => '', 'look_in' => 'subject', 'category_name' => 'Nope'),
    array('term' => 'LWPTSA', 'look_in' => 'not-a-place', 'category_name' => 'LWPTSA'),
));
$t->equals(2, count($dirty), 'blank-term rows dropped');
$t->equals('body', $dirty[0]['look_in'], 'body look_in kept');
$t->equals(12, $dirty[0]['category_id'], 'category id is int');
$t->equals('subject', $dirty[1]['look_in'], 'invalid look_in falls back to subject');

$event = array(
    'title'       => 'LWPTSA Board meeting',
    'description' => '<p>Notes for the WAPTA workshop and snacks.</p>',
);

$t->check(
    Azure_Calendar_Mapping_Manager::event_matches_rule($event, array('term' => 'WAPTA', 'look_in' => 'body')),
    'WAPTA matches in HTML body after tags are stripped'
);
$t->check(
    !Azure_Calendar_Mapping_Manager::event_matches_rule($event, array('term' => 'WAPTA', 'look_in' => 'subject')),
    'WAPTA does not match subject-only'
);
$t->check(
    Azure_Calendar_Mapping_Manager::event_matches_rule($event, array('term' => 'lwptsa', 'look_in' => 'subject')),
    'subject match is case-insensitive'
);
$t->check(
    Azure_Calendar_Mapping_Manager::event_matches_rule($event, array('term' => 'WAPTA', 'look_in' => 'subject_or_body')),
    'subject_or_body sees the body hit'
);

$single = (object) array(
    'mapping_mode'  => 'single',
    'category_name' => 'All Meetings',
    'category_rules'=> '',
);
$t->equals(
    array('All Meetings'),
    Azure_Calendar_Mapping_Manager::categories_for_event($event, $single),
    'single mode always uses the mapped category'
);

$rules_map = (object) array(
    'mapping_mode'  => 'rules',
    'category_name' => 'Unsorted',
    'category_rules'=> Azure_Calendar_Mapping_Manager::encode_category_rules(array(
        array('term' => 'WAPTA', 'look_in' => 'body', 'category_name' => 'WAPTA'),
        array('term' => 'LWPTSA', 'look_in' => 'subject', 'category_name' => 'LWPTSA'),
    )),
);
$matched = Azure_Calendar_Mapping_Manager::categories_for_event($event, $rules_map);
sort($matched);
$t->equals(array('LWPTSA', 'WAPTA'), $matched, 'an event can receive every matching category');

$no_hit = Azure_Calendar_Mapping_Manager::categories_for_event(
    array('title' => 'Pizza night', 'description' => 'Just dinner'),
    $rules_map
);
$t->equals(array('Unsorted'), $no_hit, 'unmatched events use the fallback category');

$no_fallback = (object) array(
    'mapping_mode'  => 'rules',
    'category_name' => '',
    'category_rules'=> $rules_map->category_rules,
);
$t->equals(
    array(),
    Azure_Calendar_Mapping_Manager::categories_for_event(array('title' => 'Pizza', 'description' => ''), $no_fallback),
    'unmatched with no fallback stays uncategorized'
);

$t->equals(
    array('Legacy'),
    Azure_Calendar_Mapping_Manager::categories_for_event($event, 'Legacy'),
    'a plain category string still works for older sync callers'
);

exit($t->finish() === 0 ? 0 : 1);
