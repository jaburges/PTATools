<?php
/**
 * User Account Dropdown Shortcode
 * 
 * Provides a collapsible dropdown menu for logged-in users with WooCommerce integration
 * 
 * Usage: [user-account-dropdown]
 * 
 * Parameters:
 * - show_avatar: true/false (default: true) - Show user avatar
 * - avatar_size: number (default: 40) - Avatar size in pixels
 * - show_orders: true/false (default: true) - Show orders link (requires WooCommerce)
 * - show_downloads: true/false (default: false) - Show downloads link
 * - show_addresses: true/false (default: false) - Show addresses link
 * - show_payment_methods: true/false (default: false) - Show payment methods link
 * - custom_links: JSON array of custom links (optional)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_User_Account_Shortcode {
    
    public function __construct() {
        add_shortcode('user-account-dropdown', array($this, 'account_dropdown_shortcode'));
        add_shortcode('user-account-menu', array($this, 'account_dropdown_shortcode'));
        
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        add_action('wp_ajax_azure_account_state', array($this, 'ajax_account_state'));
        add_action('wp_ajax_nopriv_azure_account_state', array($this, 'ajax_account_state'));
    }
    
    /**
     * Enqueue frontend assets
     *
     * Always enqueue on the front-end (shortcode may be in widgets/menus/templates,
     * not just $post->post_content). Tell W3TC's pgcache to vary by login state
     * so the rendered HTML doesn't get cross-served between users.
     */
    public function enqueue_frontend_assets() {
        if (is_admin()) { return; }
        wp_enqueue_style(
            'azure-user-account-dropdown',
            AZURE_PLUGIN_URL . 'css/user-account-dropdown.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
    }

    /**
     * User account dropdown shortcode
     *
     * Strategy (W3TC + AFD friendly):
     *   - For LOGGED-IN users we render the full menu directly in PHP.
     *     W3TC has pgcache.reject.logged = true, so logged-in requests
     *     bypass the page cache and always get fresh HTML. AFD also
     *     respects the origin Cache-Control.
     *   - For ANONYMOUS users we render the cache-safe "Log In" link plus
     *     a small inline JS fallback that detects the WordPress auth cookie
     *     and AJAX-swaps the menu. This is defense-in-depth in case a
     *     cached anon page is ever served to a logged-in browser.
     */
    public function account_dropdown_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_avatar' => 'true',
            'avatar_size' => 40,
            'show_orders' => 'true',
            'show_downloads' => 'false',
            'show_addresses' => 'false',
            'show_payment_methods' => 'false',
            'show_store_credit' => 'false',
            'collapsed' => 'true',
            'style' => 'default',
        ), $atts);

        $style       = sanitize_text_field($atts['style']);
        $ajax_url    = admin_url('admin-ajax.php');
        $dropdown_id = 'user-account-dropdown-' . wp_rand(1000, 9999);

        // Hint to W3TC / AFD that this response is per-user
        if (is_user_logged_in() && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (is_user_logged_in()) {
            return $this->render_logged_in_menu($dropdown_id, $style);
        }

        $login_url = wp_login_url(home_url($_SERVER['REQUEST_URI'] ?? '/'));

        ob_start();
        ?>
        <div class="user-account-dropdown-wrapper logged-out style-<?php echo esc_attr($style); ?>"
             id="<?php echo esc_attr($dropdown_id); ?>"
             data-ajax="<?php echo esc_url($ajax_url); ?>"
             style="position:relative;display:inline-block;z-index:9999;">

            <!-- Logged-out state (default / cache-safe) -->
            <a href="<?php echo esc_url($login_url); ?>" class="user-account-login-link">
                <span class="user-account-login-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                </span>
                <span><?php _e('Log In', 'azure-plugin'); ?></span>
            </a>
        </div>

        <script>
        (function(){
            if (window._azureAcctInit) return;
            window._azureAcctInit = true;

            // Fallback: if a cached anon page was served to a logged-in browser,
            // detect the auth cookie and AJAX-swap the menu.
            var hasAuth = document.cookie.split(';').some(function(c){
                return c.trim().indexOf('wordpress_logged_in_') === 0;
            });
            if (!hasAuth) return;

            var wrappers = document.querySelectorAll('.user-account-dropdown-wrapper.logged-out');
            if (!wrappers.length) return;

            var ajaxUrl = wrappers[0].getAttribute('data-ajax');
            if (!ajaxUrl) return;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
            xhr.onreadystatechange = function(){
                if (xhr.readyState !== 4 || xhr.status !== 200) return;
                try { var d = JSON.parse(xhr.responseText); } catch(e){ return; }
                if (!d || !d.logged_in) return;

                wrappers.forEach(function(w){
                    var id = w.id;
                    var menuId = id + '-menu';
                    var items = '';
                    (d.menu || []).forEach(function(m){
                        if (!m.url) return;
                        items += '<li style="margin:0;padding:0;">'
                            + '<a href="' + m.url + '" style="display:flex;align-items:center;gap:12px;padding:10px 16px;color:#24292e;text-decoration:none;">'
                            + '<span>' + m.label + '</span></a></li>';
                    });

                    w.className = w.className.replace('logged-out','logged-in');
                    w.innerHTML =
                        '<div class="user-account-toggle" role="button" tabindex="0" aria-expanded="false"'
                        + ' aria-controls="' + menuId + '"'
                        + ' onclick="azureToggleAccountMenu(\'' + id + '\')"'
                        + ' style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;">'
                        + '<img src="' + (d.avatar||'') + '" width="32" height="32" class="user-account-avatar" style="border-radius:50%;" />'
                        + '<span class="user-account-name">' + d.display_name + '</span>'
                        + '<span class="user-account-arrow" style="transition:transform .2s ease;">'
                        + '<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                        + '</span></div>'
                        + '<div class="user-account-menu" id="' + menuId + '" style="display:none;position:absolute;top:100%;right:0;min-width:200px;background:#fff;border:1px solid #e1e4e8;border-radius:8px;margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:10000;">'
                        + '<ul style="list-style:none;margin:0;padding:8px 0;">' + items + '</ul></div>';
                });
            };
            xhr.send('action=azure_account_state');
        })();

        if (typeof window.azureToggleAccountMenu !== 'function') {
            window.azureToggleAccountMenu = function(id) {
                var w = document.getElementById(id);
                if (!w) return;
                var t = w.querySelector('.user-account-toggle');
                var m = w.querySelector('.user-account-menu');
                if (!m) return;
                var open = m.style.display === 'none' || m.style.display === '';
                m.style.display = open ? 'block' : 'none';
                if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
        }

        document.addEventListener('click', function(e){
            document.querySelectorAll('.user-account-dropdown-wrapper.logged-in').forEach(function(w){
                if (!w.contains(e.target)) {
                    var m = w.querySelector('.user-account-menu');
                    var t = w.querySelector('.user-account-toggle');
                    if (m) m.style.display = 'none';
                    if (t) t.setAttribute('aria-expanded','false');
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the full logged-in user menu directly in PHP.
     * Called when is_user_logged_in() is true so we don't depend on JS to swap.
     */
    private function render_logged_in_menu($dropdown_id, $style) {
        $user      = wp_get_current_user();
        $wc        = class_exists('WooCommerce');
        $acct      = $wc ? wc_get_page_permalink('myaccount') : admin_url('profile.php');
        $avatar    = get_avatar_url($user->ID, array('size' => 40));
        $menu_id   = $dropdown_id . '-menu';

        $items = array(
            array('label' => __('Dashboard', 'azure-plugin'),       'url' => $acct),
        );
        if ($wc) {
            $items[] = array('label' => __('Orders', 'azure-plugin'),          'url' => wc_get_endpoint_url('orders', '', $acct));
            $items[] = array('label' => __('Account Details', 'azure-plugin'), 'url' => wc_get_endpoint_url('edit-account', '', $acct));
        } else {
            $items[] = array('label' => __('Account Details', 'azure-plugin'), 'url' => admin_url('profile.php'));
        }
        $items[] = array('label' => __('Log Out', 'azure-plugin'), 'url' => wp_logout_url(home_url()));

        ob_start();
        ?>
        <div class="user-account-dropdown-wrapper logged-in style-<?php echo esc_attr($style); ?>"
             id="<?php echo esc_attr($dropdown_id); ?>"
             style="position:relative;display:inline-block;z-index:9999;">
            <div class="user-account-toggle" role="button" tabindex="0" aria-expanded="false"
                 aria-controls="<?php echo esc_attr($menu_id); ?>"
                 onclick="azureToggleAccountMenu('<?php echo esc_js($dropdown_id); ?>')"
                 style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                <img src="<?php echo esc_url($avatar); ?>" width="32" height="32"
                     class="user-account-avatar" alt=""
                     style="border-radius:50%;" />
                <span class="user-account-name"><?php echo esc_html($user->display_name); ?></span>
                <span class="user-account-arrow" style="transition:transform .2s ease;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </div>
            <div class="user-account-menu" id="<?php echo esc_attr($menu_id); ?>"
                 style="display:none;position:absolute;top:100%;right:0;min-width:200px;background:#fff;border:1px solid #e1e4e8;border-radius:8px;margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:10000;">
                <ul style="list-style:none;margin:0;padding:8px 0;">
                    <?php foreach ($items as $item): if (empty($item['url'])) continue; ?>
                        <li style="margin:0;padding:0;">
                            <a href="<?php echo esc_url($item['url']); ?>"
                               style="display:flex;align-items:center;gap:12px;padding:10px 16px;color:#24292e;text-decoration:none;">
                                <span><?php echo esc_html($item['label']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <script>
        if (typeof window.azureToggleAccountMenu !== 'function') {
            window.azureToggleAccountMenu = function(id) {
                var w = document.getElementById(id);
                if (!w) return;
                var t = w.querySelector('.user-account-toggle');
                var m = w.querySelector('.user-account-menu');
                if (!m) return;
                var open = m.style.display === 'none' || m.style.display === '';
                m.style.display = open ? 'block' : 'none';
                if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
        }
        if (!window._azureAcctOutsideClick) {
            window._azureAcctOutsideClick = true;
            document.addEventListener('click', function(e){
                document.querySelectorAll('.user-account-dropdown-wrapper.logged-in').forEach(function(w){
                    if (!w.contains(e.target)) {
                        var m = w.querySelector('.user-account-menu');
                        var t = w.querySelector('.user-account-toggle');
                        if (m) m.style.display = 'none';
                        if (t) t.setAttribute('aria-expanded','false');
                    }
                });
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler: return current user state for cache-busting
     */
    public function ajax_account_state() {
        if (!is_user_logged_in()) {
            wp_send_json(array('logged_in' => false));
        }

        $user = wp_get_current_user();
        $wc   = class_exists('WooCommerce');
        $acct = $wc ? wc_get_page_permalink('myaccount') : admin_url('profile.php');

        wp_send_json(array(
            'logged_in'    => true,
            'display_name' => $user->display_name,
            'avatar'       => get_avatar_url($user->ID, array('size' => 40)),
            'menu'         => array(
                array('label' => __('Dashboard', 'azure-plugin'),       'url' => $acct),
                array('label' => __('Orders', 'azure-plugin'),          'url' => $wc ? wc_get_endpoint_url('orders', '', $acct) : ''),
                array('label' => __('Account Details', 'azure-plugin'), 'url' => $wc ? wc_get_endpoint_url('edit-account', '', $acct) : admin_url('profile.php')),
                array('label' => __('Log Out', 'azure-plugin'),         'url' => wp_logout_url(home_url())),
            ),
        ));
    }
}

