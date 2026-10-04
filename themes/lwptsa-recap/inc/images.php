<?php
/**
 * Some media rows outlived their files (a resized copy or the original is
 * missing on disk). Serve the original when a resized copy is missing, drop
 * missing files from srcset, and show a placeholder instead of a broken image.
 */

if (!defined('ABSPATH')) {
    exit;
}

function lwptsa_attachment_file_exists($id) {
    static $cache = array();
    $id = (int) $id;
    if (!isset($cache[$id])) {
        $file = get_attached_file($id);
        $cache[$id] = $file && file_exists($file);
    }
    return $cache[$id];
}

function lwptsa_upload_url_exists($url, $attachment_id) {
    $original = get_attached_file($attachment_id);
    if (!$original) {
        return true;
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    return file_exists(dirname($original) . '/' . wp_basename(rawurldecode($path)));
}

add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size, $icon) {
    if (!$image || $icon || $size === 'full' || lwptsa_upload_url_exists($image[0], $attachment_id)) {
        return $image;
    }
    if (!lwptsa_attachment_file_exists($attachment_id)) {
        return $image;
    }
    $meta = wp_get_attachment_metadata($attachment_id);
    return array(
        wp_get_attachment_url($attachment_id),
        isset($meta['width']) ? (int) $meta['width'] : 0,
        isset($meta['height']) ? (int) $meta['height'] : 0,
        false,
    );
}, 10, 4);

add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (!is_array($sources)) {
        return $sources;
    }
    foreach ($sources as $width => $source) {
        if (!lwptsa_upload_url_exists($source['url'], $attachment_id)) {
            unset($sources[$width]);
        }
    }
    return $sources;
}, 10, 5);

/**
 * Featured image block: hide a missing file; with the "lw-fallback" class,
 * show the category placeholder instead.
 */
add_filter('render_block_core/post-featured-image', function ($html, $block, $instance) {
    $post_id = isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();
    if (!$post_id) {
        return $html;
    }
    $thumb = (int) get_post_thumbnail_id($post_id);
    if ($html !== '' && $thumb && lwptsa_attachment_file_exists($thumb)) {
        return $html;
    }
    $class = isset($block['attrs']['className']) ? (string) $block['attrs']['className'] : '';
    if (strpos($class, 'lw-fallback') === false) {
        return '';
    }
    $ph = lwptsa_placeholder($post_id, 'wp-block-post-featured-image ' . $class);
    if (!empty($block['attrs']['isLink'])) {
        $ph = '<a href="' . esc_url(get_permalink($post_id)) . '" tabindex="-1" aria-hidden="true">' . $ph . '</a>';
    }
    return $ph;
}, 10, 3);
