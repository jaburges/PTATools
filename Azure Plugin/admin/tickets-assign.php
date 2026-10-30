<?php
/**
 * Tickets → Assign seats: give seats to cast & crew (VIP) orders from the
 * list provided outside the website.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooCommerce') || !class_exists('Azure_Tickets_Assign')) {
    echo '<div class="notice notice-warning inline"><p>' . esc_html__('WooCommerce is needed to assign seats.', 'azure-plugin') . '</p></div>';
    return;
}

$can_edit = current_user_can('manage_woocommerce');
$performances = array();
foreach ((array) wc_get_products(array('type' => 'ticket', 'status' => array('publish', 'private', 'draft'), 'limit' => 200)) as $p) {
    if (!Azure_Tickets_Config::vip_products($p->get_id())) {
        continue;
    }
    foreach (Azure_Tickets_Config::performances($p->get_id()) as $pid) {
        $performance = $pid === $p->get_id() ? $p : wc_get_product($pid);
        $ts = Azure_Tickets_Config::event_ts($pid);
        $performances[$pid] = ($performance ? $performance->get_name() : $p->get_name()) . ($ts ? ' — ' . wp_date('D j M, g:ia', $ts) : '');
    }
}
$product_id = isset($_GET['product']) ? absint($_GET['product']) : 0;
if (!isset($performances[$product_id])) {
    $product_id = $performances ? (int) array_key_first($performances) : 0;
}

$notice = null;
$unsaved = array();
if ($product_id && $can_edit && isset($_POST['pta_assign_order'])) {
    check_admin_referer('pta_assign_seats_' . $product_id);
    $order_id = absint($_POST['pta_assign_order']);
    $orders = Azure_Tickets_Assign::vip_orders($product_id);
    $order = isset($orders[$order_id]) ? wc_get_order($order_id) : null;
    if (!$order) {
        $notice = array('error', array(__('That order isn\'t a paid cast & crew order for this performance.', 'azure-plugin')));
    } elseif (!empty($_POST['pta_assign_test'])) {
        $me = wp_get_current_user()->user_email;
        if (!Azure_Tickets_Assign::assigned($order_id, $product_id)) {
            $notice = array('error', array(__('No test sent: save this order\'s seats first.', 'azure-plugin')));
        } elseif (class_exists('Azure_Tickets_Generator') && (new Azure_Tickets_Generator())->send_ticket_email($order_id, $me)) {
            /* translators: 1: order number, 2: admin's email address */
            $notice = array('success', array(sprintf(__('Test of order #%1$s\'s email sent to %2$s. The family was not emailed.', 'azure-plugin'), $order->get_order_number(), $me)));
        } else {
            $notice = array('error', array(__('The test email could not be sent. Check the site\'s email settings.', 'azure-plugin')));
        }
    } else {
        $text = (string) wp_unslash($_POST['pta_assign_seats'][$order_id] ?? '');
        $r = Azure_Tickets_Assign::save($order, $product_id, $text, $orders[$order_id]);
        if (!$r['ok']) {
            $notice = array('error', $r['errors']);
            $unsaved = array($order_id => $text);
        } else {
            /* translators: 1: order number, 2: added, 3: removed, 4: renamed */
            $msg = sprintf(__('Order #%1$s saved: %2$d added, %3$d removed, %4$d renamed.', 'azure-plugin'), $order->get_order_number(), $r['added'], $r['removed'], $r['renamed']);
            $type = 'success';
            if (!empty($_POST['pta_assign_email'][$order_id]) && class_exists('Azure_Tickets_Generator')) {
                if ((new Azure_Tickets_Generator())->send_ticket_email($order_id)) {
                    $order->add_order_note(__('Cast & crew seats emailed to the customer.', 'azure-plugin'));
                    /* translators: %s: buyer's email address */
                    $msg .= ' ' . sprintf(__('Tickets emailed to %s.', 'azure-plugin'), $order->get_billing_email());
                } else {
                    $type = 'error';
                    $msg .= ' ' . __('The tickets email could not be sent; nothing was emailed.', 'azure-plugin');
                }
            }
            $notice = array($type, array($msg));
        }
    }
}

