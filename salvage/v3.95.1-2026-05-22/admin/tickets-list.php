<?php
/**
 * Tickets Module - Tickets List Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$tickets_table = $wpdb->prefix . 'azure_tickets';

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Filters
$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$filter_product = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Build query
$where = array('1=1');
$where_args = array();

if ($filter_status) {
    $where[] = 't.status = %s';
    $where_args[] = $filter_status;
}

if ($filter_product) {
    $where[] = 't.product_id = %d';
    $where_args[] = $filter_product;
}

if ($search) {
    $where[] = '(t.ticket_code LIKE %s OR t.attendee_name LIKE %s OR t.attendee_email LIKE %s)';
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $where_args[] = $search_like;
    $where_args[] = $search_like;
    $where_args[] = $search_like;
}

$where_clause = implode(' AND ', $where);

// Get total count
$total_query = "SELECT COUNT(*) FROM {$tickets_table} t WHERE {$where_clause}";
if (!empty($where_args)) {
    $total_query = $wpdb->prepare($total_query, $where_args);
}
$total_items = $wpdb->get_var($total_query) ?: 0;
$total_pages = ceil($total_items / $per_page);

// Get tickets
$query = "SELECT t.*, p.post_title as product_name, o.ID as order_exists
          FROM {$tickets_table} t
          LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
          LEFT JOIN {$wpdb->posts} o ON t.order_id = o.ID
          WHERE {$where_clause}
          ORDER BY t.created_at DESC
          LIMIT %d OFFSET %d";

$query_args = array_merge($where_args, array($per_page, $offset));
$tickets = $wpdb->get_results($wpdb->prepare($query, $query_args));

// Get products for filter dropdown
$ticket_products = $wpdb->get_results("
    SELECT DISTINCT p.ID, p.post_title 
    FROM {$wpdb->posts} p
    INNER JOIN {$tickets_table} t ON p.ID = t.product_id
    WHERE p.post_type = 'product'
    ORDER BY p.post_title
");
?>

<div class="tickets-list-wrap">
    <h2><?php _e('All Tickets', 'azure-plugin'); ?></h2>
    
    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" class="tickets-filters">
            <input type="hidden" name="page" value="azure-plugin-tickets">
            <input type="hidden" name="tab" value="tickets">
            
            <select name="status">
                <option value=""><?php _e('All Statuses', 'azure-plugin'); ?></option>
                <option value="active" <?php selected($filter_status, 'active'); ?>><?php _e('Active', 'azure-plugin'); ?></option>
                <option value="used" <?php selected($filter_status, 'used'); ?>><?php _e('Used', 'azure-plugin'); ?></option>
                <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>><?php _e('Cancelled', 'azure-plugin'); ?></option>
            </select>
            
            <select name="product_id">
                <option value=""><?php _e('All Events', 'azure-plugin'); ?></option>
                <?php foreach ($ticket_products as $product): ?>
                <option value="<?php echo $product->ID; ?>" <?php selected($filter_product, $product->ID); ?>>
                    <?php echo esc_html($product->post_title); ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search tickets...', 'azure-plugin'); ?>">
            
            <button type="submit" class="button"><?php _e('Filter', 'azure-plugin'); ?></button>
            
            <?php if ($filter_status || $filter_product || $search): ?>
            <a href="?page=azure-plugin-tickets&tab=tickets" class="button"><?php _e('Clear', 'azure-plugin'); ?></a>
            <?php endif; ?>
        </form>
        
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php printf(_n('%s item', '%s items', $total_items, 'azure-plugin'), number_format($total_items)); ?>
            </span>
        </div>
    </div>
    
    <?php if (empty($tickets)): ?>
    <div class="no-tickets">
        <p><?php _e('No tickets found.', 'azure-plugin'); ?></p>
    </div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped tickets-table">
        <thead>
            <tr>
                <th class="column-code"><?php _e('Ticket Code', 'azure-plugin'); ?></th>
                <th class="column-event"><?php _e('Event', 'azure-plugin'); ?></th>
                <th class="column-attendee"><?php _e('Attendee', 'azure-plugin'); ?></th>
                <th class="column-seat"><?php _e('Seat', 'azure-plugin'); ?></th>
                <th class="column-order"><?php _e('Order', 'azure-plugin'); ?></th>
                <th class="column-status"><?php _e('Status', 'azure-plugin'); ?></th>
                <th class="column-date"><?php _e('Purchased', 'azure-plugin'); ?></th>
                <th class="column-actions"><?php _e('Actions', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickets as $ticket): ?>
            <tr>
                <td class="column-code">
                    <code class="ticket-code"><?php echo esc_html($ticket->ticket_code); ?></code>
                </td>
                <td class="column-event">
                    <?php 
                    if ($ticket->product_name) {
                        echo '<a href="' . get_edit_post_link($ticket->product_id) . '">' . esc_html($ticket->product_name) . '</a>';
                    } else {
                        echo '<span class="deleted">' . __('(deleted)', 'azure-plugin') . '</span>';
                    }
                    ?>
                </td>
                <td class="column-attendee">
                    <strong><?php echo esc_html($ticket->attendee_name ?: '-'); ?></strong>
                    <?php if ($ticket->attendee_email): ?>
                    <br><small><?php echo esc_html($ticket->attendee_email); ?></small>
                    <?php endif; ?>
                </td>
                <td class="column-seat">
                    <?php 
                    if ($ticket->row_letter && $ticket->seat_number) {
                        echo '<span class="seat-badge">' . esc_html($ticket->row_letter . $ticket->seat_number) . '</span>';
                    } else {
                        echo '<span class="seat-badge ga">' . __('GA', 'azure-plugin') . '</span>';
                    }
                    ?>
                </td>
                <td class="column-order">
                    <?php if ($ticket->order_exists): ?>
                    <a href="<?php echo admin_url('post.php?post=' . $ticket->order_id . '&action=edit'); ?>">
                        #<?php echo $ticket->order_id; ?>
                    </a>
                    <?php else: ?>
                    <span class="deleted">#<?php echo $ticket->order_id; ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-status">
                    <?php
                    $status_classes = array(
                        'active' => 'status-active',
                        'used' => 'status-used',
                        'cancelled' => 'status-cancelled'
                    );
                    $status_class = $status_classes[$ticket->status] ?? '';
                    ?>
                    <span class="ticket-status <?php echo $status_class; ?>">
                        <?php echo ucfirst($ticket->status); ?>
                    </span>
                    <?php if ($ticket->status === 'used' && $ticket->checked_in_at): ?>
                    <br><small><?php echo date('M j, g:i A', strtotime($ticket->checked_in_at)); ?></small>
                    <?php endif; ?>
                </td>
                <td class="column-date">
                    <?php echo date('M j, Y', strtotime($ticket->created_at)); ?>
                    <br><small><?php echo date('g:i A', strtotime($ticket->created_at)); ?></small>
                </td>
                <td class="column-actions">
                    <button type="button" class="button button-small view-ticket-qr" data-ticket-id="<?php echo $ticket->id; ?>" data-qr="<?php echo esc_attr($ticket->qr_data); ?>">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                    <?php if ($ticket->status === 'active'): ?>
                    <button type="button" class="button button-small cancel-ticket" data-ticket-id="<?php echo $ticket->id; ?>">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small resend-ticket" data-ticket-id="<?php echo $ticket->id; ?>">
                        <span class="dashicons dashicons-email"></span>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $pagination_args = array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'total' => $total_pages,
                'current' => $current_page,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;'
            );
            echo paginate_links($pagination_args);
            ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- QR Code Modal -->
<div id="qr-modal" class="ticket-modal" style="display: none;">
    <div class="modal-content">
        <span class="modal-close">&times;</span>
        <h3><?php _e('Ticket QR Code', 'azure-plugin'); ?></h3>
        <div class="qr-display">
            <canvas id="qr-canvas"></canvas>
        </div>
        <p class="ticket-code-display"></p>
    </div>
</div>

<style>
.tickets-filters {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.tickets-filters select,
.tickets-filters input[type="search"] {
    max-width: 200px;
}

.tickets-table .column-code { width: 120px; }
.tickets-table .column-event { width: 20%; }
.tickets-table .column-attendee { width: 20%; }
.tickets-table .column-seat { width: 80px; }
.tickets-table .column-order { width: 80px; }
.tickets-table .column-status { width: 100px; }
.tickets-table .column-date { width: 120px; }
.tickets-table .column-actions { width: 100px; }

.ticket-code {
    background: #f0f0f1;
    padding: 3px 6px;
    border-radius: 3px;
    font-size: 12px;
}

.seat-badge {
    display: inline-block;
    padding: 3px 8px;
    background: #2271b1;
    color: #fff;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.seat-badge.ga {
    background: #996800;
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

.deleted {
    color: #999;
    font-style: italic;
}

.no-tickets {
    padding: 40px;
    text-align: center;
    background: #f9f9f9;
    border-radius: 4px;
}

/* Modal */
.ticket-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ticket-modal .modal-content {
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    max-width: 400px;
    width: 90%;
    text-align: center;
    position: relative;
}

