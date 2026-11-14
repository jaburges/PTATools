<?php
/**
 * Tickets → Visualizer: who has each seat at a performance, and the
 * four-page printout for the door.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooCommerce') || !class_exists('Azure_Tickets_Visualizer')) {
    echo '<div class="notice notice-warning inline"><p>' . esc_html__('WooCommerce is needed for the seat visualizer.', 'azure-plugin') . '</p></div>';
    return;
}
if (!current_user_can(Azure_Tickets_Visualizer::CAP)) {
    echo '<div class="notice notice-warning inline"><p>' . esc_html__('The visualizer shows buyers\' names and emails, so only shop managers and administrators can open it.', 'azure-plugin') . '</p></div>';
    return;
}

$events = Azure_Tickets_Visualizer::events();
list($event_id, $product_id) = Azure_Tickets_Visualizer::pick($events, absint($_GET['product'] ?? 0));
?>
<div class="ptv">
    <?php if (!$events): ?>
        <h2><?php esc_html_e('Seat visualizer', 'azure-plugin'); ?></h2>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e('No ticket product has a venue yet. Choose a venue on the ticket product\'s Seating tab, then come back here.', 'azure-plugin'); ?>
        </p></div>
    </div>
    <?php return; endif; ?>

    <?php
    $event = $events[$event_id];
    $sold = Azure_Tickets_Visualizer::sold_counts(array_keys($event['performances']));
    $snap = Azure_Tickets_Visualizer::snapshot($product_id);
    $tab_url = admin_url('admin.php?page=azure-plugin-tickets&tab=visualizer');
    ?>
    <div class="ptv-head">
        <h2><?php echo esc_html($event['name']); ?></h2>
        <?php if (count($events) > 1): ?>
        <form method="get" class="ptv-pick">
            <input type="hidden" name="page" value="azure-plugin-tickets">
            <input type="hidden" name="tab" value="visualizer">
            <label for="ptv-event"><strong><?php esc_html_e('Event', 'azure-plugin'); ?></strong></label>
            <select id="ptv-event" name="product" onchange="this.form.submit()">
                <?php foreach ($events as $eid => $e): ?>
                    <option value="<?php echo (int) $eid; ?>" <?php selected($eid, $event_id); ?>><?php echo esc_html($e['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="button"><?php esc_html_e('Show', 'azure-plugin'); ?></button></noscript>
        </form>
        <?php endif; ?>
    </div>

    <nav class="ptv-chips" aria-label="<?php esc_attr_e('Performances', 'azure-plugin'); ?>">
        <?php foreach ($event['performances'] as $pid => $label): ?>
            <a class="ptv-chip<?php echo $pid === $product_id ? ' is-current' : ''; ?>" href="<?php echo esc_url(add_query_arg('product', (int) $pid, $tab_url)); ?>"<?php echo $pid === $product_id ? ' aria-current="page"' : ''; ?>>
                <strong><?php echo esc_html($label); ?></strong>
                <small><?php
                    /* translators: %d: seats sold */
                    echo esc_html(sprintf(_n('%d seat sold', '%d seats sold', $sold[$pid] ?? 0, 'azure-plugin'), $sold[$pid] ?? 0));
                    if (Azure_Tickets_Config::sales_closed($pid)) {
                        echo ' · ' . esc_html__('online sales closed', 'azure-plugin');
                    }
                ?></small>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$snap): ?>
        <div class="notice notice-info inline"><p><?php esc_html_e('This performance has no venue seating layout.', 'azure-plugin'); ?></p></div>
    </div>
    <?php return; endif; ?>

    <?php $st = $snap['stats']; ?>
    <div class="ptv-toolbar">
        <p class="ptv-stats">
            <?php
            /* translators: 1: seats sold, 2: capacity */
            echo '<strong>' . esc_html(sprintf(__('%1$d of %2$d sold', 'azure-plugin'), $st['sold'], $st['capacity'])) . '</strong>';
            /* translators: %d: tickets checked in */
            $in_label = __('%d checked in', 'azure-plugin');
            echo '<span class="ptv-in-count" data-label="' . esc_attr($in_label) . '"' . ($st['checked_in'] ? '' : ' hidden') . '> · '
                . esc_html(sprintf($in_label, $st['checked_in'])) . '</span>';
            if ($st['held']) {
                /* translators: %d: seats in carts */
                echo ' · ' . esc_html(sprintf(__('%d in carts', 'azure-plugin'), $st['held']));
            }
            if ($st['ordered']) {
                /* translators: %d: seats awaiting payment */
                echo ' · ' . esc_html(sprintf(__('%d awaiting payment', 'azure-plugin'), $st['ordered']));
            }
            /* translators: %d: free seats */
            echo ' · ' . esc_html(sprintf(__('%d free', 'azure-plugin'), $st['free']));
            if ($st['free_closed']) {
                /* translators: %d: free seats in sections not on public sale */
                echo ' · ' . esc_html(sprintf(__('%d free but not on public sale', 'azure-plugin'), $st['free_closed']));
            }
            ?>
        </p>
        <button type="button" class="button button-primary ptv-print-open">
            <span class="dashicons dashicons-printer" style="vertical-align:text-bottom;"></span> <?php esc_html_e('Printout', 'azure-plugin'); ?>
        </button>
    </div>

    <div class="ptv-toolbar">
        <div class="ptv-legend">
            <span><i class="ptv-key is-free"></i> <?php esc_html_e('Free', 'azure-plugin'); ?></span>
            <span><i class="ptv-key is-sold"></i> <?php esc_html_e('Sold', 'azure-plugin'); ?></span>
            <span><i class="ptv-key is-checked-in"></i> <?php esc_html_e('Checked in', 'azure-plugin'); ?></span>
            <span><i class="ptv-key is-held"></i> <?php esc_html_e('In a cart or awaiting payment', 'azure-plugin'); ?></span>
            <span><i class="ptv-key is-closed"></i> <?php esc_html_e('Not on public sale', 'azure-plugin'); ?></span>
            <span><i class="ptv-key is-group"></i> <?php esc_html_e('Outline: bought together', 'azure-plugin'); ?></span>
        </div>
        <label class="ptv-find">
            <span class="screen-reader-text"><?php esc_html_e('Find a name, email or order number', 'azure-plugin'); ?></span>
            <input type="search" class="ptv-find-input" placeholder="<?php esc_attr_e('Find a name, email or order #', 'azure-plugin'); ?>">
        </label>
    </div>

    <?php $box = Azure_Tickets_Visualizer::bounds($snap['layout']); ?>
    <?php
    $door = array(
        'url'     => admin_url('admin-ajax.php'),
        'action'  => Azure_Tickets_Visualizer::DOOR_ACTION,
        'nonce'   => wp_create_nonce(Azure_Tickets_Visualizer::DOOR_ACTION),
        'product' => $product_id,
    );
    ?>
    <div class="ptv-map" data-w="<?php echo (int) $box['w']; ?>" data-h="<?php echo (int) $box['h']; ?>" data-door="<?php echo esc_attr(wp_json_encode($door)); ?>">
        <?php echo Azure_Tickets_Visualizer::map_html($snap['layout'], $snap['sections'], $snap['occ'], array('focusable' => true)); // Escaped as it is built. ?>
    </div>
    <p class="ptv-note"><?php esc_html_e('Hover over (or tab to) a seat to see who has it; the seats bought with it light up. Click a sold seat to open its order. Right-click it (or press the menu key) to check that order in without a QR code, all at once or seat by seat. The map shows the moment the page loaded, apart from your own check-ins; reload to catch up.', 'azure-plugin'); ?></p>
    <div class="ptv-tip" role="status" hidden></div>

    <script type="application/json" id="ptv-data"><?php echo wp_json_encode(Azure_Tickets_Visualizer::client_data($snap), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    <div class="ptv-menu" role="menu" hidden></div>

    <div class="ptv-modal ptv-door" hidden role="dialog" aria-modal="true" aria-labelledby="ptv-door-title">
        <div class="ptv-modal__box ptv-door__box">
            <h2 id="ptv-door-title"></h2>
            <p class="ptv-door__sub"></p>
            <div class="ptv-door__body" aria-live="polite"></div>
            <div class="ptv-modal__actions ptv-door__actions"></div>
        </div>
    </div>

    <div class="ptv-modal ptv-print" hidden role="dialog" aria-modal="true" aria-labelledby="ptv-modal-title">
        <form class="ptv-modal__box" data-url="<?php echo esc_url(Azure_Tickets_Visualizer::print_url(0)); ?>">
            <h2 id="ptv-modal-title"><?php esc_html_e('Print the seating map', 'azure-plugin'); ?></h2>
            <p><?php esc_html_e('A full-size map across four landscape pages that tape together, with every buyer\'s name on their seats and the free seats left blank, for checking people in at the door and selling the rest by hand.', 'azure-plugin'); ?></p>
            <fieldset>
                <legend><?php esc_html_e('Performance', 'azure-plugin'); ?></legend>
                <div class="ptv-days">
                    <?php foreach ($event['performances'] as $pid => $label): ?>
                        <label><input type="radio" name="product" value="<?php echo (int) $pid; ?>" <?php checked($pid, $product_id); ?>> <?php echo esc_html($label); ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset>
                <legend><label for="ptv-paper"><?php esc_html_e('Paper', 'azure-plugin'); ?></label></legend>
                <select id="ptv-paper" name="paper">
                    <?php foreach (Azure_Tickets_Visualizer::PAPERS as $key => $p): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </fieldset>
            <fieldset>
                <label><input type="checkbox" name="names" value="1" checked> <?php esc_html_e('Write names on the sold seats', 'azure-plugin'); ?></label>
            </fieldset>
            <div class="ptv-modal__actions">
                <button type="button" class="button ptv-print-cancel"><?php esc_html_e('Cancel', 'azure-plugin'); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e('Open print preview', 'azure-plugin'); ?></button>
            </div>
        </form>
    </div>
</div>
