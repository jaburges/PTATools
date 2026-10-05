<?php
/**
 * Story (post) helpers shared by the theme's blocks.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Categories that describe placement rather than subject, so they are never
 * shown as a story's label.
 */
function lwptsa_placement_category_slugs() {
    return (array) apply_filters('lwptsa_placement_category_slugs', array('general-news', 'featured', 'homepage', 'uncategorized'));
}

// PTA Tools AI: label related stories by subject, never by placement, and
// translate the theme's own labels around a machine-translated story.
add_filter('pta_ai_ignored_category_slugs', function ($slugs) {
    return array_values(array_unique(array_merge((array) $slugs, lwptsa_placement_category_slugs())));
});
add_filter('pta_ai_ui_strings', function ($strings) {
    return array_merge((array) $strings, array('Related stories', 'Up next', 'Read'));
});

/**
 * @return WP_Term|null
 */
function lwptsa_primary_category($post) {
    $post = get_post($post);
    if (!$post) {
        return null;
    }
    $terms = get_the_category($post->ID);
    if (!$terms) {
        return null;
    }
    $placement = lwptsa_placement_category_slugs();
    $subject   = array_values(array_filter($terms, function ($t) use ($placement) {
        return !in_array($t->slug, $placement, true);
    }));
    if ($subject) {
        usort($subject, function ($a, $b) {
            return ($b->parent > 0) <=> ($a->parent > 0) ?: strcmp($a->name, $b->name);
        });
        return $subject[0];
    }
    foreach ($terms as $t) {
        if ($t->slug === 'general-news') {
            return $t;
        }
    }
    return null;
}

function lwptsa_category_label($post) {
    $term = lwptsa_primary_category($post);
    return $term ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : __('News', 'lwptsa-recap');
}

/**
 * Posts already shown higher up the page, so later lists can skip them.
 */
function lwptsa_shown_posts(array $add = array()) {
    static $ids = array();
    foreach ($add as $id) {
        $ids[(int) $id] = true;
    }
    return array_keys($ids);
}

function lwptsa_story_date($post, $short = false) {
    $post = get_post($post);
    if ($short && get_the_date('Y', $post) === wp_date('Y')) {
        return get_the_date('M j', $post);
    }
    return get_the_date('M j, Y', $post);
}

function lwptsa_story_excerpt($post, $words = 22) {
    $post = get_post($post);
    $text = has_excerpt($post) ? $post->post_excerpt : strip_shortcodes($post->post_content);
    $text = wp_strip_all_tags(excerpt_remove_blocks($text));
    return wp_trim_words(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), $words, '…');
}

function lwptsa_reading_minutes($post) {
    $post  = get_post($post);
    $words = str_word_count(wp_strip_all_tags(strip_shortcodes($post->post_content)));
    return max(1, (int) ceil($words / 220));
}

function lwptsa_tone($term) {
    if (!$term || $term->slug === 'general-news') {
        return 0;
    }
    return (int) $term->term_id % 4;
}

/**
 * Listings are three-card rows, so show 12 per page rather than the site's
 * "posts per page" setting.
 */
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->is_home() || $query->is_category() || $query->is_tag() || $query->is_search() || $query->is_author() || $query->is_date()) {
        $query->set('posts_per_page', 12);
    }
});

/**
 * Featured image, or a soft category tile when there is none or the file is gone.
 */
function lwptsa_story_media($post, $size = 'medium_large', $class = '') {
    $post  = get_post($post);
    $thumb = (int) get_post_thumbnail_id($post);
    if ($thumb && lwptsa_attachment_file_exists($thumb)) {
        $img = wp_get_attachment_image($thumb, $size, false, array('alt' => '', 'loading' => 'lazy', 'decoding' => 'async'));
        if ($img) {
            return '<div class="' . esc_attr(trim('lw-media ' . $class)) . '">' . $img . '</div>';
        }
    }
    return lwptsa_placeholder($post, $class);
}

function lwptsa_placeholder($post, $class = '') {
    $term = lwptsa_primary_category($post);
    return sprintf(
        '<div class="%s" aria-hidden="true"><span>%s</span></div>',
        esc_attr(trim('lw-media lw-ph lw-tone-' . lwptsa_tone($term) . ' ' . $class)),
        esc_html(lwptsa_category_label($post))
    );
}

/**
 * @param string $variant card | lead | spot | mini
 * @param string $note    Optional line under the title (mini only).
 */
function lwptsa_story_card($post, $variant = 'card', $note = '') {
    $post  = get_post($post);
    $url   = esc_url(get_permalink($post));
    $title = esc_html(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'));
    $kick  = '<span class="lw-kicker">' . esc_html(lwptsa_category_label($post)) . '</span>';
    $iso   = esc_attr(get_the_date('c', $post));

    switch ($variant) {
        case 'lead':
            return '<article class="lw-lead">' . lwptsa_story_media($post, 'large', 'lw-media--wide')
                . $kick . '<h2 class="lw-lead__title"><a href="' . $url . '">' . $title . '</a></h2>'
                . '<time class="lw-meta" datetime="' . $iso . '">' . esc_html(lwptsa_story_date($post)) . '</time></article>';
        case 'spot':
            return '<article class="lw-spot"><div>' . $kick . '<h3 class="lw-spot__title"><a href="' . $url . '">' . $title . '</a></h3></div>'
                . '<time class="lw-meta" datetime="' . $iso . '">' . esc_html(lwptsa_story_date($post, true)) . '</time></article>';
        case 'mini':
            return '<article class="lw-mini">' . lwptsa_story_media($post, 'thumbnail', 'lw-media--thumb')
                . '<div><h3 class="lw-mini__title"><a href="' . $url . '">' . $title . '</a></h3>'
                . ($note !== '' ? '<div class="lw-meta">' . esc_html($note) . '</div>' : '') . '</div></article>';
        default:
            $excerpt = lwptsa_story_excerpt($post);
            return '<article class="lw-card">' . lwptsa_story_media($post, 'medium_large', 'lw-media--card')
                . $kick . '<h3 class="lw-card__title"><a href="' . $url . '">' . $title . '</a></h3>'
                . ($excerpt !== '' ? '<p class="lw-card__excerpt">' . esc_html($excerpt) . '</p>' : '')
                . '<time class="lw-meta" datetime="' . $iso . '">' . esc_html(lwptsa_story_date($post)) . '</time></article>';
    }
}

/**
 * @return WP_Post[]
 */
function lwptsa_latest_posts($count, array $args = array()) {
    return get_posts(array_merge(array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => $count,
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ), $args));
}
