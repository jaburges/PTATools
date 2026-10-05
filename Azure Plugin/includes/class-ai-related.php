<?php
/**
 * Related stories and Up Next from post embeddings.
 *
 * Each published post gets one vector. Neighbour lists are computed in
 * the background and stored in post meta, so a page view only reads
 * meta. Themes ask for them through the pta_related_posts filter
 * (array of array('id' => int, 'reason' => string)) and pta_up_next_post
 * (post ID), and fall back to their own logic when nothing comes back.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Related {

    const META = '_pta_ai_related';
    const VERSION_META = '_pta_ai_related_v';
    const VERSION_OPTION = 'pta_ai_vectors_version';
    const KEEP = 12;
    const MAX_EMBED_CHARS = 8000;

    // Up Next prefers a story from the last year when one is close enough.
    const UP_NEXT_MAX_AGE = YEAR_IN_SECONDS;

    private static $shown = array();

    public static function register_hooks() {
        add_filter('pta_related_posts', array(__CLASS__, 'filter_related'), 10, 3);
        add_filter('pta_up_next_post', array(__CLASS__, 'filter_up_next'), 10, 2);
    }

    public static function embed_text($post) {
        $text = wp_strip_all_tags($post->post_title) . "\n\n";
        $summary = Azure_AI_Summary::get($post->ID);
        if (!empty($summary['summary'])) {
            $text .= $summary['summary'] . "\n\n";
        }
        $text .= Azure_AI_Module::post_text($post);
        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_EMBED_CHARS) : substr($text, 0, self::MAX_EMBED_CHARS);
    }

    /**
     * @return string|WP_Error 'saved' or 'unchanged'.
     */
    public static function embed_post($post, Azure_AI_Client $client, $force = false) {
        $hash = Azure_AI_Module::content_hash($post);
        if (!$force && Azure_AI_Store::embedding_hash($post->ID) === $hash) {
            return 'unchanged';
        }
        $vectors = $client->embed(array(self::embed_text($post)), 'embedding', $post->ID);
        if (is_wp_error($vectors)) {
            return $vectors;
        }
        Azure_AI_Store::save_embedding($post->ID, $hash, Azure_AI_Vectors::normalize($vectors[0]));
        update_option(self::VERSION_OPTION, (int) get_option(self::VERSION_OPTION, 0) + 1, false);
        return 'saved';
    }

    /**
     * Recompute neighbour lists that predate the newest embedding.
     *
     * @return int Posts refreshed.
     */
    public static function refresh_stale($limit) {
        global $wpdb;
        $version = (int) get_option(self::VERSION_OPTION, 0);
        if ($version === 0) {
            return 0;
        }
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT e.post_id FROM ' . Azure_AI_Store::table('embeddings') . " e
             JOIN {$wpdb->posts} p ON p.ID = e.post_id AND p.post_status = 'publish'
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = e.post_id AND m.meta_key = %s
             WHERE m.meta_value IS NULL OR CAST(m.meta_value AS UNSIGNED) < %d
             ORDER BY p.post_date DESC
             LIMIT %d",
            self::VERSION_META,
            $version,
            (int) $limit
        )));
        if (!$ids) {
            return 0;
        }
        $all = Azure_AI_Store::all_embeddings();
        foreach ($ids as $id) {
            if (!isset($all[$id])) {
                continue;
            }
            $near = Azure_AI_Vectors::nearest($all[$id], $all, self::KEEP, array($id));
            update_post_meta($id, self::META, array(
                'ids' => array_map('intval', array_keys($near)),
                'scores' => array_map(function ($s) { return round($s, 4); }, array_values($near)),
            ));
            update_post_meta($id, self::VERSION_META, $version);
        }
        return count($ids);
    }

    /**
     * Stored neighbours that are still published, best first.
     */
    public static function neighbours($post_id) {
        $meta = get_post_meta((int) $post_id, self::META, true);
        if (!is_array($meta) || empty($meta['ids'])) {
            return array();
        }
        $out = array();
        foreach ($meta['ids'] as $id) {
            if (get_post_status((int) $id) === 'publish') {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * Drop posts whose title repeats one already picked (or the post's
     * own): reposted stories would otherwise show up twice.
     */
    public static function distinct_titles(array $ids, array $taken_titles = array()) {
        $seen = array_flip(array_map(array(__CLASS__, 'title_key'), $taken_titles));
        $out = array();
        foreach ($ids as $id) {
            $key = self::title_key(get_the_title($id));
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = (int) $id;
        }
        return $out;
    }

    public static function title_key($title) {
        $title = html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title)));
    }

    public static function filter_related($items, $post_id, $count) {
        if (!Azure_AI_Module::feature_enabled('related')) {
            return $items;
        }
        $ids = array_slice(self::distinct_titles(self::neighbours($post_id), array(get_the_title($post_id))), 0, max(1, (int) $count));
        if (!$ids) {
            return $items;
        }
        self::$shown[(int) $post_id] = $ids;

        $own = self::topic_terms($post_id);
        $out = array();
        foreach ($ids as $id) {
            $shared = array_intersect_key(self::topic_terms($id), $own);
            $out[] = array(
                'id' => $id,
                'reason' => $shared ? reset($shared) : Azure_AI_Translate::ui('Similar story'),
            );
        }
        return $out;
    }

    public static function filter_up_next($next, $post_id) {
        if (!Azure_AI_Module::feature_enabled('related') || $next) {
            return $next;
        }
        $shown = self::$shown[(int) $post_id] ?? array();
        $taken = array_merge(array(get_the_title($post_id)), array_map('get_the_title', $shown));
        $candidates = self::distinct_titles(array_values(array_diff(self::neighbours($post_id), $shown)), $taken);
        if (!$candidates) {
            return $next;
        }
        $cutoff = time() - self::UP_NEXT_MAX_AGE;
        foreach (array_slice($candidates, 0, 6) as $id) {
            if ((int) get_post_time('U', true, $id) >= $cutoff) {
                return $id;
            }
        }
        return $candidates[0];
    }

    /**
     * Category names for a post keyed by term ID, without the default
     * category or ones a theme uses for placement (featured slots and the
     * like), which say nothing about the topic.
     */
    private static function topic_terms($post_id) {
        $default = (int) get_option('default_category');
        $ignored = (array) apply_filters('pta_ai_ignored_category_slugs', array('featured', 'uncategorized'));
        $out = array();
        foreach ((array) get_the_category($post_id) as $term) {
            if ((int) $term->term_id !== $default && !in_array($term->slug, $ignored, true)) {
                $out[(int) $term->term_id] = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
            }
        }
        return $out;
    }
}
