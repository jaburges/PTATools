<?php
/**
 * PTA Tools Capability Registry & Seeder
 *
 * Defines the canonical set of PTA-Tools-specific WordPress capabilities
 * (e.g. send_newsletters, restore_pta_backups, manage_pta_role_assignments)
 * and seeds them onto WordPress roles on activation, version bump, or on
 * demand.
 *
 * Phase 1 scope: define caps and surface them in the Role Editor. Most
 * code paths in the plugin still gate on `manage_options`; only a handful
 * of high-impact gates have been retrofitted to consult these caps. The
 * remaining `manage_options` -> per-cap migration is a separate phase.
 *
 * Each cap entry has:
 *   slug          machine name (a-z0-9_)
 *   label         human-readable description for the role editor
 *   group         registry group key (events, newsletter, backup, ...)
 *   default_roles WP role slugs that get this cap by default
 *   high_trust    bool — render with a warning icon in the editor
 *   wired         bool — true if this cap is consulted by code today,
 *                       false if it's defined for visibility only and
 *                       will be wired in a future phase.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Capabilities {

    const REGISTRY_VERSION = '1.0.0';
    const VERSION_OPTION   = 'azure_plugin_caps_version';

    /**
     * Group definitions. Keys match the `group` field in registry entries
     * and feed the Role Editor's per-group rendering.
     *
     * @return array
     */
    public static function get_groups() {
        return array(
            'events'      => array('label' => 'PTA Events',                'icon' => 'calendar-alt'),
            'newsletter'  => array('label' => 'Newsletter',                'icon' => 'email-alt'),
            'backup'      => array('label' => 'Backups',                   'icon' => 'backup'),
            'pta_roles'   => array('label' => 'PTA Roles & Departments',   'icon' => 'groups'),
            'calendar'    => array('label' => 'Calendar Embed / Sync',     'icon' => 'calendar'),
            'tickets'     => array('label' => 'Tickets',                   'icon' => 'tickets-alt'),
            'auctions'    => array('label' => 'Auctions',                  'icon' => 'cart'),
            'donations'   => array('label' => 'Donations',                 'icon' => 'heart'),
            'classes'     => array('label' => 'Classes',                   'icon' => 'welcome-learn-more'),
            'volunteers'  => array('label' => 'Volunteer Signups',         'icon' => 'admin-users'),
            'settings'    => array('label' => 'Settings & Diagnostics',    'icon' => 'admin-settings'),
        );
    }

    /**
     * Canonical capability registry. Order matters: caps render in this
     * order within each group in the Role Editor.
     *
     * @return array<int, array{slug:string,label:string,group:string,default_roles:array<string>,high_trust:bool,wired:bool}>
     */
    public static function get_registry() {
        $admin_only = array('administrator');
        $admin_editor = array('administrator', 'editor');

        // Editor's "trusted content owner" set — used for newsletter and
        // event publishing caps.
        $trusted_set = $admin_editor;

        $registry = array();

        // ------------------------------------------------------------------
        // PTA Events (pta_event / pta_venue / pta_organizer)
        // ------------------------------------------------------------------
        foreach (array(
            'pta_events'    => "PTA events",
            'pta_venues'    => "PTA venues",
            'pta_organizers'=> "PTA organizers",
        ) as $object => $object_label) {
            $registry[] = array('slug' => "edit_{$object}",                   'label' => "Edit own {$object_label}",                  'group' => 'events', 'default_roles' => array('administrator','editor','author'), 'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "edit_others_{$object}",            'label' => "Edit others' {$object_label}",              'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "edit_published_{$object}",         'label' => "Edit published {$object_label}",            'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "edit_private_{$object}",           'label' => "Edit private {$object_label}",              'group' => 'events', 'default_roles' => $admin_only,                                'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "publish_{$object}",                'label' => "Publish {$object_label}",                   'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "delete_{$object}",                 'label' => "Delete own {$object_label}",                'group' => 'events', 'default_roles' => array('administrator','editor','author'), 'high_trust' => false, 'wired' => false);
            $registry[] = array('slug' => "delete_others_{$object}",          'label' => "Delete others' {$object_label}",            'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => true,  'wired' => false);
            $registry[] = array('slug' => "delete_published_{$object}",       'label' => "Delete published {$object_label}",          'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => true,  'wired' => false);
            $registry[] = array('slug' => "delete_private_{$object}",         'label' => "Delete private {$object_label}",            'group' => 'events', 'default_roles' => $admin_only,                                'high_trust' => true,  'wired' => false);
            $registry[] = array('slug' => "read_private_{$object}",           'label' => "Read private {$object_label}",              'group' => 'events', 'default_roles' => $admin_editor,                              'high_trust' => false, 'wired' => false);
        }

        // ------------------------------------------------------------------
        // Newsletter
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'view_newsletters',           'label' => 'View newsletters',                          'group' => 'newsletter', 'default_roles' => array('administrator','editor','author'), 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'edit_newsletters',           'label' => 'Create and edit newsletter drafts',         'group' => 'newsletter', 'default_roles' => $trusted_set,                               'high_trust' => false, 'wired' => true);
        $registry[] = array('slug' => 'send_newsletters',           'label' => 'Send newsletters',                          'group' => 'newsletter', 'default_roles' => $trusted_set,                               'high_trust' => true,  'wired' => true);
        $registry[] = array('slug' => 'manage_newsletter_lists',    'label' => 'Manage recipient lists',                    'group' => 'newsletter', 'default_roles' => $trusted_set,                               'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'manage_newsletter_templates','label' => 'Manage newsletter templates',               'group' => 'newsletter', 'default_roles' => $trusted_set,                               'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'view_newsletter_stats',      'label' => 'View newsletter open/click stats',          'group' => 'newsletter', 'default_roles' => $trusted_set,                               'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Backups
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'view_pta_backups',           'label' => 'View backups',                              'group' => 'backup', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => true);
        $registry[] = array('slug' => 'create_pta_backups',         'label' => 'Create / trigger backups',                  'group' => 'backup', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => true);
        $registry[] = array('slug' => 'restore_pta_backups',        'label' => 'Restore from backup',                       'group' => 'backup', 'default_roles' => $admin_only,   'high_trust' => true,  'wired' => true);
        $registry[] = array('slug' => 'delete_pta_backups',         'label' => 'Delete backups',                            'group' => 'backup', 'default_roles' => $admin_only,   'high_trust' => true,  'wired' => true);

        // ------------------------------------------------------------------
        // PTA Roles & Departments
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_role_assignments','label' => 'Assign users to PTA roles',                 'group' => 'pta_roles', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => true);
        $registry[] = array('slug' => 'manage_pta_role_definitions','label' => 'Create / edit PTA role definitions',        'group' => 'pta_roles', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'manage_pta_departments',     'label' => 'Manage departments',                        'group' => 'pta_roles', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Calendar Embed / Sync
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'view_calendar_embed',        'label' => 'View calendar embed admin',                 'group' => 'calendar', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'manage_calendar_embed',      'label' => 'Manage calendar embed settings',            'group' => 'calendar', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'manage_calendar_sync',       'label' => 'Manage Outlook calendar sync',              'group' => 'calendar', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Tickets
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_tickets',         'label' => 'Manage tickets',                            'group' => 'tickets', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'scan_tickets',               'label' => 'Scan tickets at the door',                  'group' => 'tickets', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Auctions
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_auctions',        'label' => 'Manage auctions',                           'group' => 'auctions', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'place_pta_bids',             'label' => 'Place bids on auctions',                    'group' => 'auctions', 'default_roles' => array('administrator','editor','author','contributor','subscriber'), 'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Donations
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'view_pta_donations',         'label' => 'View donation records',                     'group' => 'donations', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'manage_pta_donations',       'label' => 'Manage donation campaigns',                 'group' => 'donations', 'default_roles' => $admin_only,   'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Classes
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_classes',         'label' => 'Manage class products and events',          'group' => 'classes', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Volunteer Signups
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_volunteers',      'label' => 'Manage volunteer sheets',                   'group' => 'volunteers', 'default_roles' => $admin_editor, 'high_trust' => false, 'wired' => false);
        $registry[] = array('slug' => 'view_pta_volunteer_signups', 'label' => 'View volunteer signups',                    'group' => 'volunteers', 'default_roles' => array('administrator','editor','author'), 'high_trust' => false, 'wired' => false);

        // ------------------------------------------------------------------
        // Settings & Diagnostics
        // ------------------------------------------------------------------
        $registry[] = array('slug' => 'manage_pta_settings',        'label' => 'Manage PTA Tools plugin settings',          'group' => 'settings', 'default_roles' => $admin_only, 'high_trust' => true,  'wired' => false);
        $registry[] = array('slug' => 'run_pta_diagnostics',        'label' => 'Run PTA Tools diagnostics',                 'group' => 'settings', 'default_roles' => $admin_only, 'high_trust' => false, 'wired' => true);

        /**
         * Filter the PTA Tools capability registry. Lets other modules
         * register their own caps without editing this file.
         *
         * @param array $registry
         */
        return apply_filters('azure_capabilities_registry', $registry);
    }

    /**
     * Return cap_slug => label map for the Role Editor's friendly labels.
     *
     * @return array<string,string>
     */
    public static function get_label_map() {
        $map = array();
        foreach (self::get_registry() as $entry) {
            $map[$entry['slug']] = $entry['label'];
        }
        return $map;
    }

    /**
     * Return cap_slug => group_key map. Used by the Role Editor's
     * group classifier to put PTA caps in their proper bucket.
     *
     * @return array<string,string>
     */
    public static function get_group_map() {
        $map = array();
        foreach (self::get_registry() as $entry) {
            $map[$entry['slug']] = $entry['group'];
        }
        return $map;
    }

    /**
     * Return list of cap slugs that should render with a high-trust badge.
     *
     * @return array<string>
     */
    public static function get_high_trust_caps() {
        $caps = array();
        foreach (self::get_registry() as $entry) {
            if (!empty($entry['high_trust'])) {
                $caps[] = $entry['slug'];
            }
        }
        return $caps;
    }

    /**
     * Return list of cap slugs that are NOT yet consulted by code (for
     * the "wired in Phase 2" UI hint).
     *
     * @return array<string>
     */
    public static function get_unwired_caps() {
        $caps = array();
        foreach (self::get_registry() as $entry) {
            if (empty($entry['wired'])) {
                $caps[] = $entry['slug'];
            }
        }
        return $caps;
    }

    /**
     * Whether a given cap belongs to the PTA Tools registry.
     *
     * @param string $cap
     * @return bool
     */
    public static function is_pta_cap($cap) {
        $map = self::get_label_map();
        return isset($map[$cap]);
    }

    /**
     * Seed the registry onto WP roles. Idempotent — safe to call repeatedly.
     *
     * Runs on plugin activation, on version bump, and on demand from the
     * "Re-seed PTA capabilities" button in the Role Editor. Does NOT remove
     * caps that operators may have customised on roles outside the
     * default_roles list — only adds.
     *
     * @param bool $force If true, run even when the stored registry version
     *                    already matches REGISTRY_VERSION.
     * @return array{added:int, skipped:int, roles_touched:array<string>}
     */
    public static function seed($force = false) {
        $stored = get_option(self::VERSION_OPTION, '0');
        if (!$force && version_compare($stored, self::REGISTRY_VERSION, '>=')) {
            return array('added' => 0, 'skipped' => 0, 'roles_touched' => array(), 'noop' => true);
        }

        $registry = self::get_registry();
        $added = 0;
        $skipped = 0;
        $roles_touched = array();

        foreach ($registry as $entry) {
            $cap = $entry['slug'];
            foreach ((array) ($entry['default_roles'] ?? array()) as $role_slug) {
                $role = get_role($role_slug);
                if (!$role) {
                    // Role not present (e.g. multisite without 'editor' on this site) — skip.
                    continue;
                }
                if (!empty($role->capabilities[$cap])) {
                    $skipped++;
                    continue;
                }
                $role->add_cap($cap, true);
                $added++;
                if (!in_array($role_slug, $roles_touched, true)) {
                    $roles_touched[] = $role_slug;
                }
            }
        }

        update_option(self::VERSION_OPTION, self::REGISTRY_VERSION, false);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'Capabilities seeded: %d caps added, %d already present, roles touched: %s',
                $added, $skipped, implode(', ', $roles_touched)
            ), 'Capabilities');
        }

        return array(
            'added'         => $added,
            'skipped'       => $skipped,
            'roles_touched' => $roles_touched,
            'version'       => self::REGISTRY_VERSION,
            'noop'          => false,
        );
    }

    /**
     * Convenience permission check used by retrofitted gates.
     *
     * Pattern: a user with the specific PTA cap OR with `manage_options`
     * passes. Falling back to `manage_options` keeps existing administrator
     * users working with zero migration risk during Phase 1.
     *
     * @param string $cap
     * @param int|null $user_id Optional; defaults to current user.
     * @return bool
     */
    public static function user_can($cap, $user_id = null) {
        if ($user_id === null) {
            return current_user_can($cap) || current_user_can('manage_options');
        }
        return user_can($user_id, $cap) || user_can($user_id, 'manage_options');
    }

    // =====================================================================
    // STALE-CAP DETECTION & CLEANUP
    //
    // WordPress doesn't auto-remove caps when a plugin is deactivated —
    // they stay persisted in wp_options.wp_user_roles forever unless an
    // operator explicitly cleans them. After the TEC retirement migration,
    // every role on wilderptsa.net still carries `edit_tribe_events`,
    // `delete_tribe_events`, etc. They're inert (no code reads them now
    // that pta_event has its own caps) but they clutter the Role Editor.
    //
    // The probe below reports what's stale; the cleanup wipes those caps
    // from every role.
    // =====================================================================

    /**
     * Definitions of cap-cleanup targets. Each target is keyed by a stable
     * identifier and describes:
     *   label             human-readable for the UI banner
     *   detector          callable returning true when the source plugin
     *                     IS NOT active (i.e. caps are safe to clean up)
     *   match_patterns    list of regex patterns; any cap matching one of
     *                     these on a role is considered stale
     *
     * Add new entries here when other plugins are retired.
     *
     * @return array<string,array{label:string,detector:callable,match_patterns:array<string>}>
     */
    public static function get_stale_cap_targets() {
        $targets = array(
            'tec' => array(
                'label'          => 'The Events Calendar',
                'detector'       => function () {
                    // TEC main class isn't loaded → plugin is inactive.
                    return !class_exists('Tribe__Events__Main')
                        && !is_plugin_active('the-events-calendar/the-events-calendar.php');
                },
                'match_patterns' => array(
                    '/^edit_tribe_events?$/i',
                    '/^edit_others_tribe_events?$/i',
                    '/^edit_published_tribe_events?$/i',
                    '/^edit_private_tribe_events?$/i',
                    '/^delete_tribe_events?$/i',
                    '/^delete_others_tribe_events?$/i',
                    '/^delete_published_tribe_events?$/i',
                    '/^delete_private_tribe_events?$/i',
                    '/^publish_tribe_events?$/i',
                    '/^read_private_tribe_events?$/i',
                    '/^edit_tribe_venues?$/i',
                    '/^edit_others_tribe_venues?$/i',
                    '/^edit_published_tribe_venues?$/i',
                    '/^edit_private_tribe_venues?$/i',
                    '/^delete_tribe_venues?$/i',
                    '/^delete_others_tribe_venues?$/i',
                    '/^delete_published_tribe_venues?$/i',
                    '/^delete_private_tribe_venues?$/i',
                    '/^publish_tribe_venues?$/i',
                    '/^read_private_tribe_venues?$/i',
                    '/^edit_tribe_organizers?$/i',
                    '/^edit_others_tribe_organizers?$/i',
                    '/^edit_published_tribe_organizers?$/i',
                    '/^edit_private_tribe_organizers?$/i',
                    '/^delete_tribe_organizers?$/i',
                    '/^delete_others_tribe_organizers?$/i',
                    '/^delete_published_tribe_organizers?$/i',
                    '/^delete_private_tribe_organizers?$/i',
                    '/^publish_tribe_organizers?$/i',
                    '/^read_private_tribe_organizers?$/i',
                    '/^manage_event_aggregator$/i',
                    '/^manage_events_calendar/i',
                    '/^tribe_/i',
                ),
            ),
        );

        /**
         * Filter the stale-cap target registry. Lets future modules add
         * cleanup definitions for plugins they retire.
         *
         * @param array $targets
         */
        return apply_filters('azure_capabilities_stale_targets', $targets);
    }

    /**
     * Probe a single target: is the source plugin inactive, and how many
     * stale caps are sitting on roles?
     *
     * @param string $target_key
     * @return array{key:string,label:string,plugin_inactive:bool,roles:array<string,array{role_label:string,caps:array<string>}>,total_caps:int,roles_affected:int}
     */
    public static function probe_stale_target($target_key) {
        // Required for is_plugin_active() in admin context.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $targets = self::get_stale_cap_targets();
        $target = isset($targets[$target_key]) ? $targets[$target_key] : null;
        if (!$target) {
            return array(
                'key' => $target_key, 'label' => $target_key,
                'plugin_inactive' => false, 'roles' => array(),
                'total_caps' => 0, 'roles_affected' => 0,
            );
        }

        $detector = $target['detector'];
        $plugin_inactive = is_callable($detector) ? (bool) call_user_func($detector) : false;

        $roles_affected = array();
        $total = 0;

        if ($plugin_inactive) {
            $patterns = (array) $target['match_patterns'];
            foreach (wp_roles()->roles as $role_slug => $role_data) {
                $matched = array();
                foreach ((array) ($role_data['capabilities'] ?? array()) as $cap => $has) {
                    if (empty($has)) continue;
                    foreach ($patterns as $pat) {
                        if (preg_match($pat, $cap)) {
                            $matched[] = $cap;
                            break;
                        }
                    }
                }
                if (!empty($matched)) {
                    $roles_affected[$role_slug] = array(
                        'role_label' => $role_data['name'] ?? $role_slug,
                        'caps'       => $matched,
                    );
                    $total += count($matched);
                }
            }
        }

        return array(
            'key'             => $target_key,
            'label'           => $target['label'],
            'plugin_inactive' => $plugin_inactive,
            'roles'           => $roles_affected,
            'total_caps'      => $total,
            'roles_affected'  => count($roles_affected),
        );
    }

    /**
     * Remove all stale caps for a target from every role. Only runs when
     * the target's `detector` reports the plugin as inactive — so an
     * operator can't accidentally nuke live caps while a plugin is still
     * loaded.
     *
     * @param string $target_key
     * @return array{ok:bool,reason?:string,caps_removed:int,roles_touched:array<string>,target:string}
     */
    public static function cleanup_stale_target($target_key) {
        $probe = self::probe_stale_target($target_key);
        if (!$probe['plugin_inactive']) {
            return array(
                'ok'            => false,
                'reason'        => "Source plugin for '{$target_key}' is still active — cleanup refused.",
                'caps_removed'  => 0,
                'roles_touched' => array(),
                'target'        => $target_key,
            );
        }

        $targets = self::get_stale_cap_targets();
        $patterns = (array) ($targets[$target_key]['match_patterns'] ?? array());
        if (empty($patterns)) {
            return array(
                'ok' => false, 'reason' => 'No patterns registered for target.',
                'caps_removed' => 0, 'roles_touched' => array(), 'target' => $target_key,
            );
        }

        $caps_removed = 0;
        $roles_touched = array();

        foreach (wp_roles()->roles as $role_slug => $role_data) {
            $role = get_role($role_slug);
            if (!$role) continue;
            $touched = false;
            foreach ((array) ($role_data['capabilities'] ?? array()) as $cap => $has) {
                foreach ($patterns as $pat) {
                    if (preg_match($pat, $cap)) {
                        $role->remove_cap($cap);
                        $caps_removed++;
                        $touched = true;
                        break;
                    }
                }
            }
            if ($touched) {
                $roles_touched[] = $role_slug;
            }
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                "Stale cap cleanup '%s': removed %d cap(s) from %d role(s) [%s]",
                $target_key, $caps_removed, count($roles_touched), implode(',', $roles_touched)
            ), 'Capabilities');
        }

        return array(
            'ok'            => true,
            'caps_removed'  => $caps_removed,
            'roles_touched' => $roles_touched,
            'target'        => $target_key,
        );
    }
}
