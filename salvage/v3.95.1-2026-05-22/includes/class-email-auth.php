<?php
/**
 * Email authentication handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Auth {
    
    private $settings;
    private $credentials;
    
    public function __construct() {
        $this->settings = Azure_Settings::get_all_settings();
        $this->credentials = Azure_Settings::get_credentials('email');
        
        // AJAX handlers for OAuth flow
        add_action('wp_ajax_nopriv_azure_email_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_azure_email_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_azure_email_authorize', array($this, 'ajax_authorize'));
        add_action('wp_ajax_azure_email_revoke', array($this, 'ajax_revoke_token'));
        
        // Schedule token refresh
        add_action('init', array($this, 'schedule_token_refresh'));
    }
    
    /**
     * Get authorization URL for Microsoft Graph API
     */
    public function get_authorization_url($user_email = null, $state = null) {
        if (empty($this->credentials['client_id']) || empty($this->credentials['tenant_id'])) {
            Azure_Logger::error('Email Auth: Missing client credentials');
            return false;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $base_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize";
        
        $scopes = 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.ReadWrite offline_access';
        
        $params = array(
            'client_id' => $this->credentials['client_id'],
            'response_type' => 'code',
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_email_callback'),
            'response_mode' => 'query',
            'scope' => $scopes,
            'state' => $state ?: wp_create_nonce('azure_email_state')
        );
        
        if ($user_email) {
            $params['login_hint'] = $user_email;
        }
        
        return $base_url . '?' . http_build_query($params);
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_callback() {
        Azure_Logger::info('Email Auth: Handling OAuth callback');
        
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            Azure_Logger::error('Email Auth: Invalid callback parameters');
            wp_die('Invalid callback parameters');
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state']);
        
        // Verify state parameter
        if (!wp_verify_nonce($state, 'azure_email_state')) {
            Azure_Logger::error('Email Auth: Invalid state parameter');
            wp_die('Invalid state parameter');
        }
        
        // Exchange code for access token
        $token_data = $this->exchange_code_for_token($code);
        
        if (!$token_data) {
            Azure_Logger::error('Email Auth: Failed to get access token');
            wp_die('Failed to get access token');
        }
        
        // Get user info to store token per user
        $user_info = $this->get_user_info($token_data['access_token']);
        
        if (!$user_info) {
            Azure_Logger::error('Email Auth: Failed to get user information');
            wp_die('Failed to get user information');
        }
        
        // Store tokens for this user
        $this->store_user_token($user_info['mail'] ?? $user_info['userPrincipalName'], $token_data);
        
        Azure_Logger::info('Email Auth: Authorization completed successfully');
        Azure_Database::log_activity('email', 'authorization_completed', 'auth', null);
        
        // Redirect back to email settings
        wp_redirect(admin_url('admin.php?page=azure-plugin-emails&tab=settings&auth=success'));
        exit;
    }
    
    /**
     * Exchange authorization code for access token
     */
    private function exchange_code_for_token($code) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'code' => $code,
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_email_callback'),
            'grant_type' => 'authorization_code',
            'scope' => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.ReadWrite offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email Auth: Token request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('Email Auth: Token error - ' . $token_data['error_description']);
            return false;
        }
        
        return $token_data;
    }
    
    /**
     * Get user information from Microsoft Graph
     */
    private function get_user_info($access_token) {
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email Auth: User info request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $user_info = json_decode($response_body, true);
        
        if (isset($user_info['error'])) {
            Azure_Logger::error('Email Auth: User info error - ' . $user_info['error']['message']);
            return false;
        }
        
        return $user_info;
    }
    
    /**
     * Store user token in database
     */
    private function store_user_token($user_email, $token_data) {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            Azure_Logger::error('Email Auth: Email tokens table not found');
            return false;
        }
        
        $expires_at = date('Y-m-d H:i:s', time() + ($token_data['expires_in'] ?? 3600));
        
        // Delete existing token for this user
        $wpdb->delete($table, array('user_email' => $user_email), array('%s'));
        
        // Insert new token
        $result = $wpdb->insert(
            $table,
            array(
                'user_email' => $user_email,
                'access_token' => $token_data['access_token'],
                'refresh_token' => $token_data['refresh_token'] ?? '',
                'token_type' => $token_data['token_type'] ?? 'Bearer',
                'expires_at' => $expires_at,
                'scope' => $token_data['scope'] ?? ''
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            Azure_Logger::info('Email Auth: Token stored for user: ' . $user_email);
            return true;
        } else {
            Azure_Logger::error('Email Auth: Failed to store token for user: ' . $user_email);
            return false;
        }
    }
    
    /**
     * Get access token for user
     */
    public function get_user_access_token($user_email) {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            return false;
        }
        
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_email = %s",
            $user_email
        ));
        
        if (!$token_data) {
            return false;
        }
        
        // Check if token is expired
        if (strtotime($token_data->expires_at) <= time()) {
            Azure_Logger::info('Email Auth: Token expired for user: ' . $user_email . ', refreshing...');
            
            if (!empty($token_data->refresh_token)) {
                $new_token = $this->refresh_user_token($user_email, $token_data->refresh_token);
                return $new_token ? $new_token : false;
            } else {
                Azure_Logger::error('Email Auth: No refresh token for user: ' . $user_email);
                return false;
            }
        }
        
        return $token_data->access_token;
    }
    
    /**
     * Refresh user access token
     */
    private function refresh_user_token($user_email, $refresh_token) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.ReadWrite offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email Auth: Token refresh failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('Email Auth: Token refresh error - ' . $token_data['error_description']);
            return false;
        }
        
        // Store new token
        $this->store_user_token($user_email, $token_data);
        
        return $token_data['access_token'];
    }
    
    /**
     * Get application access token (for service account sending)
     */
    public function get_app_access_token() {
        $cache_key = 'azure_email_app_token';
        $cached_token = get_transient($cache_key);
        
        if ($cached_token) {
            return $cached_token;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email Auth: App token request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('Email Auth: App token error - ' . $token_data['error_description']);
            return false;
        }
        
        $access_token = $token_data['access_token'];
        $expires_in = $token_data['expires_in'] ?? 3600;
        
        // Cache token for a bit less than expiry time
        set_transient($cache_key, $access_token, $expires_in - 60);
        
        return $access_token;
    }
    
    /**
     * Check if user has valid token
     */
    public function user_has_token($user_email) {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            return false;
        }
        
        $token_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_email = %s AND expires_at > NOW()",
            $user_email
        ));
        
        return intval($token_count) > 0;
    }
    
    /**
     * Get all authorized users
     */
    public function get_authorized_users() {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            return array();
        }
        
        $users = $wpdb->get_results("SELECT user_email, expires_at FROM {$table} WHERE expires_at > NOW()");
        
        return $users ?: array();
    }
    
    /**
     * Revoke user token
     */
    public function revoke_user_token($user_email) {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            return false;
        }
        
        $result = $wpdb->delete($table, array('user_email' => $user_email), array('%s'));
        
        if ($result) {
            Azure_Logger::info('Email Auth: Token revoked for user: ' . $user_email);
            Azure_Database::log_activity('email', 'token_revoked', 'user', null, array('user_email' => $user_email));
        }
        
        return $result !== false;
    }
    
    /**
     * Schedule token refresh
     */
    public function schedule_token_refresh() {
        if (!wp_next_scheduled('azure_mail_token_refresh')) {
            wp_schedule_event(time() + 3600, 'hourly', 'azure_mail_token_refresh');
        }
        
        add_action('azure_mail_token_refresh', array($this, 'refresh_expired_tokens'));
    }
    
    /**
     * Refresh expired tokens (scheduled task)
     */
    public function refresh_expired_tokens() {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        
        if (!$table) {
            return;
        }
        
        // Get tokens that will expire in the next hour
        $expiring_tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)"
        ));
        
        foreach ($expiring_tokens as $token) {
            if (!empty($token->refresh_token)) {
                Azure_Logger::info('Email Auth: Proactively refreshing token for: ' . $token->user_email);
                $this->refresh_user_token($token->user_email, $token->refresh_token);
            }
        }
    }
    
    /**
     * AJAX handlers
     */
    public function ajax_authorize() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $user_email = sanitize_email($_POST['user_email'] ?? '');
        $auth_url = $this->get_authorization_url($user_email);
        
        if ($auth_url) {
            wp_send_json_success(array('auth_url' => $auth_url));
        } else {
            wp_send_json_error('Failed to generate authorization URL. Check your credentials.');
        }
    }
    
    public function ajax_revoke_token() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $user_email = sanitize_email($_POST['user_email'] ?? '');
        
        if (empty($user_email)) {
            wp_send_json_error('User email is required');
        }
        
        $result = $this->revoke_user_token($user_email);
        
        if ($result) {
            wp_send_json_success('Token revoked successfully');
        } else {
            wp_send_json_error('Failed to revoke token');
        }
    }
}




