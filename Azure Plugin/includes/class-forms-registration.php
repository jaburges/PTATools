<?php
/**
 * Parent self-registration through a PTA form.
 *
 * A form with Settings > "Create a parent account" turned on makes a
 * locked parent account from each response, saves the family, children
 * and emergency contact where Family Info reads them, and emails an
 * activation link. The account cannot sign in until that link is used,
 * and accounts nobody activates are deleted after PURGE_AFTER_DAYS.
 *
 * The submitter always sees the form's normal success message, whether
 * or not the address already had an account, so the form cannot be used
 * to find out who is registered.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Forms_Registration {

    const SOURCE = 'form_registration';
    const PAGE_OPTION = 'azure_forms_register_page';
    const META_ENTRY = '_pta_registration_entry';
    const LINK_TTL = 604800;
    const PURGE_AFTER_DAYS = 8;
    const NOTICE_THROTTLE = 3600;
    const REQUIRED_TARGETS = array('first_name', 'last_name', 'email', 'children');

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('pta_form_validate', array($this, 'validate'), 10, 4);
        add_action('pta_form_submitted', array($this, 'handle_submission'), 5, 4);
        add_action('woocommerce_login_form_end', array($this, 'render_register_link'));
        add_action('login_form', array($this, 'render_register_link'));
    }

    public static function is_registration_form($form) {
        return !empty($form['settings']['registration']);
    }

    /**
     * Profile targets the form still needs before it can create accounts.
     *
     * @return string[]
     */
    public static function missing_requirements(array $schema) {
        return array_values(array_diff(self::REQUIRED_TARGETS, array_keys(Azure_Forms_Schema::profile_map($schema))));
    }

    /**
     * Answers keyed by profile target, e.g. first_name, emergency_phone, children.
     */
    public static function extract(array $schema, array $data) {
        $out = array();
        foreach (Azure_Forms_Schema::profile_map($schema) as $target => $name) {
            $value = $data[$name] ?? '';
            if ($target === 'children') {
                $out[$target] = is_array($value) ? $value : array();
            } else {
                $out[$target] = is_scalar($value) ? trim((string) $value) : '';
            }
        }
        return $out;
    }

    public static function sso_domain() {
        if (!class_exists('Azure_Settings')) {
            return '';
        }
        return strtolower(trim((string) Azure_Settings::get_setting('org_domain', '')));
    }

    /**
     * pta_form_validate: runs after the schema checks pass.
     */
    public function validate($errors, $form, $data, $user_id) {
        if (!self::is_registration_form($form) || !empty($errors)) {
            return $errors;
        }
        $map = Azure_Forms_Schema::profile_map($form['schema'] ?? array());
        $email_field = $map['email'] ?? (string) key($data);

        if ($user_id) {
            $errors[$email_field] = __("You're already signed in, so there's no need to register.", 'azure-plugin');
            return $errors;
        }

        $email = strtolower((string) ($data[$map['email'] ?? ''] ?? ''));
        $sso = self::sso_domain();
        if ($sso !== '' && substr(strrchr($email, '@') ?: '', 1) === $sso) {
            $errors[$email_field] = sprintf(
                /* translators: %s: email domain such as @example.org */
                __('%s accounts sign in with Microsoft, so there is no need to register. Use "Sign in with Microsoft" on the My Account page.', 'azure-plugin'),
                '@' . $sso
            );
        }
        return $errors;
    }

    /**
     * pta_form_submitted, priority 5 so the account exists before any
     * notification rule runs.
     */
    public function handle_submission($form, $entry_id, $data, $user_id) {
        if (!self::is_registration_form($form) || $user_id) {
            return;
        }
        $schema = $form['schema'] ?? array();
        $missing = self::missing_requirements($schema);
        if ($missing) {
            Azure_Logger::warning(sprintf(
                'Forms: registration form %d is missing %s; no account created for entry %d',
                (int) $form['id'], implode(', ', $missing), (int) $entry_id
            ), array('module' => 'Forms'));
            return;
        }
        self::register(self::extract($schema, $data), (int) $entry_id);
    }

    /**
     * @param array $r Output of extract().
     * @return string created | resent | exists | throttled | error
     */
    public static function register(array $r, $entry_id = 0) {
        self::load_dependencies();
        $email = strtolower(trim((string) ($r['email'] ?? '')));
        if (!is_email($email)) {
            return 'error';
        }
        $first = (string) ($r['first_name'] ?? '');
        $last = (string) ($r['last_name'] ?? '');
        $full = trim($first . ' ' . $last);

        $existing = get_user_by('email', $email);
        if ($existing) {
            if (!self::claim_notice_slot($email)) {
                return 'throttled';
            }
            if (self::is_pending((int) $existing->ID)) {
                self::send_activation($existing, $first);
                return 'resent';
            }
            self::send_exists_notice($existing);
            return 'exists';
        }

        $phone = (string) ($r['phone'] ?? '');
        $parent2 = trim(($r['parent_2_first_name'] ?? '') . ' ' . ($r['parent_2_last_name'] ?? ''));
        $user_id = Azure_Parent_Migration::create_parent_user($email, $full, self::SOURCE, array(
            'billing_first_name'    => $first,
            'billing_last_name'     => $last,
            'billing_email'         => $email,
            'billing_phone'         => $phone,
            'pta_pf_parent_1_name'  => $full,
            'pta_pf_parent_1_email' => $email,
            'pta_pf_parent_1_cell'  => $phone,
            'pta_pf_parent_2_name'  => $parent2,
            'pta_pf_parent_2_email' => strtolower((string) ($r['parent_2_email'] ?? '')),
            'pta_pf_parent_2_cell'  => (string) ($r['parent_2_phone'] ?? ''),
            self::META_ENTRY        => (int) $entry_id,
        ));
        if (is_wp_error($user_id)) {
            Azure_Logger::error('Forms: registration could not create an account: ' . $user_id->get_error_message(), array('module' => 'Forms'));
            return 'error';
        }
        $user_id = (int) $user_id;

        // create_parent_user splits the display name at the first space,
        // which gets "Mary Ann Smith" wrong.
        update_user_meta($user_id, 'first_name', $first);
        update_user_meta($user_id, 'last_name', $last);
        // Activation goes straight to choosing a password, so there is no
        // temporary password to change afterwards.
        delete_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET);

        $children = self::save_family($user_id, $r);

        $user = get_user_by('id', $user_id);
        $sent = $user ? self::send_activation($user, $first) : false;
        self::claim_notice_slot($email);
        Azure_Logger::info(sprintf(
            'Forms: registered parent user %d with %d child(ren); activation email %s',
            $user_id, $children, $sent ? 'sent' : 'FAILED'
        ), array('module' => 'Forms'));
        return 'created';
    }

    /**
     * @return int Children saved.
     */
    private static function save_family($user_id, array $r) {
        $family_id = Azure_User_Children::ensure_family_for_user($user_id);
        $keys = self::child_meta_keys();
        $saved = 0;
        foreach ((array) ($r['children'] ?? array()) as $child) {
            $meta = array_filter(array(
                $keys['grade']   => (string) ($child['grade'] ?? ''),
                $keys['teacher'] => (string) ($child['teacher'] ?? ''),
            ), 'strlen');
            if (Azure_User_Children::save_child($user_id, array(
                'child_name' => (string) ($child['name'] ?? ''),
                'family_id'  => $family_id,
                'meta'       => $meta,
            ))) {
                $saved++;
            }
        }
        $emergency = array_filter(array(
            'pta_pf_emergency_contact_name'  => (string) ($r['emergency_name'] ?? ''),
            'pta_pf_emergency_contact_cell'  => (string) ($r['emergency_phone'] ?? ''),
            'pta_pf_emergency_contact_email' => strtolower((string) ($r['emergency_email'] ?? '')),
        ), 'strlen');
        if ($family_id && $emergency) {
            Azure_User_Children::update_family_meta($family_id, $emergency);
        }
        return $saved;
    }

    /**
     * Child meta keys the shop's product fields write, so Family Info and
     * order auto-fill read the same values.
     *
     * @return array{grade:string,teacher:string}
     */
    public static function child_meta_keys() {
        $keys = array('grade' => 'childsgrade', 'teacher' => 'child_teacher');
        if (class_exists('Azure_Product_Fields_Module')) {
            $keys = array_merge($keys, (array) Azure_Product_Fields_Module::get_child_profile_field_keys());
        }
        return array('grade' => 'pta_pf_' . $keys['grade'], 'teacher' => 'pta_pf_' . $keys['teacher']);
    }

    public static function is_pending($user_id) {
        return get_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, true) === self::SOURCE
            && (string) get_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, true) === '1';
    }

    /**
     * One account email per address per hour, so a bot replaying someone
     * else's address cannot flood their inbox.
     */
    private static function claim_notice_slot($email) {
        $key = 'pta_reg_notice_' . md5(strtolower($email));
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, 1, self::NOTICE_THROTTLE);
        return true;
    }

    public static function send_activation($user, $first_name = '') {
        $url = Azure_Parent_Activation::issue_url((int) $user->ID, self::LINK_TTL);
        if (!$url) {
            return false;
        }
        list($subject, $body) = Azure_Email_Messages::render('parent_registration', array(
            'site_name'      => Azure_Email_Messages::site_name(),
            'first_name'     => $first_name !== '' ? $first_name : ((string) get_user_meta($user->ID, 'first_name', true) ?: __('there', 'azure-plugin')),
            'activation_url' => $url,
        ));
        return (bool) wp_mail($user->user_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    public static function send_exists_notice($user) {
        $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
        list($subject, $body) = Azure_Email_Messages::render('parent_registration_exists', array(
            'site_name'  => Azure_Email_Messages::site_name(),
            'first_name' => (string) get_user_meta($user->ID, 'first_name', true) ?: __('there', 'azure-plugin'),
            'login_url'  => $account,
            'reset_url'  => wp_lostpassword_url(),
        ));
        return (bool) wp_mail($user->user_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Delete registrations that were never activated. Runs from the daily
     * forms purge.
     *
     * @return int Accounts deleted.
     */
    public static function purge_unactivated($now = null) {
        global $wpdb;
        self::load_dependencies();
        $now = $now === null ? time() : (int) $now;
        $cutoff = gmdate('Y-m-d H:i:s', $now - self::PURGE_AFTER_DAYS * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT u.ID FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} s ON s.user_id = u.ID AND s.meta_key = %s AND s.meta_value = %s
             INNER JOIN {$wpdb->usermeta} d ON d.user_id = u.ID AND d.meta_key = %s AND d.meta_value = '1'
             WHERE u.user_registered < %s
             ORDER BY u.ID ASC LIMIT 200",
            Azure_Parent_Activation::META_IMPORT_SOURCE, self::SOURCE, Azure_Parent_Role::META_LOGIN_DISABLED, $cutoff
        ));
        $deleted = 0;
        foreach ((array) $ids as $id) {
            if (self::delete_pending_user((int) $id)) {
                $deleted++;
            }
        }
        if ($deleted) {
            Azure_Logger::info(sprintf('Forms: deleted %d registration(s) never activated within %d days', $deleted, self::PURGE_AFTER_DAYS), array('module' => 'Forms'));
        }
        return $deleted;
    }

    private static function delete_pending_user($user_id) {
        global $wpdb;
        $user = get_userdata($user_id);
        if (!$user || !self::is_pending($user_id) || array_diff((array) $user->roles, array('parent'))) {
            return false;
        }

        $family = Azure_User_Children::get_family_for_user($user_id);
        if ($family && (int) $family->primary_user_id === $user_id && empty($family->secondary_user_id)) {
            $children = Azure_Database::get_table_name('user_children');
            $child_meta = Azure_Database::get_table_name('user_children_meta');
            $family_meta = Azure_Database::get_table_name('connected_family_meta');
            $families = Azure_Database::get_table_name('connected_family');
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$children} WHERE family_id = %d", (int) $family->id)) as $child_id) {
                $wpdb->delete($child_meta, array('child_id' => (int) $child_id));
            }
            $wpdb->delete($children, array('family_id' => (int) $family->id));
            $wpdb->delete($family_meta, array('family_id' => (int) $family->id));
            $wpdb->delete($families, array('id' => (int) $family->id));
            Azure_User_Children::clear_children_cache($user_id);
        }

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        return (bool) wp_delete_user($user_id);
    }

    public static function register_url() {
        $page_id = (int) get_option(self::PAGE_OPTION, 0);
        if (!$page_id || get_post_status($page_id) !== 'publish') {
            return '';
        }
        return (string) get_permalink($page_id);
    }

    public function render_register_link() {
        if (is_user_logged_in()) {
            return;
        }
        $url = self::register_url();
        if ($url === '') {
            return;
        }
        echo '<p class="pta-register-link">' . esc_html__('New here?', 'azure-plugin')
            . ' <a href="' . esc_url($url) . '">' . esc_html__('Register a new parent account', 'azure-plugin') . '</a></p>';
    }

    private static function load_dependencies() {
        foreach (array(
            'Azure_Parent_Role'       => 'class-parent-role.php',
            'Azure_Parent_Activation' => 'class-parent-activation.php',
            'Azure_Email_Messages'    => 'class-email-messages.php',
            'Azure_Parent_Migration'  => 'class-parent-migration.php',
            'Azure_User_Children'     => 'class-user-children.php',
        ) as $class => $file) {
            if (!class_exists($class)) {
                require_once AZURE_PLUGIN_PATH . 'includes/' . $file;
            }
        }
    }
}
