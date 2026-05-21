<?php
/**
 * Frontend template for the Auction Carousel BB module.
 *
 * Beaver Builder renders modules by `include`ing this file
 * (see FLBuilder::render_module_html(), which fires
 * $module->path('includes/frontend.php')). The class's render_module()
 * method is NOT called by BB — this template is the canonical entry
 * point. Variables exposed by BB at include time:
 *   $module    PTAAuctionCarouselModule instance
 *   $settings  stdClass with the saved settings (items_per_row, etc.)
 *   $id        the module's node ID (used for unique CSS targeting)
 *
 * @var stdClass $settings
 * @var string   $id
 * @var FLBuilderModule $module
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooCommerce') || !class_exists('Azure_Auction_Module')) {
    // Auction module disabled or WooCommerce missing — render an admin-only
    // hint so the editor sees something, but emit nothing on the live page.
    if (current_user_can('edit_posts')) {
        echo '<div class="pta-auction-carousel pta-auction-carousel--empty"><p><em>'
            . esc_html__('Auction Carousel: WooCommerce or the PTA Tools Auction module is not active.', 'azure-plugin')
            . '</em></p></div>';
    }
    return;
}

$items_per_row = isset($settings->items_per_row) ? (int) $settings->items_per_row : 4;
$cycle_seconds = isset($settings->cycle_seconds) ? (int) $settings->cycle_seconds : 5;
$show_bid_info = isset($settings->show_bid_info) ? $settings->show_bid_info : 'yes';

$items_per_row = max(1, min(8, $items_per_row));
$cycle_seconds = max(2, min(120, $cycle_seconds));

$auction = Azure_Auction_Module::get_instance();
$items   = $auction->get_active_auction_display_items();

if (empty($items)) {
    echo '<div class="pta-auction-carousel pta-auction-carousel--empty">';
    echo '<p>' . esc_html__('No active auctions yet.', 'azure-plugin') . '</p>';
    echo '</div>';
    return;
}

$total      = count($items);
$page_count = max(1, (int) ceil($total / $items_per_row));
$node_id    = isset($id) ? esc_attr($id) : 'auction-carousel-' . wp_rand(1000, 9999);
?>
<div class="pta-auction-carousel"
     data-items-per-row="<?php echo (int) $items_per_row; ?>"
     data-cycle-ms="<?php echo (int) $cycle_seconds * 1000; ?>"
     data-page-count="<?php echo (int) $page_count; ?>"
     data-node="<?php echo $node_id; ?>"
     style="--auction-cc-cols: <?php echo (int) $items_per_row; ?>;">

    <button type="button" class="pta-ac-nav pta-ac-prev" aria-label="<?php esc_attr_e('Previous', 'azure-plugin'); ?>">&#8249;</button>

    <div class="pta-ac-viewport">
        <div class="pta-ac-track" style="grid-auto-columns: calc(100% / <?php echo (int) $items_per_row; ?>);">
            <?php foreach ($items as $idx => $item) :
                $page_idx = (int) floor($idx / $items_per_row);
            ?>
            <a class="pta-ac-card"
               data-product-id="<?php echo (int) $item['id']; ?>"
               data-page="<?php echo (int) $page_idx; ?>"
               href="<?php echo esc_url($item['link']); ?>">
                <div class="pta-ac-card-image">
                    <?php if (!empty($item['image'])) : ?>
                        <?php echo $item['image']; // already escaped by wp_get_attachment_image ?>
                    <?php else : ?>
                        <div class="pta-ac-card-no-image"></div>
                    <?php endif; ?>
                </div>
                <h3 class="pta-ac-card-title"><?php echo esc_html($item['title']); ?></h3>
                <?php if ($show_bid_info === 'yes') : ?>
                <div class="pta-ac-card-bid">
                    <span class="pta-ac-card-label">
                        <?php echo $item['has_bids']
                            ? esc_html__('Current bid', 'azure-plugin')
                            : esc_html__('Starting bid', 'azure-plugin'); ?>
                    </span>
                    <?php if ($item['has_bids']) : ?>
                    <span class="pta-ac-card-bidder">(<span class="pta-ac-card-bidder-name"><?php echo esc_html($item['bidder']); ?></span>)</span>
                    <?php endif; ?>
                    <span class="pta-ac-card-price"><?php echo wp_kses_post(wc_price($item['price'])); ?></span>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="pta-ac-nav pta-ac-next" aria-label="<?php esc_attr_e('Next', 'azure-plugin'); ?>">&#8250;</button>

    <?php if ($page_count > 1) : ?>
    <div class="pta-ac-pager" role="tablist" aria-label="<?php esc_attr_e('Auction carousel pages', 'azure-plugin'); ?>">
        <?php for ($p = 0; $p < $page_count; $p++) : ?>
        <button type="button"
                class="pta-ac-dot<?php echo $p === 0 ? ' is-current' : ''; ?>"
                data-page="<?php echo (int) $p; ?>"
                aria-label="<?php echo esc_attr(sprintf(__('Show page %d of %d', 'azure-plugin'), $p + 1, $page_count)); ?>"></button>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
