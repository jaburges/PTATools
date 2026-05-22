<?php
/**
 * TEC Integration - STEP BY STEP TESTING VERSION
 */

if (!defined('ABSPATH')) {
    exit;
}

error_log('TEC Integration Test: 1. File started loading');

class Azure_TEC_Integration {
    
    private static $instance = null;
    private $sync_engine;
    private $data_mapper;
    private $settings;
    
    public static function get_instance() {
        error_log('TEC Integration Test: 4. get_instance called');
        if (self::$instance == null) {
            error_log('TEC Integration Test: 5. Creating new instance');
            self::$instance = new Azure_TEC_Integration();
            error_log('TEC Integration Test: 6. Instance created');
        }
        return self::$instance;
    }
    
    public function __construct() {
        error_log('TEC Integration Test: 🚀 CONSTRUCTOR STARTED - Step by step testing');
        
        try {
            error_log('TEC Integration Test: Step 1 - Checking Azure_Logger availability');
            if (!class_exists('Azure_Logger')) {
                error_log('TEC Integration Test: ❌ Azure_Logger not available - exiting constructor');
                return;
            }
            error_log('TEC Integration Test: ✅ Step 1 - Azure_Logger available');
            Azure_Logger::info('TEC Integration Test: Constructor started with Azure_Logger available', 'TEC');
            
            error_log('TEC Integration Test: Step 2 - Checking TEC plugin active');
            if (!$this->is_tec_active()) {
                error_log('TEC Integration Test: ℹ️ Step 2 - TEC not active, adding admin notice');
                add_action('admin_notices', array($this, 'tec_dependency_notice'));
                Azure_Logger::info('TEC Integration Test: The Events Calendar not active, showing dependency notice', 'TEC');
                error_log('TEC Integration Test: ✅ Step 2 - TEC dependency notice added successfully');
                return;
            }
            error_log('TEC Integration Test: ✅ Step 2 - TEC is active');
            Azure_Logger::info('TEC Integration Test: The Events Calendar is active, proceeding with initialization', 'TEC');
            
            error_log('TEC Integration Test: Step 2.5 - Loading settings');
            if (class_exists('Azure_Settings')) {
                $this->settings = Azure_Settings::get_all_settings();
                error_log('TEC Integration Test: ✅ Step 2.5 - Settings loaded successfully');
            } else {
                error_log('TEC Integration Test: ⚠️ Step 2.5 - Azure_Settings class not found, using defaults');
                $this->settings = array();
            }
            
            error_log('TEC Integration Test: Step 3 - Calling init_components()');
            $this->init_components();
            error_log('TEC Integration Test: ✅ Step 3 - init_components() completed');
            
            error_log('TEC Integration Test: Step 4 - Calling register_hooks()');  
            $this->register_hooks();
            error_log('TEC Integration Test: ✅ Step 4 - register_hooks() completed');
            
            error_log('TEC Integration Test: Step 5 - Checking if admin area');
            if (is_admin()) {
                error_log('TEC Integration Test: Step 5a - In admin area, calling init_admin()');
                $this->init_admin();
                error_log('TEC Integration Test: ✅ Step 5a - init_admin() completed');
            } else {
                error_log('TEC Integration Test: ℹ️ Step 5 - Not in admin area, skipping init_admin()');
            }
            
            error_log('TEC Integration Test: 🎉 CONSTRUCTOR COMPLETED SUCCESSFULLY');
            Azure_Logger::info('TEC Integration Test: Initialization completed successfully', 'TEC');
            
        } catch (Exception $e) {
            error_log('TEC Integration Test: 💥 EXCEPTION in constructor: ' . $e->getMessage());
            if (class_exists('Azure_Logger')) {
                Azure_Logger::fatal('TEC Integration Test: Constructor Exception: ' . $e->getMessage(), 'TEC');
            }
        } catch (Error $e) {
            error_log('TEC Integration Test: 💀 FATAL ERROR in constructor: ' . $e->getMessage());
            if (class_exists('Azure_Logger')) {
                Azure_Logger::fatal('TEC Integration Test: Constructor Fatal Error: ' . $e->getMessage(), 'TEC');
            }
        }
    }
    
    /**
     * Check if The Events Calendar plugin is active
     */
    private function is_tec_active() {
        error_log('TEC Integration Test: is_tec_active() called');
        $active = class_exists('Tribe__Events__Main');
        error_log('TEC Integration Test: is_tec_active() returning: ' . ($active ? 'true' : 'false'));
        return $active;
    }
    
    /**
     * Display admin notice if TEC is not active
     */
    public function tec_dependency_notice() {
        error_log('TEC Integration Test: tec_dependency_notice() called');
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Azure Plugin TEC Integration:</strong> The Events Calendar plugin is required for TEC integration features.</p>';
        echo '</div>';
        error_log('TEC Integration Test: tec_dependency_notice() completed');
    }
    
    /**
     * Initialize TEC integration components
     */
    private function init_components() {
        error_log('TEC Integration Test: init_components() called - ADDING REAL FUNCTIONALITY');
        
        try {
            error_log('TEC Integration Test: init_components Step 1 - Checking for Azure_TEC_Sync_Engine class');
            if (class_exists('Azure_TEC_Sync_Engine')) {
                error_log('TEC Integration Test: init_components Step 1a - Azure_TEC_Sync_Engine found, creating instance');
                $this->sync_engine = new Azure_TEC_Sync_Engine();
                error_log('TEC Integration Test: ✅ init_components Step 1a - Sync engine created successfully');
            } else {
                error_log('TEC Integration Test: ℹ️ init_components Step 1 - Azure_TEC_Sync_Engine class not found');
            }
            
            error_log('TEC Integration Test: init_components Step 2 - Checking for Azure_TEC_Data_Mapper class');
            if (class_exists('Azure_TEC_Data_Mapper')) {
                error_log('TEC Integration Test: init_components Step 2a - Azure_TEC_Data_Mapper found, creating instance');
                $this->data_mapper = new Azure_TEC_Data_Mapper();
                error_log('TEC Integration Test: ✅ init_components Step 2a - Data mapper created successfully');
            } else {
                error_log('TEC Integration Test: ℹ️ init_components Step 2 - Azure_TEC_Data_Mapper class not found');
            }
            
            error_log('TEC Integration Test: ✅ init_components() completed - REAL FUNCTIONALITY ADDED');
            Azure_Logger::success('TEC Integration: Components initialized successfully', 'TEC');
            
        } catch (Exception $e) {
            error_log('TEC Integration Test: ❌ init_components() Exception: ' . $e->getMessage());
            Azure_Logger::error('TEC Integration: Component initialization failed: ' . $e->getMessage(), 'TEC');
        }
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        error_log('TEC Integration Test: register_hooks() called - EMPTY METHOD FOR NOW');
        // TODO: Add hook registrations for TEC events
        error_log('TEC Integration Test: register_hooks() completed - NO HOOKS REGISTERED');
    }
    
    /**
     * Initialize admin interface
     */
    private function init_admin() {
        error_log('TEC Integration Test: init_admin() called - EMPTY METHOD FOR NOW');
        // TODO: Add admin page and metabox registrations
        error_log('TEC Integration Test: init_admin() completed - NO ADMIN ACTIONS TAKEN');
    }
}

error_log('TEC Integration Test: 9. Class definition completed');
error_log('TEC Integration Test: 10. File loading completed');
