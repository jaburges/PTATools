<?php
/**
 * Auction Module Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
$auction_enabled = !empty($settings['enable_auction']);

$active_auctions = 0;
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
                        </h3>
                        <p style="margin:4px 0 12px;color:#555;">
                            <?php printf(
                                esc_html__('Winner: %1$s (%2$s) — winning bid %3$s, order %4$s, %5$s', 'azure-plugin'),
                                esc_html($te['winner_name'] ?: '—'),
                                esc_html($te['winner_email'] ?: '—'),
                                wp_kses_post($te['winning_amount'] !== null ? wc_price($te['winning_amount']) : '—'),
                                $te['order_id'] ? '<a href="' . esc_url(Azure_Auction_Winners_Report::order_edit_url($te['order_id'])) . '">#' . (int) $te['order_id'] . '</a>' : '—',
                                esc_html($te['payment_state'])
                            ); ?>
                        </p>

                        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                            <thead>
                                <tr>
                                    <th style="width:60px;"><?php _e('Place', 'azure-plugin'); ?></th>
                                    <th><?php _e('Bidder', 'azure-plugin'); ?></th>
                                    <th><?php _e('Email', 'azure-plugin'); ?></th>
                                    <th style="text-align:right;"><?php _e('Their bid', 'azure-plugin'); ?></th>
                                    <th><?php _e('Last bid at', 'azure-plugin'); ?></th>
                                    <th><?php _e('Order', 'azure-plugin'); ?></th>
                                    <th><?php _e('Invoice', 'azure-plugin'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array('second' => 2, 'third' => 3) as $key => $position):
                                    $bidder = $runners[$key];
                                    $stored = $runners['stored'][$key];
                                    $u = ($bidder && !empty($bidder['user_id'])) ? get_userdata((int) $bidder['user_id']) : null;
                                ?>
                                    <tr>
                                        <td><strong><?php echo $position === 2 ? esc_html__('2nd', 'azure-plugin') : esc_html__('3rd', 'azure-plugin'); ?></strong></td>
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
                                                        "Resend WooCommerce invoice email for runner-up order #%d (%s)?",
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
                                            <td colspan="6"><em style="color:#888;"><?php _e('No bidder at this position.', 'azure-plugin'); ?></em></td>
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
