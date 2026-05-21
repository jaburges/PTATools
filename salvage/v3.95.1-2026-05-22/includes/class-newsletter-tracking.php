<?php
/**
 * Newsletter Tracking - Handle webhooks and track opens/clicks
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Tracking {
    
    private $stats_table;
    private $bounces_table;
    
    public function __construct() {
        global $wpdb;
        $this->stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $this->bounces_table = $wpdb->prefix . 'azure_newsletter_bounces';
    }
    
    /**
     * Record an open event
     */
    public function record_open($token) {
        $data = $this->decode_tracking_token($token);
        
        if (!$data) {
            return false;
        }
        
        global $wpdb;
        
        // Check for existing open from this email (dedupe)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->stats_table} 
             WHERE newsletter_id = %d AND email = %s AND event_type = 'opened'
             LIMIT 1",
            $data['newsletter_id'],
            $data['email']
        ));
        
        // Record open (even if duplicate, for tracking multiple opens)
        $wpdb->insert($this->stats_table, array(
            'newsletter_id' => $data['newsletter_id'],
            'email' => $data['email'],
            'user_id' => $this->get_user_id_by_email($data['email']),
            'event_type' => 'opened',
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => current_time('mysql')
        ));
        
        return true;
    }
    
    /**
     * Record a click event
     */
    public function record_click($token, $url) {
        $data = $this->decode_tracking_token($token);
        
        if (!$data) {
            return false;
        }
        
        global $wpdb;
        
        // Extract link text from URL if possible
        $link_text = $this->extract_link_text($url);
        
        $wpdb->insert($this->stats_table, array(
            'newsletter_id' => $data['newsletter_id'],
            'email' => $data['email'],
            'user_id' => $this->get_user_id_by_email($data['email']),
            'event_type' => 'clicked',
            'link_url' => $url,
            'link_text' => $link_text,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => current_time('mysql')
        ));
        
        return true;
    }
    
    /**
     * Process Mailgun webhook
     */
    public function process_mailgun_webhook($request) {
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Log incoming webhook for debugging
        Azure_Logger::debug_module('Newsletter', 'Mailgun webhook received', array(
            'raw_size' => strlen($body),
            'decoded' => !empty($data)
        ));
        
        // Verify signature (skip in development/testing if needed)
        if (!$this->verify_mailgun_signature($request)) {
            Azure_Logger::warning('Mailgun webhook: Invalid signature', $data);
            return new WP_REST_Response(array('error' => 'Invalid signature'), 403);
        }
        
        // Mailgun sends data in different formats depending on webhook version
        // New format: { "signature": {...}, "event-data": {...} }
        // Legacy format: flat structure
        $event_data = $data['event-data'] ?? $data;
        
        // Get event type
        $event_type = $event_data['event'] ?? '';
        
        // Get recipient email - Mailgun uses different fields based on event
        $email = $event_data['recipient'] ?? 
                 $event_data['message']['headers']['to'] ?? 
                 $event_data['envelope']['targets'] ?? 
                 '';
        
        // Get newsletter_id from custom variables (we send this when sending emails)
        $newsletter_id = null;
        if (isset($event_data['user-variables']['newsletter_id'])) {
            $newsletter_id = intval($event_data['user-variables']['newsletter_id']);
        } elseif (isset($data['newsletter_id'])) {
            $newsletter_id = intval($data['newsletter_id']);
        }
        
        // Get link URL for click events
        if ($event_type === 'clicked' && isset($event_data['url'])) {
            $event_data['url'] = $event_data['url'];
        }
        
        Azure_Logger::debug_module('Newsletter', "Processing Mailgun event: {$event_type} for {$email}");
        
        $this->process_event($event_type, $email, $newsletter_id, $event_data);
        
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Process SendGrid webhook
     */
    public function process_sendgrid_webhook($request) {
        $body = $request->get_body();
        $events = json_decode($body, true);
        
        if (!is_array($events)) {
            return new WP_REST_Response(array('error' => 'Invalid data'), 400);
        }
        
        foreach ($events as $event) {
            $event_type = $event['event'] ?? '';
            $email = $event['email'] ?? '';
            $newsletter_id = $event['newsletter_id'] ?? null;
            
            $this->process_event($event_type, $email, $newsletter_id, $event);
        }
        
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Process Amazon SES webhook (SNS notification)
     */
    public function process_ses_webhook($request) {
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Handle SNS subscription confirmation
        if (isset($data['Type']) && $data['Type'] === 'SubscriptionConfirmation') {
            wp_remote_get($data['SubscribeURL']);
            return new WP_REST_Response(array('status' => 'subscribed'), 200);
        }
        
        // Handle notifications
        if (isset($data['Type']) && $data['Type'] === 'Notification') {
            $message = json_decode($data['Message'], true);
            
            $notification_type = $message['notificationType'] ?? '';
            
            switch ($notification_type) {
                case 'Bounce':
                    $this->process_ses_bounce($message);
                    break;
                case 'Complaint':
                    $this->process_ses_complaint($message);
                    break;
                case 'Delivery':
                    $this->process_ses_delivery($message);
                    break;
            }
        }
        
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Process a generic event
     */
    private function process_event($event_type, $email, $newsletter_id, $raw_data) {
        global $wpdb;
        
        // Map service-specific event types to our standard types
        $event_map = array(
            // Mailgun events
            'accepted' => 'sent',           // Email accepted by Mailgun for delivery
            'delivered' => 'delivered',     // Successfully delivered to recipient's mail server
            'opened' => 'opened',           // Recipient opened the email
            'clicked' => 'clicked',         // Recipient clicked a link
            'complained' => 'complained',   // Recipient marked as spam
            'unsubscribed' => 'unsubscribed',
            'temporary_fail' => 'bounced',  // Soft bounce - temporary failure
            'permanent_fail' => 'bounced',  // Hard bounce - permanent failure
            'failed' => 'bounced',          // Legacy: generic failure
            'bounced' => 'bounced',         // Legacy: bounce
            
            // SendGrid events
            'processed' => 'sent',
            'dropped' => 'bounced',
            'deferred' => 'bounced',
            'bounce' => 'bounced',
            'open' => 'opened',
            'click' => 'clicked',
            'spamreport' => 'complained',
            'unsubscribe' => 'unsubscribed',
            'group_unsubscribe' => 'unsubscribed',
            'group_resubscribe' => 'sent'
        );
        
        $normalized_type = $event_map[$event_type] ?? null;
        
        // Log unknown event types for debugging
        if (!$normalized_type) {
            Azure_Logger::debug_module('Newsletter', "Unknown webhook event type: {$event_type}", $raw_data);
            return;
        }
        
        if (!$email) {
            Azure_Logger::debug_module('Newsletter', "Webhook event missing email: {$event_type}");
            return;
        }
        
        // Record the event
        $result = $wpdb->insert($this->stats_table, array(
            'newsletter_id' => $newsletter_id,
            'email' => $email,
            'user_id' => $this->get_user_id_by_email($email),
            'event_type' => $normalized_type,
            'event_data' => json_encode($raw_data),
            'link_url' => $raw_data['url'] ?? null,
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            Azure_Logger::debug_module('Newsletter', "Recorded {$normalized_type} event for {$email}");
        }
        
        // Handle bounces - determine if hard or soft
        if ($normalized_type === 'bounced') {
            $bounce_type = 'soft';
            
            // Mailgun permanent_fail = hard bounce
            if ($event_type === 'permanent_fail') {
                $bounce_type = 'hard';
            }
            // SendGrid bounce severity
            elseif (isset($raw_data['type']) && $raw_data['type'] === 'bounce') {
                $bounce_type = 'hard';
            }
            // Check severity field
            elseif (isset($raw_data['severity']) && $raw_data['severity'] === 'permanent') {
                $bounce_type = 'hard';
            }
            
            $reason = $raw_data['delivery-status']['message'] ?? 
                      $raw_data['delivery-status']['description'] ?? 
                      $raw_data['reason'] ?? 
                      null;
            
            $this->record_bounce($email, $bounce_type, $reason);
        }
        
        // Handle complaints - always block
        if ($normalized_type === 'complained') {
            $this->record_bounce($email, 'complaint');
        }
    }
    
    /**
     * Process SES bounce notification
     */
    private function process_ses_bounce($message) {
        $bounce = $message['bounce'] ?? array();
        $bounce_type = strtolower($bounce['bounceType'] ?? 'permanent');
        
        foreach ($bounce['bouncedRecipients'] ?? array() as $recipient) {
            $email = $recipient['emailAddress'] ?? '';
            if ($email) {
                $type = $bounce_type === 'permanent' ? 'hard' : 'soft';
                $this->record_bounce($email, $type, $recipient['diagnosticCode'] ?? null);
            }
        }
    }
    
    /**
     * Process SES complaint notification
     */
    private function process_ses_complaint($message) {
        $complaint = $message['complaint'] ?? array();
        
        foreach ($complaint['complainedRecipients'] ?? array() as $recipient) {
            $email = $recipient['emailAddress'] ?? '';
            if ($email) {
                $this->record_bounce($email, 'complaint');
            }
        }
    }
    
    /**
     * Process SES delivery notification
     */
    private function process_ses_delivery($message) {
        $delivery = $message['delivery'] ?? array();
        
        foreach ($delivery['recipients'] ?? array() as $email) {
            global $wpdb;
            
            $wpdb->insert($this->stats_table, array(
                'email' => $email,
                'user_id' => $this->get_user_id_by_email($email),
                'event_type' => 'delivered',
                'created_at' => current_time('mysql')
            ));
        }
    }
    
    /**
     * Record a bounce
     */
    public function record_bounce($email, $type = 'hard', $reason = null) {
        global $wpdb;
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->bounces_table} WHERE email = %s",
            $email
        ));
        
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $this->bounces_table,
                array(
                    'bounce_type' => $type,
                    'bounce_count' => $existing->bounce_count + 1,
                    'last_bounce_at' => current_time('mysql'),
                    'bounce_reason' => $reason,
                    'is_blocked' => ($existing->bounce_count + 1 >= 3 || $type === 'hard' || $type === 'complaint') ? 1 : 0
                ),
                array('email' => $email)
            );
        } else {
            // Insert new record
            $wpdb->insert($this->bounces_table, array(
                'email' => $email,
                'bounce_type' => $type,
                'bounce_count' => 1,
                'bounce_reason' => $reason,
                'last_bounce_at' => current_time('mysql'),
                'is_blocked' => ($type === 'hard' || $type === 'complaint') ? 1 : 0
            ));
        }
        
        // Block immediately for hard bounces and complaints
        if ($type === 'hard' || $type === 'complaint') {
            Azure_Logger::info("Email blocked: {$email} (reason: {$type})");
        }
    }
    
    /**
     * Verify Mailgun webhook signature
     * 
     * Mailgun webhooks are signed using the HTTP webhook signing key,
     * which is different from the API key. If not configured separately,
     * we'll use the API key as a fallback.
     */
    private function verify_mailgun_signature($request) {
        $settings = Azure_Settings::get_all_settings();
        
        // Mailgun recommends using a separate webhook signing key
        // but defaults to API key if not configured
        $signing_key = $settings['newsletter_mailgun_webhook_key'] ?? 
                       $settings['newsletter_mailgun_api_key'] ?? '';
        
        if (empty($signing_key)) {
            // Skip verification if no key configured (development mode)
            Azure_Logger::debug_module('Newsletter', 'Mailgun webhook: No signing key, skipping verification');
            return true;
        }
        
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Signature data location depends on webhook format
        $signature = $data['signature'] ?? array();
        $timestamp = $signature['timestamp'] ?? '';
        $token = $signature['token'] ?? '';
        $sig = $signature['signature'] ?? '';
        
        // Legacy format uses flat structure
        if (empty($timestamp) && isset($data['timestamp'])) {
            $timestamp = $data['timestamp'];
            $token = $data['token'] ?? '';
            $sig = $data['signature'] ?? '';
        }
        
        if (empty($timestamp) || empty($token) || empty($sig)) {
            Azure_Logger::debug_module('Newsletter', 'Mailgun webhook: Missing signature components', array(
                'has_timestamp' => !empty($timestamp),
                'has_token' => !empty($token),
                'has_signature' => !empty($sig)
            ));
            return false;
        }
        
        // Check timestamp is not too old (prevent replay attacks, allow 5 minutes)
        if (abs(time() - intval($timestamp)) > 300) {
            Azure_Logger::debug_module('Newsletter', 'Mailgun webhook: Timestamp too old');
            return false;
        }
        
        $expected = hash_hmac('sha256', $timestamp . $token, $signing_key);
        
        return hash_equals($expected, $sig);
    }
    
    /**
     * Decode a tracking token
     */
    private function decode_tracking_token($token) {
        // Tokens are base64-encoded hashes
        // We need to look up the newsletter_id and email from other sources
        // since we can't decode a one-way hash
        
        // For now, assume the token contains the data directly
        // In production, you'd store tokens in a database
        
        // This is a simplified implementation
        // A full implementation would store tokens with metadata
        
        return array(
            'newsletter_id' => null,
            'email' => null
        );
    }
    
    /**
     * Determine bounce type from event data
     */
    private function determine_bounce_type($data) {
        // Check various indicators for hard vs soft bounce
        $severity = $data['severity'] ?? ($data['bounce']['bounceType'] ?? 'permanent');
        $code = $data['delivery-status']['code'] ?? ($data['status'] ?? '');
        
        // 5xx codes are typically hard bounces
        if (strpos($code, '5') === 0 || $severity === 'permanent') {
            return 'hard';
        }
        
        return 'soft';
    }
    
    /**
     * Get user ID by email
     */
    private function get_user_id_by_email($email) {
        $user = get_user_by('email', $email);
        return $user ? $user->ID : null;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract link text (simplified - would need original HTML context)
     */
    private function extract_link_text($url) {
        // Parse URL to get path for display
        $parsed = parse_url($url);
        return $parsed['path'] ?? $url;
    }
}




