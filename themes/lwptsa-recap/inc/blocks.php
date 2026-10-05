<?php
/**
 * Dynamic blocks used by the templates. Each renders on the server; the
 * editor shows a live server-side preview.
 */

if (!defined('ABSPATH')) {
    exit;
}

function lwptsa_block_definitions() {
    return array(
        'featured-stories' => array(
            'title'       => __('Featured story and spotlight', 'lwptsa-recap'),
            'description' => __('The newest story in a category, with the latest headlines beneath it.', 'lwptsa-recap'),
            'attributes'  => array(
                'category'  => array('type' => 'string', 'default' => 'featured'),
                'spotlight' => array('type' => 'number', 'default' => 3),
                'heading'   => array('type' => 'string', 'default' => __('Featured', 'lwptsa-recap')),
            ),
        ),
        'story-grid' => array(
            'title'       => __('Story grid', 'lwptsa-recap'),
            'description' => __('Latest stories as cards, skipping any already shown above.', 'lwptsa-recap'),
            'attributes'  => array(
                'count'    => array('type' => 'number', 'default' => 6),
                'columns'  => array('type' => 'number', 'default' => 3),
                'category' => array('type' => 'string', 'default' => ''),
            ),
        ),
        'events-strip' => array(
            'title'       => __('Upcoming events', 'lwptsa-recap'),
            'description' => __('Next events from the PTA Tools calendar.', 'lwptsa-recap'),
            'attributes'  => array(
                'count'    => array('type' => 'number', 'default' => 5),
                'layout'   => array('type' => 'string', 'default' => 'cards', 'enum' => array('cards', 'list')),
                'category' => array('type' => 'string', 'default' => ''),
                'heading'  => array('type' => 'string', 'default' => ''),
            ),
        ),
        'next-event' => array(
            'title'       => __('Next event', 'lwptsa-recap'),
            'description' => __('One line naming the next event on the calendar.', 'lwptsa-recap'),
            'attributes'  => array(),
        ),
        'category-tiles' => array(
            'title'       => __('Category tiles', 'lwptsa-recap'),
            'description' => __('Links to categories with their story counts.', 'lwptsa-recap'),
            'attributes'  => array(
                'slugs' => array('type' => 'string', 'default' => 'advocacy,programs,reflections,scholarships,face,special-needs'),
            ),
        ),
        'story-category' => array(
            'title'       => __('Story category', 'lwptsa-recap'),
            'description' => __("The story's main subject category.", 'lwptsa-recap'),
            'attributes'  => array(),
            'uses'        => array('postId'),
        ),
        'byline' => array(
            'title'       => __('Story byline', 'lwptsa-recap'),
            'description' => __('Council mark, date and reading time.', 'lwptsa-recap'),
            'attributes'  => array(),
            'uses'        => array('postId'),
        ),
        'related-stories' => array(
            'title'       => __('Related stories', 'lwptsa-recap'),
            'description' => __('Stories on the same subject.', 'lwptsa-recap'),
            'attributes'  => array(
                'count' => array('type' => 'number', 'default' => 4),
            ),
            'uses'        => array('postId'),
        ),
        'up-next' => array(
            'title'       => __('Up next', 'lwptsa-recap'),
            'description' => __('A large card for the story to read next.', 'lwptsa-recap'),
            'attributes'  => array(),
            'uses'        => array('postId'),
        ),
    );
}

