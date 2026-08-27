<?php
/**
 * Plugin Name: PTA Header Account Dropdown
 * Description: Injects [pta_user_dropdown] into the ChromeNews mid-header right bar beside Search (free workaround when Header Builder Custom HTML / widgets are Pro-locked).
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins) so it survives rebuilds.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', 'pta_header_account_inject', 5);

/**
 * Print shortcode markup + mover script so it lands in .main-bar-right next to search.
 */
function pta_header_account_inject() {
    if (is_admin() || !shortcode_exists('pta_user_dropdown')) {
        return;
    }

    $html = do_shortcode('[pta_user_dropdown]');
    if ($html === '') {
        return;
    }
    ?>
<div id="pta-header-account" class="pta-header-account" hidden>
<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode HTML ?>
</div>
<style id="pta-header-account-css">
.mid-header .main-bar-right{
  display:flex !important;
  align-items:center;
  justify-content:flex-end;
  gap:12px;
  flex-wrap:nowrap;
}
.mid-header .main-bar-right .pta-header-account{
  display:inline-flex;
  align-items:center;
  line-height:1;
  margin:0;
  position:static;
}
.mid-header .main-bar-right .af-search-wrap{
  display:inline-flex;
  align-items:center;
}
.mid-header .main-bar-right .pta-user-dropdown__trigger{
  white-space:nowrap;
}
</style>
<script>
(function () {
  function place() {
    var el = document.getElementById('pta-header-account');
    if (!el) return;
    var host = document.querySelector('.mid-header .main-bar-right');
    if (!host) {
      el.hidden = false;
      return;
    }
    var search = host.querySelector('.af-search-wrap');
    if (search && search.parentNode === host) {
      host.insertBefore(el, search.nextSibling);
    } else {
      host.appendChild(el);
    }
    el.hidden = false;
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
