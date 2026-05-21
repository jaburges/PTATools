<?php
/**
 * Newsletter Bounce Handler
 * 
 * Handles bounce processing via:
 * - Webhooks from sending services
 * - IMAP mailbox monitoring (Office 365)
 * - Weekly email list validation
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Bounce {
    
    private $bounces_table;
    private $stats_table;
    private $list_members_table;
    
    public function __construct() {
        global $wpdb;
        $this->bounces_table = $wpdb->prefix . 'azure_newsletter_bounces';
        $this->stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $this->list_members_table = $wpdb->prefix . 'azure_newsletter_list_members';
    }
    
    /**
     * Process bounces from IMAP mailbox (Office 365)
     */
    public function process_imap_bounces() {
        $settings = Azure_Settings::get_all_settings();
        
        if (empty($settings['newsletter_bounce_enabled'])) {
            return;
        }
        
        $bounce_email = $settings['newsletter_bounce_mailbox'] ?? '';
        
        if (empty($bounce_email)) {
            Azure_Logger::debug_module('Newsletter', 'IMAP bounce processing skipped: no mailbox configured');
            return;
        }
        
        // Check if Azure Graph API is available
        if (!class_exists('Azure_GraphAPI')) {
            Azure_Logger::warning('Newsletter bounce: Azure Graph API not available');
            return;
        }
        
        try {
            $graph = new Azure_GraphAPI();
            
            // Get unread messages from bounce mailbox
            $messages = $graph->get_mailbox_messages($bounce_email, array(
                'filter' => "isRead eq false",
                'top' => 50,
                'select' => 'id,subject,from,body,receivedDateTime'
            ));
            
            if (empty($messages)) {
                return;
            }
            
            $processed = 0;
            
            foreach ($messages as $message) {
                $bounce_info = $this->parse_bounce_message($message);
                
                if ($bounce_info) {
                    $this->record_bounce(
                        $bounce_info['email'],
                        $bounce_info['type'],
                        $bounce_info['reason']
                    );
                    $processed++;
                }
                
                // Mark message as read
                $graph->mark_message_read($bounce_email, $message['id']);
            }
            
            if ($processed > 0) {
                Azure_Logger::info("Newsletter bounce: Processed {$processed} bounce messages from IMAP");
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Newsletter IMAP bounce error: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse a bounce email message to extract bounce info
     */
    private function parse_bounce_message($message) {
        $subject = $message['subject'] ?? '';
        $body = $message['body']['content'] ?? '';
        $from = $message['from']['emailAddress']['address'] ?? '';
        
        // Common bounce indicators
        $hard_bounce_patterns = array(
            '/user unknown/i',
            '/no such user/i',
            '/mailbox not found/i',
            '/invalid recipient/i',
            '/address rejected/i',
            '/does not exist/i',
            '/user not found/i',
            '/550 5\.1\.1/i', // Standard SMTP code for user unknown
            '/550 5\.1\.2/i', // Bad destination mailbox address
            '/550 5\.4\.1/i', // Recipient address rejected
        );
        
        $soft_bounce_patterns = array(
            '/mailbox full/i',
            '/over quota/i',
            '/temporarily rejected/i',
            '/try again later/i',
            '/service unavailable/i',
            '/452 4\.2\.2/i', // Mailbox full
            '/421 4\.7\./i',  // Temporary failure
        );
        
        // Check if this is a bounce message
        $is_bounce = (
            stripos($subject, 'delivery') !== false ||
            stripos($subject, 'undeliverable') !== false ||
            stripos($subject, 'failed') !== false ||
            stripos($subject, 'bounce') !== false ||
            stripos($subject, 'returned') !== false ||
            stripos($from, 'mailer-daemon') !== false ||
            stripos($from, 'postmaster') !== false
        );
        
        if (!$is_bounce) {
            return null;
        }
        
        // Extract bounced email address from message
        $bounced_email = $this->extract_bounced_email($body);
        
        if (!$bounced_email) {
            return null;
        }
        
        // Determine bounce type
        $type = 'soft'; // Default to soft
        
        foreach ($hard_bounce_patterns as $pattern) {
            if (preg_match($pattern, $subject . ' ' . $body)) {
                $type = 'hard';
                break;
            }
        }
        
        // Extract reason
        $reason = $this->extract_bounce_reason($body);
        
        return array(
            'email' => $bounced_email,
            'type' => $type,
            'reason' => $reason
        );
    }
    
    /**
     * Extract the bounced email address from message body
     */
    private function extract_bounced_email($body) {
        // Try various patterns to extract the bounced email
        $patterns = array(
            '/Original-Recipient:[\s]*rfc822;[\s]*([^\s<>]+@[^\s<>]+)/i',
            '/Final-Recipient:[\s]*rfc822;[\s]*([^\s<>]+@[^\s<>]+)/i',
            '/failed to deliver to[\s]*([^\s<>]+@[^\s<>]+)/i',
            '/could not be delivered to[\s]*([^\s<>]+@[^\s<>]+)/i',
            '/<([^>]+@[^>]+)>[\s]*was not delivered/i',
            '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})[\s]*\([^)]*undeliverable\)/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $email = trim($matches[1]);
                if (is_email($email)) {
                    return strtolower($email);
                }
            }
        }
        
        // Fallback: find any email in the body that's not from common services
        preg_match_all('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $body, $matches);
        
        $exclude = array('mailer-daemon', 'postmaster', 'noreply', 'no-reply');
        
        foreach ($matches[1] as $email) {
            $email = strtolower($email);
            $exclude_match = false;
            foreach ($exclude as $ex) {
                if (strpos($email, $ex) !== false) {
                    $exclude_match = true;
                    break;
                }
            }
            if (!$exclude_match && is_email($email)) {
                return $email;
            }
        }
        
        return null;
    }
    
    /**
     * Extract bounce reason from message body
     */
    private function extract_bounce_reason($body) {
        // Try to find diagnostic information
        $patterns = array(
            '/Diagnostic-Code:[\s]*(.+?)(?:\r?\n[^\s]|$)/is',
            '/Remote-MTA:[\s]*(.+?)(?:\r?\n[^\s]|$)/is',
            '/Status:[\s]*(.+?)(?:\r?\n|$)/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return substr(trim($matches[1]), 0, 500);
            }
        }
        
        return null;
    }
    
    /**
     * Record a bounce
     */
    public function record_bounce($email, $type = 'hard', $reason = null) {
        global $wpdb;
        
        $email = strtolower(trim($email));
        
        if (!is_email($email)) {
            return false;
        }
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->bounces_table} WHERE email = %s",
            $email
        ));
        
        if ($existing) {
            $new_count = $existing->bounce_count + 1;
            $should_block = ($new_count >= 3 || $type === 'hard');
            
            $wpdb->update(
                $this->bounces_table,
                array(
                    'bounce_type' => $type,
                    'bounce_count' => $new_count,
                    'last_bounce_at' => current_time('mysql'),
                    'bounce_reason' => $reason ?: $existing->bounce_reason,
                    'is_blocked' => $should_block ? 1 : 0
                ),
                array('email' => $email)
            );
            
            if ($should_block && !$existing->is_blocked) {
                Azure_Logger::info("Newsletter: Email blocked due to bounces: {$email}");
            }
        } else {
            $should_block = ($type === 'hard');
            
            $wpdb->insert($this->bounces_table, array(
                'email' => $email,
                'bounce_type' => $type,
                'bounce_count' => 1,
                'bounce_reason' => $reason,
                'last_bounce_at' => current_time('mysql'),
                'is_blocked' => $should_block ? 1 : 0
            ));
            
            if ($should_block) {
                Azure_Logger::info("Newsletter: Email blocked due to hard bounce: {$email}");
            }
        }
        
        return true;
    }
    
    /**
     * Weekly email list validation
     * 
     * Checks for:
     * - Invalid email formats
     * - Known disposable email domains
     * - Emails that have repeatedly soft-bounced
     */
    public function validate_email_list() {
        global $wpdb;
        
        Azure_Logger::info('Newsletter: Starting weekly email validation');
        
        $validated = 0;
        $blocked = 0;
        
        // 1. Block emails with multiple soft bounces
        $soft_bounce_threshold = 3;
        $blocked_soft = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->bounces_table} 
             SET is_blocked = 1 
             WHERE bounce_type = 'soft' 
             AND bounce_count >= %d 
             AND is_blocked = 0",
            $soft_bounce_threshold
        ));
        $blocked += $blocked_soft;
        
        // 2. Check list members for invalid emails
        $members = $wpdb->get_results(
            "SELECT DISTINCT email FROM {$this->list_members_table} WHERE unsubscribed_at IS NULL"
        );
        
        $disposable_domains = $this->get_disposable_domains();
        
        foreach ($members as $member) {
            $email = strtolower($member->email);
            $should_block = false;
            $reason = null;
            
            // Check email format
            if (!is_email($email)) {
                $should_block = true;
                $reason = 'Invalid email format';
            }
            
            // Check for disposable domain
            if (!$should_block) {
                $domain = substr($email, strpos($email, '@') + 1);
                if (in_array($domain, $disposable_domains)) {
                    $should_block = true;
                    $reason = 'Disposable email domain';
                }
            }
            
            if ($should_block) {
                $this->record_bounce($email, 'hard', $reason);
                $blocked++;
            }
            
            $validated++;
        }
        
        // 3. Also validate WordPress users
        $users = get_users(array('fields' => array('user_email')));
        
        foreach ($users as $user) {
            $email = strtolower($user->user_email);
            
            if (!is_email($email)) {
                $this->record_bounce($email, 'hard', 'Invalid WordPress user email');
                $blocked++;
            }
            
            $validated++;
        }
        
        Azure_Logger::info("Newsletter: Weekly validation complete - {$validated} emails checked, {$blocked} blocked");
        
        return array(
            'validated' => $validated,
            'blocked' => $blocked
        );
    }
    
    /**
     * Get list of known disposable email domains
     */
    private function get_disposable_domains() {
        // Common disposable/temporary email domains
        return array(
            'mailinator.com',
            'guerrillamail.com',
            'guerrillamail.net',
            'guerrillamail.org',
            'tempmail.com',
            'temp-mail.org',
            '10minutemail.com',
            'throwaway.email',
            'fakeinbox.com',
            'trashmail.com',
            'trashmail.net',
            'getnada.com',
            'maildrop.cc',
            'discard.email',
            'sharklasers.com',
            'getairmail.com',
            'yopmail.com',
            'yopmail.fr',
            'mailnesia.com',
            'spamgourmet.com',
            'mintemail.com',
            'tempinbox.com',
            'tempail.com',
            'tempr.email',
            'anonymbox.com'
        );
    }
    
    /**
     * Check if an email is blocked
     */
    public function is_blocked($email) {
        global $wpdb;
        
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT is_blocked FROM {$this->bounces_table} WHERE email = %s",
            strtolower($email)
        ));
        
        return $blocked == 1;
    }
    
    /**
     * Unblock an email
     */
    public function unblock($email) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->bounces_table,
            array(
                'is_blocked' => 0,
                'bounce_count' => 0
            ),
            array('email' => strtolower($email))
        );
        
        if ($result) {
            Azure_Logger::info("Newsletter: Email unblocked: {$email}");
        }
        
        return $result !== false;
    }
    
    /**
     * Get bounce statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array(
            'total_bounces' => 0,
            'hard_bounces' => 0,
            'soft_bounces' => 0,
            'complaints' => 0,
            'blocked_emails' => 0
        );
        
        $counts = $wpdb->get_results(
            "SELECT 
                bounce_type,
                COUNT(*) as count,
                SUM(is_blocked) as blocked
             FROM {$this->bounces_table}
             GROUP BY bounce_type"
        );
        
        foreach ($counts as $row) {
            $stats['total_bounces'] += $row->count;
            
            switch ($row->bounce_type) {
                case 'hard':
                    $stats['hard_bounces'] = $row->count;
                    break;
                case 'soft':
                    $stats['soft_bounces'] = $row->count;
                    break;
                case 'complaint':
                    $stats['complaints'] = $row->count;
                    break;
            }
            
            $stats['blocked_emails'] += $row->blocked;
        }
        
        return $stats;
    }
    
    /**
     * Get list of blocked emails
     */
    public function get_blocked_emails($limit = 100, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bounces_table} 
             WHERE is_blocked = 1 
             ORDER BY last_bounce_at DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }
    
    /**
     * Clear old soft bounces (older than 90 days)
     */
    public function cleanup_old_bounces() {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$this->bounces_table} 
             WHERE bounce_type = 'soft' 
             AND is_blocked = 0 
             AND last_bounce_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        
        if ($deleted > 0) {
            Azure_Logger::info("Newsletter: Cleaned up {$deleted} old soft bounces");
        }
        
        return $deleted;
    }
}




