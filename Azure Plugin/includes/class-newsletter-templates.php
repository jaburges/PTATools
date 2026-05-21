<?php
/**
 * Newsletter Templates - Thumbnail Manager
 *
 * Handles persistent PNG thumbnails for newsletter templates so the admin
 * Templates tab can render lightweight <img> tags instead of live iframes.
 *
 * Pipeline:
 *   1. Templates tab renders <img> when thumbnail_url is present.
 *   2. Otherwise, the browser snapshots the template once via html2canvas
 *      and POSTs the PNG to the AJAX endpoint registered here.
 *   3. The PNG is stored under uploads/azure-plugin/newsletter-thumbnails/
 *      and the relative URL is saved into wp_azure_newsletter_templates.thumbnail_url.
 *   4. Whenever a template's content changes, callers should fire
 *      do_action('azure_newsletter_template_changed', $template_id) so the
 *      stale thumbnail is cleared and the next admin visit regenerates it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Templates {

    /** Subdirectory under wp-content/uploads/ */
    const SUBDIR = 'azure-plugin/newsletter-thumbnails';

    public function __construct() {
        // Receive PNG snapshots from the browser (admin only)
        add_action('wp_ajax_azure_newsletter_save_template_thumbnail', array($this, 'ajax_save_thumbnail'));
        add_action('wp_ajax_azure_newsletter_regenerate_template_thumbnail', array($this, 'ajax_regenerate'));
        add_action('wp_ajax_azure_newsletter_regenerate_all_thumbnails', array($this, 'ajax_regenerate_all'));

        // Auto-clear stale thumbnails when a template changes
        add_action('azure_newsletter_template_changed', array(__CLASS__, 'clear_thumbnail'));
        add_action('azure_newsletter_templates_reset',  array(__CLASS__, 'clear_all_thumbnails'));
    }

    /* -----------------------------------------------------------------
     * Filesystem helpers
     * ----------------------------------------------------------------- */

    /**
     * Absolute path to the thumbnail directory (no trailing slash).
     */
    public static function get_dir() {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . self::SUBDIR;
    }

    /**
     * Public URL base for the thumbnail directory (no trailing slash).
     */
    public static function get_base_url() {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . self::SUBDIR;
    }

    /**
     * Ensure the thumbnail directory exists (with index.html guard) and is writable.
     *
     * @return bool true on success, false if the directory can't be created/written.
     */
    public static function ensure_dir() {
        $dir = self::get_dir();

        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return false;
            }
            // Drop a silent index.html so the directory isn't browsable
            @file_put_contents(trailingslashit($dir) . 'index.html', '');
        }

        return is_writable($dir);
    }

    /**
     * Build the on-disk filename for a given template id.
     *
     * Includes a short hash so a stale browser cache can't keep showing
     * an old image after a regeneration.
     */
    public static function filename_for($template_id, $cache_buster = null) {
        $cache_buster = $cache_buster ?: substr(md5((string) microtime(true) . $template_id), 0, 8);
        return sprintf('template-%d-%s.png', intval($template_id), $cache_buster);
    }

    /* -----------------------------------------------------------------
     * Persistence
     * ----------------------------------------------------------------- */

    /**
     * Persist a PNG (binary string) for the given template and update the DB row.
     *
     * @return string|WP_Error URL on success, WP_Error on failure.
     */
    public static function save_thumbnail($template_id, $png_binary) {
        $template_id = intval($template_id);
        if ($template_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid template ID.', 'azure-plugin'));
        }
        if (empty($png_binary)) {
            return new WP_Error('empty_png', __('No image data received.', 'azure-plugin'));
        }
        // Quick PNG signature check
        if (substr($png_binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return new WP_Error('invalid_png', __('Image data is not a valid PNG.', 'azure-plugin'));
        }
        if (!self::ensure_dir()) {
            return new WP_Error('mkdir_failed', __('Could not create thumbnail directory.', 'azure-plugin'));
        }

        // Remove any prior thumbnail for this template
        self::delete_thumbnail_files($template_id);

        $filename = self::filename_for($template_id);
        $path     = trailingslashit(self::get_dir()) . $filename;

        $written = @file_put_contents($path, $png_binary);
        if ($written === false) {
            return new WP_Error('write_failed', __('Could not write thumbnail file.', 'azure-plugin'));
        }

        $url = trailingslashit(self::get_base_url()) . $filename;

        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        $wpdb->update(
            $table,
            array('thumbnail_url' => $url),
            array('id' => $template_id),
            array('%s'),
            array('%d')
        );

        return $url;
    }

    /**
     * Clear the thumbnail for a single template.
     *
     * Deletes any matching files on disk and nulls the DB column so the
     * Templates tab re-snapshots on the next visit.
     */
    public static function clear_thumbnail($template_id) {
        $template_id = intval($template_id);
        if ($template_id <= 0) {
            return;
        }

        self::delete_thumbnail_files($template_id);

        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        $wpdb->update(
            $table,
            array('thumbnail_url' => null),
            array('id' => $template_id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Clear thumbnails for every template (used on bulk reset).
     */
    public static function clear_all_thumbnails() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        $wpdb->query("UPDATE {$table} SET thumbnail_url = NULL");

        $dir = self::get_dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob(trailingslashit($dir) . 'template-*.png') as $file) {
            @unlink($file);
        }
    }

    /**
     * Remove any on-disk thumbnail files belonging to this template id.
     */
    private static function delete_thumbnail_files($template_id) {
        $dir = self::get_dir();
        if (!is_dir($dir)) {
            return;
        }
        $pattern = trailingslashit($dir) . sprintf('template-%d-*.png', intval($template_id));
        foreach ((array) glob($pattern) as $file) {
            @unlink($file);
        }
    }

    /* -----------------------------------------------------------------
     * AJAX endpoints
     * ----------------------------------------------------------------- */

    /**
     * Receive a PNG snapshot from the browser and store it.
     *
     * Expects POST: template_id, image (data: URL), nonce
     */
    public function ajax_save_thumbnail() {
        check_ajax_referer('azure_newsletter_template_thumbnail', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'azure-plugin')), 403);
        }

        $template_id = intval($_POST['template_id'] ?? 0);
        $data_url    = isset($_POST['image']) ? (string) $_POST['image'] : '';

        if ($template_id <= 0 || $data_url === '') {
            wp_send_json_error(array('message' => __('Missing template_id or image data.', 'azure-plugin')), 400);
        }

        // Strip data: prefix and decode
        if (!preg_match('#^data:image/png;base64,(.+)$#', $data_url, $m)) {
            wp_send_json_error(array('message' => __('Image must be a base64 data: PNG URL.', 'azure-plugin')), 400);
        }

        $binary = base64_decode($m[1], true);
        if ($binary === false) {
            wp_send_json_error(array('message' => __('Could not decode image data.', 'azure-plugin')), 400);
        }

        // Cap incoming payload at ~2 MB to prevent abuse
        if (strlen($binary) > 2 * 1024 * 1024) {
            wp_send_json_error(array('message' => __('Image exceeds 2MB limit.', 'azure-plugin')), 400);
        }

        $result = self::save_thumbnail($template_id, $binary);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'template_id' => $template_id,
            'url'         => $result,
        ));
    }

    /**
     * Clear a single template's thumbnail so the next page load regenerates it.
     */
    public function ajax_regenerate() {
        check_ajax_referer('azure_newsletter_template_thumbnail', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'azure-plugin')), 403);
        }

        $template_id = intval($_POST['template_id'] ?? 0);
        if ($template_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid template ID.', 'azure-plugin')), 400);
        }

        self::clear_thumbnail($template_id);

        wp_send_json_success(array('template_id' => $template_id));
    }

    /**
     * Clear all thumbnails (admin tool).
     */
    public function ajax_regenerate_all() {
        check_ajax_referer('azure_newsletter_template_thumbnail', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'azure-plugin')), 403);
        }

        self::clear_all_thumbnails();

        wp_send_json_success();
    }
}

// Bootstrap once
if (!isset($GLOBALS['azure_newsletter_templates_instance'])) {
    $GLOBALS['azure_newsletter_templates_instance'] = new Azure_Newsletter_Templates();
}