add_action('init', function () {
    wp_register_script(
        'lwptsa-blocks-editor',
        get_theme_file_uri('assets/js/blocks-editor.js'),
        array('wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor'),
        lwptsa_asset_version('assets/js/blocks-editor.js'),
        true
    );

    $names = array();
    foreach (lwptsa_block_definitions() as $slug => $def) {
        $name    = 'lwptsa/' . $slug;
        $names[] = $name;
        register_block_type($name, array(
            'api_version'     => 3,
            'title'           => $def['title'],
            'description'     => $def['description'],
            'category'        => 'theme',
            'icon'            => 'layout',
            'attributes'      => $def['attributes'],
            'uses_context'    => isset($def['uses']) ? $def['uses'] : array(),
            'supports'        => array('html' => false, 'align' => array('wide', 'full'), 'spacing' => array('margin' => true)),
            'editor_script_handles' => array('lwptsa-blocks-editor'),
            'render_callback' => function ($attributes, $content, $block) use ($slug) {
                $fn = 'lwptsa_render_' . str_replace('-', '_', $slug);
                $html = $fn($attributes, $block);
                if ($html === '') {
                    return '';
                }
                $wrapper = get_block_wrapper_attributes(array('class' => 'lw-block lw-' . $slug));
                return '<div ' . $wrapper . '>' . $html . '</div>';
            },
        ));
    }
    wp_add_inline_script('lwptsa-blocks-editor', 'window.lwptsaBlocks = ' . wp_json_encode($names) . ';', 'before');
});

/**
 * Post for blocks that need one. Falls back to the latest story so the Site
 * Editor preview of a template has something to show.
 */
function lwptsa_block_post($block) {
    if (isset($block->context['postId']) && $block->context['postId']) {
        return get_post((int) $block->context['postId']);
    }
    if (is_singular()) {
        return get_post();
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        $latest = lwptsa_latest_posts(1);
        return $latest ? $latest[0] : null;
    }
    return null;
}

function lwptsa_render_featured_stories($a, $block) {
    $lead = array();
    if ($a['category'] !== '') {
        $with_image = lwptsa_latest_posts(5, array('category_name' => sanitize_title($a['category']), 'meta_key' => '_thumbnail_id'));
        foreach ($with_image as $p) {
            if (lwptsa_attachment_file_exists(get_post_thumbnail_id($p))) {
                $lead = array($p);
                break;
            }
        }
        if (!$lead) {
            $lead = lwptsa_latest_posts(1, array('category_name' => sanitize_title($a['category'])));
        }
    }
    $exclude = $lead ? array($lead[0]->ID) : array();
    $spot    = lwptsa_latest_posts(max(0, min(6, (int) $a['spotlight'])), array('post__not_in' => $exclude));
    if (!$lead && $spot) {
        $lead = array(array_shift($spot));
    }
    if (!$lead) {
        return '';
    }
    lwptsa_shown_posts(array_merge(wp_list_pluck($lead, 'ID'), wp_list_pluck($spot, 'ID')));

    $html = $a['heading'] !== '' ? '<h2 class="lw-eyebrow">' . esc_html($a['heading']) . '</h2>' : '';
    $html .= lwptsa_story_card($lead[0], 'lead');
    foreach ($spot as $p) {
        $html .= lwptsa_story_card($p, 'spot');
    }
    return $html;
}

function lwptsa_render_story_grid($a, $block) {
    $count = max(1, min(24, (int) $a['count']));
    $args  = array('post__not_in' => lwptsa_shown_posts());
    if ($a['category'] !== '') {
        $args['category_name'] = sanitize_title($a['category']);
    }
    $posts = lwptsa_latest_posts($count, $args);
    if (!$posts) {
        return '';
    }
    lwptsa_shown_posts(wp_list_pluck($posts, 'ID'));
    $cols = max(1, min(4, (int) $a['columns']));
    $html = '<div class="lw-grid" style="--lw-cols:' . $cols . '">';
    foreach ($posts as $p) {
        $html .= lwptsa_story_card($p, 'card');
    }
    return $html . '</div>';
}

