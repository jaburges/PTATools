<?php
/**
 * AI Connectors audit log: one row per tools/call.
 *
 * Arguments are summarised, never stored whole: anything that looks like a
 * credential is dropped, long text is reduced to its length, and email
 * addresses are masked the same way the plugin's own logs mask them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Audit {

    const STATUS_OK       = 'ok';
    const STATUS_ERROR    = 'error';
    const STATUS_DENIED   = 'denied';
    const STATUS_CONFIRM  = 'confirm_required';
    const STATUS_INVALID  = 'invalid';

    const SECRET_KEYS = '/pass|secret|token|api[_-]?key|authori[sz]ation|cookie|card|cvv|iban|ssn/i';
    const TEXT_KEYS   = '/^(content|description|short_description|body|excerpt|html|note|message)$/i';

    public static function table() {
        return Azure_Database::get_table_name('ai_audit');
    }

    /**
     * @param array $entry user_id, token_id, client_label, tool, args, status, message, duration_ms
     */
    public static function log(array $entry) {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $wpdb->insert(self::table(), array(
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'user_id'      => (int) ($entry['user_id'] ?? 0),
            'token_id'     => (int) ($entry['token_id'] ?? 0),
            'client_label' => mb_substr((string) ($entry['client_label'] ?? ''), 0, 190),
            'tool'         => mb_substr((string) ($entry['tool'] ?? ''), 0, 100),
            'args_summary' => self::summarize_args($entry['args'] ?? array()),
            'status'       => mb_substr((string) ($entry['status'] ?? self::STATUS_OK), 0, 20),
            'message'      => mb_substr(self::mask((string) ($entry['message'] ?? '')), 0, 255),
            'duration_ms'  => (int) ($entry['duration_ms'] ?? 0),
            'ip_hash'      => $ip !== '' ? hash('sha256', $ip . (function_exists('wp_salt') ? wp_salt('nonce') : '')) : '',
        ));
    }

    /**
     * JSON summary of tool arguments, at most 2000 characters.
     *
     * @param mixed $args
     */
    public static function summarize_args($args) {
        $json = wp_json_encode(self::summarize_value($args, '', 0), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }
        return strlen($json) > 2000 ? substr($json, 0, 1990) . '…' : $json;
    }

    private static function summarize_value($value, $key, $depth) {
        if ($key !== '' && preg_match(self::SECRET_KEYS, (string) $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            if ($depth >= 3) {
                return '[' . count($value) . ' items]';
            }
            $out = array();
            $i = 0;
            foreach ($value as $k => $v) {
                if ($i++ >= 20) {
                    $out['…'] = (count($value) - 20) . ' more';
                    break;
                }
                $out[$k] = self::summarize_value($v, is_string($k) ? $k : $key, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            if ($key !== '' && preg_match(self::TEXT_KEYS, (string) $key)) {
                return '[' . mb_strlen($value) . ' chars]';
            }
            $value = self::mask($value);
            return mb_strlen($value) > 120 ? mb_substr($value, 0, 60) . '… [' . mb_strlen($value) . ' chars]' : $value;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        return '[' . gettype($value) . ']';
    }

    private static function mask($text) {
        return class_exists('Azure_Private_Storage') ? Azure_Private_Storage::mask_emails($text) : $text;
    }

    /**
     * @param array $filters user_id, tool, status
     * @return array{rows:object[],total:int}
     */
    public static function query(array $filters = array(), $page = 1, $per_page = 50) {
        global $wpdb;
        $where = array('1=1');
        $args = array();
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $args[] = (int) $filters['user_id'];
        }
        if (!empty($filters['tool'])) {
            $where[] = 'tool = %s';
            $args[] = (string) $filters['tool'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $args[] = (string) $filters['status'];
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where_sql;
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $rows_sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($args, array((int) $per_page, $offset))));
        return array('rows' => (array) $rows, 'total' => $total);
    }

    public static function purge($retention_days) {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, (int) $retention_days) * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff));
    }
}