if ($product_id && $can_edit && isset($_POST['pta_assign_csv_upload'])) {
    check_admin_referer('pta_assign_csv_upload_' . $product_id);
    $file = $_FILES['pta_assign_csv'] ?? null;
    if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $notice = array('error', array(__('Choose a CSV file to upload.', 'azure-plugin')));
    } elseif ((int) $file['size'] > MB_IN_BYTES) {
        $notice = array('error', array(__('That file is too big for a seat list (over 1 MB).', 'azure-plugin')));
    } else {
        $order_rows = Azure_Tickets_Assign::order_rows($product_id);
        $numbers = array();
        foreach ($order_rows as $oid => $r) {
            $numbers[$r['number']] = $oid;
            $numbers[(string) $oid] = $oid;
        }
        $parsed = Azure_Tickets_Assign::parse_csv((string) file_get_contents($file['tmp_name']), $numbers);
        if ($parsed['errors']) {
            $notice = array('error', array_merge(array(__('Nothing was saved. Fix these rows and upload again:', 'azure-plugin')), $parsed['errors']));
        } elseif (!$parsed['orders']) {
            $notice = array('error', array(__('No seats found in the file. Fill in the Seat column, e.g. D4.', 'azure-plugin')));
        } else {
            $r = Azure_Tickets_Assign::import($product_id, $parsed['orders'], wp_list_pluck($order_rows, 'paid'), 'wc_get_order', wp_list_pluck($order_rows, 'number'));
            $notice = $r['ok']
                /* translators: 1: orders, 2: added, 3: removed, 4: renamed */
                ? array('success', array(sprintf(_n('CSV loaded: %1$d order updated (seats: %2$d added, %3$d removed, %4$d renamed). No emails were sent.', 'CSV loaded: %1$d orders updated (seats: %2$d added, %3$d removed, %4$d renamed). No emails were sent.', $r['orders'], 'azure-plugin'), $r['orders'], $r['added'], $r['removed'], $r['renamed'])))
                : array('error', !empty($r['partial']) ? $r['errors'] : array_merge(array(__('Nothing was saved. Fix these and upload again:', 'azure-plugin')), $r['errors']));
        }
    }
}