function lwptsa_render_events_strip($a, $block) {
    $count  = max(1, min(12, (int) $a['count']));
    $events = lwptsa_upcoming_events($count, sanitize_title($a['category']));
    $list   = $a['layout'] === 'list';
    $html   = $a['heading'] !== '' ? '<h2 class="lw-eyebrow">' . esc_html($a['heading']) . '</h2>' : '';

    if (!$events) {
        return $html . '<p class="lw-empty">' . sprintf(
            /* translators: %s: calendar link */
            esc_html__('Nothing on the calendar yet. %s', 'lwptsa-recap'),
            '<a href="' . esc_url(lwptsa_calendar_url()) . '">' . esc_html__('See the full calendar', 'lwptsa-recap') . '</a>'
        ) . '</p>';
    }

    $html .= $list ? '<ul class="lw-evlist">' : '<div class="lw-evs">';
    foreach ($events as $e) {
        $ts    = $e['start']->getTimestamp();
        $title = '<a href="' . esc_url($e['url']) . '">' . esc_html($e['title']) . '</a>';
        if ($list) {
            $html .= '<li><span class="lw-evlist__title">' . $title . '</span><span class="lw-meta">' . esc_html(lwptsa_event_when($e, true)) . '</span></li>';
            continue;
        }
        $meta = lwptsa_event_when($e) . ($e['venue'] !== '' ? ' · ' . $e['venue'] : '');
        $html .= '<article class="lw-ev"><div class="lw-ev__date"><b>' . esc_html(wp_date('j', $ts)) . '</b><span>'
            . esc_html(wp_date('M', $ts)) . '<br>' . esc_html(wp_date('D', $ts)) . '</span></div>'
            . '<h3 class="lw-ev__title">' . $title . '</h3><div class="lw-meta">' . esc_html($meta) . '</div></article>';
    }
    return $html . ($list ? '</ul>' : '</div>');
}

function lwptsa_render_next_event($a, $block) {
    $events = lwptsa_upcoming_events(2);
    if (!$events) {
        return '';
    }
    $html = '<div class="lw-next"><span class="lw-next__label">' . esc_html__('Next up', 'lwptsa-recap') . '</span>';
    foreach ($events as $e) {
        $html .= '<a class="lw-next__item" href="' . esc_url($e['url']) . '"><span class="lw-next__when">' . esc_html(lwptsa_event_when($e, true)) . '</span>'
            . '<span class="lw-next__title">' . esc_html($e['title']) . '</span></a>';
    }
    return $html . '<a class="lw-next__all" href="' . esc_url(lwptsa_calendar_url()) . '">' . esc_html__('Full calendar', 'lwptsa-recap') . ' →</a></div>';
}

