<?php
/**
 * PTA Sync Engine - Manages WordPress to Azure AD synchronization
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Sync_Engine {
    
    private $graph_api;
    private $auth;
    private $credentials;
    
    public function __construct() {
        $this->credentials = Azure_Settings::get_credentials('sso'); // Reuse SSO credentials
        
        if (class_exists('Azure_SSO_Auth')) {
            $this->auth = new Azure_SSO_Auth();
        }
        
        // Sync queue processor handler. The custom `five_minutes` cron
        // interval and event scheduling are owned by Azure_PTA_Cron; this
        // only binds the handler that fires when the event runs.
        add_action('pta_process_sync_queue', array($this, 'process_sync_queue'));
        
        // Manual sync triggers
        add_action('wp_ajax_pta_sync_user', array($this, 'ajax_sync_user'));
        add_action('wp_ajax_pta_sync_all_users', array($this, 'ajax_sync_all_users'));
        add_action('wp_ajax_pta_provision_user', array($this, 'ajax_provision_user'));
        add_action('wp_ajax_pta_test_sync', array($this, 'ajax_test_sync'));
    }
    
    /**
     * Get Microsoft Graph access token
     */
    private function get_access_token() {
        if (!$this->auth) {
            throw new Exception('SSO authentication not available');
        }
        
        // Try to get app-only token for administrative operations
        return $this->get_app_access_token();
    }
    
    /**
     * Get application access token for admin operations
     */
    private function get_app_access_token() {
        $cache_key = 'azure_pta_app_token';
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
            throw new Exception('Token request failed: ' . $response->get_error_message());
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            throw new Exception('Token error: ' . $token_data['error_description']);
        }
        
        $access_token = $token_data['access_token'];
        $expires_in = $token_data['expires_in'] ?? 3600;
        
        // Cache token for slightly less than expiry time
        set_transient($cache_key, $access_token, $expires_in - 60);
        
        return $access_token;
    }
    
    
    /**
     * Process sync queue
     */
    public function process_sync_queue() {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('sync_queue');
        
        if (!$table) {
            return;
        }
        
        // Get pending sync jobs ordered by priority
        $jobs = $wpdb->get_results("
            SELECT * FROM $table 
            WHERE status = 'pending' 
            AND scheduled_at <= NOW()
            AND attempts < max_attempts
            ORDER BY priority ASC, created_at ASC 
            LIMIT 10
        ");
        
        foreach ($jobs as $job) {
            $this->process_sync_job($job);
        }
    }
    
    /**
     * Process individual sync job
     */
    private function process_sync_job($job) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('sync_queue');
        
        // Update status to processing
        $wpdb->update(
            $table,
            array(
                'status' => 'processing',
                'attempts' => $job->attempts + 1
            ),
            array('id' => $job->id),
            array('%s', '%d'),
            array('%d')
        );
        
        try {
            $payload = json_decode($job->payload, true) ?: array();
            $success = false;
            
            switch ($job->queue_type) {
                case 'user_sync':
                    $success = $this->process_user_sync($job->entity_id, $job->action, $payload);
                    break;
                    
                case 'group_sync':
                    $success = $this->process_group_sync($job->entity_id, $job->action, $payload);
                    break;
                    
                case 'user_provision':
                    $success = $this->process_user_provision($job->entity_id, $job->action, $payload);
                    break;
                    
                case 'user_delete':
                    $success = $this->process_user_delete($job->entity_id, $job->action, $payload);
                    break;
                    
                default:
                    throw new Exception('Unknown queue type: ' . $job->queue_type);
            }
            
            if ($success) {
                // Mark as completed
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'completed',
                        'processed_at' => current_time('mysql')
                    ),
                    array('id' => $job->id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                Azure_Logger::info("PTA Sync: Completed job {$job->id} ({$job->queue_type})");
            } else {
                throw new Exception('Sync operation returned false');
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // Mark as failed or retry
            if ($job->attempts >= $job->max_attempts) {
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'failed',
                        'error_message' => $error_message,
                        'processed_at' => current_time('mysql')
                    ),
                    array('id' => $job->id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                
                Azure_Logger::error("PTA Sync: Job {$job->id} failed permanently: $error_message");
            } else {
                // Reset to pending for retry with exponential backoff
                $retry_delay = pow(2, $job->attempts) * 300; // 5 min, 10 min, 20 min...
                $retry_time = date('Y-m-d H:i:s', time() + $retry_delay);
                
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'pending',
                        'error_message' => $error_message,
                        'scheduled_at' => $retry_time
                    ),
                    array('id' => $job->id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                
                Azure_Logger::warning("PTA Sync: Job {$job->id} will retry at $retry_time: $error_message");
            }
        }
    }
    
    /**
     * Process user sync operations
     */
    private function process_user_sync($user_id, $action, $payload) {
        $user = get_userdata($user_id);
        if (!$user) {
            throw new Exception("User $user_id not found");
        }
        
        switch ($action) {
            case 'update_job_title':
                return $this->update_user_job_title_in_azure($user, $payload['job_title']);
                
            case 'update_manager':
                return $this->update_user_manager_in_azure($user, $payload['manager_user_id']);
                
            case 'full_sync':
                return $this->full_user_sync($user);
                
            default:
                throw new Exception("Unknown user sync action: $action");
        }
    }
    
    /**
     * Process group sync operations
     */
    private function process_group_sync($user_id, $action, $payload) {
        switch ($action) {
            case 'sync_group_memberships':
                return $this->sync_user_group_memberships($user_id, $payload);
                
            default:
                throw new Exception("Unknown group sync action: $action");
        }
    }
    
    /**
     * Process user provisioning
     */
    private function process_user_provision($user_id, $action, $payload) {
        $user = get_userdata($user_id);
        if (!$user) {
            throw new Exception("User $user_id not found");
        }
        
        switch ($action) {
            case 'provision_new_user':
                return $this->provision_user_in_azure($user, $payload);
                
            default:
                throw new Exception("Unknown user provision action: $action");
        }
    }
    
    /**
     * Process user deletion
     */
    private function process_user_delete($user_id, $action, $payload) {
        switch ($action) {
            case 'delete_from_azure':
                return $this->delete_user_from_azure($payload['azure_user_id']);
                
            default:
                throw new Exception("Unknown user delete action: $action");
        }
    }
    
    /**
     * Update user job title in Azure AD
     */
    private function update_user_job_title_in_azure($user, $job_title) {
        $access_token = $this->get_access_token();
        
        // Get user's Azure AD object ID
        $azure_object_id = get_user_meta($user->ID, 'azure_object_id', true);
        if (!$azure_object_id) {
            // Try to find user by email
            $azure_user = $this->find_azure_user_by_email($user->user_email);
            if (!$azure_user) {
                throw new Exception("Azure AD user not found for {$user->user_email}");
            }
            $azure_object_id = $azure_user['id'];
            update_user_meta($user->ID, 'azure_object_id', $azure_object_id);
        }
        
        $api_url = "https://graph.microsoft.com/v1.0/users/{$azure_object_id}";
        
        $update_data = array(
            'jobTitle' => $job_title
        );
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($update_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to update job title: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info("PTA Sync: Updated job title for user {$user->ID}: $job_title");
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            throw new Exception("Job title update failed with status $response_code: $response_body");
        }
    }
    
    /**
     * Update user manager in Azure AD
     */
    private function update_user_manager_in_azure($user, $manager_user_id) {
        if (!$manager_user_id) {
            return true; // No manager to set
        }
        
        $access_token = $this->get_access_token();
        
        // Get user's Azure AD object ID
        $azure_object_id = get_user_meta($user->ID, 'azure_object_id', true);
        if (!$azure_object_id) {
            throw new Exception("Azure AD object ID not found for user {$user->ID}");
        }
        
        // Get manager's Azure AD object ID
        $manager_azure_id = get_user_meta($manager_user_id, 'azure_object_id', true);
        if (!$manager_azure_id) {
            $manager_user = get_userdata($manager_user_id);
            if (!$manager_user) {
                throw new Exception("Manager user $manager_user_id not found");
            }
            
            $azure_manager = $this->find_azure_user_by_email($manager_user->user_email);
            if (!$azure_manager) {
                throw new Exception("Manager's Azure AD user not found: {$manager_user->user_email}");
            }
            $manager_azure_id = $azure_manager['id'];
            update_user_meta($manager_user_id, 'azure_object_id', $manager_azure_id);
        }
        
        $api_url = "https://graph.microsoft.com/v1.0/users/{$azure_object_id}/manager/\$ref";
        
        $manager_ref = array(
            '@odata.id' => "https://graph.microsoft.com/v1.0/users/{$manager_azure_id}"
        );
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($manager_ref),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to update manager: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info("PTA Sync: Updated manager for user {$user->ID} -> manager $manager_user_id");
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            throw new Exception("Manager update failed with status $response_code: $response_body");
        }
    }
    
    /**
     * Provision new user in Azure AD
     */
    private function provision_user_in_azure($user, $payload) {
        $access_token = $this->get_access_token();
        
        // Check if user already exists in Azure AD
        $existing_user = $this->find_azure_user_by_email($user->user_email);
        if ($existing_user) {
            Azure_Logger::info("PTA Sync: User {$user->user_email} already exists in Azure AD");
            update_user_meta($user->ID, 'azure_object_id', $existing_user['id']);
            return true;
        }
        
        // Generate email alias using configured domain
        $domain = Azure_Settings::get_setting('org_domain', '');
        if (empty($domain)) {
            Azure_Logger::error("PTA Sync: Organization domain not configured. Please set up your domain in the Setup Wizard.");
            return false;
        }
        $email_alias = strtolower($user->first_name . substr($user->last_name, 0, 1)) . '@' . $domain;
        
        // Generate temporary password
        $temp_password = wp_generate_password(12, true, true);
        
        $user_data = array(
            'accountEnabled' => true,
            'displayName' => $user->display_name,
            'mailNickname' => strtolower($user->first_name . substr($user->last_name, 0, 1)),
            'userPrincipalName' => $email_alias,
            'passwordProfile' => array(
                'forceChangePasswordNextSignIn' => true,
                'password' => $temp_password
            ),
            'givenName' => $user->first_name,
            'surname' => $user->last_name,
            'jobTitle' => $payload['job_title'] ?? '',
            'department' => $payload['department'] ?? '',
            'usageLocation' => 'US'
        );
        
        $api_url = 'https://graph.microsoft.com/v1.0/users';
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($user_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to provision user: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 201) {
            $created_user = json_decode($response_body, true);
            $azure_object_id = $created_user['id'];
            
            // Store Azure AD info in WordPress
            update_user_meta($user->ID, 'azure_object_id', $azure_object_id);
            update_user_meta($user->ID, 'azure_upn', $email_alias);
            update_user_meta($user->ID, 'azure_temp_password', $temp_password);
            
            Azure_Logger::info("PTA Sync: Provisioned user {$user->user_email} in Azure AD: $email_alias");
            
            // Assign Office 365 license
            $this->assign_office365_license($azure_object_id);
            
            // Send welcome email
            $this->send_welcome_email($user, $email_alias, $temp_password, $payload['personal_email'] ?? $user->user_email);
            
            return true;
        } else {
            throw new Exception("User provision failed with status $response_code: $response_body");
        }
    }
    
    /**
     * Assign Office 365 Business Basic license
     */
    private function assign_office365_license($azure_user_id) {
        try {
            $access_token = $this->get_access_token();
            
            // Business Basic SKU ID (this may need to be configured per tenant)
            $business_basic_sku = 'O365_BUSINESS_ESSENTIALS'; // You may need to get the actual SKU ID
            
            $license_data = array(
                'addLicenses' => array(
                    array('skuId' => $business_basic_sku)
                ),
                'removeLicenses' => array()
            );
            
            $api_url = "https://graph.microsoft.com/v1.0/users/{$azure_user_id}/assignLicense";
            
            $response = wp_remote_post($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($license_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::warning("PTA Sync: Failed to assign license: " . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            
            if ($response_code === 200) {
                Azure_Logger::info("PTA Sync: Assigned Office 365 license to user $azure_user_id");
                return true;
            } else {
                $response_body = wp_remote_retrieve_body($response);
                Azure_Logger::warning("PTA Sync: License assignment failed with status $response_code: $response_body");
                return false;
            }
            
        } catch (Exception $e) {
            Azure_Logger::warning("PTA Sync: License assignment error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send welcome email to new user
     */
    private function send_welcome_email($user, $azure_email, $temp_password, $personal_email) {
        // Get organization settings with fallbacks
        $org_name = Azure_Settings::get_setting('org_name', get_bloginfo('name'));
        $org_team = Azure_Settings::get_setting('org_team_name', '');
        if (empty($org_team)) {
            $org_team = $org_name . ' Administration';
        }
        $org_domain = Azure_Settings::get_setting('org_domain', 'yourorg.net');
        $from_email = Azure_Settings::get_setting('org_admin_email', '');
        if (empty($from_email)) {
            $from_email = 'admin@' . $org_domain;
        }
        
        $subject = "Welcome to {$org_name} - Your Office 365 Account";
        
        $message = "Hello {$user->first_name},\n\n";
        $message .= "Welcome to {$org_name}! Your Office 365 account has been created.\n\n";
        $message .= "Your login credentials:\n";
        $message .= "Username: $azure_email\n";
        $message .= "Temporary Password: $temp_password\n\n";
        $message .= "You will be required to change your password on first login.\n\n";
        $message .= "You can access your account at: https://office.com\n\n";
        $message .= "If you have any questions, please contact the PTA administrators.\n\n";
        $message .= "Best regards,\n{$org_team}";
        
        // Use the email module if available
        if (class_exists('Azure_Email_Mailer')) {
            $mailer = new Azure_Email_Mailer();
            return $mailer->send_email_graph($personal_email, $subject, $message, array('From: ' . $from_email));
        } else {
            // Fallback to WordPress mail
            return wp_mail($personal_email, $subject, $message, array('From: ' . $from_email));
        }
    }
    
    /**
     * Find Azure AD user by email
     */
    private function find_azure_user_by_email($email) {
        $access_token = $this->get_access_token();
        
        $api_url = "https://graph.microsoft.com/v1.0/users?$filter=userPrincipalName eq '" . urlencode($email) . "'";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (!empty($data['value'])) {
            return $data['value'][0];
        }
        
        return false;
    }
    
    /**
     * Sync user group memberships
     */
    private function sync_user_group_memberships($user_id, $payload) {
        // Delegate to the groups manager
        if (class_exists('Azure_PTA_Groups_Manager')) {
            $groups_manager = new Azure_PTA_Groups_Manager();
            return $groups_manager->sync_user_group_memberships($user_id);
        } else {
            Azure_Logger::warning("PTA Sync: Groups manager not available for user $user_id");
            return false;
        }
    }
    
    /**
     * Delete user from Azure AD
     */
    private function delete_user_from_azure($azure_user_id) {
        $access_token = $this->get_access_token();
        
        $api_url = "https://graph.microsoft.com/v1.0/users/{$azure_user_id}";
        
        $response = wp_remote_request($api_url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to delete user: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info("PTA Sync: Deleted user from Azure AD: $azure_user_id");
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            throw new Exception("User deletion failed with status $response_code: $response_body");
        }
    }
    
    /**
     * Full user sync
     */
    private function full_user_sync($user) {
        $pta_manager = Azure_PTA_Manager::get_instance();
        $job_title = $pta_manager->update_user_job_title($user->ID);
        
        // Update job title
        $this->update_user_job_title_in_azure($user, $job_title);
        
        // Update manager if user has primary role
        $assignments = $pta_manager->get_user_assignments($user->ID);
        $primary_assignment = null;
        
        foreach ($assignments as $assignment) {
            if ($assignment->is_primary) {
                $primary_assignment = $assignment;
                break;
            }
        }
        
        if ($primary_assignment) {
            $department = $pta_manager->get_department($primary_assignment->department_id);
            if ($department && $department->vp_user_id) {
                $this->update_user_manager_in_azure($user, $department->vp_user_id);
            }
        }
        
        return true;
    }
    
    /**
     * AJAX Handlers
     */
    public function ajax_sync_user() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        
        $user_id = intval($_POST['user_id']);
        
        try {
            Azure_PTA_Database::queue_sync(
                'user_sync',
                'user',
                $user_id,
                'full_sync',
                null,
                1 // High priority
            );
            
            wp_send_json_success('User sync queued successfully');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_test_sync() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        
        try {
            $access_token = $this->get_access_token();
            
            wp_send_json_success(array(
                'message' => 'Sync engine connection test successful',
                'token_available' => !empty($access_token)
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
