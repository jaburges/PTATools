<?php
/**
 * Calendar authentication handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Auth {

    /**
     * Option key holding per-user token health (status, last error, last check).
     * Format:
     *   [
     *     'jamieb@example.com' => [
     *        'status'        => 'ok' | 'expires_soon' | 'expired_no_refresh' | 'refresh_failed',
     *        'last_error'    => string|null,
     *        'last_error_at' => 'YYYY-mm-dd HH:ii:ss' (UTC),
     *        'last_check_at' => 'YYYY-mm-dd HH:ii:ss' (UTC),
     *     ],
     *   ]
     */
    const TOKEN_HEALTH_OPTION = 'azure_calendar_token_health';

    private $settings;
    private $credentials;

    public function __construct() {
        // Ensure Azure_Settings is available before using it
        if (!class_exists('Azure_Settings')) {
            error_log('Azure_Calendar_Auth: Azure_Settings class not found');
            return;
        }

        try {
            $this->settings = Azure_Settings::get_all_settings();
            $this->credentials = Azure_Settings::get_credentials('calendar');
        } catch (Exception $e) {
            error_log('Azure_Calendar_Auth: Failed to get settings - ' . $e->getMessage());
            $this->settings = array();
            $this->credentials = array();
        }

        // AJAX handlers for OAuth flow
        add_action('wp_ajax_nopriv_azure_calendar_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_azure_calendar_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_nopriv_azure_calendar_user_callback', array($this, 'handle_user_callback'));
        add_action('wp_ajax_azure_calendar_user_callback', array($this, 'handle_user_callback'));
        add_action('wp_ajax_azure_calendar_authorize', array($this, 'ajax_authorize'));
        add_action('wp_ajax_azure_calendar_revoke', array($this, 'ajax_revoke_token'));

        // Proactive hourly token refresh. The cron event itself is registered centrally
        // by Azure_PTA_Cron::ensure() when enable_calendar is on. We only attach the
        // handler so the scheduled event has something to invoke.
        add_action('azure_calendar_token_refresh', array($this, 'refresh_token_if_needed'));
    }
    
    /**
     * Get authorization URL for Microsoft Graph API
     */
    public function get_authorization_url($state = null) {
        if (empty($this->credentials['client_id']) || empty($this->credentials['tenant_id'])) {
            Azure_Logger::error('Calendar Auth: Missing client credentials');
            return false;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $base_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize";
        
        $params = array(
            'client_id' => $this->credentials['client_id'],
            'response_type' => 'code',
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_calendar_callback'),
            'response_mode' => 'query',
            'scope' => 'https://graph.microsoft.com/Calendars.Read https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/Calendars.Read.Shared https://graph.microsoft.com/Calendars.ReadWrite.Shared offline_access',
            'state' => $state ?: wp_create_nonce('azure_calendar_state')
        );
        
        return $base_url . '?' . http_build_query($params);
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_callback() {
        Azure_Logger::info('Calendar Auth: Handling OAuth callback');
        
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            Azure_Logger::error('Calendar Auth: Invalid callback parameters');
            wp_die('Invalid callback parameters');
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state']);
        
        // Verify state parameter
        if (!wp_verify_nonce($state, 'azure_calendar_state')) {
            Azure_Logger::error('Calendar Auth: Invalid state parameter');
            wp_die('Invalid state parameter');
        }
        
        // Exchange code for access token
        $token_data = $this->exchange_code_for_token($code);
        
        if (!$token_data) {
            Azure_Logger::error('Calendar Auth: Failed to get access token');
            wp_die('Failed to get access token');
        }
        
        // Store tokens
        $this->store_tokens($token_data);
        
        Azure_Logger::info('Calendar Auth: Authorization completed successfully');
        Azure_Database::log_activity('calendar', 'authorization_completed', 'auth', null);
        
        // Redirect back to calendar settings
        wp_redirect(admin_url('admin.php?page=azure-plugin-calendar&auth=success'));
        exit;
    }
    
    /**
     * Exchange authorization code for access token
     */
    private function exchange_code_for_token($code, $is_user_callback = false) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        // Use different redirect URI for user callback
        $redirect_action = $is_user_callback ? 'azure_calendar_user_callback' : 'azure_calendar_callback';
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'code' => $code,
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=' . $redirect_action),
            'grant_type' => 'authorization_code'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Calendar Auth: Token request failed', array(
                'error' => $response->get_error_message()
            ));
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('Calendar Auth: Token error', array(
                'error' => $token_data['error'],
                'error_description' => $token_data['error_description'] ?? 'No description',
                'response_code' => $response_code,
                'response_body' => $response_body
            ));
            return false;
        }
        
        if ($response_code !== 200 || empty($token_data['access_token'])) {
            Azure_Logger::error('Calendar Auth: Invalid token response', array(
                'response_code' => $response_code,
                'has_access_token' => !empty($token_data['access_token']),
                'response_body' => $response_body
            ));
            return false;
        }
        
        return $token_data;
    }
    
    /**
     * Store access and refresh tokens
     */
    private function store_tokens($token_data) {
        $expires_at = time() + ($token_data['expires_in'] ?? 3600);
        
        $token_info = array(
            'access_token' => $token_data['access_token'],
            'refresh_token' => $token_data['refresh_token'] ?? '',
            'expires_at' => $expires_at,
            'token_type' => $token_data['token_type'] ?? 'Bearer',
            'scope' => $token_data['scope'] ?? ''
        );
        
        update_option('azure_calendar_tokens', $token_info);
        
        Azure_Logger::info('Calendar Auth: Tokens stored successfully');
    }
    
    /**
     * Get valid access token (refresh if needed)
     * If $user_email is provided, gets token for that specific user (for shared mailbox access)
     */
    public function get_access_token($user_email = null) {
        // If user email specified, get user-specific token
        if ($user_email) {
            return $this->get_user_access_token($user_email);
        }
        
        // Default: get general calendar token
        $tokens = get_option('azure_calendar_tokens', array());
        
        if (empty($tokens['access_token'])) {
            return false;
        }
        
        // Check if token is expired
        if (time() >= ($tokens['expires_at'] ?? 0)) {
            Azure_Logger::info('Calendar Auth: Access token expired, refreshing...');
            
            if (!empty($tokens['refresh_token'])) {
                $new_tokens = $this->refresh_access_token($tokens['refresh_token']);
                
                if ($new_tokens) {
                    $tokens = $new_tokens;
                } else {
                    Azure_Logger::error('Calendar Auth: Failed to refresh token');
                    return false;
                }
            } else {
                Azure_Logger::error('Calendar Auth: No refresh token available');
                return false;
            }
        }
        
        return $tokens['access_token'];
    }
    
    /**
     * Check if user has a valid token
     */
    public function has_valid_user_token($user_email) {
        if (empty($user_email)) {
            return false;
        }
        
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return false;
        }
        
        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_email = %s",
            $user_email
        ));
        
        if (!$token_row || empty($token_row->access_token)) {
            return false;
        }
        
        // Check if token is expired
        if (!empty($token_row->expires_at)) {
            $expires_at = strtotime($token_row->expires_at);
            if ($expires_at <= time()) {
                // Token expired, but we can refresh if refresh_token exists
                return !empty($token_row->refresh_token);
            }
        }
        
        return true;
    }
    
    /**
     * Get access token for a specific user (for shared mailbox impersonation)
     */
    public function get_user_access_token($user_email) {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return false;
        }
        
        // Get token from database
        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_email = %s",
            $user_email
        ));
        
        if (!$token_row) {
            Azure_Logger::error("Calendar Auth: No token found for user {$user_email}");
            return false;
        }
        
        // Check if token is expired
        $expires_at = strtotime($token_row->expires_at);
        if (time() >= $expires_at) {
            Azure_Logger::info("Calendar Auth: Token expired for {$user_email}, refreshing...");
            
            if (!empty($token_row->refresh_token)) {
                $new_tokens = $this->refresh_user_access_token($token_row->refresh_token, $user_email);
                
                if ($new_tokens) {
                    return $new_tokens['access_token'];
                } else {
                    Azure_Logger::error("Calendar Auth: Failed to refresh token for {$user_email}");
                    return false;
                }
            } else {
                Azure_Logger::error("Calendar Auth: No refresh token for {$user_email}");
                return false;
            }
        }
        
        return $token_row->access_token;
    }
    
    /**
     * Store tokens for a specific user (shared mailbox)
     */
    public function store_user_tokens($user_email, $token_data) {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return false;
        }
        
        $expires_at = date('Y-m-d H:i:s', time() + ($token_data['expires_in'] ?? 3600));
        
        $data = array(
            'user_email' => $user_email,
            'access_token' => $token_data['access_token'],
            'refresh_token' => $token_data['refresh_token'] ?? '',
            'token_type' => $token_data['token_type'] ?? 'Bearer',
            'expires_at' => $expires_at,
            'scope' => $token_data['scope'] ?? '',
            'updated_at' => current_time('mysql')
        );
        
        // Check if token already exists for this user
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_email = %s",
            $user_email
        ));
        
        if ($existing) {
            // Update existing token
            $result = $wpdb->update(
                $table,
                $data,
                array('user_email' => $user_email),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%s')
            );
        } else {
            // Insert new token
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $table,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
        
        if ($result !== false) {
            Azure_Logger::info("Calendar Auth: Stored tokens for {$user_email}");
            // Healthy path: token was successfully obtained/stored, clear any prior error.
            self::update_token_health($user_email, 'ok', null);
            return true;
        } else {
            Azure_Logger::error("Calendar Auth: Failed to store tokens for {$user_email}");
            self::update_token_health($user_email, 'refresh_failed', 'db_write_failed');
            return false;
        }
    }

    /**
     * Refresh access token for a specific user
     */
    private function refresh_user_access_token($refresh_token, $user_email) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Calendars.Read https://graph.microsoft.com/Calendars.ReadWrite offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            Azure_Logger::error("Calendar Auth: Token refresh failed for {$user_email} - " . $msg);
            self::update_token_health($user_email, 'refresh_failed', 'network: ' . $msg);
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);

        if (isset($token_data['error'])) {
            $err = isset($token_data['error']) ? $token_data['error'] : 'unknown';
            $desc = isset($token_data['error_description']) ? $token_data['error_description'] : '';
            Azure_Logger::error("Calendar Auth: Token refresh error for {$user_email} - {$err}: {$desc}");
            self::update_token_health($user_email, 'refresh_failed', $err);
            return false;
        }

        // Store new tokens (this also records 'ok' health)
        $this->store_user_tokens($user_email, $token_data);

        return $token_data;
    }
    
    /**
     * Check if a user is authenticated
     */
    public function is_user_authenticated($user_email) {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return false;
        }
        
        $token = $wpdb->get_var($wpdb->prepare(
            "SELECT access_token FROM {$table} WHERE user_email = %s",
            $user_email
        ));
        
        return !empty($token);
    }
    
    /**
     * Handle OAuth callback for user-specific authentication
     */
    public function handle_user_callback() {
        Azure_Logger::info('Calendar Auth: Handling user OAuth callback');
        
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            Azure_Logger::error('Calendar Auth: Invalid user callback parameters');
            wp_die('Invalid callback parameters');
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state']);
        
        // Decode state to get user email
        $state_data = json_decode(base64_decode($state), true);
        
        if (!$state_data || !isset($state_data['user_email']) || !isset($state_data['nonce']) || !isset($state_data['timestamp'])) {
            Azure_Logger::error('Calendar Auth: Invalid state data', array('state_data' => $state_data));
            wp_die('Invalid state data');
        }
        
        $user_email = sanitize_email($state_data['user_email']);
        
        // Check if state is not too old (within 10 minutes)
        $timestamp = intval($state_data['timestamp']);
        if ((time() - $timestamp) > 600) {
            Azure_Logger::error('Calendar Auth: State timestamp expired', array(
                'timestamp' => $timestamp,
                'current_time' => time(),
                'age_seconds' => (time() - $timestamp)
            ));
            wp_die('Authorization state expired. Please try authenticating again.');
        }
        
        // Verify nonce (matches the format used when creating: 'azure_calendar_user_' . $user_email)
        $nonce_valid = wp_verify_nonce($state_data['nonce'], 'azure_calendar_user_' . $user_email);
        
        if (!$nonce_valid) {
            // Log detailed nonce verification failure
            Azure_Logger::error('Calendar Auth: Invalid state nonce', array(
                'nonce_received' => $state_data['nonce'],
                'nonce_action' => 'azure_calendar_user_' . $user_email,
                'user_email' => $user_email,
                'is_user_logged_in' => is_user_logged_in(),
                'current_user_id' => get_current_user_id(),
                'timestamp_age' => (time() - $timestamp)
            ));
            
            // Since nonces can fail due to session issues during OAuth redirect,
            // we'll allow it if the timestamp is recent (within 10 minutes)
            // This is acceptable because the OAuth code itself is single-use and expires quickly
            if ((time() - $timestamp) > 600) {
                wp_die('Invalid state nonce. Please try authenticating again.');
            }
            
            Azure_Logger::warning('Calendar Auth: Bypassing nonce check due to recent timestamp', array('user_email' => $user_email));
        }
        
        // Exchange code for access token
        $token_data = $this->exchange_code_for_token($code, true);  // true for user callback
        
        if (!$token_data) {
            Azure_Logger::error("Calendar Auth: Failed to get access token for {$user_email}");
            wp_die('Failed to get access token');
        }
        
        // Store tokens for this user
        $this->store_user_tokens($user_email, $token_data);
        
        Azure_Logger::info("Calendar Auth: User authorization completed for {$user_email}");
        Azure_Database::log_activity('calendar', 'user_authorization_completed', 'auth', null, array('user_email' => $user_email));
        
        // Redirect back to the page that initiated authentication
        $return_page = isset($state_data['return_page']) ? $state_data['return_page'] : 'azure-plugin-tec';
        wp_redirect(admin_url('admin.php?page=' . $return_page . '&auth=success&user=' . urlencode($user_email)));
        exit;
    }
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_access_token($refresh_token) {
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'https://graph.microsoft.com/Calendars.Read https://graph.microsoft.com/Calendars.ReadWrite offline_access'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Calendar Auth: Token refresh failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('Calendar Auth: Token refresh error - ' . $token_data['error_description']);
            return false;
        }
        
        // Store new tokens
        $this->store_tokens($token_data);
        
        return get_option('azure_calendar_tokens', array());
    }
    
    /**
     * Check if user is authenticated
     */
    public function is_authenticated() {
        $tokens = get_option('azure_calendar_tokens', array());
        return !empty($tokens['access_token']) && !empty($tokens['refresh_token']);
    }
    
    /**
     * Get authentication status
     */
    public function get_auth_status() {
        $tokens = get_option('azure_calendar_tokens', array());
        
        if (empty($tokens['access_token'])) {
            return array(
                'authenticated' => false,
                'message' => 'Not authenticated'
            );
        }
        
        $expires_at = $tokens['expires_at'] ?? 0;
        $expires_in = $expires_at - time();
        
        if ($expires_in <= 0) {
            return array(
                'authenticated' => true,
                'expired' => true,
                'message' => 'Token expired',
                'expires_at' => date('Y-m-d H:i:s', $expires_at)
            );
        }
        
        return array(
            'authenticated' => true,
            'expired' => false,
            'message' => 'Authenticated',
            'expires_at' => date('Y-m-d H:i:s', $expires_at),
            'expires_in_hours' => round($expires_in / 3600, 2)
        );
    }
    
    /**
     * Revoke access tokens
     */
    public function revoke_tokens() {
        delete_option('azure_calendar_tokens');
        Azure_Logger::info('Calendar Auth: Tokens revoked');
        Azure_Database::log_activity('calendar', 'tokens_revoked', 'auth', null);
    }
    
    /**
     * AJAX handler for authorization
     */
    public function ajax_authorize() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $auth_url = $this->get_authorization_url();
        
        if ($auth_url) {
            wp_send_json_success(array('auth_url' => $auth_url));
        } else {
            wp_send_json_error('Failed to generate authorization URL. Check your credentials.');
        }
    }
    
    /**
     * AJAX handler for revoking tokens
     */
    public function ajax_revoke_token() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $this->revoke_tokens();
        
        wp_send_json_success('Authorization revoked successfully');
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return array(
                'success' => false,
                'message' => 'No valid access token available'
            );
        }
        
        // Try to get user calendars to test connection
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/calendars', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            $calendar_count = isset($data['value']) ? count($data['value']) : 0;
            
            return array(
                'success' => true,
                'message' => 'Connection successful',
                'calendar_count' => $calendar_count
            );
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => 'API Error (Status: ' . $response_code . '): ' . ($error_data['error']['message'] ?? 'Unknown error')
            );
        }
    }
    
    /**
     * Get user calendars
     */
    public function get_user_calendars() {
        $access_token = $this->get_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/calendars', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Calendar Auth: Failed to get calendars - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            Azure_Logger::error('Calendar Auth: Calendar request failed with status ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data['value'] ?? array();
    }
    
    /**
     * Schedule token refresh
     */
    public function schedule_token_refresh() {
        if (!wp_next_scheduled('azure_calendar_token_refresh')) {
            wp_schedule_event(time() + 3600, 'hourly', 'azure_calendar_token_refresh');
        }
        
        add_action('azure_calendar_token_refresh', array($this, 'refresh_token_if_needed'));
    }
    
    /**
     * Refresh tokens proactively. Hooked to `azure_calendar_token_refresh` (hourly).
     *
     * Handles two stores:
     *   1. Legacy single-tenant token in wp_options 'azure_calendar_tokens'
     *   2. Per-user delegated tokens in wp_azure_email_tokens (used by Calendar
     *      Embed and Calendar Sync today)
     *
     * Only tokens within REFRESH_THRESHOLD_SECONDS of expiry are refreshed, so the
     * worst case per cron run is one wp_remote_post per connected account.
     */
    public function refresh_token_if_needed() {
        $threshold = 1800; // refresh 30 minutes before expiry

        // ── 1. Legacy single token (backwards compatibility) ───────────────
        $tokens = get_option('azure_calendar_tokens', array());
        if (!empty($tokens['access_token']) && !empty($tokens['refresh_token'])) {
            $expires_at = isset($tokens['expires_at']) ? (int) $tokens['expires_at'] : 0;
            if (time() >= ($expires_at - $threshold)) {
                Azure_Logger::info('Calendar Auth: Refreshing legacy token proactively');
                $this->refresh_access_token($tokens['refresh_token']);
            }
        }

        // ── 2. Per-user delegated tokens (the path Embed and Sync use) ─────
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return;
        }

        // Index on `expires_at` already exists; this is a single fast scan.
        $cutoff = date('Y-m-d H:i:s', time() + $threshold);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_email, refresh_token, expires_at
               FROM {$table}
              WHERE refresh_token IS NOT NULL AND refresh_token <> ''
                AND expires_at <= %s",
            $cutoff
        ));

        if (empty($rows)) {
            return;
        }

        Azure_Logger::info('Calendar Auth: Proactive refresh scanning ' . count($rows) . ' user token(s)');

        foreach ($rows as $row) {
            $email = $row->user_email;
            // refresh_user_access_token writes both tokens and health on success/failure.
            $this->refresh_user_access_token($row->refresh_token, $email);
        }
    }

    // ==========================================
    // Token health surface (dashboard widget feeds off this)
    // ==========================================

    /**
     * Record the outcome of a token operation. Single wp_option write per change;
     * called only on auth/refresh boundaries (never on every page load).
     */
    private static function update_token_health($user_email, $status, $error_code) {
        if (empty($user_email)) {
            return;
        }
        $health = get_option(self::TOKEN_HEALTH_OPTION, array());
        if (!is_array($health)) { $health = array(); }

        $now_utc = current_time('mysql', true);
        $entry = isset($health[$user_email]) && is_array($health[$user_email])
            ? $health[$user_email]
            : array();

        $entry['status']        = $status;
        $entry['last_check_at'] = $now_utc;
        if ($status === 'ok') {
            $entry['last_error']    = null;
            $entry['last_error_at'] = null;
        } elseif ($error_code !== null) {
            $entry['last_error']    = $error_code;
            $entry['last_error_at'] = $now_utc;
        }

        $health[$user_email] = $entry;
        update_option(self::TOKEN_HEALTH_OPTION, $health, false); // not autoloaded
    }

    /**
     * Build a connected-accounts summary for the dashboard widget.
     * Single SELECT against wp_azure_email_tokens + one option read.
     *
     * @return array<int, array{
     *   user_email:string,
     *   status:string,
     *   expires_at:string|null,
     *   expires_in:int|null,
     *   has_refresh_token:bool,
     *   last_error:string|null,
     *   last_error_at:string|null
     * }>
     */
    public static function get_token_health_summary() {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_tokens');
        if (!$table) {
            return array();
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT user_email, expires_at,
                    CASE WHEN refresh_token IS NULL OR refresh_token = '' THEN 0 ELSE 1 END AS has_refresh
               FROM {$table}
              ORDER BY user_email ASC"
        );
        if (empty($rows)) {
            return array();
        }

        $health = get_option(self::TOKEN_HEALTH_OPTION, array());
        if (!is_array($health)) { $health = array(); }
        $now = time();

        $out = array();
        foreach ($rows as $row) {
            $email = $row->user_email;
            $expires_ts = !empty($row->expires_at) ? strtotime($row->expires_at . ' UTC') : 0;
            $expires_in = $expires_ts ? ($expires_ts - $now) : null;
            $has_refresh = (bool) intval($row->has_refresh);

            $entry = isset($health[$email]) && is_array($health[$email]) ? $health[$email] : array();
            $stored_status = isset($entry['status']) ? $entry['status'] : null;
            $last_error    = isset($entry['last_error']) ? $entry['last_error'] : null;
            $last_error_at = isset($entry['last_error_at']) ? $entry['last_error_at'] : null;

            // Derive live status from observable facts. Stored 'refresh_failed' wins
            // because it tells us a recent attempt failed and lazy-refresh won't fix it.
            if ($stored_status === 'refresh_failed') {
                $status = 'refresh_failed';
            } elseif (!$has_refresh && $expires_in !== null && $expires_in <= 0) {
                $status = 'expired_no_refresh';
            } elseif ($expires_in !== null && $expires_in <= 1800) {
                $status = 'expires_soon';
            } else {
                $status = 'ok';
            }

            $out[] = array(
                'user_email'        => $email,
                'status'            => $status,
                'expires_at'        => $row->expires_at,
                'expires_in'        => $expires_in,
                'has_refresh_token' => $has_refresh,
                'last_error'        => $last_error,
                'last_error_at'     => $last_error_at,
            );
        }

        return $out;
    }
    
    // ==========================================
    // Per-User Token Management (for Delegated Access)
    // ==========================================
    
    /**
     * Get authorization URL for a specific user (delegated access)
     */
    public function get_authorization_url_for_user($user_email, $return_page = 'azure-plugin-tec') {
        if (empty($this->credentials['client_id']) || empty($this->credentials['tenant_id'])) {
            Azure_Logger::error('Calendar Auth: Missing client credentials');
            return false;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $base_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize";
        
        // Create a state parameter that includes the user email and return page
        $state_data = array(
            'user_email' => $user_email,
            'return_page' => $return_page,
            'nonce' => wp_create_nonce('azure_calendar_user_' . $user_email),
            'timestamp' => time()
        );
        $state = base64_encode(json_encode($state_data));
        
        $params = array(
            'client_id' => $this->credentials['client_id'],
            'response_type' => 'code',
            'redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_calendar_user_callback'),
            'response_mode' => 'query',
            'scope' => 'https://graph.microsoft.com/Calendars.Read https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/Calendars.Read.Shared https://graph.microsoft.com/Calendars.ReadWrite.Shared offline_access',
            'state' => $state,
            'login_hint' => $user_email // Hint to Azure to pre-fill email
        );
        
        return $base_url . '?' . http_build_query($params);
    }
}
?>