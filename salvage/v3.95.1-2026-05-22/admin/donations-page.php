<?php
/**
 * Donations Module Admin Page (tab inside Selling)
 */
if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
$module_enabled = !empty($settings['enable_donations']);
$campaigns = Azure_Donations_Module::get_all_campaigns();
$default_campaign = intval($settings['donations_default_campaign'] ?? 0);
$enable_roundup = !empty($settings['donations_enable_roundup']);
$enable_custom = !empty($settings['donations_enable_custom']);
$quick_amounts = $settings['donations_quick_amounts'] ?? '1,5,10';
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1><span class="dashicons dashicons-heart"></span> <?php _e('Donations', 'azure-plugin'); ?></h1>
<?php endif; ?>

<div class="azure-donations-page">

    <?php if (!$module_enabled): ?>
    <div class="notice notice-warning" style="margin: 15px 0;">
        <p><?php _e('The Donations module is currently disabled.', 'azure-plugin'); ?>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a></p>
    </div>
    <?php endif; ?>

    <!-- Settings -->
    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin:0 0 15px;"><span class="dashicons dashicons-admin-generic"></span> Donation Settings</h2>
        <table class="form-table" style="margin:0;">
            <tr>
                <th>Enable Round-Up</th>
                <td>
                    <label><input type="checkbox" id="donations_enable_roundup" <?php checked($enable_roundup); ?> /> Show "Round up to nearest dollar" toggle at checkout</label>
                </td>
            </tr>
            <tr>
                <th>Enable Custom Amount</th>
                <td>
                    <label><input type="checkbox" id="donations_enable_custom" <?php checked($enable_custom); ?> /> Show quick-pick donation buttons at checkout</label>
                </td>
            </tr>
            <tr>
                <th>Quick Amounts</th>
                <td>
                    <input type="text" id="donations_quick_amounts" value="<?php echo esc_attr($quick_amounts); ?>" class="regular-text" />
                    <p class="description">Comma-separated dollar amounts (e.g. 1,5,10,25)</p>
                </td>
            </tr>
            <tr>
                <th>Default Campaign</th>
                <td>
                    <select id="donations_default_campaign">
                        <option value="0">— First active campaign —</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php selected($default_campaign, $c->id); ?>><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Shortcode</th>
                <td>
                    <code>[pta-donate]</code>
                    <p class="description">Place on any page. Attributes: <code>campaign_id</code>, <code>amounts="5,10,25,50"</code>, <code>show_custom="yes"</code>, <code>button_text="Donate Now"</code></p>
                </td>
            </tr>
        </table>
        <button type="button" class="button button-primary save-donation-settings" style="margin-top:10px;">
            <span class="dashicons dashicons-saved" style="vertical-align:middle; line-height:1; margin-right:4px;"></span> Save Settings
        </button>
        <span id="donation-settings-result" style="display:none; margin-left:10px;"></span>
    </div>

    <!-- Campaigns -->
    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2 style="margin:0;"><span class="dashicons dashicons-megaphone"></span> Campaigns</h2>
            <button type="button" class="button button-primary add-campaign-btn">
                <span class="dashicons dashicons-plus-alt" style="vertical-align:middle; line-height:1; margin-right:4px;"></span> New Campaign
            </button>
        </div>

        <?php if (empty($campaigns)): ?>
            <p class="description">No campaigns yet. Create one to start accepting donations.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Goal</th>
                        <th>Raised</th>
                        <th>Progress</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                        <?php $pct = $c->goal_amount > 0 ? min(100, round(($c->raised_amount / $c->goal_amount) * 100)) : 0; ?>
                        <tr>
                            <td><strong><?php echo esc_html($c->name); ?></strong></td>
                            <td>
                                <?php if ($c->is_active): ?>
                                    <span style="color:#00a32a; font-weight:600;">&#9679; Active</span>
                                <?php else: ?>
                                    <span style="color:#999;">&#9679; Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $c->goal_amount > 0 ? '$' . number_format($c->goal_amount, 2) : '—'; ?></td>
                            <td>$<?php echo number_format($c->raised_amount, 2); ?></td>
                            <td>
                                <?php if ($c->goal_amount > 0): ?>
                                    <div style="background:#f0f0f1; border-radius:4px; height:16px; width:120px; display:inline-block; vertical-align:middle;">
                                        <div style="background:#2271b1; border-radius:4px; height:100%; width:<?php echo $pct; ?>%;"></div>
                                    </div>
                                    <span style="font-size:12px; margin-left:4px;"><?php echo $pct; ?>%</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small edit-campaign" data-id="<?php echo $c->id; ?>"
                                        data-name="<?php echo esc_attr($c->name); ?>"
                                        data-description="<?php echo esc_attr($c->description); ?>"
                                        data-goal="<?php echo esc_attr($c->goal_amount); ?>"
                                        data-active="<?php echo $c->is_active; ?>">Edit</button>
                                <button type="button" class="button button-small button-link-delete delete-campaign" data-id="<?php echo $c->id; ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Recent Donations -->
    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
        <h2 style="margin:0 0 15px;"><span class="dashicons dashicons-chart-bar"></span> Recent Donations</h2>
        <div id="donation-records-container">
            <p class="description">Loading...</p>
        </div>
    </div>
</div>

