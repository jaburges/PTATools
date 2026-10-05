<?php
/**
 * Storage for the AI module: usage/cost log, post embeddings and cached
 * translations. Tables are created by dbDelta when the schema version
 * option is behind.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Store {

    const DB_VERSION = '1';
    const DB_VERSION_OPTION = 'pta_ai_db_version';

    private static $month_cost = null;

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'pta_ai_' . $name;
    }

    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('usage') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            feature varchar(32) NOT NULL,
            deployment varchar(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
            completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
            cost decimal(12,6) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY feature (feature)
        ) {$charset};");

        dbDelta("CREATE TABLE " . self::table('embeddings') . " (
            post_id bigint(20) unsigned NOT NULL,
            content_hash char(32) NOT NULL,
            dims smallint(5) unsigned NOT NULL,
            vector longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (post_id)
        ) {$charset};");

        dbDelta("CREATE TABLE " . self::table('translations') . " (
            post_id bigint(20) unsigned NOT NULL,
            lang varchar(12) NOT NULL,
            content_hash char(32) NOT NULL,
            title text NOT NULL,
            excerpt text NOT NULL,
            content longtext NOT NULL,
            summary text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (post_id,lang)
        ) {$charset};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function is_installed() {
        return get_option(self::DB_VERSION_OPTION) === self::DB_VERSION;
    }

    /**
     * Dollar cost of one call, from the per-million-token prices in settings.
     */
    public static function cost_of($deployment, $prompt_tokens, $completion_tokens, array $settings) {
        $is_embed = $deployment === (string) ($settings['ai_embed_deployment'] ?? 'embed');
        if ($is_embed) {
            return $prompt_tokens * (float) ($settings['ai_price_embed'] ?? 0) / 1000000;
        }
        return ($prompt_tokens * (float) ($settings['ai_price_input'] ?? 0)
            + $completion_tokens * (float) ($settings['ai_price_output'] ?? 0)) / 1000000;
    }

    public static function record_usage($feature, $deployment, $prompt_tokens, $completion_tokens, $post_id = 0) {
        global $wpdb;
        $cost = self::cost_of($deployment, $prompt_tokens, $completion_tokens, Azure_AI_Module::settings());
        $wpdb->insert(self::table('usage'), array(
            'created_at' => gmdate('Y-m-d H:i:s'),
            'feature' => substr((string) $feature, 0, 32),
            'deployment' => substr((string) $deployment, 0, 64),
            'post_id' => (int) $post_id,
            'prompt_tokens' => (int) $prompt_tokens,
            'completion_tokens' => (int) $completion_tokens,
            'cost' => $cost,
        ), array('%s', '%s', '%s', '%d', '%d', '%d', '%f'));
        if (self::$month_cost !== null) {
            self::$month_cost += $cost;
        }
    }

    public static function month_cost() {
        if (self::$month_cost === null) {
            global $wpdb;
            self::$month_cost = (float) $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(cost), 0) FROM ' . self::table('usage') . ' WHERE created_at >= %s',
                gmdate('Y-m-01 00:00:00')
            ));
        }
        return self::$month_cost;
    }

    public static function over_budget() {
        $budget = (float) (Azure_AI_Module::settings()['ai_monthly_budget'] ?? 0);
        return $budget <= 0 || self::month_cost() >= $budget;
    }

    /**
     * Month-to-date usage grouped by feature, for the settings page.
     */
    public static function usage_report() {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT feature, COUNT(*) AS calls, SUM(prompt_tokens) AS prompt_tokens,
                    SUM(completion_tokens) AS completion_tokens, SUM(cost) AS cost
             FROM ' . self::table('usage') . '
             WHERE created_at >= %s
             GROUP BY feature ORDER BY feature',
            gmdate('Y-m-01 00:00:00')
        ));
    }

    // ── Embeddings ────────────────────────────────────────────────────

    public static function save_embedding($post_id, $hash, array $vector) {
        global $wpdb;
        $wpdb->replace(self::table('embeddings'), array(
            'post_id' => (int) $post_id,
            'content_hash' => $hash,
            'dims' => count($vector),
            'vector' => Azure_AI_Vectors::pack($vector),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ), array('%d', '%s', '%d', '%s', '%s'));
    }

    public static function embedding_hash($post_id) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT content_hash FROM ' . self::table('embeddings') . ' WHERE post_id = %d',
            $post_id
        ));
    }

    /**
     * Every stored vector for published posts, keyed by post ID.
     */
    public static function all_embeddings() {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            'SELECT e.post_id, e.vector FROM ' . self::table('embeddings') . " e
             JOIN {$wpdb->posts} p ON p.ID = e.post_id
             WHERE p.post_status = 'publish' AND p.post_type = 'post'"
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row->post_id] = Azure_AI_Vectors::unpack($row->vector);
        }
        return $out;
    }

    public static function delete_post($post_id) {
        global $wpdb;
        $wpdb->delete(self::table('embeddings'), array('post_id' => (int) $post_id), array('%d'));
        $wpdb->delete(self::table('translations'), array('post_id' => (int) $post_id), array('%d'));
    }

    // ── Translations ──────────────────────────────────────────────────

    public static function get_translation($post_id, $lang) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('translations') . ' WHERE post_id = %d AND lang = %s',
            $post_id,
            $lang
        ));
    }

    /**
     * @param array $fields title, excerpt, content (HTML), summary (JSON of
     *                      the translated summary and key points).
     */
    public static function save_translation($post_id, $lang, $hash, array $fields) {
        global $wpdb;
        $wpdb->replace(self::table('translations'), array(
            'post_id' => (int) $post_id,
            'lang' => $lang,
            'content_hash' => $hash,
            'title' => (string) ($fields['title'] ?? ''),
            'excerpt' => (string) ($fields['excerpt'] ?? ''),
            'content' => (string) ($fields['content'] ?? ''),
            'summary' => (string) ($fields['summary'] ?? ''),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ), array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
    }

    public static function translation_count() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('translations'));
    }

    public static function embedding_count() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('embeddings'));
    }

    public static function translated_langs($post_id, $hash) {
        global $wpdb;
        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT lang FROM ' . self::table('translations') . ' WHERE post_id = %d AND content_hash = %s',
            $post_id,
            $hash
        )));
    }
}
