<?php
/**
 * Frontend Performance
 *
 * Targeted optimizations for the front-end to reduce per-pageload overhead
 * for anonymous and logged-out visitors.
 *
 * What this does (frontend-only, never affects admin or REST):
 *   - Dequeues WooCommerce's `wc-cart-fragments` script on non-WC pages.
 *     The script issues an AJAX request to /?wc-ajax=get_refreshed_fragments
 *     on EVERY page render, even when the visitor has zero items in the
 *     cart and the page isn't a shop/product/cart page. The AJAX hits the
 *     origin (Front Door bypass), so each pageload pays the cart-fragments
 *     round-trip in the critical path.
 *
 * All optimizations can be disabled with the option
 * `azure_frontend_performance` = `0`. Default: enabled.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Frontend_Performance {

    private static $instance = null;
    const OPTION_KEY = 'azure_frontend_performance';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!self::is_enabled()) {
            return;
        }
        // Dequeueing at `wp_enqueue_scripts` is unreliable because:
        //   1. WC enqueues `wc-cart-fragments` at priority 10.
        //   2. Side Cart (xoo-wsc-main) declares it as a DEPENDENCY,
        //      so the dep walker re-enqueues it after we dequeue.
        //   3. Other plugins enqueue at priority 100+, after our hook runs.
        //
        // The bulletproof approach: filter `script_loader_tag` so that
        // when WordPress is about to emit the `<script>` tag for a
        // suppressed handle, we replace the tag with an empty string.
        // This runs at the absolute last moment — after all enqueues,
        // dependencies, and deferral logic have settled.
        //
        // We still call wp_dequeue_script() in `wp_enqueue_scripts`
        // (priority 99) so that the inline params blob (`-extra`) is
        // also dropped — the script_loader_tag filter only catches the
        // src tag, not the inline before/after data. Dequeuing kills
        // both, even if the dep walker brings the handle back.
        add_action('wp_enqueue_scripts', array($this, 'maybe_dequeue_cart_scripts'), 99);
        add_filter('script_loader_tag', array($this, 'maybe_strip_cart_tag'), 10, 3);
        add_filter('print_scripts_array', array($this, 'maybe_strip_cart_handles'), 99);
    }

    public static function is_enabled() {
        return get_option(self::OPTION_KEY, '1') !== '0';
    }

    /**
     * Returns true if the current request is rendering a page that
     * actually needs the WooCommerce cart UI (shop, single product,
     * cart, checkout, account, etc.).
     *
     * We don't gate on `is_woocommerce()` alone because that's false
     * on the cart and account pages.
     */
    private function page_needs_cart() {
        if (!function_exists('is_shop')) {
            // WooCommerce isn't loaded yet; be defensive and assume needed.
            return true;
        }
        return is_shop()
            || is_product_category()
            || is_product_tag()
            || is_product()
            || is_cart()
            || is_checkout()
            || is_account_page();
    }

    /**
     * Returns the handles we want to suppress on non-WC pages.
     */
    private function suppressed_handles() {
        return array(
            // WC core cart-fragments AJAX (the main per-pageload tax).
            'wc-cart-fragments',
            // wc-add-to-cart depends on cart-fragments and isn't useful
            // on a page with no add-to-cart UI.
            'wc-add-to-cart',
            // Side Cart fly-out (xoo-wsc-main is the actual handle, not
            // xoo-wsc-public — verified via emitted HTML on /).
            'xoo-wsc-main',
            'xoo-wsc-public',
            'xoo-wsc-modal',
            'xoo-wsc-cart-frag',
        );
    }

    /**
     * Dequeue cart scripts at `wp_enqueue_scripts` priority 99. This
     * primarily kills the inline `-extra` data blobs (cart_hash_key,
     * fragment_name, etc.) that bloat the page even when the script
     * itself can be filtered out.
     */
    public function maybe_dequeue_cart_scripts() {
        if ($this->page_needs_cart()) {
            return;
        }
        foreach ($this->suppressed_handles() as $h) {
            if (wp_script_is($h, 'enqueued') || wp_script_is($h, 'registered')) {
                wp_dequeue_script($h);
            }
            if (wp_style_is($h, 'enqueued') || wp_style_is($h, 'registered')) {
                wp_dequeue_style($h);
            }
        }
    }

    /**
     * Final safety net: filter the `print_scripts_array` so the
     * handles never make it to the dependency walker. This runs after
     * all `wp_enqueue_scripts` callbacks have completed.
     *
     * @param array $handles List of handles WP is about to print.
     * @return array Filtered list with our suppressed handles removed.
     */
    public function maybe_strip_cart_handles($handles) {
        if ($this->page_needs_cart()) {
            return $handles;
        }
        $kill = array_flip($this->suppressed_handles());
        return array_values(array_filter((array) $handles, function ($h) use ($kill) {
            return !isset($kill[$h]);
        }));
    }

    /**
     * Absolute last-line-of-defense: replace the `<script>` tag with
     * an empty string for any handle that survived the dequeue + dep
     * stripping. Filters here run during the actual HTML emit.
     *
     * @param string $tag    Full script HTML tag.
     * @param string $handle Script handle.
     * @param string $src    Script src URL.
     * @return string Tag (possibly empty).
     */
    public function maybe_strip_cart_tag($tag, $handle, $src) {
        if ($this->page_needs_cart()) {
            return $tag;
        }
        $kill = array_flip($this->suppressed_handles());
        if (isset($kill[$handle])) {
            return '';
        }
        return $tag;
    }
}
