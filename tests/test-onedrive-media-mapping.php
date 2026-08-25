<?php
/**
 * Regression checks for the wp_azure_onedrive_files mapping table.
 *
 * The mapping row is what ties a WordPress attachment to its OneDrive copy.
 * If it is never linked to an attachment ID, deleting media in WordPress
 * silently leaves the OneDrive file behind and the attachment edit screen
 * shows no OneDrive status. The row is also written on every sync pass, so it
 * has to tolerate being stored twice for the same OneDrive file — the column
 * carries a UNIQUE key, and a plain INSERT just fails.
 *
 * Run:  php tests/test-onedrive-media-mapping.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/../Azure Plugin/includes/class-onedrive-media-manager.php';

$t = new TestRunner('OneDrive Media mapping table');

const MAP_TABLE = 'wp_azure_onedrive_files';

/** Records what the manager asks Graph to do, without any network. */
class Stub_Graph_API {
    public $deleted = array();
    public $delete_result = true;
    public function delete_file($file_id) {
        $this->deleted[] = $file_id;
        return $this->delete_result;
    }
}

function make_manager() {
    global $wpdb;
    WP_Shim::reset();
    // Disabled so the constructor returns before touching cron or Graph auth;
    // the collaborator is injected directly instead.
    WP_Shim::$settings['enable_onedrive_media'] = false;
    $wpdb = new Fake_WPDB();
    $wpdb->tables[MAP_TABLE] = array();
    return new Azure_OneDrive_Media_Manager();
}

function map_rows() {
    global $wpdb;
    return $wpdb->tables[MAP_TABLE];
}

/** A Graph-formatted file, as format_file_data() produces. */
function od_file($overrides = array()) {
    return array_merge(array(
        'id'           => '01ONEDRIVEID',
        'name'         => 'photo.png',
        'size'         => 4096,
        'mime_type'    => 'image/png',
        'modified'     => '2026-07-01T10:00:00Z',
        'web_url'      => 'https://contoso.sharepoint.com/photo.png',
        'download_url' => 'https://download.example/one',
        'parent_path'  => '/drives/b!x/root:/WordPress Media/2026',
        'is_folder'    => false,
    ), $overrides);
}

// ---------------------------------------------------------------------------
// 1. An upload stores a mapping row, and the attachment links to it afterwards
// ---------------------------------------------------------------------------

$mgr = make_manager();

// This is what handle_upload_to_onedrive() does: the wp_handle_upload filter
// fires before wp_insert_attachment, so there is no attachment ID yet.
call_private($mgr, 'store_file_mapping', array(null, od_file(), '/tmp/wp-uploads/2026/07/photo.png'));

$rows = map_rows();
$t->equals(1, count($rows), 'the upload writes exactly one mapping row');
$t->equals('01ONEDRIVEID', $rows[0]['onedrive_id'], 'the OneDrive file id is recorded');
$t->equals('https://contoso.sharepoint.com/photo.png', $rows[0]['public_url'], 'the OneDrive web URL is recorded so the edit screen can link to it');
$t->equals('2026', $rows[0]['folder_year'], 'the folder year comes from the OneDrive path, not today');

// Now WordPress creates the attachment post and fires add_attachment.
update_post_meta(4321, '_wp_attached_file', '2026/07/photo.png');
$mgr->link_pending_file_mapping(4321);

$t->equals(4321, (int) map_rows()[0]['attachment_id'], 'the mapping row is linked to the new attachment');

// ---------------------------------------------------------------------------
// 2. Deleting the attachment now propagates to OneDrive
// ---------------------------------------------------------------------------

$graph = new Stub_Graph_API();
write_private($mgr, 'graph_api', $graph);

$mgr->handle_delete_attachment(4321);

$t->equals(array('01ONEDRIVEID'), $graph->deleted, 'deleting the attachment deletes the OneDrive file');
$t->equals(0, count(map_rows()), 'the mapping row is removed once OneDrive confirms the delete');

// If OneDrive refuses, the mapping row must survive so it can be retried.
$mgr = make_manager();
$graph = new Stub_Graph_API();
$graph->delete_result = false;
write_private($mgr, 'graph_api', $graph);
call_private($mgr, 'store_file_mapping', array(99, od_file()));
$mgr->handle_delete_attachment(99);
$t->equals(1, count(map_rows()), 'a failed OneDrive delete leaves the mapping row in place');

// ---------------------------------------------------------------------------
// 3. Re-storing the same OneDrive file updates instead of failing
// ---------------------------------------------------------------------------

$mgr = make_manager();
call_private($mgr, 'store_file_mapping', array(11, od_file()));
call_private($mgr, 'store_file_mapping', array(11, od_file(array('download_url' => 'https://download.example/two'))));

$rows = map_rows();
$t->equals(1, count($rows), 'storing the same OneDrive file twice does not duplicate the row');
$t->equals('https://download.example/two', $rows[0]['download_url'], 'the refreshed download URL replaces the expired one');

// A later sync pass that has no attachment ID must not unlink an existing one.
call_private($mgr, 'store_file_mapping', array(null, od_file(array('download_url' => 'https://download.example/three'))));
$rows = map_rows();
$t->equals(1, count($rows), 'a sync pass without an attachment ID still updates in place');
$t->equals(11, (int) $rows[0]['attachment_id'], 'an already-linked attachment ID is preserved');
$t->equals('https://download.example/three', $rows[0]['download_url'], 'the download URL is still refreshed');

// ---------------------------------------------------------------------------
// 4. Guard rails
// ---------------------------------------------------------------------------

$mgr = make_manager();
$t->equals(false, call_private($mgr, 'store_file_mapping', array(1, od_file(array('id' => '')))), 'a file with no OneDrive id is rejected');
$t->equals(0, count(map_rows()), 'no row is written for a file with no OneDrive id');

// A sparse Graph payload must not raise warnings or write nulls into NOT NULL columns.
$mgr = make_manager();
call_private($mgr, 'store_file_mapping', array(7, array('id' => '01SPARSE', 'name' => 'x.bin')));
$row = map_rows()[0];
$t->equals('', $row['onedrive_path'], 'a missing parent path stores as an empty string');
$t->equals(0, $row['file_size'], 'a missing size stores as zero');
$t->equals('', $row['download_url'], 'a missing download URL stores as an empty string');

// link_pending_file_mapping must not hijack a row that is already linked.
$mgr = make_manager();
call_private($mgr, 'store_file_mapping', array(500, od_file()));
update_post_meta(600, '_wp_attached_file', '2026/07/photo.png');
$mgr->link_pending_file_mapping(600);
$t->equals(500, (int) map_rows()[0]['attachment_id'], 'an already-linked row is not stolen by a later upload of the same name');

exit($t->finish() > 0 ? 1 : 0);
