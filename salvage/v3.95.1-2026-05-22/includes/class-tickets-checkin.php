<?php
/**
 * Tickets Module - Check-in Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Checkin {
    
    /**
     * Validate a ticket
     * 
     * @param string $ticket_code
     * @return array Validation result
     */
    public function validate_ticket($ticket_code) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
            strtoupper(trim($ticket_code))
        ));
        
        if (!$ticket) {
            return array(
                'valid' => false,
                'status' => 'not_found',
                'message' => __('Ticket not found', 'azure-plugin')
            );
        }
        
        // Get product details
        $product = wc_get_product($ticket->product_id);
        $event_name = $product ? $product->get_name() : 'Unknown Event';
        
        $seat_info = $ticket->row_letter ? 
            'Row ' . $ticket->row_letter . ', Seat ' . $ticket->seat_number : 
            'General Admission';
        
        $result = array(
            'valid' => true,
            'ticket_id' => $ticket->id,
            'ticket_code' => $ticket->ticket_code,
            'status' => $ticket->status,
            'attendee' => $ticket->attendee_name ?: 'Guest',
            'event' => $event_name,
            'seat' => $seat_info,
            'order_id' => $ticket->order_id
        );
        
        switch ($ticket->status) {
            case 'active':
                $result['message'] = __('Valid ticket - Ready for check-in', 'azure-plugin');
                break;
                
            case 'used':
                $result['valid'] = false;
                $result['message'] = __('Ticket already used', 'azure-plugin');
                $result['checked_in_at'] = $ticket->checked_in_at;
                break;
                
            case 'cancelled':
                $result['valid'] = false;
                $result['message'] = __('Ticket has been cancelled', 'azure-plugin');
                break;
                
            default:
                $result['valid'] = false;
                $result['message'] = __('Invalid ticket status', 'azure-plugin');
        }
        
        return $result;
    }
    
    /**
     * Validate QR code data
     * 
     * @param string $qr_data JSON QR data
     * @return array Validation result
     */
    public function validate_qr($qr_data) {
        $data = json_decode($qr_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['c'])) {
            return array(
                'valid' => false,
                'status' => 'invalid_qr',
                'message' => __('Invalid QR code format', 'azure-plugin')
            );
        }
        
        // Verify checksum if present
        if (!empty($data['cs']) && !empty($data['t'])) {
            $expected_cs = substr(hash('sha256', $data['c'] . $data['t'] . AUTH_SALT), 0, 8);
            if ($data['cs'] !== $expected_cs) {
                return array(
                    'valid' => false,
                    'status' => 'invalid_checksum',
                    'message' => __('Ticket verification failed', 'azure-plugin')
                );
            }
        }
        
        return $this->validate_ticket($data['c']);
    }
    
    /**
     * Check in a ticket
     * 
     * @param int|string $ticket_id_or_code Ticket ID or code
     * @param int $user_id User performing check-in
     * @return array Result
     */
    public function check_in($ticket_id_or_code, $user_id = null) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $checkins_table = $wpdb->prefix . 'azure_ticket_checkins';
        
        // Find ticket
        if (is_numeric($ticket_id_or_code)) {
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE id = %d",
                $ticket_id_or_code
            ));
        } else {
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
                strtoupper(trim($ticket_id_or_code))
            ));
        }
        
        if (!$ticket) {
            return array(
                'success' => false,
                'status' => 'not_found',
                'message' => __('Ticket not found', 'azure-plugin')
            );
        }
        
        if ($ticket->status === 'used') {
            return array(
                'success' => false,
                'status' => 'already_used',
                'message' => __('Ticket already used', 'azure-plugin'),
                'checked_in_at' => $ticket->checked_in_at
            );
        }
        
        if ($ticket->status !== 'active') {
            return array(
                'success' => false,
                'status' => 'invalid',
                'message' => __('Ticket is not valid for check-in', 'azure-plugin')
            );
        }
        
        // Perform check-in
        $user_id = $user_id ?: get_current_user_id();
        $check_in_time = current_time('mysql');
        
        $updated = $wpdb->update(
            $tickets_table,
            array(
                'status' => 'used',
                'checked_in_at' => $check_in_time,
                'checked_in_by' => $user_id
            ),
            array('id' => $ticket->id)
        );
        
        if ($updated === false) {
            return array(
                'success' => false,
                'status' => 'error',
                'message' => __('Database error during check-in', 'azure-plugin')
            );
        }
        
        // Log the check-in
        $wpdb->insert($checkins_table, array(
            'ticket_id' => $ticket->id,
            'action' => 'checkin',
            'user_id' => $user_id,
            'device_info' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => $check_in_time
        ));
        
        // Get ticket details for response
        $product = wc_get_product($ticket->product_id);
        $event_name = $product ? $product->get_name() : 'Unknown Event';
        
        $seat_info = $ticket->row_letter ? 
            'Row ' . $ticket->row_letter . ', Seat ' . $ticket->seat_number : 
            'General Admission';
        
        return array(
            'success' => true,
            'status' => 'success',
            'message' => __('Check-in successful!', 'azure-plugin'),
            'ticket' => array(
                'id' => $ticket->id,
                'code' => $ticket->ticket_code,
                'attendee' => $ticket->attendee_name ?: 'Guest',
                'event' => $event_name,
                'seat' => $seat_info
            ),
            'checked_in_at' => $check_in_time
        );
    }
    
    /**
     * Undo a check-in (for corrections)
     * 
     * @param int $ticket_id
     * @param int $user_id User performing action
     * @return array Result
     */
    public function undo_checkin($ticket_id, $user_id = null) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $checkins_table = $wpdb->prefix . 'azure_ticket_checkins';
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE id = %d",
            $ticket_id
        ));
        
        if (!$ticket) {
            return array(
                'success' => false,
                'message' => __('Ticket not found', 'azure-plugin')
            );
        }
        
        if ($ticket->status !== 'used') {
            return array(
                'success' => false,
                'message' => __('Ticket is not checked in', 'azure-plugin')
            );
        }
        
        // Revert status
        $wpdb->update(
            $tickets_table,
            array(
                'status' => 'active',
                'checked_in_at' => null,
                'checked_in_by' => null
            ),
            array('id' => $ticket_id)
        );
        
        // Log the undo
        $wpdb->insert($checkins_table, array(
            'ticket_id' => $ticket_id,
            'action' => 'undo_checkin',
            'user_id' => $user_id ?: get_current_user_id(),
            'device_info' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => current_time('mysql')
        ));
        
        return array(
            'success' => true,
            'message' => __('Check-in has been reversed', 'azure-plugin')
        );
    }
    
    /**
     * Get check-in statistics for an event/product
     * 
     * @param int $product_id
     * @return array Statistics
     */
    public function get_checkin_stats($product_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tickets,
                SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as checked_in,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM {$tickets_table} 
            WHERE product_id = %d",
            $product_id
        ));
        
        return array(
            'total' => intval($stats->total_tickets),
            'active' => intval($stats->active_tickets),
            'checked_in' => intval($stats->checked_in),
            'cancelled' => intval($stats->cancelled),
            'check_in_rate' => $stats->total_tickets > 0 ? 
                round(($stats->checked_in / $stats->total_tickets) * 100, 1) : 0
        );
    }
    
    /**
     * Get recent check-ins
     * 
     * @param int $product_id Optional product/event filter
     * @param int $limit Number of records
     * @return array Recent check-ins
     */
    public function get_recent_checkins($product_id = null, $limit = 20) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $checkins_table = $wpdb->prefix . 'azure_ticket_checkins';
        
        $where = $product_id ? $wpdb->prepare("AND t.product_id = %d", $product_id) : '';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, t.ticket_code, t.attendee_name, t.row_letter, t.seat_number, 
                    p.post_title as event_name
            FROM {$checkins_table} c
            INNER JOIN {$tickets_table} t ON c.ticket_id = t.id
            LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
            WHERE c.action = 'checkin' {$where}
            ORDER BY c.created_at DESC
            LIMIT %d",
            $limit
        ));
        
        return array_map(function($row) {
            return array(
                'ticket_code' => $row->ticket_code,
                'attendee' => $row->attendee_name ?: 'Guest',
                'seat' => $row->row_letter ? $row->row_letter . $row->seat_number : 'GA',
                'event' => $row->event_name,
                'checked_in_at' => $row->created_at,
                'checked_in_by' => $row->user_id
            );
        }, $results);
    }
    
    /**
     * Export check-in data to CSV
     * 
     * @param int $product_id
     * @return string CSV content
     */
    public function export_checkins_csv($product_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE product_id = %d ORDER BY id",
            $product_id
        ));
        
        $csv_lines = array();
        $csv_lines[] = implode(',', array(
            'Ticket Code',
            'Attendee Name',
            'Email',
            'Seat',
            'Status',
            'Checked In At',
            'Order ID'
        ));
        
        foreach ($tickets as $ticket) {
            $seat = $ticket->row_letter ? $ticket->row_letter . $ticket->seat_number : 'GA';
            
            $csv_lines[] = implode(',', array(
                $ticket->ticket_code,
                '"' . str_replace('"', '""', $ticket->attendee_name) . '"',
                $ticket->attendee_email,
                $seat,
                $ticket->status,
                $ticket->checked_in_at ?: '',
                $ticket->order_id
            ));
        }
        
        return implode("\n", $csv_lines);
    }
}

