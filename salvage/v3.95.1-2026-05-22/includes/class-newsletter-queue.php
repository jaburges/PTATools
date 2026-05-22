<?php
/**
 * Newsletter Queue - Batch email sending with rate limiting
 * 
 * Handles queuing emails, processing batches via WP-Cron,
 * and respecting rate limits from sending services.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Queue {
    
    private $table;
    private $newsletters_table;
    private $stats_table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'azure_newsletter_queue';
        $this->newsletters_table = $wpdb->prefix . 'azure_newsletters';
        $this->stats_table = $wpdb->prefix . 'azure_newsletter_stats';
    }
    
    /**
     * Queue a newsletter for sending to a list
     * 
     * @param int $newsletter_id Newsletter ID
     * @param string $list_id List ID or 'all' for all WordPress users
     * @param string $scheduled_at Scheduled time (MySQL datetime)
     * @return array Result with count of queued emails
     */
    public function queue_newsletter($newsletter_id, $list_id = 'all', $scheduled_at = null) {
        global $wpdb;
        
        if (!$scheduled_at) {
            $scheduled_at = current_time('mysql');
        }
        
        // Normalize list_id - handle potential issues
        $list_id = trim(strval($list_id));
        
        error_log("[Newsletter Queue] Processing list_id='{$list_id}' for newsletter #{$newsletter_id}");
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info("Queue: Processing list_id='{$list_id}' (type: " . gettype($list_id) . ") for newsletter #{$newsletter_id}");
        }
        
        // Get list info for better error messages
        $list_name = $list_id;
        $list_type = 'unknown';
        $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
        $member_count_in_db = 0;
        
        if (strtolower($list_id) !== 'all') {
            $lists_table = $wpdb->prefix . 'azure_newsletter_lists';
            $list_info = $wpdb->get_row($wpdb->prepare(
                "SELECT name, type FROM {$lists_table} WHERE id = %d",
                intval($list_id)
            ));
            if ($list_info) {
                $list_name = $list_info->name;
                $list_type = $list_info->type ?? 'NULL';
                error_log("[Newsletter Queue] List found: name='{$list_name}', type='{$list_type}'");
                
                // Get direct member count from database for debugging
                $member_count_in_db = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d",
                    intval($list_id)
                ));
                
                // Count active (not unsubscribed) members
                $active_member_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d AND unsubscribed_at IS NULL",
                    intval($list_id)
                ));
                
                // Get sample of actual data to see what's stored
                $sample_members = $wpdb->get_results($wpdb->prepare(
                    "SELECT user_id, email, unsubscribed_at FROM {$members_table} WHERE list_id = %d LIMIT 5",
                    intval($list_id)
                ));
                $sample_data = array();
                foreach ($sample_members as $sm) {
                    $sample_data[] = "user_id={$sm->user_id}, email=" . ($sm->email ?: 'EMPTY') . ", unsub=" . ($sm->unsubscribed_at ?: 'NULL');
                }
                
                error_log("[Newsletter Queue] Direct member count in DB for list {$list_id}: {$member_count_in_db} total, {$active_member_count} active");
                error_log("[Newsletter Queue] Sample member data: " . implode(' | ', $sample_data));
            } else {
                error_log("[Newsletter Queue] ERROR: List ID {$list_id} not found in database!");
            }
        }
        
        // Get recipients based on list
        $recipients = $this->get_recipients($list_id);
        
        error_log("[Newsletter Queue] Found " . count($recipients) . " recipients for list '{$list_name}' (ID: {$list_id})");
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info("Queue: Found " . count($recipients) . " recipients for list '{$list_name}'");
        }
        
        if (empty($recipients)) {
            error_log("[Newsletter Queue] ERROR: No recipients found for list '{$list_name}' (ID: {$list_id})");
            
            // DIRECT BYPASS: Try to get recipients directly here for debugging
            $bypass_members = $wpdb->get_results($wpdb->prepare(
                "SELECT m.user_id, m.email, u.user_email, u.display_name 
                 FROM {$wpdb->prefix}azure_newsletter_list_members m
                 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                 WHERE m.list_id = %d AND m.unsubscribed_at IS NULL",
                intval($list_id)
            ));
            error_log("[Newsletter Queue] BYPASS query found: " . count($bypass_members) . " members");
            error_log("[Newsletter Queue] BYPASS SQL error: " . ($wpdb->last_error ?: 'none'));
            
            // If bypass works, use those recipients!
            if (!empty($bypass_members)) {
                error_log("[Newsletter Queue] BYPASS working! Using direct query results.");
                foreach ($bypass_members as $member) {
                    $email = !empty($member->email) ? $member->email : $member->user_email;
                    if (!empty($email)) {
                        $recipients[] = array(
                            'user_id' => $member->user_id ?? null,
                            'email' => $email,
                            'name' => $member->display_name ?? ''
                        );
                    }
                }
            }
            
            // If still empty after bypass, return error
            if (empty($recipients)) {
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::warning("Queue: No recipients found for list '{$list_name}'");
                }
                return array(
                    'success' => false,
                    'error' => "No recipients found for list '{$list_name}'",
                    'debug' => array(
                        'list_id' => $list_id,
                        'list_name' => $list_name,
                        'list_type' => $list_type,
                        'members_in_db' => $member_count_in_db,
                        'active_members' => $active_member_count ?? 0,
                        'sample_data' => $sample_data ?? array(),
                        'bypass_count' => count($bypass_members),
                        'bypass_error' => $wpdb->last_error ?: 'none'
                    )
                );
            }
        }
        
        // Track original count before filtering
        $original_count = count($recipients);
        
        // Filter out blocked/bounced emails
        $filter_result = $this->filter_blocked_recipients_with_stats($recipients);
        $recipients = $filter_result['recipients'];
        $blocked_count = $filter_result['blocked'];
        $bounced_count = $filter_result['bounced'];
        
        if (class_exists('Azure_Logger') && ($blocked_count > 0 || $bounced_count > 0)) {
            Azure_Logger::info("Queue: Filtered out {$blocked_count} blocked and {$bounced_count} bounced recipients");
        }
        
        // Queue each recipient
        $queued = 0;
        $skipped = 0;
        
        foreach ($recipients as $recipient) {
            $result = $wpdb->insert($this->table, array(
                'newsletter_id' => $newsletter_id,
                'user_id' => $recipient['user_id'],
                'email' => $recipient['email'],
                'status' => 'pending',
                'scheduled_at' => $scheduled_at
            ), array('%d', '%d', '%s', '%s', '%s'));
            
            if ($result) {
                $queued++;
            } else {
                // Duplicate or error - skip
                $skipped++;
            }
        }
        
        // Update newsletter status
        $wpdb->update(
            $this->newsletters_table,
            array('status' => 'scheduled', 'scheduled_at' => $scheduled_at),
            array('id' => $newsletter_id)
        );
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info("Newsletter #{$newsletter_id} queued: {$queued} emails, {$skipped} skipped, {$blocked_count} blocked, {$bounced_count} bounced");
        }
        
        return array(
            'success' => true,
            'queued' => $queued,
            'skipped' => $skipped,
            'original_count' => $original_count,
            'blocked' => $blocked_count,
            'bounced' => $bounced_count,
            'filtered_total' => $blocked_count + $bounced_count
        );
    }
    
    /**
     * Get recipients for a list
     */
    private function get_recipients($list_id) {
        global $wpdb;
        
        $recipients = array();
        
        // Use case-insensitive comparison for 'all'
        if (strtolower($list_id) === 'all') {
            // All WordPress users with email addresses
            $users = get_users(array(
                'fields' => array('ID', 'user_email', 'display_name'),
                'number' => -1
            ));
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info("Queue get_recipients: Fetching all users, found " . count($users) . " total users");
            }
            
            foreach ($users as $user) {
                if (!empty($user->user_email)) {
                    $recipients[] = array(
                        'user_id' => $user->ID,
                        'email' => $user->user_email,
                        'name' => $user->display_name
                    );
                }
            }
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info("Queue get_recipients: After filtering for emails, have " . count($recipients) . " recipients");
            }
        } else {
            // Custom list
            error_log("[Newsletter Queue] Looking up custom list with id={$list_id}");
            
            $lists_table = $wpdb->prefix . 'azure_newsletter_lists';
            $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
            
            $list = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$lists_table} WHERE id = %d",
                $list_id
            ));
            
            error_log("[Newsletter Queue] List found: " . ($list ? "yes, type='" . ($list->type ?? 'NULL') . "', name={$list->name}" : "NO"));
            
            if ($list) {
                // Normalize list type - treat NULL/empty as 'custom'
                $list_type = !empty($list->type) ? strtolower(trim($list->type)) : 'custom';
                error_log("[Newsletter Queue] Normalized list type: '{$list_type}'");
                
                switch ($list_type) {
                    case 'role':
                        error_log("[Newsletter Queue] Processing role-based list");
                        $criteria = json_decode($list->criteria, true);
                        error_log("[Newsletter Queue] Criteria: " . json_encode($criteria));
                        if (!empty($criteria['roles'])) {
                            foreach ($criteria['roles'] as $role) {
                                $users = get_users(array(
                                    'role' => $role,
                                    'fields' => array('ID', 'user_email', 'display_name')
                                ));
                                error_log("[Newsletter Queue] Role '{$role}' has " . count($users) . " users");
                                
                                foreach ($users as $user) {
                                    if (!empty($user->user_email)) {
                                        $recipients[] = array(
                                            'user_id' => $user->ID,
                                            'email' => $user->user_email,
                                            'name' => $user->display_name
                                        );
                                    }
                                }
                            }
                        }
                        break;
                        
                    case 'custom':
                        error_log("[Newsletter Queue] Processing custom list ID {$list_id}, querying members table: {$members_table}");
                        
                        // First check if the members table exists
                        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$members_table}'");
                        error_log("[Newsletter Queue] Members table exists: " . ($table_exists ? 'yes' : 'NO!'));
                        
                        // Count all members first (ignore unsubscribed check for debugging)
                        $total_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d",
                            intval($list_id)
                        ));
                        error_log("[Newsletter Queue] Total members in list (including unsubscribed): " . $total_count);
                        
                        // Use JOIN with users table to get email reliably (same as get_list_members)
                        $members = $wpdb->get_results($wpdb->prepare(
                            "SELECT m.user_id, m.email, m.first_name, m.last_name, 
                                    u.user_email, u.display_name 
                             FROM {$members_table} m
                             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                             WHERE m.list_id = %d AND m.unsubscribed_at IS NULL",
                            intval($list_id)
                        ));
                        
                        // Log any SQL errors
                        if ($wpdb->last_error) {
                            error_log("[Newsletter Queue] SQL Error: " . $wpdb->last_error);
                        }
                        
                        error_log("[Newsletter Queue] Found " . count($members) . " active members in custom list");
                        
                        foreach ($members as $member) {
                            // Prefer m.email, fallback to u.user_email from WordPress
                            $email = !empty($member->email) ? $member->email : $member->user_email;
                            
                            if (!empty($email)) {
                                // Prefer display_name from WP user, fallback to first_name + last_name
                                $name = !empty($member->display_name) ? $member->display_name : trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? ''));
                                
                                error_log("[Newsletter Queue] Member: " . $email . " (" . $name . ")");
                                $recipients[] = array(
                                    'user_id' => $member->user_id ?? null,
                                    'email' => $email,
                                    'name' => $name
                                );
                            } else {
                                error_log("[Newsletter Queue] WARNING: Member has no email (user_id: " . ($member->user_id ?? 'null') . "), skipping");
                            }
                        }
                        break;
                    
                    case 'manual':
                    default:
                        // Treat 'manual' and any other unknown type as custom list with members
                        error_log("[Newsletter Queue] Processing as manual/custom list (type was: {$list_type})");
                        
                        // Query members table with JOIN to WordPress users
                        $members = $wpdb->get_results($wpdb->prepare(
                            "SELECT m.user_id, m.email, m.first_name, m.last_name, 
                                    u.user_email, u.display_name 
                             FROM {$members_table} m
                             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                             WHERE m.list_id = %d AND m.unsubscribed_at IS NULL",
                            intval($list_id)
                        ));
                        
                        if ($wpdb->last_error) {
                            error_log("[Newsletter Queue] SQL Error in default case: " . $wpdb->last_error);
                        }
                        
                        error_log("[Newsletter Queue] Found " . count($members) . " members in default case");
                        
                        foreach ($members as $member) {
                            $email = !empty($member->email) ? $member->email : $member->user_email;
                            
                            if (!empty($email)) {
                                $name = !empty($member->display_name) ? $member->display_name : trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? ''));
                                error_log("[Newsletter Queue] Default case member: " . $email);
                                $recipients[] = array(
                                    'user_id' => $member->user_id ?? null,
                                    'email' => $email,
                                    'name' => $name
                                );
                            }
                        }
                        break;
                }
            } else {
                error_log("[Newsletter Queue] ERROR: List not found in database!");
            }
        }
        
        // Deduplicate by email
        $seen = array();
        $unique = array();
        foreach ($recipients as $r) {
            $email_lower = strtolower($r['email']);
            if (!isset($seen[$email_lower])) {
                $seen[$email_lower] = true;
                $unique[] = $r;
            }
        }
        
        return $unique;
    }
    
    /**
     * Filter out blocked/bounced recipients
     */
    /**
     * Filter blocked recipients (legacy method for compatibility)
     */
    private function filter_blocked_recipients($recipients) {
        $result = $this->filter_blocked_recipients_with_stats($recipients);
        return $result['recipients'];
    }
    
    /**
     * Filter blocked/bounced recipients and return statistics
     */
    private function filter_blocked_recipients_with_stats($recipients) {
        global $wpdb;
        
        $bounces_table = $wpdb->prefix . 'azure_newsletter_bounces';
        
        // Check if bounces table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$bounces_table}'") === $bounces_table;
        
        if (!$table_exists) {
            return array(
                'recipients' => $recipients,
                'blocked' => 0,
                'bounced' => 0
            );
        }
        
        // Get blocked emails (manually blocked)
        $blocked_emails = $wpdb->get_col("SELECT LOWER(email) FROM {$bounces_table} WHERE is_blocked = 1");
        
        // Get hard bounced emails (not manually blocked but bounced)
        $bounced_emails = $wpdb->get_col("SELECT LOWER(email) FROM {$bounces_table} WHERE bounce_type = 'hard' AND is_blocked = 0");
        
        $blocked_count = 0;
        $bounced_count = 0;
        
        // Filter out blocked and bounced
        $filtered = array_filter($recipients, function($r) use ($blocked_emails, $bounced_emails, &$blocked_count, &$bounced_count) {
            $email_lower = strtolower($r['email']);
            
            if (in_array($email_lower, $blocked_emails)) {
                $blocked_count++;
                return false;
            }
            
            if (in_array($email_lower, $bounced_emails)) {
                $bounced_count++;
                return false;
            }
            
            return true;
        });
        
        return array(
            'recipients' => array_values($filtered), // Re-index array
            'blocked' => $blocked_count,
            'bounced' => $bounced_count
        );
    }
    
    /**
     * Process a batch of queued emails
     * 
     * @return array Result with sent/failed counts
     */
    public function process_batch() {
        global $wpdb;
        
        $settings = Azure_Settings::get_all_settings();
        $batch_size = $settings['newsletter_batch_size'] ?? 100;
        $rate_limit = $settings['newsletter_rate_limit_per_hour'] ?? 1000;
        
        // Check rate limit
        $sent_this_hour = $this->get_sent_this_hour();
        if ($sent_this_hour >= $rate_limit) {
            Azure_Logger::debug_module('Newsletter', 'Rate limit reached, skipping batch');
            return array('sent' => 0, 'failed' => 0, 'total' => 0, 'rate_limited' => true);
        }
        
        // Adjust batch size based on remaining rate limit
        $remaining = $rate_limit - $sent_this_hour;
        $batch_size = min($batch_size, $remaining);
        
        // Get pending emails that are due
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, n.subject, n.from_name, n.from_email, n.content_html
             FROM {$this->table} q
             JOIN {$this->newsletters_table} n ON q.newsletter_id = n.id
             WHERE q.status = 'pending' 
             AND q.scheduled_at <= %s
             AND q.attempts < 3
             ORDER BY q.scheduled_at ASC
             LIMIT %d",
            current_time('mysql'),
            $batch_size
        ));
        
        if (empty($pending)) {
            return array('sent' => 0, 'failed' => 0, 'total' => 0);
        }
        
        // Ensure sender class is loaded
        if (!class_exists('Azure_Newsletter_Sender')) {
            $sender_file = AZURE_PLUGIN_PATH . 'includes/class-newsletter-sender.php';
            if (file_exists($sender_file)) {
                require_once $sender_file;
            } else {
                error_log("[Newsletter Queue] FATAL: Sender class file not found at: " . $sender_file);
                return array('sent' => 0, 'failed' => count($pending), 'total' => count($pending), 'error' => 'Sender class not found');
            }
        }
        
        $sender = new Azure_Newsletter_Sender();
        $sent = 0;
        $failed = 0;
        
        foreach ($pending as $item) {
            // Get recipient name
            $name = '';
            if ($item->user_id) {
                $user = get_user_by('id', $item->user_id);
                if ($user) {
                    $name = $user->display_name;
                }
            }
            
            // Personalize content
            $html = $this->personalize_content($item->content_html, array(
                'email' => $item->email,
                'first_name' => $this->get_first_name($name, $item->email),
                'user_id' => $item->user_id
            ));
            
            // Inline CSS for email client compatibility
            $html = $this->inline_css($html);
            
            // Send email
            $result = $sender->send(array(
                'to' => $item->email,
                'to_name' => $name,
                'from' => $item->from_email,
                'from_name' => $item->from_name,
                'subject' => $this->personalize_subject($item->subject, array(
                    'first_name' => $this->get_first_name($name, $item->email)
                )),
                'html' => $html,
                'newsletter_id' => $item->newsletter_id
            ));
            
            if ($result['success']) {
                // Mark as sent (increment attempts to show 1 attempt was made)
                $wpdb->update(
                    $this->table,
                    array(
                        'status' => 'sent',
                        'sent_at' => current_time('mysql'),
                        'attempts' => $item->attempts + 1
                    ),
                    array('id' => $item->id)
                );
                
                // Record sent stat
                error_log('[Newsletter Queue] Recording sent stat for newsletter ' . $item->newsletter_id . ', email: ' . $item->email);
                $this->record_stat($item->newsletter_id, $item->email, $item->user_id, 'sent');
                
                $sent++;
            } else {
                // Increment attempts
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table} 
                     SET attempts = attempts + 1, error_message = %s
                     WHERE id = %d",
                    $result['error'],
                    $item->id
                ));
                
                // If max attempts reached, mark as failed
                if ($item->attempts + 1 >= 3) {
                    $wpdb->update(
                        $this->table,
                        array('status' => 'failed'),
                        array('id' => $item->id)
                    );
                }
                
                $failed++;
            }
            
            // Small delay between sends to avoid overwhelming the service
            usleep(100000); // 100ms
        }
        
        // Check if newsletter is complete
        $this->check_newsletter_completion();
        
        return array(
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($pending)
        );
    }
    
    /**
     * Get number of emails sent in the current hour
     */
    private function get_sent_this_hour() {
        global $wpdb;
        
        $hour_start = date('Y-m-d H:00:00');
        
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} 
             WHERE status = 'sent' AND sent_at >= %s",
            $hour_start
        ));
    }
    
    /**
     * Personalize email content with merge tags
     */
    private function personalize_content($html, $data) {
        // Replace both regular and URL-encoded versions of placeholders
        $first_name = $data['first_name'] ?? '';
        $email = $data['email'] ?? '';
        $user_id = $data['user_id'] ?? '';
        
        // Regular placeholders
        $html = str_replace('{{first_name}}', $first_name, $html);
        $html = str_replace('{{email}}', $email, $html);
        $html = str_replace('{{user_id}}', $user_id, $html);
        
        // URL-encoded placeholders (in case they appear in href attributes)
        $html = str_replace('%7B%7Bfirst_name%7D%7D', $first_name, $html);
        $html = str_replace('%7B%7Bemail%7D%7D', $email, $html);
        $html = str_replace('%7B%7Buser_id%7D%7D', $user_id, $html);
        
        return $html;
    }
    
    /**
     * Inline CSS styles for email client compatibility
     * Most email clients strip <style> tags, so we need to inline CSS
     */
    private function inline_css($html) {
        if (empty($html)) {
            return $html;
        }
        
        // Extract CSS from <style> tags
        $css_rules = array();
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            foreach ($matches[1] as $css) {
                // Parse CSS rules
                if (preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $rules, PREG_SET_ORDER)) {
                    foreach ($rules as $rule) {
                        $selectors = trim($rule[1]);
                        $properties = trim($rule[2]);
                        
                        // Skip @media, @keyframes, etc.
                        if (strpos($selectors, '@') === 0) {
                            continue;
                        }
                        
                        // Handle multiple selectors
                        $selector_list = array_map('trim', explode(',', $selectors));
                        foreach ($selector_list as $selector) {
                            if (!empty($selector)) {
                                $css_rules[$selector] = isset($css_rules[$selector]) 
                                    ? $css_rules[$selector] . ' ' . $properties 
                                    : $properties;
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($css_rules)) {
            return $html;
        }
        
        // Load HTML into DOMDocument
        $dom = new DOMDocument();
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Add UTF-8 meta to ensure proper encoding
        $html_with_meta = '<?xml encoding="UTF-8">' . $html;
        $dom->loadHTML($html_with_meta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Apply CSS rules to matching elements
        foreach ($css_rules as $selector => $properties) {
            // Convert CSS selector to XPath (basic conversion)
            $xpath_query = $this->css_to_xpath($selector);
            
            if (empty($xpath_query)) {
                continue;
            }
            
            try {
                $elements = $xpath->query($xpath_query);
                if ($elements === false) {
                    continue;
                }
                
                foreach ($elements as $element) {
                    if ($element instanceof DOMElement) {
                        $existing_style = $element->getAttribute('style');
                        $new_style = $existing_style 
                            ? rtrim($existing_style, '; ') . '; ' . $properties 
                            : $properties;
                        $element->setAttribute('style', $new_style);
                    }
                }
            } catch (Exception $e) {
                // Skip invalid selectors
                continue;
            }
        }
        
        // Get the HTML back
        $result = $dom->saveHTML();
        
        // Remove the XML declaration we added
        $result = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $result);
        
        // Keep the <style> tags for clients that support them (Gmail app, Apple Mail)
        // The inline styles provide fallback for others
        
        return $result;
    }
    
    /**
     * Convert basic CSS selector to XPath
     */
    private function css_to_xpath($selector) {
        $selector = trim($selector);
        
        if (empty($selector)) {
            return '';
        }
        
        // Handle ID selector: #id
        if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
            return "//*[@id='{$m[1]}']";
        }
        
        // Handle class selector: .class
        if (preg_match('/^\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }
        
        // Handle element selector: div
        if (preg_match('/^([\w]+)$/', $selector, $m)) {
            return "//{$m[1]}";
        }
        
        // Handle element.class: div.class
        if (preg_match('/^([\w]+)\.([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }
        
        // Handle element#id: div#id
        if (preg_match('/^([\w]+)#([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[@id='{$m[2]}']";
        }
        
        // Handle descendant: div p (simplified)
        if (preg_match('/^([\w]+)\s+([\w]+)$/', $selector, $m)) {
            return "//{$m[1]}//{$m[2]}";
        }
        
        // Handle universal with class: *.class or just element
        if (preg_match('/^\*?\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }
        
        // For complex selectors, try basic element match
        if (preg_match('/^[\w]+/', $selector, $m)) {
            return "//{$m[0]}";
        }
        
        return '';
    }
    
    /**
     * Personalize email subject
     */
    private function personalize_subject($subject, $data) {
        $replacements = array(
            '{{first_name}}' => $data['first_name'] ?? ''
        );
        
        return str_replace(array_keys($replacements), array_values($replacements), $subject);
    }
    
    /**
     * Extract first name from display name or email
     */
    private function get_first_name($display_name, $email) {
        if (!empty($display_name)) {
            $parts = explode(' ', $display_name);
            return $parts[0];
        }
        
        // Fall back to email prefix
        $parts = explode('@', $email);
        return ucfirst($parts[0]);
    }
    
    /**
     * Record a statistics event
     */
    private function record_stat($newsletter_id, $email, $user_id, $event_type, $event_data = null) {
        global $wpdb;
        
        error_log('[Newsletter Queue] record_stat called: type=' . $event_type . ', newsletter=' . $newsletter_id . ', email=' . $email);
        error_log('[Newsletter Queue] Stats table: ' . $this->stats_table);
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->stats_table}'");
        if (!$table_exists) {
            error_log('[Newsletter Queue] ERROR: Stats table does not exist! Creating it now...');
            // Try to create the table
            $this->create_stats_table();
        }
        
        $result = $wpdb->insert($this->stats_table, array(
            'newsletter_id' => $newsletter_id,
            'email' => $email,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'event_data' => $event_data,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => current_time('mysql')
        ));
        
        if ($result === false) {
            error_log('[Newsletter Queue] FAILED to insert stat: ' . $wpdb->last_error);
        } else {
            error_log('[Newsletter Queue] Stat recorded successfully, insert ID: ' . $wpdb->insert_id);
        }
    }
    
    /**
     * Create stats table if it doesn't exist
     */
    private function create_stats_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->stats_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NULL,
            email varchar(255) NOT NULL,
            event_type varchar(50) NOT NULL,
            event_data text NULL,
            link_url varchar(2048) NULL,
            link_text varchar(255) NULL,
            link_position int NULL,
            ip_address varchar(45) NULL,
            user_agent varchar(512) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_newsletter_event (newsletter_id, event_type),
            KEY idx_email (email),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('[Newsletter Queue] Stats table creation attempted');
    }
    
    /**
     * Check if a newsletter has completed sending
     */
    private function check_newsletter_completion() {
        global $wpdb;
        
        // Find newsletters that are "sending" but have no more pending emails
        $newsletters = $wpdb->get_col(
            "SELECT DISTINCT newsletter_id 
             FROM {$this->table} 
             WHERE status = 'pending'"
        );
        
        // Get all newsletters marked as "sending" or "scheduled"
        $active = $wpdb->get_col(
            "SELECT id FROM {$this->newsletters_table} 
             WHERE status IN ('sending', 'scheduled')"
        );
        
        foreach ($active as $id) {
            if (!in_array($id, $newsletters)) {
                // No more pending - mark as sent
                $wpdb->update(
                    $this->newsletters_table,
                    array(
                        'status' => 'sent',
                        'sent_at' => current_time('mysql')
                    ),
                    array('id' => $id)
                );
                
                // Get stats for logging
                $stats = $this->get_newsletter_stats($id);
                Azure_Logger::info(sprintf(
                    'Newsletter #%d completed: %d/%d sent successfully',
                    $id,
                    $stats['sent'],
                    $stats['total']
                ));
            }
        }
    }
    
    /**
     * Get stats for a newsletter
     */
    public function get_newsletter_stats($newsletter_id) {
        global $wpdb;
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE newsletter_id = %d",
            $newsletter_id
        ));
        
        $sent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE newsletter_id = %d AND status = 'sent'",
            $newsletter_id
        ));
        
        $failed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE newsletter_id = %d AND status = 'failed'",
            $newsletter_id
        ));
        
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE newsletter_id = %d AND status = 'pending'",
            $newsletter_id
        ));
        
        return array(
            'total' => (int)$total,
            'sent' => (int)$sent,
            'failed' => (int)$failed,
            'pending' => (int)$pending
        );
    }
    
    /**
     * Pause a newsletter
     */
    public function pause_newsletter($newsletter_id) {
        global $wpdb;
        
        $wpdb->update(
            $this->newsletters_table,
            array('status' => 'paused'),
            array('id' => $newsletter_id)
        );
        
        Azure_Logger::info("Newsletter #{$newsletter_id} paused");
        
        return true;
    }
    
    /**
     * Resume a paused newsletter
     */
    public function resume_newsletter($newsletter_id) {
        global $wpdb;
        
        $wpdb->update(
            $this->newsletters_table,
            array('status' => 'sending'),
            array('id' => $newsletter_id)
        );
        
        Azure_Logger::info("Newsletter #{$newsletter_id} resumed");
        
        return true;
    }
    
    /**
     * Cancel a newsletter and remove pending queue items
     */
    public function cancel_newsletter($newsletter_id) {
        global $wpdb;
        
        // Delete pending queue items
        $deleted = $wpdb->delete(
            $this->table,
            array('newsletter_id' => $newsletter_id, 'status' => 'pending')
        );
        
        // Update newsletter status
        $wpdb->update(
            $this->newsletters_table,
            array('status' => 'draft'),
            array('id' => $newsletter_id)
        );
        
        Azure_Logger::info("Newsletter #{$newsletter_id} cancelled, {$deleted} queue items removed");
        
        return array('deleted' => $deleted);
    }
}




