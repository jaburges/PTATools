<?php
/**
 * Plugin Name: PTA Header Cart
 * Description: Cart icon in the ChromeNews mid-header, immediately left of Search. Count updates via WooCommerce cart fragments.
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins), not on the uploads
 * share, so it survives revision rebuilds. Do not add a theme Header Builder
 * cart block for this — Custom HTML is Pro-locked and a static block cannot
 * hide itself when the cart is empty.
 */

if (!defined('ABSPATH')) {
    exit;
}

function pta_header_cart_count() {
    if (!function_exists('WC') || !WC()->cart) {
        return 0;
    }
    return (int) WC()->cart->get_cart_contents_count();
}

function pta_header_cart_markup($count = null) {
    if ($count === null) {
        $count = pta_header_cart_count();
    }
    $url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
    $count_hidden = $count > 0 ? '' : ' hidden';

    ob_start();
    ?>
<a class="pta-header-cart" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr(sprintf(__('View cart (%d items)', 'azure-plugin'), $count)); ?>">
    <span class="pta-header-cart__icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
    </span>
    <span class="pta-header-cart__count"<?php echo $count_hidden; ?>><?php echo (int) $count; ?></span>
</a>
    <?php
    return ob_get_clean();
}

add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $fragments['a.pta-header-cart'] = pta_header_cart_markup();
    return $fragments;
});

add_action('wp_footer', 'pta_header_cart_inject', 4);

function pta_header_cart_inject() {
    if (is_admin() || !function_exists('WC')) {
        return;
    }
    ?>
<div id="pta-header-cart-slot" hidden>
<?php echo pta_header_cart_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
<style id="pta-header-cart-css">
.mid-header .main-bar-right{
  display:flex !important;
  align-items:center;
  justify-content:flex-end;
  gap:12px;
  flex-wrap:nowrap;
}
.pta-header-cart{
  display:inline-flex;
  align-items:center;
  gap:4px;
  color:inherit;
  text-decoration:none;
  line-height:1;
}
.pta-header-cart__count[hidden]{display:none !important;}
.pta-header-cart__count{
  min-width:1.1em;
  font-size:12px;
  font-weight:700;
}
</style>
<script>
(function () {
  function place() {
    var slot = document.getElementById('pta-header-cart-slot');
    if (!slot) return;
    var el = slot.querySelector('a.pta-header-cart') || slot;
    var host = document.querySelector('.mid-header .main-bar-right');
    if (!host) {
      slot.hidden = false;
      return;
    }
    var search = host.querySelector('.af-search-wrap');
    if (search && search.parentNode === host) {
      host.insertBefore(el, search);
    } else {
      host.insertBefore(el, host.firstChild);
    }
    if (slot.parentNode) {
      slot.parentNode.removeChild(slot);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', place);
  } else {
    place();
  }
})();
</script>
    <?php
}
