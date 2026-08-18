<?php
/**
 * Detect App Service vs Container Apps and resolve the backup hosting mode.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Backup_Host {

    const MODE_AUTO        = 'auto';
    const MODE_APP_SERVICE = 'app_service';
    const MODE_CONTAINER   = 'container';

    /**
     * What the platform looks like right now, ignoring the admin override.
     *
     * @return string app_service|container|unknown
     */
    public static function detect() {
        $container_env = getenv('CONTAINER_APP_NAME');
        if ($container_env === false) {
            $container_env = getenv('CONTAINER_APP_REVISION');
        }
        if (is_string($container_env) && $container_env !== '') {
            return self::MODE_CONTAINER;
        }

        $pin = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '') . '/mu-plugins/000-container-core-pin.php';
        if ($pin && file_exists($pin)) {
            return self::MODE_CONTAINER;
        }
        if (defined('AZURE_PLUGIN_PATH') && file_exists(AZURE_PLUGIN_PATH . '../healthz.php')) {
            return self::MODE_CONTAINER;
        }

        $site = getenv('WEBSITE_SITE_NAME');
        $inst = getenv('WEBSITE_INSTANCE_ID');
        if ((is_string($site) && $site !== '') || (is_string($inst) && $inst !== '')) {
            return self::MODE_APP_SERVICE;
        }

        return 'unknown';
    }

    /**
     * Admin setting: auto | app_service | container.
     */
    public static function setting() {
        $mode = Azure_Settings::get_setting('backup_hosting_mode', self::MODE_AUTO);
        if (!in_array($mode, array(self::MODE_AUTO, self::MODE_APP_SERVICE, self::MODE_CONTAINER), true)) {
            return self::MODE_AUTO;
        }
        return $mode;
    }

    /**
     * Mode used for backup/restore behaviour.
     *
     * @return string app_service|container
     */
    public static function resolved() {
        $setting = self::setting();
        if ($setting === self::MODE_APP_SERVICE || $setting === self::MODE_CONTAINER) {
            return $setting;
        }
        $detected = self::detect();
        if ($detected === self::MODE_CONTAINER) {
            return self::MODE_CONTAINER;
        }
        return self::MODE_APP_SERVICE;
    }

    public static function is_container() {
        return self::resolved() === self::MODE_CONTAINER;
    }

    public static function label($mode = null) {
        $mode = $mode ?: self::resolved();
        if ($mode === self::MODE_CONTAINER) {
            return __('Container Apps', 'azure-plugin');
        }
        if ($mode === self::MODE_APP_SERVICE) {
            return __('App Service', 'azure-plugin');
        }
        return __('Unknown', 'azure-plugin');
    }

    /**
     * Plugin/theme inventory written into the backup manifest.
     *
     * @return array{plugins:array<int,array{slug:string,name:string,version:string,active:bool}>,themes:array<int,array{slug:string,name:string,version:string,active:bool}>}
     */
    public static function inventory() {
        $active = (array) get_option('active_plugins', array());
        $plugins = array();
        if (function_exists('get_plugins')) {
            foreach (get_plugins() as $file => $data) {
                $slug = dirname($file);
                if ($slug === '.') {
                    $slug = basename($file, '.php');
                }
                $plugins[] = array(
                    'slug'    => $slug,
                    'name'    => isset($data['Name']) ? $data['Name'] : $slug,
                    'version' => isset($data['Version']) ? $data['Version'] : '',
                    'active'  => in_array($file, $active, true),
                );
            }
        }

        $themes = array();
        $current = function_exists('get_stylesheet') ? get_stylesheet() : '';
        if (function_exists('wp_get_themes')) {
            foreach (wp_get_themes() as $slug => $theme) {
                $themes[] = array(
                    'slug'    => $slug,
                    'name'    => $theme->get('Name'),
                    'version' => $theme->get('Version'),
                    'active'  => ($slug === $current),
                );
            }
        }

        return array(
            'plugins' => $plugins,
            'themes'  => $themes,
        );
    }

    public static function latest_blob_prefix() {
        return sanitize_title(get_bloginfo('name')) . '/container-latest';
    }
}
