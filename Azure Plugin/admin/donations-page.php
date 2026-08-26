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
$enable_wag = Azure_Donations_Module::wag_enabled();
$wag_campaign = Azure_Donations_Module::get_wag_campaign_id();
$quick_entries = Azure_Donations_Module::get_quick_amount_entries();
$gift_products = Azure_Donations_Module::get_gift_products();
$wag_levels = Azure_Donations_Module::get_wag_levels();
$wag_heading = (string) Azure_Settings::get_setting('donations_wag_heading', '');
if ($wag_heading === '') {
    $wag_heading = Azure_Donations_Module::default_wag_heading();
}
$wag_label = (string) Azure_Settings::get_setting('donations_wag_label', '');
if ($wag_label === '') {
    $wag_label = Azure_Donations_Module::WAG_DEFAULT_LABEL;
}
$wag_footer = (string) Azure_Settings::get_setting('donations_wag_footer', '');
if ($wag_footer === '') {
    $wag_footer = Azure_Donations_Module::WAG_DEFAULT_FOOTER;
}
$wag_bg = Azure_Donations_Module::get_wag_bg();
$wag_fg = Azure_Donations_Module::get_wag_fg();
if (strlen(ltrim($wag_bg, '#')) === 3) {
    $h = ltrim($wag_bg, '#');
    $wag_bg = '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
}
if (strlen(ltrim($wag_fg, '#')) === 3) {
    $h = ltrim($wag_fg, '#');
    $wag_fg = '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
}
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
                <th>Donation Items</th>
                <td>
                    <label><input type="checkbox" id="donations_enable_wag" <?php checked($enable_wag); ?> /> Show suggested giving levels via <code>[WAG]</code></label>
                    <p class="description" style="margin-top:8px;">Three buttons mapped to a WooCommerce product variation. Clicking a button opens that item with the variation already selected. Turn this off to hide the shortcode without deleting the mappings. Purchases of the mapped products still count toward the campaign below, even if the buyer never used <code>[WAG]</code>.</p>
                    <p style="margin-top:10px;">
                        <label for="donations_wag_campaign"><strong><?php esc_html_e('Campaign', 'azure-plugin'); ?></strong></label><br />
                        <select id="donations_wag_campaign">
                            <option value="0">— Select campaign —</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo (int) $c->id; ?>" <?php selected($wag_campaign, (int) $c->id); ?>><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <div id="wag-settings" style="<?php echo $enable_wag ? '' : 'display:none;'; ?> margin-top:12px;">
                        <p>
                            <label for="donations_wag_heading"><strong><?php esc_html_e('Heading', 'azure-plugin'); ?></strong></label><br />
                            <input type="text" id="donations_wag_heading" class="large-text" value="<?php echo esc_attr($wag_heading); ?>" />
                        </p>
                        <p>
                            <label for="donations_wag_label"><strong><?php esc_html_e('Section label', 'azure-plugin'); ?></strong></label><br />
                            <input type="text" id="donations_wag_label" class="regular-text" value="<?php echo esc_attr($wag_label); ?>" />
                        </p>
                        <p>
                            <label for="donations_wag_footer"><strong><?php esc_html_e('Footer', 'azure-plugin'); ?></strong></label><br />
                            <input type="text" id="donations_wag_footer" class="large-text" value="<?php echo esc_attr($wag_footer); ?>" />
                        </p>
                        <p style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
                            <label>
                                <strong><?php esc_html_e('Background', 'azure-plugin'); ?></strong><br />
                                <input type="color" id="donations_wag_bg" value="<?php echo esc_attr($wag_bg); ?>" />
                            </label>
                            <label>
                                <strong><?php esc_html_e('Foreground text', 'azure-plugin'); ?></strong><br />
                                <input type="color" id="donations_wag_fg" value="<?php echo esc_attr($wag_fg); ?>" />
                            </label>
                            <span class="description"><?php esc_html_e('Background is the navy fill, heading, and borders. Foreground is the text on the highlighted button.', 'azure-plugin'); ?></span>
                        </p>
                        <table class="widefat striped" style="max-width:960px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Amount', 'azure-plugin'); ?></th>
                                    <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                                    <th><?php esc_html_e('Suffix', 'azure-plugin'); ?></th>
                                    <th><?php esc_html_e('Product', 'azure-plugin'); ?></th>
                                    <th><?php esc_html_e('Variation', 'azure-plugin'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="wag-level-rows">
                                <?php foreach ($wag_levels as $i => $level): ?>
                                    <tr class="wag-level-row" data-variation-id="<?php echo (int) $level['variation_id']; ?>">
                                        <td><input type="number" class="wag-amount small-text" min="0" step="0.01" value="<?php echo esc_attr($level['amount']); ?>" style="width:90px;" /></td>
                                        <td><input type="text" class="wag-name regular-text" value="<?php echo esc_attr($level['name']); ?>" /></td>
                                        <td><input type="text" class="wag-suffix" value="<?php echo esc_attr($level['suffix']); ?>" style="width:120px;" /></td>
                                        <td>
                                            <select class="wag-product-id">
                                                <option value="0">— Select product —</option>
                                                <?php foreach ($wc_products as $product): ?>
                                                    <option value="<?php echo (int) $product->get_id(); ?>" <?php selected((int) $level['product_id'], (int) $product->get_id()); ?>><?php echo esc_html($product->get_name()); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="wag-variation-id">
                                                <option value="0">— Select variation —</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e('The first button is shown filled; the other two use an outline. Map each row to the donation product and the matching variation.', 'azure-plugin'); ?></p>
                    </div>
                </td>
            </tr>
            <tr>
                <th>Shortcodes</th>
                <td>
                    <code>[pta-donate]</code>
                    <p class="description">Donation form. Uses Quick Amounts by default. Optional: <code>campaign_id</code>, <code>amounts="5,10,25,50"</code> (numeric override), <code>show_custom="yes"</code>, <code>button_text="Donate Now"</code></p>
                    <code>[WAG]</code>
                    <p class="description">Suggested giving levels from Donation Items above. Hidden when Donation Items is disabled.</p>
                    <code>[Donation-progress campaign="WAG"]</code>
                    <p class="description">Horizontal thermometer for a campaign total, including Donation Item purchases. <code>campaign="WAG"</code> uses the Donation Items campaign. You can also pass a campaign name or numeric id.</p>
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
                        <?php
                        $raised = Azure_Donations_Module::get_campaign_raised($c);
                        $pct = $c->goal_amount > 0 ? min(100, round(($raised / $c->goal_amount) * 100)) : 0;
                        ?>
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
                            <td>$<?php echo number_format($raised, 2); ?></td>
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

    function toggleWagFields() {
        $('#wag-settings').toggle($('#donations_enable_wag').is(':checked'));
    }
    $('#donations_enable_wag').on('change', toggleWagFields);

    function loadWagVariations($row, selectedId) {
        var productId = parseInt($row.find('.wag-product-id').val(), 10) || 0;
        var $sel = $row.find('.wag-variation-id');
        if (!productId) {
            $sel.html('<option value="0">— Select a product first —</option>');
            return;
        }
        $sel.html('<option value="0">Loading…</option>');
        $.post(ajaxUrl, {
            action: 'azure_donations_get_variations',
            nonce: nonce,
            product_id: productId
        }, function(r) {
            var list = (r && r.success && $.isArray(r.data)) ? r.data : [];
            var html;
            if (!list.length) {
                html = '<option value="0">— No variations (simple product) —</option>';
            } else {
                html = '<option value="0">— Select variation —</option>';
                list.forEach(function(v) {
                    var id = parseInt(v.id, 10) || 0;
                    var sel = String(id) === String(selectedId) ? ' selected' : '';
                    html += '<option value="' + id + '"' + sel + '>' + $('<div>').text(v.label || ('#' + id)).html() + '</option>';
                });
            }
            $sel.html(html);
        }).fail(function() {
            $sel.html('<option value="0">— Could not load variations —</option>');
        });
    }

    $('#wag-level-rows .wag-level-row').each(function() {
        var $row = $(this);
        loadWagVariations($row, $row.attr('data-variation-id') || 0);
    });

    $('#wag-level-rows').on('change', '.wag-product-id', function() {
        loadWagVariations($(this).closest('.wag-level-row'), 0);
    });

    function collectWagLevels() {
        var rows = [];
        $('#wag-level-rows .wag-level-row').each(function() {
            rows.push({
                amount: parseFloat($(this).find('.wag-amount').val()) || 0,
                name: $(this).find('.wag-name').val(),
                suffix: $(this).find('.wag-suffix').val(),
                product_id: parseInt($(this).find('.wag-product-id').val(), 10) || 0,
                variation_id: parseInt($(this).find('.wag-variation-id').val(), 10) || 0
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
            donations_enable_wag: $('#donations_enable_wag').is(':checked') ? '1' : '0',
            donations_quick_amounts: JSON.stringify(collectQuickAmounts()),
            donations_default_campaign: $('#donations_default_campaign').val(),
            donations_wag_campaign: $('#donations_wag_campaign').val(),
            donations_gift_products: JSON.stringify(collectGiftProducts()),
            donations_wag_heading: $('#donations_wag_heading').val(),
            donations_wag_label: $('#donations_wag_label').val(),
            donations_wag_footer: $('#donations_wag_footer').val(),
            donations_wag_bg: $('#donations_wag_bg').val(),
            donations_wag_fg: $('#donations_wag_fg').val(),
            donations_wag_levels: JSON.stringify(collectWagLevels())
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
