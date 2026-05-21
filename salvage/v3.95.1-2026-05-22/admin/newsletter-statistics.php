<?php
/**
 * Newsletter Statistics Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$stats_table = $wpdb->prefix . 'azure_newsletter_stats';
$newsletters_table = $wpdb->prefix . 'azure_newsletters';

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stats_table}'") === $stats_table;

if (!$table_exists) {
    echo '<div class="notice notice-info"><p>' . __('Newsletter tables not yet created.', 'azure-plugin') . '</p></div>';
    return;
}

// Get filter params
$campaign_filter = isset($_GET['campaign']) ? intval($_GET['campaign']) : 0;
$date_from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : date('Y-m-d');

// Build date condition
$date_condition = $wpdb->prepare(
    "AND created_at BETWEEN %s AND %s",
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59'
);

$campaign_condition = '';
if ($campaign_filter > 0) {
    $campaign_condition = $wpdb->prepare("AND newsletter_id = %d", $campaign_filter);
}

// Debug: Check what's in the stats table
$debug_total = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table}");
$debug_sent = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'sent'");
$debug_recent = $wpdb->get_results("SELECT * FROM {$stats_table} ORDER BY created_at DESC LIMIT 5");

// Get aggregate stats
$total_sent = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'sent' {$date_condition} {$campaign_condition}");
$total_delivered = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'delivered' {$date_condition} {$campaign_condition}");
$total_opened = $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE event_type = 'opened' {$date_condition} {$campaign_condition}");
$total_clicked = $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE event_type = 'clicked' {$date_condition} {$campaign_condition}");
$total_bounced = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'bounced' {$date_condition} {$campaign_condition}");
$total_unsubscribed = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'unsubscribed' {$date_condition} {$campaign_condition}");
$total_complained = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'complained' {$date_condition} {$campaign_condition}");

// Calculate rates - use delivered count if available, otherwise fall back to sent
$base_count = $total_delivered > 0 ? $total_delivered : $total_sent;
$open_rate = $base_count > 0 ? round(($total_opened / $base_count) * 100, 1) : 0;
$click_rate = $base_count > 0 ? round(($total_clicked / $base_count) * 100, 1) : 0;
$bounce_rate = $total_sent > 0 ? round(($total_bounced / $total_sent) * 100, 1) : 0;
$delivery_rate = $total_sent > 0 ? round(($total_delivered / $total_sent) * 100, 1) : 0;

// Get all campaigns for filter dropdown
$campaigns = $wpdb->get_results("SELECT id, name, sent_at FROM {$newsletters_table} WHERE status = 'sent' ORDER BY sent_at DESC");

// Get daily stats for chart
$daily_stats = $wpdb->get_results("
    SELECT 
        DATE(created_at) as date,
        SUM(CASE WHEN event_type = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN event_type = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN event_type = 'opened' THEN 1 ELSE 0 END) as opened,
        SUM(CASE WHEN event_type = 'clicked' THEN 1 ELSE 0 END) as clicked,
        SUM(CASE WHEN event_type = 'bounced' THEN 1 ELSE 0 END) as bounced
    FROM {$stats_table}
    WHERE 1=1 {$date_condition} {$campaign_condition}
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");

// Get top clicked links (if specific campaign)
$top_links = array();
if ($campaign_filter > 0) {
    $top_links = $wpdb->get_results($wpdb->prepare("
        SELECT link_url, link_text, COUNT(*) as clicks
        FROM {$stats_table}
        WHERE event_type = 'clicked' AND newsletter_id = %d
        GROUP BY link_url, link_text
        ORDER BY clicks DESC
        LIMIT 10
    ", $campaign_filter));
}
?>

<div class="newsletter-statistics-page">
    
    <!-- Filters -->
    <div class="stats-filters">
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="azure-plugin-newsletter">
            <input type="hidden" name="tab" value="statistics">
            
            <select name="campaign">
                <option value=""><?php _e('All Campaigns', 'azure-plugin'); ?></option>
                <?php foreach ($campaigns as $c): ?>
                <option value="<?php echo $c->id; ?>" <?php selected($campaign_filter, $c->id); ?>>
                    <?php echo esc_html($c->name); ?>
                    (<?php echo date_i18n(get_option('date_format'), strtotime($c->sent_at)); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            
            <input type="date" name="from" value="<?php echo esc_attr($date_from); ?>">
            <span>to</span>
            <input type="date" name="to" value="<?php echo esc_attr($date_to); ?>">
            
            <button type="submit" class="button"><?php _e('Filter', 'azure-plugin'); ?></button>
            
            <button type="button" id="sync-stats-btn" class="button" style="margin-left: 20px;">
                <span class="dashicons dashicons-update" style="line-height: 1.3;"></span>
                <?php _e('Sync Stats from Mailgun', 'azure-plugin'); ?>
            </button>
            <span id="sync-status" style="margin-left: 10px;"></span>
        </form>
    </div>
    
    <?php if (isset($_GET['debug']) || $debug_total == 0): ?>
    <!-- Debug Info -->
    <div class="notice notice-info" style="padding: 10px; margin: 10px 0;">
        <strong>📊 Stats Debug:</strong>
        Total records in stats table: <strong><?php echo $debug_total; ?></strong> |
        Total 'sent' events (all time): <strong><?php echo $debug_sent; ?></strong> |
        Filter: <?php echo esc_html($date_from); ?> to <?php echo esc_html($date_to); ?> |
        Filtered 'sent': <strong><?php echo $total_sent; ?></strong>
        <?php if (!empty($debug_recent)): ?>
        <br><br><strong>Recent stats entries:</strong>
        <table class="widefat" style="margin-top: 10px;">
            <thead><tr><th>ID</th><th>Newsletter</th><th>Email</th><th>Event</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($debug_recent as $r): ?>
            <tr>
                <td><?php echo $r->id ?? '-'; ?></td>
                <td><?php echo $r->newsletter_id ?? '-'; ?></td>
                <td><?php echo esc_html($r->email ?? '-'); ?></td>
                <td><?php echo esc_html($r->event_type ?? '-'); ?></td>
                <td><?php echo esc_html($r->created_at ?? '-'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <br><em>No stats recorded yet.</em>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Overview Stats -->
    <div class="stats-overview">
        <div class="stat-card highlight">
            <div class="stat-value"><?php echo number_format($total_sent); ?></div>
            <div class="stat-label"><?php _e('Emails Sent', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card <?php echo $delivery_rate >= 95 ? 'success' : ($delivery_rate >= 90 ? '' : 'warning'); ?>">
            <div class="stat-value"><?php echo $delivery_rate; ?>%</div>
            <div class="stat-label"><?php _e('Delivered', 'azure-plugin'); ?></div>
            <div class="stat-detail"><?php echo number_format($total_delivered); ?> <?php _e('delivered', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $open_rate; ?>%</div>
            <div class="stat-label"><?php _e('Open Rate', 'azure-plugin'); ?></div>
            <div class="stat-detail"><?php echo number_format($total_opened); ?> <?php _e('unique opens', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $click_rate; ?>%</div>
            <div class="stat-label"><?php _e('Click Rate', 'azure-plugin'); ?></div>
            <div class="stat-detail"><?php echo number_format($total_clicked); ?> <?php _e('unique clicks', 'azure-plugin'); ?></div>
        </div>
        <div class="stat-card <?php echo $bounce_rate > 5 ? 'warning' : ''; ?>">
            <div class="stat-value"><?php echo $bounce_rate; ?>%</div>
            <div class="stat-label"><?php _e('Bounce Rate', 'azure-plugin'); ?></div>
            <div class="stat-detail"><?php echo number_format($total_bounced); ?> <?php _e('bounced', 'azure-plugin'); ?></div>
        </div>
    </div>
    
    <!-- Secondary Stats -->
    <div class="stats-secondary">
        <div class="secondary-stat">
            <span class="dashicons dashicons-no"></span>
            <strong><?php echo number_format($total_unsubscribed); ?></strong> <?php _e('unsubscribed', 'azure-plugin'); ?>
        </div>
        <div class="secondary-stat <?php echo $total_complained > 0 ? 'warning' : ''; ?>">
            <span class="dashicons dashicons-flag"></span>
            <strong><?php echo number_format($total_complained); ?></strong> <?php _e('complaints', 'azure-plugin'); ?>
        </div>
    </div>
    
    <!-- Chart -->
    <div class="stats-chart-section">
        <h3><?php _e('Activity Over Time', 'azure-plugin'); ?></h3>
        <div class="chart-container">
            <canvas id="stats-chart" height="300"></canvas>
        </div>
    </div>
    
    <?php if ($campaign_filter > 0 && !empty($top_links)): ?>
    <!-- Top Links -->
    <div class="top-links-section">
        <h3><?php _e('Top Clicked Links', 'azure-plugin'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Link', 'azure-plugin'); ?></th>
                    <th class="num"><?php _e('Clicks', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_links as $link): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($link->link_text ?: '(no text)'); ?></strong><br>
                        <small><a href="<?php echo esc_url($link->link_url); ?>" target="_blank"><?php echo esc_html($link->link_url); ?></a></small>
                    </td>
                    <td class="num"><?php echo number_format($link->clicks); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Recent Activity -->
    <div class="recent-activity-section">
        <h3><?php _e('Recent Activity', 'azure-plugin'); ?></h3>
        <?php
        $recent = $wpdb->get_results("
            SELECT s.*, n.name as newsletter_name
            FROM {$stats_table} s
            LEFT JOIN {$newsletters_table} n ON s.newsletter_id = n.id
            WHERE 1=1 {$date_condition} {$campaign_condition}
            ORDER BY s.created_at DESC
            LIMIT 50
        ");
        ?>
        <?php if (empty($recent)): ?>
        <p class="no-data"><?php _e('No activity data available for the selected period.', 'azure-plugin'); ?></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'azure-plugin'); ?></th>
                    <th><?php _e('Campaign', 'azure-plugin'); ?></th>
                    <th><?php _e('Email', 'azure-plugin'); ?></th>
                    <th><?php _e('Event', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $event): ?>
                <tr>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($event->created_at)); ?></td>
                    <td><?php echo esc_html($event->newsletter_name); ?></td>
                    <td><?php echo esc_html($event->email); ?></td>
                    <td>
                        <span class="event-badge event-<?php echo esc_attr($event->event_type); ?>">
                            <?php echo ucfirst($event->event_type); ?>
                        </span>
                        <?php if ($event->event_type === 'clicked' && $event->link_url): ?>
                        <br><small><?php echo esc_html($event->link_text ?: $event->link_url); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<style>
.newsletter-statistics-page .stats-filters {
    margin-bottom: 20px;
}
.newsletter-statistics-page .filter-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.newsletter-statistics-page .stats-overview {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}
.newsletter-statistics-page .stat-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    text-align: center;
    border-radius: 4px;
}
.newsletter-statistics-page .stat-card.highlight {
    border-color: #2271b1;
    background: #f0f6fc;
}
.newsletter-statistics-page .stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #1d2327;
}
.newsletter-statistics-page .stat-label {
    color: #646970;
    margin-top: 5px;
}
.newsletter-statistics-page .stat-detail {
    font-size: 12px;
    color: #999;
    margin-top: 5px;
}
.newsletter-statistics-page .stats-chart-section,
.newsletter-statistics-page .top-links-section,
.newsletter-statistics-page .recent-activity-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.newsletter-statistics-page .stats-chart-section h3,
.newsletter-statistics-page .top-links-section h3,
.newsletter-statistics-page .recent-activity-section h3 {
    margin-top: 0;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}
.newsletter-statistics-page .chart-container {
    position: relative;
    height: 300px;
}
.newsletter-statistics-page .event-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}
.newsletter-statistics-page .event-sent { background: #f0f6fc; color: #2271b1; }
.newsletter-statistics-page .event-delivered { background: #edfaef; color: #007017; }
.newsletter-statistics-page .event-opened { background: #fcf9e8; color: #996800; }
.newsletter-statistics-page .event-clicked { background: #e7f5e7; color: #006505; }
.newsletter-statistics-page .event-bounced { background: #fef2f1; color: #8a2424; }
.newsletter-statistics-page .event-unsubscribed { background: #f0f0f1; color: #50575e; }
.newsletter-statistics-page .event-complained { background: #fef2f1; color: #8a2424; }

/* Stat card states */
.newsletter-statistics-page .stat-card.success {
    border-color: #00a32a;
    background: #edfaef;
}
.newsletter-statistics-page .stat-card.success .stat-value {
    color: #007017;
}
.newsletter-statistics-page .stat-card.warning {
    border-color: #dba617;
    background: #fcf9e8;
}
.newsletter-statistics-page .stat-card.warning .stat-value {
    color: #996800;
}