.modal-close {
    position: absolute;
    top: 10px;
    right: 15px;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.modal-close:hover {
    color: #000;
}

.qr-display {
    padding: 20px;
    background: #fff;
    display: inline-block;
    border: 1px solid #ddd;
    margin: 15px 0;
}

.ticket-code-display {
    font-family: monospace;
    font-size: 18px;
    font-weight: 600;
    color: #333;
}
</style>

<script>
jQuery(document).ready(function($) {
    // View QR Code
    $('.view-ticket-qr').on('click', function() {
        var qrData = $(this).data('qr');
        var ticketCode = JSON.parse(qrData).code;
        
        $('#qr-modal').show();
        $('.ticket-code-display').text(ticketCode);
        
        // Generate QR code (would need qrcode library)
        // For now, show placeholder
        $('#qr-canvas').replaceWith('<div style="width:200px;height:200px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;"><span style="color:#999;">QR Code</span></div>');
    });
    
    // Close modal
    $('.modal-close, .ticket-modal').on('click', function(e) {
        if (e.target === this) {
            $('#qr-modal').hide();
        }
    });
    
    // Cancel ticket
    $('.cancel-ticket').on('click', function() {
        if (!confirm('<?php _e('Are you sure you want to cancel this ticket?', 'azure-plugin'); ?>')) {
            return;
        }
        
        var $btn = $(this);
        var ticketId = $btn.data('ticket-id');
        
        // AJAX call to cancel ticket
        alert('Cancel ticket ' + ticketId);
    });
    
    // Resend ticket
    $('.resend-ticket').on('click', function() {
        var $btn = $(this);
        var ticketId = $btn.data('ticket-id');
        
        // AJAX call to resend ticket email
        alert('Resend ticket ' + ticketId);
    });
});
</script>

