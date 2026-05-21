<?php
/**
 * Newsletter AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Ajax {
    
    public function __construct() {
        // Newsletter AJAX handlers
        add_action('wp_ajax_azure_newsletter_save', array($this, 'save_newsletter'));
        add_action('wp_ajax_azure_newsletter_send_test', array($this, 'send_test_email'));
        add_action('wp_ajax_azure_newsletter_spam_check', array($this, 'check_spam_score'));
        add_action('wp_ajax_azure_newsletter_accessibility_check', array($this, 'check_accessibility'));
        add_action('wp_ajax_azure_newsletter_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_azure_newsletter_get_recipients_count', array($this, 'get_recipients_count'));
        add_action('wp_ajax_azure_newsletter_pause', array($this, 'pause_newsletter'));
        add_action('wp_ajax_azure_newsletter_resume', array($this, 'resume_newsletter'));
        add_action('wp_ajax_azure_newsletter_cancel', array($this, 'cancel_newsletter'));
        
        // Database management
        add_action('wp_ajax_azure_newsletter_create_tables', array($this, 'create_tables'));
        add_action('wp_ajax_azure_newsletter_reset_data', array($this, 'reset_data'));
        
        // Settings page test email
        add_action('wp_ajax_azure_newsletter_send_test_email', array($this, 'send_test_email_from_settings'));
        
        // Queue management
        add_action('wp_ajax_azure_newsletter_process_queue', array($this, 'process_queue_now'));
        add_action('wp_ajax_azure_newsletter_schedule_cron', array($this, 'schedule_cron'));
        add_action('wp_ajax_azure_newsletter_get_queue_details', array($this, 'get_queue_details'));
        
        // Template management
        add_action('wp_ajax_azure_newsletter_get_template', array($this, 'get_template'));
        add_action('wp_ajax_azure_newsletter_reset_templates', array($this, 'reset_templates'));
        
        // Campaign preview
        add_action('wp_ajax_azure_newsletter_get_preview', array($this, 'get_newsletter_preview'));
        
        // List member management
        add_action('wp_ajax_azure_newsletter_get_list_members', array($this, 'get_list_members'));
        add_action('wp_ajax_azure_newsletter_search_users', array($this, 'search_users'));
        add_action('wp_ajax_azure_newsletter_add_list_member', array($this, 'add_list_member'));
        add_action('wp_ajax_azure_newsletter_remove_list_member', array($this, 'remove_list_member'));
        
        // Stats sync
        add_action('wp_ajax_azure_newsletter_sync_stats', array($this, 'sync_stats'));
    }
    
    /**
     * Reset system templates to defaults
     */
    public function reset_templates() {
        check_ajax_referer('azure_newsletter_reset_templates', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Ensure Newsletter Module class is loaded
        if (!class_exists('Azure_Newsletter_Module')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-module.php';
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        
        // Check if is_system column exists, add it if not
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'is_system'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN is_system tinyint(1) DEFAULT 0");
        }
        
        // Check if content_html column exists, add it if not
        $content_col_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'content_html'");
        if (empty($content_col_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN content_html longtext AFTER description");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN content_json longtext AFTER content_html");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN thumbnail_url varchar(500) AFTER description");
        }
        
        // Delete ALL existing templates (since old ones don't have is_system)
        $deleted = $wpdb->query("DELETE FROM {$table}");
        
        // Re-insert default templates with HTML content
        $default_templates = Azure_Newsletter_Module::get_default_templates();
        $inserted = 0;
        $errors = array();
        
        foreach ($default_templates as $template) {
            $result = $wpdb->insert($table, $template);
            if ($result) {
                $inserted++;
            } else {
                $errors[] = $template['name'] . ': ' . $wpdb->last_error;
            }
        }
        
        if (!empty($errors)) {
            wp_send_json_error('Insert errors: ' . implode(', ', $errors));
        }

        // Wipe cached PNG thumbnails so they regenerate from the new HTML
        do_action('azure_newsletter_templates_reset');

        wp_send_json_success(array(
            'message' => sprintf(__('Reset complete. Deleted %d old templates, inserted %d new templates.', 'azure-plugin'), $deleted, $inserted),
            'deleted' => $deleted,
            'inserted' => $inserted
        ));
    }
    
    /**
     * Get template content for preview or editor
     */
    public function get_template() {
        check_ajax_referer('newsletter_get_template', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $template_id = intval($_POST['template_id'] ?? 0);
        
        if (!$template_id) {
            wp_send_json_error('Invalid template ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $template_id
        ));
        
        if (!$template) {
            wp_send_json_error('Template not found');
        }
        
        wp_send_json_success(array(
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'content_html' => $template->content_html,
            'content_json' => $template->content_json
        ));
    }
    
    /**
     * Get newsletter preview HTML for Quick View
     */
    public function get_newsletter_preview() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        
        if (!$newsletter_id) {
            wp_send_json_error('Invalid newsletter ID');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletters';
        $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $lists_table = $wpdb->prefix . 'azure_newsletter_lists';
        
        $newsletter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $newsletter_id
        ));
        
        if (!$newsletter) {
            wp_send_json_error('Newsletter not found');
        }
        
        // Get recipient info from saved lists OR queue
        $recipients = array('total' => 0, 'lists' => array());
        
        // First try to get from saved recipient_lists
        $saved_lists = json_decode($newsletter->recipient_lists ?? '[]', true);
        if (!empty($saved_lists)) {
            foreach ($saved_lists as $list_id) {
                if ($list_id === 'all') {
                    $count = count_users()['total_users'];
                    $recipients['lists'][] = array('name' => 'All WordPress Subscribers', 'count' => $count);
                    $recipients['total'] += $count;
                } else {
                    $list = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$lists_table} WHERE id = %d",
                        intval($list_id)
                    ));
                    if ($list) {
                        $count = 0;
                        if ($list->type === 'role') {
                            $criteria = json_decode($list->criteria, true);
                            if (!empty($criteria['roles'])) {
                                foreach ($criteria['roles'] as $role) {
                                    $count += count(get_users(array('role' => $role)));
                                }
                            }
                        } elseif ($list->type === 'custom') {
                            $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
                            $count = intval($wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d AND unsubscribed_at IS NULL",
                                $list_id
                            )));
                        }
                        $recipients['lists'][] = array('name' => $list->name, 'count' => $count);
                        $recipients['total'] += $count;
                    }
                }
            }
        } else {
            // Fall back to queue count for sent newsletters
            $queued_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE newsletter_id = %d",
                $newsletter_id
            ));
            $recipients['total'] = intval($queued_count);
        }
        
        // Get stats if sent
        $stats = null;
        if ($newsletter->status === 'sent') {
            // Get sent count from queue (more reliable than stats table)
            $sent_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE newsletter_id = %d AND status = 'sent'",
                $newsletter_id
            ));
            
            // Get engagement stats from stats table (populated by webhooks)
            $open_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = %d AND event_type = 'opened'",
                $newsletter_id
            ));
            $click_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = %d AND event_type = 'clicked'",
                $newsletter_id
            ));
            $bounce_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$stats_table} WHERE newsletter_id = %d AND event_type = 'bounced'",
                $newsletter_id
            ));
            
            $stats = array(
                'sent' => intval($sent_count),
                'opens' => intval($open_count),
                'clicks' => intval($click_count),
                'bounces' => intval($bounce_count),
                'open_rate' => $sent_count > 0 ? round(($open_count / $sent_count) * 100, 1) : 0,
                'click_rate' => $sent_count > 0 ? round(($click_count / $sent_count) * 100, 1) : 0
            );
        }
        
        // Format scheduled_at
        $scheduled_at = null;
        if ($newsletter->scheduled_at) {
            $scheduled_at = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($newsletter->scheduled_at));
        }
        
        // Clean up HTML for preview - remove any CSS text that leaked into body
        $clean_html = $this->clean_html_for_preview($newsletter->content_html);
        
        // Return all data
        wp_send_json_success(array(
            'html' => $clean_html,
            'subject' => $newsletter->subject,
            'name' => $newsletter->name,
            'status' => $newsletter->status,
            'from_email' => $newsletter->from_email,
            'from_name' => $newsletter->from_name,
            'scheduled_at' => $scheduled_at,
            'recipients' => $recipients,
            'stats' => $stats
        ));
    }
    
    /**
     * Get list members
     */
    public function get_list_members() {
        check_ajax_referer('azure_newsletter_lists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $list_id = intval($_POST['list_id'] ?? 0);
        
        if (!$list_id) {
            wp_send_json_error('Invalid list ID');
        }
        
        global $wpdb;
        $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
        
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT m.user_id, m.email, u.user_email, u.display_name 
             FROM {$members_table} m
             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
             WHERE m.list_id = %d AND m.unsubscribed_at IS NULL
             ORDER BY u.display_name ASC",
            $list_id
        ));
        
        wp_send_json_success(array('members' => $members));
    }
    
    /**
     * Search WordPress users
     */
    public function search_users() {
        check_ajax_referer('azure_newsletter_lists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $query = sanitize_text_field($_POST['query'] ?? '');
        
        if (strlen($query) < 2) {
            wp_send_json_success(array('users' => array()));
        }
        
        // Search users by name or email
        $users = get_users(array(
            'search' => '*' . $query . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number' => 20,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));
        
        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'ID' => $user->ID,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name
            );
        }
        
        wp_send_json_success(array('users' => $results));
    }
    
    /**
     * Add member to list
     */
    public function add_list_member() {
        check_ajax_referer('azure_newsletter_lists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $list_id = intval($_POST['list_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if (!$list_id || !$user_id) {
            wp_send_json_error('Invalid parameters');
        }
        
        // Get user email
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }
        
        global $wpdb;
        $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
        
        // Check if already a member (using composite key: list_id + email)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT list_id, email, unsubscribed_at FROM {$members_table} WHERE list_id = %d AND email = %s",
            $list_id, $user->user_email
        ));
        
        if ($existing) {
            // Reactivate if unsubscribed
            $wpdb->update(
                $members_table,
                array('unsubscribed_at' => null, 'user_id' => $user_id),
                array('list_id' => $list_id, 'email' => $user->user_email)
            );
            wp_send_json_success(array('message' => 'Member reactivated'));
        } else {
            // Insert new member
            $result = $wpdb->insert($members_table, array(
                'list_id' => $list_id,
                'user_id' => $user_id,
                'email' => $user->user_email,
                'subscribed_at' => current_time('mysql')
            ));
            
            if ($result === false) {
                error_log("[Newsletter] Failed to add member: " . $wpdb->last_error);
                wp_send_json_error('Failed to add member: ' . $wpdb->last_error);
            }
            
            wp_send_json_success(array('message' => 'Member added'));
        }
    }
    
    /**
     * Remove member from list
     */
    public function remove_list_member() {
        check_ajax_referer('azure_newsletter_lists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $list_id = intval($_POST['list_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if (!$list_id || !$user_id) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
        
        // Soft delete by setting unsubscribed_at
        $wpdb->update(
            $members_table,
            array('unsubscribed_at' => current_time('mysql')),
            array('list_id' => $list_id, 'user_id' => $user_id)
        );
        
        wp_send_json_success(array('message' => 'Member removed'));
    }
    
    /**
     * Save newsletter (draft or scheduled)
     */
    public function save_newsletter() {
        // ALWAYS log to error_log for debugging (bypasses any Logger issues)
        error_log('=== [Newsletter] save_newsletter AJAX START ===');
        error_log('[Newsletter] send_option: ' . ($_POST['send_option'] ?? 'NOT SET'));
        error_log('[Newsletter] newsletter_lists RAW: ' . ($_POST['newsletter_lists'] ?? 'NOT SET'));
        error_log('[Newsletter] All POST keys: ' . implode(', ', array_keys($_POST)));
        
        // Also log via Azure_Logger if available
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Newsletter AJAX: save_newsletter called');
            Azure_Logger::info('Newsletter AJAX: send_option=' . ($_POST['send_option'] ?? 'not set'));
            Azure_Logger::info('Newsletter AJAX: newsletter_lists=' . ($_POST['newsletter_lists'] ?? 'not set'));
        }
        
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('Newsletter AJAX: Permission denied for user');
            }
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletters';
        
        // Ensure recipient_lists column exists (migration)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'recipient_lists'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN recipient_lists text AFTER content_json");
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        $send_option = sanitize_key($_POST['send_option'] ?? 'draft');
        
        // Parse from field
        $from_parts = explode('|', sanitize_text_field($_POST['newsletter_from'] ?? ''));
        $from_email = $from_parts[0] ?? '';
        $from_name = $from_parts[1] ?? '';
        
        // Handle recipient lists - decode from JSON string
        $recipient_lists = array();
        if (!empty($_POST['newsletter_lists'])) {
            $lists_raw = stripslashes($_POST['newsletter_lists']);
            $decoded = json_decode($lists_raw, true);
            if (is_array($decoded)) {
                $recipient_lists = array_map('sanitize_text_field', $decoded);
            } elseif (is_string($_POST['newsletter_lists'])) {
                // Fallback for single value
                $recipient_lists = array(sanitize_text_field($_POST['newsletter_lists']));
            }
        }
        
        $data = array(
            'name' => sanitize_text_field($_POST['newsletter_name'] ?? ''),
            'subject' => sanitize_text_field($_POST['newsletter_subject'] ?? ''),
            'from_email' => $from_email,
            'from_name' => $from_name,
            'content_html' => $this->sanitize_email_html($_POST['newsletter_content_html'] ?? ''),
            'content_json' => wp_unslash($_POST['newsletter_content_json'] ?? ''),
            'recipient_lists' => json_encode($recipient_lists),
            'updated_at' => current_time('mysql')
        );
        
        // Handle scheduling
        if ($send_option === 'schedule') {
            $schedule_date = sanitize_text_field($_POST['schedule_date'] ?? '');
            $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '09:00');
            
            if ($schedule_date) {
                // Convert PST to server time
                $pst = new DateTimeZone('America/Los_Angeles');
                $server_tz = new DateTimeZone(wp_timezone_string());
                
                $dt = new DateTime($schedule_date . ' ' . $schedule_time, $pst);
                $dt->setTimezone($server_tz);
                
                $data['scheduled_at'] = $dt->format('Y-m-d H:i:s');
                $data['status'] = 'scheduled';
            }
        } elseif ($send_option === 'now') {
            $data['status'] = 'scheduled';
            $data['scheduled_at'] = current_time('mysql');
        } else {
            $data['status'] = 'draft';
        }
        
        // Generate archive token if not exists
        if (empty($data['archive_token'])) {
            $data['archive_token'] = wp_generate_password(32, false);
        }
        
        if ($newsletter_id > 0) {
            // Update existing
            $wpdb->update($table, $data, array('id' => $newsletter_id));
        } else {
            // Create new
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $newsletter_id = $wpdb->insert_id;
        }
        
        // Create WordPress page if requested
        if (!empty($_POST['create_wp_page'])) {
            $this->create_newsletter_page($newsletter_id, $data);
        }
        
        // Log all the data we received for debugging
        error_log('[Newsletter] After save: newsletter_id=' . $newsletter_id . ', send_option=' . $send_option . ', status=' . $data['status']);
        error_log('[Newsletter] recipient_lists parsed: ' . json_encode($recipient_lists));
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Newsletter Save: newsletter_id=' . $newsletter_id . ', send_option=' . $send_option . ', status=' . $data['status']);
            Azure_Logger::info('Newsletter Save: recipient_lists received: ' . json_encode($recipient_lists));
        }
        
        // Queue for sending if scheduled for now
        error_log('[Newsletter] Checking queue condition: send_option=' . $send_option . ' (should be "now" or "schedule")');
        if ($send_option === 'now' || $send_option === 'schedule') {
            error_log('[Newsletter] ENTERING queue block');
            // Use the already-decoded recipient_lists
            $lists_to_queue = !empty($recipient_lists) ? $recipient_lists : array('all');
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Newsletter Send: Starting queue process for newsletter #' . $newsletter_id);
                Azure_Logger::info('Newsletter Send: Lists to queue: ' . json_encode($lists_to_queue));
            }
            
            // Ensure queue class is loaded
            if (!class_exists('Azure_Newsletter_Queue')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php';
            }
            
            $queue = new Azure_Newsletter_Queue();
            $total_queued = 0;
            $total_original = 0;
            $total_blocked = 0;
            $total_bounced = 0;
            $total_skipped = 0;
            $queue_errors = array();
            
            // Queue for each selected list
            error_log('[Newsletter] lists_to_queue: ' . json_encode($lists_to_queue));
            foreach ($lists_to_queue as $list_id) {
                error_log('[Newsletter] Queuing for list_id: ' . $list_id);
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info('Newsletter Send: Queuing for list: ' . $list_id);
                }
                
                $queue_result = $queue->queue_newsletter($newsletter_id, $list_id, $data['scheduled_at']);
                error_log('[Newsletter] queue_result: ' . json_encode($queue_result));
                
                if (isset($queue_result['error'])) {
                    // Include debug info if available
                    $error_msg = $queue_result['error'];
                    if (!empty($queue_result['debug'])) {
                        $debug = $queue_result['debug'];
                        $error_msg .= " [DEBUG: type={$debug['list_type']}, total={$debug['members_in_db']}, active={$debug['active_members']}";
                        if (!empty($debug['sample_data'])) {
                            $error_msg .= ", sample: " . implode(' | ', array_slice($debug['sample_data'], 0, 2));
                        }
                        $error_msg .= "]";
                    }
                    $queue_errors[] = $error_msg;
                    if (class_exists('Azure_Logger')) {
                        Azure_Logger::warning('Newsletter Send: Queue error for list ' . $list_id . ': ' . $queue_result['error']);
                    }
                } else {
                    $queued_count = $queue_result['queued'] ?? 0;
                    $total_queued += $queued_count;
                    $total_original += $queue_result['original_count'] ?? 0;
                    $total_blocked += $queue_result['blocked'] ?? 0;
                    $total_bounced += $queue_result['bounced'] ?? 0;
                    $total_skipped += $queue_result['skipped'] ?? 0;
                    
                    if (class_exists('Azure_Logger')) {
                        Azure_Logger::info('Newsletter Send: Queued ' . $queued_count . ' emails for list ' . $list_id);
                    }
                }
            }
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Newsletter Send: Total queued: ' . $total_queued . ', blocked: ' . $total_blocked . ', bounced: ' . $total_bounced);
            }
            
            // If "Send Now", immediately process the queue
            $process_result = null;
            if ($send_option === 'now' && $total_queued > 0) {
                error_log('[Newsletter] Send Now selected - immediately processing queue');
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info('Newsletter Send: Processing queue immediately (Send Now)');
                }
                
                try {
                    $process_result = $queue->process_batch();
                    error_log('[Newsletter] Immediate process result: ' . json_encode($process_result));
                } catch (Exception $e) {
                    error_log('[Newsletter] Error processing queue immediately: ' . $e->getMessage());
                    $process_result = array('error' => $e->getMessage());
                }
            }
            
            wp_send_json_success(array(
                'newsletter_id' => $newsletter_id,
                'status' => $data['status'],
                'queued' => $total_queued,
                'original_recipients' => $total_original,
                'blocked' => $total_blocked,
                'bounced' => $total_bounced,
                'skipped' => $total_skipped,
                'filtered_total' => $total_blocked + $total_bounced,
                'errors' => $queue_errors,
                'sent_immediately' => $process_result,
                'debug' => 'path:queued'
            ));
        }
        
        // This path is for draft saves only
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Newsletter Save: Saved as draft (no queuing), send_option=' . $send_option);
        }
        
        wp_send_json_success(array(
            'newsletter_id' => $newsletter_id,
            'status' => $data['status'],
            'content_html' => $data['content_html'],
            'content_json' => $data['content_json']
        ));
    }
    
    /**
     * Send test email
     */
    public function send_test_email() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $html = $this->sanitize_email_html($_POST['html'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? 'Test Newsletter');
        
        // Parse from field
        $from_parts = explode('|', sanitize_text_field($_POST['from'] ?? ''));
        $from_email = $from_parts[0] ?? get_option('admin_email');
        $from_name = $from_parts[1] ?? get_bloginfo('name');
        
        if (!is_email($email)) {
            wp_send_json_error('Invalid email address');
        }
        
        if (empty($html)) {
            wp_send_json_error('No email content provided');
        }
        
        // Clean and prepare HTML for email
        $html = self::prepare_email_html($html);
        
        // Ensure sender class is loaded
        if (!class_exists('Azure_Newsletter_Sender')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-sender.php';
        }
        
        $sender = new Azure_Newsletter_Sender();
        $result = $sender->send(array(
            'to' => $email,
            'from' => $from_email,
            'from_name' => $from_name,
            'subject' => '[TEST] ' . $subject,
            'html' => $html
        ));
        
        if ($result['success']) {
            wp_send_json_success('Test email sent successfully');
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    /**
     * Prepare HTML for email sending - clean up GrapesJS output
     */
    private static function prepare_email_html($html) {
        // Remove raw CSS text that appears before HTML tags (GrapesJS bug)
        // This catches patterns like: "* { box-sizing: border-box; } body {margin: 0;} ..."
        $html = preg_replace('/^[^<]*\*\s*\{[^}]*\}[^<]*/s', '', $html);
        
        // Remove any text content before the first HTML tag
        $html = preg_replace('/^[^<]+/', '', $html);
        
        // Extract style tags from body and collect them
        $styles = '';
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            foreach ($matches[1] as $style) {
                $styles .= $style . "\n";
            }
            // Remove style tags from body
            $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        }
        
        // Check if it already has a proper structure
        if (stripos($html, '<!DOCTYPE') === false) {
            // Build proper email HTML structure
            $email_html = "<!DOCTYPE html>\n";
            $email_html .= "<html xmlns=\"http://www.w3.org/1999/xhtml\">\n";
            $email_html .= "<head>\n";
            $email_html .= "<meta charset=\"UTF-8\">\n";
            $email_html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
            $email_html .= "<meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n";
            $email_html .= "<title>Newsletter</title>\n";
            
            // Add collected styles in head
            if (!empty($styles)) {
                $email_html .= "<style type=\"text/css\">\n";
                $email_html .= "/* Email Reset */\n";
                $email_html .= "body, table, td { margin: 0; padding: 0; }\n";
                $email_html .= "img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; display: block; }\n";
                $email_html .= $styles;
                $email_html .= "</style>\n";
            }
            
            $email_html .= "</head>\n";
            $email_html .= "<body style=\"margin:0;padding:0;\">\n";
            $email_html .= trim($html);
            $email_html .= "\n</body>\n</html>";
            
            return $email_html;
        }
        
        // Already has structure - just move styles to head if they're in body
        if (!empty($styles) && preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $head_match)) {
            $new_head = $head_match[1] . "\n<style type=\"text/css\">\n" . $styles . "\n</style>\n";
            $html = str_replace($head_match[1], $new_head, $html);
        }
        
        return $html;
    }
    
    /**
     * Check spam score - combines local checks with optional external SpamAssassin
     */
    public function check_spam_score() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $html = $this->sanitize_email_html($_POST['html'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? 'test@example.com');
        $use_external = isset($_POST['use_external']) && $_POST['use_external'] === 'true';
        
        // Run local checks first (always available, fast)
        $local_result = $this->run_local_spam_checks($html, $subject);
        
        // Optionally run external SpamAssassin check via Postmark (free API)
        $external_result = null;
        if ($use_external) {
            $external_result = $this->run_postmark_spamcheck($html, $subject, $from_email);
        }
        
        // Combine results
        $combined_score = $local_result['score'];
        $combined_issues = $local_result['issues'];
        
        if ($external_result && $external_result['success']) {
            $sa_score = $external_result['score'];
            
            // Add SpamAssassin findings
            if (!empty($external_result['rules'])) {
                foreach ($external_result['rules'] as $rule) {
                    if ($rule['score'] > 0) { // Only show rules that add to spam score
                        $combined_issues[] = array(
                            'type' => 'spamassassin',
                            'message' => $rule['description'] . ' [' . $rule['name'] . ']',
                            'score' => $rule['score']
                        );
                    }
                }
            }
            
            // Weight: average of local and SpamAssassin scores
            $combined_score = round(($local_result['score'] + min($sa_score, 10)) / 2, 1);
        }
        
        // Determine overall message
        $message = 'Excellent! Your email looks good.';
        if ($combined_score >= 3 && $combined_score < 5) {
            $message = 'Good, but there are some areas for improvement.';
        } elseif ($combined_score >= 5) {
            $message = 'Warning: Your email may be flagged as spam.';
        }
        
        wp_send_json_success(array(
            'score' => min($combined_score, 10),
            'message' => $message,
            'issues' => $combined_issues,
            'local_score' => $local_result['score'],
            'external_result' => $external_result,
            'checks_performed' => array(
                'local' => true,
                'spamassassin' => $use_external && $external_result && $external_result['success']
            )
        ));
    }
    
    /**
     * Run local spam checks (no external dependencies)
     */
    private function run_local_spam_checks($html, $subject) {
        $issues = array();
        $score = 0;
        
        // === Subject Line Checks ===
        $spam_words = array(
            'free' => 1, 'win' => 1.5, 'winner' => 1.5, 'cash' => 1.5, 
            'prize' => 1.5, 'urgent' => 1, 'act now' => 1.5, 'limited time' => 1,
            'click here' => 1, 'buy now' => 1, 'order now' => 1, 'don\'t miss' => 0.5,
            'exclusive deal' => 1, 'risk free' => 1.5, 'no obligation' => 1,
            'million' => 1.5, 'billion' => 1.5, 'guarantee' => 0.5,
            '100%' => 1, 'double your' => 1.5, 'earn money' => 1.5
        );
        
        foreach ($spam_words as $word => $weight) {
            if (stripos($subject, $word) !== false) {
                $issues[] = array(
                    'type' => 'subject',
                    'message' => "Subject contains spam trigger: '{$word}'",
                    'score' => $weight
                );
                $score += $weight;
            }
        }
        
        // ALL CAPS subject
        if (strlen($subject) > 5 && strtoupper($subject) === $subject) {
            $issues[] = array('type' => 'subject', 'message' => 'Subject is all uppercase', 'score' => 2);
            $score += 2;
        }
        
        // Excessive punctuation
        if (substr_count($subject, '!') > 1) {
            $issues[] = array('type' => 'subject', 'message' => 'Multiple exclamation marks', 'score' => 1);
            $score += 1;
        }
        
        // RE: or FW: spam trick
        if (preg_match('/^(RE:|FW:|FWD:)/i', $subject)) {
            $issues[] = array('type' => 'subject', 'message' => 'Starts with RE:/FW: (spam technique)', 'score' => 1.5);
            $score += 1.5;
        }
        
        // === Content Checks ===
        if (empty($html)) {
            $issues[] = array('type' => 'content', 'message' => 'No HTML content', 'score' => 3);
            $score += 3;
        } else {
            // Image to text ratio
            preg_match_all('/<img/i', $html, $images);
            $text_length = strlen(trim(strip_tags($html)));
            $image_count = count($images[0]);
            
            if ($image_count > 0 && $text_length < 100) {
                $issues[] = array('type' => 'content', 'message' => 'Low text-to-image ratio', 'score' => 2);
                $score += 2;
            }
            
            // Unsubscribe link
            if (stripos($html, 'unsubscribe') === false) {
                $issues[] = array('type' => 'compliance', 'message' => 'Missing unsubscribe link', 'score' => 2);
                $score += 2;
            }
            
            // Physical address
            $has_address = stripos($html, 'address') !== false || 
                           stripos($html, 'street') !== false ||
                           preg_match('/\d{5}(-\d{4})?/', $html);
            if (!$has_address) {
                $issues[] = array('type' => 'compliance', 'message' => 'No physical address (CAN-SPAM)', 'score' => 1);
                $score += 1;
            }
            
            // Spam phrases in content
            $content_spam = array('click below' => 0.5, 'act immediately' => 1, 'dear friend' => 1,
                'you have been selected' => 1.5, 'this is not spam' => 2);
            foreach ($content_spam as $phrase => $weight) {
                if (stripos($html, $phrase) !== false) {
                    $issues[] = array('type' => 'content', 'message' => "Spam phrase: '{$phrase}'", 'score' => $weight);
                    $score += $weight;
                }
            }
            
            // URL shorteners
            $shorteners = array('bit.ly', 'tinyurl', 'goo.gl', 't.co');
            foreach ($shorteners as $shortener) {
                if (stripos($html, $shortener) !== false) {
                    $issues[] = array('type' => 'content', 'message' => "URL shortener ({$shortener})", 'score' => 1);
                    $score += 1;
                    break;
                }
            }
        }
        
        return array('score' => round($score, 1), 'issues' => $issues);
    }
    
    /**
     * Run SpamAssassin check via Postmark's free API
     * https://spamcheck.postmarkapp.com/
     */
    private function run_postmark_spamcheck($html, $subject, $from_email) {
        // Generate proper email headers to avoid false positives
        $message_id = '<' . uniqid('newsletter-', true) . '@' . parse_url(home_url(), PHP_URL_HOST) . '>';
        $date = date('r'); // RFC 2822 format
        $to_email = 'test@example.com'; // Placeholder for spam check
        
        // Generate plain text version from HTML
        $plain_text = $this->html_to_plain_text($html);
        
        // Build multipart email with both HTML and plain text
        $boundary = 'boundary_' . md5(time());
        
        // Build raw email format for SpamAssassin with proper headers
        $raw_email = "From: {$from_email}\r\n";
        $raw_email .= "To: {$to_email}\r\n";
        $raw_email .= "Subject: {$subject}\r\n";
        $raw_email .= "Date: {$date}\r\n";
        $raw_email .= "Message-ID: {$message_id}\r\n";
        $raw_email .= "MIME-Version: 1.0\r\n";
        $raw_email .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $raw_email .= "\r\n";
        
        // Plain text part
        $raw_email .= "--{$boundary}\r\n";
        $raw_email .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $raw_email .= "Content-Transfer-Encoding: quoted-printable\r\n";
        $raw_email .= "\r\n";
        $raw_email .= $plain_text . "\r\n";
        $raw_email .= "\r\n";
        
        // HTML part
        $raw_email .= "--{$boundary}\r\n";
        $raw_email .= "Content-Type: text/html; charset=UTF-8\r\n";
        $raw_email .= "Content-Transfer-Encoding: quoted-printable\r\n";
        $raw_email .= "\r\n";
        $raw_email .= $html . "\r\n";
        $raw_email .= "\r\n";
        
        // End boundary
        $raw_email .= "--{$boundary}--\r\n";
        
        $response = wp_remote_post('https://spamcheck.postmarkapp.com/filter', array(
            'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json'),
            'body' => json_encode(array('email' => $raw_email, 'options' => 'long')),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['score'])) {
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        $rules = array();
        if (!empty($body['rules'])) {
            foreach ($body['rules'] as $rule) {
                $rules[] = array(
                    'name' => $rule['name'] ?? 'Unknown',
                    'score' => floatval($rule['score'] ?? 0),
                    'description' => $rule['description'] ?? ''
                );
            }
        }
        
        return array(
            'success' => true,
            'score' => floatval($body['score']),
            'is_spam' => isset($body['success']) && $body['success'] === false,
            'rules' => $rules
        );
    }
    
    /**
     * Convert HTML email to plain text
     */
    private function html_to_plain_text($html) {
        // Remove style and script tags
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);
        
        // Convert common HTML elements
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);
        
        // Convert links to text with URL
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '$2 ($1)', $text);
        
        // Remove remaining HTML tags
        $text = strip_tags($text);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s+\n/', "\n\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Check accessibility
     */
    public function check_accessibility() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $html = $this->sanitize_email_html($_POST['html'] ?? '');
        
        $checks = array();
        
        // Check for alt text on images
        preg_match_all('/<img[^>]+>/i', $html, $images);
        $images_without_alt = 0;
        foreach ($images[0] as $img) {
            if (strpos($img, 'alt=') === false || preg_match('/alt=["\'][\s]*["\']/', $img)) {
                $images_without_alt++;
            }
        }
        
        $checks[] = array(
            'pass' => $images_without_alt === 0,
            'message' => $images_without_alt === 0 
                ? 'All images have alt text' 
                : $images_without_alt . ' image(s) missing alt text'
        );
        
        // Check for link text
        preg_match_all('/<a[^>]*>(.*?)<\/a>/is', $html, $links);
        $bad_links = 0;
        foreach ($links[1] as $link_text) {
            $text = strtolower(trim(strip_tags($link_text)));
            if (in_array($text, array('click here', 'here', 'read more', 'more'))) {
                $bad_links++;
            }
        }
        
        $checks[] = array(
            'pass' => $bad_links === 0,
            'message' => $bad_links === 0 
                ? 'Link text is descriptive' 
                : $bad_links . ' link(s) have non-descriptive text'
        );
        
        // Check color contrast (simplified - just check for very light text)
        $has_light_text = preg_match('/color:\s*#[fF]{3,6}|color:\s*white|color:\s*rgb\(255/i', $html);
        $has_light_bg = preg_match('/background[^:]*:\s*#[fF]{3,6}|background[^:]*:\s*white/i', $html);
        
        $checks[] = array(
            'pass' => !($has_light_text && $has_light_bg),
            'message' => !($has_light_text && $has_light_bg) 
                ? 'Color contrast appears adequate' 
                : 'Check color contrast - light text on light background detected'
        );
        
        // Check for table layout accessibility
        $has_tables = strpos($html, '<table') !== false;
        $has_role = strpos($html, 'role="presentation"') !== false;
        
        $checks[] = array(
            'pass' => !$has_tables || $has_role,
            'message' => !$has_tables 
                ? 'No layout tables detected' 
                : ($has_role ? 'Layout tables have role="presentation"' : 'Consider adding role="presentation" to layout tables')
        );
        
        // Check heading structure
        preg_match_all('/<h([1-6])/i', $html, $headings);
        $has_headings = !empty($headings[1]);
        
        $checks[] = array(
            'pass' => $has_headings,
            'message' => $has_headings 
                ? 'Document has heading structure' 
                : 'Consider adding headings for structure'
        );
        
        wp_send_json_success(array('checks' => $checks));
    }
    
    /**
     * Test sending service connection
     */
    public function test_connection() {
        check_ajax_referer('newsletter_test_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $service = sanitize_key($_POST['service'] ?? '');
        
        // Ensure sender class is loaded
        if (!class_exists('Azure_Newsletter_Sender')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-sender.php';
        }
        
        $sender = new Azure_Newsletter_Sender($service);
        $result = $sender->test_connection();
        
        if ($result['success']) {
            wp_send_json_success('Connection successful');
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    /**
     * Send a test email from the settings page
     */
    public function send_test_email_from_settings() {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        
        // Set custom error handler to catch all errors
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        try {
            check_ajax_referer('newsletter_send_test_email', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('Permission denied', 'azure-plugin'));
                return;
            }
            
            $email = sanitize_email($_POST['email'] ?? '');
            
            if (!is_email($email)) {
                wp_send_json_error(__('Invalid email address', 'azure-plugin'));
                return;
            }
            // Ensure required classes are loaded
            if (!class_exists('Azure_Settings')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-settings.php';
            }
            if (!class_exists('Azure_Newsletter_Sender')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-sender.php';
            }
            
            $settings = Azure_Settings::get_all_settings();
            $service = $settings['newsletter_sending_service'] ?? 'mailgun';
            $from_addresses = $settings['newsletter_from_addresses'] ?? array();
            $from_name = get_bloginfo('name');
            
            // Get the first from address - handle both string and array formats
            $from_email = '';
            if (!empty($from_addresses)) {
                if (is_array($from_addresses)) {
                    $first_address = reset($from_addresses);
                    $from_email = is_array($first_address) ? ($first_address['email'] ?? '') : $first_address;
                } else {
                    $from_email = $from_addresses;
                }
            }
            
            // Fallback to admin email
            if (empty($from_email)) {
                $from_email = get_option('admin_email');
            }
            
            // Check if service is configured
            if (empty($service)) {
                wp_send_json_error(__('No sending service configured. Please select a sending service and save settings.', 'azure-plugin'));
                return;
            }
            
            // Parse from address if it contains name (format: "email|name")
            if (is_string($from_email) && strpos($from_email, '|') !== false) {
                $parts = explode('|', $from_email);
                $from_email = $parts[0];
                $from_name = $parts[1] ?? $from_name;
            }
            
            if (empty($from_email) || !is_email($from_email)) {
                wp_send_json_error(__('No valid "From" email address configured. Please add a From Address in settings.', 'azure-plugin'));
                return;
            }
            
            // Create test email HTML
            $site_name = get_bloginfo('name');
            $site_url = home_url();
            $current_time = current_time('F j, Y g:i a');
            
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Email</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #2271b1 0%, #135e96 100%); border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;">Test Email</h1>
                            <p style="margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">Email Configuration Test</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px; color: #1d2327; font-size: 20px;">Success!</h2>
                            <p style="margin: 0 0 20px; color: #50575e; font-size: 16px; line-height: 1.6;">
                                Your newsletter email configuration is working correctly. 
                                This test email was sent using <strong>' . esc_html(ucfirst($service)) . '</strong>.
                            </p>
                            <table width="100%" style="background: #f8f9fa; border-radius: 6px; margin: 20px 0;">
                                <tr><td style="padding: 15px;">
                                    <p style="margin: 5px 0;"><strong>From:</strong> ' . esc_html($from_name) . ' &lt;' . esc_html($from_email) . '&gt;</p>
                                    <p style="margin: 5px 0;"><strong>To:</strong> ' . esc_html($email) . '</p>
                                    <p style="margin: 5px 0;"><strong>Service:</strong> ' . esc_html(ucfirst($service)) . '</p>
                                    <p style="margin: 5px 0;"><strong>Sent:</strong> ' . esc_html($current_time) . '</p>
                                </td></tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 40px; background: #f8f9fa; border-radius: 0 0 8px 8px; text-align: center;">
                            <p style="margin: 0; color: #646970; font-size: 13px;">
                                Sent from <a href="' . esc_url($site_url) . '" style="color: #2271b1;">' . esc_html($site_name) . '</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
            
            // Get reply-to address if configured
            $reply_to = $settings['newsletter_reply_to'] ?? '';
            
            $sender = new Azure_Newsletter_Sender($service);
            $result = $sender->send(array(
                'to' => $email,
                'from' => $from_email,
                'from_name' => $from_name,
                'reply_to' => $reply_to,
                'subject' => sprintf('[%s] Test Email - Configuration Verified', $site_name),
                'html' => $html,
                'text' => "Test Email from {$site_name}\n\nYour email configuration is working correctly!\n\nService: {$service}\nFrom: {$from_name} <{$from_email}>\nTo: {$email}\nSent at: {$current_time}"
            ));
            
            if ($result['success']) {
                wp_send_json_success(sprintf(
                    __('Test email sent successfully to %s', 'azure-plugin'),
                    $email
                ));
            } else {
                wp_send_json_error(sprintf(
                    __('Failed to send: %s', 'azure-plugin'),
                    $result['error'] ?? 'Unknown error'
                ));
            }
            
        } catch (Throwable $e) {
            restore_error_handler();
            wp_send_json_error(sprintf(
                __('Error: %s (Line %d in %s)', 'azure-plugin'),
                $e->getMessage(),
                $e->getLine(),
                basename($e->getFile())
            ));
        }
        
        restore_error_handler();
    }
    
    /**
     * Manually process the email queue
     */
    public function process_queue_now() {
        check_ajax_referer('azure_newsletter_process_queue', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'azure-plugin'));
        }
        
        // Ensure required classes are loaded
        if (!class_exists('Azure_Newsletter_Queue')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php';
        }
        
        try {
            $queue = new Azure_Newsletter_Queue();
            $result = $queue->process_batch();
            
            wp_send_json_success(array(
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'rate_limited' => $result['rate_limited'] ?? false,
                'message' => sprintf(
                    __('Processed %d emails: %d sent, %d failed', 'azure-plugin'),
                    $result['total'] ?? 0,
                    $result['sent'] ?? 0,
                    $result['failed'] ?? 0
                )
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Schedule the queue cron job
     */
    public function schedule_cron() {
        check_ajax_referer('azure_newsletter_schedule_cron', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'azure-plugin'));
        }
        
        // Clear existing schedule
        wp_clear_scheduled_hook('azure_newsletter_process_queue');
        
        // Schedule new cron
        if (!wp_next_scheduled('azure_newsletter_process_queue')) {
            wp_schedule_event(time(), 'every_minute', 'azure_newsletter_process_queue');
        }
        
        $next = wp_next_scheduled('azure_newsletter_process_queue');
        if ($next) {
            wp_send_json_success(array(
                'next_run' => human_time_diff(time(), $next),
                'next_timestamp' => $next
            ));
        } else {
            wp_send_json_error(__('Failed to schedule cron', 'azure-plugin'));
        }
    }
    
    /**
     * Get queue details for a specific newsletter (for grouped view)
     */
    public function get_queue_details() {
        check_ajax_referer('azure_newsletter_queue_details', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'azure-plugin'));
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        if (!$newsletter_id) {
            wp_send_json_error(__('Invalid newsletter ID', 'azure-plugin'));
        }
        
        global $wpdb;
        $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE newsletter_id = %d ORDER BY scheduled_at DESC",
            $newsletter_id
        ));
        
        // Build HTML table
        ob_start();
        ?>
        <table class="detail-table widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Status', 'azure-plugin'); ?></th>
                    <th><?php _e('Recipient', 'azure-plugin'); ?></th>
                    <th><?php _e('Scheduled', 'azure-plugin'); ?></th>
                    <th><?php _e('Sent', 'azure-plugin'); ?></th>
                    <th><?php _e('Attempts', 'azure-plugin'); ?></th>
                    <th><?php _e('Error', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($item->status); ?>">
                            <?php echo esc_html(ucfirst($item->status)); ?>
                        </span>
                    </td>
                    <td>
                        <strong><?php echo esc_html($item->email); ?></strong>
                        <?php if ($item->user_id): ?>
                        <br><small>User #<?php echo esc_html($item->user_id); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        if ($item->scheduled_at) {
                            echo esc_html(date_i18n('M j, g:i a', strtotime($item->scheduled_at)));
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($item->sent_at) {
                            echo esc_html(date_i18n('M j, g:i a', strtotime($item->sent_at)));
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($item->attempts); ?>/3</td>
                    <td>
                        <?php if ($item->error_message): ?>
                        <span class="error-text"><?php echo esc_html($item->error_message); ?></span>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * Get recipients count for a list
     */
    public function get_recipients_count() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $list_id = sanitize_text_field($_POST['list_id'] ?? 'all');
        $count = 0;
        $type = 'unknown';
        
        if ($list_id === 'all') {
            $count = count_users()['total_users'];
            $type = 'all_wordpress_users';
        } else {
            global $wpdb;
            $lists_table = $wpdb->prefix . 'azure_newsletter_lists';
            $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
            
            $list = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$lists_table} WHERE id = %d",
                intval($list_id)
            ));
            
            if (!$list) {
                wp_send_json_error(array('message' => 'List not found', 'list_id' => $list_id));
                return;
            }
            
            $type = $list->type;
            
            if ($list->type === 'role') {
                $criteria = json_decode($list->criteria, true);
                foreach ($criteria['roles'] ?? array() as $role) {
                    $count += count(get_users(array('role' => $role)));
                }
            } elseif ($list->type === 'custom') {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d AND unsubscribed_at IS NULL",
                    intval($list_id)
                ));
            } elseif ($list->type === 'all_users') {
                $count = count_users()['total_users'];
            }
        }
        
        wp_send_json_success(array(
            'count' => intval($count),
            'type' => $type,
            'list_id' => $list_id
        ));
    }
    
    /**
     * Pause newsletter sending
     */
    public function pause_newsletter() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        
        if (!$newsletter_id) {
            wp_send_json_error('Invalid newsletter ID');
        }
        
        // Ensure queue class is loaded
        if (!class_exists('Azure_Newsletter_Queue')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php';
        }
        
        $queue = new Azure_Newsletter_Queue();
        $queue->pause_newsletter($newsletter_id);
        
        wp_send_json_success('Newsletter paused');
    }
    
    /**
     * Resume newsletter sending
     */
    public function resume_newsletter() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        
        if (!$newsletter_id) {
            wp_send_json_error('Invalid newsletter ID');
        }
        
        // Ensure queue class is loaded
        if (!class_exists('Azure_Newsletter_Queue')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php';
        }
        
        $queue = new Azure_Newsletter_Queue();
        $queue->resume_newsletter($newsletter_id);
        
        wp_send_json_success('Newsletter resumed');
    }
    
    /**
     * Cancel newsletter sending
     */
    public function cancel_newsletter() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $newsletter_id = intval($_POST['newsletter_id'] ?? 0);
        
        if (!$newsletter_id) {
            wp_send_json_error('Invalid newsletter ID');
        }
        
        // Ensure queue class is loaded
        if (!class_exists('Azure_Newsletter_Queue')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php';
        }
        
        $queue = new Azure_Newsletter_Queue();
        $result = $queue->cancel_newsletter($newsletter_id);
        
        wp_send_json_success(array(
            'message' => 'Newsletter cancelled',
            'deleted' => $result['deleted']
        ));
    }
    
    /**
     * Create WordPress page for newsletter
     */
    private function create_newsletter_page($newsletter_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletters';
        
        $category = sanitize_text_field($_POST['page_category'] ?? 'newsletter');
        
        // Create or get the category/tag
        $term = get_term_by('slug', $category, 'category');
        if (!$term) {
            $term = get_term_by('slug', $category, 'post_tag');
        }
        if (!$term) {
            // Create as category
            $term_result = wp_insert_term($category, 'category');
            $term_id = is_array($term_result) ? $term_result['term_id'] : 0;
        } else {
            $term_id = $term->term_id;
        }
        
        // Create the page
        $page_data = array(
            'post_title' => $data['name'],
            'post_content' => $data['content_html'],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => get_current_user_id()
        );
        
        // Check if page already exists
        $existing_page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wp_page_id FROM {$table} WHERE id = %d",
            $newsletter_id
        ));
        
        if ($existing_page_id) {
            $page_data['ID'] = $existing_page_id;
            $page_id = wp_update_post($page_data);
        } else {
            $page_id = wp_insert_post($page_data);
        }
        
        if ($page_id && !is_wp_error($page_id)) {
            // Update newsletter with page ID
            $wpdb->update(
                $table,
                array(
                    'wp_page_id' => $page_id,
                    'page_category' => $category
                ),
                array('id' => $newsletter_id)
            );
            
            // Add category
            if ($term_id) {
                wp_set_post_categories($page_id, array($term_id), true);
            }
            
            Azure_Logger::info("Newsletter #{$newsletter_id} page created: {$page_id}");
        }
        
        return $page_id;
    }
    
    /**
     * Create/Update newsletter database tables
     */
    public function create_tables() {
        check_ajax_referer('azure_newsletter_create_tables', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'azure-plugin'));
        }
        
        try {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            // Newsletters table
            $table_newsletters = $wpdb->prefix . 'azure_newsletters';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_newsletters} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                subject varchar(255) NOT NULL,
                from_email varchar(255) NOT NULL,
                from_name varchar(255) NOT NULL,
                content_html longtext,
                content_json longtext,
                recipient_lists text,
                status varchar(20) DEFAULT 'draft',
                scheduled_at datetime DEFAULT NULL,
                sent_at datetime DEFAULT NULL,
                created_by bigint(20) UNSIGNED NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                archive_token varchar(64) DEFAULT NULL,
                wp_page_id bigint(20) UNSIGNED DEFAULT NULL,
                page_category varchar(100) DEFAULT 'newsletter',
                PRIMARY KEY (id),
                KEY status (status),
                KEY scheduled_at (scheduled_at),
                KEY archive_token (archive_token)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Queue table
            $table_queue = $wpdb->prefix . 'azure_newsletter_queue';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_queue} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id bigint(20) UNSIGNED NOT NULL,
                email varchar(255) NOT NULL,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                status varchar(20) DEFAULT 'pending',
                scheduled_at datetime NOT NULL,
                sent_at datetime DEFAULT NULL,
                error_message text,
                attempts int(11) DEFAULT 0,
                PRIMARY KEY (id),
                KEY newsletter_id (newsletter_id),
                KEY status (status),
                KEY scheduled_at (scheduled_at),
                KEY email (email)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Stats table (includes user_id for tracking)
            $table_stats = $wpdb->prefix . 'azure_newsletter_stats';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_stats} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                newsletter_id bigint(20) UNSIGNED NOT NULL,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                email varchar(255) NOT NULL,
                event_type varchar(50) NOT NULL,
                event_data text,
                link_url varchar(2048) DEFAULT NULL,
                link_text varchar(255) DEFAULT NULL,
                ip_address varchar(45) DEFAULT NULL,
                user_agent text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY newsletter_id (newsletter_id),
                KEY event_type (event_type),
                KEY email (email),
                KEY newsletter_event (newsletter_id, event_type)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Add missing columns for existing installations (schema migration)
            $columns_to_add = array(
                'user_id' => "ALTER TABLE {$table_stats} ADD COLUMN user_id bigint(20) UNSIGNED DEFAULT NULL AFTER newsletter_id",
                'link_url' => "ALTER TABLE {$table_stats} ADD COLUMN link_url varchar(2048) DEFAULT NULL AFTER event_data",
                'link_text' => "ALTER TABLE {$table_stats} ADD COLUMN link_text varchar(255) DEFAULT NULL AFTER link_url"
            );
            
            foreach ($columns_to_add as $column => $alter_sql) {
                $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_stats} LIKE '{$column}'");
                if (empty($column_exists)) {
                    $wpdb->query($alter_sql);
                    error_log("[Newsletter] Added missing column '{$column}' to stats table");
                }
            }
            
            // Lists table
            $table_lists = $wpdb->prefix . 'azure_newsletter_lists';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_lists} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                description text,
                type varchar(20) DEFAULT 'custom',
                criteria longtext,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY type (type)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // List members table
            $table_members = $wpdb->prefix . 'azure_newsletter_list_members';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_members} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                list_id bigint(20) UNSIGNED NOT NULL,
                email varchar(255) NOT NULL,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                subscribed_at datetime NOT NULL,
                unsubscribed_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY list_email (list_id, email),
                KEY list_id (list_id),
                KEY email (email)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Bounces table
            $table_bounces = $wpdb->prefix . 'azure_newsletter_bounces';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_bounces} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                newsletter_id bigint(20) UNSIGNED DEFAULT NULL,
                bounce_type varchar(20) DEFAULT 'hard',
                bounce_reason text,
                bounced_at datetime NOT NULL,
                blocked tinyint(1) DEFAULT 0,
                PRIMARY KEY (id),
                KEY email (email),
                KEY newsletter_id (newsletter_id),
                KEY blocked (blocked)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Templates table
            $table_templates = $wpdb->prefix . 'azure_newsletter_templates';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_templates} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                description text,
                category varchar(100) DEFAULT 'general',
                content_html longtext,
                content_json longtext,
                thumbnail_url varchar(500) DEFAULT NULL,
                is_default tinyint(1) DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY category (category),
                KEY is_default (is_default)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Sending config table
            $table_sending = $wpdb->prefix . 'azure_newsletter_sending_config';
            $sql = "CREATE TABLE IF NOT EXISTS {$table_sending} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                from_name varchar(255) NOT NULL,
                from_email varchar(255) NOT NULL,
                is_default tinyint(1) DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY is_default (is_default)
            ) {$charset_collate};";
            dbDelta($sql);
            
            // Log the result
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Newsletter tables created/updated via AJAX', array('module' => 'Newsletter'));
            }
            
            wp_send_json_success(__('Newsletter database tables created/updated successfully!', 'azure-plugin'));
            
        } catch (Exception $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Failed to create newsletter tables: ' . $e->getMessage(), array(
                    'module' => 'Newsletter',
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ));
            }
            wp_send_json_error(__('Failed to create tables: ', 'azure-plugin') . $e->getMessage());
        }
    }
    
    /**
     * Reset newsletter data (dangerous!)
     */
    public function reset_data() {
        check_ajax_referer('azure_newsletter_reset_data', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'azure-plugin'));
        }
        
        // Verify confirmation
        $confirm = sanitize_text_field($_POST['confirm'] ?? '');
        if ($confirm !== 'RESET') {
            wp_send_json_error(__('Please type RESET to confirm this action.', 'azure-plugin'));
        }
        
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'azure_newsletter_queue',
            $wpdb->prefix . 'azure_newsletter_stats',
            $wpdb->prefix . 'azure_newsletter_bounces',
        );
        
        foreach ($tables as $table) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::warning('Newsletter data reset by user', array(
                'module' => 'Newsletter',
                'user_id' => get_current_user_id()
            ));
        }
        
        wp_send_json_success(__('Newsletter data has been reset.', 'azure-plugin'));
    }
    
    /**
     * Clean HTML for preview display
     * Removes CSS text that may have leaked into body content from GrapesJS
     */
    private function clean_html_for_preview($html) {
        if (empty($html)) {
            return '';
        }
        
        $original = $html;
        $html = trim($html);
        
        // AGGRESSIVE FIX: If content starts with CSS comment or CSS-like text, strip it
        // Pattern: /* ... */ or property: value; or selector { }
        if (preg_match('/^\/\*|^[a-z\-]+\s*\{|^[a-z\-]+\s*:/i', $html)) {
            // Find where actual HTML content starts
            // Look for <!DOCTYPE, <html, <head, <body, <table, <div etc.
            if (preg_match('/<(!DOCTYPE|html|head|body|table|div|tr|td|p|center|section)/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                $html = substr($html, $matches[0][1]);
            }
        }
        
        // If content still starts with text (not a tag), find and strip to first tag
        if (!empty($html) && $html[0] !== '<') {
            // Find ANY HTML tag
            if (preg_match('/<[a-z!]/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
                $html = substr($html, $matches[0][1]);
            }
        }
        
        // If it's a full HTML document, clean the body content
        if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $matches)) {
            $body_content = $matches[1];
            $cleaned_body = $this->strip_css_text($body_content);
            
            // Rebuild the document with cleaned body
            $body_start = strpos($html, '<body');
            $body_tag_end = strpos($html, '>', $body_start) + 1;
            $body_close = strpos($html, '</body>');
            
            if ($body_start !== false && $body_close !== false) {
                $html = substr($html, 0, $body_tag_end) . $cleaned_body . substr($html, $body_close);
            }
        } else {
            // Not a full document, clean and wrap
            $html = $this->strip_css_text($html);
        }
        
        // Ensure we have a complete HTML document
        if (stripos($html, '<!DOCTYPE') === false && stripos($html, '<html') === false) {
            // Check if it starts with table/div/etc (partial content)
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background: #f4f4f4; }
        img { max-width: 100%; height: auto; }
        table { border-collapse: collapse; }
    </style>
</head>
<body>' . $html . '</body>
</html>';
        }
        
        return $html;
    }
    
    /**
     * Strip CSS text that appears before HTML content
     */
    private function strip_css_text($content) {
        $content = trim($content);
        
        if (empty($content)) {
            return '';
        }
        
        // If content starts with a proper HTML tag, it's clean
        if (preg_match('/^<(table|div|tr|td|p|img|a|span|h[1-6]|center|section|article|header|footer)/i', $content)) {
            return $content;
        }
        
        // If starts with < but something else (like <!-- or <style), handle it
        if ($content[0] === '<') {
            // Remove leading comments
            $content = preg_replace('/^<!--[\s\S]*?-->\s*/s', '', $content);
            // Remove style tags that might be rendering as text
            $content = preg_replace('/^<style[^>]*>[\s\S]*?<\/style>\s*/is', '', $content);
            return trim($content);
        }
        
        // Content starts with text - find first real HTML element
        // This handles CSS text like: "body { margin: 0; } table { ... } <table>..."
        $patterns = array(
            '/<table\b/i',
            '/<div\b/i', 
            '/<tr\b/i',
            '/<td\b/i',
            '/<p\b/i',
            '/<center\b/i',
            '/<img\b/i',
            '/<a\b/i',
            '/<h[1-6]\b/i',
            '/<section\b/i',
            '/<article\b/i',
        );
        
        $earliest_pos = strlen($content);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                if ($m[0][1] < $earliest_pos) {
                    $earliest_pos = $m[0][1];
                }
            }
        }
        
        if ($earliest_pos > 0 && $earliest_pos < strlen($content)) {
            return substr($content, $earliest_pos);
        }
        
        // Fallback - find any HTML tag
        if (preg_match('/<[a-z]/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            if ($m[0][1] > 0) {
                return substr($content, $m[0][1]);
            }
        }
        
        return $content;
    }
    
    /**
     * Sanitize email HTML while preserving styles and structure
     * 
     * Note: We don't use wp_kses for email HTML because:
     * 1. Only admins can create newsletters (trusted source)
     * 2. wp_kses strips CSS content inside <style> tags
     * 3. Email HTML requires full styling preservation
     * 
     * Instead, we do minimal sanitization focused on security.
     */
    private function sanitize_email_html($html) {
        if (empty($html)) {
            return '';
        }
        
        // Unslash the content (WordPress adds slashes)
        $html = wp_unslash($html);
        
        // Remove any PHP tags (security)
        $html = preg_replace('/<\?php.*?\?>/is', '', $html);
        $html = preg_replace('/<\?=.*?\?>/is', '', $html);
        $html = preg_replace('/<\?.*?\?>/is', '', $html);
        
        // Remove script tags (security) - but preserve style tags
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        
        // Remove event handlers (onclick, onerror, etc.)
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]*/i', '', $html);
        
        // Remove javascript: URLs
        $html = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $html);
        
        return $html;
    }
    
    /**
     * Sync statistics from queue and Mailgun
     */
    public function sync_stats() {
        check_ajax_referer('azure_newsletter_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
        
        $results = array(
            'sent_synced' => 0,
            'mailgun_events' => 0,
            'errors' => array()
        );
        
        // Step 1: Sync "sent" events from queue table
        // Get all sent queue items that don't have a corresponding stats entry
        $sent_items = $wpdb->get_results("
            SELECT q.newsletter_id, q.email, q.user_id, q.sent_at
            FROM {$queue_table} q
            LEFT JOIN {$stats_table} s ON q.newsletter_id = s.newsletter_id AND q.email = s.email AND s.event_type = 'sent'
            WHERE q.status = 'sent' AND s.id IS NULL
        ");
        
        foreach ($sent_items as $item) {
            $inserted = $wpdb->insert($stats_table, array(
                'newsletter_id' => $item->newsletter_id,
                'email' => $item->email,
                'user_id' => $item->user_id,
                'event_type' => 'sent',
                'created_at' => $item->sent_at ?: current_time('mysql')
            ));
            
            if ($inserted) {
                $results['sent_synced']++;
            }
        }
        
        // Step 2: Try to pull events from Mailgun API
        $settings = Azure_Settings::get_all_settings();
        $api_key = $settings['newsletter_mailgun_api_key'] ?? '';
        $domain = $settings['newsletter_mailgun_domain'] ?? '';
        $region = $settings['newsletter_mailgun_region'] ?? 'us';
        
        if (!empty($api_key) && !empty($domain)) {
            $mailgun_results = $this->fetch_mailgun_events($api_key, $domain, $region);
            $results['mailgun_events'] = $mailgun_results['count'] ?? 0;
            if (!empty($mailgun_results['error'])) {
                $results['errors'][] = $mailgun_results['error'];
            }
        } else {
            $results['errors'][] = 'Mailgun API not configured - cannot fetch opens/clicks';
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Fetch events from Mailgun API and store in stats table
     */
    private function fetch_mailgun_events($api_key, $domain, $region = 'us') {
        global $wpdb;
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        
        $api_base = $region === 'eu' 
            ? 'https://api.eu.mailgun.net/v3/' 
            : 'https://api.mailgun.net/v3/';
        
        // Fetch last 7 days of events
        $begin = date('r', strtotime('-7 days'));
        $end = date('r');
        
        $url = $api_base . $domain . '/events?' . http_build_query(array(
            'begin' => $begin,
            'end' => $end,
            'limit' => 300,
            'event' => 'delivered OR opened OR clicked OR failed OR complained'
        ));
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('count' => 0, 'error' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return array('count' => 0, 'error' => "Mailgun API error: HTTP {$code} - {$body}");
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $events = $data['items'] ?? array();
        $count = 0;
        
        // Event type mapping
        $event_map = array(
            'delivered' => 'delivered',
            'opened' => 'opened',
            'clicked' => 'clicked',
            'failed' => 'bounced',
            'complained' => 'complained'
        );
        
        foreach ($events as $event) {
            $event_type = $event['event'] ?? '';
            $mapped_type = $event_map[$event_type] ?? null;
            
            if (!$mapped_type) {
                continue;
            }
            
            $email = $event['recipient'] ?? '';
            $newsletter_id = $event['user-variables']['newsletter_id'] ?? null;
            $timestamp = $event['timestamp'] ?? time();
            $created_at = date('Y-m-d H:i:s', $timestamp);
            
            // Check if this exact event already exists (prevent duplicates)
            $message_id = $event['message']['headers']['message-id'] ?? '';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$stats_table} 
                 WHERE email = %s AND event_type = %s AND created_at = %s
                 LIMIT 1",
                $email, $mapped_type, $created_at
            ));
            
            if (!$existing && $email) {
                $insert_data = array(
                    'newsletter_id' => $newsletter_id,
                    'email' => $email,
                    'event_type' => $mapped_type,
                    'created_at' => $created_at,
                    'event_data' => json_encode($event)
                );
                
                // Add link URL for click events
                if ($mapped_type === 'clicked' && isset($event['url'])) {
                    $insert_data['link_url'] = $event['url'];
                }
                
                $inserted = $wpdb->insert($stats_table, $insert_data);
                if ($inserted) {
                    $count++;
                }
            }
        }
        
        return array('count' => $count);
    }
}

// Initialize
new Azure_Newsletter_Ajax();


