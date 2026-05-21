<?php
/**
 * Tickets Module - Dashboard Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$tickets_table = $wpdb->prefix . 'azure_tickets';

// Get recent tickets
$recent_tickets = array();
if ($wpdb->get_var("SHOW TABLES LIKE '{$tickets_table}'") === $tickets_table) {
    $recent_tickets = $wpdb->get_results("
        SELECT t.*, p.post_title as product_name 
        FROM {$tickets_table} t
        LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
        ORDER BY t.created_at DESC 
        LIMIT 10
    ");
}

// Get venue count (TEC venues with seating layouts)
$venues_with_layouts = 0;
if (class_exists('Tribe__Events__Main')) {
    $tec_venues = get_posts(array(
        'post_type' => 'tribe_venue',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids'
    ));
    
    foreach ($tec_venues as $venue_id) {
        if (get_post_meta($venue_id, '_azure_seating_layout', true)) {
            $venues_with_layouts++;
        }
    }
}
$stats['total_venues'] = $venues_with_layouts;

// Get upcoming events with tickets
$upcoming_events = array();
if (class_exists('Tribe__Events__Main')) {
    $upcoming_events = tribe_get_events(array(
        'start_date' => 'now',
        'posts_per_page' => 5,
        'meta_query' => array(
            array(
                'key' => '_ticket_product_id',
                'compare' => 'EXISTS'
            )
        )
    ));
}
?>

<div class="tickets-dashboard">
    <h2><?php _e('Tickets Dashboard', 'azure-plugin'); ?></h2>
    
    <!-- Stats Cards -->
    <div class="stats-cards">
        <div class="stat-card info">
            <div class="stat-number"><?php echo number_format($stats['total_venues']); ?></div>
            <div class="stat-label"><?php _e('Venues', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card success">
            <div class="stat-number"><?php echo number_format($stats['total_tickets_sold']); ?></div>
            <div class="stat-label"><?php _e('Tickets Sold', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['tickets_today']); ?></div>
            <div class="stat-label"><?php _e('Sold Today', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-number"><?php echo count($upcoming_events); ?></div>
            <div class="stat-label"><?php _e('Upcoming Events', 'azure-plugin'); ?></div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions" style="margin: 30px 0;">
        <h3><?php _e('Quick Actions', 'azure-plugin'); ?></h3>
        <div class="action-buttons">
            <a href="?page=azure-plugin-tickets&tab=venues&action=new" class="button button-primary">
                <span class="dashicons dashicons-plus-alt"></span>
                <?php _e('Create New Venue', 'azure-plugin'); ?>
            </a>
            <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button">
                <span class="dashicons dashicons-tickets-alt"></span>
                <?php _e('Create Ticket Product', 'azure-plugin'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets-checkin'); ?>" class="button">
                <span class="dashicons dashicons-smartphone"></span>
                <?php _e('Open Check-in Scanner', 'azure-plugin'); ?>
            </a>
        </div>
    </div>
    
    <!-- Recent Tickets -->
    <div class="recent-tickets">
        <h3><?php _e('Recent Tickets', 'azure-plugin'); ?></h3>
        
        <?php if (empty($recent_tickets)): ?>
        <p class="no-items"><?php _e('No tickets sold yet.', 'azure-plugin'); ?></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Ticket Code', 'azure-plugin'); ?></th>
                    <th><?php _e('Event', 'azure-plugin'); ?></th>
                    <th><?php _e('Attendee', 'azure-plugin'); ?></th>
                    <th><?php _e('Seat', 'azure-plugin'); ?></th>
                    <th><?php _e('Status', 'azure-plugin'); ?></th>
                    <th><?php _e('Date', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_tickets as $ticket): ?>
                <tr>
                    <td><code><?php echo esc_html($ticket->ticket_code); ?></code></td>
                    <td><?php echo esc_html($ticket->product_name ?: 'Unknown'); ?></td>
                    <td><?php echo esc_html($ticket->attendee_name ?: '-'); ?></td>
                    <td>
                        <?php 
                        if ($ticket->row_letter && $ticket->seat_number) {
                            echo esc_html($ticket->row_letter . $ticket->seat_number);
                        } else {
                            _e('General', 'azure-plugin');
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        $status_class = '';
                        switch ($ticket->status) {
                            case 'active': $status_class = 'status-active'; break;
                            case 'used': $status_class = 'status-used'; break;
                            case 'cancelled': $status_class = 'status-cancelled'; break;
                        }
                        ?>
                        <span class="ticket-status <?php echo $status_class; ?>">
                            <?php echo ucfirst($ticket->status); ?>
                        </span>
                    </td>
                    <td><?php echo date('M j, Y g:i A', strtotime($ticket->created_at)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($upcoming_events)): ?>
    <!-- Upcoming Events -->
    <div class="upcoming-events" style="margin-top: 30px;">
        <h3><?php _e('Upcoming Events with Tickets', 'azure-plugin'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Event', 'azure-plugin'); ?></th>
                    <th><?php _e('Date', 'azure-plugin'); ?></th>
                    <th><?php _e('Tickets Sold', 'azure-plugin'); ?></th>
                    <th><?php _e('Actions', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming_events as $event): 
                    $ticket_product_id = get_post_meta($event->ID, '_ticket_product_id', true);
                    $tickets_sold = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$tickets_table} WHERE product_id = %d",
                        $ticket_product_id
                    )) ?: 0;
                ?>
                <tr>
                    <td><?php echo esc_html($event->post_title); ?></td>
                    <td><?php echo tribe_get_start_date($event); ?></td>
                    <td><?php echo number_format($tickets_sold); ?></td>
                    <td>
                        <a href="<?php echo get_edit_post_link($event->ID); ?>" class="button button-small">
                            <?php _e('Edit', 'azure-plugin'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
.tickets-dashboard .action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tickets-dashboard .action-buttons .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.ticket-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.ticket-status.status-active {
    background: #d7f0e5;
    color: #00713a;
}

.ticket-status.status-used {
    background: #e5e5e5;
    color: #666;
}

.ticket-status.status-cancelled {
    background: #fce4e4;
    color: #d63638;
}

.no-items {
    padding: 20px;
    text-align: center;
    color: #666;
    background: #f9f9f9;
    border-radius: 4px;
}
</style>

