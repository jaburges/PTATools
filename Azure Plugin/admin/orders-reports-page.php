<?php
/**
 * Orders Reports — admin page.
 *
 * Renders the Reports tab inside the Selling page. Two sub-views are
 * possible:
 *   ?page=azure-plugin-selling&tab=reports                 → New Report builder
 *   ?page=azure-plugin-selling&tab=reports&subtab=saved    → Saved Reports list
 *   ?page=azure-plugin-selling&tab=reports&edit=<id>       → Load a saved
 *                                                            report into the
 *                                                            builder for edit.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_woocommerce')) {
    return;
}

// Convert mysql 'YYYY-MM-DD HH:MM:SS' to datetime-local 'YYYY-MM-DDTHH:MM'.
// Declared at top of file so conditional-function-declaration hoisting
// rules don't bite (PHP only hoists unconditional top-level declarations).
if (!function_exists('azure_or_format_dt_local')) {
    function azure_or_format_dt_local($v) {
        if (empty($v)) return '';
        return substr(str_replace(' ', 'T', (string) $v), 0, 16);
    }
}

$subtab = isset($_GET['subtab']) ? sanitize_key((string) $_GET['subtab']) : 'new';
if (!in_array($subtab, array('new', 'saved'), true)) {
    $subtab = 'new';
}

$edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
$dup_id  = isset($_GET['duplicate']) ? absint($_GET['duplicate']) : 0;
if ($dup_id > 0) {
    $clone_result = Azure_Orders_Reports_Storage::duplicate($dup_id);
    if (!is_wp_error($clone_result)) {
        wp_safe_redirect(admin_url('admin.php?page=azure-plugin-selling&tab=reports&edit=' . (int) $clone_result));
        exit;
    }
}

$loaded_report = null;
if ($edit_id > 0) {
    $loaded_report = Azure_Orders_Reports_Storage::load($edit_id);
    if (!$loaded_report) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Report not found.', 'azure-plugin') . '</p></div>';
        $edit_id = 0;
    } else {
        $subtab = 'new';
    }
}

$registry        = Azure_Orders_Reports_Columns::all();
$categories      = Azure_Orders_Reports_Columns::categories();
$default_columns = Azure_Orders_Reports_Columns::default_columns_for_granularity(
    $loaded_report ? ($loaded_report['config']['granularity'] ?? 'line_item') : 'line_item'
);

$cfg = $loaded_report
    ? $loaded_report['config']
    : Azure_Orders_Reports_Storage::sanitize_config(array());

$report_name   = $loaded_report['name'] ?? '';
$selected_keys = array_values(array_filter(array_map(function ($c) {
    return is_array($c) ? ($c['key'] ?? '') : '';
}, $cfg['columns'])));
if (empty($selected_keys)) {
    $selected_keys = $default_columns;
}

$all_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
?>

<div class="azure-or-wrap">
    <h2 style="margin-top:16px;">
        <span class="dashicons dashicons-media-spreadsheet"></span>
        <?php _e('Orders Reports', 'azure-plugin'); ?>
    </h2>

    <p class="description">
        <?php _e('Build, save, and export WooCommerce order reports. Includes Product Fields captured at checkout.', 'azure-plugin'); ?>
    </p>

    <nav class="azure-or-subtabs">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=reports&subtab=new')); ?>"
           class="azure-or-subtab <?php echo $subtab === 'new' ? 'active' : ''; ?>">
            <?php echo $edit_id ? esc_html__('Edit Report', 'azure-plugin') : esc_html__('New Report', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=reports&subtab=saved')); ?>"
           class="azure-or-subtab <?php echo $subtab === 'saved' ? 'active' : ''; ?>">
            <?php _e('Saved Reports', 'azure-plugin'); ?>
        </a>
    </nav>

    <?php if ($subtab === 'saved'): ?>
        <?php $rows = Azure_Orders_Reports_Storage::list_all(); ?>
        <?php if (empty($rows)): ?>
            <p><em><?php _e('No saved reports yet.', 'azure-plugin'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=reports&subtab=new')); ?>"><?php _e('Build your first report.', 'azure-plugin'); ?></a></em></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'azure-plugin'); ?></th>
                        <th><?php _e('Last modified by', 'azure-plugin'); ?></th>
                        <th><?php _e('Last modified', 'azure-plugin'); ?></th>
                        <th><?php _e('Last exported', 'azure-plugin'); ?></th>
                        <th style="width:280px;"><?php _e('Actions', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $author    = $r['author'] ? get_userdata($r['author']) : null;
                    $edit_url  = admin_url('admin.php?page=azure-plugin-selling&tab=reports&edit=' . (int) $r['id']);
                    $dup_url   = wp_nonce_url(admin_url('admin.php?page=azure-plugin-selling&tab=reports&duplicate=' . (int) $r['id']), 'azure_or_duplicate_' . $r['id']);
                ?>
                    <tr data-report-id="<?php echo (int) $r['id']; ?>">
                        <td>
                            <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($r['name']); ?></a></strong>
                        </td>
                        <td><?php echo esc_html($author ? $author->display_name : '—'); ?></td>
                        <td><?php echo esc_html($r['modified']); ?></td>
                        <td>
                            <?php if (!empty($r['last_exported_at'])): ?>
                                <?php echo esc_html($r['last_exported_at']); ?>
                                <?php if ($r['last_exported_rows'] > 0): ?>
                                    <div style="color:#888;font-size:11px;"><?php printf(esc_html__('%d rows', 'azure-plugin'), (int) $r['last_exported_rows']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <em style="color:#888;">—</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php _e('Edit', 'azure-plugin'); ?></a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="azure_or_export_saved" />
                                <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>" />
                                <?php wp_nonce_field('azure_or_export_saved_' . (int) $r['id']); ?>
                                <button type="submit" class="button button-primary"><?php _e('Export', 'azure-plugin'); ?></button>
                            </form>
                            <a class="button" href="<?php echo esc_url($dup_url); ?>"><?php _e('Duplicate', 'azure-plugin'); ?></a>
                            <button type="button" class="button azure-or-delete" data-id="<?php echo (int) $r['id']; ?>"><?php _e('Delete', 'azure-plugin'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <!-- New Report / Edit Report builder -->
        <form id="azure-or-builder" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="azure_or_export" />
            <input type="hidden" name="report_id" value="<?php echo (int) $edit_id; ?>" />
            <?php wp_nonce_field('azure_or_export'); ?>

            <div class="azure-or-section">
                <label><strong><?php _e('Report name', 'azure-plugin'); ?></strong></label>
                <input type="text" name="report_name" id="azure-or-name" value="<?php echo esc_attr($report_name); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. Yearbook fulfillment list', 'azure-plugin'); ?>" />
            </div>

            <fieldset class="azure-or-section">
                <legend><?php _e('Date range', 'azure-plugin'); ?></legend>
                <div class="azure-or-row">
                    <label><?php _e('From', 'azure-plugin'); ?> <input type="datetime-local" name="date_from" id="azure-or-from" value="<?php echo esc_attr(azure_or_format_dt_local($cfg['date_range']['from'])); ?>" /></label>
                    <label><?php _e('To', 'azure-plugin'); ?> <input type="datetime-local" name="date_to" id="azure-or-to" value="<?php echo esc_attr(azure_or_format_dt_local($cfg['date_range']['to'] ?: current_time('mysql'))); ?>" /></label>
                    <input type="hidden" name="date_preset" id="azure-or-preset" value="<?php echo esc_attr((string) $cfg['date_range']['preset']); ?>" />
                </div>
                <div class="azure-or-presets">
                    <span class="azure-or-presets-label"><?php _e('Presets:', 'azure-plugin'); ?></span>
                    <?php foreach (array(
                        'last_7_days'      => __('Last 7 days', 'azure-plugin'),
                        'last_30_days'     => __('Last 30 days', 'azure-plugin'),
                        'previous_month'   => __('Previous month', 'azure-plugin'),
                        'previous_quarter' => __('Previous quarter', 'azure-plugin'),
                        'this_school_year' => __('This school year', 'azure-plugin'),
                    ) as $slug => $label): ?>
                        <button type="button" class="button azure-or-preset" data-preset="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="azure-or-row" style="margin-top:8px;">
                    <label title="<?php esc_attr_e('When checked, the report always runs through “right now”, ignoring the To date above. Useful for ongoing year-to-date or school-year reports.', 'azure-plugin'); ?>">
                        <input type="checkbox" name="date_to_today" id="azure-or-to-today" value="1" <?php checked(!empty($cfg['date_range']['to_today'])); ?> />
                        <?php _e('Always end on today (ignore To date)', 'azure-plugin'); ?>
                    </label>
                </div>
            </fieldset>

            <?php
            // Compact summary for the collapsible Filters section. Counts
            // active filter dimensions so the user can tell at a glance
            // what's set without expanding the section.
            $azure_or_status_count = count($cfg['filters']['statuses']);
            $azure_or_prod_count   = count((array) $cfg['filters']['product_ids']);
            $azure_or_cat_count    = count((array) $cfg['filters']['category_ids']);
            $azure_or_tag_count    = count((array) $cfg['filters']['tag_ids']);
            $azure_or_filter_summary = sprintf(
                /* translators: 1: status count, 2: products count, 3: categories count, 4: tags count */
                __('%1$d statuses · %2$d products · %3$d categories · %4$d tags', 'azure-plugin'),
                $azure_or_status_count, $azure_or_prod_count, $azure_or_cat_count, $azure_or_tag_count
            );
            ?>
            <details class="azure-or-section azure-or-section--details" open>
                <summary>
                    <strong><?php _e('Filters', 'azure-plugin'); ?></strong>
                    <span class="azure-or-summary-meta"><?php echo esc_html($azure_or_filter_summary); ?></span>
                </summary>

                <div class="azure-or-row">
                    <label><strong><?php _e('Order Status', 'azure-plugin'); ?></strong></label>
                    <div class="azure-or-statuses">
                        <?php foreach ($all_statuses as $slug => $label):
                            // wc_get_order_statuses keys are 'wc-xxx'; the slug we filter on is 'xxx'
                            $bare = preg_replace('/^wc-/', '', $slug);
                            $checked = in_array($bare, $cfg['filters']['statuses'], true);
                        ?>
                            <label class="azure-or-status-toggle">
                                <input type="checkbox" name="statuses[]" value="<?php echo esc_attr($bare); ?>" <?php checked($checked); ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="azure-or-row">
                    <label for="azure-or-product-search"><strong><?php _e('Products', 'azure-plugin'); ?></strong></label>
                    <select id="azure-or-product-search"
                            class="wc-product-search"
                            name="product_ids[]"
                            multiple="multiple"
                            style="min-width:400px;"
                            data-placeholder="<?php echo esc_attr__('Search for a product (includes drafts)\u2026', 'azure-plugin'); ?>"
                            data-action="azure_or_search_products"
                            data-allow_clear="true"
                            data-multiple="true">
                        <?php foreach ((array) $cfg['filters']['product_ids'] as $pid):
                            $pid_int = (int) $pid;
                            // Render the saved pill even when the
                            // product is now Draft/Pending so the
                            // filter survives status changes on
                            // historical reports.
                            $title  = get_the_title($pid_int) ?: ('#' . $pid_int);
                            $status = get_post_status($pid_int);
                            if ($status && $status !== 'publish') {
                                $title .= ' — ' . ucfirst($status);
                            }
                        ?>
                            <option value="<?php echo $pid_int; ?>" selected="selected"><?php echo esc_html($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:6px;">
                        <?php _e('Search matches published, draft, pending, and private products by title, content, SKU, or post ID. Non-published products are tagged in the dropdown.', 'azure-plugin'); ?>
                    </p>
                </div>

                <div class="azure-or-row azure-or-row-split">
                    <div class="azure-or-half">
                        <label><strong><?php _e('Categories', 'azure-plugin'); ?></strong></label>
                        <select name="category_ids[]" multiple size="6">
                            <?php
                            $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                            foreach ($cats as $cat):
                                $sel = in_array((int) $cat->term_id, (array) $cfg['filters']['category_ids'], true);
                            ?>
                                <option value="<?php echo (int) $cat->term_id; ?>" <?php selected($sel); ?>><?php echo esc_html($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="azure-or-half">
                        <label><strong><?php _e('Tags', 'azure-plugin'); ?></strong></label>
                        <select name="tag_ids[]" multiple size="6">
                            <?php
                            $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
                            foreach ($tags as $tag):
                                $sel = in_array((int) $tag->term_id, (array) $cfg['filters']['tag_ids'], true);
                            ?>
                                <option value="<?php echo (int) $tag->term_id; ?>" <?php selected($sel); ?>><?php echo esc_html($tag->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </details>

            <fieldset class="azure-or-section">
                <legend><?php _e('Row granularity', 'azure-plugin'); ?></legend>
                <label>
                    <input type="radio" name="granularity" value="line_item" <?php checked($cfg['granularity'], 'line_item'); ?> />
                    <?php _e('One row per line item (recommended)', 'azure-plugin'); ?>
                </label>
                <label style="margin-left:20px;">
                    <input type="radio" name="granularity" value="order" <?php checked($cfg['granularity'], 'order'); ?> />
                    <?php _e('One row per order', 'azure-plugin'); ?>
                </label>
            </fieldset>

            <fieldset class="azure-or-section">
                <legend><?php _e('Columns', 'azure-plugin'); ?></legend>
                <p class="description"><?php _e('Check columns from the left to add them. Drag the right list to reorder.', 'azure-plugin'); ?></p>

                <div class="azure-or-columns-grid">
                    <div class="azure-or-available">
                        <h4><?php _e('Available', 'azure-plugin'); ?></h4>
                        <?php
                        // Default-open only the categories users reach for most often.
                        // Order Amounts / Billing / Shipping / Aggregated stay collapsed
                        // — click their summary to expand. Cuts roughly half the vertical
                        // height of the picker on page load.
                        $azure_or_open_by_default = array('order', 'customer', 'line_item', 'product_fields');
                        foreach ($categories as $cat_slug => $cat_label):
                            $cols_in_cat = array_filter($registry, function ($c) use ($cat_slug) { return $c['category'] === $cat_slug; });
                            if (empty($cols_in_cat)) continue;
                            $is_open = in_array($cat_slug, $azure_or_open_by_default, true);
                            $selected_in_cat = 0;
                            foreach ($cols_in_cat as $cc) {
                                if (in_array($cc['key'], $selected_keys, true)) $selected_in_cat++;
                            }
                        ?>
                            <details <?php echo $is_open ? 'open' : ''; ?>>
                                <summary>
                                    <?php echo esc_html($cat_label); ?>
                                    <?php if ($selected_in_cat > 0): ?>
                                        <span class="azure-or-cat-badge"><?php echo (int) $selected_in_cat; ?></span>
                                    <?php endif; ?>
                                </summary>
                                <ul>
                                    <?php foreach ($cols_in_cat as $c):
                                        $checked = in_array($c['key'], $selected_keys, true); ?>
                                        <li>
                                            <label>
                                                <input type="checkbox" class="azure-or-col-toggle"
                                                       data-key="<?php echo esc_attr($c['key']); ?>"
                                                       data-label="<?php echo esc_attr($c['label']); ?>"
                                                       data-granularity="<?php echo esc_attr(implode(',', $c['granularity'])); ?>"
                                                       <?php checked($checked); ?> />
                                                <?php echo esc_html($c['label']); ?>
                                                <small style="color:#888;">(<?php echo esc_html(implode('+', $c['granularity'])); ?>)</small>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <div class="azure-or-selected">
                        <h4><?php _e('Selected (drag to reorder)', 'azure-plugin'); ?></h4>
                        <ul id="azure-or-selected-list">
                            <?php foreach ($selected_keys as $key):
                                if (!isset($registry[$key])) continue;
                                $c = $registry[$key];
                            ?>
                                <li data-key="<?php echo esc_attr($key); ?>">
                                    <span class="dashicons dashicons-menu"></span>
                                    <span class="azure-or-col-label"><?php echo esc_html($c['label']); ?></span>
                                    <input type="hidden" name="columns[]" value="<?php echo esc_attr($key); ?>" />
                                    <button type="button" class="azure-or-col-remove">&times;</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </fieldset>

            <div class="azure-or-actions">
                <button type="button" class="button" id="azure-or-preview-btn">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php _e('Preview', 'azure-plugin'); ?>
                </button>
                <button type="button" class="button" id="azure-or-save-btn">
                    <span class="dashicons dashicons-saved"></span>
                    <?php echo $edit_id ? esc_html__('Update saved report', 'azure-plugin') : esc_html__('Save report', 'azure-plugin'); ?>
                </button>
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Export to Excel', 'azure-plugin'); ?>
                </button>
            </div>

            <div id="azure-or-preview" style="margin-top:20px;"></div>
        </form>
    <?php endif; ?>
</div>
