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
    } else {
        $text = (string) wp_unslash($_POST['pta_assign_seats'][$order_id] ?? '');
        $r = Azure_Tickets_Assign::save($order, $product_id, $text, $orders[$order_id]);
        if (!$r['ok']) {
            $notice = array('error', $r['errors']);
            $unsaved = array($order_id => $text);
        } else {
            /* translators: 1: order number, 2: added, 3: removed, 4: renamed */
            $msg = sprintf(__('Order #%1$s saved: %2$d added, %3$d removed, %4$d renamed.', 'azure-plugin'), $order->get_order_number(), $r['added'], $r['removed'], $r['renamed']);
            if (!empty($_POST['pta_assign_email'][$order_id]) && class_exists('Azure_Tickets_Generator')) {
                (new Azure_Tickets_Generator())->send_ticket_email($order_id);
                $order->add_order_note(__('Cast & crew seats emailed to the customer.', 'azure-plugin'));
                $msg .= ' ' . __('Tickets emailed.', 'azure-plugin');
            }
            $notice = array('success', array($msg));
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
    <table class="widefat striped pta-assign__table">
        <thead><tr>
            <th><?php esc_html_e('Order', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Family', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Tickets', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Seats (one per line: seat, then name)', 'azure-plugin'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $oid => $row): $o = $row['order']; ?>
            <tr>
                <td><a href="<?php echo esc_url($o->get_edit_order_url()); ?>">#<?php echo esc_html($o->get_order_number()); ?></a></td>
                <td><?php echo esc_html(trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name())); ?><br><small><?php echo esc_html($o->get_billing_email()); ?></small></td>
                <td><?php echo (int) count($row['assigned']); ?> / <?php echo (int) $row['qty']; ?></td>
                <td colspan="2">
                    <form method="post" class="pta-assign__form">
                        <?php wp_nonce_field('pta_assign_seats_' . $product_id); ?>
                        <input type="hidden" name="pta_assign_order" value="<?php echo (int) $oid; ?>">
                        <textarea name="pta_assign_seats[<?php echo (int) $oid; ?>]" rows="<?php echo (int) max(2, $row['qty']); ?>" placeholder="D4 Jane Smith" <?php disabled(!$can_edit); ?>><?php echo esc_textarea($unsaved[$oid] ?? Azure_Tickets_Assign::as_text($row['assigned'])); ?></textarea>
                        <div class="pta-assign__actions">
                            <label><input type="checkbox" name="pta_assign_email[<?php echo (int) $oid; ?>]" value="1"> <?php esc_html_e('Email tickets', 'azure-plugin'); ?></label>
                            <button type="submit" class="button button-primary" <?php disabled(!$can_edit); ?>><?php esc_html_e('Save', 'azure-plugin'); ?></button>
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
</style>
