<?php
/**
 * Newsletter Campaigns List
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$newsletters_table = $wpdb->prefix . 'azure_newsletters';
$stats_table = $wpdb->prefix . 'azure_newsletter_stats';

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$newsletters_table}'") === $newsletters_table;

if (!$table_exists) {
    echo '<div class="notice notice-info"><p>' . __('Newsletter tables not yet created. Please deactivate and reactivate the plugin to create the necessary database tables.', 'azure-plugin') . '</p></div>';
    return;
}

// Handle single-row actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitize_key($_GET['action']);
    $id = intval($_GET['id']);
    
    if ($action === 'delete' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_' . $id)) {
        $wpdb->delete($newsletters_table, array('id' => $id), array('%d'));
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Campaign deleted.', 'azure-plugin') . '</p></div>';
    } elseif ($action === 'duplicate' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'duplicate_' . $id)) {
        $original = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$newsletters_table} WHERE id = %d", $id), ARRAY_A);
        if ($original) {
            unset($original['id']);
            $original['name'] = $original['name'] . ' (Copy)';
            $original['status'] = 'draft';
            $original['created_at'] = current_time('mysql');
            $original['sent_at'] = null;
            $original['scheduled_at'] = null;
            $wpdb->insert($newsletters_table, $original);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Campaign duplicated.', 'azure-plugin') . '</p></div>';
        }
    }
}

// Handle bulk actions
if (isset($_POST['action']) && isset($_POST['newsletter_ids'])) {
    if (wp_verify_nonce($_POST['_wpnonce'], 'bulk_newsletters')) {
        $ids = array_map('intval', $_POST['newsletter_ids']);
        switch ($_POST['action']) {
            case 'delete':
                $wpdb->query("DELETE FROM {$newsletters_table} WHERE id IN (" . implode(',', $ids) . ")");
                echo '<div class="notice notice-success"><p>' . __('Selected campaigns deleted.', 'azure-plugin') . '</p></div>';
                break;
            case 'duplicate':
                foreach ($ids as $id) {
                    $original = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$newsletters_table} WHERE id = %d", $id), ARRAY_A);
                    if ($original) {
                        unset($original['id']);
                        $original['name'] = $original['name'] . ' (Copy)';
                        $original['status'] = 'draft';
                        $original['created_at'] = current_time('mysql');
                        $wpdb->insert($newsletters_table, $original);
                    }
                }
                echo '<div class="notice notice-success"><p>' . __('Selected campaigns duplicated.', 'azure-plugin') . '</p></div>';
                break;
        }
    }
}

// Get filter and search params
$status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Build query
$where = "WHERE 1=1";
if ($status_filter) {
    $where .= $wpdb->prepare(" AND status = %s", $status_filter);
}
if ($search) {
    $where .= $wpdb->prepare(" AND (name LIKE %s OR subject LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
}

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

$total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$newsletters_table} {$where}");
$total_pages = ceil($total_items / $per_page);

// Get campaigns
$campaigns = $wpdb->get_results("
    SELECT * FROM {$newsletters_table} 
    {$where}
    ORDER BY created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");

// Get status counts
$status_counts = $wpdb->get_results("
    SELECT status, COUNT(*) as count 
    FROM {$newsletters_table} 
    GROUP BY status
", OBJECT_K);
?>

<div class="newsletter-campaigns-page">
    
    <!-- Quick Stats -->
    <div class="quick-stats">
        <div class="stat-box">
            <span class="stat-value"><?php echo intval($status_counts['sent']->count ?? 0); ?></span>
            <span class="stat-label"><?php _e('Sent', 'azure-plugin'); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-value"><?php echo intval($status_counts['scheduled']->count ?? 0); ?></span>
            <span class="stat-label"><?php _e('Scheduled', 'azure-plugin'); ?></span>
        </div>
        <div class="stat-box">
            <span class="stat-value"><?php echo intval($status_counts['draft']->count ?? 0); ?></span>
            <span class="stat-label"><?php _e('Drafts', 'azure-plugin'); ?></span>
        </div>
    </div>
    
    <!-- Filters and Search -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="filter_status" id="filter-status">
                <option value=""><?php _e('All statuses', 'azure-plugin'); ?></option>
                <option value="draft" <?php selected($status_filter, 'draft'); ?>><?php _e('Draft', 'azure-plugin'); ?></option>
                <option value="scheduled" <?php selected($status_filter, 'scheduled'); ?>><?php _e('Scheduled', 'azure-plugin'); ?></option>
                <option value="sending" <?php selected($status_filter, 'sending'); ?>><?php _e('Sending', 'azure-plugin'); ?></option>
                <option value="sent" <?php selected($status_filter, 'sent'); ?>><?php _e('Sent', 'azure-plugin'); ?></option>
                <option value="paused" <?php selected($status_filter, 'paused'); ?>><?php _e('Paused', 'azure-plugin'); ?></option>
            </select>
            <button type="button" class="button" id="filter-btn"><?php _e('Filter', 'azure-plugin'); ?></button>
        </div>
        
        <form method="get" class="search-box">
            <input type="hidden" name="page" value="azure-plugin-newsletter">
            <input type="hidden" name="tab" value="campaigns">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search campaigns...', 'azure-plugin'); ?>">
            <button type="submit" class="button"><?php _e('Search', 'azure-plugin'); ?></button>
        </form>
        
        <div class="tablenav-pages">
            <?php if ($total_pages > 1): ?>
            <span class="pagination-links">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;'
                ));
                ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
        <span class="dashicons dashicons-email-alt"></span>
        <h3><?php _e('No campaigns yet', 'azure-plugin'); ?></h3>
        <p><?php _e('Create your first newsletter campaign to get started.', 'azure-plugin'); ?></p>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new'); ?>" class="button button-primary button-hero">
            <?php _e('Create Your First Campaign', 'azure-plugin'); ?>
        </a>
    </div>
    <?php else: ?>
    
    <form method="post">
        <?php wp_nonce_field('bulk_newsletters'); ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="action" id="bulk-action-selector">
                    <option value=""><?php _e('Bulk Actions', 'azure-plugin'); ?></option>
                    <option value="delete"><?php _e('Delete', 'azure-plugin'); ?></option>
                    <option value="duplicate"><?php _e('Duplicate', 'azure-plugin'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('Apply', 'azure-plugin'); ?></button>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped campaigns-table">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all">
                    </td>
                    <th class="column-name"><?php _e('Name', 'azure-plugin'); ?></th>
                    <th class="column-subject"><?php _e('Subject', 'azure-plugin'); ?></th>
                    <th class="column-status"><?php _e('Status', 'azure-plugin'); ?></th>
                    <th class="column-sent"><?php _e('Sent', 'azure-plugin'); ?></th>
                    <th class="column-opens"><?php _e('Opens', 'azure-plugin'); ?></th>
                    <th class="column-clicks"><?php _e('Clicks', 'azure-plugin'); ?></th>
                    <th class="column-date"><?php _e('Date', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                <?php
                // Get queue stats - this is the reliable source for sent count
                $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
                $queue_stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                     FROM {$queue_table} WHERE newsletter_id = %d",
                    $campaign->id
                ));
                
                // Use queue sent count as primary (most reliable)
                $sent_count = intval($queue_stats->sent ?? 0);
                
                // Get engagement stats (opens/clicks from stats table - populated by webhooks)
                $open_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = %d AND event_type = 'opened'",
                    $campaign->id
                ));
                $click_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = %d AND event_type = 'clicked'",
                    $campaign->id
                ));
                
                // If no stats from webhooks yet, fall back to local stats
                if ($open_count == 0) {
                    $open_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = %d AND event_type IN ('opened', 'open')",
                        $campaign->id
                    ));
                }
                
                $open_rate = $sent_count > 0 ? round(($open_count / $sent_count) * 100, 1) : 0;
                $click_rate = $sent_count > 0 ? round(($click_count / $sent_count) * 100, 1) : 0;
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" name="newsletter_ids[]" value="<?php echo $campaign->id; ?>">
                    </th>
                    <td class="column-name">
                        <strong>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new&id=' . $campaign->id); ?>">
                                <?php echo esc_html($campaign->name); ?>
                            </a>
                        </strong>
                        <div class="row-actions">
                            <span class="quick-view">
                                <a href="#" class="quick-view-link" 
                                   data-id="<?php echo $campaign->id; ?>" 
                                   data-name="<?php echo esc_attr($campaign->name); ?>"
                                   data-subject="<?php echo esc_attr($campaign->subject); ?>">
                                    <?php _e('Quick View', 'azure-plugin'); ?>
                                </a> |
                            </span>
                            <span class="edit">
                                <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new&id=' . $campaign->id); ?>">
                                    <?php _e('Edit', 'azure-plugin'); ?>
                                </a> |
                            </span>
                            <span class="duplicate">
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns&action=duplicate&id=' . $campaign->id), 'duplicate_' . $campaign->id); ?>">
                                    <?php _e('Duplicate', 'azure-plugin'); ?>
                                </a> |
                            </span>
                            <?php if ($campaign->status === 'draft'): ?>
                            <span class="delete">
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns&action=delete&id=' . $campaign->id), 'delete_' . $campaign->id); ?>" 
                                   class="submitdelete" onclick="return confirm('<?php _e('Are you sure?', 'azure-plugin'); ?>')">
                                    <?php _e('Delete', 'azure-plugin'); ?>
                                </a> |
                            </span>
                            <?php endif; ?>
                            <span class="view">
                                <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=statistics&campaign=' . $campaign->id); ?>">
                                    <?php _e('View Stats', 'azure-plugin'); ?>
                                </a>
                            </span>
                        </div>
                    </td>
                    <td class="column-subject"><?php echo esc_html($campaign->subject); ?></td>
                    <td class="column-status">
                        <span class="status-badge status-<?php echo esc_attr($campaign->status); ?>">
                            <?php echo ucfirst($campaign->status); ?>
                        </span>
                        <?php if ($campaign->status === 'scheduled' && $campaign->scheduled_at): ?>
                        <br><small><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($campaign->scheduled_at)); ?></small>
                        <?php endif; ?>
                        <?php if ($queue_stats && $queue_stats->total > 0 && ($campaign->status === 'scheduled' || $campaign->status === 'sending')): ?>
                        <div class="queue-stats-mini">
                            <?php if ($queue_stats->pending > 0): ?>
                            <span class="queue-pending" title="<?php esc_attr_e('Pending in queue', 'azure-plugin'); ?>">
                                <span class="dashicons dashicons-clock"></span> <?php echo number_format($queue_stats->pending); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($queue_stats->sent > 0): ?>
                            <span class="queue-sent" title="<?php esc_attr_e('Sent', 'azure-plugin'); ?>">
                                <span class="dashicons dashicons-yes"></span> <?php echo number_format($queue_stats->sent); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($queue_stats->failed > 0): ?>
                            <span class="queue-failed" title="<?php esc_attr_e('Failed', 'azure-plugin'); ?>">
                                <span class="dashicons dashicons-no"></span> <?php echo number_format($queue_stats->failed); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php elseif (($campaign->status === 'scheduled' || $campaign->status === 'sending') && (!$queue_stats || $queue_stats->total == 0)): ?>
                        <div class="queue-stats-mini queue-empty">
                            <span class="dashicons dashicons-warning"></span>
                            <span><?php _e('No recipients queued', 'azure-plugin'); ?></span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="column-sent"><?php echo number_format($sent_count); ?></td>
                    <td class="column-opens">
                        <?php if ($sent_count > 0): ?>
                        <?php echo number_format($open_count); ?> <small>(<?php echo $open_rate; ?>%)</small>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td class="column-clicks">
                        <?php if ($sent_count > 0): ?>
                        <?php echo number_format($click_count); ?> <small>(<?php echo $click_rate; ?>%)</small>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td class="column-date">
                        <?php 
                        if ($campaign->sent_at) {
                            echo date_i18n(get_option('date_format'), strtotime($campaign->sent_at));
                        } else {
                            echo date_i18n(get_option('date_format'), strtotime($campaign->created_at));
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <?php endif; ?>
</div>

<style>
.newsletter-campaigns-page .quick-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}
.newsletter-campaigns-page .stat-box {
    background: #f0f6fc;
    padding: 15px 25px;
    border-radius: 4px;
    text-align: center;
    border-left: 4px solid #2271b1;
}
.newsletter-campaigns-page .stat-value {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #1d2327;
}
.newsletter-campaigns-page .stat-label {
    color: #646970;
    font-size: 13px;
}
.newsletter-campaigns-page .empty-state {
    text-align: center;
    padding: 60px 20px;
}
.newsletter-campaigns-page .empty-state .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #dcdcde;
}
.newsletter-campaigns-page .empty-state h3 {
    margin: 20px 0 10px;
}
.newsletter-campaigns-page .search-box {
    float: right;
    display: flex;
    gap: 5px;
}
.newsletter-campaigns-page .column-cb {
    width: 30px;
}
.newsletter-campaigns-page .column-status {
    width: 140px;
}

/* Queue stats mini display */
.queue-stats-mini {
    display: flex;
    gap: 8px;
    margin-top: 5px;
    font-size: 11px;
}
.queue-stats-mini span {
    display: inline-flex;
    align-items: center;
    gap: 2px;
}
.queue-stats-mini .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}
.queue-stats-mini .queue-pending {
    color: #996800;
}
.queue-stats-mini .queue-sent {
    color: #00a32a;
}
.queue-stats-mini .queue-failed {
    color: #d63638;
}
.queue-stats-mini.queue-empty {
    color: #d63638;
    font-style: italic;
}
.queue-stats-mini.queue-empty .dashicons {
    color: #d63638;
}
.newsletter-campaigns-page .column-sent,
.newsletter-campaigns-page .column-opens,
.newsletter-campaigns-page .column-clicks {
    width: 100px;
}
.newsletter-campaigns-page .column-date {
    width: 100px;
}
</style>

