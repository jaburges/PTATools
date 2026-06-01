<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>PTA Tools - Main Settings</h1>
    
    <?php
    // Show setup wizard progress banner if not completed
    $wizard_class_exists = class_exists('Azure_Setup_Wizard');
    $wizard_completed = Azure_Settings::get_setting('setup_wizard_completed', false);
    $restore_completed = get_option('azure_restore_completed', false);
    
    if ($wizard_class_exists && !$wizard_completed && !$restore_completed):
        $wizard_progress = Azure_Setup_Wizard::get_wizard_progress();
    ?>
    <div class="setup-progress-banner">
        <h3><?php _e('Complete Your Setup', 'azure-plugin'); ?></h3>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo esc_attr($wizard_progress['percent']); ?>%"></div>
        </div>
        <p><?php printf(__('Step %d of %d completed', 'azure-plugin'), $wizard_progress['current_step'], $wizard_progress['total_steps']); ?></p>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-setup'); ?>" class="button button-primary">
            <?php _e('Continue Setup', 'azure-plugin'); ?>
        </a>
    </div>
    <?php endif; ?>
    
    <div class="azure-plugin-dashboard">
        <div class="azure-plugin-modules">
            <h2>Module Status</h2>
            
            <?php
            $calendar_enabled = !empty($settings['enable_calendar']);
            $selling_any = ($settings['enable_auction'] ?? false) || ($settings['enable_classes'] ?? false) || ($settings['enable_product_fields'] ?? false) || ($settings['enable_donations'] ?? false);
            ?>
            <div class="module-cards">
                <div class="module-card <?php echo $settings['enable_sso'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-admin-users"></span> SSO Authentication</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="sso" <?php checked($settings['enable_sso']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-sso'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Enable Azure AD Single Sign-On for user authentication.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_backup'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-backup"></span> Azure Backup</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="backup" <?php checked($settings['enable_backup']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-backup'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Backup your WordPress site to Azure Blob Storage.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $calendar_enabled ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-calendar-alt"></span> Calendar</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="calendar" <?php checked($settings['enable_calendar']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-calendar'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Calendar embed, native PTA event calendar (pta_event), upcoming events, and volunteer sign-ups.</p>
                        <div class="sub-modules">
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="volunteer" <?php checked($settings['enable_volunteer'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Volunteer Sign Up
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_email'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-email-alt"></span> Emails</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="email" <?php checked($settings['enable_email']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-emails'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Send emails through Microsoft Graph API with logging and tracking.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_pta'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-networking"></span> PTA Roles Manager</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="pta" <?php checked($settings['enable_pta']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-pta'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Manage PTA organizational structure with Azure AD sync.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_onedrive_media'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-cloud-upload"></span> OneDrive Media</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="onedrive_media" <?php checked($settings['enable_onedrive_media'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-onedrive-media'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Store WordPress media files in OneDrive/SharePoint with CDN optimization.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_newsletter'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-email-alt2"></span> Newsletter</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="newsletter" <?php checked($settings['enable_newsletter'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Create and send newsletters with drag-drop editor, tracking, and bounce handling.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_tickets'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-tickets-alt"></span> Event Tickets</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="tickets" <?php checked($settings['enable_tickets'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Visual seating designer, QR code tickets, Apple Wallet, and event check-in.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $selling_any ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-cart"></span> Selling</h3>
                        <div class="module-controls">
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-selling'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Auction, Classes, Product Fields, and Donations.</p>
                        <div class="sub-modules">
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="auction" <?php checked($settings['enable_auction'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Auction
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="classes" <?php checked($settings['enable_classes'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Classes
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="product_fields" <?php checked($settings['enable_product_fields'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Product Fields
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="donations" <?php checked($settings['enable_donations'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Donations
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="azure-plugin-site-behavior" style="margin-top: 20px;">
            <h2>Site Behavior</h2>
            <p class="description">WordPress-wide behavior toggles. These don't enable a module — they change how the site responds.</p>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <tbody>
                    <tr>
                        <td style="width: 40px; padding: 12px 10px;">
                            <label class="switch-mini">
                                <input type="checkbox" class="module-toggle" data-module="no_comments" <?php checked($settings['enable_no_comments'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <strong>Disable all comments</strong><br />
                            <span class="description">
                                Closes commenting and pingbacks on every post type, removes the Comments admin menu and toolbar item,
                                strips the <code>/wp/v2/comments</code> REST endpoints, and drops the comments RSS feed and pingback header.
                                Replaces the standalone "Disable All Comments" code snippet so WPCode is no longer required.
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="azure-plugin-dependencies" style="margin-top: 20px;">
            <h2>Plugin Dependencies</h2>
            <p class="description">These third-party plugins are required by various PTA Tools modules. Install and activate any that are needed for your enabled modules.</p>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Plugin</th>
                        <th style="width: 15%;">Status</th>
                        <th>Used By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Calendar/events were migrated off The Events Calendar to
                    // PTA Tools' native pta_event CPT in v3.86. Event Tickets
                    // (Tribe__Tickets__Main) is not referenced anywhere in
                    // this plugin's runtime; tickets are implemented natively
                    // (custom WC product type, QR codes, seating designer,
                    // check-in handler) under includes/class-tickets-*.php.
                    $dependencies = array(
                        array(
                            'name'    => 'WooCommerce',
                            'check'   => class_exists('WooCommerce'),
                            'modules' => 'Classes, Auction, Tickets, Product Fields, Donations',
                        ),
                        array(
                            'name'    => 'Forminator',
                            'check'   => class_exists('Forminator'),
                            'modules' => 'PTA Roles (Signup Forms)',
                        ),
                        array(
                            'name'    => 'Beaver Builder',
                            'check'   => class_exists('FLBuilder'),
                            'modules' => 'PTA Roles (Custom Modules)',
                        ),
                    );
                    foreach ($dependencies as $dep):
                        $status_class = $dep['check'] ? 'success' : 'error';
                        $status_label = $dep['check'] ? 'Active' : 'Not Installed';
                        $icon = $dep['check'] ? 'yes-alt' : 'warning';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($dep['name']); ?></strong></td>
                        <td>
                            <span class="dashicons dashicons-<?php echo $icon; ?>" style="color: <?php echo $dep['check'] ? '#46b450' : '#dc3232'; ?>; vertical-align: middle;"></span>
                            <span style="color: <?php echo $dep['check'] ? '#46b450' : '#dc3232'; ?>;"><?php echo $status_label; ?></span>
                        </td>
                        <td><?php echo esc_html($dep['modules']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="azure-plugin-settings">
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                
                <!-- Hidden inputs to mirror module toggle states -->
                <input type="hidden" name="enable_sso" id="hidden_enable_sso" value="<?php echo $settings['enable_sso'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_backup" id="hidden_enable_backup" value="<?php echo $settings['enable_backup'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_calendar" id="hidden_enable_calendar" value="<?php echo $settings['enable_calendar'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_email" id="hidden_enable_email" value="<?php echo $settings['enable_email'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_pta" id="hidden_enable_pta" value="<?php echo $settings['enable_pta'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_onedrive_media" id="hidden_enable_onedrive_media" value="<?php echo ($settings['enable_onedrive_media'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_classes" id="hidden_enable_classes" value="<?php echo ($settings['enable_classes'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_newsletter" id="hidden_enable_newsletter" value="<?php echo ($settings['enable_newsletter'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_tickets" id="hidden_enable_tickets" value="<?php echo ($settings['enable_tickets'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_auction" id="hidden_enable_auction" value="<?php echo ($settings['enable_auction'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_product_fields" id="hidden_enable_product_fields" value="<?php echo ($settings['enable_product_fields'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_volunteer" id="hidden_enable_volunteer" value="<?php echo ($settings['enable_volunteer'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_donations" id="hidden_enable_donations" value="<?php echo ($settings['enable_donations'] ?? false) ? '1' : '0'; ?>" />
                
                <div class="credentials-section">
                    <h2>Azure Credentials</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Use Common Credentials</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="use_common_credentials" id="use_common_credentials" <?php checked($settings['use_common_credentials'] ?? true); ?> />
                                    Use the same Azure credentials for all enabled modules
                                </label>
                                <p class="description">When enabled, all modules will use the common credentials below. When disabled, each module can have its own credentials.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="common-credentials" <?php echo !($settings['use_common_credentials'] ?? true) ? 'style="display:none;"' : ''; ?>>
                        <h3>Common Credentials</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Client ID</th>
                                <td>
                                    <input type="text" name="common_client_id" id="common_client_id" value="<?php echo esc_attr($settings['common_client_id'] ?? ''); ?>" class="regular-text" />
                                    <p class="description">Your Azure App Registration Client ID</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Client Secret</th>
                                <td>
                                    <input type="password" name="common_client_secret" id="common_client_secret" value="<?php echo esc_attr($settings['common_client_secret'] ?? ''); ?>" class="regular-text" />
                                    <p class="description">Your Azure App Registration Client Secret</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Tenant ID</th>
                                <td>
                                    <input type="text" name="common_tenant_id" id="common_tenant_id" value="<?php echo esc_attr($settings['common_tenant_id'] ?? 'common'); ?>" class="regular-text" />
                                    <p class="description">Your Azure Tenant ID (or 'common' for multi-tenant)</p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <button type="button" class="button test-credentials" 
                                        data-client-id-field="common_client_id" 
                                        data-client-secret-field="common_client_secret" 
                                        data-tenant-id-field="common_tenant_id">
                                        Test Credentials
                                    </button>
                                    <span class="credentials-status"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php
                // ── Calendar Sync Connection ────────────────────────
                // Consolidated M365 sign-in for the Calendar Embed and
                // Calendar Sync tabs. Reuses the existing AJAX
                // handlers in class-admin.php
                // (azure_save_calendar_embed_email,
                //  azure_calendar_embed_authorize,
                //  azure_calendar_embed_revoke).
                $calendar_module_enabled = !empty($settings['enable_calendar']);
                $cal_user_email          = (string) ($settings['calendar_embed_user_email'] ?? '');
                $cal_mailbox_email       = (string) ($settings['calendar_embed_mailbox_email'] ?? '');
                $cal_authenticated       = false;
                if ($calendar_module_enabled && !empty($cal_user_email) && class_exists('Azure_Calendar_Auth')) {
                    try {
                        $cal_auth_check    = new Azure_Calendar_Auth();
                        $cal_authenticated = (bool) $cal_auth_check->has_valid_user_token($cal_user_email);
                    } catch (\Throwable $e) {
                        $cal_authenticated = false;
                    }
                }
                ?>
                <div class="credentials-section calendar-sync-connection" style="margin-top:24px;">
                    <h2><span class="dashicons dashicons-calendar-alt"></span> Calendar Sync Connection</h2>
                    <?php if (!$calendar_module_enabled): ?>
                        <p class="description" style="margin-top:8px;">
                            Enable the Calendar module above to configure the M365 calendar sign-in used by both Calendar Embed and Calendar Sync.
                        </p>
                    <?php else: ?>
                        <p class="description" style="margin-top:8px;">
                            Sign in once here with the M365 account that has delegated access to the shared mailbox. The Calendar Embed and Calendar Sync tabs both read this connection.
                        </p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="calendar_embed_user_email">Your M365 account</label></th>
                                <td>
                                    <input type="email"
                                           id="calendar_embed_user_email"
                                           name="calendar_embed_user_email"
                                           value="<?php echo esc_attr($cal_user_email); ?>"
                                           placeholder="admin@yourorg.net"
                                           class="regular-text">
                                    <p class="description">The Microsoft 365 account you'll sign in with (delegated access only).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="calendar_embed_mailbox_email">Shared mailbox email</label></th>
                                <td>
                                    <input type="email"
                                           id="calendar_embed_mailbox_email"
                                           name="calendar_embed_mailbox_email"
                                           value="<?php echo esc_attr($cal_mailbox_email); ?>"
                                           placeholder="calendar@yourorg.net"
                                           class="regular-text">
                                    <p class="description">The shared mailbox whose calendars you want to embed/sync.</p>
                                    <button type="button" class="button button-primary" id="save-calendar-emails" style="margin-top:6px;">
                                        <span class="dashicons dashicons-saved"></span> Save Connection
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Status</th>
                                <td>
                                    <div class="auth-status-display">
                                        <?php if ($cal_authenticated): ?>
                                            <span class="status-badge status-success">
                                                <span class="dashicons dashicons-yes-alt" style="color:#1f6e2a;"></span>
                                                Authenticated as <strong><?php echo esc_html($cal_user_email); ?></strong>
                                            </span>
                                            <p class="description" style="margin-top:4px;">
                                                Reading calendars from: <strong><?php echo esc_html($cal_mailbox_email); ?></strong>
                                            </p>
                                            <div class="auth-actions-inline" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                                <button type="button" class="button button-secondary" id="calendar-reauth">Re-authenticate</button>
                                                <button type="button" class="button button-link-delete" id="revoke-calendar-auth">Revoke access</button>
                                            </div>
                                        <?php else: ?>
                                            <span class="status-badge status-error">
                                                <span class="dashicons dashicons-warning" style="color:#b32d2e;"></span>
                                                Not authenticated
                                            </span>
                                            <div class="auth-actions-inline" style="margin-top:8px;">
                                                <?php if (!empty($cal_user_email) && !empty($cal_mailbox_email)): ?>
                                                    <button type="button" class="button button-primary" id="calendar-auth">
                                                        <span class="dashicons dashicons-admin-network"></span> Authenticate Calendar
                                                    </button>
                                                <?php else: ?>
                                                    <p class="description">Enter and save both email addresses above, then authenticate.</p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>

                <script>
                jQuery(function ($) {
                    function ajaxNonce() {
                        return (window.azure_plugin_ajax && azure_plugin_ajax.nonce) ? azure_plugin_ajax.nonce : '';
                    }
                    function ajaxUrl() {
                        return (window.azure_plugin_ajax && azure_plugin_ajax.ajax_url) ? azure_plugin_ajax.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
                    }

                    $('#save-calendar-emails').on('click', function () {
                        var $btn = $(this);
                        var userEmail = $('#calendar_embed_user_email').val();
                        var mailboxEmail = $('#calendar_embed_mailbox_email').val();
                        if (!userEmail) { alert('Enter your M365 account email.'); return; }
                        if (!mailboxEmail) { alert('Enter the shared mailbox email.'); return; }

                        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Saving...');
                        $.post(ajaxUrl(), {
                            action: 'azure_save_calendar_embed_email',
                            user_email: userEmail,
                            mailbox_email: mailboxEmail,
                            nonce: ajaxNonce()
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
                        $.post(ajaxUrl(), {
                            action: 'azure_calendar_embed_authorize',
                            user_email: userEmail,
                            nonce: ajaxNonce()
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
                        if (!window.confirm('Revoke calendar access? You will need to re-authenticate before syncing or embedding calendars.')) return;
                        var $btn = $(this);
                        var mailboxEmail = $('#calendar_embed_mailbox_email').val();
                        $btn.prop('disabled', true).text('Revoking...');
                        $.post(ajaxUrl(), {
                            action: 'azure_calendar_embed_revoke',
                            email: mailboxEmail,
                            nonce: ajaxNonce()
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
                
                <div class="debug-section" style="margin-top: 20px;">
                    <h2>Debug Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="debug_mode">Debug Mode</label>
                            </th>
                            <td>
                                <input type="checkbox" id="debug_mode" name="debug_mode" value="1" 
                                       <?php checked($settings['debug_mode'] ?? false); ?> />
                                <label for="debug_mode">Enable detailed debug logging</label>
                                <p class="description">
                                    ⚠️ <strong>Warning:</strong> Only enable for troubleshooting. Requires WP_DEBUG to be enabled in wp-config.php. 
                                    <br>Impacts performance when enabled. Logs are written to <code>wp-content/plugins/PTA Tools/logs.md</code>
                                </p>
                            </td>
                        </tr>
                        
                        <tr id="debug-modules-row" style="<?php echo ($settings['debug_mode'] ?? false) ? '' : 'display:none;'; ?>">
                            <th scope="row">Debug Modules</th>
                            <td>
                                <?php
                                $debug_modules = $settings['debug_modules'] ?? array();
                                $available_modules = array('Core', 'SSO', 'Calendar', 'Email', 'Backup', 'PTA', 'OneDrive');
                                foreach ($available_modules as $module):
                                ?>
                                <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                                    <input type="checkbox" name="debug_modules[]" value="<?php echo esc_attr($module); ?>"
                                           <?php checked(in_array($module, $debug_modules)); ?> />
                                    <?php echo esc_html($module); ?>
                                </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    Select specific modules to debug. Leave all unchecked to debug all modules.
                                    <br><strong>Tip:</strong> Enable only the module you're troubleshooting to reduce log noise.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-section" style="margin-top: 20px;">
                    <h3>Platform (deployment slots)</h3>
                    <p class="description" style="margin-bottom: 12px;">
                        Used by the <strong>Sync Prod DB → Staging DB</strong> action under <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=critical')); ?>">System → Critical → Danger Zone</a>.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="platform_staging_database_name">Staging database name</label></th>
                            <td>
                                <input type="text" name="platform_staging_database_name" id="platform_staging_database_name"
                                       value="<?php echo esc_attr($settings['platform_staging_database_name'] ?? ''); ?>"
                                       class="regular-text" placeholder="<?php echo esc_attr(DB_NAME . '_staging'); ?>" />
                                <p class="description">Optional. Leave blank to auto-detect from the production database name.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="platform_staging_site_url">Staging site URL</label></th>
                            <td>
                                <input type="text" name="platform_staging_site_url" id="platform_staging_site_url"
                                       value="<?php echo esc_attr($settings['platform_staging_site_url'] ?? ''); ?>"
                                       class="regular-text" placeholder="https://yoursite-staging.azurewebsites.net" />
                                <p class="description">Used for URL search-replace after DB sync.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save Settings" />
                </p>
            </form>
        </div>

        <div class="azure-plugin-info">
            <div class="info-box">
                <h3>Quick Setup Guide</h3>
                <ol>
                    <li>Create an Azure App Registration in your Azure portal</li>
                    <li>Add the required API permissions for the modules you want to use</li>
                    <li>Copy the Client ID, Client Secret, and Tenant ID to the credentials section above</li>
                    <li>Enable the modules you want to use</li>
                    <li>Configure each module using the links above</li>
                </ol>
            </div>
            
            <div class="info-box">
                <h3>Required API Permissions</h3>
                <ul>
                    <li><strong>SSO:</strong> User.Read, openid, profile, email</li>
                    <li><strong>Backup:</strong> Files.ReadWrite.All (for backup storage)</li>
                    <li><strong>Calendar:</strong> Calendar.Read, Calendar.ReadWrite</li>
                    <li><strong>Email:</strong> Mail.Send, Mail.ReadWrite</li>
                </ul>
            </div>
            
            <div class="info-box">
                <h3>Support & Documentation</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=azure-plugin-system'); ?>" class="button">View Logs</a>
                    <a href="#" class="button" onclick="location.reload();">Refresh Status</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle common credentials toggle
    $('#use_common_credentials').change(function() {
        if ($(this).is(':checked')) {
            $('#common-credentials').slideDown();
        } else {
            $('#common-credentials').slideUp();
        }
    });
    
    // Handle debug mode toggle
    $('#debug_mode').on('change', function() {
        if ($(this).is(':checked')) {
            $('#debug-modules-row').slideDown('fast');
        } else {
            $('#debug-modules-row').slideUp('fast');
        }
    });
    
    // Sync hidden form inputs when module toggles change. The actual AJAX save
    // is handled centrally by admin.js — this handler ONLY keeps the hidden
    // form inputs in sync so that if the user later clicks "Save Settings"
    // the form POST does not accidentally revert module states. Having a
    // second AJAX request fire here was causing race conditions where a
    // duplicate save would occasionally clobber the first (most visibly on
    // the PTA module toggle).
    $(document).on('change', '.module-toggle', function() {
        var module = $(this).data('module');
        var enabled = $(this).is(':checked');
        if (module) {
            $('#hidden_enable_' + module).val(enabled ? '1' : '0');
        }
    });
    
    // Handle credentials test
    $('.test-credentials').click(function() {
        var button = $(this);
        var status = button.siblings('.credentials-status');
        var clientIdField = $('#' + button.data('client-id-field'));
        var clientSecretField = $('#' + button.data('client-secret-field'));
        var tenantIdField = $('#' + button.data('tenant-id-field'));
        
        button.prop('disabled', true).text('Testing...');
        status.html('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_test_credentials',
                client_id: clientIdField.val(),
                client_secret: clientSecretField.val(),
                tenant_id: tenantIdField.val(),
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
            button.prop('disabled', false).text('Test Credentials');
            
            console.log('Test Credentials Response:', response);
            console.log('Response type:', typeof response);
            console.log('Response keys:', response ? Object.keys(response) : 'null');
            
            // Handle string responses (jQuery might not parse as JSON if there's whitespace)
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response.trim());
                    console.log('Parsed string response:', response);
                } catch (e) {
                    console.error('Failed to parse response:', e);
                    status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> Invalid JSON response from server');
                    setTimeout(function() { status.fadeOut(); }, 5000);
                    return;
                }
            }
            
            // WordPress AJAX format: response.success, response.data
            if (response && typeof response === 'object' && ('success' in response || response.hasOwnProperty('success'))) {
                if (response.success === true || response.success === 'true') {
                    // Success response: response.data contains the validation result
                    var message = (response.data && response.data.message) || 'Credentials are valid';
                    status.html('<span class="dashicons dashicons-yes-alt" style="color: green; font-weight: bold;"></span> <strong style="color: green;">' + message + '</strong>');
                    status.show(); // Keep it visible permanently
                } else {
                    // Error response: response.data is the error message
                    var errorMsg = typeof response.data === 'string' ? response.data : (response.data ? JSON.stringify(response.data) : 'Credentials validation failed');
                    status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> <strong style="color: red;">' + errorMsg + '</strong>');
                    // Only fade out errors after 8 seconds
                    setTimeout(function() {
                        status.fadeOut();
                    }, 8000);
                }
            } else {
                console.error('Invalid response structure:', response);
                status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> Invalid response from server. Check console for details.');
                setTimeout(function() {
                    status.fadeOut();
                }, 8000);
            }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text('Test Credentials');
                console.error('AJAX Error:', xhr.responseText);
                status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> <strong style="color: red;">Network error: ' + (error || 'Unknown error') + '</strong>');
                
                setTimeout(function() {
                    status.fadeOut();
                }, 8000);
            }
        });
    });
});
</script>