/* Secondary stats */
.newsletter-statistics-page .stats-secondary {
    display: flex;
    gap: 30px;
    margin-bottom: 30px;
    padding: 15px 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}
.newsletter-statistics-page .secondary-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #646970;
}
.newsletter-statistics-page .secondary-stat .dashicons {
    color: #646970;
}
.newsletter-statistics-page .secondary-stat.warning {
    color: #8a2424;
}
.newsletter-statistics-page .secondary-stat.warning .dashicons {
    color: #d63638;
}
.newsletter-statistics-page .no-data {
    text-align: center;
    padding: 40px;
    color: #646970;
}
.newsletter-statistics-page th.num,
.newsletter-statistics-page td.num {
    text-align: right;
    width: 100px;
}

@media (max-width: 1200px) {
    .newsletter-statistics-page .stats-overview {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 782px) {
    .newsletter-statistics-page .stats-overview {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
jQuery(document).ready(function($) {
    // Sync Stats Button
    $('#sync-stats-btn').on('click', function() {
        var btn = $(this);
        var status = $('#sync-status');
        
        btn.prop('disabled', true);
        status.html('<span class="spinner is-active" style="float:none;margin:0;vertical-align:middle;"></span> <?php _e('Syncing...', 'azure-plugin'); ?>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_sync_stats',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_nonce'); ?>'
        }).done(function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                var d = response.data;
                var msg = '✓ Synced: ' + d.sent_synced + ' sent events';
                if (d.mailgun_events > 0) {
                    msg += ', ' + d.mailgun_events + ' Mailgun events';
                }
                if (d.errors && d.errors.length > 0) {
                    msg += ' (Note: ' + d.errors.join(', ') + ')';
                }
                status.html('<span style="color:#00a32a;">' + msg + '</span>');
                
                // Reload after 2 seconds to show updated stats
                if (d.sent_synced > 0 || d.mailgun_events > 0) {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            } else {
                status.html('<span style="color:#d63638;">✗ ' + (response.data || 'Sync failed') + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            status.html('<span style="color:#d63638;">✗ Request failed</span>');
        });
    });
    
    var ctx = document.getElementById('stats-chart').getContext('2d');
    
    var chartData = {
        labels: <?php echo json_encode(array_map(function($d) { return date('M j', strtotime($d->date)); }, $daily_stats)); ?>,
        datasets: [
            {
                label: '<?php _e('Sent', 'azure-plugin'); ?>',
                data: <?php echo json_encode(array_map(function($d) { return intval($d->sent); }, $daily_stats)); ?>,
                borderColor: '#2271b1',
                backgroundColor: 'rgba(34, 113, 177, 0.1)',
                fill: true,
                tension: 0.4
            },
            {
                label: '<?php _e('Delivered', 'azure-plugin'); ?>',
                data: <?php echo json_encode(array_map(function($d) { return intval($d->delivered); }, $daily_stats)); ?>,
                borderColor: '#00a32a',
                backgroundColor: 'transparent',
                tension: 0.4
            },
            {
                label: '<?php _e('Opened', 'azure-plugin'); ?>',
                data: <?php echo json_encode(array_map(function($d) { return intval($d->opened); }, $daily_stats)); ?>,
                borderColor: '#dba617',
                backgroundColor: 'transparent',
                tension: 0.4
            },
            {
                label: '<?php _e('Clicked', 'azure-plugin'); ?>',
                data: <?php echo json_encode(array_map(function($d) { return intval($d->clicked); }, $daily_stats)); ?>,
                borderColor: '#9b59b6',
                backgroundColor: 'transparent',
                tension: 0.4
            },
            {
                label: '<?php _e('Bounced', 'azure-plugin'); ?>',
                data: <?php echo json_encode(array_map(function($d) { return intval($d->bounced); }, $daily_stats)); ?>,
                borderColor: '#d63638',
                backgroundColor: 'transparent',
                tension: 0.4
            }
        ]
    };
    
    new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});
</script>
