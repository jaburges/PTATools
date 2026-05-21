<?php
/**
 * Beaver Builder Module for PTA Roles
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_BeaverBuilder {

    public function __construct() {
        // Wait until Beaver Builder has loaded its module base class, then register
        // our custom modules. We hook a single 'init' at priority 20 so we run after
        // BB itself registers FLBuilderModule on default-priority 10.
        add_action('init', array($this, 'load_modules'), 20);
    }

    public function load_modules() {
        if (!class_exists('FLBuilderModule')) {
            // Beaver Builder isn't active on this request — nothing to register.
            return;
        }

        require_once AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-roles-directory/pta-roles-directory.php';
        require_once AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-department-roles/pta-department-roles.php';
        require_once AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-org-chart/pta-org-chart.php';
        require_once AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-open-positions/pta-open-positions.php';
        require_once AZURE_PLUGIN_PATH . 'includes/beaver-builder/auction-carousel/auction-carousel.php';
    }
}

// All five module classes are declared in their per-module include files
// (includes/beaver-builder/<slug>/<slug>.php). DO NOT declare stub classes
// in this shared file — FLBuilderModule's slug is derived from the file
// where the class is declared (via ReflectionClass), so co-locating
// multiple stubs here caused them all to share one slug ("class-pta-beaver-builder")
// and BB silently dropped all but the first via its
// "module with this slug already exists" guard.
//
// Azure_PTA_BeaverBuilder is instantiated by AzurePlugin::init_pta_components().
// No auto-instantiation here.


