<?php
/**
 * Product Fields admin: the drag handles reorder a group's fields and the
 * new order is saved.
 *
 * Run: php tests/test-product-fields-reorder.php
 */

require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('Product Fields reorder');

$page = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/product-fields-page.php');
$admin = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-product-fields-admin.php');

$t->check(strpos($page, "wp_enqueue_script('jquery-ui-sortable');") !== false, 'the page loads jQuery UI Sortable');
$t->check(strpos($page, "\$('#azure-pf-fields-body').sortable(") !== false, 'the fields table body is sortable');
$t->check(strpos($page, "handle: '.dashicons-move'") !== false, 'rows are dragged by the move handle');
$t->check(strpos($page, "action: 'azure_pf_reorder_fields'") !== false, 'dropping a row saves the new order');
$t->check(strpos($admin, "add_action('wp_ajax_azure_pf_reorder_fields'") !== false, 'the reorder endpoint is registered');

exit($t->finish() === 0 ? 0 : 1);
