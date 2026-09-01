<?php
/**
 * Plugin Name: PTA Mobile Header
 * Description: Hide the live date/time, align the hamburger, and move cart/search/sign-in into the blue nav on small screens.
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', 'pta_header_mobile_inject', 6);

function pta_header_mobile_inject() {
    if (is_admin()) {
        return;
    }
    ?>
<style id="pta-header-mobile-css">
/* Live clock in the mid-header — not useful, and it becomes its own row on phones. */
.mid-header .topbar-date,
.mid-header #topbar-time {
    display: none !important;
}
.mid-header .main-bar-left:not(:has(*:not(.topbar-date))) {
    display: none !important;
}
.header-layout-centered .mid-header .main-bar-left {
    display: none !important;
}

@media screen and (max-width: 991px) {
    .header-layout-centered .mid-header-wrapper {
        padding: 8px 0 !important;
    }
    .header-layout-centered .mid-bar-flex {
        flex-wrap: nowrap !important;
        gap: 8px !important;
        justify-content: center;
    }
    /* Utilities live in the blue bar once JS moves .main-bar-right. */
    .header-layout-centered .bottom-header .container-wrapper {
        padding-left: 16px;
        padding-right: 16px;
    }
    .header-layout-centered .bottom-bar-flex {
        align-items: center;
    }
    body .header-layout-centered .main-navigation .toggle-menu {
        padding: 12px 10px 12px 0 !important;
        width: auto;
        height: auto;
        display: flex;
        align-items: center;
    }
    .header-layout-centered .bottom-header .main-bar-right {
        display: flex !important;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: nowrap;
        margin-right: 8px;
    }
    .header-layout-centered .bottom-header .pta-header-cart,
    .header-layout-centered .bottom-header .search-icon,
    .header-layout-centered .bottom-header .pta-user-dropdown--guest,
    .header-layout-centered .bottom-header .pta-user-dropdown__trigger,
    .header-layout-centered .bottom-header .pta-user-dropdown__name,
    .header-layout-centered .bottom-header .search-icon i {
        color: #fff !important;
    }
    .header-layout-centered .bottom-header .search-icon {
        line-height: 1;
    }
}
</style>
<script>
(function () {
  var mq = window.matchMedia('(max-width: 991px)');
  function dest() {
    return document.querySelector('.bottom-header .bottom-bar-right');
  }
  function home() {
    return document.querySelector('.mid-header .mid-bar-flex');
  }
  function utils() {
    return document.querySelector('.main-bar-right');
  }
  function place() {
    var el = utils();
    var d = dest();
    var h = home();
    if (!el || !d || !h) return;
    if (mq.matches) {
      if (el.parentNode !== d) {
        d.insertBefore(el, d.firstChild);
      }
    } else if (el.parentNode !== h) {
      h.appendChild(el);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', place);
  } else {
    place();
  }
  if (mq.addEventListener) {
    mq.addEventListener('change', place);
  } else if (mq.addListener) {
    mq.addListener(place);
  }
})();
</script>
    <?php
}
