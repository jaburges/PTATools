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
$enable_amounts = Azure_Donations_Module::amounts_enabled();
$enable_gifts = Azure_Donations_Module::gift_products_enabled();
$quick_entries = Azure_Donations_Module::get_quick_amount_entries();
$gift_products = Azure_Donations_Module::get_gift_products();
$wc_products = array();
if (function_exists('wc_get_products')) {
    $wc_products = wc_get_products(array(
        'status'  => array('publish', 'private'),
        'limit'   => -1,
        'orderby' => 'title',
        'order'   => 'ASC',
    ));
}
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
                <th>Donation amounts</th>
                <td>
                    <label><input type="checkbox" id="donations_enable_custom" <?php checked($enable_amounts); ?> /> Show named donation options at checkout and on <code>[pta-donate]</code></label>
                    <p class="description" style="margin-top:8px;">Check Custom on a row so the donor can type their own amount. Turn this off to hide the amount buttons without deleting the list.</p>
                    <div id="donation-amount-rows"></div>
                    <p><button type="button" class="button add-quick-amount"><?php esc_html_e('Add amount', 'azure-plugin'); ?></button></p>
                    <template id="donation-amount-row-tpl">
                        <div class="donation-amount-row" style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                            <input type="text" class="amount-label regular-text" placeholder="Wolf Pack - $150 Per student" />
                            <input type="number" class="amount-value small-text" min="0" step="0.01" placeholder="150" style="width:90px;" />
                            <label style="white-space:nowrap;"><input type="checkbox" class="amount-custom" /> <?php esc_html_e('Custom', 'azure-plugin'); ?></label>
                            <button type="button" class="button-link-delete remove-amount-row"><?php esc_html_e('Remove', 'azure-plugin'); ?></button>
                        </div>
                    </template>
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
                <th>Gift products</th>
                <td>
                    <label><input type="checkbox" id="donations_enable_gift_products" <?php checked($enable_gifts); ?> /> Show gift-product buttons on <code>[pta-donate]</code></label>
                    <p class="description" style="margin-top:8px;">Adds a WooCommerce product to the cart and skips product fields (for donated memberships). Site admin is emailed on every donation. Turn this off to hide the buttons without deleting the list.</p>
                    <div id="donation-gift-rows">
                        <?php if (empty($gift_products)): ?>
                            <p class="description donation-gift-empty">No gift products yet. Add Individual and Staff membership here.</p>
                        <?php endif; ?>
                    </div>
                    <p><button type="button" class="button add-gift-product"><?php esc_html_e('Add gift product', 'azure-plugin'); ?></button></p>
                    <template id="donation-gift-row-tpl">
                        <div class="donation-gift-row" style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                            <input type="text" class="gift-label regular-text" placeholder="Donate an Individual PTA Membership" />
                            <select class="gift-product-id">
                                <option value="0">— Select product —</option>
                                <?php foreach ($wc_products as $product): ?>
                                    <option value="<?php echo (int) $product->get_id(); ?>"><?php echo esc_html($product->get_name()); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button-link-delete remove-gift-row">Remove</button>
                        </div>
                    </template>
                </td>
            </tr>
            <tr>
                <th>Shortcodes</th>
                <td>
                    <code>[pta-donate]</code>
                    <p class="description">Donation form. Uses Quick Amounts by default. Optional: <code>campaign_id</code>, <code>amounts="5,10,25,50"</code> (numeric override), <code>show_custom="yes"</code>, <code>button_text="Donate Now"</code></p>
                    <code>[donations-list]</code>
                    <p class="description">Public table: date, role (Parent / Staff / Guest), and product or amount. Never includes names or emails. Optional: <code>limit="25"</code></p>
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
    var existingGifts = <?php echo wp_json_encode($gift_products); ?>;
    var existingAmounts = <?php echo wp_json_encode($quick_entries); ?>;

    function addAmountRow(label, amount, isCustom) {
        var $tpl = $($('#donation-amount-row-tpl').html());
        $tpl.find('.amount-label').val(label || '');
        $tpl.find('.amount-value').val(isCustom ? '' : (amount || ''));
        $tpl.find('.amount-custom').prop('checked', !!isCustom);
        $tpl.find('.amount-value').prop('disabled', !!isCustom);
        $('#donation-amount-rows').append($tpl);
    }

    (existingAmounts || []).forEach(function(row) {
        addAmountRow(row.label, row.amount, row.custom);
    });

    $('.add-quick-amount').on('click', function() {
        addAmountRow('', '', false);
    });

    $('#donation-amount-rows').on('click', '.remove-amount-row', function() {
        $(this).closest('.donation-amount-row').remove();
    });

    $('#donation-amount-rows').on('change', '.amount-custom', function() {
        var $row = $(this).closest('.donation-amount-row');
        $row.find('.amount-value').prop('disabled', $(this).is(':checked')).val($(this).is(':checked') ? '' : $row.find('.amount-value').val());
    });

    function collectQuickAmounts() {
        var rows = [];
        $('#donation-amount-rows .donation-amount-row').each(function() {
            rows.push({
                label: $(this).find('.amount-label').val(),
                amount: parseFloat($(this).find('.amount-value').val()) || 0,
                custom: $(this).find('.amount-custom').is(':checked')
            });
        });
        return rows;
    }

    function addGiftRow(label, productId) {
        var $tpl = $($('#donation-gift-row-tpl').html());
        $tpl.find('.gift-label').val(label || '');
        $tpl.find('.gift-product-id').val(String(productId || 0));
        $('#donation-gift-rows .donation-gift-empty').remove();
        $('#donation-gift-rows').append($tpl);
    }

    (existingGifts || []).forEach(function(g) {
        addGiftRow(g.label, g.product_id);
    });

    $('.add-gift-product').on('click', function() {
        addGiftRow('', 0);
    });

    $('#donation-gift-rows').on('click', '.remove-gift-row', function() {
        $(this).closest('.donation-gift-row').remove();
        if (!$('#donation-gift-rows .donation-gift-row').length) {
            $('#donation-gift-rows').append('<p class="description donation-gift-empty">No gift products yet. Add Individual and Staff membership here.</p>');
        }
    });

    function collectGiftProducts() {
        var rows = [];
        $('#donation-gift-rows .donation-gift-row').each(function() {
            rows.push({
                label: $(this).find('.gift-label').val(),
                product_id: parseInt($(this).find('.gift-product-id').val(), 10) || 0
            });
        });
        return rows;
    }

    $('.save-donation-settings').on('click', function() {
        var $btn = $(this), $res = $('#donation-settings-result');
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span> Saving...');
        $res.hide();
        $.post(ajaxUrl, {
            action: 'azure_donations_save_settings',
            nonce: nonce,
            donations_enable_roundup: $('#donations_enable_roundup').is(':checked') ? '1' : '0',
            donations_enable_custom: $('#donations_enable_custom').is(':checked') ? '1' : '0',
            donations_enable_gift_products: $('#donations_enable_gift_products').is(':checked') ? '1' : '0',
            donations_quick_amounts: JSON.stringify(collectQuickAmounts()),
            donations_default_campaign: $('#donations_default_campaign').val(),
            donations_gift_products: JSON.stringify(collectGiftProducts())
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
                html += '<table class="widefat striped"><thead><tr><th>Date</th><th>Campaign</th><th>Role</th><th>Gift</th><th>Type</th><th>Order</th></tr></thead><tbody>';
                d.records.forEach(function(r) {
                    var gift = (r.product_name && String(r.product_name).trim()) ? r.product_name : ('$' + parseFloat(r.amount || 0).toFixed(2));
                    var role = r.donor_role === 'staff' ? 'Staff' : (r.donor_role === 'parent' ? 'Parent' : (r.donor_role ? 'Guest' : '—'));
                    html += '<tr>';
                    html += '<td>' + r.created_at + '</td>';
                    html += '<td>' + (r.campaign_name || '—') + '</td>';
                    html += '<td>' + role + '</td>';
                    html += '<td>' + gift + '</td>';
                    html += '<td>' + r.donation_type + '</td>';
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