<!-- Quick View Modal -->
<div id="quick-view-modal" class="newsletter-modal" style="display:none;">
    <div class="newsletter-modal-overlay"></div>
    <div class="newsletter-modal-content newsletter-modal-wide">
        <div class="newsletter-modal-header">
            <h2 id="modal-title"><?php _e('Newsletter Preview', 'azure-plugin'); ?></h2>
            <button type="button" class="close-modal">&times;</button>
        </div>
        <div class="newsletter-modal-body quick-view-body">
            <!-- Left: Info Panel -->
            <div class="quick-view-info">
                <div class="info-section">
                    <h4><?php _e('Subject', 'azure-plugin'); ?></h4>
                    <p id="modal-subject-text"></p>
                </div>
                <div class="info-section">
                    <h4><?php _e('Status', 'azure-plugin'); ?></h4>
                    <p id="modal-status-text"></p>
                </div>
                <div class="info-section">
                    <h4><?php _e('From', 'azure-plugin'); ?></h4>
                    <p id="modal-from-text"></p>
                </div>
                <div class="info-section">
                    <h4><?php _e('Recipients', 'azure-plugin'); ?></h4>
                    <div id="modal-recipients-info">
                        <span class="spinner is-active" style="float:none;margin:0;"></span>
                    </div>
                </div>
                <div class="info-section" id="modal-schedule-section" style="display:none;">
                    <h4><?php _e('Scheduled', 'azure-plugin'); ?></h4>
                    <p id="modal-schedule-text"></p>
                </div>
                <div class="info-section" id="modal-stats-section" style="display:none;">
                    <h4><?php _e('Statistics', 'azure-plugin'); ?></h4>
                    <div id="modal-stats-info"></div>
                </div>
            </div>
            <!-- Right: Preview -->
            <div class="quick-view-preview">
                <div id="modal-loading" style="text-align:center;padding:40px;">
                    <span class="spinner is-active" style="float:none;"></span>
                    <p><?php _e('Loading preview...', 'azure-plugin'); ?></p>
                </div>
                <iframe id="preview-iframe" style="width:100%;height:100%;border:none;display:none;"></iframe>
            </div>
        </div>
        <div class="newsletter-modal-footer">
            <a href="#" id="modal-edit-btn" class="button button-primary"><?php _e('Edit', 'azure-plugin'); ?></a>
            <button type="button" class="button close-modal"><?php _e('Close', 'azure-plugin'); ?></button>
        </div>
    </div>
