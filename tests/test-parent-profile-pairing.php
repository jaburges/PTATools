<?php
/**
 * Parent profile pairing: Parent 2 directory opt-in sits beside Parent 1.
 *
 * Run: php tests/test-parent-profile-pairing.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-user-children.php';

$t = new TestRunner('Parent profile pairing');

$t->check(
    Azure_User_Children::is_directory_opt_in_field(array(
        'key'   => 'pta_pf_parent_1_opt_in',
        'label' => 'List me in the parent directory',
    )),
    'canonical parent 1 opt-in is detected'
);
$t->check(
    Azure_User_Children::is_directory_opt_in_field(array(
        'key'   => 'pta_pf_check_here_to_opt_in_parent_2',
        'label' => 'Check here to Opt-In Parent 2 to Parent and Staff Only Directory',
    )),
    'live Parent 2 label is detected even with a custom key'
);
$t->check(
    !Azure_User_Children::is_directory_opt_in_field(array(
        'key'   => 'pta_pf_emergency_contact_name',
        'label' => 'Emergency Contact Name',
    )),
    'emergency contact is not treated as an opt-in'
);

$parent_only = Azure_User_Children::pair_parent_profile_fields(array(
    array('key' => 'pta_pf_parent_1_cell', 'label' => 'Parent 1 Mobile', 'type' => 'text'),
    array('key' => 'pta_pf_parent_2_cell', 'label' => 'Parent 2 Cell', 'type' => 'text'),
    array(
        'key'   => 'pta_pf_parent_1_opt_in',
        'label' => 'Check here to Opt-In to Parent and Staff Only Directory',
        'type'  => 'checkbox',
    ),
));
$t->equals(2, count($parent_only['pairs']), 'cell + opt-in rows when Parent 2 opt-in is missing');
$t->equals('pta_pf_parent_1_opt_in', $parent_only['pairs'][1]['left']['key'], 'parent 1 opt-in is the left cell');
$t->equals(null, $parent_only['pairs'][1]['right'], 'right cell is empty when Parent 2 opt-in is not in the parent list');

$live = Azure_User_Children::pair_parent_profile_fields(array(
    array('key' => 'pta_pf_parent_1_email', 'label' => 'Parent 1 Email', 'type' => 'email'),
    array('key' => 'pta_pf_parent_2_email', 'label' => 'Parent 2 Email', 'type' => 'email'),
    array('key' => 'pta_pf_parent_1_cell', 'label' => 'Parent 1 Mobile', 'type' => 'text'),
    array('key' => 'pta_pf_parent_2_cell', 'label' => 'Parent 2 Cell', 'type' => 'text'),
    array(
        'key'   => 'pta_pf_parent_1_opt_in',
        'label' => 'Check here to Opt-In to Parent and Staff Only Directory',
        'type'  => 'checkbox',
    ),
    array(
        'key'   => 'pta_pf_check_here_to_opt_in_parent_2',
        'label' => 'Check here to Opt-In Parent 2 to Parent and Staff Only Directory',
        'type'  => 'checkbox',
    ),
));
$t->equals(3, count($live['pairs']), 'email, cell, and opt-in each get a row');
$opt = $live['pairs'][2];
$t->equals('pta_pf_parent_1_opt_in', $opt['left']['key'], 'parent 1 opt-in stays on the left');
$t->equals('pta_pf_check_here_to_opt_in_parent_2', $opt['right']['key'], 'parent 2 opt-in is paired on the right');
$t->equals(
    'Check here to Opt-In Parent 2 to Parent and Staff Only Directory',
    $opt['right']['label'],
    'parent 2 keeps its configured label'
);
$t->equals(0, count($live['tail']), 'paired opt-ins are not dumped into the single-column tail');

$family_emergency = array(
    array('key' => 'pta_pf_emergency_contact_name', 'label' => 'Emergency Contact Name'),
    array('key' => 'pta_pf_check_here_to_opt_in_parent_2', 'label' => 'Check here to Opt-In Parent 2 to Parent and Staff Only Directory'),
);
$kept = 0;
foreach ($family_emergency as $field) {
    if (!Azure_User_Children::is_directory_opt_in_field($field)) {
        $kept++;
    }
}
$t->equals(1, $kept, 'family section keeps emergency contact and drops the Parent 2 opt-in');

$t->check(
    Azure_User_Children::is_staff_profile_field(array('key' => 'pta_pf_staff_first_name', 'label' => 'First Name')),
    'staff first name is a staff field'
);
$t->check(
    !Azure_User_Children::belongs_on_parent_profile(array('key' => 'pta_pf_staff_email', 'label' => 'Email')),
    'staff email stays off the parent profile'
);
$t->check(
    Azure_User_Children::belongs_on_parent_profile(array('key' => 'pta_pf_parent_1_name', 'label' => 'Parent 1 Name')),
    'parent 1 name stays on the parent profile'
);
$t->check(
    Azure_User_Children::is_parent_contact_duplicate_field(array('key' => 'pta_pf_parent_2_cell_2', 'label' => 'Parent 2 Mobile')),
    'numbered Parent 2 Mobile is a duplicate of Parent 2 Cell'
);
$t->check(
    !Azure_User_Children::is_parent_contact_duplicate_field(array('key' => 'pta_pf_emergency_contact_cell', 'label' => 'Emergency Contact Cell')),
    'emergency contact cell is kept'
);

$dupes = Azure_User_Children::pair_parent_profile_fields(array(
    array('key' => 'pta_pf_parent_2_cell', 'label' => 'Parent 2 Cell', 'type' => 'text'),
    array('key' => 'pta_pf_parent_2_cell_2', 'label' => 'Parent 2 Mobile', 'type' => 'tel'),
    array('key' => 'pta_pf_staff_first_name', 'label' => 'First Name', 'type' => 'text'),
    array('key' => 'pta_pf_staff_phone', 'label' => 'Phone', 'type' => 'tel'),
));
$t->equals(1, count($dupes['pairs']), 'cell pair is created from the canonical Parent 2 Cell');
$t->equals('pta_pf_parent_2_cell', $dupes['pairs'][0]['right']['key'], 'canonical cell wins over Parent 2 Mobile');
$t->equals(0, count($dupes['tail']), 'staff fields and Parent 2 Mobile are not repeated under the grid');

exit($t->finish() === 0 ? 0 : 1);
