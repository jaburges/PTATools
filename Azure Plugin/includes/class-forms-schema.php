<?php
/**
 * Forms: field registry, schema sanitising, submission validation,
 * field rendering, spam guards and CSV escaping.
 *
 * Everything here is static and side-effect free so the rules can be
 * unit tested without WordPress (tests/test-forms-schema.php).
 *
 * A schema is a list of fields:
 *   {id, type, label, name, required, placeholder, help, options[],
 *    width: full|half, prefill, content, maxlength, min, max,
 *    show_if: {field, value}}
 *
 * show_if may only reference an input field that comes earlier, so
 * visibility can be decided in one pass on both client and server.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Forms_Schema {

    const MAX_FIELDS  = 80;
    const MAX_OPTIONS = 60;
    const MIN_FILL_SECONDS = 3;

    /** Names the submit pipeline uses for itself. */
    const RESERVED_NAMES = array('website', 'form_id', 'action', 'id', '_pta_ts', '_pta_elapsed', '_pta_form', '_wpnonce');

    const PREFILL_SOURCES = array('first_name', 'last_name', 'full_name', 'email', 'phone');

    /**
     * Inputs post as pta_f[name] so a field called "name" or "year" can
     * never be read as a WordPress query var on the no-JS page post.
     */
    const INPUT_PREFIX = 'pta_f';

    public static function input_name($name, $multi = false) {
        return self::INPUT_PREFIX . '[' . $name . ']' . ($multi ? '[]' : '');
    }

    /**
     * @return array<string,array{label:string,input:bool,options?:bool,multi?:bool,max?:int,prefill?:bool}>
     */
    public static function types() {
        return array(
            'heading'    => array('label' => __('Heading', 'azure-plugin'),          'input' => false),
            'paragraph'  => array('label' => __('Paragraph', 'azure-plugin'),        'input' => false),
            'text'       => array('label' => __('Text', 'azure-plugin'),             'input' => true, 'max' => 200,  'prefill' => true),
            'email'      => array('label' => __('Email', 'azure-plugin'),            'input' => true, 'max' => 254,  'prefill' => true),
            'phone'      => array('label' => __('Phone', 'azure-plugin'),            'input' => true, 'max' => 40,   'prefill' => true),
            'number'     => array('label' => __('Number', 'azure-plugin'),           'input' => true, 'max' => 40),
            'textarea'   => array('label' => __('Long text', 'azure-plugin'),        'input' => true, 'max' => 5000),
            'select'     => array('label' => __('Dropdown', 'azure-plugin'),         'input' => true, 'options' => true),
            'checkboxes' => array('label' => __('Checkboxes', 'azure-plugin'),       'input' => true, 'options' => true, 'multi' => true),
            'radio'      => array('label' => __('Radio buttons', 'azure-plugin'),    'input' => true, 'options' => true),
            'date'       => array('label' => __('Date', 'azure-plugin'),             'input' => true, 'max' => 10),
            'consent'    => array('label' => __('Consent checkbox', 'azure-plugin'), 'input' => true),
            'child'      => array('label' => __('Child', 'azure-plugin'),            'input' => true, 'max' => 200),
            'grade'      => array('label' => __('Grade', 'azure-plugin'),            'input' => true, 'max' => 50,  'dynamic' => true),
            'teacher'    => array('label' => __('Teacher', 'azure-plugin'),          'input' => true, 'max' => 191, 'dynamic' => true),
            'children'   => array('label' => __('Children (repeating)', 'azure-plugin'), 'input' => true, 'group' => true),
        );
    }

    const MAX_CHILDREN = 10;

    /**
     * Where a registration form saves each answer. Keys are stored on the
     * field as `profile`; each may be used by one field only.
     *
     * @return array<string,array{label:string,types:string[]}>
     */
    public static function profile_targets() {
        return array(
            'first_name'          => array('label' => __('Parent 1 first name', 'azure-plugin'), 'types' => array('text')),
            'last_name'           => array('label' => __('Parent 1 last name', 'azure-plugin'), 'types' => array('text')),
            'email'               => array('label' => __('Parent 1 email (sign-in)', 'azure-plugin'), 'types' => array('email')),
            'phone'               => array('label' => __('Parent 1 cell', 'azure-plugin'), 'types' => array('phone', 'text')),
            'parent_2_first_name' => array('label' => __('Parent 2 first name', 'azure-plugin'), 'types' => array('text')),
            'parent_2_last_name'  => array('label' => __('Parent 2 last name', 'azure-plugin'), 'types' => array('text')),
            'parent_2_email'      => array('label' => __('Parent 2 email', 'azure-plugin'), 'types' => array('email')),
            'parent_2_phone'      => array('label' => __('Parent 2 cell', 'azure-plugin'), 'types' => array('phone', 'text')),
            'emergency_name'      => array('label' => __('Emergency contact name', 'azure-plugin'), 'types' => array('text')),
            'emergency_phone'     => array('label' => __('Emergency contact cell', 'azure-plugin'), 'types' => array('phone', 'text')),
            'emergency_email'     => array('label' => __('Emergency contact email', 'azure-plugin'), 'types' => array('email')),
        );
    }

    /**
     * Field name for each mapped profile target, plus `children` for the
     * first Children field.
     *
     * @return array<string,string>
     */
    public static function profile_map(array $schema) {
        $map = array();
        foreach (self::input_fields($schema) as $field) {
            if ($field['type'] === 'children') {
                if (!isset($map['children'])) {
                    $map['children'] = $field['name'];
                }
                continue;
            }
            $target = (string) ($field['profile'] ?? '');
            if ($target !== '' && !isset($map[$target])) {
                $map[$target] = $field['name'];
            }
        }
        return $map;
    }

    /** @var array<string,callable> */
    private static $option_sources = array();

    /** @var array<string,string[]> */
    private static $option_cache = array();

    /**
     * Override where grade/teacher choices come from (tests).
     */
    public static function set_option_source($type, $source) {
        self::$option_sources[$type] = $source;
        unset(self::$option_cache[$type]);
    }

    /**
     * Choices for grade and teacher fields, read from Product Fields so
     * forms never drift from the store. An empty teacher list means the
     * roster is free text, so the field renders as a text box.
     *
     * @return string[]
     */
    public static function dynamic_options($type) {
        if (isset(self::$option_cache[$type])) {
            return self::$option_cache[$type];
        }
        $options = array();
        if (isset(self::$option_sources[$type])) {
            $options = (array) call_user_func(self::$option_sources[$type]);
        } elseif ($type === 'grade') {
            $options = class_exists('Azure_Product_Fields_Module')
                ? Azure_Product_Fields_Module::get_grade_options()
                : array('PreK', 'K', '1', '2', '3', '4', '5');
        } elseif ($type === 'teacher' && class_exists('Azure_Product_Fields_Module')) {
            $options = Azure_Product_Fields_Module::get_teacher_options();
        }
        $options = array_values(array_unique(array_filter(array_map('strval', (array) $options), 'strlen')));
        return self::$option_cache[$type] = $options;
    }

    /**
     * Same rule as form-rule conditions: case-insensitive equals, any
     * ticked checkbox counts, and an empty value means "answered at all".
     */
    public static function value_matches($actual, $want) {
        $want = strtolower(trim((string) $want));
        $values = is_array($actual) ? $actual : array($actual);
        foreach ($values as $v) {
            $v = strtolower(trim(is_scalar($v) ? (string) $v : ''));
            if ($want === '' ? $v !== '' : $v === $want) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $field
     * @param array $data  Validated answers so far.
     */
    public static function is_visible(array $field, array $data) {
        if (empty($field['show_if']['field'])) {
            return true;
        }
        return self::value_matches($data[$field['show_if']['field']] ?? null, $field['show_if']['value'] ?? '');
    }

    public static function is_input_type($type) {
        $types = self::types();
        return isset($types[$type]) && !empty($types[$type]['input']);
    }

    public static function has_options($type) {
        $types = self::types();
        return isset($types[$type]) && !empty($types[$type]['options']);
    }

    /**
     * Input fields only, in order.
     *
     * @param array $schema
     * @return array
     */
    public static function input_fields(array $schema) {
        $out = array();
        foreach ($schema as $field) {
            if (is_array($field) && self::is_input_type($field['type'] ?? '')) {
                $out[] = $field;
            }
        }
        return $out;
    }

    // ─── Sanitising ───────────────────────────────────────────────────

    /**
     * Drop unknown types and attributes, clamp lengths, and make ids and
     * names unique. Names are what rules reference as {field:name}.
     *
     * @param mixed $fields Decoded schema (array) or JSON string.
     * @return array
     */
    public static function sanitize_schema($fields) {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            $fields = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($fields)) {
            return array();
        }

        $types = self::types();
        $out = array();
        $ids = array();
        $names = array();
        $profiles = array();
        $n = 0;

        foreach ($fields as $raw) {
            if (!is_array($raw) || count($out) >= self::MAX_FIELDS) {
                continue;
            }
            $type = isset($raw['type']) ? (string) $raw['type'] : '';
            if (!isset($types[$type])) {
                continue;
            }
            $n++;

            $id = isset($raw['id']) ? strtolower((string) $raw['id']) : '';
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id) || isset($ids[$id])) {
                $id = 'fld_' . $n;
                while (isset($ids[$id])) {
                    $id .= '_';
                }
            }
            $ids[$id] = true;

            $label = self::clean_text($raw['label'] ?? '', 200);
            if ($type === 'consent') {
                $label = self::clean_inline_html($raw['label'] ?? '', 500);
            }

            $field = array(
                'id'    => $id,
                'type'  => $type,
                'label' => $label,
                'width' => (($raw['width'] ?? '') === 'half') ? 'half' : 'full',
            );
            if (isset($raw['show_if']) && is_array($raw['show_if'])) {
                $target = (string) ($raw['show_if']['field'] ?? '');
                if ($target !== '' && isset($names[$target])) {
                    $field['show_if'] = array(
                        'field' => $target,
                        'value' => self::clean_text($raw['show_if']['value'] ?? '', 200),
                    );
                }
            }

            if ($type === 'paragraph') {
                $field['content'] = function_exists('wp_kses_post')
                    ? wp_kses_post((string) ($raw['content'] ?? ''))
                    : strip_tags((string) ($raw['content'] ?? ''), '<a><strong><em><b><i><br><p><ul><ol><li>');
                $field['width'] = 'full';
                $out[] = $field;
                continue;
            }
            if ($type === 'heading') {
                $field['width'] = 'full';
                $out[] = $field;
                continue;
            }

            $name = self::unique_name(
                self::make_name(isset($raw['name']) && $raw['name'] !== '' ? $raw['name'] : $label),
                $names
            );
            $names[$name] = true;

            $field['name']        = $name;
            $field['required']    = !empty($raw['required']);
            $field['placeholder'] = self::clean_text($raw['placeholder'] ?? '', 200);
            $field['help']        = self::clean_text($raw['help'] ?? '', 500);

            if (!empty($types[$type]['options'])) {
                $field['options'] = self::clean_options($raw['options'] ?? array());
            }
            if (!empty($types[$type]['prefill'])) {
                $prefill = (string) ($raw['prefill'] ?? '');
                $field['prefill'] = in_array($prefill, self::PREFILL_SOURCES, true) ? $prefill : '';
            }
            $target = (string) ($raw['profile'] ?? '');
            $targets = self::profile_targets();
            if ($target !== '' && isset($targets[$target]) && in_array($type, $targets[$target]['types'], true) && !isset($profiles[$target])) {
                $field['profile'] = $target;
                $profiles[$target] = true;
            }
            if ($type === 'children') {
                $max = isset($raw['max_children']) && is_numeric($raw['max_children']) ? (int) $raw['max_children'] : 6;
                $field['max_children'] = max(1, min(self::MAX_CHILDREN, $max));
                $field['details_required'] = !array_key_exists('details_required', $raw) || !empty($raw['details_required']);
                $field['width'] = 'full';
            }
            if (isset($types[$type]['max'])) {
                $cap = (int) $types[$type]['max'];
                $max = isset($raw['maxlength']) && is_numeric($raw['maxlength']) ? (int) $raw['maxlength'] : $cap;
                $field['maxlength'] = max(1, min($cap, $max));
            }
            if ($type === 'number') {
                $field['min'] = (isset($raw['min']) && is_numeric($raw['min'])) ? (float) $raw['min'] : null;
                $field['max'] = (isset($raw['max']) && is_numeric($raw['max'])) ? (float) $raw['max'] : null;
            }

            $out[] = $field;
        }

        return $out;
    }

    public static function make_name($text) {
        $name = strtolower(trim((string) $text));
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        $name = trim((string) $name, '_');
        $name = substr($name, 0, 40);
        $name = rtrim($name, '_');
        if ($name === '') {
            $name = 'field';
        }
        if (preg_match('/^[0-9]/', $name) || in_array($name, self::RESERVED_NAMES, true)) {
            $name = 'f_' . $name;
        }
        return $name;
    }

    private static function unique_name($name, array $taken) {
        if (!isset($taken[$name])) {
            return $name;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = substr($name, 0, 36) . '_' . $i;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
        return $name . '_' . uniqid();
    }

    private static function clean_text($value, $max) {
        $value = is_scalar($value) ? (string) $value : '';
        $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
        return self::truncate($value, $max);
    }

    private static function clean_inline_html($value, $max) {
        $value = is_scalar($value) ? (string) $value : '';
        $allowed = array(
            'a'      => array('href' => true, 'target' => true, 'rel' => true),
            'strong' => array(),
            'em'     => array(),
            'b'      => array(),
            'i'      => array(),
        );
        $value = function_exists('wp_kses') ? wp_kses($value, $allowed) : strip_tags($value, '<a><strong><em><b><i>');
        return self::truncate(trim($value), $max);
    }

    private static function clean_options($options) {
        if (is_string($options)) {
            $options = preg_split('/\r\n|\r|\n/', $options);
        }
        if (!is_array($options)) {
            return array();
        }
        $out = array();
        foreach ($options as $opt) {
            $opt = self::clean_text($opt, 200);
            if ($opt === '' || in_array($opt, $out, true)) {
                continue;
            }
            $out[] = $opt;
            if (count($out) >= self::MAX_OPTIONS) {
                break;
            }
        }
        return $out;
    }

    private static function truncate($value, $max) {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private static function length($value) {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    // ─── Validation ───────────────────────────────────────────────────

    /**
     * @param array $schema Sanitised schema.
     * @param array $input  name => raw value.
     * @return array{data:array,errors:array}
     */
    public static function validate_submission(array $schema, array $input) {
        $data = array();
        $errors = array();
        $required_msg = __('This field is required.', 'azure-plugin');

        foreach (self::input_fields($schema) as $field) {
            if (!self::is_visible($field, $data)) {
                continue;
            }
            $name = $field['name'];
            $type = $field['type'];
            $raw = array_key_exists($name, $input) ? $input[$name] : null;
            $required = !empty($field['required']);

            if ($type === 'children') {
                $checked = self::validate_children($field, $raw);
                if ($checked['error'] !== '') {
                    $errors[$name] = $checked['error'];
                } elseif ($required && empty($checked['children'])) {
                    $errors[$name] = __('Please add at least one child.', 'azure-plugin');
                } else {
                    $data[$name] = $checked['children'];
                }
                continue;
            }

            if ($type === 'checkboxes') {
                $values = is_array($raw) ? $raw : (($raw === null || $raw === '') ? array() : array($raw));
                $picked = array();
                foreach ($values as $v) {
                    $v = is_scalar($v) ? self::clean_text($v, 200) : '';
                    if ($v === '') {
                        continue;
                    }
                    if (!in_array($v, $field['options'] ?? array(), true)) {
                        $errors[$name] = __('Please choose from the listed options.', 'azure-plugin');
                        continue 2;
                    }
                    if (!in_array($v, $picked, true)) {
                        $picked[] = $v;
                    }
                }
                if ($required && empty($picked)) {
                    $errors[$name] = $required_msg;
                    continue;
                }
                $data[$name] = $picked;
                continue;
            }

            if ($type === 'consent') {
                $yes = in_array(is_scalar($raw) ? strtolower((string) $raw) : '', array('1', 'on', 'yes', 'true'), true) || $raw === true;
                if ($required && !$yes) {
                    $errors[$name] = __('Please tick this box to continue.', 'azure-plugin');
                    continue;
                }
                $data[$name] = $yes ? 'yes' : '';
                continue;
            }

            if (is_array($raw) || is_object($raw)) {
                $errors[$name] = __('Invalid value.', 'azure-plugin');
                continue;
            }
            $raw = $raw === null ? '' : (string) $raw;
            $value = ($type === 'textarea')
                ? (function_exists('sanitize_textarea_field') ? sanitize_textarea_field($raw) : trim(strip_tags($raw)))
                : self::clean_text($raw, 100000);
            $value = trim($value);

            if ($value === '') {
                if ($required) {
                    $errors[$name] = $required_msg;
                    continue;
                }
                $data[$name] = '';
                continue;
            }

            if (isset($field['maxlength']) && self::length($value) > (int) $field['maxlength']) {
                $errors[$name] = sprintf(__('Please keep this under %d characters.', 'azure-plugin'), (int) $field['maxlength']);
                continue;
            }

            switch ($type) {
                case 'email':
                    $ok = function_exists('is_email') ? (bool) is_email($value) : (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
                    if (!$ok) {
                        $errors[$name] = __('Please enter a valid email address.', 'azure-plugin');
                        continue 2;
                    }
                    break;
                case 'phone':
                    $digits = preg_replace('/\D/', '', $value);
                    if (!preg_match('/^\+?[\d\s().-]+$/', $value) || strlen($digits) < 7 || strlen($digits) > 15) {
                        $errors[$name] = __('Please enter a valid phone number.', 'azure-plugin');
                        continue 2;
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$name] = __('Please enter a number.', 'azure-plugin');
                        continue 2;
                    }
                    if (isset($field['min']) && $field['min'] !== null && (float) $value < (float) $field['min']) {
                        $errors[$name] = sprintf(__('Please enter %s or more.', 'azure-plugin'), self::format_number($field['min']));
                        continue 2;
                    }
                    if (isset($field['max']) && $field['max'] !== null && (float) $value > (float) $field['max']) {
                        $errors[$name] = sprintf(__('Please enter %s or less.', 'azure-plugin'), self::format_number($field['max']));
                        continue 2;
                    }
                    break;
                case 'date':
                    $d = DateTime::createFromFormat('!Y-m-d', $value);
                    if (!$d || $d->format('Y-m-d') !== $value) {
                        $errors[$name] = __('Please enter a valid date.', 'azure-plugin');
                        continue 2;
                    }
                    break;
                case 'select':
                case 'radio':
                    if (!in_array($value, $field['options'] ?? array(), true)) {
                        $errors[$name] = __('Please choose from the listed options.', 'azure-plugin');
                        continue 2;
                    }
                    break;
                case 'grade':
                case 'teacher':
                    $choices = self::dynamic_options($type);
                    if ($choices && !in_array($value, $choices, true)) {
                        $errors[$name] = __('Please choose from the listed options.', 'azure-plugin');
                        continue 2;
                    }
                    break;
            }

            $data[$name] = $value;
        }

        return array('data' => $data, 'errors' => $errors);
    }

    /**
     * Rows with nothing filled in are dropped, so an extra empty block
     * never blocks a submission.
     *
     * @param mixed $raw List (or index-keyed map) of {name, grade, teacher}.
     * @return array{children:array<int,array{name:string,grade:string,teacher:string}>,error:string}
     */
    public static function validate_children(array $field, $raw) {
        $rows = is_array($raw) ? array_values($raw) : array();
        $rows = array_slice($rows, 0, (int) ($field['max_children'] ?? self::MAX_CHILDREN));
        $details = !empty($field['details_required']);
        $grades = self::dynamic_options('grade');
        $teachers = self::dynamic_options('teacher');
        $children = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $child = array(
                'name'    => self::clean_text($row['name'] ?? '', 200),
                'grade'   => self::clean_text($row['grade'] ?? '', 50),
                'teacher' => self::clean_text($row['teacher'] ?? '', 191),
            );
            if ($child['name'] === '' && $child['grade'] === '' && $child['teacher'] === '') {
                continue;
            }
            if ($child['name'] === '') {
                return array('children' => array(), 'error' => __("Please enter each child's name.", 'azure-plugin'));
            }
            if ($details && ($child['grade'] === '' || $child['teacher'] === '')) {
                return array('children' => array(), 'error' => __("Please choose each child's grade and teacher.", 'azure-plugin'));
            }
            if (($child['grade'] !== '' && $grades && !in_array($child['grade'], $grades, true))
                || ($child['teacher'] !== '' && $teachers && !in_array($child['teacher'], $teachers, true))) {
                return array('children' => array(), 'error' => __('Please choose grade and teacher from the lists.', 'azure-plugin'));
            }
            $children[] = $child;
        }
        return array('children' => $children, 'error' => '');
    }

    private static function format_number($n) {
        $n = (float) $n;
        return (floor($n) == $n) ? (string) (int) $n : rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    /**
     * First email-typed value in a submission, for anti-spam and the
     * per-person limit.
     */
    public static function first_email(array $schema, array $data) {
        foreach (self::input_fields($schema) as $field) {
            if ($field['type'] === 'email' && !empty($data[$field['name']]) && is_string($data[$field['name']])) {
                return $data[$field['name']];
            }
        }
        return '';
    }

    // ─── Spam guards ──────────────────────────────────────────────────

    /**
     * Rendered into the form as a hidden field. Pages are edge-cached, so
     * the stamp can be old; that only ever makes the time trap pass.
     */
    public static function timing_token($now, $secret) {
        $now = (int) $now;
        return $now . '.' . substr(hash_hmac('sha256', (string) $now, (string) $secret), 0, 16);
    }

    /**
     * @param string          $honeypot Value of the hidden "website" field.
     * @param string          $token    timing_token() from the page.
     * @param string|int|null $elapsed  Seconds since page load, measured by JS (null without JS).
     * @return string '' when the submission passes, otherwise a reason code.
     */
    public static function check_guard($honeypot, $token, $elapsed, $now, $secret, $min = self::MIN_FILL_SECONDS) {
        if (trim((string) $honeypot) !== '') {
            return 'honeypot';
        }
        $parts = explode('.', (string) $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return 'bad_token';
        }
        if (!hash_equals(self::timing_token((int) $parts[0], $secret), (string) $token)) {
            return 'bad_token';
        }
        if ((int) $now - (int) $parts[0] < $min) {
            return 'too_fast';
        }
        if ($elapsed !== null && $elapsed !== '' && is_numeric($elapsed) && (float) $elapsed < $min) {
            return 'too_fast';
        }
        return '';
    }

    // ─── CSV ──────────────────────────────────────────────────────────

    /**
     * Neutralise spreadsheet formulas. Phone numbers such as "+1 425…"
     * are left alone.
     */
    public static function csv_cell($value) {
        if (is_array($value)) {
            $value = self::is_children_value($value) ? self::display_value($value) : implode('; ', array_map('strval', $value));
        }
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false && !preg_match('/^[+-]?[\d\s().-]+$/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }

    public static function csv_line(array $cells) {
        $out = array();
        foreach ($cells as $cell) {
            $out[] = '"' . str_replace('"', '""', self::csv_cell($cell)) . '"';
        }
        return implode(',', $out) . "\n";
    }

    /**
     * @param array $value Stored entry value.
     */
    public static function display_value($value) {
        if (self::is_children_value($value)) {
            $lines = array();
            foreach ($value as $child) {
                $details = array_filter(array(
                    ($child['grade'] ?? '') !== '' ? sprintf(__('Grade %s', 'azure-plugin'), $child['grade']) : '',
                    (string) ($child['teacher'] ?? ''),
                ), 'strlen');
                $lines[] = (string) ($child['name'] ?? '') . ($details ? ' (' . implode(', ', $details) . ')' : '');
            }
            return implode('; ', $lines);
        }
        if (is_array($value)) {
            return implode(', ', array_map(function ($v) {
                return is_scalar($v) ? (string) $v : '';
            }, $value));
        }
        return (string) $value;
    }

    public static function is_children_value($value) {
        return is_array($value) && $value !== array() && is_array(reset($value)) && array_key_exists('name', reset($value));
    }

    // ─── Rendering ────────────────────────────────────────────────────

    /**
     * @param array  $schema
     * @param string $uid    Unique prefix for element ids on the page.
     * @param array  $values name => value to pre-populate (no-JS error round trip).
     */
    public static function render_fields(array $schema, $uid, array $values = array()) {
        $html = '';
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $field['name'] ?? '';
            $html .= self::render_field($field, $uid, $name !== '' && array_key_exists($name, $values) ? $values[$name] : null);
        }
        return $html;
    }

    /**
     * One block per child. Without JavaScript only the first block is
     * usable; pta-forms.js clones it for "Add another child".
     */
    private static function render_children(array $field, $uid, $value, $show_if) {
        $name = $field['name'];
        $required = !empty($field['required']);
        $details = !empty($field['details_required']);
        $max = (int) ($field['max_children'] ?? 6);
        $rows = self::is_children_value($value) ? array_values($value) : array();
        if (!$rows) {
            $rows = array(array('name' => '', 'grade' => '', 'teacher' => ''));
        }
        $help_id = $uid . '-' . $name . '-help';
        $help = ($field['help'] ?? '') !== '' ? '<p class="pta-form__help" id="' . esc_attr($help_id) . '">' . esc_html($field['help']) . '</p>' : '';
        $req_mark = $required ? ' <span class="pta-form__req" aria-hidden="true">*</span>' : '';

        $html = '<div class="pta-form__field pta-form__field--children" data-name="' . esc_attr($name) . '" data-max="' . $max . '"' . $show_if . '>';
        $html .= '<fieldset class="pta-form__children"><legend class="pta-form__label">' . esc_html($field['label'] ?? '') . $req_mark . '</legend>' . $help;
        foreach ($rows as $i => $row) {
            $html .= self::render_child_block($name, $uid, (int) $i, is_array($row) ? $row : array(), $required && $i === 0, $details);
        }
        $html .= '<button type="button" class="pta-form__child-add" hidden>' . esc_html(__('+ Add another child', 'azure-plugin')) . '</button>';
        $html .= '</fieldset><p class="pta-form__error" role="alert" hidden></p></div>';
        return $html;
    }

    private static function render_child_block($name, $uid, $i, array $row, $name_required, $details) {
        $base = self::INPUT_PREFIX . '[' . $name . '][' . $i . ']';
        $id = $uid . '-' . $name . '-' . $i;
        $mark = ' <span class="pta-form__req" aria-hidden="true">*</span>';
        $html = '<div class="pta-form__child" data-index="' . $i . '">';
        $html .= '<div class="pta-form__child-head"><span class="pta-form__child-title">' . esc_html(sprintf(__('Child %d', 'azure-plugin'), $i + 1)) . '</span>'
            . '<button type="button" class="pta-form__child-remove" hidden>' . esc_html(__('Remove', 'azure-plugin')) . '</button></div>';

        $html .= '<div class="pta-form__field pta-form__field--child-name"><label class="pta-form__label" for="' . esc_attr($id . '-name') . '">'
            . esc_html(__("Child's first and last name", 'azure-plugin')) . ($name_required ? $mark : '') . '</label>'
            . '<input type="text" id="' . esc_attr($id . '-name') . '" name="' . esc_attr($base . '[name]') . '" value="' . esc_attr((string) ($row['name'] ?? '')) . '" maxlength="200" autocomplete="off"'
            . ($name_required ? ' required aria-required="true"' : '') . '></div>';

        foreach (array('grade' => __('Grade', 'azure-plugin'), 'teacher' => __('Teacher', 'azure-plugin')) as $key => $label) {
            $options = self::dynamic_options($key);
            $cid = $id . '-' . $key;
            $current = (string) ($row[$key] ?? '');
            $req = $details && $name_required ? ' required aria-required="true"' : '';
            $html .= '<div class="pta-form__field pta-form__field--half"><label class="pta-form__label" for="' . esc_attr($cid) . '">' . esc_html($label) . ($details ? $mark : '') . '</label>';
            if ($options) {
                $html .= '<select id="' . esc_attr($cid) . '" name="' . esc_attr($base . '[' . $key . ']') . '"' . $req . '><option value="">' . esc_html(__('Choose…', 'azure-plugin')) . '</option>';
                foreach ($options as $opt) {
                    $html .= '<option value="' . esc_attr($opt) . '"' . ($current === $opt ? ' selected' : '') . '>' . esc_html($opt) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input type="text" id="' . esc_attr($cid) . '" name="' . esc_attr($base . '[' . $key . ']') . '" value="' . esc_attr($current) . '" maxlength="' . ($key === 'grade' ? 50 : 191) . '" autocomplete="off"' . $req . '>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    public static function render_field(array $field, $uid, $value = null) {
        $type = $field['type'] ?? '';
        $width = (($field['width'] ?? 'full') === 'half') ? ' pta-form__field--half' : '';
        $show_if = '';
        if (!empty($field['show_if']['field'])) {
            $show_if = ' data-show-if="' . esc_attr($field['show_if']['field']) . '" data-show-value="' . esc_attr($field['show_if']['value'] ?? '') . '"';
        }

        if ($type === 'heading') {
            return '<h3 class="pta-form__heading"' . $show_if . '>' . esc_html($field['label'] ?? '') . '</h3>';
        }
        if ($type === 'paragraph') {
            return '<div class="pta-form__text"' . $show_if . '>' . ($field['content'] ?? '') . '</div>';
        }
        if (!self::is_input_type($type) || empty($field['name'])) {
            return '';
        }
        if ($type === 'children') {
            return self::render_children($field, $uid, $value, $show_if);
        }
        if ($type === 'grade' || $type === 'teacher') {
            $field['options'] = self::dynamic_options($type);
        }

        $name = $field['name'];
        $id = $uid . '-' . $name;
        $required = !empty($field['required']);
        $req_attr = $required ? ' required aria-required="true"' : '';
        $help_id = $id . '-help';
        $described = ($field['help'] ?? '') !== '' ? ' aria-describedby="' . esc_attr($help_id) . '"' : '';
        $req_mark = $required ? ' <span class="pta-form__req" aria-hidden="true">*</span>' : '';
        $placeholder = ($field['placeholder'] ?? '') !== '' ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';
        $maxlength = isset($field['maxlength']) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '';
        $prefill = ($field['prefill'] ?? '') !== '' ? ' data-prefill="' . esc_attr($field['prefill']) . '"' : '';
        $label_html = esc_html($field['label'] ?? '');
        $scalar = is_scalar($value) ? (string) $value : '';

        $open = '<div class="pta-form__field pta-form__field--' . esc_attr($type) . $width . '" data-name="' . esc_attr($name) . '"' . $show_if . '>';
        if (($type === 'grade' || $type === 'teacher') && !empty($field['options'])) {
            $type = 'select';
        }
        $help = ($field['help'] ?? '') !== '' ? '<p class="pta-form__help" id="' . esc_attr($help_id) . '">' . esc_html($field['help']) . '</p>' : '';
        $error = '<p class="pta-form__error" role="alert" hidden></p>';

        switch ($type) {
            case 'textarea':
                $control = '<textarea id="' . esc_attr($id) . '" name="' . esc_attr(self::input_name($name)) . '" rows="5"' . $placeholder . $maxlength . $req_attr . $described . '>'
                    . esc_html($scalar) . '</textarea>';
                return $open . '<label class="pta-form__label" for="' . esc_attr($id) . '">' . $label_html . $req_mark . '</label>' . $control . $help . $error . '</div>';

            case 'select':
                $control = '<select id="' . esc_attr($id) . '" name="' . esc_attr(self::input_name($name)) . '"' . $req_attr . $described . '>';
                $control .= '<option value="">' . esc_html(($field['placeholder'] ?? '') !== '' ? $field['placeholder'] : __('Choose…', 'azure-plugin')) . '</option>';
                foreach ($field['options'] ?? array() as $opt) {
                    $control .= '<option value="' . esc_attr($opt) . '"' . ($scalar === $opt ? ' selected' : '') . '>' . esc_html($opt) . '</option>';
                }
                $control .= '</select>';
                return $open . '<label class="pta-form__label" for="' . esc_attr($id) . '">' . $label_html . $req_mark . '</label>' . $control . $help . $error . '</div>';

            case 'radio':
            case 'checkboxes':
                $multi = $type === 'checkboxes';
                $picked = $multi ? (is_array($value) ? array_map('strval', $value) : array()) : array($scalar);
                $control = '<fieldset class="pta-form__choices"' . $described . '><legend class="pta-form__label">' . $label_html . $req_mark . '</legend>';
                foreach ($field['options'] ?? array() as $i => $opt) {
                    $oid = $id . '-' . $i;
                    $control .= '<label class="pta-form__choice" for="' . esc_attr($oid) . '"><input type="' . ($multi ? 'checkbox' : 'radio') . '" id="' . esc_attr($oid) . '" name="'
                        . esc_attr(self::input_name($name, $multi)) . '" value="' . esc_attr($opt) . '"'
                        . (in_array($opt, $picked, true) ? ' checked' : '')
                        . ((!$multi && $required) ? ' required' : '') . '> <span>' . esc_html($opt) . '</span></label>';
                }
                $control .= '</fieldset>';
                return $open . $control . $help . $error . '</div>';

            case 'consent':
                $control = '<label class="pta-form__choice pta-form__consent" for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr(self::input_name($name))
                    . '" value="yes"' . ($scalar === 'yes' ? ' checked' : '') . $req_attr . $described . '> <span>' . ($field['label'] ?? '') . $req_mark . '</span></label>';
                return $open . $control . $help . $error . '</div>';

            default:
                $input_type = array('email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date')[$type] ?? 'text';
                $extra = '';
                if ($type === 'email') {
                    $extra .= ' autocomplete="email"';
                } elseif ($type === 'phone') {
                    $extra .= ' autocomplete="tel"';
                } elseif ($type === 'number') {
                    $extra .= ' step="any"';
                    if (isset($field['min']) && $field['min'] !== null) {
                        $extra .= ' min="' . esc_attr(self::format_number($field['min'])) . '"';
                    }
                    if (isset($field['max']) && $field['max'] !== null) {
                        $extra .= ' max="' . esc_attr(self::format_number($field['max'])) . '"';
                    }
                }
                if ($type === 'number' || $type === 'date') {
                    $maxlength = '';
                }
                $datalist = '';
                if ($type === 'child') {
                    $extra .= ' list="' . esc_attr($id . '-children') . '" data-children="1" autocomplete="off"';
                    $datalist = '<datalist id="' . esc_attr($id . '-children') . '"></datalist>';
                }
                $control = '<input type="' . $input_type . '" id="' . esc_attr($id) . '" name="' . esc_attr(self::input_name($name)) . '" value="' . esc_attr($scalar) . '"'
                    . $placeholder . $maxlength . $extra . $prefill . $req_attr . $described . '>' . $datalist;
                return $open . '<label class="pta-form__label" for="' . esc_attr($id) . '">' . $label_html . $req_mark . '</label>' . $control . $help . $error . '</div>';
        }
    }
}
