<?php
/**
 * PTA Tools Email Router
 *
 * Pre-wp_mail interceptor (priority 1) that routes each outgoing
 * wp_mail() through a configured provider based on its `From:`
 * header. Replaces the historical "one transport wins all of
 * wp_mail" model with a per-service routing table:
 *
 *   Newsletter (AcyMailing)            news@wilderptsa.net   provider=acy
 *   WooCommerce shop                   shop@wilderptsa.net   provider=graph
 *   WordPress core / default (*)       info@wilderptsa.net   provider=graph
 *
 * Storage:  wp_options.azure_email_routing  (versioned JSON).
 *
 * Order matters. The router walks the rules top to bottom and the
 * first matching `from_match` wins; `*` is a catch-all that should
 * stay at the bottom (the seeded "wpcore" row is marked
 * `is_default = true` for this reason and protected from deletion).
 *
 * Dispatch behaviour per provider:
 *   - graph   -> Azure_Email_Mailer::send_email_graph (per-call From)
 *   - acs     -> Azure_Email_Mailer::send_email_acs   (per-call From)
 *   - acy     -> pass-through (returns null so AcyMailing's own
 *                pre_wp_mail or native send pipeline keeps owning it)
 *   - wpmail  -> pass-through (default WP transport)
 *   - none    -> drop the email (returns true with no send;
 *                used for blocking-mode rows during incident response)
 *
 * Recursion guard: if a routed dispatch internally calls wp_mail()
 * (e.g. our Graph mailer falls back to wp_mail on token failure),
 * the router skips routing on the nested call. Without this we
 * would loop forever.
 *
 * Always-log: every routed dispatch is recorded in
 * wp_azure_email_logs BEFORE the actual send. Fills the visibility
 * gap that pre_wp_mail short-circuits create for the existing
 * Azure_Email_Logger (which hooks the later `wp_mail` filter and
 * never fires when we short-circuit).
 *
 * @package AzurePlugin
 * @since   3.123
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Router {

    const OPTION_KEY = 'azure_email_routing';
    const STORAGE_VERSION = 1;

    /** Reentrancy guard. */
    private static $is_routing = false;

    /** @var bool */
    private static $bootstrapped = false;

    /**
     * Bootstrap on plugins_loaded priority 1 so we beat AcyMailing
     * and the "ACS App Service email plugin" (both typically at
     * priority 10). Idempotent.
     */
    public static function bootstrap() {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        // Routing engine. Priority 1 so it runs ahead of all other
        // pre_wp_mail filters added at the default priority.
        add_filter('pre_wp_mail', array(__CLASS__, 'route'), 1, 2);

        // AJAX handlers for the Sending admin tab.
        add_action('wp_ajax_azure_email_routing_save',         array(__CLASS__, 'ajax_save_routing'));
        add_action('wp_ajax_azure_email_routing_test_row',     array(__CLASS__, 'ajax_test_row'));
        add_action('wp_ajax_azure_email_routing_check_auth',   array(__CLASS__, 'ajax_check_auth'));
        add_action('wp_ajax_azure_email_routing_reset',        array(__CLASS__, 'ajax_reset_routing'));
        add_action('wp_ajax_azure_email_routing_strict_mode',  array(__CLASS__, 'ajax_strict_mode'));
    }

    // ---------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------

    /**
     * Load the current routing table. Returns the default seeded
     * table if no option is stored yet.
     *
     * @return array { version:int, routes:array<int,array> }
     */
    public static function get_routing() {
        $stored = get_option(self::OPTION_KEY, null);
        if (!is_array($stored) || !isset($stored['routes']) || !is_array($stored['routes'])) {
            return self::default_routing();
        }
        // Defensive: ensure default catch-all exists; if a previous
        // admin somehow deleted it, append one back so wp_mail never
        // falls into a "no route matched" black hole.
        $has_default = false;
        foreach ($stored['routes'] as $r) {
            if (!empty($r['is_default'])) { $has_default = true; break; }
        }
        if (!$has_default) {
            $stored['routes'][] = self::default_wpcore_row();
        }
        return $stored;
    }

    /**
     * Persist the routing table after light validation.
     *
     * @param array $routes
     * @return array Normalized stored payload.
     */
    public static function save_routing(array $routes) {
        $clean = array();
        $seen_default = false;
        foreach ($routes as $row) {
            if (!is_array($row)) continue;
            $id           = isset($row['id'])           ? sanitize_key((string) $row['id'])                                : wp_generate_password(8, false);
            $label        = isset($row['label'])        ? sanitize_text_field((string) $row['label'])                       : '';
            $from_match   = isset($row['from_match'])   ? trim(sanitize_text_field((string) $row['from_match']))            : '';
            $from_address = isset($row['from_address']) ? sanitize_email((string) $row['from_address'])                     : '';
            $from_name    = isset($row['from_name'])    ? sanitize_text_field((string) $row['from_name'])                   : '';
            $reply_to     = isset($row['reply_to'])     ? sanitize_email((string) $row['reply_to'])                         : '';
            $provider     = isset($row['provider'])     ? sanitize_key((string) $row['provider'])                            : 'graph';
            $enabled      = !empty($row['enabled']);
            $is_default   = !empty($row['is_default']);
            if ($is_default) {
                $seen_default = true;
                // Catch-all defaults always match everything.
                $from_match = '*';
            }
            if (!in_array($provider, array('graph', 'acs', 'acy', 'wpmail', 'none'), true)) {
                $provider = 'graph';
            }
            $clean[] = compact('id', 'label', 'from_match', 'from_address', 'from_name', 'reply_to', 'provider', 'enabled', 'is_default');
        }
        if (!$seen_default) {
            $clean[] = self::default_wpcore_row();
        }
        $payload = array(
            'version' => self::STORAGE_VERSION,
            'routes'  => $clean,
            'saved_at'=> current_time('mysql'),
        );
        update_option(self::OPTION_KEY, $payload, false);
        return $payload;
    }

    /**
     * Reset to the seeded defaults.
     */
    public static function reset_routing() {
        delete_option(self::OPTION_KEY);
        return self::get_routing();
    }

    /** Seeded default table matching the user's spec. */
    private static function default_routing() {
        return array(
            'version' => self::STORAGE_VERSION,
            'routes' => array(
                array(
                    'id'           => 'newsletter',
                    'label'        => 'Newsletter (AcyMailing)',
                    'from_match'   => 'news@',
                    'from_address' => 'news@' . self::default_domain(),
                    'from_name'    => 'Wilder PTSA Newsletter',
                    'reply_to'     => '',
                    'provider'     => 'acy',
                    'enabled'      => true,
                    'is_default'   => false,
                ),
                array(
                    'id'           => 'shop',
                    'label'        => 'WooCommerce shop',
                    'from_match'   => 'shop@' . self::default_domain(),
                    'from_address' => 'shop@' . self::default_domain(),
                    'from_name'    => 'Wilder PTSA Shop',
                    'reply_to'     => '',
                    'provider'     => 'graph',
                    'enabled'      => true,
                    'is_default'   => false,
                ),
                self::default_wpcore_row(),
            ),
            'saved_at' => null,
        );
    }

    private static function default_wpcore_row() {
        return array(
            'id'           => 'wpcore',
            'label'        => 'WordPress core / default',
            'from_match'   => '*',
            'from_address' => 'info@' . self::default_domain(),
            'from_name'    => 'Wilder PTSA',
            'reply_to'     => '',
            'provider'     => 'graph',
            'enabled'      => true,
            'is_default'   => true,
        );
    }

    private static function default_domain() {
        // Derive from site URL; admin can override per row in the UI.
        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
        $host = preg_replace('/^www\./', '', $host);
        return $host;
    }

    // ---------------------------------------------------------------
    // Pre-wp_mail interceptor
    // ---------------------------------------------------------------

    /**
     * @param mixed $null
     * @param array $atts {to, subject, message, headers, attachments}
     * @return null|true Null = let WP/other filters continue; true = short-circuit.
     */
    public static function route($null, $atts) {
        // Reentrancy: skip routing on the inner wp_mail call from
        // inside a dispatch handler.
        if (self::$is_routing) {
            return $null;
        }
        if (!is_array($atts) || empty($atts['to'])) {
            return $null;
        }

        $routing = self::get_routing();
        $routes  = isset($routing['routes']) ? $routing['routes'] : array();
        if (empty($routes)) {
            return $null;
        }

        $from_email = self::extract_from_email($atts['headers'] ?? array());

        $match = self::pick_route($routes, $from_email);
        if (!$match) {
            return $null;
        }

        // Disabled rows still match (and block downstream routes),
        // but act as a no-op pass-through.
        if (empty($match['enabled'])) {
            return $null;
        }

        $provider = $match['provider'];

        // ACY + wpmail intentionally do NOT short-circuit — let the
        // downstream handler (AcyMailing's pre_wp_mail, or WP default)
        // continue. We still log so admins can see the routing
        // decision in the email log.
        if ($provider === 'acy' || $provider === 'wpmail') {
            self::log_routed($atts, $match, 'passthrough', '');
            return $null;
        }

        // "none" = drop. Useful as an emergency kill switch on a row.
        if ($provider === 'none') {
            self::log_routed($atts, $match, 'dropped', 'route provider=none');
            return true;
        }

        // graph / acs short-circuit dispatch.
        self::$is_routing = true;
        try {
            $ok      = false;
            $err     = '';
            $headers = self::normalize_headers($atts['headers'] ?? array(), $match);
            $atts['headers'] = $headers; // make the recursion guard see the rewritten headers

            // Email module may be disabled (enable_email=false) which
            // skips the normal Email module bootstrap. Force-load the
            // mailer AND its auth dependency so the router works
            // independently of the module toggle. The mailer's
            // constructor logs "Authentication service not available"
            // when Azure_Email_Auth is missing — that was the v3.123
            // silent-failure mode.
            if (!class_exists('Azure_Email_Auth')) {
                $auth_path = AZURE_PLUGIN_PATH . 'includes/class-email-auth.php';
                if (file_exists($auth_path)) {
                    require_once $auth_path;
                }
            }
            if (!class_exists('Azure_Email_Mailer')) {
                $mailer_path = AZURE_PLUGIN_PATH . 'includes/class-email-mailer.php';
                if (file_exists($mailer_path)) {
                    require_once $mailer_path;
                }
            }

            if (!class_exists('Azure_Email_Mailer')) {
                self::log_routed($atts, $match, 'failed', 'Azure_Email_Mailer file missing');
                return $null;
            }
            if (!class_exists('Azure_Email_Auth')) {
                self::log_routed($atts, $match, 'failed', 'Azure_Email_Auth file missing (cannot authenticate to Graph/ACS)');
                return self::strict_mode() ? true : $null;
            }

            $mailer = new Azure_Email_Mailer();

            // Mark "begin send" so we can read the last [Email] log
            // line back out as the failure reason if the send fails
            // silently. Azure_Email_Mailer returns bool, not a result
            // struct, so this is the most reliable way to capture the
            // real cause without a wider refactor.
            $log_mark_before = self::current_log_tail_marker();

            if ($provider === 'graph') {
                $ok = (bool) $mailer->send_email_graph(
                    $atts['to'],
                    isset($atts['subject']) ? (string) $atts['subject'] : '',
                    isset($atts['message']) ? (string) $atts['message'] : '',
                    $headers,
                    isset($atts['attachments']) ? (array) $atts['attachments'] : array(),
                    $match['from_address'] // per-call From
                );
            } elseif ($provider === 'acs') {
                $ok = (bool) $mailer->send_email_acs(
                    $atts['to'],
                    isset($atts['subject']) ? (string) $atts['subject'] : '',
                    isset($atts['message']) ? (string) $atts['message'] : '',
                    $headers,
                    isset($atts['attachments']) ? (array) $atts['attachments'] : array(),
                    $match['from_address']
                );
            }

            if (!$ok) {
                $err = self::extract_recent_email_error($log_mark_before);
                if ($err === '') {
                    $err = 'send_email_' . $provider . ' returned false (no error captured)';
                }
            }

            self::log_routed($atts, $match, $ok ? 'sent' : 'failed', $err);

            // Decision on failure:
            //  - strict mode: return true (short-circuit; no fallback,
            //    so the email-logs row is the clean source of truth).
            //  - permissive (default): return null so other interceptors
            //    or default wp_mail can attempt delivery. CAVEAT: if
            //    another pre_wp_mail interceptor (e.g. the ACS App
            //    Service email plugin) is installed and forces its
            //    own sender, recipients will see a different From
            //    than the route configured. That's the v3.123
            //    surprise — fix by enabling strict mode OR removing
            //    competing interceptors.
            if ($ok) {
                return true;
            }
            return self::strict_mode() ? true : $null;
        } catch (\Throwable $e) {
            self::log_routed($atts, $match, 'failed', $e->getMessage());
            return self::strict_mode() ? true : $null;
        } finally {
            self::$is_routing = false;
        }
    }

    /**
     * Strict-mode toggle. Stored in `wp_options.azure_email_router_strict`.
     * Defaults to false (permissive). When true, failed dispatches
     * short-circuit instead of falling through to other interceptors.
     */
    public static function strict_mode() {
        return (bool) get_option('azure_email_router_strict', false);
    }

    /**
     * Read the current end-of-log marker so we can grab anything
     * Azure_Logger writes between now and after a send attempt.
     * Returns the byte count of the log file, or null when the log
     * isn't accessible from PHP.
     */
    private static function current_log_tail_marker() {
        $path = defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH . 'logs.txt' : '';
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        return $size === false ? null : (int) $size;
    }

    /**
     * Tail logs.txt from $marker onwards and return the most recent
     * [Email] ERROR line. Used to enrich the email_logs row when a
     * send returns false without surfacing the underlying error.
     *
     * @param int|null $marker  Byte offset captured BEFORE the send.
     * @return string           Error message, or empty string when none.
     */
    private static function extract_recent_email_error($marker) {
        $path = defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH . 'logs.txt' : '';
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $size = @filesize($path);
        if ($size === false) {
            return '';
        }
        // Read at most the trailing 16KB regardless of marker — keeps
        // us under PHP's memory ceiling for huge log files.
        $start = max(0, ($marker !== null ? (int) $marker : ($size - 16384)));
        $start = min($start, max(0, $size - 16384));
        $len   = $size - $start;
        if ($len <= 0) {
            return '';
        }
        $fp = @fopen($path, 'r');
        if (!$fp) {
            return '';
        }
        fseek($fp, $start);
        $chunk = fread($fp, $len);
        fclose($fp);
        if (!is_string($chunk) || $chunk === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $chunk);
        // Walk backwards looking for the most recent Email error.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            if (stripos($line, '[Email]') !== false && stripos($line, 'ERROR') !== false) {
                // Strip the timestamp prefix "MM-DD-YYYY HH:MM:SS " for brevity.
                $line = preg_replace('/^\d{2}-\d{2}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+/', '', $line);
                return mb_substr($line, 0, 800);
            }
        }
        return '';
    }

    /**
     * Extract `addr@x` from a `From:` header (or array of headers).
     *
     * @param string|array $headers
     * @return string Lowercased email, or empty if no From header.
     */
    public static function extract_from_email($headers) {
        if (is_string($headers)) {
            $headers = preg_split('/\r?\n/', $headers);
        }
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $h) {
            if (!is_string($h)) continue;
            if (!preg_match('/^\s*from\s*:\s*(.+)$/i', $h, $m)) continue;
            $value = trim($m[1]);
            if (preg_match('/<([^>]+)>/', $value, $am)) {
                return strtolower(trim($am[1]));
            }
            return strtolower(trim($value));
        }
        return '';
    }

    /**
     * First matching, enabled route wins. `*` is the catch-all.
     * Empty `from_match` rows (e.g. the AcyMailing newsletter
     * documentation row) never match wp_mail traffic — they are
     * informational placeholders for the admin UI only.
     */
    private static function pick_route(array $routes, $from_email) {
        $from = strtolower((string) $from_email);
        foreach ($routes as $r) {
            $m = isset($r['from_match']) ? strtolower(trim((string) $r['from_match'])) : '';
            if ($m === '') continue;
            if ($m === '*') return $r;
            if ($m === $from) return $r;
            // Prefix-with-trailing-@ (e.g. "news@") matches the local-part shape
            if (substr($m, -1) === '@' && $from !== '' && strpos($from, $m) === 0) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Rewrite the headers array so the From header matches the
     * route's configured from_address + from_name. Preserves all
     * other headers verbatim.
     */
    private static function normalize_headers($headers, array $route) {
        if (is_string($headers)) {
            $headers = $headers === '' ? array() : preg_split('/\r?\n/', $headers);
        }
        if (!is_array($headers)) {
            $headers = array();
        }
        // Strip any existing From: / Reply-To: lines.
        $headers = array_values(array_filter($headers, function ($h) {
            if (!is_string($h)) return true;
            $hl = strtolower($h);
            return !(strpos($hl, 'from:') === 0 || strpos($hl, 'reply-to:') === 0);
        }));
        $from = $route['from_address'];
        if (!empty($route['from_name'])) {
            $from = sprintf('"%s" <%s>', str_replace('"', '', $route['from_name']), $route['from_address']);
        }
        $headers[] = 'From: ' . $from;
        if (!empty($route['reply_to'])) {
            $headers[] = 'Reply-To: ' . $route['reply_to'];
        }
        return $headers;
    }

    /**
     * Write a routed-send row to wp_azure_email_logs so admins can
     * see who routed where, even when the dispatch short-circuits
     * before the regular Azure_Email_Logger fires.
     */
    private static function log_routed(array $atts, array $route, $status, $error_message) {
        if (!class_exists('Azure_Database')) return;
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) return;
        global $wpdb;
        $to = is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'];
        $message = isset($atts['message']) ? (string) $atts['message'] : '';
        $headers = isset($atts['headers']) ? (is_array($atts['headers']) ? implode("\n", $atts['headers']) : (string) $atts['headers']) : '';
        $attachments = isset($atts['attachments']) ? (is_array($atts['attachments']) ? wp_json_encode($atts['attachments']) : (string) $atts['attachments']) : '';
        $wpdb->insert($table, array(
            'to_email'      => $to,
            'from_email'    => $route['from_address'],
            'subject'       => isset($atts['subject']) ? (string) $atts['subject'] : '',
            'message'       => $message,
            'headers'       => $headers,
            'attachments'   => $attachments,
            'method'        => 'router-' . $route['provider'],
            'status'        => $status,
            'error_message' => $error_message,
            'plugin_source' => 'router:' . $route['id'],
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
    }

    // ---------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------

    private static function guard_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized'); return false;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce'); return false;
        }
        return true;
    }

    public static function ajax_save_routing() {
        if (!self::guard_ajax()) return;
        $raw = isset($_POST['routes']) ? wp_unslash($_POST['routes']) : '';
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            wp_send_json_error('Routes payload must be a JSON array');
        }
        $saved = self::save_routing($decoded);
        wp_send_json_success($saved);
    }

    public static function ajax_reset_routing() {
        if (!self::guard_ajax()) return;
        wp_send_json_success(self::reset_routing());
    }

    public static function ajax_strict_mode() {
        if (!self::guard_ajax()) return;
        $enabled = isset($_POST['enabled']) && ($_POST['enabled'] === '1' || $_POST['enabled'] === 'true');
        update_option('azure_email_router_strict', $enabled ? 1 : 0, false);
        wp_send_json_success(array('strict_mode' => $enabled));
    }

    /**
     * Test a single row by issuing a real wp_mail() with the row's
     * From + a sample body, then reading back the email-log row that
     * either we or the regular logger captured.
     *
     * Body params: row_id (required), to (optional; defaults to current admin email)
     */
    public static function ajax_test_row() {
        if (!self::guard_ajax()) return;
        $row_id = isset($_POST['row_id']) ? sanitize_key((string) $_POST['row_id']) : '';
        $to     = isset($_POST['to']) ? sanitize_email((string) $_POST['to']) : '';
        if ($to === '') {
            $current = wp_get_current_user();
            $to      = $current && $current->user_email ? $current->user_email : get_option('admin_email');
        }
        if (!is_email($to)) {
            wp_send_json_error('Invalid recipient email');
        }

        $routing = self::get_routing();
        $route   = null;
        foreach ($routing['routes'] as $r) {
            if (($r['id'] ?? '') === $row_id) { $route = $r; break; }
        }
        if (!$route) {
            wp_send_json_error('Route not found');
        }

        $subject = sprintf(
            'PTA Tools routing test [%s] %s',
            $route['id'],
            current_time('H:i:s')
        );
        $body = '<!DOCTYPE html><html><body style="font-family:-apple-system,sans-serif;line-height:1.5;color:#1d2327;">' .
                '<h2>PTA Tools email routing test</h2>' .
                '<p>Sent through route: <strong>' . esc_html($route['label']) . '</strong></p>' .
                '<ul>' .
                '<li>Route id: <code>' . esc_html($route['id']) . '</code></li>' .
                '<li>Provider: <code>' . esc_html($route['provider']) . '</code></li>' .
                '<li>From: <code>' . esc_html($route['from_address']) . '</code></li>' .
                '<li>Reply-To: <code>' . esc_html($route['reply_to'] ?: '(none)') . '</code></li>' .
                '</ul>' .
                '<p style="color:#646970;font-size:12px;">Sent at ' . esc_html(current_time('mysql')) . '</p>' .
                '</body></html>';

        $from = !empty($route['from_name'])
            ? sprintf('"%s" <%s>', $route['from_name'], $route['from_address'])
            : $route['from_address'];
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . $from);

        $start = microtime(true);
        $ok    = wp_mail($to, $subject, $body, $headers);
        $ms    = (int) ((microtime(true) - $start) * 1000);

        // Best-effort log lookup
        $logged = null;
        if (class_exists('Azure_Database')) {
            global $wpdb;
            $log_table = Azure_Database::get_table_name('email_logs');
            if ($log_table) {
                $logged = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, method, status, from_email, to_email, error_message, LENGTH(message) AS body_bytes
                     FROM {$log_table}
                     WHERE to_email = %s AND subject = %s
                     ORDER BY id DESC LIMIT 1",
                    $to, $subject
                ), ARRAY_A);
            }
        }

        wp_send_json_success(array(
            'route_id'   => $route['id'],
            'provider'   => $route['provider'],
            'to'         => $to,
            'from'       => $route['from_address'],
            'wp_mail_ok' => (bool) $ok,
            'elapsed_ms' => $ms,
            'log_row'    => $logged,
        ));
    }

    /**
     * Verify the configured From mailbox can actually be used by
     * the chosen provider. Returns a badge-friendly result.
     *
     * Body params: row_id
     */
    public static function ajax_check_auth() {
        if (!self::guard_ajax()) return;
        $row_id = isset($_POST['row_id']) ? sanitize_key((string) $_POST['row_id']) : '';
        $routing = self::get_routing();
        $route   = null;
        foreach ($routing['routes'] as $r) {
            if (($r['id'] ?? '') === $row_id) { $route = $r; break; }
        }
        if (!$route) {
            wp_send_json_error('Route not found');
        }

        $provider = $route['provider'];
        $result = array(
            'route_id' => $route['id'],
            'provider' => $provider,
            'state'    => 'unknown',
            'message'  => '',
        );

        if ($provider === 'graph') {
            // Try to acquire an app token AND probe the From mailbox.
            if (!class_exists('Azure_Email_Auth')) {
                $auth_path = AZURE_PLUGIN_PATH . 'includes/class-email-auth.php';
                if (file_exists($auth_path)) require_once $auth_path;
            }
            if (!class_exists('Azure_Email_Auth')) {
                $result['state']   = 'error';
                $result['message'] = 'Email Auth class not loaded; enable the Email module first.';
                wp_send_json_success($result);
            }
            try {
                $auth  = new Azure_Email_Auth();
                $token = method_exists($auth, 'get_user_access_token') ? $auth->get_user_access_token($route['from_address']) : null;
                if (!$token && method_exists($auth, 'get_app_access_token')) {
                    $token = $auth->get_app_access_token();
                }
                if (!$token) {
                    $result['state']   = 'error';
                    $result['message'] = 'No token (user not signed in AND no app credentials configured)';
                    wp_send_json_success($result);
                }
                $probe = wp_remote_get('https://graph.microsoft.com/v1.0/users/' . rawurlencode($route['from_address']),
                    array('headers' => array('Authorization' => 'Bearer ' . $token), 'timeout' => 15));
                if (is_wp_error($probe)) {
                    $result['state']   = 'error';
                    $result['message'] = 'Graph probe network error: ' . $probe->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($probe);
                    if ($code === 200) {
                        $result['state']   = 'ok';
                        $result['message'] = 'Graph can address ' . $route['from_address'];
                    } elseif ($code === 404) {
                        $result['state']   = 'error';
                        $result['message'] = 'Mailbox not found in tenant (404)';
                    } elseif ($code === 403) {
                        $result['state']   = 'error';
                        $result['message'] = 'No Send-As permission (403). Grant Send-As on this shared mailbox to the OAuth user in M365 admin.';
                    } else {
                        $result['state']   = 'warn';
                        $result['message'] = 'Graph probe returned ' . $code . ' (sends may still work)';
                    }
                }
            } catch (\Throwable $e) {
                $result['state']   = 'error';
                $result['message'] = $e->getMessage();
            }
            wp_send_json_success($result);
        }

        if ($provider === 'acs') {
            $result['state']   = 'info';
            $result['message'] = 'Verify the From address is a registered sender username on the ACS verified domain (Azure portal -> Email Communication Services -> Domain -> Sender usernames). Live verification from WP not implemented.';
            wp_send_json_success($result);
        }

        if ($provider === 'acy') {
            $result['state']   = 'info';
            $result['message'] = 'Managed natively by AcyMailing. Verify in AcyMailing -> Configuration -> Mail settings.';
            wp_send_json_success($result);
        }

        if ($provider === 'wpmail') {
            $result['state']   = 'info';
            $result['message'] = 'Passes through to WP default wp_mail (PHP mail() or whatever else intercepts pre_wp_mail).';
            wp_send_json_success($result);
        }

        if ($provider === 'none') {
            $result['state']   = 'warn';
            $result['message'] = 'Routing provider is "none" — emails matching this row will be silently dropped.';
            wp_send_json_success($result);
        }

        wp_send_json_success($result);
    }
}
