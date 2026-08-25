<?php
/**
 * Homepage ticker (ChromeNews Exclusive News).
 *
 * ChromeNews only queries the `post` type for the Exclusive News marquee.
 * This lets a single WordPress category — Homepage — also include pages
 * and events. Add or remove that category on any of those items to show
 * or hide them in the ticker.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Homepage_Ticker {

    const CATEGORY_SLUG = 'homepage';
    const CATEGORY_NAME = 'Homepage';
    const MIGRATE_OPTION = 'azure_homepage_ticker_migrated_31475';

    /** @var string[] */
    const POST_TYPES = array('post', 'page', 'pta_event');

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // After pta_event registers (priority 20).
        add_action('init', array($this, 'register_category_on_types'), 25);
        add_action('init', array($this, 'ensure_homepage_category'), 26);
        add_action('init', array($this, 'maybe_migrate_flash_category'), 30);
        add_action('pre_get_posts', array($this, 'expand_homepage_queries'));
    }

    /**
     * Same Categories box as posts, on pages and events.
     */
    public function register_category_on_types() {
        if (!taxonomy_exists('category')) {
            return;
        }
        foreach (self::POST_TYPES as $type) {
            if ($type === 'post') {
                continue;
            }
            if (post_type_exists($type)) {
                register_taxonomy_for_object_type('category', $type);
            }
        }
    }

    public function ensure_homepage_category() {
        if (!taxonomy_exists('category')) {
            return;
        }
        if ($this->homepage_term()) {
            return;
        }
        wp_insert_term(self::CATEGORY_NAME, 'category', array(
            'slug'        => self::CATEGORY_SLUG,
            'description' => 'Shown in Exclusive News on the homepage. Add or remove this category to show or hide the item.',
        ));
    }

    /**
     * @return WP_Term|null
     */
    private function homepage_term() {
        $term = get_term_by('slug', self::CATEGORY_SLUG, 'category');
        if ($term instanceof WP_Term) {
            return $term;
        }
        $term = get_term_by('name', self::CATEGORY_NAME, 'category');
        return ($term instanceof WP_Term) ? $term : null;
    }

    /**
     * Keep the current ticker items after we switch the theme to Homepage.
     *
     * ChromeNews Exclusive News is already pointed at some post category.
     * Those posts get Homepage appended, then the customizer category is
     * pointed at Homepage so pages and events can join the same list.
     */
    public function maybe_migrate_flash_category() {
        if (get_option(self::MIGRATE_OPTION)) {
            return;
        }
        $homepage = $this->homepage_term();
        if (!$homepage) {
            return;
        }
        $homepage_id = (int) $homepage->term_id;

        $current = 0;
        $mods = get_theme_mods();
        if (is_array($mods) && isset($mods['select_flash_news_category'])) {
            $current = (int) $mods['select_flash_news_category'];
        }

        if ($current > 0 && $current !== $homepage_id) {
            $ids = get_posts(array(
                'post_type'              => 'post',
                'posts_per_page'         => 50,
                'cat'                    => $current,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));
            foreach ($ids as $post_id) {
                wp_set_object_terms((int) $post_id, $homepage_id, 'category', true);
            }
        } elseif ($current === 0) {
            $limit = 5;
            if (is_array($mods) && isset($mods['number_of_flash_news'])) {
                $limit = max(1, (int) $mods['number_of_flash_news']);
            }
            $ids = get_posts(array(
                'post_type'              => 'post',
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));
            foreach ($ids as $post_id) {
                wp_set_object_terms((int) $post_id, $homepage_id, 'category', true);
            }
        }

        set_theme_mod('select_flash_news_category', $homepage_id);
        update_option(self::MIGRATE_OPTION, 1, true);
    }

    /**
     * ChromeNews hard-codes post_type=post. When the query is the Homepage
     * category, include pages and events too.
     */
    public function expand_homepage_queries($query) {
        if (!($query instanceof WP_Query)) {
            return;
        }
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (!$this->query_targets_homepage($query)) {
            return;
        }

        $current = $query->get('post_type');
        if ($current === 'any') {
            return;
        }
        if ($current === '' || $current === 'post') {
            $query->set('post_type', self::POST_TYPES);
            return;
        }
        if (is_array($current) && $current === array('post')) {
            $query->set('post_type', self::POST_TYPES);
        }
    }

    private function query_targets_homepage(WP_Query $query) {
        $homepage = $this->homepage_term();
        if (!$homepage) {
            return false;
        }
        $id = (int) $homepage->term_id;

        $cat = $query->get('cat');
        if ($cat !== '' && $cat !== '0' && (int) $cat === $id) {
            return true;
        }

        $name = $query->get('category_name');
        if (is_string($name) && $name !== '') {
            $slug = strtok($name, '/');
            if ($slug === $homepage->slug || $slug === self::CATEGORY_SLUG) {
                return true;
            }
        }

        $in = $query->get('category__in');
        if (is_array($in) && in_array($id, array_map('intval', $in), true)) {
            return true;
        }

        if ($query->is_category($id) || $query->is_category(self::CATEGORY_SLUG)) {
            return true;
        }

        return false;
    }
}
