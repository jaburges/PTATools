<?php
/**
 * Setup Wizard Class
 * 
 * Handles the first-time setup wizard for the Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Setup_Wizard {
    
    private static $instance = null;
    
    // Wizard steps definition
    public $steps = array(
        1 => array('id' => 'welcome', 'title' => 'Welcome'),
        2 => array('id' => 'organization', 'title' => 'Organization Info'),
        3 => array('id' => 'modules', 'title' => 'Module Selection'),
        4 => array('id' => 'azure', 'title' => 'Azure App Registration'),
        5 => array('id' => 'backup', 'title' => 'Backup Setup'),
        6 => array('id' => 'pta', 'title' => 'PTA Roles Setup'),
        7 => array('id' => 'sso', 'title' => 'SSO Configuration'),
        8 => array('id' => 'onedrive', 'title' => 'OneDrive Media'),
        9 => array('id' => 'summary', 'title' => 'Summary')
    );
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_wizard_menu'), 20); // After main menu (priority 10)
        add_action('admin_init', array($this, 'maybe_redirect_to_wizard'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_azure_wizard_save_step', array($this, 'ajax_save_step'));
        add_action('wp_ajax_azure_wizard_validate_azure', array($this, 'ajax_validate_azure'));
        add_action('wp_ajax_azure_wizard_validate_backup', array($this, 'ajax_validate_backup'));
        add_action('wp_ajax_azure_wizard_import_defaults', array($this, 'ajax_import_defaults'));
        add_action('wp_ajax_azure_wizard_pull_azure_ad', array($this, 'ajax_pull_azure_ad'));
        add_action('wp_ajax_azure_wizard_complete', array($this, 'ajax_complete_wizard'));
        add_action('wp_ajax_azure_wizard_skip', array($this, 'ajax_skip_wizard'));
    }
    
    /**
     * Add wizard menu item
     */
    public function add_wizard_menu() {
        $wizard_completed = Azure_Settings::get_setting('setup_wizard_completed', false);
        
        // Always register under azure-plugin parent so page is accessible
        // We'll hide it from menu via CSS/filter if completed
        add_submenu_page(
            'azure-plugin',
            __('Setup Wizard', 'azure-plugin'),
            $wizard_completed ? '' : __('Setup Wizard', 'azure-plugin'), // Empty title hides from menu
            'manage_options',
            'azure-plugin-setup',
            array($this, 'render_wizard_page')
        );
    }
    
    /**
     * Redirect to wizard on first run
     */
    public function maybe_redirect_to_wizard() {
        // Only for admins
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if we should redirect
        $wizard_completed = Azure_Settings::get_setting('setup_wizard_completed', false);
        
        if ($wizard_completed) {
            return;
        }
        
        // Don't redirect on AJAX, wizard page, restore wizard, or activation
        $page = $_GET['page'] ?? '';
        if (wp_doing_ajax() || $page === 'azure-plugin-setup' || $page === 'azure-plugin-restore') {
            return;
        }
        
        // Don't redirect during plugin activation
        if (isset($_GET['activate']) || isset($_GET['activate-multi'])) {
            return;
        }

        // Don't redirect if a restore recently completed (DB was replaced)
        if (get_transient('azure_restore_progress')) {
            return;
        }
        if (get_option('azure_restore_completed')) {
            return;
        }
        
        // Check if this is a fresh install (no settings saved yet)
        $settings = get_option('azure_plugin_settings', array());
        if (empty($settings)) {
            // Fresh install - redirect to wizard
            if (isset($_GET['page']) && strpos($_GET['page'], 'azure-plugin') === 0) {
                wp_redirect(admin_url('admin.php?page=azure-plugin-setup'));
                exit;
            }
        }
    }
    
    /**
     * Enqueue wizard scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'azure-plugin-setup') === false) {
            return;
        }
        
        wp_enqueue_style(
            'azure-setup-wizard',
            AZURE_PLUGIN_URL . 'css/setup-wizard.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'azure-setup-wizard',
            AZURE_PLUGIN_URL . 'js/setup-wizard.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('azure-setup-wizard', 'azureWizard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('azure_setup_wizard'),
            'dashboardUrl' => admin_url('admin.php?page=azure-plugin'),
            'strings' => array(
                'saving' => __('Saving...', 'azure-plugin'),
                'validating' => __('Validating...', 'azure-plugin'),
                'success' => __('Success!', 'azure-plugin'),
                'error' => __('Error', 'azure-plugin'),
                'connectionSuccess' => __('Connection successful!', 'azure-plugin'),
                'connectionFailed' => __('Connection failed', 'azure-plugin'),
                'importSuccess' => __('Import completed successfully!', 'azure-plugin'),
                'pullSuccess' => __('Azure AD pull completed!', 'azure-plugin'),
                'requiredField' => __('This field is required', 'azure-plugin'),
                'invalidEmail' => __('Please enter a valid email address', 'azure-plugin'),
                'invalidDomain' => __('Please enter a valid domain (e.g., yourorg.net)', 'azure-plugin')
            )
        ));
    }
    
    /**
     * Get current wizard step
     */
    public function get_current_step() {
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        return max(1, min($step, count($this->steps)));
    }
    
    /**
     * Get steps that should be shown based on enabled modules
     */
    public function get_active_steps() {
        $modules = Azure_Settings::get_setting('setup_wizard_modules', array());
        $active_steps = array(1, 2, 3, 4); // Welcome, Org, Modules, Azure are always shown
        
        // Add module-specific steps
        if (in_array('backup', $modules)) {
            $active_steps[] = 5; // Backup
        }
        if (in_array('pta', $modules)) {
            $active_steps[] = 6; // PTA Roles
        }
        if (in_array('sso', $modules)) {
            $active_steps[] = 7; // SSO
        }
        if (in_array('onedrive', $modules)) {
            $active_steps[] = 8; // OneDrive
        }
        
        $active_steps[] = 9; // Summary is always last
        
        sort($active_steps);
        return $active_steps;
    }
    
    /**
     * Get next step number
     */
    public function get_next_step($current) {
        $active_steps = $this->get_active_steps();
        $current_index = array_search($current, $active_steps);
        
        if ($current_index !== false && isset($active_steps[$current_index + 1])) {
            return $active_steps[$current_index + 1];
        }
        
        return 9; // Default to summary
    }
    
    /**
     * Get previous step number
     */
    public function get_prev_step($current) {
        $active_steps = $this->get_active_steps();
        $current_index = array_search($current, $active_steps);
        
        if ($current_index !== false && $current_index > 0) {
            return $active_steps[$current_index - 1];
        }
        
        return 1;
    }
    
    /**
     * Calculate progress percentage
     */
    public function get_progress_percent() {
        $current_step = Azure_Settings::get_setting('setup_wizard_step', 1);
        $active_steps = $this->get_active_steps();
        $total_steps = count($active_steps);
        
        $current_index = array_search($current_step, $active_steps);
        if ($current_index === false) {
            $current_index = 0;
        }
        
        return round(($current_index / max(1, $total_steps - 1)) * 100);
    }
    
    /**
     * Render the wizard page
     */
    public function render_wizard_page() {
        $current_step = $this->get_current_step();
        $settings = Azure_Settings::get_all_settings();
        
        include AZURE_PLUGIN_PATH . 'admin/setup-wizard-page.php';
    }
    
    /**
     * AJAX: Save step data
     */
    public function ajax_save_step() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $step = intval($_POST['step'] ?? 1);
        $data = $_POST['data'] ?? array();
        
        // Process based on step
        switch ($step) {
            case 2: // Organization Info
                $this->save_organization_info($data);
                break;
                
            case 3: // Module Selection
                $this->save_module_selection($data);
                break;
                
            case 4: // Azure credentials
                $this->save_azure_credentials($data);
                break;
                
            case 5: // Backup settings
                $this->save_backup_settings($data);
                break;
                
            case 7: // SSO settings
                $this->save_sso_settings($data);
                break;
                
            case 8: // OneDrive settings
                $this->save_onedrive_settings($data);
                break;
        }
        
        // Update current step
        Azure_Settings::update_setting('setup_wizard_step', $step);
        
        wp_send_json_success(array(
            'message' => 'Step saved',
            'next_step' => $this->get_next_step($step)
        ));
    }
    
    /**
     * Save organization info
     */
    private function save_organization_info($data) {
        if (isset($data['org_domain'])) {
            Azure_Settings::update_setting('org_domain', sanitize_text_field($data['org_domain']));
        }
        if (isset($data['org_name'])) {
            Azure_Settings::update_setting('org_name', sanitize_text_field($data['org_name']));
        }
        if (isset($data['org_team_name'])) {
            Azure_Settings::update_setting('org_team_name', sanitize_text_field($data['org_team_name']));
        }
        if (isset($data['org_admin_email'])) {
            Azure_Settings::update_setting('org_admin_email', sanitize_email($data['org_admin_email']));
        }
    }
    
    /**
     * Save module selection
     */
    private function save_module_selection($data) {
        $modules = isset($data['modules']) ? (array)$data['modules'] : array();
        $modules = array_map('sanitize_key', $modules);
        
        Azure_Settings::update_setting('setup_wizard_modules', $modules);
        
        // Enable the selected modules
        $module_map = array(
            'sso' => 'enable_sso',
            'calendar' => 'enable_calendar',
            'newsletter' => 'enable_newsletter',
            'backup' => 'enable_backup',
            'pta' => 'enable_pta',
            'onedrive' => 'enable_onedrive_media',
            'classes' => 'enable_classes'
        );
        
        foreach ($module_map as $key => $setting) {
            Azure_Settings::update_setting($setting, in_array($key, $modules));
        }
        
        // Check if using common credentials
        $use_common = isset($data['use_common_credentials']) && $data['use_common_credentials'];
        Azure_Settings::update_setting('use_common_credentials', $use_common);
    }
    
    /**
     * Save Azure credentials
     */
    private function save_azure_credentials($data) {
        if (isset($data['client_id'])) {
            Azure_Settings::update_setting('common_client_id', sanitize_text_field($data['client_id']));
        }
        if (isset($data['client_secret'])) {
            Azure_Settings::update_setting('common_client_secret', sanitize_text_field($data['client_secret']));
        }
        if (isset($data['tenant_id'])) {
            Azure_Settings::update_setting('common_tenant_id', sanitize_text_field($data['tenant_id']));
        }
    }
    
    /**
     * Save backup settings
     */
    private function save_backup_settings($data) {
        if (isset($data['storage_account'])) {
            Azure_Settings::update_setting('backup_storage_account_name', sanitize_text_field($data['storage_account']));
        }
        if (isset($data['container_name'])) {
            Azure_Settings::update_setting('backup_storage_container_name', sanitize_text_field($data['container_name']));
        }
        if (isset($data['storage_key'])) {
            Azure_Settings::update_setting('backup_storage_account_key', sanitize_text_field($data['storage_key']));
        }
    }
    
    /**
     * Save SSO settings
     */
    private function save_sso_settings($data) {
        if (isset($data['show_on_login'])) {
            Azure_Settings::update_setting('sso_show_on_login_page', (bool)$data['show_on_login']);
        }
        if (isset($data['button_text'])) {
            Azure_Settings::update_setting('sso_login_button_text', sanitize_text_field($data['button_text']));
        }
        if (isset($data['default_role'])) {
            Azure_Settings::update_setting('sso_default_role', sanitize_key($data['default_role']));
        }
        if (isset($data['use_custom_role'])) {
            Azure_Settings::update_setting('sso_use_custom_role', (bool)$data['use_custom_role']);
        }
        if (isset($data['custom_role_name'])) {
            Azure_Settings::update_setting('sso_custom_role_name', sanitize_text_field($data['custom_role_name']));
        }
    }
    
    /**
     * Save OneDrive settings
     */
    private function save_onedrive_settings($data) {
        if (isset($data['storage_type'])) {
            Azure_Settings::update_setting('onedrive_media_storage_type', sanitize_key($data['storage_type']));
        }
        if (isset($data['base_folder'])) {
            Azure_Settings::update_setting('onedrive_media_base_folder', sanitize_text_field($data['base_folder']));
        }
        if (isset($data['use_year_folders'])) {
            Azure_Settings::update_setting('onedrive_media_use_year_folders', (bool)$data['use_year_folders']);
        }
    }
    
    /**
     * AJAX: Validate Azure connection
     */
    public function ajax_validate_azure() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        $tenant_id = sanitize_text_field($_POST['tenant_id'] ?? '');
        
        if (empty($client_id) || empty($client_secret) || empty($tenant_id)) {
            wp_send_json_error(array(
                'message' => __('All fields are required', 'azure-plugin'),
                'details' => __('Please fill in Client ID, Client Secret, and Tenant ID.', 'azure-plugin')
            ));
        }
        
        // Validate GUID format for Client ID and Tenant ID
        $guid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        
        if (!preg_match($guid_pattern, $client_id)) {
            wp_send_json_error(array(
                'message' => __('Invalid Client ID format', 'azure-plugin'),
                'details' => __('Client ID should be a GUID (e.g., 12345678-1234-1234-1234-123456789abc)', 'azure-plugin')
            ));
        }
        
        if ($tenant_id !== 'common' && !preg_match($guid_pattern, $tenant_id)) {
            wp_send_json_error(array(
                'message' => __('Invalid Tenant ID format', 'azure-plugin'),
                'details' => __('Tenant ID should be a GUID or "common"', 'azure-plugin')
            ));
        }
        
        // Test the credentials
        $result = Azure_Settings::validate_credentials($client_id, $client_secret, $tenant_id);
        
        if ($result['valid']) {
            Azure_Settings::update_setting('setup_wizard_azure_validated', true);
            wp_send_json_success(array(
                'message' => __('Connection successful!', 'azure-plugin'),
                'details' => __('Your Azure credentials are valid.', 'azure-plugin')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Connection failed', 'azure-plugin'),
                'details' => $result['message'],
                'resolution' => __('Please verify your credentials in the Azure Portal. Ensure the Client Secret has not expired and the app has the correct permissions.', 'azure-plugin')
            ));
        }
    }
    
    /**
     * AJAX: Validate Backup connection
     */
    public function ajax_validate_backup() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $storage_account = sanitize_text_field($_POST['storage_account'] ?? '');
        $container_name = sanitize_text_field($_POST['container_name'] ?? '');
        $storage_key = sanitize_text_field($_POST['storage_key'] ?? '');
        
        if (empty($storage_account) || empty($container_name) || empty($storage_key)) {
            wp_send_json_error(array(
                'message' => __('All fields are required', 'azure-plugin'),
                'details' => __('Please fill in Storage Account, Container Name, and Access Key.', 'azure-plugin')
            ));
        }
        
        // Test connection to Azure Blob Storage
        if (!class_exists('Azure_Backup_Storage')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-azure-storage.php';
        }
        
        if (class_exists('Azure_Backup_Storage')) {
            $storage = new Azure_Backup_Storage();
            $result = $storage->test_connection($storage_account, $storage_key, $container_name);
            
            if ($result['success']) {
                Azure_Settings::update_setting('setup_wizard_backup_validated', true);
                wp_send_json_success(array(
                    'message' => __('Connection successful!', 'azure-plugin'),
                    'details' => __('Connected to Azure Blob Storage.', 'azure-plugin')
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Connection failed', 'azure-plugin'),
                    'details' => $result['message'],
                    'resolution' => __('Please verify your storage account name and access key. Ensure the container exists.', 'azure-plugin')
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => __('Backup storage class not available', 'azure-plugin')
            ));
        }
    }
    
    /**
     * AJAX: Import default tables (PTA)
     */
    public function ajax_import_defaults() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Use the existing PTA import functionality
        if (class_exists('Azure_PTA_Module')) {
            $pta = Azure_PTA_Module::get_instance();
            if (method_exists($pta, 'import_default_data')) {
                $result = $pta->import_default_data();
                if ($result) {
                    wp_send_json_success(array(
                        'message' => __('Default tables imported successfully!', 'azure-plugin')
                    ));
                }
            }
        }
        
        // Fallback - try to trigger the import action
        do_action('azure_pta_import_defaults');
        
        wp_send_json_success(array(
            'message' => __('Import initiated. Check the PTA Roles module for results.', 'azure-plugin')
        ));
    }
    
    /**
     * AJAX: Pull from Azure AD
     */
    public function ajax_pull_azure_ad() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Use the existing Azure AD pull functionality
        if (class_exists('Azure_PTA_Sync_Engine')) {
            $sync = new Azure_PTA_Sync_Engine();
            if (method_exists($sync, 'pull_from_azure_ad')) {
                $result = $sync->pull_from_azure_ad();
                wp_send_json_success(array(
                    'message' => __('Azure AD pull completed!', 'azure-plugin'),
                    'details' => $result
                ));
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Pull initiated. Check the system logs for details.', 'azure-plugin')
        ));
    }
    
    /**
     * AJAX: Complete wizard
     */
    public function ajax_complete_wizard() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        Azure_Settings::update_setting('setup_wizard_completed', true);
        Azure_Settings::update_setting('setup_wizard_step', 9);
        
        wp_send_json_success(array(
            'message' => __('Setup complete!', 'azure-plugin'),
            'redirect' => admin_url('admin.php?page=azure-plugin')
        ));
    }
    
    /**
     * AJAX: Skip wizard
     */
    public function ajax_skip_wizard() {
        check_ajax_referer('azure_setup_wizard', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        Azure_Settings::update_setting('setup_wizard_completed', true);
        
        wp_send_json_success(array(
            'message' => __('Wizard skipped', 'azure-plugin'),
            'redirect' => admin_url('admin.php?page=azure-plugin')
        ));
    }
    
    /**
     * Check if wizard should be shown
     */
    public static function should_show_wizard() {
        if (get_option('azure_restore_completed')) return false;
        return !Azure_Settings::get_setting('setup_wizard_completed', false);
    }
    
    /**
     * Get wizard progress for dashboard
     */
    public static function get_wizard_progress() {
        $instance = self::get_instance();
        return array(
            'completed' => Azure_Settings::get_setting('setup_wizard_completed', false),
            'current_step' => Azure_Settings::get_setting('setup_wizard_step', 1),
            'percent' => $instance->get_progress_percent(),
            'total_steps' => count($instance->get_active_steps())
        );
    }
}

// Initialize
Azure_Setup_Wizard::get_instance();

