<?php
/**
 * Azure Plugin Settings Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Settings {
    
    private static $option_name = 'azure_plugin_settings';
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_Settings();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function register_settings() {
        register_setting('azure_plugin_settings', self::$option_name);
    }
    
    public static function get_all_settings() {
        return get_option(self::$option_name, array());
    }
    
    public static function get_setting($key, $default = '') {
        $settings = self::get_all_settings();
        $value = isset($settings[$key]) ? $settings[$key] : $default;

        // Derive org_domain from WP_HOME if not explicitly set
        if ($key === 'org_domain' && empty($value)) {
            $home = defined('WP_HOME') ? WP_HOME : get_option('home', '');
            $parsed = wp_parse_url($home);
            if (!empty($parsed['host'])) {
                $value = preg_replace('/^www\./', '', $parsed['host']);
            }
        }

        return $value;
    }
    
    public static function update_setting($key, $value) {
        $settings = self::get_all_settings();
        $old_value = isset($settings[$key]) ? $settings[$key] : 'not_set';
        $settings[$key] = $value;
        
        // Debug logging
        error_log("Azure Plugin Settings Debug: Updating key '{$key}' from '{$old_value}' to '{$value}'");
        error_log("Azure Plugin Settings Debug: Option name: '" . self::$option_name . "'");
        error_log("Azure Plugin Settings Debug: Settings array size: " . count($settings));
        error_log("Azure Plugin Settings Debug: Settings content: " . json_encode($settings));
        
        $result = update_option(self::$option_name, $settings);
        
        error_log("Azure Plugin Settings Debug: update_option result: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        if (!$result) {
            // Try to get more info about why it failed
            $current_option = get_option(self::$option_name, 'OPTION_NOT_EXISTS');
            error_log("Azure Plugin Settings Debug: Current option value: " . json_encode($current_option));
            
            // Check if the issue is the option already has the same value
            // WordPress returns false if value hasn't changed, which is actually success
            if (is_array($current_option) && isset($current_option[$key]) && $current_option[$key] == $value) {
                error_log("Azure Plugin Settings Debug: Setting '{$key}' already has value '{$value}' - this is normal");
                return true;
            }
            
            // Also check if entire settings array matches
            if ($current_option === $settings || serialize($current_option) === serialize($settings)) {
                error_log("Azure Plugin Settings Debug: Option already has the same value - this is normal");
                return true;
            }
            
            // Try alternative approaches to fix the issue
            if ($current_option === 'OPTION_NOT_EXISTS') {
                error_log("Azure Plugin Settings Debug: Option doesn't exist, trying add_option");
                $result = add_option(self::$option_name, $settings);
                error_log("Azure Plugin Settings Debug: add_option result: " . ($result ? 'SUCCESS' : 'FAILED'));
            } else {
                // Try deleting and recreating the option
                error_log("Azure Plugin Settings Debug: Trying to delete and recreate option");
                delete_option(self::$option_name);
                $result = add_option(self::$option_name, $settings);
                error_log("Azure Plugin Settings Debug: delete/add_option result: " . ($result ? 'SUCCESS' : 'FAILED'));
            }
        }
        
        return $result;
    }
    
    public static function update_settings($new_settings) {
        $settings = self::get_all_settings();
        $settings = array_merge($settings, $new_settings);
        return update_option(self::$option_name, $settings);
    }
    
    /**
     * Get credentials for a specific module
     * If use_common_credentials is true, returns common credentials
     * Otherwise returns module-specific credentials
     */
    public static function get_credentials($module) {
        $settings = self::get_all_settings();
        $use_common = self::get_setting('use_common_credentials', true);
        
        if ($use_common) {
            return array(
                'client_id' => self::get_setting('common_client_id', ''),
                'client_secret' => self::get_setting('common_client_secret', ''),
                'tenant_id' => self::get_setting('common_tenant_id', 'common')
            );
        }
        
        // Return module-specific credentials
        switch ($module) {
            case 'sso':
                return array(
                    'client_id' => self::get_setting('sso_client_id', ''),
                    'client_secret' => self::get_setting('sso_client_secret', ''),
                    'tenant_id' => self::get_setting('sso_tenant_id', 'common')
                );
            
            case 'backup':
                return array(
                    'client_id' => self::get_setting('backup_client_id', ''),
                    'client_secret' => self::get_setting('backup_client_secret', ''),
                    'tenant_id' => self::get_setting('backup_tenant_id', 'common')
                );
            
            case 'calendar':
                return array(
                    'client_id' => self::get_setting('calendar_client_id', ''),
                    'client_secret' => self::get_setting('calendar_client_secret', ''),
                    'tenant_id' => self::get_setting('calendar_tenant_id', 'common')
                );
            
            case 'email':
                return array(
                    'client_id' => self::get_setting('email_client_id', ''),
                    'client_secret' => self::get_setting('email_client_secret', ''),
                    'tenant_id' => self::get_setting('email_tenant_id', 'common')
                );
            
            case 'pta':
                return array(
                    'client_id' => self::get_setting('pta_client_id', ''),
                    'client_secret' => self::get_setting('pta_client_secret', ''),
                    'tenant_id' => self::get_setting('pta_tenant_id', 'common')
                );
            
            case 'onedrive_media':
                return array(
                    'client_id' => self::get_setting('onedrive_media_client_id', ''),
                    'client_secret' => self::get_setting('onedrive_media_client_secret', ''),
                    'tenant_id' => self::get_setting('onedrive_media_tenant_id', 'common')
                );
            
            default:
                return array(
                    'client_id' => '',
                    'client_secret' => '',
                    'tenant_id' => 'common'
                );
        }
    }
    
    public static function is_module_enabled($module) {
        return self::get_setting("enable_{$module}", false);
    }
    
    public static function enable_module($module, $enabled = true) {
        return self::update_setting("enable_{$module}", $enabled);
    }
    
    public static function get_module_settings($module) {
        $settings = self::get_all_settings();
        $module_settings = array();
        
        $prefix = $module . '_';
        foreach ($settings as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                $module_key = substr($key, strlen($prefix));
                $module_settings[$module_key] = $value;
            }
        }
        
        return $module_settings;
    }
    
    public static function validate_credentials($client_id, $client_secret, $tenant_id = 'common') {
        if (empty($client_id) || empty($client_secret)) {
            return array(
                'valid' => false,
                'message' => 'Client ID and Client Secret are required'
            );
        }
        
        // Basic format validation
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $client_id)) {
            return array(
                'valid' => false,
                'message' => 'Client ID must be a valid UUID format'
            );
        }
        
        // Test the credentials by making a basic auth request
        $auth_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $response = wp_remote_post($auth_url, array(
            'body' => array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'message' => 'Failed to connect to Microsoft: ' . $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return array(
                'valid' => false,
                'message' => 'Authentication failed: ' . $data['error_description']
            );
        }
        
        if (isset($data['access_token'])) {
            return array(
                'valid' => true,
                'message' => 'Credentials are valid'
            );
        }
        
        return array(
            'valid' => false,
            'message' => 'Unexpected response from Microsoft'
        );
    }
    
    public static function get_default_settings() {
        return array(
            // General settings
            'enable_sso' => false,
            'platform_staging_database_name' => '',
            'platform_staging_site_url' => '',
            'enable_backup' => false,
            'enable_calendar' => false,
            'enable_email' => false,
            'enable_pta' => false,
            'enable_classes' => false,
            'enable_newsletter' => false,
            'enable_tickets' => false,
            'enable_auction' => false,
            'auction_display_live' => false,
            'auction_display_card_scale'    => 80,
            'auction_display_cards_wide'    => 4,
            'auction_display_cards_tall'    => 3,
            'auction_display_slide_seconds' => 5,

            // Debug settings
            'debug_mode' => false,
            'debug_modules' => array(), // Empty = all modules, or specific: ['SSO', 'Calendar', 'Email', 'Backup', ...]
            
            // Common credentials
            'use_common_credentials' => true,
            'common_client_id' => '',
            'common_client_secret' => '',
            'common_tenant_id' => 'common',
            
            // SSO specific settings
            'sso_client_id' => '',
            'sso_client_secret' => '',
            'sso_tenant_id' => 'common',
            'sso_redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_sso_callback'),
            'sso_require_sso' => false,
            'sso_auto_create_users' => true,
            'sso_default_role' => 'subscriber',
            'sso_show_on_login_page' => true,
            'sso_login_button_text' => 'Sign in with Microsoft',
            'sso_login_org_heading'   => '',
            'sso_use_custom_role' => false,
            'sso_custom_role_name' => 'AzureAD',
            'sso_sync_enabled' => false,
            'sso_sync_frequency' => 'hourly',
            'sso_preserve_local_data' => false,
            'sso_exclude_external_domains' => false,
            
            // Backup specific settings
            'backup_client_id' => '',
            'backup_client_secret' => '',
            'backup_storage_account' => '',
            'backup_storage_key' => '',
            'backup_container_name' => 'wordpress-backups',
            // New field names (with _name and _key suffixes)
            'backup_storage_account_name' => '',
            'backup_storage_account_key' => '',
            'backup_storage_container_name' => 'wordpress-backups',
            'backup_types' => array('content', 'media', 'plugins', 'themes'),
            'backup_selected_plugins' => array(),
            'backup_selected_themes' => array(),
            'backup_retention_days' => 30,
            'backup_split_size' => 400,
            'backup_max_execution_time' => 300,
            'backup_schedule_enabled' => false,
            'backup_schedule_frequency' => 'daily',
            'backup_schedule_time' => '02:00',
            'backup_email_notifications' => true,
            'backup_notification_email' => get_option('admin_email'),
            
            // Calendar specific settings
            'calendar_client_id' => '',
            'calendar_client_secret' => '',
            'calendar_tenant_id' => '',
            'calendar_embed_user_email' => '',
            'calendar_embed_mailbox_email' => '',
            'calendar_embed_enabled_calendars' => array(),
            'calendar_embed_timezones' => array(),
            'calendar_default_timezone' => 'America/New_York',
            'calendar_default_view' => 'month',
            'calendar_default_color_theme' => 'blue',
            'calendar_cache_duration' => 3600,
            'calendar_max_events_per_calendar' => 100,
            
            // Email specific settings
            'email_client_id' => '',
            'email_client_secret' => '',
            'email_tenant_id' => '',
            'email_auth_method' => 'graph_api',
            'email_send_as_alias' => '',
            'email_override_wp_mail' => false,
            'email_hve_smtp_server' => 'smtp-hve.office365.com',
            'email_hve_smtp_port' => 587,
            'email_hve_username' => '',
            'email_hve_password' => '',
            'email_hve_from_email' => '',
            'email_hve_encryption' => 'tls',
            'email_hve_override_wp_mail' => false,
            'email_acs_connection_string' => '',
            'email_acs_endpoint' => '',
            'email_acs_access_key' => '',
            'email_acs_from_email' => '',
            'email_acs_display_name' => '',
            'email_acs_override_wp_mail' => false,
            
            // PTA specific settings
            'pta_client_id' => '',
            'pta_client_secret' => '',
            'pta_tenant_id' => 'common',
            'pta_sync_enabled' => true,
            'pta_sync_frequency' => 'hourly',
            'pta_auto_provision' => true,
            'pta_delete_azure_users' => true,
            'pta_welcome_email_enabled' => true,
            'pta_license_sku' => 'O365_BUSINESS_ESSENTIALS',

            // PTA Forminator integration
            'pta_forminator_form_id' => '',
            'pta_forminator_role_field_id' => '',
            'pta_forminator_dept_field_id' => '',
            'pta_forminator_fname_field_id' => '',
            'pta_forminator_lname_field_id' => '',
            'pta_forminator_email_field_id' => '',
            'pta_forminator_open_roles_only' => true,
            
            // OneDrive Media specific settings
            'onedrive_media_client_id' => '',
            'onedrive_media_client_secret' => '',
            'onedrive_media_tenant_id' => 'common',
            'onedrive_media_storage_type' => 'onedrive',
            'onedrive_media_sharepoint_site_url' => '',
            'onedrive_media_site_id' => '',
            'onedrive_media_drive_id' => '',
            'onedrive_media_drive_name' => '',
            'onedrive_media_base_folder' => 'WordPress Media',
            'onedrive_media_use_year_folders' => true,
            'onedrive_media_auto_sync' => false,
            'onedrive_media_sync_frequency' => 'hourly',
            'onedrive_media_sync_direction' => 'two_way',
            'onedrive_media_sharing_link_type' => 'anonymous',
            'onedrive_media_link_expiration' => 'never',
            'onedrive_media_cdn_optimization' => true,
            'onedrive_media_show_badge' => true,
            'onedrive_media_keep_local_copies' => true,
            'onedrive_media_max_file_size' => 4294967296,
            'onedrive_media_chunk_size' => 10485760,
            
            // Newsletter specific settings
            'newsletter_sending_service' => 'mailgun',
            'newsletter_batch_size' => 100,
            'newsletter_rate_limit_per_hour' => 1000,
            'newsletter_from_addresses' => array(),
            'newsletter_bounce_mailbox' => '',
            'newsletter_bounce_enabled' => false,
            'newsletter_default_category' => 'newsletter',
            
            // Organization settings (used across modules)
            'org_domain' => '',              // e.g., "yourptsa.net"
            'org_name' => '',                // e.g., "LWSD PTA"
            'org_team_name' => '',           // e.g., "LWSD PTA Team"
            'org_admin_email' => '',         // e.g., "admin@yourptsa.net" (FROM address for system emails)
            
            // Setup wizard settings
            'setup_wizard_completed' => false,
            'setup_wizard_step' => 0,
            'setup_wizard_modules' => array(),
            'setup_wizard_azure_validated' => false,
            'setup_wizard_backup_validated' => false
        );
    }
    
    public static function reset_to_defaults() {
        $defaults = self::get_default_settings();
        return update_option(self::$option_name, $defaults);
    }
    
    public static function export_settings() {
        $settings = self::get_all_settings();
        
        // Remove sensitive data for export
        $safe_settings = $settings;
        $sensitive_keys = array(
            'common_client_secret', 'sso_client_secret', 'backup_client_secret',
            'calendar_client_secret', 'email_client_secret', 'pta_client_secret',
            'onedrive_media_client_secret',
            'backup_storage_key', 'email_hve_password', 'email_acs_access_key'
        );
        
        foreach ($sensitive_keys as $key) {
            if (isset($safe_settings[$key])) {
                $safe_settings[$key] = '***REDACTED***';
            }
        }
        
        return json_encode($safe_settings, JSON_PRETTY_PRINT);
    }
    
    public static function import_settings($json_data) {
        $imported_settings = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON format'
            );
        }
        
        // Filter out redacted values
        foreach ($imported_settings as $key => $value) {
            if ($value === '***REDACTED***') {
                unset($imported_settings[$key]);
            }
        }
        
        $current_settings = self::get_all_settings();
        $merged_settings = array_merge($current_settings, $imported_settings);
        
        if (update_option(self::$option_name, $merged_settings)) {
            return array(
                'success' => true,
                'message' => 'Settings imported successfully'
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Failed to update settings'
        );
    }
}