<?php
/**
 * User Management Module (v3.69)
 *
 * Consolidates everything that touches WP users + the connected family tree
 * into a single PTA Tools surface, so the site can eventually retire the
 * MemberPlus plugin (header dropdown, sign-up forms, member directory etc.)
 * without losing functionality.
 *
 * Three concerns:
 *   1. Family Hierarchy — visual Parent 1 ↔ Parent 2 ↔ children mapping
 *      backed by the v3.67 connected_family schema.
 *   2. Role Editor — wraps the existing pta-role-editor-page.php cap matrix
 *      and absorbs the Subscriber → Parent migration tool.
 *   3. Account Menu — registers a `pta-account-menu` theme location and
 *      ships a [pta_user_dropdown] shortcode so the Kadence header HTML
 *      can render the user dropdown without MemberPlus.
 *
 * Resource policy:
 *   - Admin code (AJAX handlers, family search) is only attached when
 *     `is_admin()` is true.
 *   - Frontend code is limited to the shortcode handler + CSS/JS, which
 *     only enqueue when the shortcode actually renders on the page.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_User_Management_Module {

    const NONCE_ACTION       = 'azure_um_nonce';
    const FAMILIES_PAGE_SIZE = 25;
    const NAV_LOCATION       = 'pta-account-menu';

    private static $instance = null;
    private static $assets_enqueued = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Frontend: dropdown shortcode + nav location. Both are cheap.
        add_action('after_setup_theme', array($this, 'register_nav_location'));
        add_shortcode('pta_user_dropdown', array($this, 'render_user_dropdown'));

        // Admin-only: AJAX handlers for the Family Hierarchy tab + the
        // inline menu assignment on the Account Menu tab.
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('wp_ajax_pta_um_search_families', array($this, 'ajax_search_families'));
            add_action('wp_ajax_pta_um_assign_account_menu', array($this, 'ajax_assign_account_menu'));

            // Hydration for the header account menu on a cached page. Both
            // variants are registered because a visitor whose session expired
            // still has the marker cookie and will ask; they get a "not signed
            // in" answer rather than a 400.
            add_action('wp_ajax_pta_header_account', array($this, 'ajax_header_account'));
            add_action('wp_ajax_nopriv_pta_header_account', array($this, 'ajax_header_account'));
        }

        // Parents dashboard widget (24h / 7d / 30d / total). Cheap — only
        // wires up on /wp-admin/index.php and queries are indexed
        // user-meta lookups.
        if (is_admin()) {
            add_action('wp_dashboard_setup', array($this, 'register_parents_widget'));
        }
    }

    /**
     * Register the Parents dashboard widget. Renders only for admins so
     * non-privileged dashboards stay clean.
     */
    public function register_parents_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'azure_parents_stats',
            __('Parents', 'azure-plugin'),
            array($this, 'render_parents_widget')
        );
    }

    /**
     * Render the Parents dashboard widget.
     *
     * Stats:
     *   - 24h logins  — distinct parent users with `_pta_last_login` in last 24h
     *   - 7d  logins  — same, last 7 days
     *   - Total       — all users with the `parent` role
     *   - Disabled    — parents still carrying `_pta_login_disabled` (haven't
     *                   received their welcome email yet)
     *
     * Login meta is stamped by Azure_Parent_Role::record_last_login() on
     * every successful sign-in (SSO + native), so the underlying query is
     * a single user_meta scan that MySQL serves from the indexed meta_key.
     */
    public function render_parents_widget() {
        global $wpdb;

        $now_utc      = current_time('mysql', true);
        $cutoff_24h   = gmdate('Y-m-d H:i:s', strtotime($now_utc) - DAY_IN_SECONDS);
        $cutoff_7d    = gmdate('Y-m-d H:i:s', strtotime($now_utc) - 7 * DAY_IN_SECONDS);
        $meta_login   = Azure_Parent_Role::META_LAST_LOGIN;
        $meta_disabled = Azure_Parent_Role::META_LOGIN_DISABLED;

        $stats = array(
            'logins_24h' => 0,
            'logins_7d'  => 0,
            'total'      => 0,
            'disabled'   => 0,
        );

        $stats['logins_24h'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value >= %s",
            $meta_login,
            $cutoff_24h
        ));
        $stats['logins_7d'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value >= %s",
            $meta_login,
            $cutoff_7d
        ));

        // count_users() is the canonical "users by role" lookup. WP caches
        // the result in-memory per request, so reusing it here is free.
        $parent_role = Azure_Parent_Role::ROLE_SLUG;
        $counts = count_users();
        $stats['total'] = isset($counts['avail_roles'][$parent_role])
            ? (int) $counts['avail_roles'][$parent_role]
            : 0;

        $stats['disabled'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value <> '0' AND meta_value <> ''",
            $meta_disabled
        ));

        $um_url = admin_url('admin.php?page=azure-plugin-user-management');
        ?>
        <style>
            .azure-parents-widget .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 12px; }
            .azure-parents-widget .stat-card { background: #f9f9f9; padding: 12px; text-align: center; border-radius: 4px; border-left: 3px solid #0078d4; }
            .azure-parents-widget .stat-card .stat-number { font-size: 22px; font-weight: 700; color: #1d2327; line-height: 1.2; }
            .azure-parents-widget .stat-card .stat-label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.3px; }
            .azure-parents-widget .stat-card.fresh { border-left-color: #00a32a; }
            .azure-parents-widget .stat-card.warm  { border-left-color: #2271b1; }
            .azure-parents-widget .stat-card.warn  { border-left-color: #dba617; }
            .azure-parents-widget .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        </style>
        <div class="azure-parents-widget">
            <div class="stat-grid">
                <div class="stat-card fresh">
                    <div class="stat-number"><?php echo number_format($stats['logins_24h']); ?></div>
                    <div class="stat-label"><?php _e('Last 24 hours', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card warm">
                    <div class="stat-number"><?php echo number_format($stats['logins_7d']); ?></div>
                    <div class="stat-label"><?php _e('Last 7 days', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label"><?php _e('Total parents', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card <?php echo $stats['disabled'] > 0 ? 'warn' : ''; ?>">
                    <div class="stat-number"><?php echo number_format($stats['disabled']); ?></div>
                    <div class="stat-label"><?php _e('Not yet activated', 'azure-plugin'); ?></div>
                </div>
            </div>
            <p class="description" style="margin: 0 0 8px; font-size: 11px; color: #646970;">
                <?php _e('Login activity is tracked from this build forward — historical sign-ins before the upgrade are not counted.', 'azure-plugin'); ?>
            </p>
            <div class="actions">
                <a href="<?php echo esc_url($um_url); ?>" class="button button-primary"><?php _e('User Management', 'azure-plugin'); ?></a>
                <a href="<?php echo esc_url(admin_url('users.php?role=' . $parent_role)); ?>" class="button"><?php _e('All parents', 'azure-plugin'); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: assign a WordPress nav menu to the pta-account-menu theme
     * location, or clear the assignment if menu_id = 0. Mirrors what
     * Appearance → Menus → Manage Locations does, just inline so the
     * admin doesn't have to leave the Account Menu tab.
     */
    public function ajax_assign_account_menu() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options') || !current_user_can('edit_theme_options')) {
            wp_send_json_error('forbidden', 403);
        }

        $menu_id = isset($_POST['menu_id']) ? (int) $_POST['menu_id'] : 0;
        if ($menu_id < 0) {
            $menu_id = 0;
        }
        if ($menu_id > 0 && !wp_get_nav_menu_object($menu_id)) {
            wp_send_json_error('invalid_menu', 400);
        }

        $locations = get_theme_mod('nav_menu_locations');
        if (!is_array($locations)) {
            $locations = array();
        }
        if ($menu_id === 0) {
            unset($locations[self::NAV_LOCATION]);
        } else {
            $locations[self::NAV_LOCATION] = $menu_id;
        }
        set_theme_mod('nav_menu_locations', $locations);

        $assigned = $menu_id ? wp_get_nav_menu_object($menu_id) : null;
        $items    = $assigned ? wp_get_nav_menu_items($assigned->term_id) : array();

        wp_send_json_success(array(
            'menu_id'    => $menu_id,
            'menu_name'  => $assigned ? $assigned->name : '',
            'item_count' => is_array($items) ? count($items) : 0,
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Nav menu location for the header dropdown
    // ─────────────────────────────────────────────────────────────────

    public function register_nav_location() {
        register_nav_menu(self::NAV_LOCATION, __('PTA Account Menu (header dropdown)', 'azure-plugin'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  [pta_user_dropdown] — frontend header dropdown
    // ─────────────────────────────────────────────────────────────────

    /**
     * Render the user account dropdown. Replaces MemberPlus's
     * `memb-plus-nav.php` with a much smaller surface.
     *
     *   Logged out: a "Sign in" link.
     *   Logged in : avatar/name + a panel rendered from the menu assigned
     *               to the `pta-account-menu` theme location, falling back
     *               to a sensible WooCommerce default if no menu is set.
     */
    public function render_user_dropdown($atts = array(), $content = null, $tag = '') {
        $atts = shortcode_atts(array(
            'logged_out_text' => __('Sign in', 'azure-plugin'),
            'show_avatar'     => 'yes',
            'show_name'       => 'yes',
        ), is_array($atts) ? $atts : array(), $tag);

        $this->maybe_enqueue_dropdown_assets();

        if (!is_user_logged_in()) {
            return $this->render_guest_shell($atts);
        }

        // Signed-in responses are sent `no-store` by Azure_Edge_Cache, so this
        // markup can never be shared and is safe to render inline. Rendering it
        // here rather than hydrating it keeps the signed-in path free of an
        // extra round trip.
        return $this->render_signed_in_dropdown($atts);
    }

    /**
     * The guest header, built to be identical for every anonymous visitor so
     * Front Door can hold one copy of the page for all of them.
     *
     * Two things are deliberately absent. There is no display name or menu,
     * because that would make the page personal. And there is no `redirect_to`
     * baked into the sign-in link: the cached copy is shared across visitors,
     * so the return path is filled in client-side from the address bar
     * instead — otherwise everyone would be sent back to whichever page
     * happened to be cached first.
     *
     * If a signed-in visitor is ever served this copy, the script swaps in
     * their real menu. In normal operation that does not happen, because their
     * requests bypass the cache; it matters when an edge rule is wrong, and it
     * is what would allow caching for signed-in shoppers later.
     */
    private function render_guest_shell($atts) {
        $guest = sprintf(
            '<a class="pta-user-dropdown pta-user-dropdown--guest" href="%s" data-pta-login>%s</a>',
            esc_url(wp_login_url()),
            esc_html($atts['logged_out_text'])
        );

        $script = sprintf(
            '(function(){var r=document.querySelector("[data-pta-account-shell]");if(!r)return;' .
            'var l=r.querySelector("[data-pta-login]");' .
            'if(l){var u=l.getAttribute("href");l.setAttribute("href",u+(u.indexOf("?")===-1?"?":"&")+"redirect_to="+encodeURIComponent(location.pathname+location.search));}' .
            'if(!/(?:^|;\s*)%s=1(?:;|$)/.test(document.cookie))return;' .
            'fetch(%s,{credentials:"same-origin",headers:{"Accept":"application/json"}})' .
            '.then(function(x){return x.ok?x.json():null})' .
            '.then(function(d){if(d&&d.success&&d.data&&d.data.logged_in&&d.data.html){r.innerHTML=d.data.html;}})' .
            '.catch(function(){});})();',
            esc_js($this->marker_cookie_name()),
            wp_json_encode(add_query_arg('action', 'pta_header_account', admin_url('admin-ajax.php')))
        );

        return '<span class="pta-user-dropdown-shell" data-pta-account-shell>' . $guest . '</span>'
            . '<script>' . $script . '</script>';
    }

    private function marker_cookie_name() {
        return class_exists('Azure_Edge_Cache') ? Azure_Edge_Cache::MARKER_COOKIE : 'pta_signed_in';
    }

    /**
     * Return the signed-in header markup for whoever holds the cookie.
     *
     * No nonce is required or wanted. This reads nothing but the requester's
     * own session and changes no state, and a nonce would have to be embedded
     * in the cached page — where it would be shared between visitors and
     * expire, which is the whole problem this endpoint exists to avoid.
     */
    public function ajax_header_account() {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');

        if (!is_user_logged_in()) {
            wp_send_json_success(array('logged_in' => false, 'html' => ''));
        }

        // No asset enqueue here: the cached page that is asking already carries
        // the dropdown CSS and JS from its own guest render, and the toggle
        // binds through delegation on document, so injected markup just works.
        wp_send_json_success(array(
            'logged_in' => true,
            'html'      => $this->render_signed_in_dropdown(array(
                'logged_out_text' => __('Sign in', 'azure-plugin'),
                'show_avatar'     => 'yes',
                'show_name'       => 'yes',
            )),
        ));
    }

    /**
     * Return the account markup for the current signed-in user. Shared by the
     * inline render and the hydration endpoint so the two cannot drift.
     */
    private function render_signed_in_dropdown($atts) {
        $user        = wp_get_current_user();
        $avatar_html = ($atts['show_avatar'] === 'yes') ? get_avatar($user->ID, 32, '', $user->display_name, array('class' => 'pta-user-dropdown__avatar')) : '';
        $name_html   = ($atts['show_name'] === 'yes')
            ? '<span class="pta-user-dropdown__name">' . esc_html($user->display_name) . '</span>'
            : '';

        $menu_html = wp_nav_menu(array(
            'theme_location' => self::NAV_LOCATION,
            'echo'           => false,
            'fallback_cb'    => false,
            'container'      => false,
            'menu_class'     => 'pta-user-dropdown__menu',
            'depth'          => 1,
        ));

        if (empty($menu_html)) {
            $menu_html = $this->default_account_menu_html();
        }

        return sprintf(
            '<div class="pta-user-dropdown" data-pta-user-dropdown>
                <button type="button" class="pta-user-dropdown__trigger" aria-expanded="false" aria-haspopup="true">
                    %s%s<span class="pta-user-dropdown__caret" aria-hidden="true">▾</span>
                </button>
                <div class="pta-user-dropdown__panel" hidden>%s</div>
            </div>',
            $avatar_html,
            $name_html,
            $menu_html
        );
    }

    /**
     * Fallback menu used when no nav menu has been assigned to the
     * `pta-account-menu` theme location yet. Mirrors the default
     * WooCommerce My Account links plus the v3.67 Family Info endpoint.
     */
    private function default_account_menu_html() {
        $endpoints = array();
        if (function_exists('wc_get_account_endpoint_url')) {
            $endpoints['dashboard']      = array(__('Dashboard', 'azure-plugin'), wc_get_account_endpoint_url('dashboard'));
            $endpoints['orders']         = array(__('Orders', 'azure-plugin'), wc_get_account_endpoint_url('orders'));
            $endpoints['profile']        = array(__('Family Info', 'azure-plugin'), wc_get_account_endpoint_url('profile'));
            $endpoints['edit-account']   = array(__('Account details', 'azure-plugin'), wc_get_account_endpoint_url('edit-account'));
            $endpoints['logout']         = array(__('Log out', 'azure-plugin'), wc_logout_url(home_url()));
        } else {
            $endpoints['logout'] = array(__('Log out', 'azure-plugin'), wp_logout_url(home_url()));
        }

        $items = '';
        foreach ($endpoints as $slug => $row) {
            $items .= sprintf(
                '<li class="pta-user-dropdown__item pta-user-dropdown__item--%s"><a href="%s">%s</a></li>',
                esc_attr($slug),
                esc_url($row[1]),
                esc_html($row[0])
            );
        }
        return '<ul class="pta-user-dropdown__menu pta-user-dropdown__menu--default">' . $items . '</ul>';
    }

    /**
     * Enqueue the dropdown CSS+JS once per request, and only when the
     * shortcode actually renders. Keeps the cost off pages that don't
     * use it.
     */
    private function maybe_enqueue_dropdown_assets() {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'pta-user-dropdown',
            AZURE_PLUGIN_URL . 'css/pta-user-dropdown.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'pta-user-dropdown',
            AZURE_PLUGIN_URL . 'js/pta-user-dropdown.js',
            array(),
            AZURE_PLUGIN_VERSION,
            true
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Family hierarchy admin queries + AJAX
    // ─────────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of families with parent/child summaries.
     *
     * Joins:
     *   connected_family → wp_users (Parent 1)
     *                    → wp_users (Parent 2, optional)
     *                    → user_children (children count + names rolled up)
     *
     * Rolling the child names up in SQL keeps this a single round trip per
     * page of the admin grid; the AJAX handler then hydrates emergency
     * contact meta from connected_family_meta only for the rows we render.
     */
    public static function get_families($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array(
            'search'   => '',
            'page'     => 1,
            'per_page' => self::FAMILIES_PAGE_SIZE,
        ));
        $page     = max(1, (int) $args['page']);
        $per_page = max(1, min(100, (int) $args['per_page']));
        $offset   = ($page - 1) * $per_page;

        $fam_table  = Azure_Database::get_table_name('connected_family');
        $kid_table  = Azure_Database::get_table_name('user_children');
        if (!$fam_table || !$kid_table) {
            return array('rows' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page);
        }

        $users = $wpdb->users;

        $where  = '1=1';
        $params = array();
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where = '(u1.display_name LIKE %s OR u1.user_email LIKE %s
                      OR u2.display_name LIKE %s OR u2.user_email LIKE %s
                      OR f.display_name LIKE %s
                      OR EXISTS (SELECT 1 FROM ' . $kid_table . ' c
                                 WHERE c.family_id = f.id AND c.child_name LIKE %s))';
            $params = array($like, $like, $like, $like, $like, $like);
        }

        $total_sql = "SELECT COUNT(DISTINCT f.id)
                      FROM {$fam_table} f
                      LEFT JOIN {$users} u1 ON f.primary_user_id = u1.ID
                      LEFT JOIN {$users} u2 ON f.secondary_user_id = u2.ID
                      WHERE {$where}";
        $total = (int) $wpdb->get_var(empty($params) ? $total_sql : $wpdb->prepare($total_sql, $params));

        $rows_sql = "SELECT f.id, f.display_name AS family_name,
                            f.primary_user_id, f.secondary_user_id,
                            u1.display_name AS p1_name, u1.user_email AS p1_email,
                            u2.display_name AS p2_name, u2.user_email AS p2_email,
                            (SELECT COUNT(*) FROM {$kid_table} c WHERE c.family_id = f.id) AS child_count
                     FROM {$fam_table} f
                     LEFT JOIN {$users} u1 ON f.primary_user_id = u1.ID
                     LEFT JOIN {$users} u2 ON f.secondary_user_id = u2.ID
                     WHERE {$where}
                     ORDER BY f.id DESC
                     LIMIT %d OFFSET %d";
        $rows_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $rows_params));

        // Hydrate children + emergency contact meta only for visible rows.
        $family_ids = array_map(function ($r) { return (int) $r->id; }, $rows);
        $children_by_family = array();
        $em_by_family       = array();
        if (!empty($family_ids)) {
            $ids_in = implode(',', array_map('intval', $family_ids));

            $kids = $wpdb->get_results(
                "SELECT id, family_id, child_name FROM {$kid_table}
                 WHERE family_id IN ({$ids_in})
                 ORDER BY family_id ASC, child_name ASC"
            );
            foreach ($kids as $k) {
                $children_by_family[(int) $k->family_id][] = $k;
            }

            $fm_table = Azure_Database::get_table_name('connected_family_meta');
            if ($fm_table) {
                $em_keys = array(
                    'pta_pf_emergency_contact_name',
                    'pta_pf_emergency_contact_email',
                    'pta_pf_emergency_contact_cell',
                );
                $placeholders = implode(',', array_fill(0, count($em_keys), '%s'));
                $em_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT family_id, meta_key, meta_value
                     FROM {$fm_table}
                     WHERE family_id IN ({$ids_in}) AND meta_key IN ({$placeholders})",
                    $em_keys
                ));
                foreach ($em_rows as $row) {
                    $em_by_family[(int) $row->family_id][$row->meta_key] = $row->meta_value;
                }
            }
        }

        foreach ($rows as $r) {
            $r->children          = isset($children_by_family[$r->id]) ? $children_by_family[$r->id] : array();
            $r->emergency_contact = isset($em_by_family[$r->id]) ? $em_by_family[$r->id] : array();
        }

        return array(
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }

    public function ajax_search_families() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $result = self::get_families(array(
            'search'   => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'page'     => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'per_page' => self::FAMILIES_PAGE_SIZE,
        ));
        wp_send_json_success($result);
    }
}