<!-- Campaign Modal -->
<div id="donation-campaign-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:100000; background:rgba(0,0,0,.5);">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:30px; border-radius:8px; width:500px; max-width:90%;">
        <h2 id="campaign-modal-title" style="margin:0 0 15px;">New Campaign</h2>
        <form id="campaign-form">
            <input type="hidden" id="campaign-id" value="0" />
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="campaign-name">Name *</label></th>
                    <td><input type="text" id="campaign-name" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="campaign-desc">Description</label></th>
                    <td><textarea id="campaign-desc" rows="3" class="large-text"></textarea></td>
                </tr>
                <tr>
                    <th><label for="campaign-goal">Goal Amount ($)</label></th>
                    <td><input type="number" id="campaign-goal" min="0" step="0.01" value="0" class="regular-text" />
                    <p class="description">Set to 0 for no goal / unlimited.</p></td>
                </tr>
                <tr>
                    <th>Active</th>
                    <td><label><input type="checkbox" id="campaign-active" checked /> Campaign is active</label></td>
                </tr>
            </table>
            <div style="margin-top:15px; display:flex; gap:10px;">
                <button type="submit" class="button button-primary">Save Campaign</button>
                <button type="button" class="button close-campaign-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(function($) {
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_js(wp_create_nonce('azure_plugin_nonce')); ?>';

    // ── Settings ──
    $('.save-donation-settings').on('click', function() {
        var $btn = $(this), $res = $('#donation-settings-result');
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span> Saving...');
        $res.hide();
        $.post(ajaxUrl, {
            action: 'azure_donations_save_settings',
            nonce: nonce,
            donations_enable_roundup: $('#donations_enable_roundup').is(':checked') ? '1' : '0',
            donations_enable_custom: $('#donations_enable_custom').is(':checked') ? '1' : '0',
            donations_quick_amounts: $('#donations_quick_amounts').val(),
            donations_default_campaign: $('#donations_default_campaign').val()
        }, function(r) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align:middle;line-height:1;margin-right:4px;"></span> Save Settings');
            $res.css('color', r.success ? '#00a32a' : '#d63638').text(r.success ? 'Saved!' : (r.data || 'Error')).show();
            if (r.success) setTimeout(function() { $res.fadeOut(); }, 3000);
        }).fail(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="vertical-align:middle;line-height:1;margin-right:4px;"></span> Save Settings');
            $res.css('color', '#d63638').text('Network error').show();
        });
    });

    // ── Campaign Modal ──
    function openModal(id, name, desc, goal, active) {
        $('#campaign-id').val(id || 0);
        $('#campaign-name').val(name || '');
        $('#campaign-desc').val(desc || '');
        $('#campaign-goal').val(goal || 0);
        $('#campaign-active').prop('checked', active !== 0);
        $('#campaign-modal-title').text(id ? 'Edit Campaign' : 'New Campaign');
        $('#donation-campaign-modal').show();
    }

    $('.add-campaign-btn').on('click', function() { openModal(); });
    $('.close-campaign-modal').on('click', function() { $('#donation-campaign-modal').hide(); });
    $('#donation-campaign-modal').on('click', function(e) { if (e.target === this) $(this).hide(); });

    $('.edit-campaign').on('click', function() {
        var $b = $(this);
        openModal($b.data('id'), $b.data('name'), $b.data('description'), $b.data('goal'), $b.data('active'));
    });

    $('#campaign-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Saving...');
        $.post(ajaxUrl, {
            action: 'azure_donations_save_campaign',
            nonce: nonce,
            id: $('#campaign-id').val(),
            name: $('#campaign-name').val(),
            description: $('#campaign-desc').val(),
            goal_amount: $('#campaign-goal').val(),
            is_active: $('#campaign-active').is(':checked') ? 1 : 0
        }, function(r) {
            if (r.success) {
                location.reload();
            } else {
                alert(r.data || 'Error saving campaign');
                $btn.prop('disabled', false).text('Save Campaign');
            }
        }).fail(function(xhr) {
            alert('Save failed: ' + xhr.status);
            $btn.prop('disabled', false).text('Save Campaign');
        });
    });

    // ── Delete Campaign ──
    $('.delete-campaign').on('click', function() {
        if (!confirm('Delete this campaign? Existing donation records will be kept.')) return;
        var $btn = $(this), id = $btn.data('id');
        $btn.prop('disabled', true);
        $.post(ajaxUrl, { action: 'azure_donations_delete_campaign', nonce: nonce, id: id }, function(r) {
            if (r.success) location.reload();
            else { alert(r.data || 'Error'); $btn.prop('disabled', false); }
        });
    });

    // ── Load Recent Donations ──
    function loadRecords() {
        $.post(ajaxUrl, { action: 'azure_donations_get_records', nonce: nonce }, function(r) {
            if (!r.success) { $('#donation-records-container').html('<p>Error loading records.</p>'); return; }
            var d = r.data, html = '';
            html += '<p style="margin:0 0 10px;"><strong>' + d.totals.total_count + '</strong> donations totaling <strong>$' + parseFloat(d.totals.total_amount).toFixed(2) + '</strong></p>';
            if (d.records.length === 0) {
                html += '<p class="description">No donations recorded yet.</p>';
            } else {
                html += '<table class="widefat striped"><thead><tr><th>Date</th><th>Campaign</th><th>Type</th><th>Amount</th><th>Order</th></tr></thead><tbody>';
                d.records.forEach(function(r) {
                    html += '<tr>';
                    html += '<td>' + r.created_at + '</td>';
                    html += '<td>' + (r.campaign_name || '—') + '</td>';
                    html += '<td>' + r.donation_type + '</td>';
                    html += '<td>$' + parseFloat(r.amount).toFixed(2) + '</td>';
                    html += '<td>' + (r.order_id ? '<a href="post.php?post=' + r.order_id + '&action=edit">#' + r.order_id + '</a>' : '—') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
            }
            $('#donation-records-container').html(html);
        });
    }
    loadRecords();
});
</script>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
