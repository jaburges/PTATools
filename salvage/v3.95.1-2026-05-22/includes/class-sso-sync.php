<?php
/**
 * SSO Sync handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_SSO_Sync {
    
    public function __construct() {
        add_action('wp_ajax_azure_sync_users', array($this, 'sync_users_ajax'));
        add_action('azure_sso_scheduled_sync', array($this, 'scheduled_sync'));
        
        // Exclusion list AJAX handlers
        add_action('wp_ajax_azure_get_all_ad_users', array($this, 'ajax_get_all_ad_users'));
        add_action('wp_ajax_azure_add_user_to_exclusion', array($this, 'ajax_add_user_to_exclusion'));
        add_action('wp_ajax_azure_remove_user_from_exclusion', array($this, 'ajax_remove_user_from_exclusion'));
        add_action('wp_ajax_azure_save_exclude_external_domains', array($this, 'ajax_save_exclude_external_domains'));
        
        // Schedule sync if enabled
        $this->setup_scheduled_sync();
    }
    
    /**
     * Setup scheduled sync
     */
    private function setup_scheduled_sync() {
        $sync_enabled = Azure_Settings::get_setting('sso_sync_enabled', false);
        $sync_frequency = Azure_Settings::get_setting('sso_sync_frequency', 'hourly');

        $existing = wp_get_schedule('azure_sso_scheduled_sync');

        if (!$sync_enabled) {
            if ($existing) {
                wp_clear_scheduled_hook('azure_sso_scheduled_sync');
            }
            return;
        }

        // Only reschedule if the frequency changed or there's no schedule
        if ($existing !== $sync_frequency) {
            wp_clear_scheduled_hook('azure_sso_scheduled_sync');
            wp_schedule_event(time() + 60, $sync_frequency, 'azure_sso_scheduled_sync');
        }
    }
    
    /**
     * AJAX handler for manual user sync
     */
    public function sync_users_ajax() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $result = $this->sync_users();
        
        wp_send_json($result);
    }
    
    /**
     * Scheduled sync callback
     */
    public function scheduled_sync() {
        Azure_Logger::info('SSO: Starting scheduled user sync');
        $result = $this->sync_users();
        
        if ($result['success']) {
            Azure_Logger::info('SSO: Scheduled sync completed successfully');
        } else {
            Azure_Logger::error('SSO: Scheduled sync failed: ' . $result['message']);
        }
    }
    
    /**
     * Sync users with Azure AD
     */
    public function sync_users() {
        try {
            $credentials = Azure_Settings::get_credentials('sso');
            
            if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                return array(
                    'success' => false,
                    'message' => 'SSO credentials not configured'
                );
            }
            
            // Get access token for application permissions
            $access_token = $this->get_app_access_token($credentials);
            
            if (!$access_token) {
                return array(
                    'success' => false,
                    'message' => 'Failed to get access token'
                );
            }
            
            // Get all users from Azure AD
            $azure_users = $this->get_azure_users($access_token);
            
            if (!$azure_users) {
                return array(
                    'success' => false,
                    'message' => 'Failed to retrieve users from Azure AD'
                );
            }
            
            $total_users = count($azure_users);
            $sync_stats = array(
                'successful' => 0,
                'created' => 0,
                'updated' => 0,
                'linked' => 0,
                'skipped' => 0,
                'errors' => 0,
                'total' => $total_users
            );
            
            $detailed_results = array(
                'successful' => array(),
                'skipped' => array(),
                'errors' => array()
            );
            
            Azure_Logger::info("SSO: Starting sync of $total_users Azure AD users");
            
            $processed = 0;
            // Sync each user
            foreach ($azure_users as $azure_user) {
                $processed++;
                $email = $azure_user['mail'] ?? $azure_user['userPrincipalName'] ?? 'Unknown';
                
                // Log progress every 10 users
                if ($processed % 10 === 0 || $processed === $total_users) {
                    $percentage = round(($processed / $total_users) * 100);
                    Azure_Logger::info("SSO: Sync progress: $processed/$total_users ($percentage%)");
                }
                
                $result = $this->sync_single_user($azure_user);
                
                // Handle new array return format
                if (is_array($result)) {
                    $status = $result['status'];
                    $message = $result['message'];
                } else {
                    // Handle old string format for backward compatibility
                    $status = $result;
                    $message = "User '$email': $status";
                }
                
                switch ($status) {
                    case 'updated':
                        $sync_stats['updated']++;
                        $sync_stats['successful']++;
                        $detailed_results['successful'][] = $message;
                        break;
                    case 'created':
                        $sync_stats['created']++;
                        $sync_stats['successful']++;
                        $detailed_results['successful'][] = $message;
                        break;
                    case 'linked':
                        $sync_stats['linked']++;
                        $sync_stats['successful']++;
                        $detailed_results['successful'][] = $message;
                        break;
                    case 'skipped':
                        $sync_stats['skipped']++;
                        $detailed_results['skipped'][] = $message;
                        Azure_Logger::warning("SSO: $message");
                        break;
                    case 'error':
                    default:
                        $sync_stats['errors']++;
                        $detailed_results['errors'][] = $message;
                        Azure_Logger::error("SSO: $message");
                        break;
                }
            }
            
            // Log final results
            Azure_Logger::info("SSO: Sync completed - Successful: {$sync_stats['successful']}, Skipped: {$sync_stats['skipped']}, Errors: {$sync_stats['errors']}");
            
            // Store detailed results including individual messages for widgets
            $complete_results = array_merge($sync_stats, array(
                'detailed_results' => $detailed_results
            ));
            
            Azure_Database::log_activity('sso', 'users_synced', 'sync', null, $complete_results);
            
            return array(
                'success' => true,
                'message' => sprintf(
                    'Sync completed. Successful: %d (Created: %d, Updated: %d, Linked: %d), Skipped: %d, Errors: %d',
                    $sync_stats['successful'],
                    $sync_stats['created'], 
                    $sync_stats['updated'],
                    $sync_stats['linked'],
                    $sync_stats['skipped'],
                    $sync_stats['errors']
                ),
                'stats' => $sync_stats,
                'details' => $detailed_results
            );
            
        } catch (Exception $e) {
            Azure_Logger::error('SSO: Sync error: ' . $e->getMessage());
            
            return array(
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Get application access token
     */
    private function get_app_access_token($credentials) {
        $tenant_id = $credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('SSO: Token request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            Azure_Logger::error('SSO: Token error - ' . $token_data['error_description']);
            return false;
        }
        
        return $token_data['access_token'] ?? false;
    }
    
    /**
     * Get all users from Azure AD
     */
    private function get_azure_users($access_token) {
        $users = array();
        $next_link = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,mail,userPrincipalName,givenName,surname,accountEnabled,department,jobTitle';
        
        do {
            $response = wp_remote_get($next_link, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('SSO: Users request failed - ' . $response->get_error_message());
                return false;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            if (isset($data['error'])) {
                Azure_Logger::error('SSO: Users API error - ' . $data['error']['message']);
                return false;
            }
            
            if (isset($data['value'])) {
                $users = array_merge($users, $data['value']);
            }
            
            $next_link = $data['@odata.nextLink'] ?? null;
            
        } while ($next_link);
        
        return $users;
    }
    
    /**
     * Sync a single user
     */
    private function sync_single_user($azure_user) {
        global $wpdb;
        
        $azure_user_id = $azure_user['id'];
        $email = $azure_user['mail'] ?? $azure_user['userPrincipalName'];
        $display_name = $azure_user['displayName'] ?? 'Unknown';
        
        // Skip users in the exclusion list (service accounts, shared mailboxes, etc.)
        if (self::is_user_excluded($azure_user_id, $email)) {
            return array(
                'status' => 'skipped',
                'message' => "User '{$display_name}' ({$email}): Excluded from sync (in Do Not Sync list)"
            );
        }
        
        // Skip disabled accounts
        if (!($azure_user['accountEnabled'] ?? true)) {
            return array(
                'status' => 'skipped',
                'message' => "User '{$display_name}' ({$email}): Account disabled in Azure AD"
            );
        }
        
        if (empty($email)) {
            Azure_Logger::warning('SSO: No email for user: ' . $azure_user_id);
            return array(
                'status' => 'error',
                'message' => "User '{$display_name}' (ID: {$azure_user_id}): No email address"
            );
        }
        
        $sso_users_table = Azure_Database::get_table_name('sso_users');
        
        // Check if mapping exists
        $existing_mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sso_users_table} WHERE azure_user_id = %s",
            $azure_user_id
        ));
        
        if ($existing_mapping) {
            $preserve_local_data = Azure_Settings::get_setting('sso_preserve_local_data', false);
            
            // Update existing mapping
            $wpdb->update(
                $sso_users_table,
                array(
                    'azure_email' => $email,
                    'azure_display_name' => $display_name,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing_mapping->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            // Update WordPress user if exists and preserve_local_data is disabled
            $wp_user = get_user_by('ID', $existing_mapping->wordpress_user_id);
            if ($wp_user) {
                if (!$preserve_local_data) {
                    wp_update_user(array(
                        'ID' => $wp_user->ID,
                        'display_name' => $display_name,
                        'first_name' => $azure_user['givenName'] ?? '',
                        'last_name' => $azure_user['surname'] ?? ''
                    ));
                    
                    // Update department from Azure AD
                    if (!empty($azure_user['department'])) {
                        update_user_meta($wp_user->ID, 'department', sanitize_text_field($azure_user['department']));
                    }
                    
                    Azure_Logger::info("SSO: Updated existing mapped user '$email' with Azure AD data");
                } else {
                    Azure_Logger::info("SSO: Preserved local data for mapped user '$email' (preserve_local_data enabled)");
                }
            }
            
            return array('status' => 'updated', 'message' => "Updated existing mapping for '$email'");
        }
        
        // Check if auto-create is enabled
        if (!Azure_Settings::get_setting('sso_auto_create_users', true)) {
            return array('status' => 'skipped', 'message' => "Skipped user '$email' (auto-create disabled)");
        }
        
        // Check if WordPress user exists by email
        $wp_user = get_user_by('email', $email);
        $preserve_local_data = Azure_Settings::get_setting('sso_preserve_local_data', false);
        
        if (!$wp_user) {
            // Create new WordPress user
            $username = $this->generate_username($email, $display_name);
            $password = wp_generate_password(20, true, true);
            
            $user_id = wp_create_user($username, $password, $email);
            
            if (is_wp_error($user_id)) {
                Azure_Logger::error('SSO: Failed to create user - ' . $user_id->get_error_message());
                return array('status' => 'error', 'message' => $user_id->get_error_message());
            }
            
            // Determine the role to assign
            $role_to_assign = $this->get_sso_role();
            
            // Update user data
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $display_name,
                'first_name' => $azure_user['givenName'] ?? '',
                'last_name' => $azure_user['surname'] ?? '',
                'role' => $role_to_assign
            ));
            
            // Map department from Azure AD to WordPress user meta
            if (!empty($azure_user['department'])) {
                update_user_meta($user_id, 'department', sanitize_text_field($azure_user['department']));
            }
            
            // Store Azure AD jobTitle for reference (but don't auto-assign)
            if (!empty($azure_user['jobTitle'])) {
                update_user_meta($user_id, 'azure_job_title', sanitize_text_field($azure_user['jobTitle']));
            }
            
            $wp_user = get_user_by('ID', $user_id);
            Azure_Logger::info("SSO: Created new user '$email' with role '$role_to_assign'");
            
        } else {
            // User exists by email - link to Azure AD
            Azure_Logger::info("SSO: Linking existing user '$email' to Azure AD");
            
            if (!$preserve_local_data) {
                // Update user data with Azure AD info
                wp_update_user(array(
                    'ID' => $wp_user->ID,
                    'display_name' => $display_name,
                    'first_name' => $azure_user['givenName'] ?? '',
                    'last_name' => $azure_user['surname'] ?? ''
                ));
                
                // Update department from Azure AD
                if (!empty($azure_user['department'])) {
                    update_user_meta($wp_user->ID, 'department', sanitize_text_field($azure_user['department']));
                }
                
                // Store Azure AD jobTitle for reference (but don't auto-assign)
                if (!empty($azure_user['jobTitle'])) {
                    update_user_meta($wp_user->ID, 'azure_job_title', sanitize_text_field($azure_user['jobTitle']));
                }
                
                Azure_Logger::info("SSO: Updated existing user '$email' with Azure AD data");
            } else {
                Azure_Logger::info("SSO: Preserved local data for existing user '$email' (preserve_local_data enabled)");
            }
        }
        
        // Create SSO mapping
        $wpdb->insert(
            $sso_users_table,
            array(
                'wordpress_user_id' => $wp_user->ID,
                'azure_user_id' => $azure_user_id,
                'azure_email' => $email,
                'azure_display_name' => $display_name
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        if ($existing_mapping) {
            return array('status' => 'updated', 'message' => "Updated user '$email'");
        } else {
            $status = isset($user_id) ? 'created' : 'linked';
            $message = isset($user_id) ? "Created new user '$email'" : "Linked existing user '$email'";
            return array('status' => $status, 'message' => $message);
        }
    }
    
    /**
     * Generate unique username
     */
    private function generate_username($email, $display_name) {
        // Try email prefix first
        $username = sanitize_user(substr($email, 0, strpos($email, '@')));
        
        if (!username_exists($username)) {
            return $username;
        }
        
        // Try display name
        $username = sanitize_user(strtolower(str_replace(' ', '', $display_name)));
        
        if (!username_exists($username)) {
            return $username;
        }
        
        // Add numbers until unique
        $base_username = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Get the role to assign to SSO users (custom or default).
     *
     * Thin wrapper around the static helper so other modules (parent
     * importer, AcyMailing migrator) can answer "what role does SSO put
     * @{org_domain} users into?" without instantiating Azure_SSO_Sync.
     */
    private function get_sso_role() {
        return self::resolve_configured_role_slug();
    }

    /**
     * Public helper: resolve the role slug that this site's SSO is
     * configured to assign new sign-ins. Centralizes the
     * sso_use_custom_role / sso_custom_role_name / sso_default_role
     * decision tree and lazily creates the custom role on first call.
     *
     * Used by:
     *   - Azure_SSO_Sync internal sync flow (above)
     *   - Azure_Parent_Migration when bucketing @{org_domain} imports
     *     (those should land in the SSO role, not `parent`).
     *
     * Falls back to the WP `subscriber` role if the option is unset.
     */
    public static function resolve_configured_role_slug() {
        $use_custom_role = Azure_Settings::get_setting('sso_use_custom_role', false);

        if ($use_custom_role) {
            $custom_role_name = Azure_Settings::get_setting('sso_custom_role_name', 'AzureAD');

            $role_slug = sanitize_key(strtolower($custom_role_name));
            $role_display_name = sanitize_text_field($custom_role_name);

            if (!get_role($role_slug)) {
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info("SSO: Creating custom role '$role_display_name' with slug '$role_slug'");
                }

                $subscriber_role = get_role('subscriber');
                $capabilities = $subscriber_role ? $subscriber_role->capabilities : array(
                    'read' => true,
                    'level_0' => true,
                );
                $capabilities['azure_ad_user'] = true;

                add_role($role_slug, $role_display_name, $capabilities);

                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info("SSO: Custom role '$role_display_name' created successfully");
                }
            }

            return $role_slug;
        }

        return Azure_Settings::get_setting('sso_default_role', 'subscriber');
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats() {
        global $wpdb;
        
        $sso_users_table = Azure_Database::get_table_name('sso_users');
        
        if (!$sso_users_table) {
            return false;
        }
        
        $total_mappings = $wpdb->get_var("SELECT COUNT(*) FROM {$sso_users_table}");
        
        $recent_logins = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sso_users_table} WHERE last_login > %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        return array(
            'total_mappings' => intval($total_mappings),
            'recent_logins' => intval($recent_logins),
            'last_sync' => get_option('azure_sso_last_sync', 'Never')
        );
    }
    
    /**
     * AJAX handler to get all Azure AD users for exclusion dropdown
     */
    public function ajax_get_all_ad_users() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized access');
        }
        
        try {
            $credentials = Azure_Settings::get_credentials('sso');
            
            if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                wp_send_json_error('SSO credentials not configured');
            }
            
            // Get access token
            $access_token = $this->get_app_access_token($credentials);
            
            if (!$access_token) {
                wp_send_json_error('Failed to get access token');
            }
            
            // Get all users from Azure AD
            $azure_users = $this->get_azure_users($access_token);
            
            if (!$azure_users) {
                wp_send_json_error('Failed to retrieve users from Azure AD');
            }
            
            // Format users for dropdown
            $formatted_users = array();
            foreach ($azure_users as $user) {
                $formatted_users[] = array(
                    'id' => $user['id'],
                    'displayName' => $user['displayName'] ?? 'Unknown',
                    'mail' => $user['mail'] ?? '',
                    'userPrincipalName' => $user['userPrincipalName'] ?? ''
                );
            }
            
            // Sort by display name
            usort($formatted_users, function($a, $b) {
                return strcasecmp($a['displayName'], $b['displayName']);
            });
            
            wp_send_json_success(array(
                'users' => $formatted_users,
                'count' => count($formatted_users)
            ));
            
        } catch (Exception $e) {
            Azure_Logger::error('SSO: Failed to get AD users for exclusion dropdown - ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler to add user to exclusion list
     */
    public function ajax_add_user_to_exclusion() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized access');
        }
        
        $user_id = sanitize_text_field($_POST['user_id'] ?? '');
        $display_name = sanitize_text_field($_POST['display_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (empty($user_id)) {
            wp_send_json_error('User ID is required');
        }
        
        // Get current exclusion list
        $excluded_users = get_option('azure_sso_excluded_users', array());
        
        // Check if already excluded
        if (isset($excluded_users[$user_id])) {
            wp_send_json_error('User is already in the exclusion list');
        }
        
        // Add to exclusion list
        $added_at = current_time('mysql');
        $excluded_users[$user_id] = array(
            'display_name' => $display_name,
            'email' => $email,
            'added_at' => $added_at,
            'added_by' => get_current_user_id()
        );
        
        update_option('azure_sso_excluded_users', $excluded_users);
        
        Azure_Logger::info("SSO: User '{$display_name}' ({$email}) added to exclusion list by user " . get_current_user_id());
        
        wp_send_json_success(array(
            'message' => 'User added to exclusion list',
            'added_at' => date('M j, Y g:i A', strtotime($added_at))
        ));
    }
    
    /**
     * AJAX handler to remove user from exclusion list
     */
    public function ajax_remove_user_from_exclusion() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized access');
        }
        
        $user_id = sanitize_text_field($_POST['user_id'] ?? '');
        
        if (empty($user_id)) {
            wp_send_json_error('User ID is required');
        }
        
        // Get current exclusion list
        $excluded_users = get_option('azure_sso_excluded_users', array());
        
        // Check if user is in the list
        if (!isset($excluded_users[$user_id])) {
            wp_send_json_error('User is not in the exclusion list');
        }
        
        $removed_user = $excluded_users[$user_id];
        unset($excluded_users[$user_id]);
        
        update_option('azure_sso_excluded_users', $excluded_users);
        
        Azure_Logger::info("SSO: User '{$removed_user['display_name']}' ({$removed_user['email']}) removed from exclusion list by user " . get_current_user_id());
        
        wp_send_json_success(array(
            'message' => 'User removed from exclusion list'
        ));
    }
    
    public function ajax_save_exclude_external_domains() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized access');
        }

        $enabled = ($_POST['enabled'] ?? '') === 'true';
        Azure_Settings::update_setting('sso_exclude_external_domains', $enabled);

        Azure_Logger::info('SSO: Exclude external domains ' . ($enabled ? 'enabled' : 'disabled') . ' by user ' . get_current_user_id());

        wp_send_json_success(array('message' => 'Setting saved'));
    }

    /**
     * Check if a user is in the exclusion list or belongs to an external domain.
     * 
     * @param string $azure_user_id The Azure AD user ID
     * @param string $email The user's email (fallback check)
     * @return bool True if user is excluded
     */
    public static function is_user_excluded($azure_user_id, $email = '') {
        $excluded_users = get_option('azure_sso_excluded_users', array());
        
        // Check by Azure user ID
        if (isset($excluded_users[$azure_user_id])) {
            return true;
        }
        
        // Also check by email as fallback
        if (!empty($email)) {
            foreach ($excluded_users as $excluded) {
                if (isset($excluded['email']) && strtolower($excluded['email']) === strtolower($email)) {
                    return true;
                }
            }
        }

        // Check external domain exclusion
        if (!empty($email) && Azure_Settings::get_setting('sso_exclude_external_domains', false)) {
            $org_domain = strtolower(Azure_Settings::get_setting('org_domain', ''));
            if (!empty($org_domain)) {
                $email_domain = strtolower(substr($email, strrpos($email, '@') + 1));
                if ($email_domain !== $org_domain) {
                    return true;
                }
            }
        }

        return false;
    }
}