$orders = $product_id ? Azure_Tickets_Assign::vip_orders($product_id) : array();
$sections = $product_id ? Azure_Tickets_Config::sections($product_id) : array();
$paid = array_sum($orders);
$assigned_total = 0;
$rows = array();
foreach ($orders as $oid => $qty) {
    $assigned = Azure_Tickets_Assign::assigned($oid, $product_id);
    $assigned_total += count($assigned);
    $rows[$oid] = array('order' => wc_get_order($oid), 'qty' => $qty, 'assigned' => $assigned);
}
?>
<div class="pta-assign">
    <h2><?php esc_html_e('Assign cast & crew seats', 'azure-plugin'); ?></h2>
    <p class="description">
        <?php esc_html_e('Families buy the hidden cast & crew product without choosing a seat. Paste the seats for each order below, one per line, e.g. "D4 Jane Smith". Saving updates the seat map straight away; tick "Email tickets" to send the family their seats and QR codes.', 'azure-plugin'); ?>
    </p>

    <?php if (!$performances): ?>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e('No performance has a cast & crew product yet. On the ticket product, open Seating → "Cast & crew (VIP) seat assignment" and choose the hidden product.', 'azure-plugin'); ?>
        </p></div>
    </div>
    <?php return; endif; ?>

    <form method="get" class="pta-assign__pick">
        <input type="hidden" name="page" value="azure-plugin-tickets">
        <input type="hidden" name="tab" value="assign">
        <label for="pta-assign-product"><strong><?php esc_html_e('Performance', 'azure-plugin'); ?></strong></label>
        <select id="pta-assign-product" name="product" onchange="this.form.submit()">
            <?php foreach ($performances as $pid => $label): ?>
                <option value="<?php echo (int) $pid; ?>" <?php selected($pid, $product_id); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="button"><?php esc_html_e('Show', 'azure-plugin'); ?></button></noscript>
    </form>

    <?php if ($notice): ?>
        <div class="notice notice-<?php echo esc_attr($notice[0]); ?> inline"><p><?php echo implode('<br>', array_map('esc_html', $notice[1])); ?></p></div>
    <?php endif; ?>

    <p class="pta-assign__summary">
        <?php
        /* translators: 1: orders, 2: tickets bought, 3: seats assigned */
        printf(esc_html__('%1$d paid orders · %2$d cast & crew tickets · %3$d seats assigned', 'azure-plugin'), count($orders), $paid, $assigned_total);
        if ($paid > $assigned_total) {
            echo ' · <strong>' . esc_html(sprintf(__('%d still to assign', 'azure-plugin'), $paid - $assigned_total)) . '</strong>';
        }
        ?>
    </p>

    <?php if (!$rows): ?>
        <p><?php esc_html_e('No paid cast & crew orders for this performance yet.', 'azure-plugin'); ?></p>
    <?php else: ?>
    <div class="pta-assign__csv">
        <p>
            <strong><?php esc_html_e('Assign from a spreadsheet', 'azure-plugin'); ?></strong><br>
            <?php esc_html_e('Download the template (every order, one row per ticket, with any seats already assigned), fill in the Seat column, and upload it. Each order in the file gets exactly the seats listed; orders with no seats in the file are left as they are. Nothing is saved unless the whole file checks out, and uploading never emails anyone.', 'azure-plugin'); ?>
        </p>
        <p>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=azure_tickets_assign_csv&product=' . (int) $product_id), 'pta_assign_csv_' . $product_id)); ?>"><span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span> <?php esc_html_e('Download CSV template', 'azure-plugin'); ?></a>
        </p>
        <form method="post" enctype="multipart/form-data" class="pta-assign__upload">
            <?php wp_nonce_field('pta_assign_csv_upload_' . $product_id); ?>
            <input type="file" name="pta_assign_csv" accept=".csv,text/csv" required <?php disabled(!$can_edit); ?>>
            <button type="submit" name="pta_assign_csv_upload" value="1" class="button button-primary" <?php disabled(!$can_edit); ?>><?php esc_html_e('Upload CSV', 'azure-plugin'); ?></button>
        </form>
    </div>
    <table class="widefat striped pta-assign__table">
        <thead><tr>
            <th><?php esc_html_e('Order', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Family', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Tickets', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Seats (one per line: seat, then name)', 'azure-plugin'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $oid => $row): $o = $row['order']; $emailed = class_exists('Azure_Tickets_Generator') ? Azure_Tickets_Generator::emailed_at($o) : 0; ?>
            <tr>
                <td><a href="<?php echo esc_url($o->get_edit_order_url()); ?>">#<?php echo esc_html($o->get_order_number()); ?></a></td>
                <td><?php echo esc_html(trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name())); ?><br><small><?php echo esc_html($o->get_billing_email()); ?></small></td>
                <td>
                    <?php echo (int) count($row['assigned']); ?> / <?php echo (int) $row['qty']; ?>
                    <?php if ($emailed): ?>
                    <div class="pta-assign__sent is-sent" title="<?php echo esc_attr(wp_date('l j F Y, g:i a', $emailed)); ?>"><span class="dashicons dashicons-yes-alt"></span> <?php
                        /* translators: %s: date and time */
                        echo esc_html(sprintf(__('Emailed %s', 'azure-plugin'), wp_date('D j M, g:ia', $emailed)));
                    ?></div>
                    <?php else: ?>
                    <div class="pta-assign__sent"><span class="dashicons dashicons-email"></span> <?php esc_html_e('Not emailed yet', 'azure-plugin'); ?></div>
                    <?php endif; ?>
                </td>
                <td colspan="2">
                    <form method="post" class="pta-assign__form">
                        <?php wp_nonce_field('pta_assign_seats_' . $product_id); ?>
                        <input type="hidden" name="pta_assign_order" value="<?php echo (int) $oid; ?>">
                        <textarea name="pta_assign_seats[<?php echo (int) $oid; ?>]" rows="<?php echo (int) max(2, $row['qty']); ?>" placeholder="D4 Jane Smith" <?php disabled(!$can_edit); ?>><?php echo esc_textarea($unsaved[$oid] ?? Azure_Tickets_Assign::as_text($row['assigned'])); ?></textarea>
                        <div class="pta-assign__actions">
                            <label><input type="checkbox" name="pta_assign_email[<?php echo (int) $oid; ?>]" value="1"> <?php echo esc_html($emailed ? __('Email tickets again', 'azure-plugin') : __('Email tickets', 'azure-plugin')); ?></label>
                            <span>
                                <?php if ($row['assigned']): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=azure_tickets_email_preview&order=' . (int) $oid), 'pta_ticket_email_preview')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Preview email', 'azure-plugin'); ?></a>
                                <button type="submit" name="pta_assign_test" value="1" class="button" <?php disabled(!$can_edit); ?>><?php esc_html_e('Send a test to me', 'azure-plugin'); ?></button>
                                <?php endif; ?>
                                <button type="submit" class="button button-primary" <?php disabled(!$can_edit); ?>><?php esc_html_e('Save', 'azure-plugin'); ?></button>
                            </span>
                        </div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3><?php esc_html_e('Free seats', 'azure-plugin'); ?></h3>
    <?php foreach (Azure_Tickets_Assign::free_seats($product_id) as $sid => $by_row): ?>
        <p><strong><?php echo esc_html($sections[$sid]['name']); ?></strong>
        <?php if (!$sections[$sid]['on_sale']): ?><em>(<?php esc_html_e('not on public sale yet', 'azure-plugin'); ?>)</em><?php endif; ?><br>
        <?php foreach ($by_row as $row_letter => $nums): ?>
            <?php echo esc_html($row_letter . ': ' . implode(', ', $nums)); ?><br>
        <?php endforeach; ?>
        </p>
    <?php endforeach; ?>
</div>
<style>
.pta-assign__pick { margin: 12px 0; display: flex; gap: 8px; align-items: center; }
.pta-assign__table textarea { width: 100%; font-family: monospace; }
.pta-assign__actions { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
.pta-assign__summary { font-size: 14px; }
.pta-assign__csv { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; padding: 4px 14px 12px; margin: 12px 0 16px; max-width: 900px; }
.pta-assign__upload { display: flex; gap: 8px; align-items: center; }
.pta-assign__sent { margin-top: 6px; font-size: 12px; color: #646970; white-space: nowrap; }
.pta-assign__sent .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
.pta-assign__sent.is-sent { color: #00a32a; font-weight: 600; }
</style>