function lwptsa_render_category_tiles($a, $block) {
    $html = '';
    foreach (array_filter(array_map('sanitize_title', explode(',', (string) $a['slugs']))) as $slug) {
        $term = get_term_by('slug', $slug, 'category');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $html .= '<a class="lw-tile" href="' . esc_url(get_term_link($term)) . '"><b>' . esc_html(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')) . '</b>'
            . '<span>' . esc_html(number_format_i18n($term->count)) . ' →</span></a>';
    }
    return $html === '' ? '' : '<div class="lw-tiles">' . $html . '</div>';
}

function lwptsa_render_story_category($a, $block) {
    $post = lwptsa_block_post($block);
    if (!$post) {
        return '';
    }
    $term  = lwptsa_primary_category($post);
    $label = esc_html(lwptsa_category_label($post));
    return $term ? '<a class="lw-kicker" href="' . esc_url(get_term_link($term)) . '">' . $label . '</a>' : '<span class="lw-kicker">' . $label . '</span>';
}

function lwptsa_render_byline($a, $block) {
    $post = lwptsa_block_post($block);
    if (!$post) {
        return '';
    }
    $minutes = lwptsa_reading_minutes($post);
    return '<span class="lw-mark lw-mark--sun" aria-hidden="true">LW</span><span>'
        . esc_html(apply_filters('lwptsa_byline_name', __('LWPTSA Council', 'lwptsa-recap'), $post))
        . ' · <time datetime="' . esc_attr(get_the_date('c', $post)) . '">' . esc_html(get_the_date('F j, Y', $post)) . '</time>'
        /* translators: %d: minutes */
        . ' · ' . esc_html(sprintf(_n('%d min read', '%d min read', $minutes, 'lwptsa-recap'), $minutes)) . '</span>';
}

/**
 * Related stories. A plugin can supply its own list (PTA Tools AI does,
 * from embeddings, via pta_related_posts) through the lwptsa_related_posts
 * filter as array( array( 'id' => int, 'reason' => string ), ... ).
 */
function lwptsa_render_related_stories($a, $block) {
    $post = lwptsa_block_post($block);
    if (!$post) {
        return '';
    }
    $count = max(1, min(8, (int) $a['count']));
    $items = apply_filters('lwptsa_related_posts', apply_filters('pta_related_posts', null, $post->ID, $count), $post->ID, $count);

    if (!is_array($items)) {
        $items = array();
        $term  = lwptsa_primary_category($post);
        if ($term) {
            /* translators: %s: category name */
            $reason = sprintf(__('More in %s', 'lwptsa-recap'), html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'));
            foreach (lwptsa_latest_posts($count, array('cat' => $term->term_id, 'post__not_in' => array($post->ID))) as $p) {
                $items[] = array('id' => $p->ID, 'reason' => $reason);
            }
        }
        if (count($items) < $count) {
            $have = array_merge(array($post->ID), wp_list_pluck($items, 'id'));
            foreach (lwptsa_latest_posts($count - count($items), array('post__not_in' => $have)) as $p) {
                $items[] = array('id' => $p->ID, 'reason' => __('Latest from the Council', 'lwptsa-recap'));
            }
        }
    }

    $html = '';
    foreach (array_slice($items, 0, $count) as $item) {
        $p = get_post((int) $item['id']);
        if ($p && $p->post_status === 'publish') {
            $html .= lwptsa_story_card($p, 'mini', isset($item['reason']) ? (string) $item['reason'] : '');
        }
    }
    return $html === '' ? '' : '<h2 class="lw-eyebrow">' . esc_html__('Related stories', 'lwptsa-recap') . '</h2>' . $html;
}

/**
 * The story to read next: lwptsa_up_next_post filter, else the previous
 * story on the same subject, else the newest featured story.
 */
function lwptsa_render_up_next($a, $block) {
    $post = lwptsa_block_post($block);
    if (!$post) {
        return '';
    }
    $next = apply_filters('lwptsa_up_next_post', apply_filters('pta_up_next_post', null, $post->ID), $post->ID);
    if (!$next) {
        $term = lwptsa_primary_category($post);
        $args = array(
            'post__not_in' => array($post->ID),
            'date_query'   => array(array('before' => $post->post_date, 'inclusive' => false)),
        );
        if ($term) {
            $args['cat'] = $term->term_id;
        }
        $found = lwptsa_latest_posts(1, $args);
        if (!$found) {
            $found = lwptsa_latest_posts(1, array('category_name' => 'featured', 'post__not_in' => array($post->ID)));
        }
        $next = $found ? $found[0] : null;
    }
    $next = $next ? get_post($next) : null;
    if (!$next) {
        return '';
    }
    $url = esc_url(get_permalink($next));
    return '<article class="lw-upnext">' . lwptsa_story_media($next, 'medium_large', 'lw-media--upnext')
        . '<div><span class="lw-eyebrow">' . esc_html__('Up next', 'lwptsa-recap') . '</span>'
        . '<h2 class="lw-upnext__title"><a href="' . $url . '">' . esc_html(html_entity_decode(get_the_title($next), ENT_QUOTES, 'UTF-8')) . '</a></h2>'
        . '<p>' . esc_html(lwptsa_story_excerpt($next, 30)) . '</p></div>'
        . '<a class="lw-btn" href="' . $url . '" aria-hidden="true" tabindex="-1">' . esc_html__('Read', 'lwptsa-recap') . ' →</a></article>';
}
