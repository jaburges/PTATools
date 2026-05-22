<?php
/**
 * Auction Module Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle the "Auction Display Page" live toggle inline. Posts back to the
// current admin URL so the user stays on this tab.
$display_toggle_notice = '';
if (
    isset($_POST['azure_auction_display_toggle'])
    && current_user_can('manage_options')
    && check_admin_referer('azure_auction_display_toggle', 'azure_auction_display_nonce')
) {
    $new_live = !empty($_POST['auction_display_live']) ? true : false;
    Azure_Settings::update_setting('auction_display_live', $new_live);
    $display_toggle_notice = $new_live
        ? __('Auction display page is now LIVE — visible to the public.', 'azure-plugin')
        : __('Auction display page is in preview mode — admins only.', 'azure-plugin');
}

// Handle "Extend all auctions" bulk action. Updates _auction_bidding_end on
// every published auction product whose status is not yet ended/sold AND
// reschedules the per-auction finalize cron via the canonical helper. This
// is the safe path for moving the auction night.
$extend_notice = '';
$extend_summary = null;
if (
    isset($_POST['azure_auction_extend_all'])
    && current_user_can('manage_options')
    && check_admin_referer('azure_auction_extend_all', 'azure_auction_extend_all_nonce')
) {
    $new_date = isset($_POST['azure_auction_extend_date']) ? sanitize_text_field($_POST['azure_auction_extend_date']) : '';
    $new_time = isset($_POST['azure_auction_extend_time']) ? sanitize_text_field($_POST['azure_auction_extend_time']) : '';
    $reopen   = !empty($_POST['azure_auction_extend_reopen']);

    if ($new_date && $new_time) {
        $combined = $new_date . ' ' . $new_time . ':00';
        $end_ts = strtotime($combined);

        if (!$end_ts) {
            $extend_notice = __('Invalid end date/time.', 'azure-plugin');
        } elseif ($end_ts <= current_time('timestamp')) {
            $extend_notice = __('End date/time must be in the future.', 'azure-plugin');
        } else {
            global $wpdb;
            $pt = $wpdb->get_var("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} tt
                                  JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                                  WHERE tt.taxonomy='product_type' AND t.slug='auction'");
            $ids = $pt ? $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
                 WHERE p.post_type='product' AND tr.term_taxonomy_id=%d AND p.post_status='publish'",
                $pt
            )) : array();

            $updated = 0; $skipped_winner = 0; $reopened = 0;
            foreach ($ids as $pid) {
                $pid    = (int) $pid;
                $status = get_post_meta($pid, '_auction_status', true);
                $winner = (int) get_post_meta($pid, '_auction_winner_user_id', true);
                $order  = (int) get_post_meta($pid, '_auction_winner_order_id', true);

                // Don't blindly extend an item with a winner / order; that path
                // requires conscious reversal because notifications may have
                // been sent and orders created.
                if ($winner || $order) {
                    $skipped_winner++;
                    continue;
                }

                if (in_array($status, array('ended', 'sold'), true)) {
                    if ($reopen) {
                        delete_post_meta($pid, '_auction_status');
                        delete_post_meta($pid, '_auction_ended_at');
                        $reopened++;
                    } else {
                        $skipped_winner++;
                        continue;
                    }
                }

                update_post_meta($pid, '_auction_bidding_end', $combined);
                if (class_exists('Azure_Auction_Lifecycle')) {
                    Azure_Auction_Lifecycle::schedule_finalize_event($pid, $combined);
                }
                clean_post_cache($pid);
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $updated++;
            }

            $extend_summary = array(
                'new_end'         => $combined,
                'updated'         => $updated,
                'reopened'        => $reopened,
                'skipped_winner'  => $skipped_winner,
                'total_scanned'   => count($ids),
            );
            $extend_notice = sprintf(
                __('Extended %1$d auction(s) to %2$s. Reopened: %3$d. Skipped (had winner/order): %4$d.', 'azure-plugin'),
                (int) $updated, esc_html($combined), (int) $reopened, (int) $skipped_winner
            );
        }
    } else {
        $extend_notice = __('Please choose both a date and a time.', 'azure-plugin');
    }
}

// Handle layout settings save (separate form so it can post independently
// of the live toggle).
$display_layout_notice = '';
if (
    isset($_POST['azure_auction_display_layout'])
    && current_user_can('manage_options')
    && check_admin_referer('azure_auction_display_layout', 'azure_auction_display_layout_nonce')
) {
    $card_scale = isset($_POST['auction_display_card_scale']) ? (int) $_POST['auction_display_card_scale'] : 80;
    $cards_wide = isset($_POST['auction_display_cards_wide']) ? (int) $_POST['auction_display_cards_wide'] : 4;
    $cards_tall = isset($_POST['auction_display_cards_tall']) ? (int) $_POST['auction_display_cards_tall'] : 3;
    $slide_secs = isset($_POST['auction_display_slide_seconds']) ? (int) $_POST['auction_display_slide_seconds'] : 5;

    Azure_Settings::update_setting('auction_display_card_scale',    max(30, min(100, $card_scale)));
    Azure_Settings::update_setting('auction_display_cards_wide',    max(1,  min(8,   $cards_wide)));
    Azure_Settings::update_setting('auction_display_cards_tall',    max(1,  min(6,   $cards_tall)));
    Azure_Settings::update_setting('auction_display_slide_seconds', max(2,  min(120, $slide_secs)));

    $display_layout_notice = __('Display layout settings saved.', 'azure-plugin');
}

$settings = Azure_Settings::get_all_settings();
$auction_enabled = !empty($settings['enable_auction']);
$display_live = !empty($settings['auction_display_live']);
$card_scale_v   = isset($settings['auction_display_card_scale'])    ? (int) $settings['auction_display_card_scale']    : 80;
$cards_wide_v   = isset($settings['auction_display_cards_wide'])    ? (int) $settings['auction_display_cards_wide']    : 4;
$cards_tall_v   = isset($settings['auction_display_cards_tall'])    ? (int) $settings['auction_display_cards_tall']    : 3;
$slide_secs_v   = isset($settings['auction_display_slide_seconds']) ? (int) $settings['auction_display_slide_seconds'] : 5;

// Try to find an existing /auction page so we can offer a quick "View" link.
$auction_page_url = '';
$auction_page = get_page_by_path('auction');
if ($auction_page instanceof WP_Post) {
    $auction_page_url = get_permalink($auction_page);
}

// Most-common future end date across published auctions, used to pre-fill
// the "extend all" form so the admin doesn't have to retype the auction
// night every time.
$current_common_end = '';
if (class_exists('WooCommerce')) {
    global $wpdb;
    $current_common_end = (string) $wpdb->get_var(
        "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_auction_bidding_end'
           AND pm.meta_value <> ''
           AND pm.meta_value > NOW()
           AND p.post_type = 'product' AND p.post_status = 'publish'
         GROUP BY pm.meta_value
         ORDER BY COUNT(*) DESC, pm.meta_value DESC
         LIMIT 1"
    );
}
$prefill_date = $current_common_end ? date('Y-m-d', strtotime($current_common_end)) : '';
$prefill_time = $current_common_end ? date('H:i',   strtotime($current_common_end)) : '19:00';

$active_auctions = 0;
$staged_auctions = 0;
$total_bids = 0;
if (class_exists('WooCommerce')) {
    global $wpdb;
    $bids_table = Azure_Database::get_table_name('auction_bids');
    if ($bids_table && $wpdb->get_var("SHOW TABLES LIKE '{$bids_table}'") === $bids_table) {
        $total_bids = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bids_table}");
    }
    $active_auctions = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_auction_bidding_end'
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
         AND (pm.meta_value = '' OR pm.meta_value > NOW())"
    );
    // Staged auctions = auction products that are scheduled (post_status = 'future').
    // The _auction_bidding_end meta is only set on auction-type products, so it
    // doubles as a product-type filter without joining the term tables.
    $staged_auctions = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_auction_bidding_end'
         WHERE p.post_type = 'product' AND p.post_status = 'future'"
    );
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-auction-page">
    <h1>
        <span class="dashicons dashicons-hammer"></span>
        <?php _e('Auction Module', 'azure-plugin'); ?>
    </h1>
<?php else: ?>
<div class="azure-auction-page">
<?php endif; ?>

    <?php if (!$auction_enabled): ?>
    <div class="notice notice-warning" style="margin: 15px 0;">
        <p><?php _e('The Auction module is currently disabled.', 'azure-plugin'); ?>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a></p>
    </div>
    <?php endif; ?>

    <?php if (!class_exists('WooCommerce')) : ?>
    <div class="notice notice-error">
        <p><strong><?php _e('WooCommerce Required:', 'azure-plugin'); ?></strong>
        <?php _e('The Auction module requires WooCommerce to be installed and activated.', 'azure-plugin'); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($auction_enabled && class_exists('WooCommerce')) : ?>
    <div class="azure-module-content">
        <div class="azure-stat-row">
            <div class="azure-stat-box">
                <span class="azure-stat-number"><?php echo (int) $active_auctions; ?></span>
                <span class="azure-stat-label"><?php _e('Active Auctions', 'azure-plugin'); ?></span>
            </div>
            <div class="azure-stat-box">
                <span class="azure-stat-number"><?php echo (int) $staged_auctions; ?></span>
                <span class="azure-stat-label"><?php _e('Staged Auctions', 'azure-plugin'); ?></span>
            </div>
            <div class="azure-stat-box">
                <span class="azure-stat-number"><?php echo (int) $total_bids; ?></span>
                <span class="azure-stat-label"><?php _e('Total Bids', 'azure-plugin'); ?></span>
            </div>
        </div>

        <div class="azure-action-row">
            <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-primary">
                <span class="dashicons dashicons-plus-alt"></span> <?php _e('Add New Product', 'azure-plugin'); ?>
            </a>
            <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">
                <span class="dashicons dashicons-list-view"></span> <?php _e('View All Products', 'azure-plugin'); ?>
            </a>

            <?php
            if (class_exists('Azure_Auction_Winners_Report')) {
                $azure_bulk_resend_count_pre = method_exists('Azure_Auction_Winners_Report', 'get_unpaid_auction_order_ids')
                    ? count((new Azure_Auction_Winners_Report())->get_unpaid_auction_order_ids())
                    : null;
                if ($azure_bulk_resend_count_pre !== null) {
                    $azure_bulk_confirm = sprintf(
                        "Send the WooCommerce invoice email to %d unpaid auction customer(s)?\n\nEach customer receives one email with a pay-now link.\nThis cannot be undone from the UI.",
                        (int) $azure_bulk_resend_count_pre
                    );
                    ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:8px;">
                        <input type="hidden" name="action" value="azure_auction_resend_all_unpaid" />
                        <?php wp_nonce_field('azure_auction_resend_all_unpaid'); ?>
                        <button type="submit"
                                class="button button-secondary"
                                <?php disabled($azure_bulk_resend_count_pre === 0); ?>
                                onclick="return confirm(<?php echo esc_attr(wp_json_encode($azure_bulk_confirm)); ?>);">
                            <span class="dashicons dashicons-email-alt2"></span>
                            <?php printf(
                                esc_html__('Email all unpaid Auction Items (%d)', 'azure-plugin'),
                                (int) $azure_bulk_resend_count_pre
                            ); ?>
                        </button>
                    </form>
                    <?php
                }
            }
            ?>
        </div>
        <p class="description"><?php _e('Create a product and select "Auction" as the product type. Set bidding end date/time, optional Buy It Now price, and require immediate payment.', 'azure-plugin'); ?></p>

        <hr style="margin: 24px 0;" />

        <div class="azure-auction-display-panel">
            <h2 style="margin-top: 0;"><span class="dashicons dashicons-screenoptions"></span> <?php _e('Auction Night Display Page', 'azure-plugin'); ?></h2>

            <?php if (!empty($display_toggle_notice)) : ?>
            <div class="notice notice-success inline" style="margin: 0 0 12px;">
                <p><?php echo esc_html($display_toggle_notice); ?></p>
            </div>
            <?php endif; ?>

            <p class="description" style="margin-top: 0;">
                <?php _e('A grid of cards (title, image, masked top bidder, current bid) that auto-refreshes every 30 seconds. Designed to project on a screen during the live auction.', 'azure-plugin'); ?>
            </p>

            <p>
                <strong><?php _e('Status:', 'azure-plugin'); ?></strong>
                <?php if ($display_live) : ?>
                    <span style="color: #1a7f37; font-weight: 600;"><?php _e('LIVE — visible to the public', 'azure-plugin'); ?></span>
                <?php else : ?>
                    <span style="color: #b26100; font-weight: 600;"><?php _e('Preview — admins only (public sees "Coming soon")', 'azure-plugin'); ?></span>
                <?php endif; ?>
            </p>

            <p>
                <strong><?php _e('Setup:', 'azure-plugin'); ?></strong>
                <?php _e('Create a WordPress page (suggested slug', 'azure-plugin'); ?> <code>/auction</code><?php _e(') and paste this shortcode into it:', 'azure-plugin'); ?>
            </p>
            <p>
                <input type="text" readonly value="[auction-display]" onclick="this.select();" style="font-family: monospace; width: 220px;" />
            </p>

            <form method="post" style="margin-top: 16px;">
                <?php wp_nonce_field('azure_auction_display_toggle', 'azure_auction_display_nonce'); ?>
                <input type="hidden" name="azure_auction_display_toggle" value="1" />
                <label style="display: inline-flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="auction_display_live" value="1" <?php checked($display_live); ?> />
                    <span><?php _e('Make the /auction page LIVE for the public', 'azure-plugin'); ?></span>
                </label>
                <p style="margin-top: 10px;">
                    <button type="submit" class="button button-primary"><?php _e('Save', 'azure-plugin'); ?></button>
                    <?php if ($auction_page_url) : ?>
                    <a href="<?php echo esc_url($auction_page_url); ?>" target="_blank" rel="noopener" class="button">
                        <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                        <?php _e('View /auction page', 'azure-plugin'); ?>
                    </a>
                    <?php endif; ?>
                </p>
            </form>

            <hr style="margin: 18px 0;" />

            <h3 style="margin: 0 0 6px;"><?php _e('Extend All Auctions', 'azure-plugin'); ?></h3>
            <p class="description" style="margin-top: 0;">
                <?php _e('Bulk-update every published auction product to a new bidding end. Each item is also re-scheduled in WP-Cron so the natural-end finalize fires at the new time. Items with a winner or order are skipped automatically.', 'azure-plugin'); ?>
            </p>

            <?php if (!empty($extend_notice)) : ?>
            <div class="notice <?php echo $extend_summary ? 'notice-success' : 'notice-error'; ?> inline" style="margin: 6px 0 12px;">
                <p><?php echo esc_html($extend_notice); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" style="margin-top: 8px;">
                <?php wp_nonce_field('azure_auction_extend_all', 'azure_auction_extend_all_nonce'); ?>
                <input type="hidden" name="azure_auction_extend_all" value="1" />
                <table class="form-table" role="presentation" style="max-width: 640px;">
                    <tr>
                        <th scope="row"><label for="azure_auction_extend_date"><?php _e('New end date', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="date" id="azure_auction_extend_date" name="azure_auction_extend_date"
                                   value="<?php echo esc_attr($prefill_date); ?>" required style="width: 180px;" />
                            <input type="time" id="azure_auction_extend_time" name="azure_auction_extend_time"
                                   value="<?php echo esc_attr($prefill_time); ?>" required style="width: 140px; margin-left: 8px;" />
                            <p class="description"><?php _e('Site timezone. Pre-filled with the most common active end date.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Reopen ended items?', 'azure-plugin'); ?></th>
                        <td>
                            <label style="display: inline-flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="azure_auction_extend_reopen" value="1" />
                                <span><?php _e('Also clear "ended" status on items that had no bids (re-opens them for bidding).', 'azure-plugin'); ?></span>
                            </label>
                            <p class="description"><?php _e('Items with a winner or order are never reopened — that path requires manual reversal.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary"
                            onclick="return confirm('<?php echo esc_js(__('Extend all eligible auction items to the chosen date/time?', 'azure-plugin')); ?>');">
                        <?php _e('Extend all eligible auctions', 'azure-plugin'); ?>
                    </button>
                </p>
            </form>

            <hr style="margin: 18px 0;" />

            <h3 style="margin: 0 0 6px;"><?php _e('Display Layout (projector / TV)', 'azure-plugin'); ?></h3>
            <p class="description" style="margin-top: 0;">
                <?php _e('The display page paginates the cards into "screens" of a fixed size and auto-advances between screens so every item is shown on the projector.', 'azure-plugin'); ?>
            </p>

            <?php if (!empty($display_layout_notice)) : ?>
            <div class="notice notice-success inline" style="margin: 0 0 12px;">
                <p><?php echo esc_html($display_layout_notice); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" style="margin-top: 8px;">
                <?php wp_nonce_field('azure_auction_display_layout', 'azure_auction_display_layout_nonce'); ?>
                <input type="hidden" name="azure_auction_display_layout" value="1" />
                <table class="form-table" role="presentation" style="max-width: 640px;">
                    <tr>
                        <th scope="row"><label for="auction_display_card_scale"><?php _e('Card size', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="number" id="auction_display_card_scale" name="auction_display_card_scale"
                                   min="30" max="100" step="5"
                                   value="<?php echo (int) $card_scale_v; ?>" /> %
                            <p class="description"><?php _e('Scales padding and typography. 100% = original size, 80% recommended for a projector with 12 cards on screen.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auction_display_cards_wide"><?php _e('Cards per row', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="number" id="auction_display_cards_wide" name="auction_display_cards_wide"
                                   min="1" max="8" step="1"
                                   value="<?php echo (int) $cards_wide_v; ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auction_display_cards_tall"><?php _e('Rows per screen', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="number" id="auction_display_cards_tall" name="auction_display_cards_tall"
                                   min="1" max="6" step="1"
                                   value="<?php echo (int) $cards_tall_v; ?>" />
                            <p class="description">
                                <?php
                                $per_page = max(1, $cards_wide_v) * max(1, $cards_tall_v);
                                printf(
                                    esc_html__('Each screen shows %d cards (%d wide x %d tall).', 'azure-plugin'),
                                    (int) $per_page, (int) $cards_wide_v, (int) $cards_tall_v
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auction_display_slide_seconds"><?php _e('Seconds per screen', 'azure-plugin'); ?></label></th>
                        <td>
                            <input type="number" id="auction_display_slide_seconds" name="auction_display_slide_seconds"
                                   min="2" max="120" step="1"
                                   value="<?php echo (int) $slide_secs_v; ?>" />
                            <p class="description"><?php _e('Time before sliding to the next screen. Set to 2-120 seconds.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary"><?php _e('Save layout settings', 'azure-plugin'); ?></button>
                </p>
            </form>
        </div>

        <?php
        if (class_exists('Azure_Auction_Winners_Report')) {
            $azure_winners_report = new Azure_Auction_Winners_Report();
            $azure_winner_rows    = $azure_winners_report->get_ended_auction_rows();
            $azure_winner_totals  = Azure_Auction_Winners_Report::compute_totals($azure_winner_rows);
            $azure_te_rows        = array_values(array_filter($azure_winner_rows, function ($r) { return !empty($r['is_te']); }));

            if (!empty($_GET['azure_te_msg'])) {
                $azure_msg_text  = wp_kses_post(rawurldecode((string) $_GET['azure_te_msg']));
                $azure_msg_state = isset($_GET['azure_te_state']) && $_GET['azure_te_state'] === 'error' ? 'error' : 'success';
                printf(
                    '<div class="notice notice-%s is-dismissible" style="margin: 15px 0;"><p>%s</p></div>',
                    esc_attr($azure_msg_state),
                    esc_html($azure_msg_text)
                );
            }
            if (!empty($_GET['azure_invoice_msg'])) {
                $azure_inv_text  = wp_kses_post(rawurldecode((string) $_GET['azure_invoice_msg']));
                $raw_state       = isset($_GET['azure_invoice_state']) ? (string) $_GET['azure_invoice_state'] : 'success';
                $azure_inv_state = in_array($raw_state, array('success', 'error', 'warning', 'info'), true) ? $raw_state : 'info';
                printf(
                    '<div class="notice notice-%s is-dismissible" style="margin: 15px 0;"><p>%s</p></div>',
                    esc_attr($azure_inv_state),
                    esc_html($azure_inv_text)
                );
            }

            $azure_unpaid_order_ids = $azure_winners_report->get_unpaid_auction_order_ids();
            $azure_unpaid_count     = count($azure_unpaid_order_ids);
            ?>

            <hr style="margin: 30px 0;" />

            <h2><span class="dashicons dashicons-awards"></span> <?php _e('Auction Winners', 'azure-plugin'); ?></h2>
            <p class="description"><?php _e('Every auction product whose status is "ended" or "sold". Payment state reflects WooCommerce order status and the `_paid_date` meta on the order.', 'azure-plugin'); ?></p>

            <?php if (empty($azure_winner_rows)): ?>
                <p><em><?php _e('No ended auctions yet.', 'azure-plugin'); ?></em></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Item', 'azure-plugin'); ?></th>
                            <th><?php _e('Ended', 'azure-plugin'); ?></th>
                            <th><?php _e('Winner', 'azure-plugin'); ?></th>
                            <th><?php _e('Email', 'azure-plugin'); ?></th>
                            <th style="text-align:right;"><?php _e('Winning bid', 'azure-plugin'); ?></th>
                            <th><?php _e('Order', 'azure-plugin'); ?></th>
                            <th><?php _e('Paid?', 'azure-plugin'); ?></th>
                            <th style="width:160px;"><?php _e('Invoice', 'azure-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($azure_winner_rows as $r): ?>
                            <?php
                            $product_link = $r['product_id'] ? get_edit_post_link($r['product_id']) : '';
                            $user_link    = $r['winner_user_id'] ? get_edit_user_link($r['winner_user_id']) : '';
                            $order_link   = !empty($r['order_edit_url']) ? $r['order_edit_url'] : ($r['order_id'] ? Azure_Auction_Winners_Report::order_edit_url($r['order_id']) : '');
                            $paid_class   = $r['is_paid'] === true ? 'azure-pay-paid' : ($r['is_paid'] === false ? 'azure-pay-unpaid' : 'azure-pay-unknown');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($product_link): ?>
                                        <a href="<?php echo esc_url($product_link); ?>"><?php echo esc_html($r['title']); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($r['title']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($r['is_te'])): ?>
                                        <span class="azure-te-badge" style="background:#2271b1;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:6px;">TE</span>
                                    <?php endif; ?>
                                    <div style="color:#888;font-size:12px;">#<?php echo (int) $r['product_id']; ?> · <?php echo esc_html($r['auction_status']); ?></div>
                                </td>
                                <td><?php echo esc_html($r['ended_at']); ?></td>
                                <td>
                                    <?php if ($user_link && $r['winner_name']): ?>
                                        <a href="<?php echo esc_url($user_link); ?>"><?php echo esc_html($r['winner_name']); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($r['winner_name'] ?: '—'); ?>
                                    <?php endif; ?>
                                    <?php if ($r['winner_login']): ?>
                                        <div style="color:#888;font-size:12px;"><?php echo esc_html($r['winner_login']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($r['winner_email'] ?: '—'); ?></td>
                                <td style="text-align:right;">
                                    <?php echo $r['winning_amount'] !== null ? wp_kses_post(wc_price($r['winning_amount'])) : '—'; ?>
                                </td>
                                <td>
                                    <?php if ($order_link): ?>
                                        <a href="<?php echo esc_url($order_link); ?>">#<?php echo (int) $r['order_id']; ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                    <?php if ($r['order_status']): ?>
                                        <div style="color:#888;font-size:12px;"><?php echo esc_html($r['order_status']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php echo esc_attr($paid_class); ?>">
                                    <?php echo esc_html($r['payment_state']); ?>
                                    <?php if (!empty($r['paid_date'])): ?>
                                        <div style="color:#888;font-size:12px;"><?php echo esc_html($r['paid_date']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $resend_oid       = (int) $r['order_id'];
                                    $resend_last_sent = $resend_oid ? Azure_Auction_Winners_Report::get_invoice_resent_at($resend_oid) : '';
                                    if ($resend_oid) {
                                        $resend_confirm = sprintf(
                                            "Resend WooCommerce invoice email for order #%d (%s)?",
                                            $resend_oid,
                                            $r['winner_email'] ?: ($r['winner_name'] ?: 'this customer')
                                        );
                                    ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                            <input type="hidden" name="action" value="azure_auction_resend_invoice" />
                                            <input type="hidden" name="order_id" value="<?php echo (int) $resend_oid; ?>" />
                                            <?php wp_nonce_field('azure_auction_resend_invoice_' . (int) $resend_oid); ?>
                                            <button type="submit" class="button button-small"
                                                    onclick="return confirm(<?php echo esc_attr(wp_json_encode($resend_confirm)); ?>);">
                                                <span class="dashicons dashicons-email-alt" style="vertical-align:middle;font-size:14px;"></span>
                                                <?php _e('Send email', 'azure-plugin'); ?>
                                            </button>
                                        </form>
                                        <?php if (!empty($resend_last_sent)): ?>
                                            <div style="color:#888;font-size:11px;margin-top:3px;">
                                                <?php printf(esc_html__('last sent: %s', 'azure-plugin'), esc_html($resend_last_sent)); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php } else { ?>
                                        <em style="color:#888;">—</em>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:bold;">
                            <td colspan="4" style="text-align:right;"><?php _e('Totals', 'azure-plugin'); ?></td>
                            <td style="text-align:right;"><?php echo wp_kses_post(wc_price($azure_winner_totals['sum_winning'])); ?></td>
                            <td colspan="3">
                                <?php printf(
                                    esc_html__('%1$d items · Paid: %2$s · Outstanding: %3$s%4$s', 'azure-plugin'),
                                    (int) $azure_winner_totals['item_count'],
                                    wp_kses_post(wc_price($azure_winner_totals['sum_paid'])),
                                    wp_kses_post(wc_price($azure_winner_totals['sum_outstanding'])),
                                    $azure_winner_totals['sum_unknown'] > 0
                                        ? ' · ' . sprintf(esc_html__('Unknown: %s', 'azure-plugin'), wp_kses_post(wc_price($azure_winner_totals['sum_unknown'])))
                                        : ''
                                ); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <style>
                    .azure-pay-paid    { color: #1e7e34; font-weight: 600; }
                    .azure-pay-unpaid  { color: #b32d2e; font-weight: 600; }
                    .azure-pay-unknown { color: #856404; font-weight: 600; }
                </style>
            <?php endif; ?>

            <hr style="margin: 30px 0;" />

            <h2><span class="dashicons dashicons-groups"></span> <?php _e('Teacher Experience — runners-up (2nd & 3rd place)', 'azure-plugin'); ?></h2>
            <p class="description">
                <?php _e('Teacher Experience auctions award places to the top 3 distinct bidders. The 1st-place winner is handled automatically by the standard lifecycle. Use this section to create wc-pending orders + send invoice emails to the 2nd and 3rd place bidders. Re-running is safe — already-created orders are skipped.', 'azure-plugin'); ?>
            </p>

            <?php if (empty($azure_te_rows)): ?>
                <p><em><?php _e('No Teacher Experience auctions detected (looking for products whose title starts with "Teacher Experience").', 'azure-plugin'); ?></em></p>
            <?php else: ?>
                <?php foreach ($azure_te_rows as $te):
                    $runners = $azure_winners_report->get_te_runners_up($te['product_id']);
                    $second_done = !empty($runners['stored']['second']['order_id']);
                    $third_done  = !empty($runners['stored']['third']['order_id']);
                    $any_pending = (!$second_done && $runners['second']) || (!$third_done && $runners['third']);
                ?>
                    <div class="azure-te-card" style="border:1px solid #ccd0d4; background:#fff; padding:14px 18px; margin:14px 0; border-radius:4px;">
                        <h3 style="margin-top:0;">
                            <a href="<?php echo esc_url(get_edit_post_link($te['product_id'])); ?>"><?php echo esc_html($te['title']); ?></a>
                            <span style="color:#888;font-size:13px;font-weight:normal;">#<?php echo (int) $te['product_id']; ?></span>
                            <span style="color:#888;font-size:13px;font-weight:normal;margin-left:8px;"><?php echo esc_html($te['ended_at']); ?> · <?php echo esc_html($te['auction_status']); ?></span>
                        </h3>

                        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                            <thead>
                                <tr>
                                    <th style="width:60px;"><?php _e('Place', 'azure-plugin'); ?></th>
                                    <th><?php _e('Bidder', 'azure-plugin'); ?></th>
                                    <th><?php _e('Email', 'azure-plugin'); ?></th>
                                    <th style="text-align:right;"><?php _e('Their bid', 'azure-plugin'); ?></th>
                                    <th><?php _e('Last bid at', 'azure-plugin'); ?></th>
                                    <th><?php _e('Order', 'azure-plugin'); ?></th>
                                    <th><?php _e('Paid?', 'azure-plugin'); ?></th>
                                    <th><?php _e('Invoice', 'azure-plugin'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $place_labels = array(1 => __('1st', 'azure-plugin'), 2 => __('2nd', 'azure-plugin'), 3 => __('3rd', 'azure-plugin'));
                                foreach (array('first' => 1, 'second' => 2, 'third' => 3) as $key => $position):
                                    $bidder = $runners[$key];
                                    $stored = $runners['stored'][$key];
                                    $u = ($bidder && !empty($bidder['user_id'])) ? get_userdata((int) $bidder['user_id']) : null;
                                    // For 1st row, look up live order paid state so the table shows it
                                    // alongside 2nd/3rd. 2nd/3rd inherit it via the resolve below.
                                    $row_order_id     = !empty($stored['order_id']) ? (int) $stored['order_id'] : 0;
                                    $row_order_obj    = $row_order_id && function_exists('wc_get_order') ? wc_get_order($row_order_id) : null;
                                    $row_order_status = $row_order_obj ? $row_order_obj->get_status() : '';
                                    $row_paid_states  = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : array('processing','completed');
                                    $row_is_paid      = $row_order_obj
                                        ? (in_array($row_order_status, $row_paid_states, true) || ($row_order_obj->get_date_paid() ? true : false))
                                        : null;
                                    $row_paid_label   = !$row_order_obj
                                        ? '—'
                                        : ($row_is_paid ? __('PAID', 'azure-plugin') : __('NOT PAID', 'azure-plugin') . ' (' . esc_html($row_order_status) . ')');
                                    $row_paid_class   = $row_is_paid === true ? 'azure-pay-paid' : ($row_is_paid === false ? 'azure-pay-unpaid' : 'azure-pay-unknown');
                                ?>
                                    <tr<?php if ($position === 1) echo ' style="background:#f6f7f7;"'; ?>>
                                        <td><strong><?php echo esc_html($place_labels[$position]); ?></strong></td>
                                        <?php if ($bidder): ?>
                                            <td>
                                                <?php if ($u): ?>
                                                    <a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html($bidder['name']); ?></a>
                                                    <div style="color:#888;font-size:12px;"><?php echo esc_html($bidder['login']); ?></div>
                                                <?php else: ?>
                                                    <?php echo esc_html($bidder['name'] ?: '—'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($bidder['email'] ?: '—'); ?></td>
                                            <td style="text-align:right;"><?php echo wp_kses_post(wc_price($bidder['bid_amount'])); ?></td>
                                            <td><?php echo esc_html($bidder['last_bid_at']); ?></td>
                                            <td>
                                                <?php if (!empty($stored['order_id'])): ?>
                                                    <a href="<?php echo esc_url(Azure_Auction_Winners_Report::order_edit_url($stored['order_id'])); ?>">#<?php echo (int) $stored['order_id']; ?></a>
                                                    <div style="color:#888;font-size:12px;"><?php echo esc_html($stored['created_at']); ?></div>
                                                <?php else: ?>
                                                    <em style="color:#888;"><?php _e('not yet created', 'azure-plugin'); ?></em>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?php echo esc_attr($row_paid_class); ?>">
                                                <?php echo esc_html($row_paid_label); ?>
                                                <?php if ($row_order_obj && $row_order_obj->get_date_paid()): ?>
                                                    <div style="color:#888;font-size:12px;"><?php echo esc_html($row_order_obj->get_date_paid()->date('Y-m-d H:i:s')); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($stored['emailed_at'])): ?>
                                                    <span style="color:#1e7e34;font-weight:600;"><?php _e('sent', 'azure-plugin'); ?></span>
                                                    <div style="color:#888;font-size:12px;"><?php echo esc_html($stored['emailed_at']); ?></div>
                                                <?php elseif (!empty($stored['order_id'])): ?>
                                                    <span style="color:#b32d2e;font-weight:600;"><?php _e('order exists, email NOT sent', 'azure-plugin'); ?></span>
                                                <?php else: ?>
                                                    <em style="color:#888;"><?php _e('not yet sent', 'azure-plugin'); ?></em>
                                                <?php endif; ?>
                                                <?php if (!empty($stored['order_id'])):
                                                    $te_resend_oid   = (int) $stored['order_id'];
                                                    $te_resent_at    = Azure_Auction_Winners_Report::get_invoice_resent_at($te_resend_oid);
                                                    $te_resend_label = sprintf(
                                                        "Resend WooCommerce invoice email for %s order #%d (%s)?",
                                                        $place_labels[$position],
                                                        $te_resend_oid,
                                                        $bidder['email'] ?: ($bidder['name'] ?: 'this customer')
                                                    );
                                                ?>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:6px 0 0 0;">
                                                        <input type="hidden" name="action" value="azure_auction_resend_invoice" />
                                                        <input type="hidden" name="order_id" value="<?php echo (int) $te_resend_oid; ?>" />
                                                        <?php wp_nonce_field('azure_auction_resend_invoice_' . (int) $te_resend_oid); ?>
                                                        <button type="submit" class="button button-small"
                                                                onclick="return confirm(<?php echo esc_attr(wp_json_encode($te_resend_label)); ?>);">
                                                            <span class="dashicons dashicons-email-alt" style="vertical-align:middle;font-size:14px;"></span>
                                                            <?php _e('Send email', 'azure-plugin'); ?>
                                                        </button>
                                                    </form>
                                                    <?php if (!empty($te_resent_at)): ?>
                                                        <div style="color:#888;font-size:11px;margin-top:3px;">
                                                            <?php printf(esc_html__('last resent: %s', 'azure-plugin'), esc_html($te_resent_at)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php else: ?>
                                            <td colspan="7"><em style="color:#888;"><?php _e('No bidder at this position.', 'azure-plugin'); ?></em></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($any_pending): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                                <input type="hidden" name="action" value="azure_auction_create_te_runner_up_orders" />
                                <input type="hidden" name="product_id" value="<?php echo (int) $te['product_id']; ?>" />
                                <?php wp_nonce_field('azure_auction_te_runner_up_' . (int) $te['product_id']); ?>
                                <?php
                                $confirm_lines = array();
                                foreach (array('second' => 2, 'third' => 3) as $key => $position) {
                                    $b = $runners[$key];
                                    $s = $runners['stored'][$key];
                                    if (!$b || !empty($s['order_id'])) { continue; }
                                    $confirm_lines[] = sprintf('%d) %s — %s', $position, $b['name'] ?: $b['login'], number_format((float) $b['bid_amount'], 2));
                                }
                                $confirm_text = sprintf(
                                    "Create order(s) + send invoice email(s) for:\n\n%s\n\nThis cannot be undone from the UI. Proceed?",
                                    implode("\n", $confirm_lines)
                                );
                                ?>
                                <button type="submit" class="button button-primary"
                                        onclick="return confirm(<?php echo esc_attr(wp_json_encode($confirm_text)); ?>);">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php _e('Create order(s) + send invoice email(s)', 'azure-plugin'); ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <p style="margin-top:10px;color:#1e7e34;font-weight:600;">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('All applicable runner-up orders are already created.', 'azure-plugin'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php
        }
        ?>
    </div>
    <?php endif; ?>
</div>
