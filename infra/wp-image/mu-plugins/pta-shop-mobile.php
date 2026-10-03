<?php
/**
 * Plugin Name: PTA Shop Mobile List
 * Description: On phones, shop products are a row: small photo, name, price, and Add to cart. Desktop stays the theme grid.
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins). ChromeNews sets
 * each product to width 100% below 480px, which is the full-width photo stack.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'pta_shop_mobile_css', 99);

function pta_shop_mobile_css() {
    if (is_admin() || !function_exists('is_shop')) {
        return;
    }
    if (!is_shop() && !is_product_taxonomy()) {
        return;
    }
    ?>
<style id="pta-shop-mobile-css">
@media screen and (max-width: 480px) {
    body.woocommerce ul.products[class*=columns-] li.product,
    body.woocommerce-page ul.products[class*=columns-] li.product {
        position: relative;
        float: none !important;
        clear: both;
        width: 100% !important;
        margin: 0 0 12px !important;
        display: grid;
        grid-template-columns: 84px minmax(0, 1fr) auto;
        grid-template-rows: auto auto;
        column-gap: 12px;
        row-gap: 2px;
        align-items: center;
    }
    body.woocommerce ul.products li.product a.woocommerce-LoopProduct-link {
        display: contents;
    }
    body.woocommerce ul.products li.product a img,
    body.woocommerce ul.products li.product img.attachment-woocommerce_thumbnail {
        grid-column: 1;
        grid-row: 1 / span 2;
        width: 84px !important;
        height: 84px !important;
        max-width: 84px !important;
        object-fit: cover;
        margin: 0 !important;
    }
    body.woocommerce ul.products li.product .woocommerce-loop-product__title {
        grid-column: 2;
        grid-row: 1;
        margin: 0;
        font-size: 16px;
        line-height: 1.25;
        font-weight: 700;
    }
    body.woocommerce ul.products li.product .price {
        grid-column: 2;
        grid-row: 2;
        margin: 0;
        font-size: 14px;
        line-height: 1.3;
    }
    body.woocommerce ul.products li.product > a.button {
        grid-column: 3;
        grid-row: 1 / span 2;
        align-self: center;
        width: auto;
        margin: 0 !important;
        white-space: nowrap;
    }
    body.woocommerce ul.products li.product .onsale {
        position: absolute;
        top: 4px;
        left: 4px;
        margin: 0;
    }
}
</style>
    <?php
}