</div>

<style>
.newsletter-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.newsletter-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
}
.newsletter-modal-content {
    position: relative;
    background: #fff;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column;
}
.newsletter-modal-content.newsletter-modal-wide {
    max-width: 1100px;
}
.newsletter-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 20px;
    border-bottom: 1px solid #dcdcde;
}
.newsletter-modal-header h2 {
    margin: 0;
    font-size: 18px;
}
.newsletter-modal-header .close-modal {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #646970;
    padding: 0;
    line-height: 1;
}
.newsletter-modal-header .close-modal:hover {
    color: #1d2327;
}
.newsletter-modal-meta {
    padding: 10px 20px;
    background: #f6f7f7;
    border-bottom: 1px solid #dcdcde;
    font-size: 13px;
    color: #50575e;
}
.newsletter-modal-body {
    flex: 1;
    overflow: auto;
    padding: 0;
}
.newsletter-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dcdcde;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* Quick View Two-Column Layout */
.quick-view-body {
    display: flex;
    flex: 1;
    overflow: hidden;
}
.quick-view-info {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid #dcdcde;
    padding: 20px;
    overflow-y: auto;
    background: #f6f7f7;
}
.quick-view-info .info-section {
    margin-bottom: 20px;
}
.quick-view-info .info-section:last-child {
    margin-bottom: 0;
}
.quick-view-info h4 {
    margin: 0 0 5px;
    font-size: 11px;
    text-transform: uppercase;
    color: #646970;
    font-weight: 600;
}
.quick-view-info p {
    margin: 0;
    font-size: 14px;
    color: #1d2327;
}
.quick-view-info .recipients-list {
    font-size: 13px;
}
.quick-view-info .recipients-list .recipient-item {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 3px 0;
}
.quick-view-info .recipients-list .recipient-count {
    color: #2271b1;
    font-weight: 500;
}
.quick-view-info .stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.quick-view-info .stat-item {
    text-align: center;
    padding: 8px;
    background: #fff;
    border-radius: 4px;
}
.quick-view-info .stat-item .stat-value {
    font-size: 18px;
    font-weight: 600;
    color: #1d2327;
}
.quick-view-info .stat-item .stat-label {
    font-size: 11px;
    color: #646970;
}
.quick-view-preview {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.quick-view-preview iframe {
    flex: 1;
}
.status-badge-modal {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}
.status-badge-modal.status-draft { background: #dcdcde; color: #50575e; }
.status-badge-modal.status-scheduled { background: #f0f6fc; color: #2271b1; }
.status-badge-modal.status-sending { background: #fcf9e8; color: #996800; }
.status-badge-modal.status-sent { background: #edfaef; color: #00a32a; }
</style>

<script>
jQuery(document).ready(function($) {
    $('#filter-btn').on('click', function() {
        var status = $('#filter-status').val();
        var url = '<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>';
        if (status) {
            url += '&status=' + status;
        }
        window.location.href = url;
    });
    
    $('#cb-select-all').on('change', function() {
        $('input[name="newsletter_ids[]"]').prop('checked', $(this).is(':checked'));
    });
    
    // Quick View Modal
    $('.quick-view-link').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('name');
        var subject = $(this).data('subject');
        
        $('#modal-title').text(name || 'Newsletter Preview');
        $('#modal-subject-text').text(subject);
        $('#modal-edit-btn').attr('href', '<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new&id='); ?>' + id);
        
        // Reset modal state
        $('#modal-loading').show();
        $('#preview-iframe').hide();
        $('#modal-recipients-info').html('<span class="spinner is-active" style="float:none;margin:0;"></span>');
        $('#modal-status-text').html('');
        $('#modal-from-text').html('');
        $('#modal-schedule-section').hide();
        $('#modal-stats-section').hide();
        
        // Show modal
        $('#quick-view-modal').show();
        $('body').css('overflow', 'hidden');
        
        // Load preview content via AJAX
        $.post(ajaxurl, {
            action: 'azure_newsletter_get_preview',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_nonce'); ?>',
            newsletter_id: id
        }, function(response) {
            $('#modal-loading').hide();
            
            if (response.success) {
                var data = response.data;
                
                // Update info panel
                $('#modal-status-text').html('<span class="status-badge-modal status-' + data.status + '">' + data.status.charAt(0).toUpperCase() + data.status.slice(1) + '</span>');
                $('#modal-from-text').text(data.from_name ? data.from_name + ' <' + data.from_email + '>' : data.from_email || '-');
                
                // Recipients info
                var recipientsHtml = '<div class="recipients-list">';
                if (data.recipients) {
                    recipientsHtml += '<div class="recipient-item"><span class="recipient-count">' + data.recipients.total + '</span> recipients</div>';
                    if (data.recipients.lists && data.recipients.lists.length > 0) {
                        data.recipients.lists.forEach(function(list) {
                            recipientsHtml += '<div class="recipient-item">• ' + list.name + ' (' + list.count + ')</div>';
                        });
                    }
                } else {
                    recipientsHtml += '<span style="color:#666;">Not set</span>';
                }
                recipientsHtml += '</div>';
                $('#modal-recipients-info').html(recipientsHtml);
                
                // Schedule info
                if (data.scheduled_at) {
                    $('#modal-schedule-text').text(data.scheduled_at);
                    $('#modal-schedule-section').show();
                }
                
                // Stats if sent
                if (data.stats && data.status === 'sent') {
                    var statsHtml = '<div class="stats-grid">';
                    statsHtml += '<div class="stat-item"><div class="stat-value">' + data.stats.sent + '</div><div class="stat-label">Sent</div></div>';
                    statsHtml += '<div class="stat-item"><div class="stat-value">' + data.stats.open_rate + '%</div><div class="stat-label">Opens</div></div>';
                    statsHtml += '<div class="stat-item"><div class="stat-value">' + data.stats.click_rate + '%</div><div class="stat-label">Clicks</div></div>';
                    statsHtml += '<div class="stat-item"><div class="stat-value">' + data.stats.bounces + '</div><div class="stat-label">Bounces</div></div>';
                    statsHtml += '</div>';
                    $('#modal-stats-info').html(statsHtml);
                    $('#modal-stats-section').show();
                }
                
                // Preview
                if (data.html) {
                    var iframe = $('#preview-iframe')[0];
                    iframe.contentWindow.document.open();
                    iframe.contentWindow.document.write(data.html);
                    iframe.contentWindow.document.close();
                    $('#preview-iframe').show();
                } else {
                    var iframe = $('#preview-iframe')[0];
                    iframe.contentWindow.document.open();
                    iframe.contentWindow.document.write('<p style="padding:20px;color:#666;text-align:center;">No preview available. Edit this newsletter to add content.</p>');
                    iframe.contentWindow.document.close();
                    $('#preview-iframe').show();
                }
            } else {
                $('#preview-iframe').show();
                var iframe = $('#preview-iframe')[0];
                iframe.contentWindow.document.open();
                iframe.contentWindow.document.write('<p style="padding:20px;color:#d63638;">Unable to load preview: ' + (response.data || 'Unknown error') + '</p>');
                iframe.contentWindow.document.close();
            }
        }).fail(function() {
            $('#modal-loading').hide();
            $('#preview-iframe').show();
            var iframe = $('#preview-iframe')[0];
            iframe.contentWindow.document.open();
            iframe.contentWindow.document.write('<p style="padding:20px;color:#d63638;">Network error loading preview.</p>');
            iframe.contentWindow.document.close();
        });
    });
    
    // Close modal
    $('.close-modal, .newsletter-modal-overlay').on('click', function() {
        $('#quick-view-modal').hide();
        $('body').css('overflow', '');
    });
    
    // Close on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#quick-view-modal').is(':visible')) {
            $('#quick-view-modal').hide();
            $('body').css('overflow', '');
        }
    });
});
</script>
