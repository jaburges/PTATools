<?php
/**
 * Tickets Module - Ticket Generator
 * 
 * Generates QR codes and handles ticket email delivery
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Generator {
    
    /**
     * Generate QR code image for a ticket
     * 
     * @param string $data The data to encode in QR
     * @param int $size Size of the QR code image
     * @return string Base64 encoded PNG image
     */
    public function generate_qr_code($data, $size = 200) {
        // Use Google Charts API as fallback (simple, no dependencies)
        // In production, consider using a local library like phpqrcode or endroid/qr-code
        $qr_url = 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size . 
                  '&cht=qr&chl=' . urlencode($data) . '&choe=UTF-8';
        
        $response = wp_remote_get($qr_url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            // Fallback: generate simple placeholder
            return $this->generate_placeholder_qr($size);
        }
        
        $image_data = wp_remote_retrieve_body($response);
        return 'data:image/png;base64,' . base64_encode($image_data);
    }
    
    /**
     * Generate placeholder QR when API fails
     */
    private function generate_placeholder_qr($size) {
        // Create a simple placeholder image
        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        
        imagefill($img, 0, 0, $white);
        
        // Draw border
        imagerectangle($img, 0, 0, $size - 1, $size - 1, $black);
        
        // Draw "QR" text
        $font_size = 3;
        $text = 'QR';
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = ($size - $text_width) / 2;
        $y = ($size - $text_height) / 2;
        imagestring($img, $font_size, $x, $y, $text, $black);
        
        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        
        return 'data:image/png;base64,' . base64_encode($data);
    }
    
    /**
     * Generate tickets for an order
     * 
     * @param int $order_id WooCommerce order ID
     * @return array Array of generated ticket IDs
     */
    public function generate_tickets_for_order($order_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }
        
        $ticket_ids = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || $product->get_type() !== 'ticket') {
                continue;
            }
            
            $seat_data = $item->get_meta('_ticket_seats');
            if (!is_array($seat_data)) {
                $seat_data = array();
            }
            
            $product_id = $item->get_product_id();
            $event_id = get_post_meta($product_id, '_ticket_event_id', true);
            $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);
            
            foreach ($seat_data as $index => $seat) {
                $ticket_code = $this->generate_ticket_code();
                
                $qr_data = json_encode(array(
                    'v' => 1, // Version
                    'c' => $ticket_code,
                    'o' => $order_id,
                    'p' => $product_id,
                    'cs' => substr(hash('sha256', $ticket_code . $order_id . AUTH_SALT), 0, 8)
                ));
                
                $wpdb->insert($tickets_table, array(
                    'order_id' => $order_id,
                    'product_id' => $product_id,
                    'event_id' => $event_id ?: null,
                    'venue_id' => $venue_id ?: null,
                    'section_id' => $seat['section_id'] ?? null,
                    'row_letter' => $seat['row'] ?? null,
                    'seat_number' => is_numeric($seat['seat'] ?? null) ? intval($seat['seat']) : null,
                    'attendee_name' => sanitize_text_field($seat['attendee_name'] ?? ''),
                    'attendee_email' => $order->get_billing_email(),
                    'ticket_code' => $ticket_code,
                    'qr_data' => $qr_data,
                    'status' => 'active',
                    'created_at' => current_time('mysql')
                ));
                
                $ticket_id = $wpdb->insert_id;
                
                // Update QR data with ticket ID
                $qr_data = json_encode(array(
                    'v' => 1,
                    't' => $ticket_id,
                    'c' => $ticket_code,
                    'cs' => substr(hash('sha256', $ticket_code . $ticket_id . AUTH_SALT), 0, 8)
                ));
                
                $wpdb->update($tickets_table, array('qr_data' => $qr_data), array('id' => $ticket_id));
                
                $ticket_ids[] = $ticket_id;
            }
        }
        
        return $ticket_ids;
    }
    
    /**
     * Generate unique ticket code
     */
    public function generate_ticket_code() {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Get tickets for an order
     * 
     * @param int $order_id
     * @return array
     */
    public function get_order_tickets($order_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ));
    }
    
    /**
     * Get a single ticket by ID
     * 
     * @param int $ticket_id
     * @return object|null
     */
    public function get_ticket($ticket_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE id = %d",
            $ticket_id
        ));
    }
    
    /**
     * Get ticket by code
     * 
     * @param string $code
     * @return object|null
     */
    public function get_ticket_by_code($code) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
            strtoupper($code)
        ));
    }
    
    /**
     * Send ticket email to customer
     * 
     * @param int $order_id
     * @return bool
     */
    public function send_ticket_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $tickets = $this->get_order_tickets($order_id);
        if (empty($tickets)) {
            return false;
        }
        
        // Group tickets by product
        $tickets_by_product = array();
        foreach ($tickets as $ticket) {
            $tickets_by_product[$ticket->product_id][] = $ticket;
        }
        
        // Build email content
        $email_content = $this->build_ticket_email($order, $tickets_by_product);
        
        // Get email subject
        $subject_template = Azure_Settings::get_setting('tickets_email_subject', 'Your tickets for {{event_name}}');
        
        // Get first product name for subject
        $first_product = wc_get_product($tickets[0]->product_id);
        $event_name = $first_product ? $first_product->get_name() : 'Your Event';
        
        $subject = str_replace(
            array('{{event_name}}', '{{order_number}}'),
            array($event_name, $order->get_order_number()),
            $subject_template
        );
        
        // Send email
        $to = $order->get_billing_email();
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Add PDF attachment if enabled
        $attachments = array();
        if (Azure_Settings::get_setting('tickets_include_pdf', true)) {
            $pdf_path = $this->generate_tickets_pdf($order_id, $tickets);
            if ($pdf_path) {
                $attachments[] = $pdf_path;
            }
        }
        
        $sent = wp_mail($to, $subject, $email_content, $headers, $attachments);
        
        // Cleanup temp PDF
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    unlink($attachment);
                }
            }
        }
        
        return $sent;
    }
    
    /**
     * Build ticket email HTML
     */
    private function build_ticket_email($order, $tickets_by_product) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Your Tickets</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding: 30px 0; background: #1d2327; color: #fff; border-radius: 8px 8px 0 0; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 30px; background: #fff; border: 1px solid #ddd; }
                .ticket-card { background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; margin: 20px 0; overflow: hidden; }
                .ticket-header { background: #2271b1; color: #fff; padding: 15px 20px; }
                .ticket-header h3 { margin: 0; font-size: 18px; }
                .ticket-body { padding: 20px; display: flex; gap: 20px; }
                .ticket-qr { text-align: center; }
                .ticket-qr img { width: 120px; height: 120px; }
                .ticket-details { flex: 1; }
                .ticket-details p { margin: 5px 0; }
                .ticket-code { font-family: monospace; font-size: 18px; font-weight: bold; letter-spacing: 2px; }
                .event-info { background: #f0f6fc; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .btn { display: inline-block; padding: 12px 25px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
                .btn-wallet { background: #000; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <h1>🎫 Your Tickets Are Ready!</h1>
                </div>
                
                <div class="content">
                    <p>Hi <?php echo esc_html($order->get_billing_first_name()); ?>,</p>
                    <p>Thank you for your purchase! Here are your tickets:</p>
                    
                    <?php foreach ($tickets_by_product as $product_id => $product_tickets): 
                        $product = wc_get_product($product_id);
                        $event_date = get_post_meta($product_id, '_ticket_event_date', true);
                    ?>
                    
                    <div class="event-info">
                        <h2 style="margin-top: 0;"><?php echo esc_html($product ? $product->get_name() : 'Event'); ?></h2>
                        <?php if ($event_date): ?>
                        <p><strong>📅 Date:</strong> <?php echo date('l, F j, Y \a\t g:i A', strtotime($event_date)); ?></p>
                        <?php endif; ?>
                        <p><strong>🎟️ Tickets:</strong> <?php echo count($product_tickets); ?></p>
                    </div>
                    
                    <?php foreach ($product_tickets as $ticket): 
                        $qr_image = $this->generate_qr_code($ticket->qr_data, 150);
                        $seat_info = $ticket->row_letter ? 
                            'Row ' . $ticket->row_letter . ', Seat ' . $ticket->seat_number : 
                            'General Admission';
                    ?>
                    <div class="ticket-card">
                        <div class="ticket-header">
                            <h3><?php echo esc_html($seat_info); ?></h3>
                        </div>
                        <div class="ticket-body">
                            <div class="ticket-qr">
                                <img src="<?php echo $qr_image; ?>" alt="QR Code">
                                <p class="ticket-code"><?php echo esc_html($ticket->ticket_code); ?></p>
                            </div>
                            <div class="ticket-details">
                                <?php if ($ticket->attendee_name): ?>
                                <p><strong>Attendee:</strong> <?php echo esc_html($ticket->attendee_name); ?></p>
                                <?php endif; ?>
                                <p><strong>Order:</strong> #<?php echo $order->get_order_number(); ?></p>
                                <p style="font-size: 12px; color: #666;">Present this QR code at the entrance for check-in.</p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php endforeach; ?>
                    
                    <div style="text-align: center; margin-top: 30px;">
                        <a href="<?php echo $order->get_view_order_url(); ?>" class="btn">View Order Details</a>
                    </div>
                    
                    <p style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 13px;">
                        <strong>Important:</strong> Please arrive at least 15 minutes before the event. Have your QR code ready on your phone or printed for faster check-in.
                    </p>
                </div>
                
                <div class="footer">
                    <p>Questions? Contact us at <?php echo get_option('admin_email'); ?></p>
                    <p>&copy; <?php echo date('Y'); ?> <?php echo get_bloginfo('name'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate PDF of tickets
     * 
     * @param int $order_id
     * @param array $tickets
     * @return string|false Path to generated PDF or false on failure
     */
    public function generate_tickets_pdf($order_id, $tickets) {
        // Check if TCPDF or similar is available
        // For now, return false as PDF generation requires additional library
        // Could use mPDF, TCPDF, or Dompdf
        
        // Placeholder for PDF generation
        // In production, implement with a PDF library
        
        return false;
    }
    
    /**
     * Cancel a ticket
     * 
     * @param int $ticket_id
     * @return bool
     */
    public function cancel_ticket($ticket_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $result = $wpdb->update(
            $tickets_table,
            array('status' => 'cancelled'),
            array('id' => $ticket_id)
        );
        
        return $result !== false;
    }
    
    /**
     * Resend ticket email
     * 
     * @param int $ticket_id
     * @return bool
     */
    public function resend_ticket_email($ticket_id) {
        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket) {
            return false;
        }
        
        return $this->send_ticket_email($ticket->order_id);
    }
}

