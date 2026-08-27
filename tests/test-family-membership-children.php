<?php
/**
 * Family PTSA membership: auto-include PreK–5 kids and sanitize roster rows.
 *
 * Run: php tests/test-family-membership-children.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-user-children.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-product-fields-module.php';

$t = new TestRunner('Family membership children');

WP_Shim::reset();
WP_Shim::$settings['membership_family_product_ids'] = array(100, 200);

$t->check(Azure_Product_Fields_Module::is_family_membership_product(100), 'configured family product is detected');
$t->check(Azure_Membership_Module::product_is_family(100), 'exact family product id matches');
$t->check(Azure_Membership_Module::product_is_family(55, 200), 'variation parent is a family product');
$t->check(!Azure_Membership_Module::product_is_family(55), 'unrelated product is not family');
$t->check(!Azure_Membership_Module::product_is_family(0), 'zero is not family');

$t->check(Azure_User_Children::include_on_family_membership(array('pta_pf_childsgrade' => 'K')), 'K is included');
$t->check(Azure_User_Children::include_on_family_membership(array('pta_pf_child_grade' => 'PreK')), 'PreK is included');
$t->check(Azure_User_Children::include_on_family_membership(array('pta_pf_childsgrade' => '5')), '5th is included');
$t->check(Azure_User_Children::include_on_family_membership(array()), 'ungraded child is included');
$t->check(!Azure_User_Children::include_on_family_membership(array('pta_pf_childsgrade' => '6')), '6th is excluded');
$t->check(!Azure_User_Children::include_on_family_membership(array('pta_pf_childsgrade' => '8th')), '8th is excluded');

$t->equals('Congdon', Azure_User_Children::teacher_from_meta(array('pta_pf_child_teacher' => 'Congdon')), 'teacher from canonical meta');
$t->equals('K', Azure_User_Children::grade_from_meta(array('pta_pf_childsgrade' => 'K')), 'grade from live-site key');

$dirty = Azure_Product_Fields_Module::sanitize_family_children(array(
    array('id' => '12', 'name' => '<b>Ada</b>', 'grade' => 'K', 'teacher' => 'Congdon'),
    array('id' => 12, 'name' => 'Ada again', 'grade' => '1', 'teacher' => 'Lee'),
    array('id' => 0, 'name' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 0, 'name' => 'Ben', 'grade' => '2', 'teacher' => 'Patel'),
));
$t->equals(2, count($dirty), 'empty rows dropped and duplicate id collapsed');
$t->equals('Ada', $dirty[0]['name'], 'name strips tags');
$t->equals(12, $dirty[0]['id'], 'id is int');
$t->equals('Ben', $dirty[1]['name'], 'guest child kept');

$name_field = (object) array('field_key' => 'child_name', 'label' => "Child's Name", 'scope' => 'child');
$grade_field = (object) array('field_key' => 'childsgrade', 'label' => 'Grade', 'scope' => 'child');
$teacher_field = (object) array('field_key' => 'child_teacher', 'label' => 'Teacher', 'scope' => 'child');
$optin_field = (object) array('field_key' => 'parent_1_opt_in', 'label' => 'Directory opt-in', 'scope' => 'parent');
$t->check(Azure_Product_Fields_Module::is_family_child_core_field($name_field), 'child name is a roster field');
$t->check(Azure_Product_Fields_Module::is_family_child_core_field($grade_field), 'grade is a roster field');
$t->check(Azure_Product_Fields_Module::is_family_child_core_field($teacher_field), 'teacher is a roster field');
$t->check(!Azure_Product_Fields_Module::is_family_child_core_field($optin_field), 'parent opt-in stays on the form');

exit($t->finish() === 0 ? 0 : 1);
