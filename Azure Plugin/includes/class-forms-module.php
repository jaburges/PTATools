<?php
/**
 * Forms: a lightweight replacement for Forminator.
 *
 * Builder (Communications > Forms) saves a field schema; [pta_form id="…"]
 * renders it; visitors submit to POST /pta/v1/forms/{id}/submit; entries
 * land in azure_form_entries and fire `pta_form_submitted` for the rules
 * engine.
 *
 * Pages are edge-cached for guests, so nothing personal is rendered into
 * the form HTML. Signed-in visitors get a REST nonce and their prefill
 * from admin-ajax after page load.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-forms-schema.php';
require_once __DIR__ . '/class-forms-themes.php';

class Azure_Forms_Module {

    const SHORTCODE     = 'pta_form';
    const REST_NS       = 'pta/v1';
    const STATUSES      = array('draft', 'open', 'closed');
    const ENTRY_STATUSES = array('new', 'read', 'spam');
    const RATE_LIMIT    = 5;
    const RATE_WINDOW   = 600;
    const PURGE_HOOK    = 'azure_forms_purge_entries';
    const AJAX_NONCE    = 'azure_forms_admin';
    const PER_PAGE      = 50;
    const TURNSTILE_OPTION = 'azure_forms_turnstile';
    const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private static $instance = null;

    /** @var array<int,array> Request-level form cache. */
    private $forms = array();

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_pta_forms_session', array($this, 'ajax_session'));
        add_action('wp_ajax_nopriv_pta_forms_session', array($this, 'ajax_session'));
        add_action('init', array($this, 'maybe_handle_page_post'), 20);
        add_action(self::PURGE_HOOK, array($this, 'purge_old_entries'));

        require_once AZURE_PLUGIN_PATH . 'includes/class-forms-registration.php';
        Azure_Forms_Registration::get_instance();

        if (is_admin()) {
            foreach (array('save', 'delete', 'duplicate', 'preview', 'entry', 'theme_save', 'theme_delete', 'theme_copy') as $op) {
                add_action('wp_ajax_azure_forms_' . $op, array($this, 'ajax_' . $op));
            }
            add_action('admin_post_azure_forms_export', array($this, 'export_csv'));
            add_action('admin_post_azure_forms_settings', array($this, 'save_global_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
    }

    public static function admin_cap() {
        return class_exists('Azure_Admin_Menu_Customizer') ? Azure_Admin_Menu_Customizer::CAP : 'manage_options';
    }

    private static function forms_table() {
        return Azure_Database::get_table_name('forms');
    }

    private static function entries_table() {
        return Azure_Database::get_table_name('form_entries');
    }

    // ─── Settings ─────────────────────────────────────────────────────

    public static function default_settings() {
        return array(
            'submit_label'      => __('Submit', 'azure-plugin'),
            'success_message'   => __('Thanks! Your response has been received.', 'azure-plugin'),
            'redirect_url'      => '',
            'require_login'     => false,
            'limit_per_person'  => 0,
            'close_at'          => '',
            'closed_message'    => '',
            'retention_days'    => 365,
            'theme'             => 'default',
            'turnstile'         => false,
            'registration'      => false,
        );
    }

    /**
     * Site-wide Cloudflare Turnstile keys (Forms > Settings).
     *
     * @return array{site_key:string,secret_key:string}
     */
    public static function turnstile_keys() {
        $saved = get_option(self::TURNSTILE_OPTION, array());
        $saved = is_array($saved) ? $saved : array();
        return array(
            'site_key'   => (string) ($saved['site_key'] ?? ''),
            'secret_key' => (string) ($saved['secret_key'] ?? ''),
        );
    }

    public static function turnstile_active(array $settings) {
        if (empty($settings['turnstile'])) {
            return false;
        }
        $keys = self::turnstile_keys();
        return $keys['site_key'] !== '' && $keys['secret_key'] !== '';
    }

    /**
     * @return string '' when verified (or Cloudflare is unreachable), else 'captcha'.
     */
    public static function verify_turnstile($token, $ip) {
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 2048) {
            return 'captcha';
        }
        $response = wp_remote_post(self::TURNSTILE_VERIFY, array(
            'timeout' => 8,
            'body'    => array(
                'secret'   => self::turnstile_keys()['secret_key'],
                'response' => $token,
                'remoteip' => $ip,
            ),
        ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            // The honeypot, time trap and rate limit still apply; an outage
            // at Cloudflare should not stop parents submitting.
            Azure_Logger::warning('Forms: Turnstile verify unavailable, allowing submission', array(
                'module' => 'Forms',
                'error'  => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response),
            ));
            return '';
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        return (is_array($body) && !empty($body['success'])) ? '' : 'captcha';
    }

    public function save_global_settings() {
        if (!current_user_can(self::admin_cap())) {
            wp_die(esc_html__('You do not have permission to change form settings.', 'azure-plugin'), 403);
        }
        check_admin_referer('azure_forms_settings');
        $current = self::turnstile_keys();
        $site = isset($_POST['turnstile_site_key']) ? sanitize_text_field(wp_unslash($_POST['turnstile_site_key'])) : '';
        $secret = isset($_POST['turnstile_secret_key']) ? sanitize_text_field(wp_unslash($_POST['turnstile_secret_key'])) : '';
        $clear = !empty($_POST['turnstile_clear']);
        update_option(self::TURNSTILE_OPTION, array(
            'site_key'   => $clear ? '' : $site,
            'secret_key' => $clear ? '' : ($secret !== '' ? $secret : $current['secret_key']),
        ), false);
        if (isset($_POST['register_page'])) {
            update_option(Azure_Forms_Registration::PAGE_OPTION, absint($_POST['register_page']), false);
        }
        $this->purge_edge_cache();
        wp_safe_redirect(admin_url('admin.php?page=azure-plugin-forms&tab=settings&saved=1'));
        exit;
    }

    /**
     * @param mixed $raw
     */
    public static function normalize_settings($raw) {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }
        $raw = is_array($raw) ? $raw : array();
        $d = self::default_settings();

        $text = function ($v, $max) {
            $v = sanitize_text_field((string) $v);
            return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
        };

        $close_at = isset($raw['close_at']) ? trim((string) $raw['close_at']) : '';
        if ($close_at !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $close_at);
            $close_at = ($dt && $dt->format('Y-m-d\TH:i') === $close_at) ? $close_at : '';
        }

        $submit = isset($raw['submit_label']) ? $text($raw['submit_label'], 60) : '';
        $success = isset($raw['success_message']) ? wp_kses_post((string) $raw['success_message']) : '';

        return array(
            'submit_label'     => $submit !== '' ? $submit : $d['submit_label'],
            'success_message'  => trim($success) !== '' ? $success : $d['success_message'],
            'redirect_url'     => isset($raw['redirect_url']) ? esc_url_raw(trim((string) $raw['redirect_url'])) : '',
            'require_login'    => !empty($raw['require_login']),
            'limit_per_person' => isset($raw['limit_per_person']) ? max(0, min(100, (int) $raw['limit_per_person'])) : 0,
            'close_at'         => $close_at,
            'closed_message'   => isset($raw['closed_message']) ? $text($raw['closed_message'], 500) : '',
            'retention_days'   => isset($raw['retention_days']) ? max(0, min(3650, (int) $raw['retention_days'])) : $d['retention_days'],
            'theme'            => Azure_Forms_Themes::clean_slug($raw['theme'] ?? 'default') ?: 'default',
            'turnstile'        => !empty($raw['turnstile']),
            'registration'     => !empty($raw['registration']),
        );
    }

    /**
     * @param array    $form
     * @param int|null $now Unix time.
     */
    public static function is_accepting($form, $now = null) {
        if (($form['status'] ?? '') !== 'open') {
            return false;
        }
        $close_at = $form['settings']['close_at'] ?? '';
        if ($close_at === '') {
            return true;
        }
        $now = $now === null ? time() : (int) $now;
        try {
            $close = new DateTime($close_at, wp_timezone());
        } catch (\Exception $e) {
            return true;
        }
        return $now < $close->getTimestamp();
    }

    // ─── Storage ──────────────────────────────────────────────────────

    private static function hydrate($row) {
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        $schema = json_decode((string) ($row['schema_json'] ?? ''), true);
        return array(
            'id'                   => (int) $row['id'],
            'title'                => (string) $row['title'],
            'slug'                 => (string) $row['slug'],
            'status'               => in_array($row['status'], self::STATUSES, true) ? $row['status'] : 'draft',
            'schema'               => is_array($schema) ? $schema : array(),
            'settings'             => self::normalize_settings($row['settings_json'] ?? ''),
            'legacy_forminator_id' => isset($row['legacy_forminator_id']) && $row['legacy_forminator_id'] !== null ? (int) $row['legacy_forminator_id'] : 0,
            'created_by'           => (int) ($row['created_by'] ?? 0),
            'created_at'           => (string) ($row['created_at'] ?? ''),
            'updated_at'           => (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * @param int|string $ref Form id or slug.
     */
    public function get_form($ref) {
        global $wpdb;
        if (is_numeric($ref)) {
            $id = (int) $ref;
            if ($id < 1) {
                return null;
            }
            if (!array_key_exists($id, $this->forms)) {
                $this->forms[$id] = self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::forms_table() . ' WHERE id = %d', $id)));
            }
            return $this->forms[$id];
        }
        $slug = sanitize_title((string) $ref);
        if ($slug === '') {
            return null;
        }
        $form = self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::forms_table() . ' WHERE slug = %s', $slug)));
        if ($form) {
            $this->forms[$form['id']] = $form;
        }
        return $form;
    }

    public function get_form_by_legacy_id($legacy_id) {
        global $wpdb;
        $legacy_id = (int) $legacy_id;
        if ($legacy_id < 1) {
            return null;
        }
        return self::hydrate($wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::forms_table() . ' WHERE legacy_forminator_id = %d ORDER BY id DESC LIMIT 1',
            $legacy_id
        )));
    }

    /**
     * @return array<int,array> Forms with `entry_count` and `new_count`.
     */
    public function get_forms() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::forms_table() . ' ORDER BY updated_at DESC, id DESC');
        $counts = array();
        $count_rows = $wpdb->get_results(
            'SELECT form_id, COUNT(*) AS total, SUM(status = \'new\') AS fresh FROM ' . self::entries_table()
            . ' WHERE status <> \'spam\' GROUP BY form_id'
        );
        foreach ((array) $count_rows as $c) {
            $counts[(int) $c->form_id] = array((int) $c->total, (int) $c->fresh);
        }
        $forms = array();
        foreach ((array) $rows as $row) {
            $form = self::hydrate($row);
            $form['entry_count'] = $counts[$form['id']][0] ?? 0;
            $form['new_count'] = $counts[$form['id']][1] ?? 0;
            $forms[] = $form;
        }
        return $forms;
    }

    private function unique_slug($slug, $exclude_id = 0) {
        global $wpdb;
        $base = sanitize_title($slug);
        if ($base === '') {
            $base = 'form';
        }
        $base = substr($base, 0, 180);
        $candidate = $base;
        for ($i = 2; $i < 500; $i++) {
            $taken = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::forms_table() . ' WHERE slug = %s AND id <> %d',
                $candidate,
                (int) $exclude_id
            ));
            if (!$taken) {
                return $candidate;
            }
            $candidate = $base . '-' . $i;
        }
        return $base . '-' . wp_generate_password(4, false, false);
    }

    /**
     * @return int|WP_Error Form id.
     */
    public function save_form($id, array $input) {
        global $wpdb;
        $id = (int) $id;
        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        if ($title === '') {
            return new WP_Error('title_required', __('Give the form a title.', 'azure-plugin'));
        }
        $status = in_array($input['status'] ?? '', self::STATUSES, true) ? $input['status'] : 'draft';
        $schema = Azure_Forms_Schema::sanitize_schema($input['schema'] ?? array());
        $settings = self::normalize_settings($input['settings'] ?? array());
        $slug = $this->unique_slug(($input['slug'] ?? '') !== '' ? $input['slug'] : $title, $id);
        $legacy = isset($input['legacy_forminator_id']) && (int) $input['legacy_forminator_id'] > 0 ? (int) $input['legacy_forminator_id'] : null;

        $row = array(
            'title'                => $title,
            'slug'                 => $slug,
            'status'               => $status,
            'schema_json'          => wp_json_encode($schema),
            'settings_json'        => wp_json_encode($settings),
            'legacy_forminator_id' => $legacy,
            'updated_at'           => current_time('mysql', true),
        );

        if ($id > 0) {
            if (!$this->get_form($id)) {
                return new WP_Error('not_found', __('That form no longer exists.', 'azure-plugin'));
            }
            $ok = $wpdb->update(self::forms_table(), $row, array('id' => $id));
            if ($ok === false) {
                return new WP_Error('db', __('Could not save the form.', 'azure-plugin'));
            }
        } else {
            $row['created_by'] = get_current_user_id();
            $row['created_at'] = current_time('mysql', true);
            if ($wpdb->insert(self::forms_table(), $row) === false) {
                return new WP_Error('db', __('Could not create the form.', 'azure-plugin'));
            }
            $id = (int) $wpdb->insert_id;
        }
        unset($this->forms[$id]);
        $this->purge_edge_cache();
        return $id;
    }

    public function delete_form($id) {
        global $wpdb;
        $id = (int) $id;
        $wpdb->delete(self::entries_table(), array('form_id' => $id));
        $wpdb->delete(self::forms_table(), array('id' => $id));
        if (self::notify_available() && $id > 0) {
            $wpdb->delete(Azure_Order_Rules_Module::table_name(), array(
                'trigger_type'  => Azure_Order_Rules_Module::TRIGGER_FORM_SUBMITTED,
                'trigger_value' => (string) $id,
            ));
        }
        unset($this->forms[$id]);
        $this->purge_edge_cache();
    }

    private function purge_edge_cache($reason = 'forms') {
        if (class_exists('Azure_Edge_Cache')) {
            Azure_Edge_Cache::get_instance()->request_purge($reason);
        }
    }

    // ─── Shortcode ────────────────────────────────────────────────────

    public function render_shortcode($atts = array()) {
        $atts = shortcode_atts(array('id' => '', 'slug' => '', 'theme' => ''), is_array($atts) ? $atts : array(), self::SHORTCODE);
        $ref = $atts['id'] !== '' ? $atts['id'] : $atts['slug'];
        $form = $ref !== '' ? $this->get_form($ref) : null;
        return $this->render_form($form, $atts['theme']);
    }

    /**
     * @param array|null $form
     * @param string     $theme_slug Overrides the form's default theme.
     */
    public function render_form($form, $theme_slug = '') {
        $is_editor = current_user_can(self::admin_cap());

        if (!$form) {
            return $is_editor ? $this->editor_notice(__('This form could not be found. Check the id in the [pta_form] shortcode.', 'azure-plugin')) : '';
        }

        $settings = $form['settings'];
        $notice = '';
        if (!self::is_accepting($form)) {
            if (!$is_editor) {
                return $settings['closed_message'] !== ''
                    ? '<div class="pta-form-closed">' . esc_html($settings['closed_message']) . '</div>'
                    : '';
            }
            $notice = $this->editor_notice($form['status'] === 'draft'
                ? __('This form is a draft. Only editors can see it; open it in Communications > Forms to publish.', 'azure-plugin')
                : __('This form is closed. Only editors can see it.', 'azure-plugin'));
        }

        $theme = Azure_Forms_Themes::get_theme($theme_slug !== '' ? $theme_slug : $settings['theme']);
        $this->enqueue_frontend_assets();

        $uid = 'pta-form-' . $form['id'] . '-' . wp_generate_password(6, false, false);
        $sent = isset($_GET['pta_form_sent']) && (int) $_GET['pta_form_sent'] === $form['id'];
        $failed = isset($_GET['pta_form_error']) && (int) $_GET['pta_form_error'] === $form['id'];

        $html = Azure_Forms_Themes::style_tag_once($theme);
        $html .= '<div class="pta-form-wrap pta-form-theme-' . esc_attr($theme['slug']) . '" id="' . esc_attr($uid) . '">';
        $html .= $notice;
        if ($theme['header_text'] !== '') {
            $html .= '<div class="pta-form__header">' . $theme['header_text'] . '</div>';
        }

        if ($sent) {
            $html .= '<div class="pta-form__success" role="status">' . wpautop($settings['success_message']) . '</div>';
        } elseif ($settings['require_login'] && !is_user_logged_in()) {
            $html .= '<div class="pta-form__login">' . sprintf(
                wp_kses(__('Please <a href="%s">sign in</a> to fill in this form.', 'azure-plugin'), array('a' => array('href' => true))),
                esc_url(wp_login_url($this->current_url()))
            ) . '</div>';
        } else {
            $html .= $this->render_form_element($form, $uid, $failed);
        }

        if ($theme['footer_html'] !== '') {
            $html .= '<div class="pta-form__footer">' . $theme['footer_html'] . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function render_form_element(array $form, $uid, $failed) {
        $settings = $form['settings'];
        $token = Azure_Forms_Schema::timing_token(time(), self::guard_secret());
        $endpoint = rest_url(self::REST_NS . '/forms/' . $form['id'] . '/submit');

        $html = '<form class="pta-form" method="post" action="' . esc_url($this->current_url()) . '"'
            . ' data-form-id="' . (int) $form['id'] . '"'
            . ' data-endpoint="' . esc_url($endpoint) . '"'
            . ' data-success="' . esc_attr(wpautop($settings['success_message'])) . '"'
            . ($settings['registration'] ? ' data-registration="1"' : '')
            . ' data-redirect="' . esc_url($settings['redirect_url']) . '">';
        $html .= '<input type="hidden" name="_pta_form" value="' . (int) $form['id'] . '">';
        $html .= '<input type="hidden" name="_pta_ts" value="' . esc_attr($token) . '">';
        $html .= '<input type="hidden" name="_pta_elapsed" value="">';
        $html .= '<div class="pta-form__hp" aria-hidden="true"><label>' . esc_html__('Leave this empty', 'azure-plugin')
            . ' <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>';

        if ($failed) {
            $html .= '<div class="pta-form__status is-error" role="alert">' . esc_html($this->page_post_error_message($form)) . '</div>';
        } else {
            $html .= '<div class="pta-form__status" role="status" aria-live="polite" hidden></div>';
        }

        $html .= '<div class="pta-form__fields">' . Azure_Forms_Schema::render_fields($form['schema'], $uid) . '</div>';
        if (self::turnstile_active($settings)) {
            wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array('in_footer' => true, 'strategy' => 'defer'));
            $html .= '<div class="pta-form__captcha cf-turnstile" data-sitekey="' . esc_attr(self::turnstile_keys()['site_key']) . '" data-theme="light"></div>';
        }
        $html .= '<div class="pta-form__actions"><button type="submit" class="pta-form__submit">' . esc_html($settings['submit_label']) . '</button></div>';
        $html .= '</form>';
        $html .= '<div class="pta-form__success" role="status" hidden></div>';
        return $html;
    }

    private function page_post_error_message(array $form) {
        $code = isset($_GET['pta_form_reason']) ? sanitize_key(wp_unslash($_GET['pta_form_reason'])) : '';
        $messages = $this->error_messages();
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        $labels = array();
        $names = isset($_GET['pta_form_fields']) ? array_filter(explode(',', sanitize_text_field(wp_unslash($_GET['pta_form_fields'])))) : array();
        foreach (Azure_Forms_Schema::input_fields($form['schema']) as $field) {
            if (in_array($field['name'], $names, true)) {
                $labels[] = wp_strip_all_tags($field['label']);
            }
        }
        return $labels
            ? sprintf(__('Please check these answers and try again: %s', 'azure-plugin'), implode(', ', $labels))
            : __('Something went wrong. Please try again.', 'azure-plugin');
    }

    private function editor_notice($text) {
        return '<div class="pta-form-notice">' . esc_html($text) . '</div>';
    }

    private function current_url() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $url = home_url($uri);
        return remove_query_arg(array('pta_form_sent', 'pta_form_error', 'pta_form_reason', 'pta_form_fields'), $url);
    }

    public function enqueue_frontend_assets() {
        if (wp_script_is('pta-forms', 'enqueued')) {
            return;
        }
        wp_enqueue_style('pta-forms', AZURE_PLUGIN_URL . 'css/pta-forms.css', array(), AZURE_PLUGIN_VERSION);
        wp_enqueue_script('pta-forms', AZURE_PLUGIN_URL . 'js/pta-forms.js', array(), AZURE_PLUGIN_VERSION, true);
        wp_localize_script('pta-forms', 'ptaForms', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'accountUrl' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'),
            'strings' => array(
                'signedIn' => __("You're already signed in, so there's no need to register. Manage your family's details in", 'azure-plugin'),
                'myAccount' => __('My Account', 'azure-plugin'),
                'childN'   => __('Child %d', 'azure-plugin'),
                'sending' => __('Sending…', 'azure-plugin'),
                'error'   => __('Something went wrong. Please try again.', 'azure-plugin'),
                'network' => __('Network error. Please check your connection and try again.', 'azure-plugin'),
                'fix'     => __('Please check the highlighted answers.', 'azure-plugin'),
            ),
        ));
    }

    // ─── Session (signed-in prefill) ──────────────────────────────────

    /**
     * Cached pages cannot carry a nonce or personal data, so signed-in
     * visitors fetch both here after load. Guests get logged_in=false.
     */
    public function ajax_session() {
        nocache_headers();
        if (!is_user_logged_in()) {
            wp_send_json_success(array('logged_in' => false));
        }
        $user = wp_get_current_user();
        $first = (string) get_user_meta($user->ID, 'first_name', true);
        $last = (string) get_user_meta($user->ID, 'last_name', true);
        $phone = (string) get_user_meta($user->ID, 'billing_phone', true);
        $full = trim($first . ' ' . $last);
        $children = array();
        if (class_exists('Azure_User_Children')) {
            foreach ((array) Azure_User_Children::get_children_for_user($user->ID) as $child) {
                $name = trim((string) ($child->child_name ?? ''));
                if ($name !== '') {
                    $children[] = $name;
                }
            }
        }
        wp_send_json_success(array(
            'logged_in' => true,
            'nonce'     => wp_create_nonce('wp_rest'),
            'children'  => array_values(array_unique($children)),
            'prefill'   => array(
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => $full !== '' ? $full : (string) $user->display_name,
                'email'      => (string) $user->user_email,
                'phone'      => $phone,
            ),
        ));
    }

    // ─── Submission ───────────────────────────────────────────────────

    public function register_rest_routes() {
        register_rest_route(self::REST_NS, '/forms/(?P<id>\d+)/submit', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_submit'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_submit($request) {
        $form = $this->get_form((int) $request['id']);
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_body_params();
        }
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
        $result = $this->process_submission($form, $fields, array(
            'website'   => $params['website'] ?? '',
            'token'     => $params['_pta_ts'] ?? '',
            'elapsed'   => $params['_pta_elapsed'] ?? null,
            'turnstile' => $params['_pta_turnstile'] ?? '',
        ));
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return new WP_REST_Response(array(
                'success' => false,
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'errors'  => is_array($data) && isset($data['errors']) ? $data['errors'] : (object) array(),
            ), is_array($data) && isset($data['status']) ? (int) $data['status'] : 400);
        }
        return new WP_REST_Response($result, 200);
    }

    /**
     * No-JS fallback: the form posts to its own page.
     */
    public function maybe_handle_page_post() {
        if (empty($_POST['_pta_form']) || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax()) {
            return;
        }
        $form_id = (int) $_POST['_pta_form'];
        $form = $this->get_form($form_id);
        $fields = isset($_POST[Azure_Forms_Schema::INPUT_PREFIX]) && is_array($_POST[Azure_Forms_Schema::INPUT_PREFIX])
            ? wp_unslash($_POST[Azure_Forms_Schema::INPUT_PREFIX])
            : array();
        $result = $this->process_submission($form, $fields, array(
            'website'   => isset($_POST['website']) ? wp_unslash($_POST['website']) : '',
            'token'     => isset($_POST['_pta_ts']) ? wp_unslash($_POST['_pta_ts']) : '',
            'elapsed'   => null,
            'turnstile' => isset($_POST['cf-turnstile-response']) ? wp_unslash($_POST['cf-turnstile-response']) : '',
        ));

        $back = wp_get_referer();
        if (!$back) {
            $back = $this->current_url();
        }
        $back = remove_query_arg(array('pta_form_sent', 'pta_form_error', 'pta_form_reason', 'pta_form_fields'), $back);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $args = array('pta_form_error' => $form_id, 'pta_form_reason' => $result->get_error_code());
            if (is_array($data) && !empty($data['errors'])) {
                $args['pta_form_fields'] = implode(',', array_keys($data['errors']));
            }
            wp_safe_redirect(add_query_arg($args, $back) . '#pta-form-' . $form_id);
            exit;
        }
        if (!empty($result['redirect'])) {
            wp_safe_redirect($result['redirect']);
            exit;
        }
        wp_safe_redirect(add_query_arg('pta_form_sent', $form_id, $back));
        exit;
    }

    private function error_messages() {
        return array(
            'not_found'    => __('This form is no longer available.', 'azure-plugin'),
            'closed'       => __('This form is closed.', 'azure-plugin'),
            'login'        => __('Please sign in to fill in this form.', 'azure-plugin'),
            'too_fast'     => __('That was quick! Please wait a moment and submit again.', 'azure-plugin'),
            'bad_token'    => __('This page is out of date. Please refresh it and try again.', 'azure-plugin'),
            'rate_limited' => __('Too many submissions from your network. Please try again in a few minutes.', 'azure-plugin'),
            'rejected'     => __('Your response could not be accepted. Please contact us if you think this is a mistake.', 'azure-plugin'),
            'limit'        => __('You have already filled in this form.', 'azure-plugin'),
            'invalid'      => __('Please check the highlighted answers.', 'azure-plugin'),
            'captcha'      => __('Please complete the "verify you are human" check and try again.', 'azure-plugin'),
            'db'           => __('Something went wrong saving your response. Please try again.', 'azure-plugin'),
        );
    }

    private function fail($code, $status, array $errors = array()) {
        $messages = $this->error_messages();
        $data = array('status' => $status);
        if ($errors) {
            $data['errors'] = $errors;
        }
        return new WP_Error($code, $messages[$code] ?? $messages['db'], $data);
    }

    /**
     * Shared by REST and the no-JS page post.
     *
     * @param array|null $form
     * @param array      $fields name => raw value.
     * @param array      $guard  website, token, elapsed.
     * @return array|WP_Error
     */
    public function process_submission($form, array $fields, array $guard) {
        global $wpdb;

        if (!$form) {
            return $this->fail('not_found', 404);
        }
        $user_id = get_current_user_id();
        $is_editor = $user_id && current_user_can(self::admin_cap());
        if (!self::is_accepting($form) && !$is_editor) {
            return $this->fail('closed', 410);
        }
        $settings = $form['settings'];
        if ($settings['require_login'] && !$user_id) {
            return $this->fail('login', 401);
        }

        $reason = Azure_Forms_Schema::check_guard(
            (string) ($guard['website'] ?? ''),
            (string) ($guard['token'] ?? ''),
            $guard['elapsed'] ?? null,
            time(),
            self::guard_secret()
        );
        if ($reason === 'honeypot') {
            Azure_Logger::info('Forms: honeypot submission dropped for form ' . $form['id'], array('module' => 'Forms'));
            return array('success' => true, 'message' => $settings['success_message'], 'redirect' => '');
        }
        if ($reason !== '') {
            return $this->fail($reason, 400);
        }

        $ip = self::client_ip();
        if (self::turnstile_active($settings)) {
            $captcha = self::verify_turnstile((string) ($guard['turnstile'] ?? ''), $ip);
            if ($captcha !== '') {
                return $this->fail($captcha, 400);
            }
        }
        $rate_key = 'pta_form_rate_' . md5(($user_id ? 'u' . $user_id : $ip) . '|' . $form['id']);
        $count = (int) get_transient($rate_key);
        if ($count >= self::RATE_LIMIT && !$is_editor) {
            return $this->fail('rate_limited', 429);
        }

        $checked = Azure_Forms_Schema::validate_submission($form['schema'], $fields);
        if (empty($checked['errors'])) {
            $checked['errors'] = (array) apply_filters('pta_form_validate', array(), $form, $checked['data'], $user_id);
        }
        if (!empty($checked['errors'])) {
            return $this->fail('invalid', 422, $checked['errors']);
        }
        set_transient($rate_key, $count + 1, self::RATE_WINDOW);
        $data = $checked['data'];
        $email = Azure_Forms_Schema::first_email($form['schema'], $data);

        if (!$user_id && $email !== '' && class_exists('Azure_Anti_Spam')) {
            $spam = Azure_Anti_Spam::check_signup('', $email, '');
            if ($spam !== null) {
                Azure_Logger::info(sprintf('Forms: anti-spam rejected form %d submission: %s [%s]', $form['id'], $spam, $email), array('module' => 'Forms'));
                return $this->fail('rejected', 422);
            }
        }

        if ($settings['limit_per_person'] > 0 && $this->count_person_entries($form, $user_id, $email) >= $settings['limit_per_person']) {
            return $this->fail('limit', 409);
        }

        $ok = $wpdb->insert(self::entries_table(), array(
            'form_id'    => $form['id'],
            'user_id'    => $user_id,
            'data_json'  => wp_json_encode($data),
            'ip_hash'    => hash('sha256', wp_salt('auth') . '|' . $ip),
            'user_agent' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            'status'     => 'new',
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            Azure_Logger::error('Forms: could not store entry for form ' . $form['id'] . ': ' . $wpdb->last_error, array('module' => 'Forms'));
            return $this->fail('db', 500);
        }
        $entry_id = (int) $wpdb->insert_id;

        try {
            do_action('pta_form_submitted', $form, $entry_id, $data, $user_id);
        } catch (\Throwable $e) {
            Azure_Logger::error('Forms: pta_form_submitted handler failed: ' . $e->getMessage(), array('module' => 'Forms'));
        }

        return array(
            'success'  => true,
            'entry_id' => $entry_id,
            'message'  => wpautop($settings['success_message']),
            'redirect' => $settings['redirect_url'],
        );
    }

    private function count_person_entries(array $form, $user_id, $email) {
        global $wpdb;
        if ($user_id) {
            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::entries_table() . ' WHERE form_id = %d AND user_id = %d AND status <> %s',
                $form['id'], $user_id, 'spam'
            ));
        }
        if ($email === '') {
            return 0;
        }
        $email_field = '';
        foreach (Azure_Forms_Schema::input_fields($form['schema']) as $field) {
            if ($field['type'] === 'email') {
                $email_field = $field['name'];
                break;
            }
        }
        if ($email_field === '') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::entries_table()
            . ' WHERE form_id = %d AND status <> %s AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data_json, %s))) = %s',
            $form['id'], 'spam', '$."' . $email_field . '"', strtolower($email)
        ));
    }

    public static function guard_secret() {
        return wp_salt('nonce') . '|pta-forms';
    }

    public static function client_ip() {
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR') as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if ($ip !== '') {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }

    // ─── Entries ──────────────────────────────────────────────────────

    /**
     * @return array{rows:array,total:int}
     */
    public function get_entries($form_id, $status = '', $page = 1, $per_page = self::PER_PAGE) {
        global $wpdb;
        $where = $wpdb->prepare('form_id = %d', (int) $form_id);
        if (in_array($status, self::ENTRY_STATUSES, true)) {
            $where .= $wpdb->prepare(' AND status = %s', $status);
        } else {
            $where .= " AND status <> 'spam'";
        }
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::entries_table() . ' WHERE ' . $where);
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $rows = $per_page > 0
            ? $wpdb->get_results('SELECT * FROM ' . self::entries_table() . ' WHERE ' . $where . $wpdb->prepare(' ORDER BY id DESC LIMIT %d OFFSET %d', (int) $per_page, $offset))
            : $wpdb->get_results('SELECT * FROM ' . self::entries_table() . ' WHERE ' . $where . ' ORDER BY id DESC');
        $out = array();
        foreach ((array) $rows as $row) {
            $data = json_decode((string) $row->data_json, true);
            $out[] = array(
                'id'         => (int) $row->id,
                'form_id'    => (int) $row->form_id,
                'user_id'    => (int) $row->user_id,
                'status'     => (string) $row->status,
                'created_at' => (string) $row->created_at,
                'data'       => is_array($data) ? $data : array(),
            );
        }
        return array('rows' => $out, 'total' => $total);
    }

    public function get_entry($entry_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::entries_table() . ' WHERE id = %d', (int) $entry_id));
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row->data_json, true);
        return array(
            'id'         => (int) $row->id,
            'form_id'    => (int) $row->form_id,
            'user_id'    => (int) $row->user_id,
            'status'     => (string) $row->status,
            'created_at' => (string) $row->created_at,
            'data'       => is_array($data) ? $data : array(),
        );
    }

    public static function entry_link($form_id, $entry_id) {
        return admin_url('admin.php?page=azure-plugin-forms&tab=entries&form=' . (int) $form_id . '&entry=' . (int) $entry_id);
    }

    public function purge_old_entries() {
        global $wpdb;
        foreach ($this->get_forms() as $form) {
            $days = (int) $form['settings']['retention_days'];
            if ($days < 1) {
                continue;
            }
            $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            $deleted = $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::entries_table() . ' WHERE form_id = %d AND created_at < %s',
                $form['id'], $cutoff
            ));
            if ($deleted) {
                Azure_Logger::info(sprintf('Forms: purged %d entries older than %d days from form %d', (int) $deleted, $days, $form['id']), array('module' => 'Forms'));
            }
        }
        Azure_Forms_Registration::purge_unactivated();
    }

    /**
     * Column headers + rows for CSV, using the current schema plus any
     * answers stored under names the form no longer has.
     *
     * @param array $form
     * @param array $entries Rows from get_entries().
     * @return array<int,array>
     */
    public static function export_rows(array $form, array $entries) {
        $columns = array();
        foreach (Azure_Forms_Schema::input_fields($form['schema']) as $field) {
            $columns[$field['name']] = wp_strip_all_tags($field['label']) !== '' ? wp_strip_all_tags($field['label']) : $field['name'];
        }
        foreach ($entries as $entry) {
            foreach (array_keys($entry['data']) as $name) {
                if (!isset($columns[$name])) {
                    $columns[$name] = $name;
                }
            }
        }
        $lines = array(array_merge(array('Entry ID', 'Submitted', 'Status', 'User ID'), array_values($columns)));
        foreach ($entries as $entry) {
            $line = array(
                $entry['id'],
                get_date_from_gmt($entry['created_at'], 'Y-m-d H:i'),
                $entry['status'],
                $entry['user_id'] ?: '',
            );
            foreach (array_keys($columns) as $name) {
                $line[] = $entry['data'][$name] ?? '';
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function export_csv() {
        if (!current_user_can(self::admin_cap())) {
            wp_die(esc_html__('You do not have permission to export entries.', 'azure-plugin'), 403);
        }
        check_admin_referer('azure_forms_export');
        $form = $this->get_form(isset($_GET['form']) ? (int) $_GET['form'] : 0);
        if (!$form) {
            wp_die(esc_html__('Form not found.', 'azure-plugin'), 404);
        }
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $entries = $this->get_entries($form['id'], $status, 1, 0);
        $filename = sanitize_file_name($form['slug'] . '-entries-' . wp_date('Y-m-d') . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        foreach (self::export_rows($form, $entries['rows']) as $line) {
            echo Azure_Forms_Schema::csv_line($line);
        }
        exit;
    }

    // ─── Admin AJAX ───────────────────────────────────────────────────

    private function require_admin_ajax() {
        if (!current_user_can(self::admin_cap())) {
            wp_send_json_error(array('message' => __('You do not have permission to manage forms.', 'azure-plugin')), 403);
        }
        check_ajax_referer(self::AJAX_NONCE, 'nonce');
    }

    private static function json_param($key) {
        if (!isset($_POST[$key])) {
            return array();
        }
        $decoded = json_decode(wp_unslash((string) $_POST[$key]), true);
        return is_array($decoded) ? $decoded : array();
    }

    public function ajax_save() {
        $this->require_admin_ajax();
        $id = isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0;
        $notify_to = null;
        if (isset($_POST['notify_to']) && self::notify_available() && Azure_Order_Rules_Module::current_user_can_manage()) {
            $notify_to = (string) wp_unslash($_POST['notify_to']);
            $check = Azure_Order_Rules_Module::parse_recipients($notify_to, true);
            if (!empty($check['errors'])) {
                wp_send_json_error(array('message' => sprintf(
                    /* translators: %s: comma-separated invalid addresses. */
                    __('Not saved. These are not valid email addresses: %s', 'azure-plugin'),
                    implode(', ', $check['errors'])
                )));
            }
        }
        $result = $this->save_form($id, array(
            'title'                => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
            'slug'                 => isset($_POST['slug']) ? wp_unslash($_POST['slug']) : '',
            'status'               => isset($_POST['status']) ? sanitize_key($_POST['status']) : 'draft',
            'schema'               => self::json_param('schema'),
            'settings'             => self::json_param('settings'),
            'legacy_forminator_id' => isset($_POST['legacy_forminator_id']) ? (int) $_POST['legacy_forminator_id'] : 0,
        ));
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        $form = $this->get_form($result);
        $notify_error = '';
        if ($notify_to !== null) {
            $set = Azure_Order_Rules_Module::set_form_recipients($form['id'], $form['title'], $notify_to);
            if (is_wp_error($set)) {
                $notify_error = $set->get_error_message();
            }
        }
        wp_send_json_success(array(
            'id'        => $form['id'],
            'slug'      => $form['slug'],
            'status'    => $form['status'],
            'schema'    => $form['schema'],
            'settings'  => $form['settings'],
            'notify'    => self::notify_summary($form['id']),
            'notify_error' => $notify_error,
            'shortcode' => '[pta_form id="' . $form['id'] . '"]',
            'message'   => __('Form saved.', 'azure-plugin'),
        ));
    }

    /**
     * Form emails are sent by rules, which need WooCommerce loaded.
     */
    public static function notify_available() {
        return class_exists('Azure_Order_Rules_Module');
    }

    /**
     * @return array|null Null when rules are unavailable.
     */
    public static function notify_summary($form_id) {
        return self::notify_available() ? Azure_Order_Rules_Module::form_notify_summary($form_id) : null;
    }

    public function ajax_delete() {
        $this->require_admin_ajax();
        $this->delete_form(isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0);
        wp_send_json_success();
    }

    public function ajax_duplicate() {
        $this->require_admin_ajax();
        $form = $this->get_form(isset($_POST['form_id']) ? (int) $_POST['form_id'] : 0);
        if (!$form) {
            wp_send_json_error(array('message' => __('Form not found.', 'azure-plugin')));
        }
        $id = $this->save_form(0, array(
            'title'    => sprintf(__('%s (copy)', 'azure-plugin'), $form['title']),
            'status'   => 'draft',
            'schema'   => $form['schema'],
            'settings' => $form['settings'],
        ));
        if (is_wp_error($id)) {
            wp_send_json_error(array('message' => $id->get_error_message()));
        }
        wp_send_json_success(array('id' => $id, 'edit_url' => admin_url('admin.php?page=azure-plugin-forms&form=' . $id)));
    }

    public function ajax_preview() {
        $this->require_admin_ajax();
        $schema = Azure_Forms_Schema::sanitize_schema(self::json_param('schema'));
        $settings = self::normalize_settings(self::json_param('settings'));
        $theme_slug = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : '';
        $theme_raw = self::json_param('theme_data');
        $theme = $theme_raw
            ? Azure_Forms_Themes::normalize_theme($theme_raw, Azure_Forms_Themes::clean_slug($theme_raw['slug'] ?? 'preview') ?: 'preview')
            : Azure_Forms_Themes::get_theme($theme_slug !== '' ? $theme_slug : $settings['theme']);

        $uid = 'pta-form-preview';
        $html = '<style>' . Azure_Forms_Themes::css_for_theme($theme) . '</style>';
        $html .= '<div class="pta-form-wrap pta-form-theme-' . esc_attr($theme['slug']) . '">';
        if ($theme['header_text'] !== '') {
            $html .= '<div class="pta-form__header">' . $theme['header_text'] . '</div>';
        }
        $html .= '<form class="pta-form" onsubmit="return false;"><div class="pta-form__fields">' . Azure_Forms_Schema::render_fields($schema, $uid) . '</div>';
        $html .= '<div class="pta-form__actions"><button type="button" class="pta-form__submit">' . esc_html($settings['submit_label']) . '</button></div></form>';
        if ($theme['footer_html'] !== '') {
            $html .= '<div class="pta-form__footer">' . $theme['footer_html'] . '</div>';
        }
        $html .= '</div>';
        wp_send_json_success(array('html' => $html));
    }

    public function ajax_entry() {
        global $wpdb;
        $this->require_admin_ajax();
        $entry_id = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
        $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';
        $entry = $this->get_entry($entry_id);
        if (!$entry) {
            wp_send_json_error(array('message' => __('Entry not found.', 'azure-plugin')));
        }
        switch ($op) {
            case 'read':
                if ($entry['status'] === 'new') {
                    $wpdb->update(self::entries_table(), array('status' => 'read'), array('id' => $entry_id));
                }
                break;
            case 'spam':
                $wpdb->update(self::entries_table(), array('status' => 'spam'), array('id' => $entry_id));
                break;
            case 'not_spam':
                $wpdb->update(self::entries_table(), array('status' => 'read'), array('id' => $entry_id));
                break;
            case 'delete':
                $wpdb->delete(self::entries_table(), array('id' => $entry_id));
                break;
            default:
                wp_send_json_error(array('message' => __('Unknown action.', 'azure-plugin')));
        }
        wp_send_json_success(array('op' => $op));
    }

    public function ajax_theme_save() {
        $this->require_admin_ajax();
        $theme = self::json_param('theme');
        $slug = Azure_Forms_Themes::clean_slug($theme['slug'] ?? '');
        if ($slug === '') {
            $slug = Azure_Forms_Themes::clean_slug($theme['label'] ?? '');
        }
        if ($slug === '' || $slug === 'default') {
            wp_send_json_error(array('message' => __('Choose a theme name other than "default".', 'azure-plugin')));
        }
        $theme['slug'] = $slug;
        $saved = Azure_Forms_Themes::save_theme($theme);
        $this->purge_edge_cache();
        wp_send_json_success(array('theme' => $saved, 'themes' => Azure_Forms_Themes::get_themes()));
    }

    public function ajax_theme_delete() {
        $this->require_admin_ajax();
        Azure_Forms_Themes::delete_theme(isset($_POST['slug']) ? wp_unslash($_POST['slug']) : '');
        $this->purge_edge_cache();
        wp_send_json_success(array('themes' => Azure_Forms_Themes::get_themes()));
    }

    public function ajax_theme_copy() {
        $this->require_admin_ajax();
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        if (!class_exists('Azure_UpNext_Themes')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-upnext-themes.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        $upnext = class_exists('Azure_UpNext_Themes') ? Azure_UpNext_Themes::get_theme($source) : null;
        if (!$upnext) {
            wp_send_json_error(array('message' => __('That up-next theme was not found.', 'azure-plugin')));
        }
        $slug = Azure_Forms_Themes::clean_slug($upnext['slug']);
        if ($slug === 'default') {
            $slug = 'upnext-default';
        }
        $theme = Azure_Forms_Themes::from_upnext($upnext, $slug);
        $saved = Azure_Forms_Themes::save_theme($theme);
        wp_send_json_success(array('theme' => $saved, 'themes' => Azure_Forms_Themes::get_themes()));
    }

    // ─── Admin assets ─────────────────────────────────────────────────

    public function enqueue_admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'azure-plugin-forms') {
            return;
        }
        wp_enqueue_style('pta-forms', AZURE_PLUGIN_URL . 'css/pta-forms.css', array(), AZURE_PLUGIN_VERSION);
        wp_enqueue_style('azure-forms-builder', AZURE_PLUGIN_URL . 'css/forms-builder.css', array('pta-forms'), AZURE_PLUGIN_VERSION);
        wp_enqueue_script('azure-forms-builder', AZURE_PLUGIN_URL . 'js/forms-builder.js', array('jquery', 'jquery-ui-sortable', 'jquery-ui-draggable'), AZURE_PLUGIN_VERSION, true);

        $types = array();
        foreach (Azure_Forms_Schema::types() as $key => $type) {
            $types[$key] = array(
                'label'   => $type['label'],
                'input'   => !empty($type['input']),
                'options' => !empty($type['options']),
                'prefill' => !empty($type['prefill']),
                'dynamic' => !empty($type['dynamic']),
                'group'   => !empty($type['group']),
                'max'     => $type['max'] ?? 0,
            );
        }
        $profile_targets = array();
        foreach (Azure_Forms_Schema::profile_targets() as $key => $target) {
            $profile_targets[$key] = array('label' => $target['label'], 'types' => $target['types']);
        }
        $upnext = array();
        if (!class_exists('Azure_UpNext_Themes') && file_exists(AZURE_PLUGIN_PATH . 'includes/class-upnext-themes.php')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-upnext-themes.php';
        }
        if (class_exists('Azure_UpNext_Themes')) {
            foreach (Azure_UpNext_Themes::get_themes() as $t) {
                $upnext[] = array('slug' => $t['slug'], 'label' => $t['label']);
            }
        }
        wp_localize_script('azure-forms-builder', 'azureForms', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::AJAX_NONCE),
            'types'        => $types,
            'profileTargets' => $profile_targets,
            'registrationRequires' => Azure_Forms_Registration::REQUIRED_TARGETS,
            'themes'       => Azure_Forms_Themes::get_themes(),
            'upnextThemes' => $upnext,
            'listUrl'      => admin_url('admin.php?page=azure-plugin-forms'),
            'turnstileReady' => self::turnstile_keys()['site_key'] !== '' && self::turnstile_keys()['secret_key'] !== '',
            'strings'      => array(
                'saved'         => __('Saved.', 'azure-plugin'),
                'saving'        => __('Saving…', 'azure-plugin'),
                'error'         => __('Could not save. Please try again.', 'azure-plugin'),
                'confirmDelete' => __('Delete this form and all of its entries? This cannot be undone.', 'azure-plugin'),
                'confirmEntry'  => __('Delete this entry? This cannot be undone.', 'azure-plugin'),
                'confirmField'  => __('Remove this field?', 'azure-plugin'),
                'confirmTheme'  => __('Delete this theme? Forms using it will fall back to Default.', 'azure-plugin'),
                'copied'        => __('Copied', 'azure-plugin'),
                'unsaved'       => __('You have unsaved changes.', 'azure-plugin'),
                'noFields'      => __('Add fields from the palette on the left.', 'azure-plugin'),
                'nameTaken'     => __('Another field already uses this name.', 'azure-plugin'),
            ),
        ));
    }
}
