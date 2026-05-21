<?php
/**
 * OneDrive Media Authentication Handler
 * Manages OAuth2 authentication for OneDrive/SharePoint access
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_OneDrive_Media_Auth {
    
    private $credentials;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'azure_onedrive_tokens';
        
        // Get credentials using the standard pattern
        $this->credentials = Azure_Settings::get_credentials('onedrive_media');
        
        // Register AJAX handlers
        add_action('wp_ajax_azure_onedrive_authorize', array($this, 'ajax_authorize'));
        add_action('wp_ajax_azure_onedrive_callback', array($this, 'ajax_callback'));
    }
    
    /**
     * Get authorization URL for user consent
     */
    public function get_authorization_url($redirect_page = 'azure-plugin-onedrive-media') {
        if (empty($this->credentials['client_id'])) {
            return false;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $auth_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize";
        
        $params = array(
            'client_id' => $this->credentials['client_id'],
            'response_type' => 'code',
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_onedrive_callback'),
            'response_mode' => 'query',
            'scope' => 'https://graph.microsoft.com/Files.ReadWrite.All https://graph.microsoft.com/Sites.ReadWrite.All offline_access',
            'state' => wp_create_nonce('azure_onedrive_auth') . '|' . $redirect_page
        );
        
        return $auth_url . '?' . http_build_query($params);
    }
    
    /**
     * Handle AJAX authorization request
     */
    public function ajax_authorize() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $auth_url = $this->get_authorization_url();
        
        if ($auth_url) {
            wp_send_json_success(array('auth_url' => $auth_url));
        } else {
            wp_send_json_error('Failed to generate authorization URL. Please check your credentials.');
        }
    }
    
    /**
     * Handle OAuth callback
     */
    public function ajax_callback() {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            wp_die('Invalid authorization callback');
        }
        
        $state_parts = explode('|', $_GET['state']);
        $nonce = $state_parts[0];
        $redirect_page = $state_parts[1] ?? 'azure-plugin-onedrive-media';
        
        if (!wp_verify_nonce($nonce, 'azure_onedrive_auth')) {
            wp_die('Invalid state parameter');
        }
        
        $code = sanitize_text_field($_GET['code']);
        $token_data = $this->exchange_code_for_token($code);
        
        if ($token_data) {
            // Get user info
            $user_info = $this->get_user_info($token_data['access_token']);
            
            if ($user_info) {
                $this->store_tokens($user_info['email'] ?? 'default', $token_data);
                Azure_Logger::info('OneDrive Media: Authorization successful for ' . ($user_info['email'] ?? 'default'));
                wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&auth=success'));
                exit;
            }
        }
        
        Azure_Logger::error('OneDrive Media: Authorization failed');
        wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&auth=error'));
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
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_onedrive_callback'),
            'grant_type' => 'authorization_code',
            'scope' => 'https://graph.microsoft.com/Files.ReadWrite.All https://graph.microsoft.com/Sites.ReadWrite.All offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media Auth: Token request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('OneDrive Media Auth: Token error - ' . $token_data['error_description']);
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
            Azure_Logger::error('OneDrive Media Auth: Failed to get user info - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $user_data = json_decode($response_body, true);
        
        if (isset($user_data['error'])) {
            Azure_Logger::error('OneDrive Media Auth: User info error - ' . $user_data['error']['message']);
            return false;
        }
        
        return array(
            'email' => $user_data['mail'] ?? $user_data['userPrincipalName'],
            'display_name' => $user_data['displayName'] ?? ''
        );
    }
    
    /**
     * Store access and refresh tokens
     */
    private function store_tokens($user_email, $token_data) {
        global $wpdb;
        
        $expires_at = date('Y-m-d H:i:s', time() + intval($token_data['expires_in'] ?? 3600));
        
        $wpdb->replace(
            $this->table_name,
            array(
                'user_email' => $user_email,
                'access_token' => $token_data['access_token'],
                'refresh_token' => $token_data['refresh_token'] ?? '',
                'expires_at' => $expires_at,
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        Azure_Logger::info("OneDrive Media Auth: Tokens stored for {$user_email}");
    }
    
    /**
     * Get valid access token (refreshes if necessary)
     */
    public function get_access_token($user_email = 'default') {
        global $wpdb;
        
        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE user_email = %s",
            $user_email
        ));
        
        // If no token found for specified user and looking for 'default', 
        // try to get any available token
        if (!$token_row && $user_email === 'default') {
            $token_row = $wpdb->get_row(
                "SELECT * FROM {$this->table_name} ORDER BY updated_at DESC LIMIT 1"
            );
        }
        
        if (!$token_row) {
            Azure_Logger::warning("OneDrive Media Auth: No token found for {$user_email}");
            return false;
        }
        
        // Check if token is expired (with 5 minute buffer)
        $expires_timestamp = strtotime($token_row->expires_at);
        if ($expires_timestamp < (time() + 300)) {
            // Token expired or expiring soon, refresh it
            Azure_Logger::info("OneDrive Media Auth: Token expired for {$token_row->user_email}, refreshing...");
            return $this->refresh_access_token($token_row->user_email, $token_row->refresh_token);
        }
        
        return $token_row->access_token;
    }
    
    /**
     * Refresh access token
     */
    private function refresh_access_token($user_email, $refresh_token) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Files.ReadWrite.All https://graph.microsoft.com/Sites.ReadWrite.All offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('OneDrive Media Auth: Token refresh failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('OneDrive Media Auth: Token refresh error - ' . $token_data['error_description']);
            return false;
        }
        
        // Store new tokens
        $this->store_tokens($user_email, $token_data);
        
        return $token_data['access_token'];
    }
    
    /**
     * Get application access token (for background sync)
     */
    public function get_app_access_token() {
        $cache_key = 'azure_onedrive_app_token';
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
            Azure_Logger::error('OneDrive Media Auth: App token request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('OneDrive Media Auth: App token error - ' . $token_data['error_description']);
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
    public function user_has_token($user_email = 'default') {
        global $wpdb;
        
        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT expires_at FROM {$this->table_name} WHERE user_email = %s",
            $user_email
        ));
        
        if (!$token_row) {
            return false;
        }
        
        // Check if token is still valid (not expired)
        return strtotime($token_row->expires_at) > time();
    }
    
    /**
     * Revoke user token
     */
    public function revoke_token($user_email) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('user_email' => $user_email),
            array('%s')
        );
        
        if ($result) {
            Azure_Logger::info("OneDrive Media Auth: Token revoked for {$user_email}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all authorized users
     */
    public function get_authorized_users() {
        global $wpdb;
        
        return $wpdb->get_results("SELECT user_email, expires_at FROM {$this->table_name} ORDER BY updated_at DESC");
    }
    
    /**
     * Test connection with current credentials
     */
    public function test_connection() {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return array(
                'success' => false,
                'message' => 'No access token available. Please authorize first.'
            );
        }
        
        // Test by getting user's OneDrive root
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/drive/root', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection test failed: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return array(
                'success' => true,
                'message' => 'Connection successful! Drive: ' . ($body['name'] ?? 'OneDrive')
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Connection test failed with status code: ' . $response_code
        );
    }
}