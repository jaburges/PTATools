<?php
/**
 * MINIMAL TEST VERSION - Calendar authentication handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Auth {
    
    private $settings;
    private $credentials;
    
    public function __construct() {
        error_log('Azure_Calendar_Auth: Minimal constructor called');
        $this->settings = array();
        $this->credentials = array();
    }
    
    public function get_authorization_url($state = null) {
        return false;
    }
}


