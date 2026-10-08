<?php
/**
 * Regression checks for the wp_azure_onedrive_files mapping table.
 *
 * The mapping row is what ties a WordPress attachment to its OneDrive backup.
 * Backups run from a queue after the attachment exists, so the row is written
 * already linked; an attachment that has a row is not queued again. Deleting
 * the attachment keeps the OneDrive copy unless delete propagation is on. The
 * row is also written on every sync pass, so it has to tolerate being stored
 * twice for the same OneDrive file — the column carries a UNIQUE key, and a
 * plain INSERT just fails.
 *
 * Run:  php tests/test-onedrive-media-mapping.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/../Azure Plugin/includes/class-onedrive-media-manager.php';

$t = new TestRunner('OneDrive Media mapping table');

const MAP_TABLE   = 'wp_azure_onedrive_files';
const QUEUE_TABLE = 'wp_azure_onedrive_sync_queue';

if (!function_exists('get_attached_file')) {
    function get_attached_file($attachment_id) {
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        return $file ? '/tmp/wp-uploads/' . $file : false;
    }
}

/** Records what the manager asks Graph to do, without any network. */
class Stub_Graph_API {
    public $deleted = array();
    public $delete_result = true;
    public function delete_file($file_id) {
        $this->deleted[] = $file_id;
        return $this->delete_result;
    }
}

/** Fake_WPDB plus the raw query() the backup queue's upsert uses. */
class Queue_WPDB extends Fake_WPDB {
    public $queued = array();
    public function query($sql) {
        if (strpos($sql, QUEUE_TABLE) !== false && stripos($sql, 'INSERT') !== false) {
            $this->queued[] = (int) $this->prepare_args[0];
        }
        $this->prepare_args = array();
        return 1;
    }
}

function make_manager() {
    global $wpdb;
    WP_Shim::reset();
    // Disabled so the constructor returns before touching cron or Graph auth;
    // the collaborator is injected directly instead.
    WP_Shim::$settings['enable_onedrive_media'] = false;
    $wpdb = new Queue_WPDB();
    $wpdb->tables[MAP_TABLE] = array();
    $wpdb->tables[QUEUE_TABLE] = array();
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
// 1. A backup stores a mapping row linked to its attachment
// ---------------------------------------------------------------------------

$mgr = make_manager();
write_private($mgr, 'graph_api', new Stub_Graph_API());

// backup_attachment() runs from the queue, after the attachment exists.
call_private($mgr, 'store_file_mapping', array(4321, od_file(), '/tmp/wp-uploads/2026/07/photo.png'));

$rows = map_rows();
$t->equals(1, count($rows), 'the backup writes exactly one mapping row');
$t->equals(4321, (int) $rows[0]['attachment_id'], 'the mapping row is linked to the attachment');
$t->equals('01ONEDRIVEID', $rows[0]['onedrive_id'], 'the OneDrive file id is recorded');
$t->equals('https://contoso.sharepoint.com/photo.png', $rows[0]['public_url'], 'the OneDrive web URL is recorded so the edit screen can link to it');
$t->equals('2026', $rows[0]['folder_year'], 'the folder year comes from the OneDrive path, not today');

// add_attachment queues new uploads, but not one that is already backed up.
update_post_meta(4321, '_wp_attached_file', '2026/07/photo.png');
update_post_meta(4322, '_wp_attached_file', '2026/07/flyer.pdf');
update_post_meta(4323, '_wp_attached_file', '2026/07/photo-300x200.png');
$mgr->queue_attachment_backup(4321);
$mgr->queue_attachment_backup(4322);
$mgr->queue_attachment_backup(4323);
global $wpdb;
$t->equals(array(4322), $wpdb->queued, 'only the new, non-thumbnail upload is queued for backup');

// ---------------------------------------------------------------------------
// 2. Deleting the attachment keeps the OneDrive backup by default
// ---------------------------------------------------------------------------

$graph = new Stub_Graph_API();
write_private($mgr, 'graph_api', $graph);
$wpdb->tables[QUEUE_TABLE][] = array('id' => 1, 'operation' => 'backup', 'attachment_id' => 4321, 'status' => 'pending');

$mgr->handle_delete_attachment(4321);

$rows = map_rows();
$t->equals(array(), $graph->deleted, 'the OneDrive copy is not deleted');
$t->equals(1, count($rows), 'the mapping row is kept so the backup can still be found');
$t->equals('orphaned', $rows[0]['sync_status'], 'the mapping row is marked orphaned');
$t->equals(null, $rows[0]['attachment_id'], 'the mapping row is unlinked from the deleted attachment');
$t->equals(0, count($wpdb->tables[QUEUE_TABLE]), 'a pending backup for the deleted attachment is dropped');

// With delete propagation on, the OneDrive copy goes too.
$mgr = make_manager();
WP_Shim::$settings['onedrive_media_delete_propagation'] = true;
$graph = new Stub_Graph_API();
write_private($mgr, 'graph_api', $graph);
call_private($mgr, 'store_file_mapping', array(98, od_file()));
$mgr->handle_delete_attachment(98);
$t->equals(array('01ONEDRIVEID'), $graph->deleted, 'with propagation on, deleting the attachment deletes the OneDrive file');
$t->equals(0, count(map_rows()), 'the mapping row is removed once OneDrive confirms the delete');

// If OneDrive refuses, the mapping row must survive so it can be retried.
$mgr = make_manager();
WP_Shim::$settings['onedrive_media_delete_propagation'] = true;
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

exit($t->finish() > 0 ? 1 : 0);
