<?php
/**
 * Restore Wizard Class
 *
 * Guides the user through restoring a backup from Azure Storage onto a new site.
 * Handles DB restore (which invalidates the session), re-authentication, file
 * restore, and OneDrive media sync — all as a step-by-step guided flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Restore_Wizard {

    private static $instance = null;
    private static $state_option = 'azure_restore_wizard_state';

    public $steps = array(
        1 => array('id' => 'connect',       'title' => 'Connect to Azure'),
        2 => array('id' => 'select-backup', 'title' => 'Select Backup'),
        3 => array('id' => 'restore-db',    'title' => 'Restore Database'),
        4 => array('id' => 'reauth',        'title' => 'Re-Authenticate'),
        5 => array('id' => 'restore-files', 'title' => 'Restore Files'),
        6 => array('id' => 'media-sync',    'title' => 'Media Sync'),
        7 => array('id' => 'complete',      'title' => 'Complete'),
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 25);
        add_action('admin_init', array($this, 'maybe_redirect_after_db_restore'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_azure_restore_wizard_validate_storage', array($this, 'ajax_validate_storage'));
        add_action('wp_ajax_azure_restore_wizard_list_backups', array($this, 'ajax_list_backups'));
        add_action('wp_ajax_azure_restore_wizard_select_backup', array($this, 'ajax_select_backup'));
        add_action('wp_ajax_azure_restore_wizard_get_manifest', array($this, 'ajax_get_manifest'));
        add_action('wp_ajax_azure_restore_wizard_run_db', array($this, 'ajax_run_db_restore'));
        add_action('wp_ajax_azure_restore_wizard_run_files', array($this, 'ajax_run_files_restore'));
        add_action('wp_ajax_azure_restore_wizard_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_azure_restore_wizard_start_media_sync', array($this, 'ajax_start_media_sync'));
        add_action('wp_ajax_azure_restore_wizard_media_sync_status', array($this, 'ajax_media_sync_status'));
        add_action('wp_ajax_azure_restore_wizard_complete', array($this, 'ajax_complete'));
        add_action('wp_ajax_azure_restore_wizard_cancel', array($this, 'ajax_cancel'));
    }

    // ------------------------------------------------------------------
    // Menu & Navigation
    // ------------------------------------------------------------------

    public function add_menu() {
        $state = self::get_state();
        $is_active = !empty($state['active']);
        $wizard_completed = Azure_Settings::get_setting('setup_wizard_completed', false);

        // Show in menu if restore is active, or if setup wizard hasn't completed yet
        $show_label = ($is_active || !$wizard_completed) ? __('Restore Wizard', 'azure-plugin') : '';

        add_submenu_page(
            'azure-plugin',
            __('Restore Wizard', 'azure-plugin'),
            $show_label,
            'manage_options',
            'azure-plugin-restore',
            array($this, 'render_page')
        );
    }

    /**
     * After a DB restore the session is invalidated. When the user logs back
     * in we detect the restore-in-progress flag and redirect them straight
     * back to the wizard at step 4 (re-auth confirmation).
     */
    public function maybe_redirect_after_db_restore() {
        if (!current_user_can('manage_options')) return;
        if (wp_doing_ajax()) return;

        $state = self::get_state();
        if (empty($state['active'])) return;

        $page = $_GET['page'] ?? '';
        if ($page === 'azure-plugin-restore') return;

        // The DB was restored and user just logged in — send them to step 4
        if (($state['step'] ?? 0) === 3 && !empty($state['db_restored'])) {
            wp_redirect(admin_url('admin.php?page=azure-plugin-restore&step=4'));
            exit;
        }
    }

    // ------------------------------------------------------------------
    // State management (persists across DB restore via wp_options)
    // ------------------------------------------------------------------

    public static function get_state() {
        return get_option(self::$state_option, array());
    }

    public static function update_state($merge) {
        $state = self::get_state();
        $state = array_merge($state, $merge);
        update_option(self::$state_option, $state, false);
        return $state;
    }

    public static function clear_state() {
        delete_option(self::$state_option);
        delete_option('azure_restore_completed');
    }

    public static function is_active() {
        $state = self::get_state();
        return !empty($state['active']);
    }

    // ------------------------------------------------------------------
    // Scripts
    // ------------------------------------------------------------------

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'azure-plugin-restore') === false) return;

        wp_enqueue_style(
            'azure-setup-wizard',
            AZURE_PLUGIN_URL . 'css/setup-wizard.css',
            array(),
            AZURE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'azure-restore-wizard',
            AZURE_PLUGIN_URL . 'js/restore-wizard.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );

        wp_localize_script('azure-restore-wizard', 'azureRestoreWizard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('azure_restore_wizard'),
            'pageUrl' => admin_url('admin.php?page=azure-plugin-restore'),
        ));
    }

    // ------------------------------------------------------------------
    // Page rendering
    // ------------------------------------------------------------------

    public function get_current_step() {
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        return max(1, min($step, count($this->steps)));
    }

    public function get_progress_percent() {
        $step = $this->get_current_step();
        return round(($step - 1) / (count($this->steps) - 1) * 100);
    }

    public function render_page() {
        $state = self::get_state();
        include AZURE_PLUGIN_PATH . 'admin/restore-wizard-page.php';
    }

    // ------------------------------------------------------------------
    // AJAX: Validate Azure Storage
    // ------------------------------------------------------------------

    public function ajax_validate_storage() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $account   = sanitize_text_field($_POST['storage_account'] ?? '');
        $key       = sanitize_text_field($_POST['storage_key'] ?? '');
        $container = sanitize_text_field($_POST['container_name'] ?? '');

        if (empty($account) || empty($key) || empty($container)) {
            wp_send_json_error('All storage fields are required.');
        }

        if (!class_exists('Azure_Backup_Storage')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-azure-storage.php';
        }

        // Save so downstream classes can use them
        Azure_Settings::update_setting('backup_storage_account_name', $account);
        Azure_Settings::update_setting('backup_storage_account_key', $key);
        Azure_Settings::update_setting('backup_storage_container_name', $container);

        $storage = new Azure_Backup_Storage();
        $result  = $storage->test_connection($account, $key, $container);

        if ($result['success']) {
            self::update_state(array(
                'active'    => true,
                'step'      => 1,
                'storage'   => compact('account', 'key', 'container'),
            ));
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // ------------------------------------------------------------------
    // AJAX: List available backups
    // ------------------------------------------------------------------

    public function ajax_list_backups() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        if (!class_exists('Azure_Backup_Storage')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-azure-storage.php';
        }

        try {
            $storage = new Azure_Backup_Storage();
            $blobs   = $storage->list_backups(200);

            // Group blobs into backup sets by directory prefix
            $backups = array();
            foreach ($blobs as $blob) {
                $name  = $blob['name'] ?? '';
                $parts = explode('/', $name);
                if (count($parts) >= 4) {
                    $key = $parts[0] . '/' . $parts[1] . '/' . $parts[2] . '/' . $parts[3];
                    if (!isset($backups[$key])) {
                        $backups[$key] = array(
                            'prefix'   => $key,
                            'site'     => $parts[0],
                            'date'     => $parts[1] . '/' . $parts[2] . '/' . $parts[3],
                            'files'    => array(),
                            'has_manifest' => false,
                        );
                    }
                    $backups[$key]['files'][] = $name;
                    if (strpos($name, 'manifest.json') !== false) {
                        $backups[$key]['has_manifest'] = true;
                    }
                } else {
                    // Legacy single-file backup
                    $backups[$name] = array(
                        'prefix' => $name,
                        'site'   => '',
                        'date'   => '',
                        'files'  => array($name),
                        'has_manifest' => false,
                    );
                }
            }

            wp_send_json_success(array('backups' => array_values($backups)));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Select a backup
    // ------------------------------------------------------------------

    public function ajax_select_backup() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $prefix = sanitize_text_field($_POST['backup_prefix'] ?? '');
        if (empty($prefix)) wp_send_json_error('No backup selected.');

        // Find the manifest or first blob to use as the restore reference
        self::update_state(array(
            'step'           => 2,
            'backup_prefix'  => $prefix,
        ));

        wp_send_json_success(array('message' => 'Backup selected.'));
    }

    // ------------------------------------------------------------------
    // AJAX: Get manifest info (for migration warnings and component list)
    // ------------------------------------------------------------------

    public function ajax_get_manifest() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $state = self::get_state();
        $prefix = $state['backup_prefix'] ?? '';
        if (empty($prefix)) wp_send_json_error('No backup selected.');

        try {
            $blob_ref = $this->find_restore_blob($prefix);

            if (!class_exists('Azure_Backup_Storage')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-backup-azure-storage.php';
            }
            $storage = new Azure_Backup_Storage();

            // Download manifest
            $manifest_blob = null;
            $blobs = $storage->list_backups(200);
            foreach ($blobs as $b) {
                $name = $b['name'] ?? '';
                if (strpos($name, $prefix) === 0 && strpos($name, 'manifest.json') !== false) {
                    $manifest_blob = $name;
                    break;
                }
            }

            if (!$manifest_blob) {
                wp_send_json_success(array(
                    'format'     => 'v1',
                    'components' => array(),
                    'warnings'   => array(),
                ));
                return;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'manifest');
            $storage->download_backup($manifest_blob, $tmp);
            $manifest = json_decode(file_get_contents($tmp), true);
            @unlink($tmp);

            if (!$manifest) {
                wp_send_json_error('Failed to parse manifest.');
            }

            // Build migration warnings
            $warnings = array();
            $source_url = $manifest['site_url'] ?? '';
            $current_url = get_option('siteurl');

            if ($source_url && $source_url !== $current_url) {
                $warnings[] = array(
                    'type'    => 'migration',
                    'message' => "This backup is from a different site (<strong>{$source_url}</strong>). " .
                                 "URLs will be automatically updated to <strong>{$current_url}</strong> during restore.",
                );
            }

            $source_php = $manifest['php_version'] ?? '';
            if ($source_php && version_compare($source_php, PHP_VERSION, '!=')) {
                $warnings[] = array(
                    'type'    => 'php_version',
                    'message' => "The backup was created on PHP <strong>{$source_php}</strong>. " .
                                 "This server is running PHP <strong>" . PHP_VERSION . "</strong>.",
                );
            }

            $source_wp = $manifest['wp_version'] ?? '';
            $current_wp = get_bloginfo('version');
            if ($source_wp && version_compare($source_wp, $current_wp, '!=')) {
                $warnings[] = array(
                    'type'    => 'wp_version',
                    'message' => "Backup WordPress version: <strong>{$source_wp}</strong>. " .
                                 "This site: <strong>{$current_wp}</strong>.",
                );
            }

            // Build component list (exclude media)
            $components = array();
            foreach ($manifest['components'] ?? array() as $entity => $info) {
                if (in_array($entity, array('uploads', 'media'))) continue;
                $file_count = count($info['files'] ?? array());
                $components[] = array(
                    'entity'     => $entity,
                    'file_count' => $file_count,
                    'status'     => $info['status'] ?? 'unknown',
                );
            }

            // Store manifest in state for later use
            self::update_state(array('manifest' => $manifest));

            wp_send_json_success(array(
                'format'     => 'v2',
                'site_url'   => $source_url,
                'wp_version' => $source_wp,
                'php_version' => $source_php,
                'timestamp'  => $manifest['timestamp'] ?? '',
                'components' => $components,
                'warnings'   => $warnings,
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Get restore progress (polled by frontend)
    // ------------------------------------------------------------------

    public function ajax_get_progress() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $progress = get_transient('azure_restore_progress');
        $log = get_transient('azure_restore_wizard_log');

        wp_send_json_success(array(
            'progress' => $progress ?: array('progress' => 0, 'status' => 'idle', 'message' => ''),
            'log'      => $log ?: array(),
        ));
    }

    // ------------------------------------------------------------------
    // Activity log helper — appends entries to a transient-based log
    // ------------------------------------------------------------------

    public static function log_activity($message, $type = 'info') {
        $log = get_transient('azure_restore_wizard_log') ?: array();
        $log[] = array(
            'time'    => gmdate('H:i:s'),
            'type'    => $type,
            'message' => $message,
        );
        // Keep last 200 entries
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }
        set_transient('azure_restore_wizard_log', $log, HOUR_IN_SECONDS);
    }

    public static function clear_log() {
        delete_transient('azure_restore_wizard_log');
    }

    // ------------------------------------------------------------------
    // AJAX: Run database restore only
    // ------------------------------------------------------------------

    public function ajax_run_db_restore() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $state = self::get_state();
        $prefix = $state['backup_prefix'] ?? '';
        if (empty($prefix)) wp_send_json_error('No backup selected.');

        // Capture current credentials and user info before DB overwrite
        $current_user = wp_get_current_user();
        $storage_creds = $state['storage'] ?? array();

        try {
            self::clear_log();
            self::log_activity('Starting database restore...', 'heading');

            if (!class_exists('Azure_Backup_Restore')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-backup-restore.php';
            }

            $restore = new Azure_Backup_Restore();

            // Find a blob to use as the base for manifest detection
            $blob_ref = $this->find_restore_blob($prefix);
            self::log_activity('Found backup reference: ' . basename($blob_ref));

            // Run restore for database only
            self::log_activity('Downloading and applying database backup...');
            $method = new ReflectionMethod($restore, 'do_restore');
            $method->setAccessible(true);
            $method->invoke($restore, $blob_ref, array('database'));

            self::log_activity('Database restore complete.', 'success');

            // After DB restore the options table has been replaced.
            // Re-inject restore wizard state so we can resume after re-login.

            // Re-save our wizard state into the now-restored DB
            $state['step'] = 3;
            $state['db_restored'] = true;
            update_option(self::$state_option, $state, false);
            update_option('azure_restore_completed', gmdate('c'), false);

            // Re-save storage credentials (post_db_restore_fixups does this
            // in the settings, but also ensure they're in the wizard state)
            if (!empty($storage_creds)) {
                Azure_Settings::update_setting('backup_storage_account_name', $storage_creds['account']);
                Azure_Settings::update_setting('backup_storage_account_key', $storage_creds['key']);
                Azure_Settings::update_setting('backup_storage_container_name', $storage_creds['container']);
            }

            // Create/update an admin user matching the current user so they
            // can log back in with known credentials
            $temp_pass = wp_generate_password(16, true, true);
            $admin_login = 'restore_admin_' . wp_generate_password(4, false);
            $admin_email = $current_user->user_email ?: 'restore@localhost';

            $user_id = wp_insert_user(array(
                'user_login' => $admin_login,
                'user_pass'  => $temp_pass,
                'user_email' => $admin_email,
                'role'       => 'administrator',
                'display_name' => 'Restore Admin',
            ));

            if (!is_wp_error($user_id)) {
                $state['temp_admin'] = array(
                    'user_id'  => $user_id,
                    'login'    => $admin_login,
                    'password' => $temp_pass,
                );
                update_option(self::$state_option, $state, false);
            }

            Azure_Logger::info('Restore Wizard: Database restored successfully. Temporary admin created.', 'Backup');

            wp_send_json_success(array(
                'message'       => 'Database restored. You will need to re-authenticate.',
                'temp_login'    => !is_wp_error($user_id) ? $admin_login : null,
                'temp_password' => !is_wp_error($user_id) ? $temp_pass : null,
                'login_url'     => wp_login_url(admin_url('admin.php?page=azure-plugin-restore&step=4')),
            ));
        } catch (Exception $e) {
            Azure_Logger::error('Restore Wizard: DB restore failed — ' . $e->getMessage(), 'Backup');
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Run file restore (plugins, themes, content — no media)
    // ------------------------------------------------------------------

    public function ajax_run_files_restore() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $state = self::get_state();
        $prefix = $state['backup_prefix'] ?? '';
        if (empty($prefix)) wp_send_json_error('No backup selected.');

        try {
            self::clear_log();
            self::log_activity('Starting file restoration...', 'heading');

            if (!class_exists('Azure_Backup_Restore')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-backup-restore.php';
            }

            $restore  = new Azure_Backup_Restore();
            $blob_ref = $this->find_restore_blob($prefix);

            // Restore everything except database and media
            $file_types = array('mu-plugins', 'plugins', 'themes', 'others', 'content');

            self::log_activity('Components to restore: ' . implode(', ', $file_types));

            $method = new ReflectionMethod($restore, 'do_restore');
            $method->setAccessible(true);
            $method->invoke($restore, $blob_ref, $file_types);

            self::update_state(array('step' => 5, 'files_restored' => true));
            self::log_activity('All file components restored successfully.', 'success');
            Azure_Logger::info('Restore Wizard: Files restored (plugins, themes, content).', 'Backup');

            wp_send_json_success(array('message' => 'Files restored successfully.'));
        } catch (Exception $e) {
            self::log_activity('File restore failed: ' . $e->getMessage(), 'error');
            Azure_Logger::error('Restore Wizard: File restore failed — ' . $e->getMessage(), 'Backup');
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Start OneDrive media sync (SharePoint → WordPress)
    // ------------------------------------------------------------------

    public function ajax_start_media_sync() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        try {
            // Set sync direction to one-way pull (SharePoint → WP)
            Azure_Settings::update_setting('onedrive_media_sync_direction', 'onedrive_to_wp');
            Azure_Logger::info('Restore Wizard: OneDrive sync direction set to onedrive_to_wp for media pull.', 'OneDrive');

            // Trigger the sync
            if (!class_exists('Azure_OneDrive_Media_Manager')) {
                wp_send_json_error('OneDrive Media module is not available. Please enable it in settings first.');
            }

            $manager = Azure_OneDrive_Media_Manager::get_instance();

            // Store a transient to track sync progress
            set_transient('azure_restore_media_sync', array(
                'status'  => 'running',
                'started' => time(),
            ), HOUR_IN_SECONDS);

            // Run synchronously for now — the sync method handles its own progress
            $result = $manager->sync_from_onedrive();

            // Mark sync as done
            set_transient('azure_restore_media_sync', array(
                'status'    => 'completed',
                'completed' => time(),
                'result'    => $result,
            ), HOUR_IN_SECONDS);

            self::update_state(array('step' => 6, 'media_synced' => true));

            wp_send_json_success(array(
                'message' => 'Media sync from SharePoint completed.',
                'result'  => $result,
            ));
        } catch (Exception $e) {
            set_transient('azure_restore_media_sync', array(
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ), HOUR_IN_SECONDS);

            Azure_Logger::error('Restore Wizard: Media sync failed — ' . $e->getMessage(), 'OneDrive');
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // AJAX: Check media sync status
    // ------------------------------------------------------------------

    public function ajax_media_sync_status() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $sync = get_transient('azure_restore_media_sync');
        wp_send_json_success($sync ?: array('status' => 'idle'));
    }

    // ------------------------------------------------------------------
    // AJAX: Complete restore wizard
    // ------------------------------------------------------------------

    public function ajax_complete() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        $state = self::get_state();

        // Switch OneDrive sync back to two-way
        Azure_Settings::update_setting('onedrive_media_sync_direction', 'two_way');
        Azure_Logger::info('Restore Wizard: OneDrive sync direction restored to two_way.', 'OneDrive');

        // Remove temp admin user if created
        if (!empty($state['temp_admin']['user_id'])) {
            $current = wp_get_current_user();
            if ($current->ID != $state['temp_admin']['user_id']) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($state['temp_admin']['user_id'], $current->ID);
                Azure_Logger::info('Restore Wizard: Temporary admin user removed.', 'Backup');
            }
        }

        // Mark wizard and setup as completed
        Azure_Settings::update_setting('setup_wizard_completed', true);
        self::clear_state();

        Azure_Logger::info('Restore Wizard: Restore process completed successfully.', 'Backup');

        wp_send_json_success(array(
            'message'  => 'Restore complete!',
            'redirect' => admin_url('admin.php?page=azure-plugin'),
        ));
    }

    // ------------------------------------------------------------------
    // AJAX: Cancel restore
    // ------------------------------------------------------------------

    public function ajax_cancel() {
        check_ajax_referer('azure_restore_wizard', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');

        // Restore sync direction if changed
        Azure_Settings::update_setting('onedrive_media_sync_direction', 'two_way');

        self::clear_state();
        Azure_Logger::info('Restore Wizard: Cancelled by user.', 'Backup');

        wp_send_json_success(array(
            'redirect' => admin_url('admin.php?page=azure-plugin'),
        ));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Find the first blob in a backup prefix that can be used as a reference
     * for manifest-based restore detection (e.g. the manifest or any .zip).
     */
    private function find_restore_blob($prefix) {
        if (!class_exists('Azure_Backup_Storage')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-azure-storage.php';
        }

        $storage = new Azure_Backup_Storage();
        $blobs   = $storage->list_backups(200);

        // Prefer the manifest
        foreach ($blobs as $blob) {
            $name = $blob['name'] ?? '';
            if (strpos($name, $prefix) === 0 && strpos($name, 'manifest.json') !== false) {
                return $name;
            }
        }

        // Fall back to any blob in the set
        foreach ($blobs as $blob) {
            $name = $blob['name'] ?? '';
            if (strpos($name, $prefix) === 0) {
                return $name;
            }
        }

        throw new Exception('No blobs found for backup: ' . $prefix);
    }
}

// Initialize
Azure_Restore_Wizard::get_instance();
