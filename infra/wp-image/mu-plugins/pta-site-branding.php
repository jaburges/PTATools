<?php
/**
 * Plugin Name: PTA Site Branding
 * Description: Wilder wolf artwork for the home-screen icon and the class race markers.
 * Author: PTA Tools
 *
 * Lives in the container image (infra/wp-image/mu-plugins), not on the uploads share.
 * The PTA Tools plugin ships generic artwork; these filters swap in this site's own.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pta_home_screen_icon_file', function ($file, $size) {
    $own = __DIR__ . '/pta-site-branding/pta-icon-' . (int) $size . '.png';
    return is_readable($own) ? $own : $file;
}, 10, 2);

add_filter('pta_class_race_runner_url', function ($url, $image) {
    $image = (int) $image;
    if ($image < 1 || $image > 10) {
        return $url;
    }
    return WPMU_PLUGIN_URL . '/pta-site-branding/race/wolf-' . $image . '.png';
}, 10, 2);
