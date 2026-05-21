<?php
/**
 * MINIMAL TEC Integration for Testing
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_TEC_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_TEC_Integration();
        }
        return self::$instance;
    }
    
    public function __construct() {
        error_log('MINIMAL TEC Integration: Constructor called successfully');
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('MINIMAL TEC Integration: Loaded successfully', 'TEC');
        }
    }
    
    public function test_method() {
        return 'TEC Integration is working';
    }
}
