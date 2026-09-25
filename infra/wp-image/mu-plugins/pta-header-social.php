<?php
/**
 * Plugin Name: PTA Header Social
 * Description: Facebook and Instagram icons in the mid-header, immediately left of the cart.
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins), not on the uploads share.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', 'pta_header_social_inject', 7);

function pta_header_social_inject() {
    if (is_admin()) {
        return;
    }
    $facebook = 'https://www.facebook.com/WilderElementaryPTSA/';
    $instagram = 'https://www.instagram.com/wilderelementaryptsa';
    ?>
<div id="pta-header-social-slot" hidden>
<span class="pta-header-social">
    <a class="pta-header-social__link pta-header-social__link--facebook" href="<?php echo esc_url($facebook); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr__('Wilder Elementary PTSA on Facebook', 'azure-plugin'); ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path>
        </svg>
    </a>
    <a class="pta-header-social__link pta-header-social__link--instagram" href="<?php echo esc_url($instagram); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr__('Wilder Elementary PTSA on Instagram', 'azure-plugin'); ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
            <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
            <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
        </svg>
    </a>
</span>
</div>
<style id="pta-header-social-css">
.pta-header-social{
  display:inline-flex;
  align-items:center;
  gap:12px;
  line-height:1;
}
.pta-header-social__link{
  display:inline-flex;
  align-items:center;
  color:inherit;
  text-decoration:none;
}
.pta-header-social__link--facebook{ color:#1877F2; }
.pta-header-social__link--instagram{ color:#E1306C; }
.pta-header-social__link:hover{ opacity:0.75; }
@media screen and (max-width: 991px) {
  .header-layout-centered .bottom-header .pta-header-social__link{
    color:#fff !important;
  }
}
</style>
<script>
(function () {
  function place() {
    var slot = document.getElementById('pta-header-social-slot');
    if (!slot) return;
    var el = slot.querySelector('.pta-header-social') || slot;
    var host = document.querySelector('.main-bar-right');
    if (!host) {
      slot.hidden = false;
      return;
    }
    var cart = host.querySelector('a.pta-header-cart');
    var search = host.querySelector('.af-search-wrap');
    if (cart && cart.parentNode === host) {
      host.insertBefore(el, cart);
    } else if (search && search.parentNode === host) {
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
