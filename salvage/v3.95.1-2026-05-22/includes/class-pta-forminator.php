<?php
/**
 * PTA Forminator Integration
 * 
 * Connects a Forminator form to the PTA Roles module, allowing visitors
 * to sign up for roles via a form that pre-populates role, department,
 * and logged-in user data.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Forminator {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_pta_render_signup_form', array($this, 'ajax_render_signup_form'));
        add_action('wp_ajax_nopriv_pta_render_signup_form', array($this, 'ajax_render_signup_form'));
        add_action('wp_ajax_pta_get_forminator_forms', array($this, 'ajax_get_forminator_forms'));
        add_action('wp_ajax_pta_save_forminator_settings', array($this, 'ajax_save_forminator_settings'));

        add_filter('forminator_cform_render_fields', array($this, 'pre_populate_fields'), 10, 2);
    }

    /**
     * Check if Forminator plugin is active and a form is configured.
     */
    public static function is_configured() {
        if (!class_exists('Forminator')) {
            return false;
        }
        $form_id = Azure_Settings::get_setting('pta_forminator_form_id', '');
        return !empty($form_id);
    }

    /**
     * Get the configured form ID.
     */
    public static function get_form_id() {
        return Azure_Settings::get_setting('pta_forminator_form_id', '');
    }

    /**
     * Get all field mapping settings.
     */
    public static function get_field_mappings() {
        return array(
            'role'  => Azure_Settings::get_setting('pta_forminator_role_field_id', ''),
            'dept'  => Azure_Settings::get_setting('pta_forminator_dept_field_id', ''),
            'fname' => Azure_Settings::get_setting('pta_forminator_fname_field_id', ''),
            'lname' => Azure_Settings::get_setting('pta_forminator_lname_field_id', ''),
            'email' => Azure_Settings::get_setting('pta_forminator_email_field_id', ''),
        );
    }

    /**
     * AJAX: Render the Forminator signup form with pre-populated values.
     * Called from the frontend modal when a visitor clicks a role.
     */
    public function ajax_render_signup_form() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pta_signup_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        $form_id = self::get_form_id();
        if (empty($form_id) || !class_exists('Forminator')) {
            wp_send_json_error('Forminator integration is not configured');
        }

        $role_name = sanitize_text_field($_POST['role_name'] ?? '');
        $dept_name = sanitize_text_field($_POST['department_name'] ?? '');

        // Store pre-population data in a transient keyed by a token
        $token = wp_generate_password(12, false);
        $prepop_data = array(
            'role_name' => $role_name,
            'dept_name' => $dept_name,
        );

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $prepop_data['first_name'] = $user->first_name;
            $prepop_data['last_name']  = $user->last_name;
            $prepop_data['email']      = $user->user_email;
        }

        set_transient('pta_forminator_prepop_' . $token, $prepop_data, 300);

        // Build the shortcode with the pre-population token
        $shortcode = '[forminator_form id="' . intval($form_id) . '"]';

        // Store the token so the filter can pick it up during rendering
        $GLOBALS['pta_forminator_prepop_token'] = $token;

        ob_start();
        echo do_shortcode($shortcode);
        $html = ob_get_clean();

        unset($GLOBALS['pta_forminator_prepop_token']);

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Filter: Pre-populate Forminator form fields with PTA role/user data.
     */
    public function pre_populate_fields($fields, $form_id) {
        $configured_form_id = self::get_form_id();
        if (intval($form_id) !== intval($configured_form_id)) {
            return $fields;
        }

        $token = $GLOBALS['pta_forminator_prepop_token'] ?? '';
        if (empty($token)) {
            // Also check for logged-in user data when form is loaded normally on a page
            if (!is_user_logged_in()) {
                return $fields;
            }
            $prepop_data = array(
                'first_name' => wp_get_current_user()->first_name,
                'last_name'  => wp_get_current_user()->last_name,
                'email'      => wp_get_current_user()->user_email,
            );
        } else {
            $prepop_data = get_transient('pta_forminator_prepop_' . $token);
            if (!$prepop_data) {
                return $fields;
            }
            delete_transient('pta_forminator_prepop_' . $token);
        }

        $mappings = self::get_field_mappings();

        $value_map = array(
            $mappings['role']  => $prepop_data['role_name'] ?? '',
            $mappings['fname'] => $prepop_data['first_name'] ?? '',
            $mappings['lname'] => $prepop_data['last_name'] ?? '',
            $mappings['email'] => $prepop_data['email'] ?? '',
            $mappings['dept']  => $prepop_data['dept_name'] ?? '',
        );

        // Remove empty mapping keys
        $value_map = array_filter($value_map, function($v, $k) {
            return !empty($k);
        }, ARRAY_FILTER_USE_BOTH);

        foreach ($fields as &$field) {
            $field_id = $field['element_id'] ?? '';
            if (isset($value_map[$field_id]) && !empty($value_map[$field_id])) {
                if (!isset($field['options'])) {
                    $field['options'] = array();
                }
                $field['options']['prefill'] = $value_map[$field_id];
                $field['default_value'] = $value_map[$field_id];
            }
        }

        return $fields;
    }

    /**
     * AJAX: Get list of available Forminator forms for the admin settings dropdown.
     */
    public function ajax_get_forminator_forms() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        if (!class_exists('Forminator_API')) {
            wp_send_json_error('Forminator plugin is not active');
        }

        $forms = Forminator_API::get_forms();
        $result = array();

        if (!is_wp_error($forms)) {
            foreach ($forms as $form) {
                $result[] = array(
                    'id'   => $form->id,
                    'name' => $form->settings['formName'] ?? ('Form #' . $form->id),
                );
            }
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Save Forminator integration settings.
     */
    public function ajax_save_forminator_settings() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $fields = array(
            'pta_forminator_form_id',
            'pta_forminator_role_field_id',
            'pta_forminator_dept_field_id',
            'pta_forminator_fname_field_id',
            'pta_forminator_lname_field_id',
            'pta_forminator_email_field_id',
        );

        foreach ($fields as $key) {
            $value = sanitize_text_field($_POST[$key] ?? '');
            Azure_Settings::update_setting($key, $value);
        }

        $open_only = isset($_POST['pta_forminator_open_roles_only'])
            ? filter_var($_POST['pta_forminator_open_roles_only'], FILTER_VALIDATE_BOOLEAN)
            : true;
        Azure_Settings::update_setting('pta_forminator_open_roles_only', $open_only);

        wp_send_json_success('Settings saved');
    }

    /**
     * Get the frontend config needed by JavaScript for the signup modal.
     */
    public static function get_frontend_config() {
        return array(
            'enabled'         => self::is_configured(),
            'open_roles_only' => Azure_Settings::get_setting('pta_forminator_open_roles_only', true),
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('pta_signup_nonce'),
        );
    }
}
