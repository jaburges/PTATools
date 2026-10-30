<?php
/**
 * AI Connectors admin screens.
 *
 *   Users > AI Connectors (PTA staff, see Policy::can_connect()): setup
 *     steps, skill file, your connected apps and personal access tokens.
 *   PTA Tools > AI Connectors (site admins only): every connection, the
 *     audit log, registered apps and settings. Entra accounts hold
 *     manage_options, so the page checks Policy::can_administer() too.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Admin {

    const NEW_TOKEN_TTL = 300;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'), 20);
        add_action('admin_post_pta_ai_create_pat', array($this, 'handle_create_pat'));
        add_action('admin_post_pta_ai_revoke', array($this, 'handle_revoke'));
        add_action('admin_post_pta_ai_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_pta_ai_delete_client', array($this, 'handle_delete_client'));
        add_action('admin_post_pta_ai_skill', array($this, 'handle_skill'));
        add_action('admin_post_pta_ai_approve_host', array($this, 'handle_approve_host'));
        add_action('admin_post_pta_ai_dismiss_host', array($this, 'handle_dismiss_host'));
        add_action('show_user_profile', array($this, 'profile_section'));
    }

    private function connect_capability() {
        $cap = (string) Azure_AI_Connectors_Settings::value('connect_capability');
        return in_array($cap, Azure_AI_Connectors_Settings::CONNECT_CAPABILITIES, true) ? $cap : 'edit_posts';
    }

    public function admin_menu() {
        add_submenu_page(
            'azure-plugin',
            'PTA Tools - AI Connectors',
            __('AI Connectors', 'azure-plugin'),
            'manage_options',
            Azure_AI_Connectors_Module::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
        add_users_page(
            __('AI Connectors', 'azure-plugin'),
            __('AI Connectors', 'azure-plugin'),
            $this->connect_capability(),
            Azure_AI_Connectors_Module::USER_PAGE,
            array($this, 'render_user_page')
        );
    }

    public function render_admin_page() {
        if (!Azure_AI_Connectors_Policy::can_administer()) {
            echo '<div class="wrap"><h1>' . esc_html__('AI Connectors', 'azure-plugin') . '</h1><div class="notice notice-info inline"><p>'
                . esc_html__('Only site administrators can change AI Connectors settings or see everyone\'s connections.', 'azure-plugin')
                . ' <a href="' . esc_url(Azure_AI_Connectors_Module::user_page_url()) . '">' . esc_html__('Set up your own connection', 'azure-plugin') . '</a></p></div></div>';
            return;
        }
        include AZURE_PLUGIN_PATH . 'admin/ai-connectors-page.php';
    }

    public function render_user_page() {
        $new_token = '';
        if (isset($_GET['pta_ai_new'])) {
            $key = 'pta_ai_new_' . get_current_user_id() . '_' . sanitize_key(wp_unslash($_GET['pta_ai_new']));
            $new_token = (string) get_transient($key);
            delete_transient($key);
        }
        include AZURE_PLUGIN_PATH . 'admin/ai-connectors-user-page.php';
    }

    public function profile_section($user) {
        if (!Azure_AI_Connectors_Policy::can_connect($user)) {
            return;
        }
        $count = count(Azure_AI_Connectors_Tokens::list_connections((int) $user->ID));
        echo '<h2>' . esc_html__('AI Connectors', 'azure-plugin') . '</h2><table class="form-table" role="presentation"><tr><th scope="row">'
            . esc_html__('Connected assistants', 'azure-plugin') . '</th><td>'
            . esc_html(sprintf(_n('%d active connection', '%d active connections', $count, 'azure-plugin'), $count))
            . ' &middot; <a href="' . esc_url(Azure_AI_Connectors_Module::user_page_url()) . '">' . esc_html__('Connect an assistant or manage tokens', 'azure-plugin') . '</a></td></tr></table>';
    }

    private function back($url, $notice, $type = 'success') {
        wp_safe_redirect(add_query_arg(array('pta_ai_notice' => rawurlencode($notice), 'pta_ai_type' => $type), $url));
        exit;
    }

    public function handle_create_pat() {
        check_admin_referer('pta_ai_create_pat');
        $user_id = get_current_user_id();
        $page = Azure_AI_Connectors_Module::user_page_url();
        if (!$user_id) {
            wp_die(esc_html__('Sign in first.', 'azure-plugin'), 403);
        }
        if (!Azure_AI_Connectors_Policy::can_connect()) {
            wp_die(esc_html__('AI assistants can only be connected to PTA staff accounts.', 'azure-plugin'), 403);
        }
        if (!Azure_AI_Connectors_Module::is_secure_request()) {
            $this->back($page, __('Tokens can only be created over HTTPS.', 'azure-plugin'), 'error');
        }
        if (!Azure_AI_Connectors_Module::rate_limit('pat_' . $user_id, 20, HOUR_IN_SECONDS)) {
            $this->back($page, __('Too many tokens created in the last hour. Try again later.', 'azure-plugin'), 'error');
        }
        $name = isset($_POST['token_name']) ? sanitize_text_field(wp_unslash($_POST['token_name'])) : '';
        $days = isset($_POST['expires_days']) ? absint($_POST['expires_days']) : 30;
        $scopes = empty($_POST['read_only'])
            ? Azure_AI_Connectors_Policy::scopes()
            : array(Azure_AI_Connectors_Policy::SCOPE_READ);
        $result = Azure_AI_Connectors_Tokens::create_pat($user_id, $name, $days, $scopes);
        if (is_wp_error($result)) {
            $this->back($page, $result->get_error_message(), 'error');
        }
        $key = strtolower(wp_generate_password(20, false, false));
        set_transient('pta_ai_new_' . $user_id . '_' . $key, $result['token'], self::NEW_TOKEN_TTL);
        wp_safe_redirect(add_query_arg('pta_ai_new', $key, $page));
        exit;
    }

    public function handle_revoke() {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer('pta_ai_revoke_' . $id);
        $as_admin = !empty($_POST['as_admin']) && Azure_AI_Connectors_Policy::can_administer();
        $back = $as_admin ? Azure_AI_Connectors_Module::admin_page_url() : Azure_AI_Connectors_Module::user_page_url();
        $result = Azure_AI_Connectors_Tokens::revoke_by_id($id, get_current_user_id(), $as_admin);
        if (is_wp_error($result)) {
            $this->back($back, $result->get_error_message(), 'error');
        }
        $this->back($back, __('Disconnected. The assistant can no longer use that connection.', 'azure-plugin'));
    }

    public function handle_save_settings() {
        check_admin_referer('pta_ai_settings');
        if (!Azure_AI_Connectors_Policy::can_administer()) {
            wp_die(esc_html__('Only site administrators can change AI Connectors settings.', 'azure-plugin'), 403);
        }
        $input = wp_unslash($_POST);
        $input['access_token_ttl'] = isset($input['access_token_minutes']) ? (int) $input['access_token_minutes'] * MINUTE_IN_SECONDS : null;
        $input['refresh_token_ttl'] = isset($input['refresh_token_days']) ? (int) $input['refresh_token_days'] * DAY_IN_SECONDS : null;
        foreach (array('entra_restricted_roles', 'content_limited_roles') as $role_list) {
            if (!isset($input[$role_list])) {
                $input[$role_list] = array();
            }
        }
        Azure_AI_Connectors_Settings::save($input);
        $this->back(Azure_AI_Connectors_Module::admin_page_url('settings'), __('Settings saved.', 'azure-plugin'));
    }

    public function handle_delete_client() {
        $client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
        check_admin_referer('pta_ai_delete_client_' . $client_id);
        if (!Azure_AI_Connectors_Policy::can_administer()) {
            wp_die(esc_html__('Only site administrators can remove apps.', 'azure-plugin'), 403);
        }
        Azure_AI_Connectors_Tokens::delete_client($client_id);
        $this->back(Azure_AI_Connectors_Module::admin_page_url('apps'), __('App removed and its connections revoked.', 'azure-plugin'));
    }

    public function handle_approve_host() {
        $host = isset($_POST['host']) ? Azure_AI_Connectors_Settings::normalize_host(wp_unslash($_POST['host'])) : '';
        check_admin_referer('pta_ai_approve_host_' . $host);
        if (!Azure_AI_Connectors_Policy::can_administer()) {
            wp_die(esc_html__('Only site administrators can approve assistants.', 'azure-plugin'), 403);
        }
        $back = Azure_AI_Connectors_Module::admin_page_url('apps');
        if (!Azure_AI_Connectors_Settings::approve_host($host)) {
            $this->back($back, __('That is not a valid domain.', 'azure-plugin'), 'error');
        }
        /* translators: %s: domain, e.g. gemini.google.com */
        $this->back($back, sprintf(__('%s approved. Start connecting again from the assistant.', 'azure-plugin'), $host));
    }

    public function handle_dismiss_host() {
        $host = isset($_POST['host']) ? Azure_AI_Connectors_Settings::normalize_host(wp_unslash($_POST['host'])) : '';
        check_admin_referer('pta_ai_dismiss_host_' . $host);
        if (!Azure_AI_Connectors_Policy::can_administer()) {
            wp_die(esc_html__('Only site administrators can manage assistants.', 'azure-plugin'), 403);
        }
        Azure_AI_Connectors_Settings::dismiss_host($host);
        $this->back(Azure_AI_Connectors_Module::admin_page_url('apps'), __('Request dismissed. The domain stays unapproved.', 'azure-plugin'));
    }

    /** Approve / Dismiss buttons for a pending assistant domain. */
    public static function host_button($action, $host, $label, $class = 'button') {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="host" value="' . esc_attr($host) . '">';
        $html .= wp_nonce_field($action . '_' . $host, '_wpnonce', true, false);
        $html .= '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
        return $html;
    }

    public function handle_skill() {
        check_admin_referer('pta_ai_skill');
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Sign in first.', 'azure-plugin'), 403);
        }
        if (!Azure_AI_Connectors_Policy::can_connect()) {
            wp_die(esc_html__('AI assistants can only be connected to PTA staff accounts.', 'azure-plugin'), 403);
        }
        nocache_headers();
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . Azure_AI_Connectors_Skill::filename() . '"');
        header('X-Content-Type-Options: nosniff');
        echo Azure_AI_Connectors_Skill::render(wp_get_current_user());
        exit;
    }

    /** Shared by both pages. */
    public static function render_notice() {
        if (empty($_GET['pta_ai_notice'])) {
            return;
        }
        $type = (isset($_GET['pta_ai_type']) && $_GET['pta_ai_type'] === 'error') ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['pta_ai_notice']))) . '</p></div>';
    }

    public static function revoke_button($row, $as_admin = false) {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js(__('Disconnect this? The assistant will stop working until it is connected again.', 'azure-plugin')) . '\');">';
        $html .= '<input type="hidden" name="action" value="pta_ai_revoke"><input type="hidden" name="id" value="' . (int) $row->id . '">';
        if ($as_admin) {
            $html .= '<input type="hidden" name="as_admin" value="1">';
        }
        $html .= wp_nonce_field('pta_ai_revoke_' . (int) $row->id, '_wpnonce', true, false);
        $html .= '<button type="submit" class="button button-link-delete">' . esc_html__('Revoke', 'azure-plugin') . '</button></form>';
        return $html;
    }

    public static function format_time($gmt) {
        if (empty($gmt)) {
            return '—';
        }
        return esc_html(get_date_from_gmt((string) $gmt, 'M j, Y g:i a'));
    }

    public static function scope_label($scope) {
        return strpos((string) $scope, Azure_AI_Connectors_Policy::SCOPE_WRITE) !== false
            ? __('Read and change', 'azure-plugin')
            : __('Read only', 'azure-plugin');
    }
}
