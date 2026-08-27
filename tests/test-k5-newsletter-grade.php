<?php
/**
 * Parent vs Alumni population from child grades.
 *
 * Parent  = any active child in PreK–5
 * Alumni  = every graded child is past 5th (grade 6+)
 * Unknown = no K–5 and no 6+ grades (blank / missing) — leave the role alone
 *
 * Run: php tests/test-k5-newsletter-grade.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-user-children.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-parent-role.php';

$t = new TestRunner('Parent vs Alumni population');

$in_school = array('PreK', 'PreK | K', 'pre-k', 'K', 'k', '1', '2', '3', '4', '5', '5th');
foreach ($in_school as $grade) {
    $t->check(
        Azure_User_Children::is_elementary_grade($grade),
        "grade {$grade} is K-5 or below"
    );
    $t->check(
        !Azure_User_Children::is_alumni_grade($grade),
        "grade {$grade} is not alumni"
    );
}

$alumni_grades = array('6', '6th', '7', '8', '12');
foreach ($alumni_grades as $grade) {
    $t->check(
        Azure_User_Children::is_alumni_grade($grade),
        "grade {$grade} is past 5th"
    );
    $t->check(
        !Azure_User_Children::is_elementary_grade($grade),
        "grade {$grade} is not K-5"
    );
}

$unknown = array('', ' ', 'middle', null);
foreach ($unknown as $grade) {
    $label = $grade === null ? 'null' : (string) $grade;
    $t->check(
        !Azure_User_Children::is_elementary_grade($grade) && !Azure_User_Children::is_alumni_grade($grade),
        "grade {$label} is neither K-5 nor alumni"
    );
}

$t->equals(
    'parent',
    Azure_User_Children::classify_population_from_grades(array('1')),
    'one K-5 child is Parent'
);
$t->equals(
    'parent',
    Azure_User_Children::classify_population_from_grades(array('5', '6')),
    'mixed 5th + past-5th stays Parent'
);
$t->equals(
    'alumni',
    Azure_User_Children::classify_population_from_grades(array('6')),
    'only past-5th is Alumni'
);
$t->equals(
    'alumni',
    Azure_User_Children::classify_population_from_grades(array('6', '7')),
    'multiple past-5th children are Alumni'
);
$t->equals(
    'alumni',
    Azure_User_Children::classify_population_from_grades(array('6', '')),
    'blank sibling does not block Alumni when the only graded child is past 5th'
);
$t->equals(
    null,
    Azure_User_Children::classify_population_from_grades(array()),
    'no children is unclassified'
);
$t->equals(
    null,
    Azure_User_Children::classify_population_from_grades(array('')),
    'blank grade only is unclassified'
);

$t->equals(
    array('customer', 'alumni'),
    Azure_Parent_Role::next_population_roles(array('parent', 'customer'), 'alumni'),
    'Parent + customer becomes Alumni + customer'
);
$t->equals(
    array('customer', 'parent'),
    Azure_Parent_Role::next_population_roles(array('alumni', 'customer'), 'parent'),
    'Alumni + customer becomes Parent + customer'
);
$t->equals(
    array('parent', 'customer'),
    Azure_Parent_Role::next_population_roles(array('parent', 'customer'), null),
    'unclassified grades leave Parent in place'
);
$t->equals(
    array('administrator'),
    Azure_Parent_Role::next_population_roles(array('administrator'), 'alumni'),
    'staff/admin without Parent/Alumni is not rewritten'
);

exit($t->finish() === 0 ? 0 : 1);
