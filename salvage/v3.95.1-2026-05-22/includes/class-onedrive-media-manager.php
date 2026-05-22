<?php
/**
 * OneDrive Media Manager - Main Orchestration Class
 * Manages WordPress Media Library integration with OneDrive/SharePoint
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_OneDrive_Media_Manager {
    
    private static $instance = null;
    private $auth;
    private $graph_api;
    private $enabled;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_OneDrive_Media_Manager();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Check if module is enabled
        $this->enabled = Azure_Settings::is_module_enabled('onedrive_media');
        
        if (!$this->enabled) {
            return;
        }
        
        // Initialize dependencies
        if (class_exists('Azure_OneDrive_Media_Auth')) {
            $this->auth = new Azure_OneDrive_Media_Auth();
        }
        
        if (class_exists('Azure_OneDrive_Media_GraphAPI')) {
            $this->graph_api = new Azure_OneDrive_Media_GraphAPI();
        }
        
        // Hook into WordPress media upload
        add_filter('wp_handle_upload_prefilter', array($this, 'intercept_upload'), 10, 1);
        add_filter('wp_handle_upload', array($this, 'handle_upload_to_onedrive'), 10, 2);
        add_action('delete_attachment', array($this, 'handle_delete_attachment'), 10, 1);
        
        // Add custom fields to attachment
        add_filter('attachment_fields_to_edit', array($this, 'add_onedrive_fields'), 10, 2);
        // Never override local URLs — files live on disk at /wp-content/uploads/YYYY/MM/.
        // SharePoint/OneDrive links are stored in the DB for sync management only.
        
        // Register AJAX handlers
        add_action('wp_ajax_onedrive_media_sync_from_onedrive', array($this, 'ajax_sync_from_onedrive'));
        add_action('wp_ajax_onedrive_media_browse_folders', array($this, 'ajax_browse_folders'));
        add_action('wp_ajax_onedrive_media_create_folder', array($this, 'ajax_create_folder'));
        add_action('wp_ajax_onedrive_media_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_onedrive_media_list_sharepoint_sites', array($this, 'ajax_list_sharepoint_sites'));
        add_action('wp_ajax_onedrive_media_list_sharepoint_drives', array($this, 'ajax_list_sharepoint_drives'));
        add_action('wp_ajax_onedrive_media_resolve_sharepoint_site', array($this, 'ajax_resolve_sharepoint_site'));
        add_action('wp_ajax_onedrive_media_create_year_folders', array($this, 'ajax_create_year_folders'));
        add_action('wp_ajax_onedrive_media_import_from_onedrive', array($this, 'ajax_import_from_onedrive'));
        add_action('wp_ajax_onedrive_media_repair_diagnose', array($this, 'ajax_repair_diagnose'));
        
        // Schedule WordPress Cron for auto-sync
        if (!wp_next_scheduled('onedrive_media_auto_sync')) {
            $frequency = Azure_Settings::get_setting('onedrive_media_sync_frequency', 'hourly');
            wp_schedule_event(time(), $frequency, 'onedrive_media_auto_sync');
        }
        add_action('onedrive_media_auto_sync', array($this, 'run_auto_sync'));

        add_action('wp_ajax_onedrive_media_repair_guids', array($this, 'ajax_repair_sharepoint_guids'));
    }
    
    /**
     * Intercept file upload before processing
     */
    public function intercept_upload($file) {
        // Validate file before upload
        $max_size = Azure_Settings::get_setting('onedrive_media_max_file_size', 4294967296); // 4GB default
        
        if ($file['size'] > $max_size) {
            $file['error'] = 'File size exceeds OneDrive limit';
            return $file;
        }
        
        return $file;
    }
    
    /**
     * AJAX: Repair attachments whose guid/URL is a SharePoint/OneDrive URL.
     */
    public function ajax_repair_sharepoint_guids() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, guid FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND (guid LIKE '%sharepoint.com%' OR guid LIKE '%onedrive.com%' OR guid LIKE '%1drv.ms%')"
        );

        if (empty($rows)) {
            wp_send_json_success(array('fixed' => 0, 'total' => 0, 'message' => 'No attachments with SharePoint/OneDrive URLs found.'));
            return;
        }

        $upload_dir = wp_upload_dir();
        $baseurl = $upload_dir['baseurl'];
        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $relative = get_post_meta($row->ID, '_wp_attached_file', true);

            if ($relative && preg_match('|^https?://|', $relative)) {
                $meta = wp_get_attachment_metadata($row->ID);
                if (!empty($meta['file'])) {
                    $relative = $meta['file'];
                } else {
                    $filename = basename(parse_url($relative, PHP_URL_PATH));
                    $relative = $filename ?: '';
                }
                if ($relative && !preg_match('|^https?://|', $relative)) {
                    update_post_meta($row->ID, '_wp_attached_file', $relative);
                }
            }

            if (!$relative || preg_match('|^https?://|', $relative)) {
                $skipped++;
                continue;
            }

            $correct_url = $baseurl . '/' . ltrim($relative, '/');
            if ($correct_url !== $row->guid) {
                $wpdb->update($wpdb->posts, array('guid' => $correct_url), array('ID' => $row->ID));
                $fixed++;
            }
        }

        $total = count($rows);
        if ($fixed > 0) {
            Azure_Logger::info("OneDrive Media: Repaired {$fixed} of {$total} attachment(s) with SharePoint URLs");
        }

        wp_send_json_success(array(
            'fixed'   => $fixed,
            'skipped' => $skipped,
            'total'   => $total,
            'message' => $fixed > 0
                ? "Repaired {$fixed} attachment URL(s)."
                : "All {$total} attachment(s) already have correct URLs.",
        ));
    }

    /**
     * Handle file upload to OneDrive after WordPress processes it
     */
    public function handle_upload_to_onedrive($upload, $context) {
        if (!$this->graph_api) {
            return $upload;
        }

        // Only upload on genuine user uploads, not sideloads from OneDrive
        // sync, repair, or other programmatic imports
        if ($context === 'sideload') {
            return $upload;
        }

        $local_file = $upload['file'];
        $file_name = basename($local_file);

        // Don't upload WP-generated thumbnails to OneDrive
        if ($this->is_wp_thumbnail($file_name)) {
            return $upload;
        }
        
        // Determine folder based on year setting
        $use_year_folders = Azure_Settings::get_setting('onedrive_media_use_year_folders', true);
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        
        if ($use_year_folders) {
            $year = date('Y');
            $remote_path = $base_folder . '/' . $year;
        } else {
            $remote_path = $base_folder;
        }
        
        // Upload to OneDrive
        $file_data = $this->graph_api->upload_file($local_file, $remote_path, $file_name);
        
        if ($file_data) {
            $this->store_file_mapping(null, $file_data, $local_file);
            Azure_Logger::info('OneDrive Media: File uploaded successfully - ' . $file_name);
        } else {
            Azure_Logger::error('OneDrive Media: Failed to upload file - ' . $file_name);
        }
        
        return $upload;
    }
    
    /**
     * Handle attachment deletion
     */
    public function handle_delete_attachment($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        // Get OneDrive file info
        $file_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        if ($file_row && $this->graph_api) {
            // Delete from OneDrive
            $result = $this->graph_api->delete_file($file_row->onedrive_id);
            
            if ($result) {
                // Remove from database
                $wpdb->delete($table, array('attachment_id' => $attachment_id), array('%d'));
                Azure_Logger::info('OneDrive Media: File deleted from OneDrive - ' . $file_row->file_name);
            } else {
                Azure_Logger::error('OneDrive Media: Failed to delete file from OneDrive - ' . $file_row->file_name);
            }
        }
    }
    
    /**
     * Store file mapping in database
     */
    private function store_file_mapping($attachment_id, $file_data, $local_path = null) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        $folder_year = null;
        if (Azure_Settings::get_setting('onedrive_media_use_year_folders', true)) {
            // Extract the year from the file's actual SharePoint path if possible,
            // rather than defaulting to the current year. This ensures files synced
            // from year-based folders (e.g. "WordPress Media/2024/photo.jpg") get
            // the correct year in their mapping.
            $parent_path = $file_data['parent_path'] ?? '';
            if (preg_match('/\/(\d{4})$/', $parent_path, $m)) {
                $folder_year = $m[1];
            } else {
                $folder_year = date('Y');
            }
        }
        
        $data = array(
            'attachment_id' => $attachment_id,
            'onedrive_id' => $file_data['id'],
            'onedrive_path' => $file_data['parent_path'],
            'file_name' => $file_data['name'],
            'file_size' => $file_data['size'],
            'mime_type' => $file_data['mime_type'],
            'folder_year' => $folder_year,
            'last_modified' => $file_data['modified'],
            'download_url' => $file_data['download_url'],
            'sync_status' => 'synced'
        );
        
        $wpdb->insert($table, $data, array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'));
        
        return $wpdb->insert_id;
    }
    
    /**
     * Add OneDrive fields to attachment edit screen
     */
    public function add_onedrive_fields($fields, $post) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        $file_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d",
            $post->ID
        ));
        
        if ($file_row) {
            $fields['onedrive_status'] = array(
                'label' => 'OneDrive Status',
                'input' => 'html',
                'html' => '<span style="color: green;">✓ Stored in OneDrive</span><br>' .
                         'File ID: ' . esc_html($file_row->onedrive_id) . '<br>' .
                         'Path: ' . esc_html($file_row->onedrive_path) . '<br>' .
                         ($file_row->public_url ? '<a href="' . esc_url($file_row->public_url) . '" target="_blank">View in OneDrive</a>' : '')
            );
        }
        
        return $fields;
    }
    
    /**
     * Get the OneDrive metadata for an attachment (for admin display only).
     */
    public function get_onedrive_info($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT onedrive_id, public_url, download_url, thumbnail_url FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));
    }
    
    /**
     * Sync files from OneDrive to WordPress (recurses into subfolders)
     */
    public function sync_from_onedrive($folder_path = '') {
        if (!$this->graph_api) {
            return array('success' => false, 'message' => 'Graph API not initialized');
        }

        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        if (empty($folder_path)) {
            $folder_path = $base_folder;
        }

        $synced_count = 0;
        $error_count  = 0;
        $skipped_count = 0;

        $this->sync_folder_recursive($folder_path, $synced_count, $error_count, $skipped_count);

        Azure_Logger::info("OneDrive Media: Sync completed - {$synced_count} synced, {$skipped_count} already mapped, {$error_count} errors");

        $message = "Synced {$synced_count} files from OneDrive";
        if ($skipped_count > 0) {
            $message .= " ({$skipped_count} already existed)";
        }
        if ($error_count > 0) {
            $message .= " ({$error_count} errors)";
        }

        return array(
            'success' => true,
            'synced'  => $synced_count,
            'skipped' => $skipped_count,
            'errors'  => $error_count,
            'message' => $message,
        );
    }

    /**
     * Recursively sync a folder and its subfolders.
     */
    private function sync_folder_recursive($folder_path, &$synced, &$errors, &$skipped, $depth = 0) {
        if ($depth > 5) {
            return;
        }

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) {
            return;
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $subfolder = ltrim($folder_path . '/' . $item['name'], '/');
                $this->sync_folder_recursive($subfolder, $synced, $errors, $skipped, $depth + 1);
                continue;
            }

            // Skip WordPress-generated thumbnails (e.g. image-150x150.png)
            if ($this->is_wp_thumbnail($item['name'])) {
                $skipped++;
                continue;
            }

            // Check if this OneDrive file ID is already mapped
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, attachment_id, download_url FROM {$table} WHERE onedrive_id = %s",
                $item['id']
            ));

            if ($existing) {
                // Refresh the download URL for internal sync tracking
                $new_url = $item['download_url'] ?? '';
                if ($new_url && $new_url !== $existing->download_url) {
                    $wpdb->update($table, array('download_url' => $new_url), array('id' => $existing->id));
                }

                $skipped++;
                continue;
            }

            // Determine the correct relative path based on the OneDrive folder structure
            $subdir = $this->get_relative_upload_subdir($item['parent_path'] ?? '');
            $relative_file = $subdir !== '' ? $subdir . '/' . $item['name'] : $item['name'];

            // Exact path match — attachment already at the correct location
            $exact_match = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                 LIMIT 1",
                $relative_file
            ));

            if ($exact_match) {
                $this->store_file_mapping((int) $exact_match, $item);
                $skipped++;
                continue;
            }

            // Basename match — attachment exists but at the WRONG path (e.g. 2026/03 instead of 2019/02).
            // Move the file to the correct location and update WordPress metadata.
            $wrong_path_match = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
                 LIMIT 1",
                '%/' . $wpdb->esc_like($item['name'])
            ));

            if (!$wrong_path_match) {
                $wrong_path_match = $wpdb->get_var($wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                     WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                     LIMIT 1",
                    $item['name']
                ));
            }

            if ($wrong_path_match && $subdir !== '') {
                $this->relocate_attachment((int) $wrong_path_match, $subdir);
                $this->store_file_mapping((int) $wrong_path_match, $item);
                $skipped++;
                continue;
            } elseif ($wrong_path_match) {
                $this->store_file_mapping((int) $wrong_path_match, $item);
                $skipped++;
                continue;
            }

            $attachment_id = $this->create_attachment_from_onedrive($item);
            if ($attachment_id) {
                $synced++;
            } else {
                $errors++;
            }
        }
    }
    
    /**
     * Move an attachment's files from their current location to the correct YYYY/MM subdir
     * and update all WordPress metadata so URLs resolve properly.
     */
    private function relocate_attachment($attachment_id, $correct_subdir) {
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $current_relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($current_relative)) {
            return;
        }

        $filename = basename($current_relative);
        $correct_relative = $correct_subdir . '/' . $filename;

        // Already in the right place
        if ($current_relative === $correct_relative) {
            return;
        }

        $old_path = $basedir . '/' . $current_relative;
        $new_dir  = $basedir . '/' . $correct_subdir;
        $new_path = $new_dir . '/' . $filename;

        // Create target directory if needed
        if (!is_dir($new_dir)) {
            wp_mkdir_p($new_dir);
        }

        // Move the main file
        if (file_exists($old_path) && !file_exists($new_path)) {
            rename($old_path, $new_path);
        }

        // Move any thumbnails (e.g. image-150x150.jpg)
        $old_dir = dirname($old_path);
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $pattern = $old_dir . '/' . $name_without_ext . '-*.' . $ext;
        foreach (glob($pattern) as $thumb_file) {
            $thumb_name = basename($thumb_file);
            $thumb_dest = $new_dir . '/' . $thumb_name;
            if (!file_exists($thumb_dest)) {
                rename($thumb_file, $thumb_dest);
            }
        }

        // Update _wp_attached_file meta
        update_post_meta($attachment_id, '_wp_attached_file', $correct_relative);

        // Update thumbnail paths in _wp_attachment_metadata
        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta)) {
            if (!empty($meta['file'])) {
                $meta['file'] = $correct_relative;
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                // Size entries only store the filename, not the subdir — they're
                // resolved relative to the file's directory, so no changes needed.
            }
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        // Update the post guid
        global $wpdb;
        $new_guid = $baseurl . '/' . $correct_relative;
        $wpdb->update($wpdb->posts, array('guid' => $new_guid), array('ID' => $attachment_id));

        Azure_Logger::info("OneDrive Media: Relocated attachment #{$attachment_id} from {$current_relative} to {$correct_relative}");
    }

    /**
     * Extract the relative subpath (e.g. "2019/02") from a Graph API parent_path
     * by stripping everything up to and including the configured base folder name.
     */
    private function get_relative_upload_subdir($parent_path) {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');

        // parent_path looks like: /drives/{id}/root:/WordPress Media/2019/02
        $pos = strpos($parent_path, ':/' . $base_folder);
        if ($pos === false) {
            return '';
        }
        $after_base = substr($parent_path, $pos + strlen(':/' . $base_folder));
        return trim($after_base, '/');
    }

    /**
     * Create WordPress attachment from OneDrive file.
     * Downloads the file and copies it directly to the correct uploads/YYYY/MM/
     * path derived from the OneDrive folder structure, bypassing media_handle_sideload
     * which always uses today's date.
     */
    private function create_attachment_from_onedrive($file_data) {
        $file_size = isset($file_data['size']) ? (int) $file_data['size'] : 0;
        $max_upload_size = wp_max_upload_size();
        if ($file_size > 0 && $file_size > $max_upload_size) {
            Azure_Logger::warning('OneDrive Media: Skipped "' . $file_data['name'] . '" (' . size_format($file_size) . ') — exceeds max upload size (' . size_format($max_upload_size) . ')');
            return false;
        }

        $temp_file = download_url($file_data['download_url']);

        if (is_wp_error($temp_file)) {
            Azure_Logger::error('OneDrive Media: Failed to download - ' . $file_data['name'] . ': ' . $temp_file->get_error_message());
            return false;
        }

        $filename = sanitize_file_name($file_data['name']);
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        // Determine target subdir from OneDrive folder structure (e.g. "2019/02")
        $subdir = $this->get_relative_upload_subdir($file_data['parent_path'] ?? '');
        if ($subdir === '') {
            // Fallback: use current YYYY/MM only if OneDrive has no folder structure
            $subdir = date('Y') . '/' . date('m');
        }

        $target_dir = $basedir . '/' . $subdir;
        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // Avoid overwriting — add suffix if file already exists
        $target_path = $target_dir . '/' . $filename;
        if (file_exists($target_path)) {
            $name_part = pathinfo($filename, PATHINFO_FILENAME);
            $ext_part  = pathinfo($filename, PATHINFO_EXTENSION);
            $counter   = 1;
            while (file_exists($target_path)) {
                $target_path = $target_dir . '/' . $name_part . '-' . $counter . '.' . $ext_part;
                $counter++;
            }
            $filename = basename($target_path);
        }

        // Copy downloaded file to correct uploads location
        $copied = copy($temp_file, $target_path);
        @unlink($temp_file);

        if (!$copied) {
            Azure_Logger::error('OneDrive Media: Failed to copy file to ' . $target_path);
            return false;
        }

        // Set correct permissions
        $stat = stat(dirname($target_path));
        @chmod($target_path, $stat['mode'] & 0000666);

        $relative_path = $subdir . '/' . $filename;
        $mime_type = $file_data['mime_type'] ?: mime_content_type($target_path);

        // Create the attachment post
        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $baseurl . '/' . $relative_path,
        );

        $attachment_id = wp_insert_attachment($attachment, $target_path, 0, true);

        if (is_wp_error($attachment_id)) {
            Azure_Logger::error('OneDrive Media: Failed to create attachment - ' . $file_data['name'] . ': ' . $attachment_id->get_error_message());
            @unlink($target_path);
            return false;
        }

        // Ensure _wp_attached_file stores the correct relative path
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        // Generate thumbnails and attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $target_path);
        if (!is_wp_error($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $this->store_file_mapping($attachment_id, $file_data);

        Azure_Logger::debug('OneDrive Media: Imported ' . $file_data['name'] . ' → uploads/' . $relative_path);

        return $attachment_id;
    }
    
    /**
     * Run auto-sync (scheduled via WordPress Cron)
     */
    public function run_auto_sync() {
        if (!Azure_Settings::get_setting('onedrive_media_auto_sync', false)) {
            return;
        }
        
        Azure_Logger::info('OneDrive Media: Starting auto-sync');
        $this->sync_from_onedrive();
    }
    
    /**
     * AJAX: Scan OneDrive folders — returns the list of top-level folders with file counts
     * so the UI can drive batch imports one folder at a time.
     */
    public function ajax_import_from_onedrive() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized');
            return;
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'scan');

        if ($mode === 'scan') {
            $this->import_scan();
        } elseif ($mode === 'batch') {
            $this->import_batch();
        } else {
            wp_send_json_error('Invalid mode');
        }
    }

    /**
     * Step 1: Scan the OneDrive base folder and return a list of top-level
     * subfolders (year folders) with their recursive file counts. Also counts
     * any loose files in the base folder root.
     */
    private function import_scan() {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $items = $this->graph_api->list_folder($base_folder);

        if (empty($items)) {
            wp_send_json_error('No files or folders found in ' . $base_folder);
            return;
        }

        $batches = array();
        $root_files = 0;

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $folder_path = $base_folder . '/' . $item['name'];
                $files = array();
                $this->collect_onedrive_files($folder_path, $item['name'], $files);
                $batches[] = array(
                    'folder'     => $item['name'],
                    'file_count' => count($files),
                );
            } else {
                if (!$this->is_wp_thumbnail($item['name'])) {
                    $root_files++;
                }
            }
        }

        // Add root loose files as a batch if any
        if ($root_files > 0) {
            array_unshift($batches, array(
                'folder'     => '__root__',
                'file_count' => $root_files,
            ));
        }

        $total_files = $root_files;
        foreach ($batches as $b) {
            $total_files += $b['file_count'];
        }

        Azure_Logger::info('OneDrive Media Import: Scan found ' . count($batches) . ' batches, ' . $total_files . ' total files');

        wp_send_json_success(array(
            'batches'     => $batches,
            'total_files' => $total_files,
        ));
    }

    private static $IMPORT_CHUNK_SIZE = 20;

    /**
     * Step 2: Import a chunk of files from a single folder.
     * Called repeatedly by the UI with increasing offset until done.
     * File list is cached in a transient after the first call so we
     * only hit the Graph API once per folder.
     */
    private function import_batch() {
        $folder = sanitize_text_field($_POST['folder'] ?? '');
        if ($folder === '') {
            wp_send_json_error('No folder specified');
            return;
        }

        $offset = max(0, intval($_POST['offset'] ?? 0));

        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $upload_dir  = wp_upload_dir();
        $basedir     = $upload_dir['basedir'];
        $baseurl     = $upload_dir['baseurl'];

        // Cache file list in a transient so we only scan OneDrive once per folder
        $cache_key = 'od_import_' . md5($folder);
        $files = get_transient($cache_key);

        if ($files === false || $offset === 0) {
            $files = array();
            if ($folder === '__root__') {
                $items = $this->graph_api->list_folder($base_folder);
                foreach (($items ?: array()) as $item) {
                    if (!$item['is_folder']) {
                        $files[] = array('item' => $item, 'subpath' => '');
                    }
                }
            } else {
                $folder_path = $base_folder . '/' . $folder;
                $this->collect_onedrive_files($folder_path, $folder, $files);
            }
            set_transient($cache_key, $files, 1800); // 30 min TTL
            if ($offset === 0) {
                Azure_Logger::info('OneDrive Media Import: Folder "' . $folder . '" — ' . count($files) . ' files found');
            }
        }

        $total   = count($files);
        $chunk   = array_slice($files, $offset, self::$IMPORT_CHUNK_SIZE);
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        global $wpdb;
        $onedrive_table = Azure_Database::get_table_name('onedrive_files');

        $max_upload_size = wp_max_upload_size();

        foreach ($chunk as $file_entry) {
            $item    = $file_entry['item'];
            $subpath = $file_entry['subpath'];

            if ($this->is_wp_thumbnail($item['name'])) {
                $skipped++;
                continue;
            }

            $file_size = isset($item['size']) ? (int) $item['size'] : 0;
            if ($file_size > 0 && $file_size > $max_upload_size) {
                Azure_Logger::warning('OneDrive Media Import: Skipped "' . $item['name'] . '" (' . size_format($file_size) . ') — exceeds max upload size (' . size_format($max_upload_size) . ')');
                $skipped++;
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$onedrive_table} WHERE onedrive_id = %s", $item['id']
            ));
            if ($existing) {
                $skipped++;
                continue;
            }

            $filename = sanitize_file_name($item['name']);
            $relative_path = $subpath !== '' ? $subpath . '/' . $filename : $filename;

            $already_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                 LIMIT 1",
                $relative_path
            ));
            if ($already_exists) {
                $this->store_file_mapping((int) $already_exists, $item);
                $skipped++;
                continue;
            }

            $temp_file = download_url($item['download_url']);
            if (is_wp_error($temp_file)) {
                Azure_Logger::error('OneDrive Media Import: Download failed — ' . $item['name'] . ': ' . $temp_file->get_error_message());
                $errors++;
                continue;
            }

            $target_dir = $subpath !== '' ? $basedir . '/' . $subpath : $basedir;
            if (!is_dir($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $target_path = $target_dir . '/' . $filename;
            if (file_exists($target_path)) {
                $name_part = pathinfo($filename, PATHINFO_FILENAME);
                $ext_part  = pathinfo($filename, PATHINFO_EXTENSION);
                $counter   = 1;
                while (file_exists($target_path)) {
                    $target_path = $target_dir . '/' . $name_part . '-' . $counter . '.' . $ext_part;
                    $counter++;
                }
                $filename = basename($target_path);
                $relative_path = $subpath !== '' ? $subpath . '/' . $filename : $filename;
            }

            $copied = copy($temp_file, $target_path);
            @unlink($temp_file);

            if (!$copied) {
                Azure_Logger::error('OneDrive Media Import: Copy failed — ' . $target_path);
                $errors++;
                continue;
            }

            @chmod($target_path, 0644);

            $mime_type = $item['mime_type'] ?: (mime_content_type($target_path) ?: 'application/octet-stream');
            $attachment_id = wp_insert_attachment(array(
                'post_mime_type' => $mime_type,
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'guid'           => $baseurl . '/' . $relative_path,
            ), $target_path, 0, true);

            if (is_wp_error($attachment_id)) {
                Azure_Logger::error('OneDrive Media Import: Attachment failed — ' . $filename . ': ' . $attachment_id->get_error_message());
                @unlink($target_path);
                $errors++;
                continue;
            }

            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

            $metadata = wp_generate_attachment_metadata($attachment_id, $target_path);
            if (!is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            $this->store_file_mapping($attachment_id, $item);
            $imported++;
        }

        $next_offset = $offset + self::$IMPORT_CHUNK_SIZE;
        $has_more = $next_offset < $total;

        if (!$has_more) {
            delete_transient($cache_key);
            Azure_Logger::info('OneDrive Media Import: Folder "' . $folder . '" complete');
        }

        wp_send_json_success(array(
            'folder'      => $folder,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'offset'      => $offset,
            'next_offset' => $next_offset,
            'total'       => $total,
            'has_more'    => $has_more,
        ));
    }

    /**
     * Recursively collect all files from OneDrive with their relative subpath.
     * Builds a flat list: [ ['item' => ..., 'subpath' => '2019/02'], ... ]
     */
    private function collect_onedrive_files($folder_path, $relative_subpath, &$files, $depth = 0) {
        if ($depth > 10) {
            return;
        }

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $child_folder  = ltrim($folder_path . '/' . $item['name'], '/');
                $child_subpath = $relative_subpath !== '' ? $relative_subpath . '/' . $item['name'] : $item['name'];
                $this->collect_onedrive_files($child_folder, $child_subpath, $files, $depth + 1);
            } else {
                $files[] = array(
                    'item'    => $item,
                    'subpath' => $relative_subpath,
                );
            }
        }
    }

    /**
     * AJAX: Sync from OneDrive
     */
    public function ajax_sync_from_onedrive() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $result = $this->sync_from_onedrive();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX: Browse OneDrive/SharePoint folders
     */
    public function ajax_browse_folders() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $folder_path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field($_POST['storage_type']) : 'onedrive';
        
        if ($this->graph_api) {
            // If SharePoint, use site and drive ID
            if ($storage_type === 'sharepoint') {
                $site_id = isset($_POST['site_id']) ? sanitize_text_field($_POST['site_id']) : '';
                $drive_id = isset($_POST['drive_id']) ? sanitize_text_field($_POST['drive_id']) : '';
                
                if (empty($site_id) || empty($drive_id)) {
                    wp_send_json_error('SharePoint site ID and drive ID required');
                    return;
                }
                
                $items = $this->graph_api->list_drive_folder($drive_id, $folder_path);
            } else {
                // OneDrive
                $items = $this->graph_api->list_folder($folder_path);
            }
            
            // Filter to only return folders
            $folders = array_filter($items, function($item) {
                return $item['is_folder'];
            });
            
            wp_send_json_success(array('folders' => array_values($folders)));
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Create folder
     */
    public function ajax_create_folder() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $parent_path = isset($_POST['parent_path']) ? sanitize_text_field($_POST['parent_path']) : '';
        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
        
        if (empty($folder_name)) {
            wp_send_json_error('Folder name is required');
            return;
        }
        
        if ($this->graph_api) {
            $result = $this->graph_api->create_folder($parent_path, $folder_name);
            
            if ($result) {
                wp_send_json_success(array('folder' => $result));
            } else {
                wp_send_json_error('Failed to create folder');
            }
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if ($this->auth) {
            $result = $this->auth->test_connection();
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        } else {
            wp_send_json_error('Authentication not initialized');
        }
    }
    
    /**
     * AJAX: List SharePoint sites
     */
    public function ajax_list_sharepoint_sites() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check if OneDrive auth is initialized
        if (!$this->auth) {
            wp_send_json_error('OneDrive authentication not initialized. Please check Azure credentials.');
            return;
        }
        
        // Get access token
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            wp_send_json_error('No access token available. Please authorize OneDrive access first (Step 1).');
            return;
        }
        
        // Make direct Graph API call
        $api_url = 'https://graph.microsoft.com/v1.0/sites?search=*';
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to connect to Microsoft Graph API: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Graph API error: ' . ($data['error']['message'] ?? 'Unknown error'));
            return;
        }
        
        $sites = $data['value'] ?? array();
        
        if (empty($sites)) {
            wp_send_json_error('No SharePoint sites found. Make sure you have access to SharePoint sites and the required permissions (Sites.Read.All).');
            return;
        }
        
        wp_send_json_success(array('sites' => $sites));
    }
    
    /**
     * AJAX: List SharePoint document libraries (drives)
     */
    public function ajax_list_sharepoint_drives() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $site_id = isset($_POST['site_id']) ? sanitize_text_field($_POST['site_id']) : '';
        
        if (empty($site_id)) {
            wp_send_json_error('Site ID required');
            return;
        }
        
        // Check if OneDrive auth is initialized
        if (!$this->auth) {
            wp_send_json_error('OneDrive authentication not initialized');
            return;
        }
        
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            wp_send_json_error('No access token available');
            return;
        }
        
        // Make direct Graph API call
        $api_url = "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to connect: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Graph API error: ' . ($data['error']['message'] ?? 'Unknown error'));
            return;
        }
        
        $drives = $data['value'] ?? array();
        
        if (empty($drives)) {
            wp_send_json_error('No document libraries found');
            return;
        }
        
        wp_send_json_success(array('drives' => $drives));
    }
    
    /**
     * AJAX: Resolve SharePoint site from URL
     */
    public function ajax_resolve_sharepoint_site() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        
        if (empty($site_url)) {
            wp_send_json_error('Site URL required');
            return;
        }
        
        if ($this->graph_api) {
            $site = $this->graph_api->get_site_by_url($site_url);
            
            if ($site) {
                wp_send_json_success(array(
                    'site_id' => $site['id'],
                    'site_name' => $site['displayName'] ?? $site['name']
                ));
            } else {
                wp_send_json_error('Failed to resolve SharePoint site');
            }
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Create year folders
     */
    public function ajax_create_year_folders() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized');
            return;
        }
        
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $current_year = (int) date('Y');
        $folders_created = array();

        // Determine the earliest year from existing WordPress uploads
        $earliest_year = $current_year;
        $upload_dir = wp_upload_dir();
        $upload_base = $upload_dir['basedir'];
        if (is_dir($upload_base)) {
            foreach (scandir($upload_base) as $entry) {
                if (preg_match('/^(\d{4})$/', $entry, $m) && is_dir($upload_base . '/' . $entry)) {
                    $yr = (int) $m[1];
                    if ($yr >= 2010 && $yr < $earliest_year) {
                        $earliest_year = $yr;
                    }
                }
            }
        }

        // Create individual year folders from earliest through current year
        for ($year = $earliest_year; $year <= $current_year; $year++) {
            $result = $this->graph_api->create_folder($base_folder, (string) $year);
            if ($result) {
                $folders_created[] = (string) $year;
            }
        }
        
        if (!empty($folders_created)) {
            wp_send_json_success(array('message' => 'Created folders: ' . implode(', ', $folders_created)));
        } else {
            wp_send_json_error('No folders were created. They may already exist.');
        }
    }
    
    /**
     * AJAX: Diagnostic check — show what OneDrive sees vs what WP has, without changing anything.
     */
    public function ajax_repair_diagnose() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        @set_time_limit(120);

        $info = array();

        // Storage config
        $info['storage_type'] = Azure_Settings::get_setting('onedrive_media_storage_type', 'onedrive');
        $info['base_folder']  = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $info['site_id']      = Azure_Settings::get_setting('onedrive_media_site_id', '') ? 'set' : 'empty';
        $info['drive_id']     = Azure_Settings::get_setting('onedrive_media_drive_id', '') ? 'set' : 'empty';

        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized — OneDrive module may need authorization.');
            return;
        }

        // Test: list the base folder
        $base_items = $this->graph_api->list_folder($info['base_folder']);
        $info['base_folder_items'] = count($base_items);
        $info['base_folder_contents'] = array();
        foreach ($base_items as $bi) {
            $info['base_folder_contents'][] = ($bi['is_folder'] ? '[folder] ' : '[file] ') . $bi['name'];
        }

        // Build full recursive index (thumbnails filtered out)
        $full_index = $this->build_onedrive_file_index();
        $info['onedrive_originals'] = count($full_index);
        $info['onedrive_note'] = 'WP thumbnails (-WxH variants) are excluded from index';
        $info['onedrive_sample_files'] = array_slice(array_keys($full_index), 0, 10);

        // WP attachments missing local files
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);

        $all_attachments = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             ORDER BY p.ID DESC"
        );

        $info['wp_total_image_attachments'] = count($all_attachments);
        $missing = array();
        foreach ($all_attachments as $att) {
            if (!file_exists($basedir . $att->attached_file)) {
                $missing[] = $att;
            }
        }
        $info['wp_missing_local_files'] = count($missing);

        // Sample WP filenames vs OneDrive index (with fuzzy matching)
        $matched = 0;
        $unmatched_samples = array();
        foreach (array_slice($missing, 0, 20) as $m) {
            $fn = basename($m->attached_file);
            $found = $this->find_in_index($fn, $full_index);
            if ($found) {
                $matched++;
            } else {
                $unmatched_samples[] = $m->attached_file;
            }
        }
        $info['sample_matched'] = $matched . ' of first ' . min(20, count($missing));
        $info['sample_unmatched_paths'] = array_slice($unmatched_samples, 0, 10);
        $info['match_note'] = 'Uses fuzzy matching: strips -scaled, -rotated, -e{timestamp}, -WxH suffixes';

        // Mapping table
        $table = Azure_Database::get_table_name('onedrive_files');
        $info['mapping_table_rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        wp_send_json_success($info);
    }

    /**
     * Try to find a file in the OneDrive index by trying multiple WordPress filename variants.
     * WP may add "-scaled", "-e{timestamp}", "-{WxH}" suffixes that OneDrive won't have.
     *
     * @return array|null  The file_data from the index, or null if no match.
     */
    private function find_in_index($filename, $onedrive_index) {
        $lower = strtolower($filename);
        if (isset($onedrive_index[$lower])) {
            return $onedrive_index[$lower];
        }

        $ext  = pathinfo($lower, PATHINFO_EXTENSION);
        $stem = pathinfo($lower, PATHINFO_FILENAME);

        // Strip "-scaled" suffix  (e.g. image-scaled.png → image.png)
        $variants = array();
        $clean = preg_replace('/-scaled$/i', '', $stem);
        if ($clean !== $stem) {
            $variants[] = $clean . '.' . $ext;
        }

        // Strip WP image-edit suffix  "-e{10-13 digit timestamp}" (e.g. img-e1764958349687.png → img.png)
        $clean2 = preg_replace('/-e\d{10,14}$/i', '', $stem);
        if ($clean2 !== $stem) {
            $variants[] = $clean2 . '.' . $ext;
        }

        // Strip both combined
        $clean3 = preg_replace('/-scaled$/i', '', $clean2);
        if ($clean3 !== $stem && $clean3 !== $clean && $clean3 !== $clean2) {
            $variants[] = $clean3 . '.' . $ext;
        }

        // Strip "-rotated" suffix (e.g. IMG_2697-rotated.jpeg → IMG_2697.jpeg)
        $clean4 = preg_replace('/-rotated$/i', '', $stem);
        if ($clean4 !== $stem) {
            $variants[] = $clean4 . '.' . $ext;
        }

        // Strip WP dimension suffix "-{W}x{H}" (e.g. image-300x200.png → image.png)
        $clean5 = preg_replace('/-\d+x\d+$/i', '', $stem);
        if ($clean5 !== $stem) {
            $variants[] = $clean5 . '.' . $ext;
        }

        foreach ($variants as $v) {
            $vl = strtolower($v);
            if (isset($onedrive_index[$vl])) {
                return $onedrive_index[$vl];
            }
        }

        return null;
    }

    /**
     * Build a filename → file data index from all OneDrive files (recursive).
     */
    private function build_onedrive_file_index() {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $index = array();
        $this->index_folder_recursive($base_folder, $index);
        Azure_Logger::info('OneDrive Repair: Indexed ' . count($index) . ' files from OneDrive');
        return $index;
    }

    private function index_folder_recursive($folder_path, &$index, $depth = 0) {
        if ($depth > 5) return;

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) return;

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $subfolder = ltrim($folder_path . '/' . $item['name'], '/');
                $this->index_folder_recursive($subfolder, $index, $depth + 1);
            } else {
                // Skip WP thumbnail variants (e.g. image-300x200.png, image-100x100.png)
                if ($this->is_wp_thumbnail($item['name'])) {
                    continue;
                }
                $key = strtolower($item['name']);
                if (!isset($index[$key])) {
                    $index[$key] = $item;
                }
            }
        }
    }

    /**
     * Check if a filename looks like a WordPress-generated thumbnail.
     * Matches patterns like: name-150x150.png, name-300x200-1.jpg (with OneDrive conflict suffix)
     */
    private function is_wp_thumbnail($filename) {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        // Matches -WxH or -WxH-N (OneDrive conflict suffix) at the end
        return (bool) preg_match('/-\d{2,4}x\d{2,4}(-\d+)?$/', $stem);
    }

    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        $stats = array(
            'total_files' => $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'synced_files' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE sync_status = 'synced'"),
            'pending_files' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE sync_status = 'pending'"),
            'error_files' => $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE sync_status = 'error'"),
            'total_size' => $wpdb->get_var("SELECT SUM(file_size) FROM {$table}")
        );
        
        return $stats;
    }
}