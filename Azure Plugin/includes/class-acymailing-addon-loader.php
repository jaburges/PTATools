<?php
/**
 * Loader for the AcyMailing "PTA Tools" dynamic-text add-on.
 *
 * AcyMailing 7+/8+ on WordPress discovers add-ons by firing the
 * `acym_load_installed_integrations` action and letting third
 * parties append `{path, className}` entries to the integrations
 * array. We bootstrap that hook here, and also wire deactivation /
 * uninstall cleanup so AcyMailing's plugin registry stays consistent
 * with our plugin's lifecycle.
 *
 * The add-on class itself lives at
 * `Azure Plugin/acymailing-addon/plugin.php`
 * (see plgAcymPtatools, extending \AcyMailing\Core\AcymPlugin).
 *
 * @package AzurePlugin
 * @since   3.116
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AcyMailing_Addon_Loader {

    /**
     * Folder slug AcyMailing uses to identify our integration in its
     * own plugin registry. MUST match plgAcym<name> lowercased — see
     * the docblock in acymailing-addon/plugin.php.
     */
    const INTEGRATION_NAME = 'ptatools';

    /**
     * Fully-qualified class name AcyMailing instantiates.
     */
    const CLASS_NAME = 'plgAcymPtatools';

    /** @var bool */
    private static $bootstrapped = false;

    /**
     * Idempotent registrar. Safe to call from multiple paths
     * (e.g. on every page load via the main plugin's init).
     */
    public static function bootstrap() {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        add_action('acym_load_installed_integrations', array(__CLASS__, 'register'), 10, 1);
    }

    /**
     * Append our add-on to the integrations array AcyMailing is
     * about to iterate. AcyMailing will then `require` plugin.php
     * from the given path and instantiate `className`.
     *
     * @param array $integrations Reference; we push our entry onto it.
     */
    public static function register(&$integrations) {
        if (!is_array($integrations)) {
            // Defensive: older AcyMailing builds might pass null.
            $integrations = array();
        }
        $integrations[] = array(
            'path'      => untrailingslashit(AZURE_PLUGIN_PATH) . '/acymailing-addon',
            'className' => self::CLASS_NAME,
        );
    }

    /**
     * Disable our integration in AcyMailing's `wp_acym_plugin` table
     * so it stops appearing in the Dynamic Text picker after our
     * main plugin is deactivated. Wired from the main plugin's
     * `deactivate()` method in azure-plugin.php.
     */
    public static function on_deactivate() {
        if (!self::load_acymailing_library()) {
            return;
        }
        try {
            $pluginClass = new \AcyMailing\Classes\PluginClass();
            $pluginClass->disable(self::INTEGRATION_NAME);
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('AcyMailing add-on disable failed: ' . $e->getMessage(), array('module' => 'Newsletter'));
            }
        }
    }

    /**
     * Hard-delete the integration registration. Wired via
     * `register_uninstall_hook` from azure-plugin.php top-level.
     * Must be a static method (or top-level function) because WP
     * calls it after the plugin's PHP has been fully unloaded.
     */
    public static function on_uninstall() {
        if (!self::load_acymailing_library()) {
            return;
        }
        try {
            $pluginClass = new \AcyMailing\Classes\PluginClass();
            $pluginClass->deleteByFolderName(self::INTEGRATION_NAME);
        } catch (\Throwable $e) {
            // Swallow — uninstall hooks should never raise.
            error_log('AcyMailing add-on uninstall failed: ' . $e->getMessage());
        }
    }

    /**
     * Pull AcyMailing's autoloader/init file from the standard WP
     * plugin path so we can construct PluginClass during
     * deactivation/uninstall when AcyMailing isn't necessarily loaded
     * yet (WordPress unloads plugins before firing these hooks).
     *
     * @return bool true if AcyMailing's PluginClass became available.
     */
    private static function load_acymailing_library() {
        if (class_exists('\\AcyMailing\\Classes\\PluginClass')) {
            return true;
        }
        $init_file = WP_PLUGIN_DIR . '/acymailing/back/Core/init.php';
        if (!file_exists($init_file)) {
            return false;
        }
        include_once $init_file;
        return class_exists('\\AcyMailing\\Classes\\PluginClass');
    }
}
