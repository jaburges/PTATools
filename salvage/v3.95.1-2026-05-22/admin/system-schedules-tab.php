<?php
/**
 * System > Schedules Tab
 * Displays all PTA Tools plugin scheduled cron jobs with status, next run, and manual trigger.
 */
if (!defined('ABSPATH')) {
    exit;
}

$plugin_crons = array(
    array(
        'hook'   => 'azure_backup_scheduled',
        'module' => 'Backup',
        'desc'   => 'Scheduled automatic backup',
    ),
    array(
        'hook'   => 'azure_backup_cleanup',
        'module' => 'Backup',
        'desc'   => 'Clean up old backup files',
    ),
    array(
        'hook'   => 'azure_sso_scheduled_sync',
        'module' => 'SSO',
        'desc'   => 'Sync Azure AD users/roles',
    ),
    array(
        'hook'   => 'onedrive_media_auto_sync',
        'module' => 'OneDrive Media',
        'desc'   => 'Auto-sync media with OneDrive/SharePoint',
    ),
    array(
        'hook'   => 'pta_sync_o365_groups_scheduled',
        'module' => 'PTA Groups',
        'desc'   => 'Sync Office 365 groups',
    ),
    array(
        'hook'   => 'pta_sync_group_memberships_scheduled',
        'module' => 'PTA Groups',
        'desc'   => 'Sync group memberships',
    ),
    array(
        'hook'   => 'pta_process_sync_queue',
        'module' => 'PTA Sync',
        'desc'   => 'Process PTA sync queue',
    ),
    array(
        'hook'   => 'pta_daily_cleanup',
        'module' => 'PTA',
        'desc'   => 'Daily PTA data cleanup',
    ),
    array(
        'hook'   => 'azure_process_email_queue',
        'module' => 'Email',
        'desc'   => 'Process outbound email queue',
    ),
    array(
        'hook'   => 'azure_mail_token_refresh',
        'module' => 'Email',
        'desc'   => 'Refresh mail OAuth token',
    ),
    array(
        'hook'   => 'azure_calendar_token_refresh',
        'module' => 'Calendar',
        'desc'   => 'Refresh calendar OAuth token',
    ),
    array(
        'hook'   => 'azure_newsletter_process_queue',
        'module' => 'Newsletter',
        'desc'   => 'Process newsletter send queue',
    ),
    array(
        'hook'   => 'azure_newsletter_check_bounces',
        'module' => 'Newsletter',
        'desc'   => 'Check for email bounces',
    ),
    array(
        'hook'   => 'azure_newsletter_weekly_validation',
        'module' => 'Newsletter',
        'desc'   => 'Weekly email list validation',
    ),
    array(
        'hook'   => 'azure_newsletter_sync_mailgun_stats',
        'module' => 'Newsletter',
        'desc'   => 'Sync Mailgun statistics',
    ),
    array(
        'hook'   => 'azure_tickets_cleanup_reservations',
        'module' => 'Tickets',
        'desc'   => 'Clean up expired ticket reservations',
    ),
    array(
        'hook'   => 'azure_auction_finalize_orphans',
        'module' => 'Auction',
        'desc'   => 'Daily safety-net: finalize ended auctions that missed their one-shot event',
    ),
);

$all_crons = _get_cron_array();
if (!is_array($all_crons)) {
    $all_crons = array();
}

$cron_lookup = array();
foreach ($all_crons as $timestamp => $hooks) {
    foreach ($hooks as $hook => $events) {
        if (!isset($cron_lookup[$hook])) {
            $cron_lookup[$hook] = array();
        }
        foreach ($events as $key => $event) {
            $cron_lookup[$hook][] = array(
                'next_run'  => $timestamp,
                'schedule'  => $event['schedule'] ?? false,
                'interval'  => $event['interval'] ?? 0,
            );
        }
    }
}

$wp_timezone = wp_timezone();
?>

<div class="azure-schedules-dashboard">
    <h2 style="margin-top: 0;">
        <span class="dashicons dashicons-clock"></span> Plugin Scheduled Jobs
    </h2>
    <p class="description">All cron jobs registered by PTA Tools. You can manually trigger or view the next scheduled run time.</p>

    <table class="widefat striped" style="margin-top: 15px;">
        <thead>
            <tr>
                <th>Module</th>
                <th>Job</th>
                <th>Hook</th>
                <th>Schedule</th>
                <th>Next Run</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($plugin_crons as $cron): ?>
                <?php
                $is_scheduled = isset($cron_lookup[$cron['hook']]);
                $next_run = '';
                $schedule_label = '—';
                $interval_str = '';

                if ($is_scheduled && !empty($cron_lookup[$cron['hook']])) {
                    $entry = $cron_lookup[$cron['hook']][0];
                    $next_run_ts = $entry['next_run'];
                    $dt = new DateTime('@' . $next_run_ts);
                    $dt->setTimezone($wp_timezone);
                    $next_run = $dt->format('M j, Y g:i A T');
                    $schedule_label = $entry['schedule'] ?: 'One-time';
                    if ($entry['interval']) {
                        $mins = round($entry['interval'] / 60);
                        if ($mins >= 1440) {
                            $interval_str = round($mins / 1440) . 'd';
                        } elseif ($mins >= 60) {
                            $interval_str = round($mins / 60) . 'h';
                        } else {
                            $interval_str = $mins . 'm';
                        }
                    }
                }
                ?>
                <tr>
                    <td><strong><?php echo esc_html($cron['module']); ?></strong></td>
                    <td><?php echo esc_html($cron['desc']); ?></td>
                    <td><code style="font-size: 11px;"><?php echo esc_html($cron['hook']); ?></code></td>
                    <td>
                        <?php echo esc_html($schedule_label); ?>
                        <?php if ($interval_str): ?>
                            <span style="color: #666;">(<?php echo esc_html($interval_str); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $is_scheduled ? esc_html($next_run) : '<span style="color: #999;">—</span>'; ?></td>
                    <td>
                        <?php if ($is_scheduled): ?>
                            <span style="color: #00a32a; font-weight: 600;">&#9679; Active</span>
                        <?php else: ?>
                            <span style="color: #999;">&#9679; Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                                class="button button-small run-cron-now"
                                data-hook="<?php echo esc_attr($cron['hook']); ?>"
                                title="Run this job now">
                            <span class="dashicons dashicons-controls-play" style="vertical-align: text-bottom; font-size: 14px; width: 14px; height: 14px;"></span>
                            Run
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    $('.run-cron-now').on('click', function() {
        var $btn = $(this);
        var hook = $btn.data('hook');
        var originalHtml = $btn.html();

        if (!confirm('Run "' + hook + '" now?')) return;

        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0;"></span>');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_run_cron_now',
            nonce: azure_plugin_ajax.nonce,
            hook: hook
        }, function(response) {
            $btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                alert('Job executed successfully.');
                location.reload();
            } else {
                alert('Failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(originalHtml);
            alert('Network error.');
        });
    });
});
</script>

<style>
.azure-schedules-dashboard {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-top: 0;
}
.azure-schedules-dashboard .widefat td,
.azure-schedules-dashboard .widefat th {
    vertical-align: middle;
}
.azure-schedules-dashboard code {
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
}
</style>
