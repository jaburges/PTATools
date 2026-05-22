<?php
/**
 * Parent Migration & Onboarding
 *
 * Centralizes the import/migration paths that bring outside contacts into
 * the WP `parent` role with a magic-link activation flow:
 *
 *   1. AcyMailing import — read every row from wp_acym_user, dedupe against
 *      existing WP users, and bucket per the user-management consolidation
 *      design (§4.3):
 *        - existing WP user (any role)        → no change (only join the
 *                                                Parents newsletter list)
 *        - email matches the SSO org_domain   → create user with the
 *                                                SSO-configured role
 *                                                (Azure_SSO_Sync::resolve_*),
 *                                                login disabled (SSO does it)
 *        - email matches the school_staff_domain → newsletter list only,
 *                                                  no WP user created
 *        - everyone else                      → create WP user with the
 *                                                Parent role + login
 *                                                disabled + activation token
 *
 *   2. Single-user provision (test path) — same bucketing as above for one
 *      email + name + optional phone + child, used to send the "test me
 *      first" welcome email before the bulk blast.
 *
 *   3. Welcome-email trigger — given a list of parent user_ids (or a role
 *      slug), issue activation tokens, render the welcome template, and
 *      hand the resulting payload to the chosen sender.
 *
 * Resource policy:
 *   - Class is admin-only — registered in init_product_fields_components()
 *     under the existing $ctx['is_admin'] gate. Front-end pageloads pay 0.
 *   - No SHOW TABLES on every request. AcyMailing presence is cached per
 *     request in a static and short-lived (5-min) transient.
 *   - Bulk operations run in batches via AJAX; the client polls until done.
 *   - All code paths fall through to wp_send_json_* and return cleanly so
 *     a partial failure never holds an HTTP request open.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Parent_Role')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
}
if (!class_exists('Azure_Parent_Activation')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php';
}

class Azure_Parent_Migration {

    const NONCE_ACTION    = 'azure_parent_migration';
    const BATCH_SIZE      = 100;
    /**
     * Default school-staff domain when the admin hasn't configured one in
     * `pta_school_staff_domain`. Empty by default so a fresh install never
     * accidentally buckets random emails into a school-staff list. Each
     * tenant configures the value that matches their school district
     * (e.g. their school-district top-level domain).
     */
    const SCHOOL_DOMAIN_DEFAULT = '';

    const SOURCE_ACYMAILING = 'acymailing';
    const SOURCE_CSV        = 'csv';
    const SOURCE_TEST       = 'test';
    const SOURCE_MANUAL     = 'manual';

    const RESULT_CREATED        = 'created';
    const RESULT_SKIPPED_EXISTS = 'skipped_existing_user';
    const RESULT_SKIPPED_INVALID = 'skipped_invalid';
    const RESULT_SKIPPED_SCHOOL = 'school_staff_only';
    const RESULT_SKIPPED_SSO    = 'sso_user_created';
    const RESULT_ERROR          = 'error';

    private static $instance       = null;
    private static $acy_present    = null; // request-scoped cache for table existence

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX endpoints. All gated by NONCE_ACTION + manage_options.
        add_action('wp_ajax_azure_pm_acy_preview',  array($this, 'ajax_acy_preview'));
        add_action('wp_ajax_azure_pm_acy_run',      array($this, 'ajax_acy_run'));
        add_action('wp_ajax_azure_pm_test_user',    array($this, 'ajax_test_user'));
        add_action('wp_ajax_azure_pm_send_welcome', array($this, 'ajax_send_welcome'));
        add_action('wp_ajax_azure_pm_welcome_preview', array($this, 'ajax_welcome_preview'));

        // Admin sub-page under User Management.
        add_action('admin_menu', array($this, 'register_admin_page'), 30);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Admin menu
    // ─────────────────────────────────────────────────────────────────

    public function register_admin_page() {
        add_submenu_page(
            'azure-plugin-user-management',
            __('Parent Migration & Welcome', 'azure-plugin'),
            __('Parent Migration', 'azure-plugin'),
            'manage_options',
            'azure-plugin-parent-migration',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'azure-plugin'));
        }
        $page_path = AZURE_PLUGIN_PATH . 'admin/parent-migration-page.php';
        if (file_exists($page_path)) {
            include $page_path;
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Parent Migration', 'azure-plugin') . '</h1>';
        echo '<p>' . esc_html__('Admin page template missing.', 'azure-plugin') . '</p></div>';
    }

    // ─────────────────────────────────────────────────────────────────
    //  Configuration helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns the lower-cased SSO org_domain (e.g. "{org}.net") if
     * configured, else empty string.
     */
    public static function get_sso_org_domain() {
        if (!class_exists('Azure_Settings')) {
            return '';
        }
        $d = Azure_Settings::get_setting('org_domain', '');
        return strtolower(trim((string) $d));
    }

    /**
     * Returns the lower-cased school staff domain. Stored as plugin option
     * `pta_school_staff_domain`. Empty by default; each tenant configures
     * the domain that matches their school district.
     */
    public static function get_school_staff_domain() {
        $d = get_option('pta_school_staff_domain', self::SCHOOL_DOMAIN_DEFAULT);
        return strtolower(trim((string) $d));
    }

    /**
     * Resolve the WP role slug used by SSO sign-ins for the current site.
     * Delegates to Azure_SSO_Sync::resolve_configured_role_slug() so the
     * @org_domain bucket lands in the same role SSO would assign on first
     * Azure-AD sign-in.
     */
    public static function get_sso_role_slug() {
        if (!class_exists('Azure_SSO_Sync')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-sso-sync.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('Azure_SSO_Sync')) {
            return Azure_SSO_Sync::resolve_configured_role_slug();
        }
        return 'azuread';
    }

    // ─────────────────────────────────────────────────────────────────
    //  AcyMailing detection + read
    // ─────────────────────────────────────────────────────────────────

    /**
     * Return true if wp_acym_user exists. Cached per-request in a static
     * and across requests in a short transient so we never run SHOW TABLES
     * on a hot path.
     */
    public static function acymailing_present() {
        if (self::$acy_present !== null) {
            return self::$acy_present;
        }
        $cached = get_transient('pta_acymailing_present');
        if ($cached !== false) {
            self::$acy_present = (bool) (int) $cached;
            return self::$acy_present;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'acym_user';
        $present = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        self::$acy_present = $present;
        set_transient('pta_acymailing_present', $present ? 1 : 0, 5 * MINUTE_IN_SECONDS);
        return $present;
    }

    /**
     * Pull every AcyMailing subscriber row. Single SELECT, ordered by id,
     * unbuffered iteration is unnecessary because the table is small (~720).
     */
    public static function load_acymailing_rows() {
        if (!self::acymailing_present()) {
            return array();
        }
        global $wpdb;
        $table = $wpdb->prefix . 'acym_user';
        $rows = $wpdb->get_results(
            "SELECT id, email, name, active, confirmed, creation_date
             FROM {$table}
             WHERE email IS NOT NULL AND email <> ''
             ORDER BY id ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Bucketing (§4.3 of the design doc)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Decide what to do with a single (email, name) row coming from any
     * source. Pure function — no DB writes; returns a plan object the
     * caller commits.
     *
     * @param string $email
     * @param string $name  Display name (may be empty)
     * @param string $source One of SOURCE_*
     * @return array {
     *   action:  'create_parent' | 'attach_existing' | 'skip_school_staff' | 'skip_sso_domain' | 'skip',
     *   email:   normalized lower-cased email,
     *   name:    sanitized display name,
     *   reason:  human-readable string for the audit log,
     *   existing_user_id: int|null,
     * }
     *
     * Bucketing rules:
     *  - school_staff domain → skip_school_staff (admin imports manually
     *                          to the `school_staff` WP role from a
     *                          separate CSV export).
     *  - org_domain          → skip_sso_domain (SSO will create the user
     *                          with the configured role on first sign-in;
     *                          pre-creating from email risks the wrong
     *                          role).
     *  - existing WP user    → attach_existing (merge `parent` role onto
     *                          whatever roles they already have).
     *  - everything else     → create_parent (new parent + magic-link
     *                          activation token).
     */
    public static function plan_row($email, $name = '', $source = self::SOURCE_MANUAL) {
        $plan = array(
            'action'           => 'skip',
            'email'            => '',
            'name'             => '',
            'reason'           => '',
            'existing_user_id' => null,
        );

        $email = strtolower(trim((string) $email));
        if ($email === '' || !is_email($email)) {
            $plan['reason'] = 'Invalid email';
            return $plan;
        }
        $plan['email'] = $email;
        $plan['name']  = sanitize_text_field((string) $name);

        $existing = get_user_by('email', $email);
        if ($existing) {
            $plan['existing_user_id'] = (int) $existing->ID;
            $plan['action'] = 'attach_existing';
            $plan['reason'] = 'Existing WP user (id ' . $existing->ID . ', roles: ' . implode(',', (array) $existing->roles) . ')';
            return $plan;
        }

        $domain = substr($email, strrpos($email, '@') + 1);
        $school = self::get_school_staff_domain();
        $sso    = self::get_sso_org_domain();

        if ($school && $domain === $school) {
            $plan['action'] = 'skip_school_staff';
            $plan['reason'] = 'School staff (' . $domain . ') — admin imports separately into school_staff role';
            return $plan;
        }

        if ($sso && $domain === $sso) {
            $plan['action'] = 'skip_sso_domain';
            $plan['reason'] = 'PTSA org (' . $domain . ') — defer to Microsoft SSO (creates user on first sign-in)';
            return $plan;
        }

        $plan['action'] = 'create_parent';
        $plan['reason'] = 'New parent';
        return $plan;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Account creation primitives
    // ─────────────────────────────────────────────────────────────────

    /**
     * Create a new WP user as a `parent` (login-disabled, force-password,
     * activation token issued). Returns the new user_id or WP_Error.
     */
    public static function create_parent_user($email, $display_name = '', $source = self::SOURCE_MANUAL, $extra_meta = array()) {
        $email = strtolower(trim((string) $email));
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Invalid email address', 'azure-plugin'));
        }

        $existing = get_user_by('email', $email);
        if ($existing) {
            return new WP_Error('user_exists', __('User already exists', 'azure-plugin'), array('user_id' => $existing->ID));
        }

        $username = self::derive_username_from_email($email);
        $random_password = wp_generate_password(32, true, true);

        $user_id = wp_insert_user(array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $random_password,
            'display_name' => $display_name !== '' ? $display_name : $username,
            'role'         => Azure_Parent_Role::ROLE_SLUG,
        ));

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
        update_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
        update_user_meta($user_id, Azure_Parent_Activation::META_IMPORTED_AT, current_time('mysql', true));
        update_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, sanitize_key($source));

        // Capture display-name fragments + any extra meta the caller wants
        // attached (phone, source-specific fields, etc.).
        if ($display_name !== '') {
            $parts = preg_split('/\s+/', trim($display_name), 2);
            if (!empty($parts[0])) {
                update_user_meta($user_id, 'first_name', $parts[0]);
            }
            if (!empty($parts[1])) {
                update_user_meta($user_id, 'last_name', $parts[1]);
            }
        }
        if (is_array($extra_meta)) {
            foreach ($extra_meta as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                update_user_meta($user_id, $k, is_string($v) ? sanitize_text_field($v) : $v);
            }
        }

        // Issue the activation token now so the welcome-email job has it.
        Azure_Parent_Activation::issue_token($user_id);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'Parent user created: user_id=%d email=%s source=%s',
                $user_id,
                $email,
                $source
            ), array('module' => 'ParentMigration'));
        }

        return (int) $user_id;
    }

    /**
     * Create a WP user destined to sign in via Azure AD SSO. Same
     * shape as create_parent_user but uses the SSO-configured role and
     * leaves activation token + force-password unset (SSO will own auth).
     */
    public static function create_sso_user($email, $display_name = '', $source = self::SOURCE_MANUAL, $extra_meta = array()) {
        $email = strtolower(trim((string) $email));
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Invalid email address', 'azure-plugin'));
        }
        $existing = get_user_by('email', $email);
        if ($existing) {
            return new WP_Error('user_exists', __('User already exists', 'azure-plugin'), array('user_id' => $existing->ID));
        }
        $role = self::get_sso_role_slug();
        $username = self::derive_username_from_email($email);
        $user_id = wp_insert_user(array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(32, true, true),
            'display_name' => $display_name !== '' ? $display_name : $username,
            'role'         => $role,
        ));
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
        update_user_meta($user_id, Azure_Parent_Activation::META_IMPORTED_AT, current_time('mysql', true));
        update_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, sanitize_key($source));
        if ($display_name !== '') {
            $parts = preg_split('/\s+/', trim($display_name), 2);
            if (!empty($parts[0])) {
                update_user_meta($user_id, 'first_name', $parts[0]);
            }
            if (!empty($parts[1])) {
                update_user_meta($user_id, 'last_name', $parts[1]);
            }
        }
        if (is_array($extra_meta)) {
            foreach ($extra_meta as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                update_user_meta($user_id, $k, is_string($v) ? sanitize_text_field($v) : $v);
            }
        }
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'SSO user pre-provisioned: user_id=%d email=%s role=%s source=%s',
                $user_id,
                $email,
                $role,
                $source
            ), array('module' => 'ParentMigration'));
        }
        return (int) $user_id;
    }

    /**
     * Attach an existing WP user to the parent population. Behavior
     * depends on what roles they already carry:
     *
     *   - administrator / editor / shop_manager / the SSO role
     *       → leave alone (stronger role wins, no parent role added)
     *   - subscriber (only)
     *       → remove subscriber, add parent (matches the existing
     *         Azure_Parent_Role_Admin bulk migrator behavior — the two
     *         roles are functionally identical so we don't want both)
     *   - customer (with or without subscriber)
     *       → keep customer, add parent. Subscriber is also removed if
     *         present. Customer is preserved so WooCommerce purchase
     *         history and billing/shipping meta stay intact — the
     *         parent role is purely additive for newsletter access +
     *         the family/children admin tools.
     *   - any other role
     *       → add parent additively
     *
     * Never disables login (this method is for users that already had
     * working accounts before we touched them).
     */
    public static function attach_existing_to_parent($user_id) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return false;
        }
        $roles = (array) $user->roles;
        $sso_role = self::get_sso_role_slug();
        $strong = array('administrator', 'editor', 'shop_manager', $sso_role);
        foreach ($strong as $r) {
            if ($r && in_array($r, $roles, true)) {
                return true;
            }
        }
        // Add parent if missing, then remove subscriber (the two are
        // functionally identical — keeping both clutters the role list
        // and breaks count_users() heuristics).
        if (!in_array(Azure_Parent_Role::ROLE_SLUG, $roles, true)) {
            $user->add_role(Azure_Parent_Role::ROLE_SLUG);
        }
        if (in_array('subscriber', $roles, true)) {
            $user->remove_role('subscriber');
        }
        return true;
    }

    /**
     * Generate a unique username from an email's local part. Falls back
     * to numeric suffixes on collision (mirrors Azure_SSO_Sync's helper).
     */
    private static function derive_username_from_email($email) {
        $local = strstr($email, '@', true);
        $base  = sanitize_user(strtolower($local), true);
        if ($base === '' || strlen($base) < 3) {
            $base = 'parent_' . substr(md5($email), 0, 8);
        }
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
            if ($i > 999) {
                $username = $base . '_' . substr(md5($email . microtime(true)), 0, 6);
                break;
            }
        }
        return $username;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Newsletter list helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Subscribe an email/user to the named newsletter list, creating the
     * list if it doesn't exist yet. The list type is `custom` for the
     * named lists ("School Staff") and `role` for the auto-bound parent
     * list, which is seeded once from azure-plugin.php upgrade hook.
     */
    public static function subscribe_to_named_list($list_name, $email, $user_id = null, $first_name = '', $last_name = '', $type = 'custom', $criteria = array()) {
        $path = AZURE_PLUGIN_PATH . 'includes/class-newsletter-lists.php';
        if (!class_exists('Azure_Newsletter_Lists') && file_exists($path)) {
            require_once $path;
        }
        if (!class_exists('Azure_Newsletter_Lists')) {
            return false;
        }
        $lists = new Azure_Newsletter_Lists();
        $list_id = self::find_or_create_list($lists, $list_name, $type, $criteria);
        if (!$list_id) {
            return false;
        }
        return $lists->add_subscriber($list_id, strtolower(trim($email)), $user_id, $first_name, $last_name);
    }

    /**
     * Lookup-or-create a list by exact name match (case-insensitive).
     * Returns the list id or false on failure.
     */
    public static function find_or_create_list($lists, $name, $type = 'custom', $criteria = array()) {
        $all = $lists->get_all_lists();
        if (is_array($all)) {
            $needle = strtolower(trim($name));
            foreach ($all as $l) {
                if (isset($l->name) && strtolower(trim($l->name)) === $needle) {
                    return (int) $l->id;
                }
            }
        }
        return $lists->create_list($name, $type, '', $criteria);
    }

    // ─────────────────────────────────────────────────────────────────
    //  AJAX: AcyMailing preview
    // ─────────────────────────────────────────────────────────────────

    public function ajax_acy_preview() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        if (!self::acymailing_present()) {
            wp_send_json_error(array('message' => __('AcyMailing table (wp_acym_user) not found on this site.', 'azure-plugin')));
        }
        $rows = self::load_acymailing_rows();
        $buckets = array(
            'attach_existing'    => 0,
            'create_parent'      => 0,
            'skip_school_staff'  => 0,
            'skip_sso_domain'    => 0,
            'skip'               => 0,
        );
        $sample = array();
        foreach ($rows as $row) {
            $plan = self::plan_row($row['email'], $row['name'] ?? '', self::SOURCE_ACYMAILING);
            if (!isset($buckets[$plan['action']])) {
                $buckets[$plan['action']] = 0;
            }
            $buckets[$plan['action']]++;
            if (count($sample) < 25) {
                $sample[] = array(
                    'email'  => $plan['email'] ?: ($row['email'] ?? ''),
                    'name'   => $plan['name'],
                    'action' => $plan['action'],
                    'reason' => $plan['reason'],
                );
            }
        }
        wp_send_json_success(array(
            'total'     => count($rows),
            'buckets'   => $buckets,
            'sample'    => $sample,
            'sso_role'  => self::get_sso_role_slug(),
            'sso_domain' => self::get_sso_org_domain(),
            'school_domain' => self::get_school_staff_domain(),
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  AJAX: AcyMailing run (batched)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Process up to BATCH_SIZE AcyMailing rows (offset by `offset`).
     * Client polls by passing back the latest cursor.
     *
     * Each row is committed independently so a partial-batch failure never
     * loses progress.
     */
    public function ajax_acy_run() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        if (!self::acymailing_present()) {
            wp_send_json_error(array('message' => __('AcyMailing table not found.', 'azure-plugin')));
        }
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

        global $wpdb;
        $table = $wpdb->prefix . 'acym_user';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, name FROM {$table}
             WHERE email IS NOT NULL AND email <> ''
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ), ARRAY_A);

        $results = array(
            'created_parent'    => 0,
            'attached_existing' => 0,
            'skipped_school'    => 0,
            'skipped_sso'       => 0,
            'errors'            => 0,
            'invalid'           => 0,
            'created_user_ids'  => array(),
        );

        foreach ($rows as $row) {
            $plan = self::plan_row($row['email'], $row['name'] ?? '', self::SOURCE_ACYMAILING);
            switch ($plan['action']) {
                case 'create_parent':
                    $uid = self::create_parent_user($plan['email'], $plan['name'], self::SOURCE_ACYMAILING);
                    if (is_wp_error($uid)) {
                        $results['errors']++;
                    } else {
                        $results['created_parent']++;
                        $results['created_user_ids'][] = (int) $uid;
                        // Parents newsletter list is role-bound (auto-syncs
                        // from the `parent` role) so we do NOT add an
                        // explicit member row — that would only pollute
                        // wp_azure_newsletter_list_members with rows the
                        // get_subscribers() role branch never reads.
                    }
                    break;
                case 'attach_existing':
                    $ok = self::attach_existing_to_parent($plan['existing_user_id']);
                    if ($ok) {
                        $results['attached_existing']++;
                    } else {
                        $results['errors']++;
                    }
                    break;
                case 'skip_school_staff':
                    $results['skipped_school']++;
                    break;
                case 'skip_sso_domain':
                    $results['skipped_sso']++;
                    break;
                case 'skip':
                default:
                    $results['invalid']++;
                    break;
            }
        }

        $next_offset = $offset + count($rows);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE email IS NOT NULL AND email <> ''");
        $done = ($next_offset >= $total);

        wp_send_json_success(array(
            'processed'   => count($rows),
            'next_offset' => $next_offset,
            'total'       => $total,
            'done'        => $done,
            'results'     => $results,
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  AJAX: single-user "test me first" provisioning
    // ─────────────────────────────────────────────────────────────────

    /**
     * Provision a single user (Jamie's "test me first" flow). Accepts
     * email, display name, optional phone and child name/grade/teacher.
     * Always lands in the Parent role (even for @org_domain emails) so
     * the welcome email + magic link can be exercised end-to-end without
     * waiting on SSO.
     */
    public function ajax_test_user() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $email = isset($_POST['email']) ? strtolower(trim(sanitize_email(wp_unslash($_POST['email'])))) : '';
        $name  = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $child_name    = isset($_POST['child_name']) ? sanitize_text_field(wp_unslash($_POST['child_name'])) : '';
        $child_grade   = isset($_POST['child_grade']) ? sanitize_text_field(wp_unslash($_POST['child_grade'])) : '';
        $child_teacher = isset($_POST['child_teacher']) ? sanitize_text_field(wp_unslash($_POST['child_teacher'])) : '';
        $force = !empty($_POST['force']);

        if (!$email || !is_email($email)) {
            wp_send_json_error(array('message' => __('Email address is required.', 'azure-plugin')));
        }

        $existing = get_user_by('email', $email);
        $user_id = $existing ? (int) $existing->ID : 0;

        if ($existing && !$force) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %1$d user id, %2$s comma-separated roles */
                    __('User already exists (id %1$d, roles: %2$s). Re-submit with "force" to attach + reissue activation token.', 'azure-plugin'),
                    $user_id,
                    implode(', ', (array) $existing->roles)
                ),
                'user_id' => $user_id,
            ));
        }

        $extra = array(
            'billing_phone' => $phone,
            'phone'         => $phone,
        );

        if (!$existing) {
            $created = self::create_parent_user($email, $name, self::SOURCE_TEST, $extra);
            if (is_wp_error($created)) {
                wp_send_json_error(array('message' => $created->get_error_message()));
            }
            $user_id = (int) $created;
        } else {
            // Force path: ensure parent role + reissue token + reapply meta.
            self::attach_existing_to_parent($user_id);
            update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
            update_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORTED_AT, current_time('mysql', true));
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, self::SOURCE_TEST);
            foreach ($extra as $k => $v) {
                if ($v !== '') {
                    update_user_meta($user_id, $k, sanitize_text_field($v));
                }
            }
            if ($name !== '') {
                wp_update_user(array('ID' => $user_id, 'display_name' => $name));
                $parts = preg_split('/\s+/', trim($name), 2);
                if (!empty($parts[0])) {
                    update_user_meta($user_id, 'first_name', $parts[0]);
                }
                if (!empty($parts[1])) {
                    update_user_meta($user_id, 'last_name', $parts[1]);
                }
            }
            Azure_Parent_Activation::issue_token($user_id);
        }

        // Optional: capture child info via the User Children module if loaded.
        if ($child_name !== '' && class_exists('Azure_User_Children') && method_exists('Azure_User_Children', 'ensure_family_for_user')) {
            try {
                $family_id = Azure_User_Children::ensure_family_for_user($user_id);
                if ($family_id && method_exists('Azure_User_Children', 'save_child')) {
                    Azure_User_Children::save_child($family_id, array(
                        'child_name' => $child_name,
                        'grade'      => $child_grade,
                        'teacher'    => $child_teacher,
                    ));
                }
            } catch (\Throwable $e) {
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::warning('Test-user child capture failed: ' . $e->getMessage(), array('module' => 'ParentMigration'));
                }
            }
        }

        // No explicit list-subscribe call: the Parents newsletter list is
        // role-bound to `parent`, so creating the user as `parent` (above)
        // already makes them a subscriber when get_subscribers() runs.

        // Build the activation URL (NOT sent yet — caller chooses to send).
        $url = Azure_Parent_Activation::issue_url($user_id);

        wp_send_json_success(array(
            'user_id'        => $user_id,
            'email'          => $email,
            'activation_url' => $url,
            'message'        => __('Test user is ready. Use "Send welcome email" below to deliver the activation link.', 'azure-plugin'),
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  AJAX: welcome email preview + send
    // ─────────────────────────────────────────────────────────────────

    /**
     * Render the welcome email body for one user without sending it.
     * Used by the admin UI to verify the merge fields and link before
     * dispatching anything. Returns subject + html.
     */
    public function ajax_welcome_preview() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($user_id <= 0) {
            wp_send_json_error(array('message' => __('user_id is required.', 'azure-plugin')));
        }
        $payload = self::build_welcome_payload($user_id);
        if (is_wp_error($payload)) {
            wp_send_json_error(array('message' => $payload->get_error_message()));
        }
        wp_send_json_success($payload);
    }

    /**
     * Send the welcome email to one user (the test path) or to every
     * parent that still has the login-disabled flag (the bulk path).
     *
     * Inputs (all optional):
     *   user_id  — single user; takes precedence over scope
     *   scope    — 'disabled_parents' (default) when no user_id
     *   limit    — soft cap on bulk send size (default 50/batch)
     *   offset   — bulk pagination cursor
     *   transport — 'wp_mail' (default) | 'mailgun' (Newsletter sender)
     */
    public function ajax_send_welcome() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }

        $user_id   = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $scope     = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'disabled_parents';
        $limit     = isset($_POST['limit']) ? max(1, min(200, (int) $_POST['limit'])) : 50;
        $offset    = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $transport = isset($_POST['transport']) ? sanitize_key($_POST['transport']) : 'wp_mail';
        if (!in_array($transport, array('wp_mail', 'mailgun'), true)) {
            $transport = 'wp_mail';
        }

        $user_ids = array();
        if ($user_id > 0) {
            $user_ids = array($user_id);
        } else {
            $user_ids = self::find_pending_parent_ids($limit, $offset);
        }

        $sent = 0;
        $failed = 0;
        $details = array();
        foreach ($user_ids as $uid) {
            $result = self::send_welcome_email($uid, $transport);
            if (is_wp_error($result)) {
                $failed++;
                $details[] = array(
                    'user_id' => $uid,
                    'ok'      => false,
                    'error'   => $result->get_error_message(),
                );
            } else {
                $sent++;
                $details[] = array(
                    'user_id' => $uid,
                    'ok'      => true,
                    'email'   => $result['email'],
                );
            }
        }

        $remaining = ($user_id > 0) ? 0 : self::count_pending_parents();
        wp_send_json_success(array(
            'sent'      => $sent,
            'failed'    => $failed,
            'remaining' => $remaining,
            'next_offset' => $offset + count($user_ids),
            'done'      => ($user_id > 0) ? true : ($remaining === 0 || count($user_ids) === 0),
            'details'   => $details,
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Welcome email composition + delivery
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build {to, subject, html, text, activation_url} for one user.
     * Issues a fresh activation token if none exists (or it's expired).
     */
    public static function build_welcome_payload($user_id, $subject_suffix = '') {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return new WP_Error('no_user', __('User not found.', 'azure-plugin'));
        }

        // We can't recover the raw token from the hash, so always issue
        // a fresh one when building the payload. This rotates any older
        // outstanding token (the only valid link is the one in this
        // email).
        $url = Azure_Parent_Activation::issue_url($user_id);
        if ($url === false) {
            return new WP_Error('token_failed', __('Could not issue activation token.', 'azure-plugin'));
        }

        // Set a fresh, human-typeable temporary password and include it
        // in the email. WooCommerce's password change form requires the
        // user to supply their current password to set a new one — for
        // a brand-new activation user there is no "current" password
        // they would know, which dead-ends the flow. Sending a temp
        // password they can copy/paste from the email keeps us on the
        // standard WC change-password path with no custom validation
        // bypasses (the magic link still does the actual auth).
        $temp_password = self::generate_temp_password();
        wp_set_password($temp_password, (int) $user_id);
        // wp_set_password destroys auth cookies for the user; that's fine
        // here because they're not logged in yet — the magic link will
        // re-establish the session via wp_set_auth_cookie().

        $site_name = get_bloginfo('name');
        $first_name = get_user_meta($user_id, 'first_name', true);
        $greeting   = $first_name ? sprintf(__('Hi %s,', 'azure-plugin'), $first_name) : __('Hello,', 'azure-plugin');

        // Subject suffix is used by the test-welcome diagnostic to make
        // each test send sortable in Gmail (which threads identical
        // subjects together — operator can't tell which one is the
        // newest without a unique tag).
        $subject = sprintf(__('Activate your %s account', 'azure-plugin'), $site_name);
        if ($subject_suffix !== '') {
            $subject .= ' — ' . $subject_suffix;
        }

        $html = self::render_welcome_html(array(
            'site_name'      => $site_name,
            'greeting'       => $greeting,
            'first_name'     => $first_name,
            'activation_url' => $url,
            'temp_password'  => $temp_password,
            'support_email'  => get_option('admin_email'),
        ));
        $text = self::render_welcome_text(array(
            'site_name'      => $site_name,
            'greeting'       => $greeting,
            'activation_url' => $url,
            'temp_password'  => $temp_password,
            'support_email'  => get_option('admin_email'),
        ));

        return array(
            'to'             => $user->user_email,
            'email'          => $user->user_email,
            'user_id'        => (int) $user_id,
            'subject'        => $subject,
            'html'           => $html,
            'text'           => $text,
            'activation_url' => $url,
            'temp_password'  => $temp_password,
        );
    }

    /**
     * Build a memorable, easy-to-type temporary password. We avoid the
     * full wp_generate_password() character set on purpose:
     *   - No symbols (users have to type this from a phone in many cases)
     *   - No ambiguous characters (0/O, 1/l/I)
     *   - Hyphenated triplets so the eye can chunk it (e.g. "Wld-K7r-9pX")
     * It's still ~62 bits of entropy because (a) account is locked behind
     * the magic-link click and (b) the user is forced to change it on
     * first save, so the temp value's lifetime is minutes-to-days, not
     * forever.
     */
    private static function generate_temp_password() {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $len = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 9; $i++) {
            $out .= $alphabet[ random_int(0, $len - 1) ];
            if ($i === 2 || $i === 5) {
                $out .= '-';
            }
        }
        return $out;
    }

    /**
     * Send the welcome email via the chosen transport. Returns the
     * payload on success or WP_Error on failure.
     *
     * Transports:
     *   'wp_mail' — uses whatever wp_mail is configured to do (Azure_Email_Mailer
     *               will route through Graph API / HVE / ACS if enabled, or
     *               fall back to PHPMailer SMTP). Best for the single-user
     *               test (no list creation, no campaign overhead).
     *   'mailgun'    — pushes through Azure_Newsletter_Sender's Mailgun backend.
     *   'acymailing' — uses AcyMailing's MailerHelper directly. This re-uses
     *                  the SMTP/from-domain/DKIM that already sends the
     *                  biweekly newsletter, so deliverability matches what
     *                  the operator has already proven works for parents'
     *                  inboxes. Recommended transport for the bulk welcome
     *                  blast on this site (the App Service Email plugin
     *                  intercepts wp_mail and routes through ACS, which
     *                  is fine for transactional but loses AcyMailing's
     *                  branded From-domain).
     */
    public static function send_welcome_email($user_id, $transport = 'wp_mail', $subject_suffix = '', $payload = null) {
        // Allow caller to pre-build the payload (so we don't rotate the
        // token a second time when the caller already has one). When
        // $payload is null we build a fresh one.
        if ($payload === null) {
            $payload = self::build_welcome_payload($user_id, $subject_suffix);
        }
        if (is_wp_error($payload)) {
            return $payload;
        }

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        if ($transport === 'mailgun') {
            $sent = self::send_via_mailgun($payload);
        } elseif ($transport === 'acymailing') {
            $sent = self::send_via_acymailing($payload);
        } else {
            $sent = wp_mail($payload['to'], $payload['subject'], $payload['html'], $headers);
        }

        if (!$sent) {
            return new WP_Error('send_failed', __('Mail server rejected the message.', 'azure-plugin'));
        }
        return $payload;
    }

    /**
     * Send a single welcome email through AcyMailing's MailerHelper. The
     * helper extends PHPMailer and is auto-configured in its constructor
     * with whatever sending method (SMTP / SendGrid / Mailgun / Gmail OAuth
     * / etc.) the operator has set up in AcyMailing → Configuration. That
     * means our welcome email goes out with the same From address, DKIM
     * signature, and reputation history as the biweekly newsletter.
     *
     * Returns true on success, false on failure (caller wraps as WP_Error).
     */
    private static function send_via_acymailing($payload) {
        if (!class_exists('\\AcyMailing\\Helpers\\MailerHelper')) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('AcyMailing MailerHelper not loaded — falling back to wp_mail', array('module' => 'ParentMigration'));
            }
            return wp_mail($payload['to'], $payload['subject'], $payload['html'], array('Content-Type: text/html; charset=UTF-8'));
        }

        try {
            $mailer = new \AcyMailing\Helpers\MailerHelper();
            // The constructor already called setFrom() using AcyMailing's
            // configured from_email / from_name and called
            // setSendingMethodSetting() to pick up SMTP/Mailgun/SendGrid
            // credentials. We just need to attach the recipient and body.
            $mailer->isHTML(true);
            $mailer->Subject = $payload['subject'];
            $mailer->Body    = $payload['html'];
            if (!empty($payload['text'])) {
                $mailer->AltBody = $payload['text'];
            }
            $mailer->addAddress($payload['to']);

            $ok = $mailer->send();
            if (!$ok && class_exists('Azure_Logger')) {
                $err = isset($mailer->ErrorInfo) ? $mailer->ErrorInfo : 'unknown';
                Azure_Logger::warning(
                    "AcyMailing send failed for {$payload['to']}: {$err} — falling back to wp_mail",
                    array('module' => 'ParentMigration')
                );
                return wp_mail($payload['to'], $payload['subject'], $payload['html'], array('Content-Type: text/html; charset=UTF-8'));
            }
            return (bool) $ok;
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning(
                    'AcyMailing send threw exception — falling back to wp_mail: ' . $e->getMessage(),
                    array('module' => 'ParentMigration')
                );
            }
            return wp_mail($payload['to'], $payload['subject'], $payload['html'], array('Content-Type: text/html; charset=UTF-8'));
        }
    }

    /**
     * Push a single welcome email through Azure_Newsletter_Sender's Mailgun
     * backend (matches the existing newsletter-test-email contract:
     * Azure_Newsletter_Sender::send() returns ['success' => bool, 'error' => msg]).
     *
     * Falls back to wp_mail if the sender isn't loadable. Returns true on
     * success; the caller wraps as needed.
     */
    private static function send_via_mailgun($payload) {
        $sender_path = AZURE_PLUGIN_PATH . 'includes/class-newsletter-sender.php';
        if (!class_exists('Azure_Newsletter_Sender') && file_exists($sender_path)) {
            require_once $sender_path;
        }
        if (!class_exists('Azure_Newsletter_Sender') || !class_exists('Azure_Settings')) {
            return wp_mail($payload['to'], $payload['subject'], $payload['html'], array('Content-Type: text/html; charset=UTF-8'));
        }

        $settings = Azure_Settings::get_all_settings();
        $from_addresses = $settings['newsletter_from_addresses'] ?? array();
        $from_email = '';
        $from_name  = get_bloginfo('name');

        if (!empty($from_addresses)) {
            if (is_array($from_addresses)) {
                $first = reset($from_addresses);
                $from_email = is_array($first) ? ($first['email'] ?? '') : $first;
            } else {
                $from_email = (string) $from_addresses;
            }
        }
        if (is_string($from_email) && strpos($from_email, '|') !== false) {
            $parts = explode('|', $from_email);
            $from_email = $parts[0];
            $from_name  = $parts[1] ?? $from_name;
        }
        if (empty($from_email) || !is_email($from_email)) {
            $from_email = get_option('admin_email');
        }

        $reply_to = $settings['newsletter_reply_to'] ?? get_option('admin_email');

        try {
            $sender = new Azure_Newsletter_Sender('mailgun');
            $result = $sender->send(array(
                'to'        => $payload['to'],
                'from'      => $from_email,
                'from_name' => $from_name,
                'reply_to'  => $reply_to,
                'subject'   => $payload['subject'],
                'html'      => $payload['html'],
                'text'      => $payload['text'],
            ));
            if (is_array($result) && !empty($result['success'])) {
                return true;
            }
            if (class_exists('Azure_Logger')) {
                $err = is_array($result) ? ($result['error'] ?? 'unknown') : 'unknown';
                Azure_Logger::warning("Mailgun send failed for {$payload['to']}: {$err} — falling back to wp_mail", array('module' => 'ParentMigration'));
            }
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Mailgun send threw, falling back to wp_mail: ' . $e->getMessage(), array('module' => 'ParentMigration'));
            }
        }

        return wp_mail($payload['to'], $payload['subject'], $payload['html'], array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * User-meta key recording when (and via which transport) we last
     * pushed the welcome email to a parent. Used by the batched blast
     * endpoint to skip parents we've already mailed so successive
     * `limit=100` calls progress through the unsent pool instead of
     * re-mailing the lowest-ID 100 every time.
     */
    const META_WELCOME_SENT_AT = '_pta_welcome_email_sent_at';

    /**
     * Lookup parent users that still have the login-disabled flag set.
     * These are the ones who haven't activated yet — the candidates for
     * the welcome blast.
     */
    public static function find_pending_parent_ids($limit = 50, $offset = 0) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT u.ID
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m_role ON m_role.user_id = u.ID AND m_role.meta_key = %s
             INNER JOIN {$wpdb->usermeta} m_dis  ON m_dis.user_id  = u.ID AND m_dis.meta_key  = %s AND m_dis.meta_value <> '0' AND m_dis.meta_value <> ''
             WHERE m_role.meta_value LIKE %s
             ORDER BY u.ID ASC
             LIMIT %d OFFSET %d",
            $wpdb->prefix . 'capabilities',
            Azure_Parent_Role::META_LOGIN_DISABLED,
            '%' . $wpdb->esc_like('"' . Azure_Parent_Role::ROLE_SLUG . '"') . '%',
            $limit,
            $offset
        ));
        return array_map('intval', (array) $rows);
    }

    public static function count_pending_parents() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m_role ON m_role.user_id = u.ID AND m_role.meta_key = %s
             INNER JOIN {$wpdb->usermeta} m_dis  ON m_dis.user_id  = u.ID AND m_dis.meta_key  = %s AND m_dis.meta_value <> '0' AND m_dis.meta_value <> ''
             WHERE m_role.meta_value LIKE %s",
            $wpdb->prefix . 'capabilities',
            Azure_Parent_Role::META_LOGIN_DISABLED,
            '%' . $wpdb->esc_like('"' . Azure_Parent_Role::ROLE_SLUG . '"') . '%'
        ));
    }

    /**
     * Pending parents that have NOT yet been mailed a welcome message
     * (i.e. no META_WELCOME_SENT_AT row in usermeta). This is the queue
     * the batched blast endpoint walks: each successful send writes the
     * meta row, so the next batch call naturally picks up the next chunk.
     */
    public static function find_unsent_welcome_parent_ids($limit = 100) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT u.ID
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m_role ON m_role.user_id = u.ID AND m_role.meta_key = %s
             INNER JOIN {$wpdb->usermeta} m_dis  ON m_dis.user_id  = u.ID AND m_dis.meta_key  = %s AND m_dis.meta_value <> '0' AND m_dis.meta_value <> ''
             LEFT  JOIN {$wpdb->usermeta} m_sent ON m_sent.user_id = u.ID AND m_sent.meta_key = %s
             WHERE m_role.meta_value LIKE %s
               AND m_sent.umeta_id IS NULL
             ORDER BY u.ID ASC
             LIMIT %d",
            $wpdb->prefix . 'capabilities',
            Azure_Parent_Role::META_LOGIN_DISABLED,
            self::META_WELCOME_SENT_AT,
            '%' . $wpdb->esc_like('"' . Azure_Parent_Role::ROLE_SLUG . '"') . '%',
            $limit
        ));
        return array_map('intval', (array) $rows);
    }

    public static function count_unsent_welcome_parents() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m_role ON m_role.user_id = u.ID AND m_role.meta_key = %s
             INNER JOIN {$wpdb->usermeta} m_dis  ON m_dis.user_id  = u.ID AND m_dis.meta_key  = %s AND m_dis.meta_value <> '0' AND m_dis.meta_value <> ''
             LEFT  JOIN {$wpdb->usermeta} m_sent ON m_sent.user_id = u.ID AND m_sent.meta_key = %s
             WHERE m_role.meta_value LIKE %s
               AND m_sent.umeta_id IS NULL",
            $wpdb->prefix . 'capabilities',
            Azure_Parent_Role::META_LOGIN_DISABLED,
            self::META_WELCOME_SENT_AT,
            '%' . $wpdb->esc_like('"' . Azure_Parent_Role::ROLE_SLUG . '"') . '%'
        ));
    }

    public static function count_welcome_already_sent() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_WELCOME_SENT_AT
        ));
    }

    /**
     * Stamp the welcome-sent marker on a user. We record both the
     * timestamp and the transport so the diagnostic dashboards can
     * tell ACS-routed sends apart from the AcyMailing/SparkPost ones.
     */
    public static function mark_welcome_sent($user_id, $transport) {
        update_user_meta(
            (int) $user_id,
            self::META_WELCOME_SENT_AT,
            sprintf('%s|%s', gmdate('Y-m-d\TH:i:s\Z'), (string) $transport)
        );
    }

    /**
     * Send the welcome email to up to $limit unsent pending parents and
     * return a structured summary. Designed to be called repeatedly (eg
     * from a PowerShell loop) until `remaining` hits zero. We deliberately
     * do NOT do the entire pool in one PHP request — App Service is happy
     * with a 60s window but we don't want to risk it, and SparkPost's
     * relay throttle is gentler if we breathe between batches.
     *
     * @param array $args {
     *     @type int    $limit       Max users to attempt this call (default 100).
     *     @type string $transport   wp_mail | acymailing | mailgun (default acymailing).
     *     @type bool   $dry_run     If true, identify users but do not actually send.
     *     @type int    $sleep_ms    Sleep between sends inside this batch (default 250ms).
     *     @type bool   $stop_on_fail Abort batch after first send failure (default false).
     * }
     */
    public static function send_welcome_batch($args = array()) {
        $limit       = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
        $transport   = isset($args['transport']) ? (string) $args['transport'] : 'acymailing';
        $dry_run     = !empty($args['dry_run']);
        $sleep_us    = isset($args['sleep_ms']) ? max(0, (int) $args['sleep_ms']) * 1000 : 250000;
        $stop_on_fail = !empty($args['stop_on_fail']);

        $started_at = microtime(true);
        $ids = self::find_unsent_welcome_parent_ids($limit);

        $sent_ok = 0;
        $sent_fail = 0;
        $skipped  = 0;
        $details  = array();

        // Domains that should NEVER receive the parent welcome email even
        // if the account somehow ended up with the parent role (legacy
        // imports, manual signups, etc.). @lwsd.org accounts belong on
        // the school_staff role; @wilderptsa.net accounts are PTSA
        // volunteers managed via SSO. We stamp them as
        // "skipped:domain_block" in META_WELCOME_SENT_AT so they fall
        // out of the unsent pool and we never look at them again.
        $blocked_domains = array('lwsd.org', 'wilderptsa.net');

        foreach ($ids as $uid) {
            $user = get_user_by('id', $uid);
            if (!$user) {
                $sent_fail++;
                $details[] = array(
                    'user_id' => $uid, 'email' => null, 'sent' => false,
                    'error' => 'user_lookup_failed',
                );
                continue;
            }

            $email_lower = strtolower((string) $user->user_email);
            $domain = (strpos($email_lower, '@') !== false)
                ? substr($email_lower, strpos($email_lower, '@') + 1)
                : '';
            if (in_array($domain, $blocked_domains, true)) {
                $skipped++;
                if (!$dry_run) {
                    update_user_meta(
                        (int) $uid,
                        self::META_WELCOME_SENT_AT,
                        sprintf('%s|skipped:domain_block:%s', gmdate('Y-m-d\TH:i:s\Z'), $domain)
                    );
                }
                $details[] = array(
                    'user_id' => $uid, 'email' => $user->user_email,
                    'sent' => false, 'error' => null,
                    'skipped' => 'domain_block', 'domain' => $domain,
                );
                continue;
            }

            if ($dry_run) {
                $details[] = array(
                    'user_id' => $uid, 'email' => $user->user_email,
                    'sent' => false, 'error' => null, 'dry_run' => true,
                );
                continue;
            }

            try {
                $payload = self::build_welcome_payload($uid);
                if (!is_array($payload)) {
                    $sent_fail++;
                    $details[] = array(
                        'user_id' => $uid, 'email' => $user->user_email,
                        'sent' => false, 'error' => 'payload_unavailable',
                    );
                    if ($stop_on_fail) { break; }
                    continue;
                }
                $send_result = self::send_welcome_email($uid, $transport, '', $payload);
                if (is_wp_error($send_result)) {
                    $sent_fail++;
                    $details[] = array(
                        'user_id' => $uid, 'email' => $user->user_email,
                        'sent' => false, 'error' => $send_result->get_error_message(),
                    );
                    if ($stop_on_fail) { break; }
                } else {
                    $sent_ok++;
                    self::mark_welcome_sent($uid, $transport);
                    $details[] = array(
                        'user_id' => $uid, 'email' => $user->user_email,
                        'sent' => true, 'error' => null,
                    );
                }
            } catch (\Throwable $e) {
                $sent_fail++;
                $details[] = array(
                    'user_id' => $uid, 'email' => $user->user_email,
                    'sent' => false, 'error' => $e->getMessage(),
                );
                if ($stop_on_fail) { break; }
            }

            if ($sleep_us > 0 && count($ids) > 1) {
                usleep($sleep_us);
            }
        }

        return array(
            'limit'           => $limit,
            'transport'       => $transport,
            'dry_run'         => $dry_run,
            'attempted'       => count($details),
            'sent_ok'         => $sent_ok,
            'sent_fail'       => $sent_fail,
            'skipped'         => $skipped,
            'remaining'       => self::count_unsent_welcome_parents(),
            'pending_total'   => self::count_pending_parents(),
            'already_sent'    => self::count_welcome_already_sent(),
            'elapsed_ms'      => (int) round((microtime(true) - $started_at) * 1000),
            'details'         => $details,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Templates
    // ─────────────────────────────────────────────────────────────────

    private static function render_welcome_html($vars) {
        $site = esc_html($vars['site_name']);
        $greeting = esc_html($vars['greeting']);
        $url = esc_url($vars['activation_url']);
        $support = esc_html($vars['support_email']);
        $temp = esc_html(isset($vars['temp_password']) ? $vars['temp_password'] : '');
        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;">
        <tr><td style="padding:32px 32px 16px 32px;">
          <h1 style="margin:0 0 12px 0;font-size:22px;color:#1d2327;">Welcome to {$site}</h1>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">{$greeting}</p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            We've created an account for you on the {$site} family portal so you can sign up
            for events, manage volunteer slots, and stay in the loop with PTSA news.
          </p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            <strong>Step 1.</strong> Click the button below to sign in. This link is single-use
            and expires in 14 days.
          </p>
          <p style="text-align:center;margin:24px 0;">
            <a href="{$url}" style="display:inline-block;padding:14px 28px;background:#0078d4;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">Sign in &amp; activate</a>
          </p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            <strong>Step 2.</strong> Once you're in, you'll be asked to pick a password
            you'll remember. Use this temporary password as the &ldquo;Current password&rdquo;:
          </p>
          <p style="text-align:center;margin:8px 0 24px 0;">
            <span style="display:inline-block;padding:12px 18px;background:#f1f3f5;border:1px solid #d1d5db;border-radius:6px;font-family:Consolas,Menlo,'SF Mono',monospace;font-size:18px;letter-spacing:0.5px;color:#1d2327;">{$temp}</span>
          </p>
          <p style="margin:0 0 8px 0;font-size:13px;line-height:1.5;color:#646970;">
            If the button doesn't work, copy and paste this link into your browser:<br>
            <span style="word-break:break-all;color:#0073aa;">{$url}</span>
          </p>
        </td></tr>
        <tr><td style="padding:16px 32px 32px 32px;border-top:1px solid #e0e0e0;">
          <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">
            Questions? Send an email to <a href="mailto:{$support}" style="color:#0073aa;">{$support}</a>.
            <br>You're receiving this because we have you on file as a current {$site} family.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    private static function render_welcome_text($vars) {
        $site = $vars['site_name'];
        $greeting = $vars['greeting'];
        $url = $vars['activation_url'];
        $support = $vars['support_email'];
        $temp = isset($vars['temp_password']) ? $vars['temp_password'] : '';
        return "Welcome to {$site}\n\n"
            . "{$greeting}\n\n"
            . "We've created an account for you on the {$site} family portal.\n\n"
            . "Step 1. Click the link below to sign in. This link is single-use and expires in 14 days.\n\n"
            . $url . "\n\n"
            . "Step 2. Once you're in, you'll be asked to pick a password you'll remember. "
            . "Use this temporary password as the \"Current password\":\n\n"
            . "    {$temp}\n\n"
            . "Questions? Send an email to {$support}.\n"
            . "You're receiving this because we have you on file as a current {$site} family.\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Misc helpers
    // ─────────────────────────────────────────────────────────────────

    public static function first_name_of($name) {
        if (!$name) return '';
        $parts = preg_split('/\s+/', trim($name), 2);
        return isset($parts[0]) ? $parts[0] : '';
    }

    public static function last_name_of($name) {
        if (!$name) return '';
        $parts = preg_split('/\s+/', trim($name), 2);
        return isset($parts[1]) ? $parts[1] : '';
    }
}
