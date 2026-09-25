<?php
/**
 * Home Screen module.
 *
 * The pin popup is shown to every phone. Push notifications are a separate
 * list: a grade or teacher send only includes parents who allowed
 * notifications while signed in and who have a matching child.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Home_Screen {

    const MODULE = 'home_screen';
    const SHOW_PIN = 'home_screen_show_pin';
    const VAPID_PUBLIC = 'pta_push_vapid_public';
    const VAPID_PRIVATE = 'pta_push_vapid_private';
    const CRON = 'pta_home_screen_send_due';
    const NONCE = 'pta_home_screen';
    const BATCH = 40;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'), 20);
        add_action('wp_ajax_pta_home_screen_subscribe', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_nopriv_pta_home_screen_subscribe', array($this, 'ajax_subscribe'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::CRON, array($this, 'process_due'));
        $this->register_frontend();
        $this->ensure_cron();
    }

    public static function is_enabled() {
        return class_exists('Azure_Settings') && Azure_Settings::is_module_enabled(self::MODULE);
    }

    public static function show_pin_instructions() {
        if (!self::is_enabled() || !class_exists('Azure_Settings')) {
            return false;
        }
        return (bool) Azure_Settings::get_setting(self::SHOW_PIN, true);
    }

    public function admin_menu() {
        add_submenu_page(
            'azure-plugin',
            'PTA Tools - Home Screen',
            'Home Screen',
            'manage_options',
            'azure-plugin-home-screen',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enable switch used on a module's own screen.
     *
     * @param string $module
     * @param string $blurb
     */
    public static function render_module_switch($module, $blurb) {
        if (!class_exists('Azure_Settings')) {
            return;
        }
        $enabled = Azure_Settings::is_module_enabled($module);
        $name = ucwords(str_replace('_', ' ', $module));
        ?>
        <div class="module-toggle-card pta-module-switch" style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0 16px;padding:14px 16px;background:#fff;border:1px solid #c3c4c7;">
            <div class="module-info">
                <strong><?php echo esc_html($name); ?></strong>
                <?php if ($blurb !== ''): ?>
                    <p style="margin:4px 0 0;"><?php echo esc_html($blurb); ?></p>
                <?php endif; ?>
            </div>
            <div class="module-control" style="display:flex;align-items:center;gap:10px;">
                <label class="switch">
                    <input type="checkbox" class="module-toggle" data-module="<?php echo esc_attr($module); ?>" <?php checked($enabled); ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php echo $enabled ? esc_html__('Enabled', 'azure-plugin') : esc_html__('Disabled', 'azure-plugin'); ?></span>
            </div>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $this->ensure_tables();
        $notice = $this->handle_admin_post();
        $editing = null;
        $edit_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && $edit_id) {
            $editing = $this->get_notification($edit_id);
        }
        $notifications = $this->list_notifications();
        $counts = $this->subscription_counts();
        $choices = $this->audience_choices();
        $show_pin = self::show_pin_instructions();
        include AZURE_PLUGIN_PATH . 'admin/home-screen-page.php';
    }

    public function register_frontend() {
        if (!self::is_enabled()) {
            return;
        }
        if (isset($_GET['pta_push_sw'])) {
            $this->serve_service_worker();
        }
        if (isset($_GET['pta_webmanifest'])) {
            $this->serve_manifest();
        }
        if (isset($_GET['pta_pwa_icon'])) {
            $this->serve_icon();
        }
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend'));
        add_action('wp_head', array($this, 'head_tags'), 2);
        add_action('wp_footer', array($this, 'footer_ui'), 20);
    }

    public function head_tags() {
        $icon = esc_url(home_url('/?pta_pwa_icon=192'));
        echo '<link rel="manifest" href="' . esc_url(home_url('/?pta_webmanifest=1')) . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . $icon . '">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="theme-color" content="#0b2545">' . "\n";
    }

    public function enqueue_frontend() {
        $keys = $this->vapid_keys();
        wp_enqueue_style('pta-home-screen', AZURE_PLUGIN_URL . 'css/home-screen.css', array(), AZURE_PLUGIN_VERSION);
        wp_enqueue_script('pta-home-screen', AZURE_PLUGIN_URL . 'js/home-screen.js', array(), AZURE_PLUGIN_VERSION, true);
        wp_localize_script('pta-home-screen', 'ptaHomeScreen', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'showPin' => self::show_pin_instructions() ? 1 : 0,
            'sw' => home_url('/?pta_push_sw=1'),
            'vapid' => $keys ? $keys['public'] : '',
            'icon' => home_url('/?pta_pwa_icon=192'),
        ));
    }

    public function footer_ui() {
        if (is_admin()) {
            return;
        }
        ?>
        <div id="pta-pin-sheet" class="pta-pin-sheet" hidden>
            <div class="pta-pin-sheet__card">
                <button type="button" class="pta-pin-sheet__close" aria-label="<?php esc_attr_e('Close', 'azure-plugin'); ?>">&times;</button>
                <p class="pta-pin-sheet__title"><?php esc_html_e('Add Wilder PTSA to your home screen', 'azure-plugin'); ?></p>
                <p class="pta-pin-sheet__ios"><?php esc_html_e('Tap the Share button, then Add to Home Screen.', 'azure-plugin'); ?></p>
                <p class="pta-pin-sheet__android"><?php esc_html_e('Tap the browser menu, then Add to Home screen or Install app.', 'azure-plugin'); ?></p>
            </div>
        </div>
        <div id="pta-push-allow" class="pta-pin-sheet" hidden>
            <div class="pta-pin-sheet__card">
                <p class="pta-pin-sheet__title"><?php esc_html_e('Turn on notifications', 'azure-plugin'); ?></p>
                <p><?php esc_html_e('Get Wilder PTSA alerts on this home screen icon.', 'azure-plugin'); ?></p>
                <button type="button" class="pta-pin-sheet__allow"><?php esc_html_e('Allow notifications', 'azure-plugin'); ?></button>
            </div>
        </div>
        <?php
    }

    public function ajax_subscribe() {
        if (!self::is_enabled()) {
            wp_send_json_error('disabled', 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
        $endpoint = isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint'])) : '';
        $p256dh = isset($_POST['p256dh']) ? sanitize_text_field(wp_unslash($_POST['p256dh'])) : '';
        $auth = isset($_POST['auth']) ? sanitize_text_field(wp_unslash($_POST['auth'])) : '';
        if ($endpoint === '' || !preg_match('#^https://#', $endpoint) || $p256dh === '' || $auth === '') {
            wp_send_json_error('invalid', 400);
        }
        $this->ensure_tables();
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_subscriptions';
        $hash = hash('sha256', $endpoint);
        $user_id = get_current_user_id();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE endpoint_hash = %s", $hash));
        $row = array(
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth_secret' => $auth,
            'user_id' => (int) $user_id,
        );
        if ($existing) {
            $wpdb->update($table, $row, array('id' => (int) $existing));
        } else {
            $row['endpoint_hash'] = $hash;
            $wpdb->insert($table, $row);
        }
        wp_send_json_success(array('user' => (int) $user_id));
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['pta_five_minutes'])) {
            $schedules['pta_five_minutes'] = array(
                'interval' => 300,
                'display' => 'Every five minutes',
            );
        }
        return $schedules;
    }

    public function ensure_cron() {
        if (!self::is_enabled() || wp_next_scheduled(self::CRON)) {
            return;
        }
        wp_schedule_event(time() + 60, 'pta_five_minutes', self::CRON);
    }

    public function process_due() {
        if (!self::is_enabled()) {
            return;
        }
        $this->ensure_tables();
        if (get_transient('pta_push_sending')) {
            return;
        }
        set_transient('pta_push_sending', 1, 120);
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_notifications';
        $now = current_time('mysql', true);
        $due = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'scheduled' AND send_at IS NOT NULL AND send_at <= %s ORDER BY send_at ASC, id ASC LIMIT 5",
            $now
        ));
        foreach ($due as $note) {
            $wpdb->update($table, array(
                'status' => 'sending',
                'last_subscription_id' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
            ), array('id' => (int) $note->id));
            $note->status = 'sending';
            $note->last_subscription_id = 0;
            $note->sent_count = 0;
            $note->failed_count = 0;
        }
        $sending = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'sending' ORDER BY id ASC LIMIT 3");
        foreach ($sending as $note) {
            $this->send_batch($note);
        }
        delete_transient('pta_push_sending');
    }

    /**
     * @param array<int,array{grade:string,teacher:string}> $children
     * @param string $audience
     * @param string[] $values
     */
    public static function family_matches(array $children, $audience, array $values) {
        if ($audience === 'all' || $audience === '') {
            return true;
        }
        foreach ($children as $child) {
            $grade = isset($child['grade']) ? (string) $child['grade'] : '';
            $teacher = isset($child['teacher']) ? (string) $child['teacher'] : '';
            if (self::child_matches($grade, $teacher, $audience, $values)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A signed-out device can receive an everyone notice only.
     *
     * @param int $user_id
     * @param array<int,array{grade:string,teacher:string}> $children
     * @param string $audience
     * @param string[] $values
     */
    public static function subscription_matches($user_id, array $children, $audience, array $values) {
        if ($audience === 'all' || $audience === '') {
            return true;
        }
        if (!(int) $user_id) {
            return false;
        }
        return self::family_matches($children, $audience, $values);
    }

    /**
     * @param string $grade
     * @param string $teacher
     * @param string $audience
     * @param string[] $values
     */
    public static function child_matches($grade, $teacher, $audience, array $values) {
        $wanted = array();
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $wanted[$value] = $value;
            }
        }
        if (!$wanted) {
            return false;
        }
        if ($audience === 'teacher') {
            return isset($wanted[strtolower(trim($teacher))]);
        }
        if ($audience === 'grade') {
            return self::grade_matches($grade, array_values($wanted));
        }
        return false;
    }

    /**
     * @param string $child_grade
     * @param string[] $selected Lowercase grade strings. A mixed roster grade
     *                           such as 4/5 also matches a child stored as 4 or 5.
     */
    public static function grade_matches($child_grade, array $selected) {
        $child = strtolower(trim($child_grade));
        if ($child === '') {
            return false;
        }
        $child_parts = preg_split('#\s*/\s*#', $child);
        foreach ($selected as $sel) {
            $sel = strtolower(trim($sel));
            if ($sel === $child) {
                return true;
            }
            $sel_parts = preg_split('#\s*/\s*#', $sel);
            if (count($child_parts) === 1 && in_array($child_parts[0], $sel_parts, true)) {
                return true;
            }
            if (count($sel_parts) === 1 && in_array($sel_parts[0], $child_parts, true)) {
                return true;
            }
        }
        return false;
    }

    private function send_batch($note) {
        global $wpdb;
        $notes = $wpdb->prefix . 'azure_push_notifications';
        $subs = $wpdb->prefix . 'azure_push_subscriptions';
        $keys = $this->vapid_keys();
        if (!$keys) {
            $wpdb->update($notes, array('status' => 'failed'), array('id' => (int) $note->id));
            return;
        }
        $values = json_decode((string) $note->audience_values, true);
        if (!is_array($values)) {
            $values = array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$subs} WHERE id > %d ORDER BY id ASC LIMIT %d",
            (int) $note->last_subscription_id,
            self::BATCH
        ));
        $sent = (int) $note->sent_count;
        $failed = (int) $note->failed_count;
        $last = (int) $note->last_subscription_id;
        $subject = 'mailto:' . (string) get_option('admin_email');
        $icon = home_url('/?pta_pwa_icon=192');
        $payload = wp_json_encode(array(
            'title' => (string) $note->title,
            'body' => (string) $note->body,
            'url' => $note->link_url !== '' ? (string) $note->link_url : home_url('/'),
            'icon' => $icon,
        ));
        foreach ($rows as $row) {
            $last = (int) $row->id;
            $children = $this->children_for_push_user((int) $row->user_id);
            if (!self::subscription_matches((int) $row->user_id, $children, $note->audience, $values)) {
                continue;
            }
            $result = Azure_Web_Push::send(
                $row->endpoint,
                $payload,
                $row->p256dh,
                $row->auth_secret,
                $keys['public'],
                $keys['private'],
                $subject
            );
            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
                if ($result['code'] === 404 || $result['code'] === 410) {
                    $wpdb->delete($subs, array('id' => (int) $row->id));
                }
            }
        }
        $done = count($rows) < self::BATCH;
        $wpdb->update($notes, array(
            'sent_count' => $sent,
            'failed_count' => $failed,
            'last_subscription_id' => $last,
            'status' => $done ? 'sent' : 'sending',
        ), array('id' => (int) $note->id));
    }

    /**
     * @param int $user_id
     * @return array<int,array{grade:string,teacher:string}>
     */
    private function children_for_push_user($user_id) {
        if (!$user_id || !class_exists('Azure_User_Children')) {
            return array();
        }
        $out = array();
        foreach (Azure_User_Children::get_children_for_user($user_id) as $child) {
            $meta = Azure_User_Children::get_child_meta($child->id);
            $out[] = array(
                'grade' => Azure_User_Children::grade_from_meta($meta),
                'teacher' => Azure_User_Children::teacher_from_meta($meta),
            );
        }
        return $out;
    }

    private function handle_admin_post() {
        if (empty($_POST['pta_home_screen_action'])) {
            return '';
        }
        check_admin_referer(self::NONCE);
        $action = sanitize_key(wp_unslash($_POST['pta_home_screen_action']));
        if ($action === 'pin') {
            $on = !empty($_POST['show_pin']);
            Azure_Settings::update_setting(self::SHOW_PIN, $on);
            return $on ? __('Pinning instructions are on.', 'azure-plugin') : __('Pinning instructions are off.', 'azure-plugin');
        }
        if ($action === 'delete') {
            $this->delete_notification((int) $_POST['notification_id']);
            return __('Notification deleted.', 'azure-plugin');
        }
        if ($action === 'save') {
            $id = $this->save_notification_from_post();
            if (!empty($_POST['send_now']) && $id) {
                $this->mark_due_now($id);
                $this->process_due();
                return __('Notification is sending.', 'azure-plugin');
            }
            return __('Notification saved.', 'azure-plugin');
        }
        return '';
    }

    private function save_notification_from_post() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_notifications';
        $audience = isset($_POST['audience']) ? sanitize_key(wp_unslash($_POST['audience'])) : 'all';
        if (!in_array($audience, array('all', 'grade', 'teacher'), true)) {
            $audience = 'all';
        }
        $values = array();
        if ($audience !== 'all' && isset($_POST['audience_values']) && is_array($_POST['audience_values'])) {
            $choices = $this->audience_choices();
            $allowed = $audience === 'grade' ? $choices[0] : $choices[1];
            foreach ($_POST['audience_values'] as $value) {
                $value = sanitize_text_field(wp_unslash($value));
                if ($value !== '' && in_array($value, $allowed, true)) {
                    $values[] = $value;
                }
            }
        }
        $link = isset($_POST['link_url']) ? esc_url_raw(wp_unslash($_POST['link_url'])) : '';
        $send_local = isset($_POST['send_at']) ? sanitize_text_field(wp_unslash($_POST['send_at'])) : '';
        $send_at = $this->local_to_gmt($send_local);
        $schedule = !empty($_POST['schedule']);
        $status = 'draft';
        if ($schedule && $send_at) {
            $status = 'scheduled';
        }
        if (!empty($_POST['send_now'])) {
            $status = 'scheduled';
            $send_at = current_time('mysql', true);
        }
        $row = array(
            'title' => substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 120),
            'body' => substr(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')), 0, 500),
            'link_url' => substr($link, 0, 500),
            'audience' => $audience,
            'audience_values' => wp_json_encode(array_values($values)),
            'send_at' => $send_at ? $send_at : null,
            'status' => $status,
        );
        $id = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;
        $existing = $id ? $this->get_notification($id) : null;
        if ($existing && in_array($existing->status, array('draft', 'scheduled'), true)) {
            $wpdb->update($table, $row, array('id' => $id));
            return $id;
        }
        $wpdb->insert($table, $row);
        return (int) $wpdb->insert_id;
    }

    private function mark_due_now($id) {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'azure_push_notifications', array(
            'status' => 'scheduled',
            'send_at' => current_time('mysql', true),
            'last_subscription_id' => 0,
        ), array('id' => (int) $id));
    }

    private function delete_notification($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'azure_push_notifications', array('id' => (int) $id));
    }

    private function get_notification($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}azure_push_notifications WHERE id = %d",
            (int) $id
        ));
    }

    private function list_notifications() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_notifications';
        if (!$this->tables_ready()) {
            return array();
        }
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100");
    }

    private function subscription_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_subscriptions';
        if (!$this->tables_ready()) {
            return array('total' => 0, 'signed_in' => 0);
        }
        return array(
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'signed_in' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE user_id > 0"),
        );
    }

    /**
     * @return array{0:string[],1:string[]}
     */
    private function audience_choices() {
        $grades = array();
        $teachers = array();
        $roster = array();
        if (!class_exists('Azure_Class_Competitions') && defined('AZURE_PLUGIN_PATH')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-class-competitions.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('Azure_Class_Competitions')) {
            $roster = Azure_Class_Competitions::get_roster();
        }
        foreach ($roster as $row) {
            if (!empty($row['grade'])) {
                $grades[$row['grade']] = $row['grade'];
            }
            if (!empty($row['name'])) {
                $teachers[$row['name']] = $row['name'];
            }
        }
        return array(array_values($grades), array_values($teachers));
    }

    private function local_to_gmt($local) {
        $local = trim((string) $local);
        if ($local === '') {
            return '';
        }
        $local = str_replace('T', ' ', $local);
        if (strlen($local) === 16) {
            $local .= ':00';
        }
        try {
            $dt = new DateTimeImmutable($local, wp_timezone());
        } catch (Exception $e) {
            return '';
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function vapid_keys() {
        $public = get_option(self::VAPID_PUBLIC, '');
        $private = get_option(self::VAPID_PRIVATE, '');
        if ($public && $private) {
            return array('public' => $public, 'private' => $private);
        }
        $keys = Azure_Web_Push::generate_vapid_keys();
        if (!$keys) {
            return null;
        }
        update_option(self::VAPID_PUBLIC, $keys['public'], false);
        update_option(self::VAPID_PRIVATE, $keys['private'], false);
        return $keys;
    }

    private function ensure_tables() {
        if ($this->tables_ready() || !class_exists('Azure_Database')) {
            return;
        }
        Azure_Database::create_tables();
    }

    private function tables_ready() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_push_subscriptions';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function serve_service_worker() {
        $path = AZURE_PLUGIN_PATH . 'assets/pta-push-sw.js';
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-store');
        if (file_exists($path)) {
            readfile($path);
        }
        exit;
    }

    private function serve_manifest() {
        $icon192 = home_url('/?pta_pwa_icon=192');
        $icon512 = home_url('/?pta_pwa_icon=512');
        $name = get_bloginfo('name');
        if ($name === '') {
            $name = 'Wilder PTSA';
        }
        $manifest = array(
            'name' => $name,
            'short_name' => $name,
            'start_url' => home_url('/'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0b2545',
            'icons' => array(
                array('src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
            ),
        );
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo wp_json_encode($manifest);
        exit;
    }

    private function serve_icon() {
        $size = isset($_GET['pta_pwa_icon']) ? (int) $_GET['pta_pwa_icon'] : 192;
        if ($size !== 512) {
            $size = 192;
        }
        $file = AZURE_PLUGIN_PATH . 'assets/pta-icon-' . $size . '.png';
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        if (file_exists($file)) {
            readfile($file);
        }
        exit;
    }
}
