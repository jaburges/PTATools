<?php
/**
 * Role-description seed matcher: PDF titles map onto existing PTA role names.
 *
 * Run: php tests/test-role-description-matcher.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-pta-role-descriptions.php';

$t = new TestRunner('Role description matcher');

$t->equals('vp communications and technology', Azure_PTA_Role_Descriptions::normalize_key('VP, Communications & Technology'), 'ampersand and comma normalize');
$t->equals('5th grade farewell', Azure_PTA_Role_Descriptions::normalize_key('5th Grade Farewell'), 'grade titles stay readable');

function role_row($name, $slug) {
    $role = new stdClass();
    $role->id = 1;
    $role->name = $name;
    $role->slug = $slug;
    return $role;
}

$catalog = array(
    role_row('President', 'president'),
    role_row('Executive VP', 'executive-vp'),
    role_row('VP Communications', 'vp-communications'),
    role_row('VP Ways and Means', 'vp-ways-and-means'),
    role_row('VP Events', 'vp-events'),
    role_row('VP Volunteers', 'vp-volunteers'),
    role_row('VP Enrichment - During School', 'vp-enrichment-during-school'),
    role_row('VP Enrichment - After School', 'vp-enrichment-after-school'),
    role_row('VP Enrichment - Performing Arts', 'vp-enrichment-performing-arts'),
    role_row('Celebration Books', 'celebration-books'),
    role_row('Game Night', 'game-night'),
    role_row('STEM Night', 'stem-night'),
    role_row('Web Master', 'web-master'),
    role_row('Annual Giving Campaign', 'annual-giving-campaign'),
    role_row('Dance Week/Spring Dance', 'dance-weekspring-dance'),
    role_row('Theater', 'theater'),
    role_row('Concessions', 'concessions'),
    role_row('Family Night Outs', 'family-night-outs'),
);

$seed_path = dirname(__DIR__) . '/Azure Plugin/data/role-descriptions.json';
$t->check(file_exists($seed_path), 'seed JSON is present');
$seed = json_decode(file_get_contents($seed_path), true);
$t->check(is_array($seed) && !empty($seed['roles']), 'seed JSON has roles');
$t->check(count($seed['roles']) >= 50, 'seed covers the board and chair packet', (string) count($seed['roles']));

$must_match = array(
    'President' => 'President',
    'Executive Vice President' => 'Executive VP',
    'VP, Communications & Technology' => 'VP Communications',
    'VP, Ways & Means' => 'VP Ways and Means',
    'VP, Community Events' => 'VP Events',
    'VP, Volunteers' => 'VP Volunteers',
    'VP, Enrichment – During School' => 'VP Enrichment - During School',
    'VP, Enrichment – Before & After School' => 'VP Enrichment - After School',
    'VP, Performing Arts' => 'VP Enrichment - Performing Arts',
    'Library Liaison' => 'Celebration Books',
    'Family Game Night' => 'Game Night',
    'STEM Fair' => 'STEM Night',
    'Store/Web Manager' => 'Web Master',
    'Wilder About Giving' => 'Annual Giving Campaign',
    'School Dance' => 'Dance Week/Spring Dance',
    'Theater Program' => 'Theater',
    'Concessions Management' => 'Concessions',
    'Family Nights Out' => 'Family Night Outs',
);

$by_title = array();
foreach ($seed['roles'] as $row) {
    $by_title[$row['title']] = $row;
}

foreach ($must_match as $pdf_title => $expected_name) {
    $t->check(isset($by_title[$pdf_title]), 'seed includes ' . $pdf_title);
    if (!isset($by_title[$pdf_title])) {
        continue;
    }
    $matched = Azure_PTA_Role_Descriptions::match_seed_to_role($by_title[$pdf_title], $catalog);
    $t->equals($expected_name, $matched ? $matched->name : null, $pdf_title . ' maps to ' . $expected_name);
}

$unmatched = Azure_PTA_Role_Descriptions::match_seed_to_role(
    array('title' => 'No Such Role', 'aliases' => array('zzz-missing')),
    $catalog
);
$t->check($unmatched === null, 'unknown titles do not attach to a random role');

$required = array('description', 'time_commitment', 'point_of_contact', 'responsibilities');
$missing_fields = 0;
foreach ($seed['roles'] as $row) {
    foreach ($required as $field) {
        if (!isset($row[$field]) || $row[$field] === '' || $row[$field] === array()) {
            $missing_fields++;
        }
    }
}
$t->equals(0, $missing_fields, 'every seed role has description, responsibilities, time, and contact');

$search_role = role_row('Book Fair', 'book-fair');
$search_role->department_name = 'Ways and Means';
$search_role->description = 'Foster a love of reading';
$search_role->time_commitment = '4 hours per week';
$search_role->point_of_contact = 'School librarian';
$search_role->pro_tip = 'Use ethernet cords';
$search_role->responsibilities = array(
    array('heading' => 'Inventory', 'body' => 'Manage reorders during the fair'),
);
$hay = Azure_PTA_Role_Descriptions::search_haystack($search_role);
$t->check(strpos($hay, 'book fair') !== false, 'haystack includes the role name');
$t->check(strpos($hay, 'ways and means') !== false, 'haystack includes the department');
$t->check(strpos($hay, 'ethernet') !== false, 'haystack includes the pro tip');
$t->check(strpos($hay, 'reorders') !== false, 'haystack includes responsibilities');
$t->check(strpos($hay, '4 hours') !== false, 'haystack includes time commitment');

exit($t->finish() === 0 ? 0 : 1);
