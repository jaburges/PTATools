<?php
/**
 * Minimal WordPress + plugin shims so individual plugin classes can be
 * exercised by the standalone test scripts in this directory without a WP
 * bootstrap. Only the functions the classes under test actually touch are
 * defined; anything else should fail loudly rather than silently pass.
 *
 * Include this before require'ing a class from "Azure Plugin/includes/".
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// WordPress runs with PHP's default timezone pinned to UTC and expresses
// site-local time separately via wp_timezone(). Mirror that here.
date_default_timezone_set('UTC');

foreach (array(
    'MINUTE_IN_SECONDS' => 60,
    'HOUR_IN_SECONDS'   => 3600,
    'DAY_IN_SECONDS'    => 86400,
    'WEEK_IN_SECONDS'   => 604800,
    'MONTH_IN_SECONDS'  => 2592000,
    'YEAR_IN_SECONDS'   => 31536000,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// ---------------------------------------------------------------------------
// Test-controlled state
// ---------------------------------------------------------------------------

class WP_Shim {
    /** @var array Site settings backing Azure_Settings. */
    public static $settings = array();
    /** @var array hook => ['timestamp' => int, 'recurrence' => string|null, 'args' => array] */
    public static $cron = array();
    /** @var array Registered cron recurrence names => interval seconds. */
    public static $schedules = array(
        'hourly'     => 3600,
        'twicedaily' => 43200,
        'daily'      => 86400,
        'weekly'     => 604800,
    );
    /** @var array Option name => value. */
    public static $options = array();
    /** @var string Site timezone used by wp_timezone(). */
    public static $timezone = 'UTC';
    /** @var array Captured log lines. */
    public static $logs = array();
    /** @var array key => [value, expires_at] */
    public static $transients = array();
    /** @var array key => ttl passed to set_transient(), for assertions. */
    public static $transient_ttls = array();
    /** @var array url-substring => response array or WP_Error (or a list to pop in order) */
    public static $http_responses = array();
    /** @var array Log of [url, args] for every HTTP call. */
    public static $http_calls = array();
    /** @var array post_id => [meta_key => value] */
    public static $post_meta = array();

    public static function reset() {
        self::$post_meta = array();
        self::$settings = array();
        self::$cron = array();
        self::$options = array();
        self::$timezone = 'UTC';
        self::$logs = array();
        self::$transients = array();
        self::$transient_ttls = array();
        self::$http_responses = array();
        self::$http_calls = array();
        self::$schedules = array(
            'hourly'     => 3600,
            'twicedaily' => 43200,
            'daily'      => 86400,
            'weekly'     => 604800,
        );
    }

    /** Queue a canned response for any request whose URL contains $needle. */
    public static function on_request($needle, $code, $body) {
        self::$http_responses[$needle][] = array(
            'code' => $code,
            'body' => is_string($body) ? $body : json_encode($body),
            'headers' => array(),
        );
    }

    public static function http($url, $args) {
        self::$http_calls[] = array('url' => $url, 'args' => $args);
        foreach (self::$http_responses as $needle => &$queue) {
            if (strpos($url, $needle) !== false && !empty($queue)) {
                return count($queue) > 1 ? array_shift($queue) : $queue[0];
            }
        }
        return new WP_Error('no_stub', 'No stubbed response for ' . $url);
    }

    /** True if any log line contains $needle. */
    public static function logged($needle) {
        foreach (self::$logs as $line) {
            if (strpos($line, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

// ---------------------------------------------------------------------------
// Plugin statics
// ---------------------------------------------------------------------------

if (!class_exists('Azure_Settings')) {
    class Azure_Settings {
        public static function get_all_settings() {
            return WP_Shim::$settings;
        }
        public static function get_setting($key, $default = '') {
            return array_key_exists($key, WP_Shim::$settings) ? WP_Shim::$settings[$key] : $default;
        }
        public static function is_module_enabled($module) {
            return (bool) self::get_setting("enable_{$module}", false);
        }
        public static function get_credentials($module) {
            return array(
                'client_id'     => self::get_setting('client_id', 'test-client'),
                'client_secret' => self::get_setting('client_secret', 'test-secret'),
                'tenant_id'     => self::get_setting('tenant_id', 'test-tenant'),
            );
        }
    }
}

if (!class_exists('Azure_Database')) {
    class Azure_Database {
        public static function get_table_name($key) {
            global $wpdb;
            $prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';
            return $prefix . 'azure_' . $key;
        }
        public static function log_activity($module, $action, $type = null, $id = null, $data = array()) {
            return true;
        }
        public static function cleanup_old_records($days) { return true; }
    }
}

if (!class_exists('Azure_Logger')) {
    class Azure_Logger {
        public static function info($m, $c = array())    { WP_Shim::$logs[] = "INFO $m"; }
        public static function error($m, $c = array())   { WP_Shim::$logs[] = "ERROR $m"; }
        public static function warning($m, $c = array()) { WP_Shim::$logs[] = "WARN $m"; }
        public static function debug($m, $c = array())   { WP_Shim::$logs[] = "DEBUG $m"; }
        public static function debug_module($mod, $m, $c = array()) { WP_Shim::$logs[] = "DEBUG[$mod] $m"; }
        public static function is_initialized() { return true; }
    }
}

// ---------------------------------------------------------------------------
// WordPress function shims
// ---------------------------------------------------------------------------

function add_action($hook, $cb, $priority = 10, $args = 1) { return true; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { return true; }
function __($text, $domain = null) { return $text; }

function wp_timezone() {
    return new DateTimeZone(WP_Shim::$timezone);
}

function current_time($type = 'mysql', $gmt = 0) {
    $offset = (new DateTimeZone(WP_Shim::$timezone))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    if ($type === 'timestamp' || $type === 'U') {
        return $gmt ? time() : time() + $offset;
    }
    return gmdate('Y-m-d H:i:s', $gmt ? time() : time() + $offset);
}

function wp_get_schedules() {
    $out = array();
    foreach (WP_Shim::$schedules as $name => $interval) {
        $out[$name] = array('interval' => $interval, 'display' => $name);
    }
    // Mirror the single production `cron_schedules` filter so tests see the
    // same recurrence names the live site does.
    if (class_exists('Azure_PTA_Cron')) {
        $out = Azure_PTA_Cron::register_intervals($out);
    }
    return $out;
}

function wp_json_encode($data, $flags = 0, $depth = 512) {
    return json_encode($data, $flags, $depth);
}

function get_date_from_gmt($gmt_string, $format = 'Y-m-d H:i:s') {
    $dt = new DateTimeImmutable($gmt_string, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(WP_Shim::$timezone))->format($format);
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    if (!isset(wp_get_schedules()[$recurrence])) {
        // Matches core: an unregistered recurrence is refused.
        return false;
    }
    WP_Shim::$cron[$hook] = array(
        'timestamp'  => (int) $timestamp,
        'recurrence' => $recurrence,
        'args'       => $args,
    );
    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = array()) {
    WP_Shim::$cron[$hook] = array('timestamp' => (int) $timestamp, 'recurrence' => null, 'args' => $args);
    return true;
}

function wp_next_scheduled($hook, $args = array()) {
    return isset(WP_Shim::$cron[$hook]) ? WP_Shim::$cron[$hook]['timestamp'] : false;
}

function wp_clear_scheduled_hook($hook, $args = array()) {
    unset(WP_Shim::$cron[$hook]);
    return true;
}

function get_option($name, $default = false) {
    return array_key_exists($name, WP_Shim::$options) ? WP_Shim::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null) {
    WP_Shim::$options[$name] = $value;
    return true;
}

function delete_option($name) {
    unset(WP_Shim::$options[$name]);
    return true;
}

function get_post_meta($post_id, $key = '', $single = false) {
    if (!isset(WP_Shim::$post_meta[$post_id][$key])) {
        return $single ? '' : array();
    }
    $value = WP_Shim::$post_meta[$post_id][$key];
    return $single ? $value : array($value);
}

function update_post_meta($post_id, $key, $value) {
    WP_Shim::$post_meta[$post_id][$key] = $value;
    return true;
}

function get_bloginfo($field = 'name') { return 'Test Site'; }
function get_site_url() { return 'https://example.test'; }
function get_home_url() { return 'https://example.test'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function size_format($bytes, $decimals = 0) { return $bytes . 'B'; }
function sanitize_title($t) { return strtolower(preg_replace('/[^A-Za-z0-9\-]+/', '-', $t)); }
function sanitize_file_name($f) { return $f; }
function wp_max_upload_size() { return 256 * 1024 * 1024; }
function wp_upload_dir() {
    return array(
        'basedir' => '/tmp/wp-uploads',
        'baseurl' => 'https://example.test/wp-content/uploads',
    );
}

// ---------------------------------------------------------------------------
// Transients
// ---------------------------------------------------------------------------

function get_transient($key) {
    if (!isset(WP_Shim::$transients[$key])) {
        return false;
    }
    list($value, $expires) = WP_Shim::$transients[$key];
    if ($expires !== 0 && $expires <= time()) {
        unset(WP_Shim::$transients[$key]);
        return false;
    }
    return $value;
}

function set_transient($key, $value, $ttl = 0) {
    WP_Shim::$transients[$key] = array($value, $ttl > 0 ? time() + $ttl : 0);
    WP_Shim::$transient_ttls[$key] = $ttl;
    return true;
}

function delete_transient($key) {
    unset(WP_Shim::$transients[$key]);
    return true;
}

// ---------------------------------------------------------------------------
// HTTP API — responses are queued by the test via WP_Shim::$http_responses,
// keyed by a substring of the request URL.
// ---------------------------------------------------------------------------

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_post($url, $args = array()) { return WP_Shim::http($url, $args); }
function wp_remote_get($url, $args = array()) { return WP_Shim::http($url, $args); }
function wp_remote_request($url, $args = array()) { return WP_Shim::http($url, $args); }

function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }

// ---------------------------------------------------------------------------
// Fake $wpdb — models the handful of operations the classes under test use.
// $wpdb->replace() is deliberately a full row replacement (DELETE + INSERT),
// matching MySQL, so tests can catch columns being silently blanked.
// ---------------------------------------------------------------------------

class Fake_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $insert_id = 0;
    public $last_error = '';

    /** @var array table => list of row arrays */
    public $tables = array();
    /** @var array Ordered log of every write, for assertions. */
    public $writes = array();
    /** @var array Args from the most recent prepare() call. */
    public $prepare_args = array();
    /** @var array table => next AUTO_INCREMENT value */
    public $next_ids = array();

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $this->prepare_args = $args;
        return $sql;
    }

    public function esc_like($text) { return addcslashes($text, '_%\\'); }

    private function &table($name) {
        if (!isset($this->tables[$name])) {
            $this->tables[$name] = array();
        }
        return $this->tables[$name];
    }

    private function table_from_sql($sql) {
        foreach (array_keys($this->tables) as $name) {
            if (strpos($sql, $name) !== false) {
                return $name;
            }
        }
        if (preg_match('/FROM\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Evaluate the small subset of WHERE syntax these classes actually use:
     * `col = %s` / `col LIKE %s` placeholders bound in order of appearance,
     * and the `(col IS NULL OR col = 0)` "not yet set" guard.
     */
    private function row_matches($row, $sql, array $bind) {
        if (preg_match_all('/\(\s*([A-Za-z0-9_]+)\s+IS\s+NULL\s+OR\s+\1\s*=\s*0\s*\)/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                if (!empty($row[$col])) {
                    return false;
                }
            }
        }

        if (!empty($bind) && preg_match_all('/([A-Za-z0-9_.]+)\s*(?:=|LIKE)\s*%[sdf]/i', $sql, $bm)) {
            foreach ($bm[1] as $i => $qualified) {
                if (!array_key_exists($i, $bind)) {
                    break;
                }
                $dot = strrpos($qualified, '.');
                $col = $dot === false ? $qualified : substr($qualified, $dot + 1);
                if (!array_key_exists($col, $row) || (string) $row[$col] !== (string) $bind[$i]) {
                    return false;
                }
            }
        }

        return true;
    }

    public function get_row($sql, $output = null) {
        // prepare() args apply to exactly one query; an unprepared query is
        // unfiltered (e.g. the "ORDER BY updated_at DESC LIMIT 1" fallback).
        $args = $this->prepare_args;
        $this->prepare_args = array();

        $name = $this->table_from_sql($sql);
        if ($name === null) return null;

        foreach ($this->table($name) as $row) {
            if ($this->row_matches($row, $sql, $args)) {
                return (object) $row;
            }
        }

        return null;
    }

    public function get_var($sql) {
        $column = preg_match('/SELECT\s+([A-Za-z0-9_]+)\s+FROM/i', $sql, $m) ? $m[1] : null;
        $row = $this->get_row($sql);
        if (!$row) return null;
        if ($column !== null) {
            return $row->{$column} ?? null;
        }
        $values = array_values((array) $row);
        return $values[0] ?? null;
    }

    public function get_results($sql, $output = null) {
        $name = $this->table_from_sql($sql);
        if ($name === null) return array();
        return array_map(function ($r) { return (object) $r; }, $this->table($name));
    }

    /** Emulate AUTO_INCREMENT so `SELECT id FROM …` works after a write. */
    private function next_id($table) {
        if (!isset($this->next_ids[$table])) {
            $this->next_ids[$table] = 1;
        }
        return $this->next_ids[$table]++;
    }

    public function replace($table, $data, $format = null) {
        $this->writes[] = array('op' => 'replace', 'table' => $table, 'data' => $data);
        $rows = &$this->table($table);
        $key = $data['user_email'] ?? null;
        if ($key !== null) {
            foreach ($rows as $i => $row) {
                if (($row['user_email'] ?? null) === $key) {
                    // Full replacement, not a merge — this is the point.
                    $data['id'] = $row['id'] ?? $this->next_id($table);
                    $rows[$i] = $data;
                    return 1;
                }
            }
        }
        $data['id'] = $this->next_id($table);
        $rows[] = $data;
        $this->insert_id = $data['id'];
        return 1;
    }

    public function insert($table, $data, $format = null) {
        $this->writes[] = array('op' => 'insert', 'table' => $table, 'data' => $data);
        $rows = &$this->table($table);
        $data['id'] = $this->next_id($table);
        $rows[] = $data;
        $this->insert_id = $data['id'];
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->writes[] = array('op' => 'update', 'table' => $table, 'data' => $data, 'where' => $where);
        $rows = &$this->table($table);
        $changed = 0;
        foreach ($rows as $i => $row) {
            $match = true;
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || (string) $row[$col] !== (string) $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $rows[$i] = array_merge($row, $data);
                $changed++;
            }
        }
        return $changed;
    }

    public function delete($table, $where, $where_format = null) {
        $this->writes[] = array('op' => 'delete', 'table' => $table, 'where' => $where);
        $rows = &$this->table($table);
        $before = count($rows);
        $rows = array_values(array_filter($rows, function ($row) use ($where) {
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || (string) $row[$col] !== (string) $val) {
                    return true;
                }
            }
            return false;
        }));
        return $before - count($rows);
    }

    public function get_charset_collate() { return ''; }
}

// ---------------------------------------------------------------------------
// Assertion helpers
// ---------------------------------------------------------------------------

class TestRunner {
    private $failures = 0;
    private $total = 0;
    private $title;

    public function __construct($title) {
        $this->title = $title;
        echo "== {$title} ==\n";
    }

    public function check($ok, $label, $detail = '') {
        $this->total++;
        if (!$ok) {
            $this->failures++;
        }
        printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ({$detail})" : '');
        return $ok;
    }

    /** Record an observation that is a known limitation, not a pass/fail. */
    public function note($text) {
        printf("[NOTE] %s\n", $text);
    }

    public function equals($expected, $actual, $label) {
        $ok = ($expected === $actual);
        return $this->check($ok, $label, $ok ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public function finish() {
        printf("\n%d/%d checks passed in %s.\n\n", $this->total - $this->failures, $this->total, $this->title);
        return $this->failures;
    }
}

/**
 * Inject a private/protected property for testing (e.g. a stub collaborator).
 */
function write_private($object, $property, $value) {
    $ref = new ReflectionProperty(get_class($object), $property);
    if (PHP_VERSION_ID < 80100) {
        $ref->setAccessible(true);
    }
    $ref->setValue($object, $value);
}

/**
 * Read a private/protected property for testing.
 */
function read_private($object, $property) {
    $ref = new ReflectionProperty(get_class($object), $property);
    if (PHP_VERSION_ID < 80100) {
        $ref->setAccessible(true);
    }
    return $ref->getValue($object);
}

/**
 * Call a private/protected method for testing.
 */
function call_private($object, $method, array $args = array()) {
    $ref = new ReflectionMethod(is_string($object) ? $object : get_class($object), $method);
    if (PHP_VERSION_ID < 80100) {
        $ref->setAccessible(true);
    }
    return $ref->invokeArgs(is_string($object) ? null : $object, $args);
}
