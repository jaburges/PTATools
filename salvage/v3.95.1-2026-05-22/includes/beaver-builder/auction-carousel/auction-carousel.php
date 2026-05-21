<?php
/**
 * Auction Carousel Beaver Builder Module
 *
 * Renders a horizontal, auto-advancing carousel of active WooCommerce
 * "auction" product-type items, reusing the same data source as the
 * [auction-display] shortcode (Azure_Auction_Module::get_active_auction_display_items()).
 *
 * Module options:
 *   - items_per_row  (1-8)   how many cards to show per visible "page"
 *   - cycle_seconds  (2-120) autoplay interval before sliding to the next page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard the CLASS declaration only — do NOT early-return from this file.
// FLBuilder::register_module() at the bottom must always run. Beaver
// Builder's $modules registry is per-request; if some other code path
// loads this file first and the early-return guard skipped register_module,
// the module would silently disappear from the picker.
if (!class_exists('PTAAuctionCarouselModule') && class_exists('FLBuilderModule')) {

class PTAAuctionCarouselModule extends FLBuilderModule {

    public function __construct() {
        parent::__construct(array(
            'name'            => __('Auction Carousel', 'azure-plugin'),
            'description'     => __('Auto-advancing carousel of active WooCommerce auction products (same source as [auction-display]).', 'azure-plugin'),
            'group'           => __('Azure Plugin', 'azure-plugin'),
            'category'        => __('PTA Modules', 'azure-plugin'),
            'dir'             => AZURE_PLUGIN_PATH . 'includes/beaver-builder/auction-carousel/',
            'url'             => AZURE_PLUGIN_URL . 'includes/beaver-builder/auction-carousel/',
            'editor_export'   => true,
            'enabled'         => true,
            'partial_refresh' => true,
            'icon'            => 'cart.svg',
        ));
    }

    // Note: BB renders this module by `include`ing
    // includes/frontend.php (see FLBuilder::render_module_html). It does
    // NOT call a render() or render_module() method on the class. Keep
    // the rendering logic in the template file, not here.
}

} // end if (!class_exists('PTAAuctionCarouselModule') && class_exists('FLBuilderModule'))

// Register the module — always call this when the file is required, even
// if the class was already declared by another path. See pta-org-chart.php
// for the full rationale.
if (class_exists('PTAAuctionCarouselModule') && class_exists('FLBuilder')) {
    FLBuilder::register_module('PTAAuctionCarouselModule', array(
    'general' => array(
        'title'    => __('General', 'azure-plugin'),
        'sections' => array(
            'layout' => array(
                'title'  => __('Carousel Layout', 'azure-plugin'),
                'fields' => array(
                    'items_per_row' => array(
                        'type'        => 'unit',
                        'label'       => __('Items per row', 'azure-plugin'),
                        'default'     => '4',
                        'slider'      => array(
                            'min'  => 1,
                            'max'  => 8,
                            'step' => 1,
                        ),
                        'description' => __('Number of auction cards visible at once. The carousel slides one row at a time.', 'azure-plugin'),
                    ),
                    'cycle_seconds' => array(
                        'type'        => 'unit',
                        'label'       => __('Time per slide', 'azure-plugin'),
                        'default'     => '5',
                        'slider'      => array(
                            'min'  => 2,
                            'max'  => 60,
                            'step' => 1,
                        ),
                        'description' => __('Seconds before auto-advancing to the next slide.', 'azure-plugin'),
                    ),
                    'show_bid_info' => array(
                        'type'    => 'select',
                        'label'   => __('Show bid info', 'azure-plugin'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes — show current/starting bid + bidder', 'azure-plugin'),
                            'no'  => __('No — image and title only', 'azure-plugin'),
                        ),
                    ),
                ),
            ),
        ),
    ),
));
}
