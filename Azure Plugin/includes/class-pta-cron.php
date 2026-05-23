<?php
/**
 * Centralized cron registry for PTA Tools.
 *
 * Owns all custom intervals + the "ensure scheduled" pass for every module's
 * recurring events. Replaces 4 separate `cron_schedules` filters and ~6
 * separate `add_action('init', 'schedule_…')` calls scattered across module
 * classes.
 *
 * Two responsibilities:
 *
 * 1. `register_intervals($schedules)`
 *    Registered as a single `cron_schedules` filter from azure-plugin.php.
 *    Runs on every request that calls `wp_get_schedules()` — usually only
 *    when WP-Cron is dispatching events. Cheap (just merges arrays).
 *
 * 2. `ensure_events_scheduled()`
 *    Called once per request from `init` in backend/cron context only.
 *    Reconciles recurring events with current module settings:
 *    - enabled modules get any missing scheduled events
 *    - disabled modules have their module-owned events removed
 *    A `wp_next_scheduled()` lookup is just an `wp_options['cron']` read,
 *    which is autoloaded and Redis-cached, so the cost when nothing needs
 *    re-scheduling is ~0.
 *
 * Frontend visitor pageloads pay nothing because we never call
 * `ensure_events_scheduled()` for them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Cron {

    /**
     * Whether ensure_events_scheduled() has already run this request.
     * Prevents duplicate work if multiple modules call it.
     */
    private static $checked = false;

    /**
     * Custom cron intervals exposed to WP-Cron.
     *
     * Keys must stay stable — existing scheduled events on production DBs
     * reference some of these names by string. Adding a new alias is fine;
     * renaming an existing one will orphan events.
     *
     * @param array $schedules Existing schedules from WP and other plugins.
     * @return array
     */
    public static function register_intervals($schedules) {
        if (!is_array($schedules)) {
            $schedules = array();
        }

        // Newsletter
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display'  => __('Every Minute', 'azure-plugin'),
            );
        }
        if (!isset($schedules['every_fifteen_minutes'])) {
            $schedules['every_fifteen_minutes'] = array(
                'interval' => 15 * 60,
                'display'  => __('Every 15 Minutes', 'azure-plugin'),
            );
        }

        if (!isset($schedules['every_15_minutes'])) {
            $schedules['every_15_minutes'] = array(
                'interval' => 15 * 60,
                'display'  => __('Every 15 Minutes', 'azure-plugin'),
            );
        }
        if (!isset($schedules['every_30_minutes'])) {
            $schedules['every_30_minutes'] = array(
                'interval' => 30 * 60,
                'display'  => __('Every 30 Minutes', 'azure-plugin'),
            );
        }

        // PTA Sync Engine
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = array(
                'interval' => 5 * 60,
                'display'  => __('Every 5 Minutes', 'azure-plugin'),
            );
        }

        // PTA Groups Manager
        if (!isset($schedules['six_hours'])) {
            $schedules['six_hours'] = array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __('Every 6 Hours', 'azure-plugin'),
            );
        }

        return $schedules;
    }

    /**
     * Reconcile recurring events with enabled module settings.
     *
     * Idempotent — `wp_next_scheduled` returns a timestamp if the hook is
     * already scheduled; `wp_clear_scheduled_hook` is a no-op if a disabled
     * module has no scheduled jobs.
     *
     * Should only be called from backend or cron context (admin pageloads,
     * admin-ajax, wp-cron). On frontend visitor pageloads we do nothing.
     */
    public static function ensure_events_scheduled() {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        $settings = get_option('azure_plugin_settings', array());
        if (!is_array($settings)) {
            return;
        }

        try {
            self::clear_disabled_module_events($settings);

            // ── PTA Manager: daily cleanup ───────────────────────────────
            if (!empty($settings['enable_pta'])) {
                self::ensure('pta_daily_cleanup', 'daily', time() + HOUR_IN_SECONDS);

                // PTA Sync Engine: process sync queue every 5 minutes
                self::ensure('pta_process_sync_queue', 'five_minutes', time() + 60);

                // PTA Groups Manager: O365 group sync (only when groups feature enabled)
                if (!empty($settings['enable_pta_groups_sync'])) {
                    self::ensure('pta_sync_o365_groups_scheduled', 'daily', time() + HOUR_IN_SECONDS);
                    self::ensure('pta_sync_group_memberships_scheduled', 'six_hours', time() + 1800);
                }
            }

            // ── Newsletter ────────────────────────────────────────────────
            if (!empty($settings['enable_newsletter'])) {
                self::ensure('azure_newsletter_process_queue', 'every_minute');
                self::ensure('azure_newsletter_check_bounces', 'every_fifteen_minutes');
                self::ensure('azure_newsletter_weekly_validation', 'weekly');
                self::ensure('azure_newsletter_sync_mailgun_stats', 'hourly');
            }

            // ── Calendar (token refresh) ──────────────────────────────────
            if (!empty($settings['enable_calendar'])) {
                self::ensure('azure_calendar_token_refresh', 'hourly', time() + HOUR_IN_SECONDS);
            }

            // ── Email (token refresh) ─────────────────────────────────────
            if (!empty($settings['enable_email'])) {
                self::ensure('azure_mail_token_refresh', 'hourly', time() + HOUR_IN_SECONDS);
                self::ensure('azure_process_email_queue', 'five_minutes', time() + 300);
            }

            // ── Volunteer (daily reminders) ───────────────────────────────
            if (!empty($settings['enable_volunteer'])) {
                self::ensure('azure_volunteer_send_reminders', 'daily');
            }

            // ── Auction (orphan sweep) ────────────────────────────────────
            // Per-auction finalize is a one-shot event scheduled at the
            // bidding-end timestamp by Azure_Auction_Product_Type. This daily
            // sweep is a safety-net for auctions whose one-shot got lost
            // (legacy data, env restores, manual cron table prunes).
            if (!empty($settings['enable_auction'])) {
                self::ensure('azure_auction_finalize_orphans', 'daily', time() + HOUR_IN_SECONDS);
            }

            // ── SSO (scheduled user sync) ─────────────────────────────────
            // Frequency is configurable — Azure_SSO_Sync owns its own
            // re-scheduling when the setting changes, so we don't touch it
            // here. Just verify the hook exists at SOMETHING when the
            // module is on.
            if (!empty($settings['enable_sso']) && !empty($settings['sso_sync_enabled'])) {
                $freq = !empty($settings['sso_sync_frequency']) ? $settings['sso_sync_frequency'] : 'daily';
                self::ensure('azure_sso_scheduled_sync', $freq, time() + 60);
            }

            // ── Logger cleanup (always on; cleans up our own logs) ───────
            self::ensure('azure_plugin_cleanup_logs', 'daily', time() + DAY_IN_SECONDS);

            // Backup, Auction, and OneDrive Media still schedule their own
            // feature-specific events (admin-toggled or per-mapping), but
            // this central pass removes stale rows when their parent module
            // has been disabled.
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('PTA Cron: ensure_events_scheduled error - ' . $e->getMessage(), array('module' => 'Cron'));
            } else {
                error_log('PTA Cron: ensure_events_scheduled error - ' . $e->getMessage());
            }
        }
    }

    /**
     * Clear module-owned cron hooks when their parent module is disabled.
     *
     * WordPress cron rows live in `wp_options['cron']`, so a hook can remain
     * scheduled long after a module has been turned off. Keeping those rows
     * causes WP-Cron to repeatedly try work for disabled features. This method
     * makes the cron table converge back to the current module settings.
     *
     * @param array $settings Azure plugin settings.
     */
    private static function clear_disabled_module_events(array $settings) {
        $module_hooks = array(
            'enable_pta' => array(
                'pta_daily_cleanup',
                'pta_process_sync_queue',
                'pta_sync_o365_groups_scheduled',
                'pta_sync_group_memberships_scheduled',
            ),
            'enable_newsletter' => array(
                'azure_newsletter_process_queue',
                'azure_newsletter_check_bounces',
                'azure_newsletter_weekly_validation',
                'azure_newsletter_sync_mailgun_stats',
            ),
            'enable_calendar' => array(
                'azure_calendar_token_refresh',
                'azure_calendar_sync_events',
            ),
            'enable_email' => array(
                'azure_mail_token_refresh',
                'azure_process_email_queue',
            ),
            'enable_volunteer' => array(
                'azure_volunteer_send_reminders',
            ),
            'enable_sso' => array(
                'azure_sso_scheduled_sync',
            ),
            'enable_backup' => array(
                'azure_backup_scheduled',
                'azure_backup_cleanup',
            ),
            'enable_onedrive_media' => array(
                'onedrive_media_auto_sync',
            ),
            'enable_tickets' => array(
                'azure_tickets_cleanup_reservations',
            ),
            'enable_auction' => array(
                'azure_auction_finalize_orphans',
            ),
        );

        foreach ($module_hooks as $module_key => $hooks) {
            if (!empty($settings[$module_key])) {
                continue;
            }
            foreach ($hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }
        }

        // PTA groups sync is a sub-feature of the PTA module. Clear its jobs
        // when PTA is off OR when the groups sync toggle is off.
        if (empty($settings['enable_pta']) || empty($settings['enable_pta_groups_sync'])) {
            wp_clear_scheduled_hook('pta_sync_o365_groups_scheduled');
            wp_clear_scheduled_hook('pta_sync_group_memberships_scheduled');
        }

        // SSO has its own sub-toggle; don't keep syncing users when the SSO
        // module is enabled but scheduled sync is off.
        if (empty($settings['enable_sso']) || empty($settings['sso_sync_enabled'])) {
            wp_clear_scheduled_hook('azure_sso_scheduled_sync');
        }

        // TEC integration was retired in v3.97. Sweep any lingering cron
        // rows left over from old installs so wp-cron doesn't keep trying
        // to fire callbacks that no longer exist.
        wp_clear_scheduled_hook('azure_tec_scheduled_sync');
        self::clear_hooks_by_prefix('azure_tec_mapping_sync_');

        // Auction has per-auction one-shot events keyed by product id
        // (`azure_auction_finalize` with [product_id] args). When the module
        // is off, the action handler isn't bound and the events would just
        // log "No action attached" warnings — clear them outright. Use the
        // exact-match branch since the hook name itself doesn't carry a
        // suffix; only the args differ.
        if (empty($settings['enable_auction'])) {
            self::clear_hook_all_args('azure_auction_finalize');
        }
    }

    /**
     * Clear every scheduled event for a hook regardless of args. Needed for
     * dynamic-args hooks like `azure_auction_finalize` where each event
     * carries the product id as args, so a plain
     * `wp_clear_scheduled_hook($hook)` (no args) won't match.
     *
     * @param string $hook Exact hook name.
     */
    private static function clear_hook_all_args($hook) {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return;
        }
        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks) || !isset($hooks[$hook]) || !is_array($hooks[$hook])) {
                continue;
            }
            foreach ($hooks[$hook] as $event) {
                $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : array();
                wp_unschedule_event((int) $timestamp, $hook, $args);
            }
        }
    }

    /**
     * Clear every scheduled hook whose name starts with a prefix.
     *
     * @param string $prefix Hook name prefix.
     */
    private static function clear_hooks_by_prefix($prefix) {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return;
        }

        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (strpos($hook, $prefix) !== 0 || !is_array($events)) {
                    continue;
                }
                foreach ($events as $event) {
                    $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : array();
                    wp_unschedule_event((int) $timestamp, $hook, $args);
                }
            }
        }
    }

    /**
     * Schedule a recurring event if it's not already scheduled.
     *
     * @param string   $hook       Action hook name.
     * @param string   $recurrence Cron schedule slug (e.g. 'hourly', 'five_minutes').
     * @param int|null $first_run  Optional unix timestamp for first run; defaults to now.
     */
    private static function ensure($hook, $recurrence, $first_run = null) {
        if (wp_next_scheduled($hook)) {
            return;
        }
        if ($first_run === null) {
            $first_run = time();
        }
        wp_schedule_event($first_run, $recurrence, $hook);
    }
}
