<?php
/**
 * Plugin Name: PTA Tools
 * Plugin URI: https://github.com/jaburges/PTATools
 * Update URI: https://github.com/jaburges/PTATools/
 * Description: Complete Microsoft 365 integration for WordPress - SSO authentication with Azure AD claims mapping, automated backup to Azure Blob Storage, Outlook calendar embedding with shared mailbox support, TEC calendar sync, email via Microsoft Graph API, PTA role management with O365 Groups sync, WooCommerce class products with TEC event generation, Newsletter module, and OneDrive media integration.
 * Version: 3.49
 * Author: Jamie Burgess
 * License: GPL v2 or later
 * Text Domain: azure-plugin
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AZURE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AZURE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AZURE_PLUGIN_VERSION', '3.49');

// Auto-update from GitHub Releases (Update URI header must match hostname: github.com)
add_filter('update_plugins_github.com', function ($update, array $plugin_data, string $plugin_file, $locales) {
    $me = plugin_basename(__FILE__);
    if ($plugin_file !== $me) {
        return $update;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/jaburges/PTATools/releases/latest',
        [
            'user-agent' => 'PTATools-Updater',
            'timeout'    => 10,
        ]
    );

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        return $update;
    }

    $release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($release['tag_name']) || empty($release['assets'])) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('PTA Tools updater: No tag_name or assets in release response');
        }
        return $update;
    }

    // Use the plugin zip asset by name; GitHub also adds "Source code (zip)" which would break updates.
    $package_url = null;
    foreach ($release['assets'] as $asset) {
        if (!empty($asset['name']) && $asset['name'] === 'pta-tools.zip' && !empty($asset['browser_download_url'])) {
            $package_url = $asset['browser_download_url'];
            break;
        }
    }
    if (!$package_url) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $names = array_map(function ($a) { return $a['name'] ?? ''; }, $release['assets']);
            error_log('PTA Tools updater: pta-tools.zip not found in assets. Asset names: ' . implode(', ', $names));
        }
        return $update;
    }

    $new_version = ltrim($release['tag_name'], 'v');

    if (!version_compare($plugin_data['Version'], $new_version, '<')) {
        return false;
    }

    // Slug must be the plugin directory name so WordPress replaces the correct folder (e.g. "Azure Plugin" not "azure-plugin").
    $slug = dirname($me);

    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log(sprintf('PTA Tools updater: offering update plugin=%s slug=%s from %s to %s package=%s', $me, $slug, $plugin_data['Version'], $new_version, $package_url));
    }

    return [
        'id'      => 'https://github.com/jaburges/PTATools/',
        'slug'    => $slug,
        'plugin'  => $me,
        'version' => $new_version,
        'url'     => $release['html_url'],
        'package' => $package_url,
        'tested'  => '6.9',
    ];
}, 10, 4);

// Main plugin class
class AzurePlugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new AzurePlugin();
        }
        return self::$instance;
    }
    
    private function __construct() {
        try {
            // Register hooks
            add_action('plugins_loaded', array($this, 'load_dependencies'), 5);
            add_action('init', array($this, 'init'), 10);
            
            // Activation/deactivation hooks must be registered immediately
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));
            
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Plugin constructor failed: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => __FILE__,
                    'line' => __LINE__
                ));
            }
            error_log('Azure Plugin: Constructor error - ' . $e->getMessage());
        }
    }
    
    public function load_dependencies() {
        try {
            // Common utilities - CRITICAL FILES
            $critical_files = array(
                'class-logger.php' => 'Logger class',
                'class-database.php' => 'Database class',
                'class-admin.php' => 'Admin class',
                'class-settings.php' => 'Settings class'
            );
            
            // Optional feature files
            $optional_files = array(
                // SSO functionality
                'class-sso-auth.php' => 'SSO Auth class',
                'class-sso-shortcode.php' => 'SSO Shortcode class',
                'class-sso-sync.php' => 'SSO Sync class',
                
                // User Account functionality
                'class-user-account-shortcode.php' => 'User Account Shortcode class',
                
                // Backup functionality
                'class-backup-engine.php' => 'Backup Engine class',
                'class-backup.php' => 'Backup class',
                'class-backup-restore.php' => 'Backup Restore class',
                'class-backup-azure-storage.php' => 'Backup Azure Storage class',
                'class-backup-scheduler.php' => 'Backup Scheduler class',
                
                // Calendar functionality
                'class-calendar-auth.php' => 'Calendar Auth class',
                'class-calendar-graph-api.php' => 'Calendar Graph API class',
                'class-calendar-manager.php' => 'Calendar Manager class',
                'class-calendar-renderer.php' => 'Calendar Renderer class',
                'class-calendar-shortcode.php' => 'Calendar Shortcode class',
                'class-calendar-events-cpt.php' => 'Calendar Events CPT class',
                'class-calendar-ical-sync.php' => 'Calendar iCal Sync class',
                'class-calendar-events-shortcode.php' => 'Calendar Events Shortcode class',
                
                // Email functionality
                'class-email-auth.php' => 'Email Auth class',
                'class-email-mailer.php' => 'Email Mailer class',
                'class-email-shortcode.php' => 'Email Shortcode class',
                'class-email-logger.php' => 'Email Logger class',
                
                // TEC Integration functionality
                'class-tec-integration.php' => 'TEC Integration class',
                'class-tec-sync-engine.php' => 'TEC Sync Engine class',
                'class-tec-data-mapper.php' => 'TEC Data Mapper class',
                'class-tec-calendar-mapping-manager.php' => 'TEC Calendar Mapping Manager class',
                'class-tec-sync-scheduler.php' => 'TEC Sync Scheduler class',
                'class-tec-integration-ajax.php' => 'TEC Integration AJAX handlers class',
                
                // PTA functionality
                'class-pta-database.php' => 'PTA Database class',
                'class-pta-manager.php' => 'PTA Manager class',
                'class-pta-sync-engine.php' => 'PTA Sync Engine class',
                'class-pta-groups-manager.php' => 'PTA Groups Manager class',
                'class-pta-shortcode.php' => 'PTA Shortcode class',
                'class-pta-forminator.php' => 'PTA Forminator Integration class',
                'class-pta-beaver-builder.php' => 'PTA Beaver Builder class',
                
                // OneDrive Media functionality
                'class-onedrive-media-auth.php' => 'OneDrive Media Auth class',
                'class-onedrive-media-graph-api.php' => 'OneDrive Media Graph API class',
                'class-onedrive-media-manager.php' => 'OneDrive Media Manager class',
                
                // Classes functionality
                'class-classes-module.php' => 'Classes Module class',
                
                // Upcoming Events shortcode functionality
                'class-upcoming-module.php' => 'Upcoming Events Module class',
                
                // Newsletter functionality
                'class-newsletter-module.php' => 'Newsletter Module class',
                
                // Auction functionality
                'class-auction-module.php' => 'Auction Module class',
                
                // Product Fields functionality
                'class-product-fields-module.php' => 'Product Fields Module class',
                'class-user-children.php' => 'User Children Profiles class',
                
                // Donations functionality
                'class-donations-module.php' => 'Donations Module class',
                
                // Volunteer Sign Up functionality
                'class-volunteer-signup.php' => 'Volunteer Sign Up Module class',
                
                // Setup Wizard
                'class-setup-wizard.php' => 'Setup Wizard class',
                
                // Restore Wizard
                'class-restore-wizard.php' => 'Restore Wizard class',
                
                // Diagnostics REST API
                'class-diagnostics-api.php' => 'Diagnostics REST API class'
            );
            
            // Load critical files first — log errors but never throw/fatal
            $critical_ok = true;
            foreach ($critical_files as $file => $description) {
                $file_path = AZURE_PLUGIN_PATH . 'includes/' . $file;
                
                if (!file_exists($file_path)) {
                    error_log("Azure Plugin: Critical file not found: {$file_path}");
                    $critical_ok = false;
                    continue;
                }
                
                try {
                    require_once $file_path;
                } catch (\Throwable $e) {
                    error_log("Azure Plugin: Error loading critical file {$file}: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                    $critical_ok = false;
                }
            }
            
            if (!$critical_ok) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p><strong>PTA Tools:</strong> One or more core files could not be loaded. Check the PHP error log for details.</p></div>';
                });
            }
            
            // Load optional files — failures are logged but never stop loading
            foreach ($optional_files as $file => $description) {
                $file_path = AZURE_PLUGIN_PATH . 'includes/' . $file;
                
                if (!file_exists($file_path)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Azure Plugin: Optional file not found: {$file_path}");
                    }
                    continue;
                }
                
                try {
                    require_once $file_path;
                } catch (\Throwable $e) {
                    error_log("Azure Plugin: Error loading optional file {$file}: " . $e->getMessage());
                }
            }
            
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Failed to load dependencies: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ));
            }
            error_log('Azure Plugin: load_dependencies failed - ' . $e->getMessage());
        }
    }
    
    public function init() {
        try {
            // Initialize logger first if not already initialized
            if (class_exists('Azure_Logger') && !Azure_Logger::is_initialized()) {
                Azure_Logger::init();
            }
            
            // Register scheduled log cleanup hook (scheduling is done during activation)
            add_action('azure_plugin_cleanup_logs', array('Azure_Logger', 'scheduled_cleanup'));
            
            // Load plugin textdomain
            load_plugin_textdomain('azure-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
            
            // Initialize settings system
            if (class_exists('Azure_Settings')) {
                Azure_Settings::get_instance();
            }
            
            // Run DB migrations on version change (dbDelta is safe to re-run)
            $stored_version = get_option('azure_plugin_db_version', '0');
            if (version_compare($stored_version, AZURE_PLUGIN_VERSION, '<')) {
                if (class_exists('Azure_Database')) {
                    Azure_Database::create_tables();
                }
                flush_rewrite_rules();
                update_option('azure_plugin_db_version', AZURE_PLUGIN_VERSION);
            }
            
            // Initialize admin components
            if (is_admin() && class_exists('Azure_Admin')) {
                Azure_Admin::get_instance();
            }
            
            // Diagnostics REST API (always active for remote monitoring)
            if (class_exists('Azure_Diagnostics_API')) {
                Azure_Diagnostics_API::get_instance();
            }
            
            // Get settings for module initialization
            $settings = get_option('azure_plugin_settings', array());
            
            // Initialize enabled modules
            if (!empty($settings['enable_sso'])) {
                $this->init_sso_components();
            }
            
            if (!empty($settings['enable_backup'])) {
                $this->init_backup_components();
            }
            
            if (!empty($settings['enable_calendar'])) {
                $this->init_calendar_components();
            }
            
            // Always register TEC AJAX handlers so settings / toggles / auth work
            // even when the module is mid-enable (toggle just flipped but init
            // for heavy components hasn't run this request yet).
            if (is_admin() && class_exists('Azure_TEC_Integration_Ajax')) {
                try {
                    new Azure_TEC_Integration_Ajax();
                } catch (\Throwable $e) {
                    error_log('Azure Plugin: Failed to init TEC AJAX handlers - ' . $e->getMessage());
                }
            }

            if ($settings['enable_tec_integration'] ?? false) {
                $this->init_tec_components();
            }
            
            // Always initialize Email Logger (logs all WordPress emails)
            $this->init_email_logger();
            
            if (!empty($settings['enable_email'])) {
                $this->init_email_components();
            }
            
            if (!empty($settings['enable_pta'])) {
                $this->init_pta_components();
            }
            
            if (!empty($settings['enable_onedrive_media'])) {
                $this->init_onedrive_media_components();
            }
            
            // Always register Classes taxonomy (needed for admin URLs even when module is disabled)
            $this->register_classes_taxonomy();
            
            if (!empty($settings['enable_classes'])) {
                $this->init_classes_components();
            }
            
            // Upcoming Events module - always available (no credentials needed)
            $this->init_upcoming_components();
            
            // Always load newsletter AJAX handlers (needed for table creation even when module is disabled)
            $this->load_newsletter_ajax();
            
            if (!empty($settings['enable_newsletter'])) {
                $this->init_newsletter_components();
            }
            
            if (!empty($settings['enable_auction'])) {
                $this->init_auction_components();
            }
            
            if (!empty($settings['enable_product_fields'])) {
                $this->init_product_fields_components();
            }
            
            if (!empty($settings['enable_donations']) || is_admin()) {
                $this->init_donations_components();
            }
            
            if (!empty($settings['enable_volunteer'])) {
                $this->init_volunteer_components();
            }
            
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Plugin init failed: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => __FILE__,
                    'line' => __LINE__
                ));
            }
            error_log('Azure Plugin: init error - ' . $e->getMessage());
        }
    }
    
    private function init_sso_components() {
        try {
            if (class_exists('Azure_SSO_Auth')) {
                new Azure_SSO_Auth();
                Azure_Logger::debug_module('SSO', 'Azure_SSO_Auth initialized successfully');
            }
            if (class_exists('Azure_SSO_Shortcode')) {
                new Azure_SSO_Shortcode();
                Azure_Logger::debug_module('SSO', 'Azure_SSO_Shortcode initialized successfully');
            }
            if (class_exists('Azure_SSO_Sync')) {
                new Azure_SSO_Sync();
                Azure_Logger::debug_module('SSO', 'Azure_SSO_Sync initialized successfully');
            }
            if (class_exists('Azure_User_Account_Shortcode')) {
                new Azure_User_Account_Shortcode();
                Azure_Logger::debug_module('SSO', 'Azure_User_Account_Shortcode initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('SSO init failed: ' . $e->getMessage(), array(
                'module' => 'SSO',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: SSO init error - ' . $e->getMessage());
        }
    }
    
    private function init_backup_components() {
        try {
            // Initialize backup components in the correct order to avoid dependency issues
            // Storage class should NOT be instantiated here as it's only used internally by other classes
            if (class_exists('Azure_Backup')) {
                new Azure_Backup();
                Azure_Logger::debug_module('Backup', 'Azure_Backup initialized successfully');
            }
            if (class_exists('Azure_Backup_Restore')) {
                new Azure_Backup_Restore();
                Azure_Logger::debug_module('Backup', 'Azure_Backup_Restore initialized successfully');
            }
            if (class_exists('Azure_Backup_Scheduler')) {
                new Azure_Backup_Scheduler();
                Azure_Logger::debug_module('Backup', 'Azure_Backup_Scheduler initialized successfully');
            }
            // Note: Azure_Backup_Storage is not instantiated here - it's created on-demand by other classes
        } catch (\Throwable $e) {
            Azure_Logger::error('Backup init failed: ' . $e->getMessage(), array(
                'module' => 'Backup',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Backup init error - ' . $e->getMessage());
        }
    }
    
    private function init_calendar_components() {
        try {
            if (class_exists('Azure_Calendar_Auth')) {
                new Azure_Calendar_Auth();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_Auth initialized successfully');
            }
            if (class_exists('Azure_Calendar_GraphAPI')) {
                new Azure_Calendar_GraphAPI();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_GraphAPI initialized successfully');
            }
            if (class_exists('Azure_Calendar_Manager')) {
                new Azure_Calendar_Manager();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_Manager initialized successfully');
            }
            if (class_exists('Azure_Calendar_Shortcode')) {
                new Azure_Calendar_Shortcode();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_Shortcode initialized successfully');
            }
            if (class_exists('Azure_Calendar_EventsCPT')) {
                new Azure_Calendar_EventsCPT();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_EventsCPT initialized successfully');
            }
            if (class_exists('Azure_Calendar_ICalSync')) {
                new Azure_Calendar_ICalSync();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_ICalSync initialized successfully');
            }
            if (class_exists('Azure_Calendar_EventsShortcode')) {
                new Azure_Calendar_EventsShortcode();
                Azure_Logger::debug_module('Calendar', 'Azure_Calendar_EventsShortcode initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar init failed: ' . $e->getMessage(), array(
                'module' => 'Calendar',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Calendar init error - ' . $e->getMessage());
        }
    }
    
    private function init_tec_components() {
        try {
            if (class_exists('Azure_TEC_Integration')) {
                Azure_TEC_Integration::get_instance();
                Azure_Logger::debug_module('TEC', 'Azure_TEC_Integration initialized successfully');
            }

            // Note: Azure_TEC_Integration_Ajax is always initialized earlier in init()
            // (not gated on enable_tec_integration) so save/auth AJAX always works.

            // Initialize TEC Sync Scheduler
            if (class_exists('Azure_TEC_Sync_Scheduler')) {
                new Azure_TEC_Sync_Scheduler();
                Azure_Logger::debug_module('TEC', 'Azure_TEC_Sync_Scheduler initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('TEC init failed: ' . $e->getMessage(), array(
                'module' => 'TEC',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: TEC init error - ' . $e->getMessage());
        }
    }
    
    private function init_email_logger() {
        // Initialize Email Logger to hook into wp_mail and register AJAX handlers
        if (class_exists('Azure_Email_Logger')) {
            Azure_Email_Logger::get_instance();
            Azure_Logger::debug('Email Logger: Initialized successfully with AJAX handlers');
        } else {
            Azure_Logger::warning('Email Logger: Azure_Email_Logger class not found');
        }
    }
    
    private function init_email_components() {
        try {
            if (class_exists('Azure_Email_Auth')) {
                new Azure_Email_Auth();
                Azure_Logger::debug_module('Email', 'Azure_Email_Auth initialized successfully');
            }
            
            if (class_exists('Azure_Email_Mailer')) {
                new Azure_Email_Mailer();
                Azure_Logger::debug_module('Email', 'Azure_Email_Mailer initialized successfully');
            }
            
            if (class_exists('Azure_Email_Shortcode')) {
                new Azure_Email_Shortcode();
                Azure_Logger::debug_module('Email', 'Azure_Email_Shortcode initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Email init failed: ' . $e->getMessage(), array(
                'module' => 'Email',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Email init error - ' . $e->getMessage());
        }
    }
    
    private function init_pta_components() {
        try {
            Azure_Logger::debug_module('PTA', 'Starting PTA components initialization');
            
            if (class_exists('Azure_PTA_Database')) {
                Azure_PTA_Database::init();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Database initialized successfully');
            }
            
            if (class_exists('Azure_PTA_Manager')) {
                Azure_PTA_Manager::get_instance();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Manager initialized successfully');
            }
            
            if (class_exists('Azure_PTA_Sync_Engine')) {
                new Azure_PTA_Sync_Engine();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Sync_Engine initialized successfully');
            }
            
            if (class_exists('Azure_PTA_Groups_Manager')) {
                new Azure_PTA_Groups_Manager();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Groups_Manager initialized successfully');
            }
            
            if (class_exists('Azure_PTA_Shortcode')) {
                new Azure_PTA_Shortcode();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Shortcode initialized successfully');
            }
            
            if (class_exists('Azure_PTA_BeaverBuilder')) {
                new Azure_PTA_BeaverBuilder();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_BeaverBuilder initialized successfully');
            }

            if (class_exists('Azure_PTA_Forminator')) {
                Azure_PTA_Forminator::get_instance();
                Azure_Logger::debug_module('PTA', 'Azure_PTA_Forminator initialized successfully');
            }
            
            Azure_Logger::debug_module('PTA', 'All PTA components initialized successfully');
            
        } catch (\Throwable $e) {
            Azure_Logger::error('PTA init failed: ' . $e->getMessage(), array(
                'module' => 'PTA',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: PTA init error - ' . $e->getMessage());
        }
    }
    
    private function init_onedrive_media_components() {
        try {
            Azure_Logger::debug_module('OneDrive', 'Starting OneDrive Media components initialization');
            
            if (class_exists('Azure_OneDrive_Media_Auth')) {
                new Azure_OneDrive_Media_Auth();
                Azure_Logger::debug_module('OneDrive', 'Azure_OneDrive_Media_Auth initialized successfully');
            }
            
            if (class_exists('Azure_OneDrive_Media_GraphAPI')) {
                new Azure_OneDrive_Media_GraphAPI();
                Azure_Logger::debug_module('OneDrive', 'Azure_OneDrive_Media_GraphAPI initialized successfully');
            }
            
            if (class_exists('Azure_OneDrive_Media_Manager')) {
                Azure_OneDrive_Media_Manager::get_instance();
                Azure_Logger::debug_module('OneDrive', 'Azure_OneDrive_Media_Manager initialized successfully');
            }
            
            Azure_Logger::debug_module('OneDrive', 'All OneDrive Media components initialized successfully');
            
        } catch (\Throwable $e) {
            Azure_Logger::error('OneDrive Media init failed: ' . $e->getMessage(), array(
                'module' => 'OneDrive',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: OneDrive Media init error - ' . $e->getMessage());
        }
    }
    
    /**
     * Register Classes taxonomy (always, even when module is disabled)
     * This ensures admin URLs work and the taxonomy is available
     */
    private function register_classes_taxonomy() {
        // Only register if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Check if already registered
        if (taxonomy_exists('class_provider')) {
            return;
        }
        
        $labels = array(
            'name'              => _x('Class Providers', 'taxonomy general name', 'azure-plugin'),
            'singular_name'     => _x('Class Provider', 'taxonomy singular name', 'azure-plugin'),
            'search_items'      => __('Search Providers', 'azure-plugin'),
            'all_items'         => __('All Providers', 'azure-plugin'),
            'edit_item'         => __('Edit Provider', 'azure-plugin'),
            'update_item'       => __('Update Provider', 'azure-plugin'),
            'add_new_item'      => __('Add New Provider', 'azure-plugin'),
            'new_item_name'     => __('New Provider Name', 'azure-plugin'),
            'menu_name'         => __('Class Providers', 'azure-plugin'),
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rewrite'           => array('slug' => 'class-provider'),
        );
        
        register_taxonomy('class_provider', array('product'), $args);
    }
    
    private function init_classes_components() {
        try {
            Azure_Logger::debug_module('Classes', 'Starting Classes module initialization');
            
            if (class_exists('Azure_Classes_Module')) {
                Azure_Classes_Module::get_instance();
                Azure_Logger::debug_module('Classes', 'Azure_Classes_Module initialized successfully');
            }
            
            Azure_Logger::debug_module('Classes', 'All Classes components initialized successfully');
            
        } catch (\Throwable $e) {
            Azure_Logger::error('Classes init failed: ' . $e->getMessage(), array(
                'module' => 'Classes',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Classes init error - ' . $e->getMessage());
        }
    }
    
    private function init_upcoming_components() {
        try {
            if (class_exists('Azure_Upcoming_Module')) {
                Azure_Upcoming_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Upcoming Events init failed: ' . $e->getMessage(), array(
                'module' => 'Upcoming',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
        }
    }
    
    /**
     * Load Newsletter AJAX handlers (always needed for table management)
     */
    private function load_newsletter_ajax() {
        // Only load in admin AJAX context
        if (!is_admin()) {
            return;
        }
        
        $ajax_file = AZURE_PLUGIN_PATH . 'includes/class-newsletter-ajax.php';
        if (file_exists($ajax_file) && !class_exists('Azure_Newsletter_Ajax')) {
            require_once $ajax_file;
        }
    }
    
    private function init_newsletter_components() {
        try {
            Azure_Logger::debug_module('Newsletter', 'Starting Newsletter module initialization');
            
            if (class_exists('Azure_Newsletter_Module')) {
                Azure_Newsletter_Module::get_instance();
                Azure_Logger::debug_module('Newsletter', 'Azure_Newsletter_Module initialized successfully');
            }
            
            Azure_Logger::debug_module('Newsletter', 'All Newsletter components initialized successfully');
            
        } catch (\Throwable $e) {
            Azure_Logger::error('Newsletter init failed: ' . $e->getMessage(), array(
                'module' => 'Newsletter',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Newsletter init error - ' . $e->getMessage());
        }
    }
    
    private function init_auction_components() {
        try {
            if (class_exists('Azure_Auction_Module')) {
                Azure_Auction_Module::get_instance();
                Azure_Logger::debug_module('Auction', 'Auction Module initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Auction init failed: ' . $e->getMessage(), array(
                'module' => 'Auction',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Auction init error - ' . $e->getMessage());
        }
    }
    
    private function init_product_fields_components() {
        try {
            if (class_exists('Azure_Product_Fields_Module')) {
                Azure_Product_Fields_Module::get_instance();
                Azure_Logger::debug_module('ProductFields', 'Product Fields Module initialized successfully');
            }
            if (class_exists('Azure_User_Children')) {
                Azure_User_Children::get_instance();
                Azure_Logger::debug_module('ProductFields', 'User Children Profiles initialized');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Product Fields init failed: ' . $e->getMessage(), array(
                'module' => 'ProductFields',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Product Fields init error - ' . $e->getMessage());
        }
    }
    
    private function init_donations_components() {
        try {
            if (class_exists('Azure_Donations_Module')) {
                Azure_Donations_Module::get_instance();
                Azure_Logger::debug_module('Donations', 'Donations Module initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Donations init failed: ' . $e->getMessage(), array(
                'module' => 'Donations',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Donations init error - ' . $e->getMessage());
        }
    }
    
    private function init_volunteer_components() {
        try {
            if (class_exists('Azure_Volunteer_Signup')) {
                Azure_Volunteer_Signup::get_instance();
                Azure_Logger::debug_module('Volunteer', 'Volunteer Sign Up Module initialized successfully');
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Volunteer init failed: ' . $e->getMessage(), array(
                'module' => 'Volunteer',
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            error_log('Azure Plugin: Volunteer init error - ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin activation with comprehensive debugging
     */
    public function activate() {
        // Create debug log file immediately
        $log_file = AZURE_PLUGIN_PATH . 'logs.md';
        $timestamp = date('Y-m-d H:i:s');
        $header = file_exists($log_file) ? '' : "# Azure Plugin Activation Debug Logs\n\n";
        
        // Helper function to write debug logs
        $write_log = function($message) use ($log_file, $timestamp) {
            $log_entry = "**{$timestamp}** {$message}  \n";
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        };
        
        $write_log($header . "🚀 **[START]** Plugin activation initiated");
        $write_log("📁 **[DEBUG]** Plugin path: " . AZURE_PLUGIN_PATH);
        $write_log("🌐 **[DEBUG]** WordPress version: " . get_bloginfo('version'));
        $write_log("🗄️ **[DEBUG]** Database version: " . $GLOBALS['wpdb']->db_version());
        
        try {
            $write_log("⏳ **[STEP 1]** Starting activation process");
            
            // Check if required directories exist
            $includes_dir = AZURE_PLUGIN_PATH . 'includes/';
            if (!is_dir($includes_dir)) {
                throw new Exception("Includes directory not found: {$includes_dir}");
            }
            $write_log("✅ **[STEP 1]** Includes directory exists");
            
            $write_log("⏳ **[STEP 2]** Loading core classes");
            // Ensure logger is available
            $logger_file = AZURE_PLUGIN_PATH . 'includes/class-logger.php';
            if (!file_exists($logger_file)) {
                throw new Exception("Logger class file not found: {$logger_file}");
            }
            
            if (!class_exists('Azure_Logger')) {
                require_once $logger_file;
                if (!class_exists('Azure_Logger')) {
                    throw new Exception("Failed to load Azure_Logger class after require");
                }
            }
            $write_log("✅ **[STEP 2]** Logger class loaded successfully");
            
            $write_log("⏳ **[STEP 3]** Loading database class");
            // Ensure database class is available
            $db_file = AZURE_PLUGIN_PATH . 'includes/class-database.php';
            if (!file_exists($db_file)) {
                throw new Exception("Database class file not found: {$db_file}");
            }
            
            if (!class_exists('Azure_Database')) {
                require_once $db_file;
                if (!class_exists('Azure_Database')) {
                    throw new Exception("Failed to load Azure_Database class after require");
                }
            }
            $write_log("✅ **[STEP 3]** Database class loaded successfully");
            
            $write_log("⏳ **[STEP 4]** Starting logger-based logging");
            // Log with our logger
            Azure_Logger::info('Azure Plugin activation started');
            $write_log("✅ **[STEP 4]** Logger-based logging working");
            
            $write_log("⏳ **[STEP 5]** Checking database connection");
            global $wpdb;
            $db_test = $wpdb->get_var("SELECT 1");
            if ($db_test !== '1') {
                throw new Exception("Database connection test failed");
            }
            $write_log("✅ **[STEP 5]** Database connection verified");
            
            $write_log("⏳ **[STEP 6]** Creating database tables");
            // Create database tables (including auction_bids and other module tables)
            try {
                Azure_Logger::info('Creating database tables');
                Azure_Database::create_tables();
                Azure_Logger::info('Database tables created successfully');
            } catch (\Throwable $e) {
                $write_log("❌ **[STEP 6 ERROR]** Failed to create main tables: " . $e->getMessage());
                Azure_Logger::error('Database table creation failed: ' . $e->getMessage());
            }
            
            // Create PTA database tables
            if (class_exists('Azure_PTA_Database')) {
                try {
                    Azure_Logger::info('Creating PTA database tables');
                    Azure_PTA_Database::create_pta_tables();
                    Azure_Logger::info('PTA database tables created successfully');
                    
                    $write_log("⏳ **[STEP 6b]** Seeding PTA data from CSV");
                    Azure_Logger::info('Seeding PTA initial data from CSV');
                    Azure_PTA_Database::seed_initial_data(false);
                    Azure_Logger::info('PTA initial data seeded successfully');
                    $write_log("✅ **[STEP 6b]** PTA data seeded from CSV");
                } catch (\Throwable $e) {
                    $write_log("❌ **[STEP 6 ERROR]** Failed to create/seed PTA tables: " . $e->getMessage());
                    Azure_Logger::error('Failed to create/seed PTA database tables: ' . $e->getMessage());
                }
            }
            
            // Create Newsletter database tables
            if (class_exists('Azure_Newsletter_Module')) {
                try {
                    $write_log("⏳ **[STEP 6c]** Creating Newsletter database tables");
                    Azure_Logger::info('Creating Newsletter database tables');
                    Azure_Newsletter_Module::create_tables();
                    Azure_Logger::info('Newsletter database tables created successfully');
                    $write_log("✅ **[STEP 6c]** Newsletter tables created");
                } catch (\Throwable $e) {
                    $write_log("❌ **[STEP 6c ERROR]** Failed to create Newsletter tables: " . $e->getMessage());
                    Azure_Logger::error('Failed to create Newsletter database tables: ' . $e->getMessage());
                }
            }
            
            $write_log("✅ **[STEP 6]** Database tables created and seeded");
            
            $write_log("⏳ **[STEP 7]** Creating AzureAD role");
            // Create AzureAD WordPress role
            $this->create_azuread_role();
            $write_log("✅ **[STEP 7]** AzureAD role created");
            
            $write_log("⏳ **[STEP 8]** Setting default options");
            // Set default options
            Azure_Logger::info('Setting default options');
            if (!get_option('azure_plugin_settings')) {
                $default_settings = array(
                    // General settings
                    'enable_sso' => false,
                    'enable_backup' => false,
                    'enable_calendar' => false,
                    'enable_email' => false,
                    'enable_pta' => false,
                    'enable_tec_integration' => false,
                    
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
                    
                    // Backup specific settings
                    'backup_client_id' => '',
                    'backup_client_secret' => '',
                    'backup_storage_account' => '',
                    'backup_storage_key' => '',
                    'backup_container_name' => 'wordpress-backups',
                    'backup_types' => array('content', 'media', 'plugins', 'themes'),
                    'backup_retention_days' => 30,
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
                    
                    // TEC Integration specific settings
                    'tec_outlook_calendar_id' => 'primary',
                    'tec_default_venue' => 'School Campus',
                    'tec_default_organizer' => 'PTSA',
                    'tec_organizer_email' => get_option('admin_email'),
                    'tec_sync_frequency' => 'hourly',
                    'tec_conflict_resolution' => 'outlook_wins',
                    'tec_include_event_url' => true,
                    'tec_event_footer' => '',
                    'tec_default_category' => 'School Event'
                );
                update_option('azure_plugin_settings', $default_settings);
                Azure_Logger::info('Default settings created');
                $write_log("✅ **[STEP 7]** Default settings created");
            } else {
                $write_log("ℹ️ **[STEP 7]** Settings already exist, skipping");
            }
            
            $write_log("⏳ **[STEP 8]** Creating backup directory");
            // Create backup directory
            $backup_dir = AZURE_PLUGIN_PATH . 'backups/';
            if (!is_dir($backup_dir)) {
                if (!wp_mkdir_p($backup_dir)) {
                    throw new Exception("Failed to create backup directory: {$backup_dir}");
                }
                // Add .htaccess for security
                $htaccess_content = "Order deny,allow\nDeny from all\n";
                file_put_contents($backup_dir . '.htaccess', $htaccess_content);
            }
            $write_log("✅ **[STEP 8]** Backup directory ready");
            
            $write_log("⏳ **[STEP 9]** Finalizing activation");
            Azure_Logger::info('Plugin activation completed successfully');
            $write_log("🎉 **[SUCCESS]** Plugin activation completed successfully");
            
        } catch (\Throwable $e) {
            $error_msg = 'Failed during activation: ' . $e->getMessage();
            $write_log("❌ **[ERROR]** {$error_msg}");
            $write_log("📍 **[ERROR FILE]** " . $e->getFile() . " line " . $e->getLine());
            $write_log("📝 **[ERROR TRACE]** " . $e->getTraceAsString());
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error($error_msg);
                Azure_Logger::error('Error in file: ' . $e->getFile() . ' line ' . $e->getLine());
                Azure_Logger::error('Stack trace: ' . $e->getTraceAsString());
            } else {
                error_log('Azure Plugin: ' . $error_msg);
            }
            
            wp_die(
                'PTA Tools activation failed: ' . esc_html($e->getMessage()) . '<br><br>Check <code>' . esc_html($log_file) . '</code> for detailed logs.<br><br><a href="' . esc_url(admin_url('plugins.php')) . '">Back to Plugins</a>',
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Azure Plugin deactivated');
        }
        
        // Clear any scheduled events from all modules
        wp_clear_scheduled_hook('azure_backup_scheduled');
        wp_clear_scheduled_hook('azure_backup_cleanup');
        wp_clear_scheduled_hook('azure_calendar_sync_events');
        wp_clear_scheduled_hook('azure_mail_token_refresh');
        wp_clear_scheduled_hook('azure_sso_scheduled_sync');
        wp_clear_scheduled_hook('azure_volunteer_send_reminders');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create AzureAD WordPress role for SSO users
     */
    private function create_azuread_role() {
        // Check if role already exists
        if (get_role('azuread')) {
            Azure_Logger::info('AzureAD role already exists');
            return;
        }
        
        // Add AzureAD role with basic capabilities
        add_role('azuread', 'Azure AD User', array(
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            'publish_posts' => false,
            'upload_files' => false,
            'edit_pages' => false,
            'edit_others_posts' => false,
            'edit_published_posts' => false,
            'delete_others_posts' => false,
            'delete_published_posts' => false,
            'delete_pages' => false,
            'manage_categories' => false,
            'manage_links' => false,
            'moderate_comments' => false,
            'unfiltered_html' => false,
            'edit_others_pages' => false,
            'edit_published_pages' => false,
            'delete_others_pages' => false,
            'delete_published_pages' => false
        ));
        
        Azure_Logger::info('Created AzureAD role for SSO users');
    }
}

// Initialize the plugin
try {
    AzurePlugin::get_instance();
} catch (\Throwable $e) {
    if (class_exists('Azure_Logger')) {
        Azure_Logger::error('Plugin initialization failed: ' . $e->getMessage(), array(
            'module' => 'Core',
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ));
    }
    error_log('Azure Plugin: Fatal initialization error - ' . $e->getMessage());
}