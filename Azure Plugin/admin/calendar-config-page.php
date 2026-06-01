<?php
/**
 * Calendar > Config tab
 *
 * Single landing page for everything a calendar admin has to wire up
 * before the Embed and Sync tabs become useful:
 *
 *   1. Microsoft 365 connection — the M365 account that signs in,
 *      the shared mailbox whose calendars we read, the Authenticate
 *      / Re-auth / Revoke buttons, and a clear status badge.
 *   2. Azure App credentials — either inherited from the global
 *      "Common Credentials" or overridden per-calendar. Includes the
 *      same Test Credentials affordance as the global Config page.
 *   3. Sync defaults — frequency + date window used by the global
 *      cron when at least one mapping has sync_enabled=1.
 *
 * Per-calendar display defaults (timezone, view, theme, cache) stay
 * on the Embed tab because they affect rendering, not connection.
 *
 * @package AzurePlugin
 * @since   3.115
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();

$use_common      = (bool) ($settings['use_common_credentials'] ?? true);
$cal_user_email  = (string) ($settings['calendar_embed_user_email'] ?? '');
$cal_mailbox     = (string) ($settings['calendar_embed_mailbox_email'] ?? '');
$cal_module_on   = !empty($settings['enable_calendar']);

$cal_creds = class_exists('Azure_Settings') ? Azure_Settings::get_credentials('calendar') : array(
    'client_id'     => '',
    'client_secret' => '',
    'tenant_id'     => '',
);

$cal_authenticated = false;
if ($cal_module_on && $cal_user_email && class_exists('Azure_Calendar_Auth')) {
    try {
        $auth_check        = new Azure_Calendar_Auth();
        $cal_authenticated = (bool) $auth_check->has_valid_user_token($cal_user_email);
    } catch (\Throwable $e) {
        $cal_authenticated = false;
    }
}

$show_auth_success = isset($_GET['auth']) && $_GET['auth'] === 'success';

$default_freq      = (string) ($settings['calendar_sync_default_frequency'] ?? 'hourly');
$default_lookback  = (int)    ($settings['calendar_sync_lookback_days']    ?? 30);
$default_lookahead = (int)    ($settings['calendar_sync_lookahead_days']   ?? 365);

$frequency_labels = array(
    '15min'      => __('Every 15 minutes', 'azure-plugin'),
    '30min'      => __('Every 30 minutes', 'azure-plugin'),
    'hourly'     => __('Hourly', 'azure-plugin'),
    'twicedaily' => __('Twice daily', 'azure-plugin'),
    'daily'      => __('Daily', 'azure-plugin'),
);
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-admin-wrap">
    <h1><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Calendar Config', 'azure-plugin'); ?></h1>
<?php endif; ?>

<div class="azure-admin-content">

    <?php if (!$cal_module_on): ?>
        <div class="notice notice-warning inline" style="padding:14px 16px;">
            <p>
                <strong><?php esc_html_e('Calendar module is disabled.', 'azure-plugin'); ?></strong>
                <?php esc_html_e('Enable the Calendar module from the PTA Tools Config screen, then return here to connect Microsoft 365.', 'azure-plugin'); ?>
                <a class="button" style="margin-left:8px;" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin')); ?>">
                    <?php esc_html_e('Open Plugin Config', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($show_auth_success): ?>
        <div class="notice notice-success is-dismissible" style="padding:10px 14px;">
            <p><strong><?php esc_html_e('Success!', 'azure-plugin'); ?></strong> <?php esc_html_e('Calendar authorization completed. You can now configure Embed and Sync.', 'azure-plugin'); ?></p>
        </div>
    <?php endif; ?>

    <!-- =========================================================
         1) Microsoft 365 connection
         ========================================================= -->
    <div class="azure-card">
        <h2 style="display:flex; align-items:center; gap:8px;">
            <span class="dashicons dashicons-admin-network"></span>
            <?php esc_html_e('Microsoft 365 Connection', 'azure-plugin'); ?>
        </h2>
        <p class="description">
            <?php esc_html_e('Sign in with the Microsoft 365 account that has delegated access to your shared mailbox. The Embed and Sync tabs both read this connection.', 'azure-plugin'); ?>
        </p>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="calendar_embed_user_email"><?php esc_html_e('Your M365 account', 'azure-plugin'); ?></label>
                </th>
                <td>
                    <input type="email"
                           id="calendar_embed_user_email"
                           name="calendar_embed_user_email"
                           value="<?php echo esc_attr($cal_user_email); ?>"
                           placeholder="admin@yourorg.net"
                           class="regular-text"
                           <?php disabled(!$cal_module_on); ?>>
                    <p class="description"><?php esc_html_e("The user that will sign in to Microsoft and grant delegated access. Not the shared mailbox.", 'azure-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="calendar_embed_mailbox_email"><?php esc_html_e('Shared mailbox email', 'azure-plugin'); ?></label>
                </th>
                <td>
                    <input type="email"
                           id="calendar_embed_mailbox_email"
                           name="calendar_embed_mailbox_email"
                           value="<?php echo esc_attr($cal_mailbox); ?>"
                           placeholder="calendar@yourorg.net"
                           class="regular-text"
                           <?php disabled(!$cal_module_on); ?>>
                    <p class="description"><?php esc_html_e('The mailbox that owns the calendars you want to embed / sync.', 'azure-plugin'); ?></p>
                    <button type="button" class="button button-primary" id="save-calendar-emails" style="margin-top:6px;" <?php disabled(!$cal_module_on); ?>>
                        <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save Connection', 'azure-plugin'); ?>
                    </button>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'azure-plugin'); ?></th>
                <td>
                    <div class="auth-status-display">
                        <?php if ($cal_authenticated): ?>
                            <span class="status-badge status-success" style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:3px; background:#e7f5ea; color:#1f6e2a;">
                                <span class="dashicons dashicons-yes-alt" style="color:#1f6e2a;"></span>
                                <?php
                                printf(
                                    /* translators: %s = M365 email address */
                                    esc_html__('Authenticated as %s', 'azure-plugin'),
                                    '<strong>' . esc_html($cal_user_email) . '</strong>'
                                );
                                ?>
                            </span>
                            <p class="description" style="margin-top:6px;">
                                <?php
                                printf(
                                    /* translators: %s = shared mailbox email address */
                                    esc_html__('Reading calendars from: %s', 'azure-plugin'),
                                    '<strong>' . esc_html($cal_mailbox) . '</strong>'
                                );
                                ?>
                            </p>
                            <div class="auth-actions-inline" style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="button button-secondary" id="calendar-reauth"><?php esc_html_e('Re-authenticate', 'azure-plugin'); ?></button>
                                <button type="button" class="button button-link-delete" id="revoke-calendar-auth"><?php esc_html_e('Revoke access', 'azure-plugin'); ?></button>
                            </div>
                        <?php else: ?>
                            <span class="status-badge status-error" style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:3px; background:#fdecea; color:#b32d2e;">
                                <span class="dashicons dashicons-warning" style="color:#b32d2e;"></span>
                                <?php esc_html_e('Not authenticated', 'azure-plugin'); ?>
                            </span>
                            <div class="auth-actions-inline" style="margin-top:10px;">
                                <?php if (!empty($cal_user_email) && !empty($cal_mailbox)): ?>
                                    <button type="button" class="button button-primary" id="calendar-auth" <?php disabled(!$cal_module_on); ?>>
                                        <span class="dashicons dashicons-admin-network"></span> <?php esc_html_e('Authenticate Calendar', 'azure-plugin'); ?>
                                    </button>
                                <?php else: ?>
                                    <p class="description"><?php esc_html_e('Enter and save both email addresses above, then authenticate.', 'azure-plugin'); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- =========================================================
         2) Azure App credentials (read-only or override)
         ========================================================= -->
    <div class="azure-card">
        <h2 style="display:flex; align-items:center; gap:8px;">
            <span class="dashicons dashicons-shield"></span>
            <?php esc_html_e('Azure App Credentials', 'azure-plugin'); ?>
        </h2>

        <?php if ($use_common): ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s = link to global PTA Tools Config */
                    esc_html__('Using common credentials from %s. To give Calendar its own App Registration, switch off "Use Common Credentials" on the main Config screen.', 'azure-plugin'),
                    '<a href="' . esc_url(admin_url('admin.php?page=azure-plugin#credentials-section')) . '">' . esc_html__('PTA Tools Config', 'azure-plugin') . '</a>'
                );
                ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Client ID', 'azure-plugin'); ?></th>
                    <td>
                        <code class="azure-readonly-cred"><?php echo esc_html($cal_creds['client_id'] ?: __('(not set)', 'azure-plugin')); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Tenant ID', 'azure-plugin'); ?></th>
                    <td>
                        <code class="azure-readonly-cred"><?php echo esc_html($cal_creds['tenant_id'] ?: 'common'); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Client Secret', 'azure-plugin'); ?></th>
                    <td>
                        <code class="azure-readonly-cred"><?php echo $cal_creds['client_secret'] ? esc_html(str_repeat('•', 18)) : esc_html__('(not set)', 'azure-plugin'); ?></code>
                    </td>
                </tr>
            </table>
        <?php else: ?>
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                <p class="description">
                    <?php esc_html_e('Common Credentials are disabled — Calendar uses its own Azure App Registration below.', 'azure-plugin'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="calendar_client_id"><?php esc_html_e('Client ID', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="text" name="calendar_client_id" id="calendar_client_id"
                                   value="<?php echo esc_attr($settings['calendar_client_id'] ?? ''); ?>"
                                   class="regular-text">
                            <p class="description"><?php esc_html_e('Calendar-specific Azure App Registration Client ID.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calendar_client_secret"><?php esc_html_e('Client Secret', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="password" name="calendar_client_secret" id="calendar_client_secret"
                                   value="<?php echo esc_attr($settings['calendar_client_secret'] ?? ''); ?>"
                                   class="regular-text" autocomplete="new-password">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calendar_tenant_id"><?php esc_html_e('Tenant ID', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="text" name="calendar_tenant_id" id="calendar_tenant_id"
                                   value="<?php echo esc_attr($settings['calendar_tenant_id'] ?? 'common'); ?>"
                                   class="regular-text">
                            <p class="description"><?php esc_html_e("Tenant ID, or 'common' for multi-tenant.", 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button type="button" class="button test-credentials"
                                    data-client-id-field="calendar_client_id"
                                    data-client-secret-field="calendar_client_secret"
                                    data-tenant-id-field="calendar_tenant_id">
                                <?php esc_html_e('Test Credentials', 'azure-plugin'); ?>
                            </button>
                            <span class="credentials-status"></span>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="azure_plugin_submit" class="button button-primary">
                        <?php esc_html_e('Save Credentials', 'azure-plugin'); ?>
                    </button>
                </p>
            </form>
        <?php endif; ?>
    </div>

    <!-- =========================================================
         3) Sync defaults
         ========================================================= -->
    <div class="azure-card">
        <h2 style="display:flex; align-items:center; gap:8px;">
            <span class="dashicons dashicons-clock"></span>
            <?php esc_html_e('Sync Defaults', 'azure-plugin'); ?>
        </h2>
        <p class="description">
            <?php esc_html_e('These defaults drive the global Outlook → pta_event cron when at least one mapping has Sync enabled. Per-mapping schedules on the Sync tab override the global cadence.', 'azure-plugin'); ?>
        </p>
        <form method="post" action="">
            <?php wp_nonce_field('azure_plugin_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="calendar_sync_default_frequency"><?php esc_html_e('Default frequency', 'azure-plugin'); ?></label></th>
                    <td>
                        <select name="calendar_sync_default_frequency" id="calendar_sync_default_frequency">
                            <?php foreach ($frequency_labels as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($default_freq, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="calendar_sync_lookback_days"><?php esc_html_e('Look back', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="number" name="calendar_sync_lookback_days" id="calendar_sync_lookback_days"
                               value="<?php echo esc_attr($default_lookback); ?>" min="0" max="3650" style="width:90px;">
                        <?php esc_html_e('days', 'azure-plugin'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="calendar_sync_lookahead_days"><?php esc_html_e('Look ahead', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="number" name="calendar_sync_lookahead_days" id="calendar_sync_lookahead_days"
                               value="<?php echo esc_attr($default_lookahead); ?>" min="0" max="3650" style="width:90px;">
                        <?php esc_html_e('days', 'azure-plugin'); ?>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="azure_plugin_submit" class="button button-primary">
                    <?php esc_html_e('Save Sync Defaults', 'azure-plugin'); ?>
                </button>
            </p>
        </form>
    </div>

</div>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<style>
.azure-readonly-cred {
    display: inline-block;
    padding: 4px 8px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 3px;
    color: #1d2327;
    font-family: Menlo, Consolas, monospace;
}
.azure-card .status-badge { font-weight: 600; }
</style>

<script>
jQuery(function ($) {
    var nonce = (window.azure_plugin_ajax && azure_plugin_ajax.nonce) ? azure_plugin_ajax.nonce : '';
    var ajaxUrl = (window.azure_plugin_ajax && azure_plugin_ajax.ajax_url) ? azure_plugin_ajax.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');

    $('#save-calendar-emails').on('click', function () {
        var $btn = $(this);
        var userEmail = $('#calendar_embed_user_email').val();
        var mailboxEmail = $('#calendar_embed_mailbox_email').val();
        if (!userEmail) { alert('Enter your M365 account email.'); return; }
        if (!mailboxEmail) { alert('Enter the shared mailbox email.'); return; }

        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Saving...');
        $.post(ajaxUrl, {
            action: 'azure_save_calendar_embed_email',
            user_email: userEmail,
            mailbox_email: mailboxEmail,
            nonce: nonce
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                alert('Failed to save: ' + (resp && resp.data ? resp.data : 'Unknown error'));
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Connection');
            }
        }).fail(function () {
            alert('Network error saving calendar connection.');
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Connection');
        });
    });

    $('#calendar-auth, #calendar-reauth').on('click', function () {
        var $btn = $(this);
        var userEmail = $('#calendar_embed_user_email').val();
        if (!userEmail) { alert('Save your M365 account email first.'); return; }
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Redirecting...');
        $.post(ajaxUrl, {
            action: 'azure_calendar_embed_authorize',
            user_email: userEmail,
            nonce: nonce
        }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.auth_url) {
                window.location.href = resp.data.auth_url;
            } else {
                alert('Failed to start authorization: ' + (resp && resp.data ? resp.data : 'Unknown error'));
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-network"></span> Authenticate Calendar');
            }
        }).fail(function () {
            alert('Network error starting authorization.');
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-network"></span> Authenticate Calendar');
        });
    });

    $('#revoke-calendar-auth').on('click', function () {
        if (!window.confirm('Revoke calendar access? You will need to re-authenticate before syncing or embedding.')) return;
        var $btn = $(this);
        var mailboxEmail = $('#calendar_embed_mailbox_email').val();
        $btn.prop('disabled', true).text('Revoking...');
        $.post(ajaxUrl, {
            action: 'azure_calendar_embed_revoke',
            email: mailboxEmail,
            nonce: nonce
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                alert('Failed to revoke: ' + (resp && resp.data ? resp.data : 'Unknown error'));
                $btn.prop('disabled', false).text('Revoke access');
            }
        }).fail(function () {
            alert('Network error during revoke.');
            $btn.prop('disabled', false).text('Revoke access');
        });
    });
});
</script>
