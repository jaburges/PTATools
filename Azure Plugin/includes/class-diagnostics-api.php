<?php
/**
 * Diagnostics REST API
 *
 * Exposes read-only diagnostic endpoints secured by a per-site API key.
 * Endpoints live under /wp-json/pta-tools/v1/diagnostics/...
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Diagnostics_API {

    private static $instance = null;
    const OPTION_KEY = 'azure_diagnostics_api_key';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Get or auto-generate the API key (stored as a WP option).
     */
    public static function get_api_key() {
        $key = get_option(self::OPTION_KEY);
        if (!$key) {
            $key = wp_generate_password(48, false);
            update_option(self::OPTION_KEY, $key, false);
        }
        return $key;
    }

    /**
     * Regenerate the API key.
     */
    public static function regenerate_api_key() {
        $key = wp_generate_password(48, false);
        update_option(self::OPTION_KEY, $key, false);
        return $key;
    }

    /**
     * Permission callback — validates the X-Diag-Key header.
     */
    public function check_api_key($request) {
        $provided = $request->get_header('X-Diag-Key');
        if (!$provided) {
            $provided = $request->get_param('key');
        }
        return $provided && hash_equals(self::get_api_key(), $provided);
    }

    public function register_routes() {
        $ns = 'pta-tools/v1';
        $auth = array($this, 'check_api_key');

        register_rest_route($ns, '/diagnostics/health', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_health'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/logs', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_logs'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/php-errors', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_php_errors'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/cron', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_cron'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/options', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_options'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/modules', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_modules'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/tables', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_tables'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/subscribers', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_subscribers'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/drop-tables', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_drop_tables'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/media-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_media_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/product-image-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_product_image_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/fix-media-dates', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_fix_media_dates'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/content-url-scan', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_content_url_scan'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/featured-image-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_featured_image_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/product-image-repair', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_product_image_repair'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/wc-order-ids', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_wc_order_ids'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/insert-order', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_insert_order'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/fix-bb-html-quotes', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array($this, 'route_fix_bb_html_quotes'),
            'permission_callback' => $auth,
        ));

        // GET reports users_can_register + default_role. POST flips them.
        // Used to enable self-service signup for live-event scenarios
        // (e.g. auction night) without round-tripping through WP admin.
        register_rest_route($ns, '/diagnostics/registration', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'route_registration_status'),
                'permission_callback' => $auth,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'route_registration_set'),
                'permission_callback' => $auth,
            ),
        ));

        // Rebuild display_name from first_name + last_name usermeta for a
        // single user. Used to clean up parents whose display_name still
        // looks auto-generated from their email after a merge.
        register_rest_route($ns, '/diagnostics/rebuild-display-name', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_rebuild_display_name'),
            'permission_callback' => $auth,
        ));

        // Merge two parent users. Preserves WooCommerce order history (legacy
        // post-store + HPOS), connected-family / user-children rows, and
        // non-conflicting user_meta. The "loser" account is then deleted via
        // wp_delete_user($loser, $winner) so any remaining post_author rows
        // (auctions, etc.) get reassigned to the winner.
        register_rest_route($ns, '/diagnostics/merge-parents', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array($this, 'route_merge_parents'),
            'permission_callback' => $auth,
        ));

        // Parent-role login readiness audit. Verifies the role exists with
        // `read` capability and reports how many parent users are actually
        // able to sign in vs. blocked by the `_pta_login_disabled` meta gate.
        register_rest_route($ns, '/diagnostics/parent-login-state', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_login_state'),
            'permission_callback' => $auth,
        ));

        // GET /event-counts — TEC -> pta_event migration visibility.
        // Reports how many tribe_events vs pta_event posts exist by
        // status, plus the current pta_calendar_owner flag value. Used
        // throughout phases 0-5 to verify dual-write parity.
        register_rest_route($ns, '/diagnostics/event-counts', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_event_counts'),
            'permission_callback' => $auth,
        ));

        // GET /auction-active — list active auction-product items the BB
        // Auction Carousel and [auction-display] shortcode use, so we can
        // verify the data source matches what's rendered on the page.
        register_rest_route($ns, '/diagnostics/auction-active', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_auction_active'),
            'permission_callback' => $auth,
        ));

        // GET /newsletter-state — surface the newsletter settings the
        // editor depends on (sender addresses, sending service) so we
        // can debug missing UI fields without WP-CLI.
        register_rest_route($ns, '/diagnostics/newsletter-state', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_newsletter_state'),
            'permission_callback' => $auth,
        ));

        // POST /auction-product-reset — clear bids for a single auction
        // product and/or update its starting bid (regular price). Used to
        // reset an auction product after test bids before going live, or
        // to correct a starting price typo. Body:
        //   { product_id, starting_bid?, clear_bids?, dry_run? }
        register_rest_route($ns, '/diagnostics/auction-product-reset', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_auction_product_reset'),
            'permission_callback' => $auth,
        ));

        // POST /event-flags — flip TEC migration flags between phases.
        // Body: { "owner": "tec|both|pta", "reader": "tribe|both|pta" }.
        // Either field is optional; missing fields are left unchanged.
        register_rest_route($ns, '/diagnostics/event-flags', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_event_flags'),
            'permission_callback' => $auth,
        ));

        // GET /event-parity — Phase 2 dual-write verification.
        // Reports tribe_events that should have a pta_event mirror but
        // don't, and pta_event posts orphaned from any tribe_events.
        register_rest_route($ns, '/diagnostics/event-parity', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_event_parity'),
            'permission_callback' => $auth,
        ));

        // POST /event-mirror-test — manually trigger the dual-write
        // mirror for a single tribe_events post, useful for verifying
        // Phase 2 code paths without waiting for the Outlook cron sync.
        // Body: { "tec_id": <int> } or query ?tec_id=<int>.
        register_rest_route($ns, '/diagnostics/event-mirror-test', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_event_mirror_test'),
            'permission_callback' => $auth,
        ));

        // POST /event-backfill — Phase 3 historical backfill.
        // Body: { "batch_size": <int 1..500>, "cursor": <int>,
        //         "dry_run": <bool>, "include_local": <bool> }
        // Returns counts + last_processed_id + has_more so the caller
        // can resume by passing cursor=last_processed_id on next call.
        register_rest_route($ns, '/diagnostics/event-backfill', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_event_backfill'),
            'permission_callback' => $auth,
        ));

        // GET /event-compare — side-by-side query of the same date
        // range against tribe_events and pta_event. Used to verify
        // Phase 4 read-side parity and surface any filter divergence
        // (TEC adds pre_get_posts filters we don't replicate yet).
        // Query: ?days=14 (default 14, max 90)
        register_rest_route($ns, '/diagnostics/event-compare', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_event_compare'),
            'permission_callback' => $auth,
        ));

        // GET /diagnostics/calendar-state — inspect calendar embed
        // configuration, auth tokens, and (optionally) live event-fetch
        // for a given calendar_id. Used to triage frontend shortcode
        // and admin preview-modal failures.
        // Query: ?calendar_id=<id>&days=30 (optional, runs a live fetch)
        register_rest_route($ns, '/diagnostics/calendar-state', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_calendar_state'),
            'permission_callback' => $auth,
        ));

        // GET /diagnostics/all-day-events — list every all-day event in
        // tribe_events / pta_event (the "WP database") together with its
        // Outlook ID (if any) and pta_event mirror status. Used to triage
        // "all-day events from TEC backend not appearing on the calendar
        // embed". The [azure_calendar] shortcode reads from Outlook only,
        // so any local-only TEC entries (no _outlook_event_id) won't show
        // up there — this endpoint surfaces exactly which events those are.
        register_rest_route($ns, '/diagnostics/all-day-events', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_all_day_events'),
            'permission_callback' => $auth,
        ));

        // POST /pta-categories-rebuild — one-shot remediation that walks
        // every existing (tribe_events -> pta_event) mirror and re-mirrors
        // category terms by NAME. Phase 5 surfaced that the original
        // mirror logic passed term IDs, which silently failed because
        // tribe_events_cat and pta_event_category are separate taxonomies
        // with disjoint term_taxonomy_id rows. This endpoint repairs
        // existing data without rerunning the whole event backfill.
        register_rest_route($ns, '/diagnostics/pta-categories-rebuild', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_pta_categories_rebuild'),
            'permission_callback' => $auth,
        ));

        // Beaver Builder modules diagnostic — reports which Azure Plugin BB
        // modules are loaded as PHP classes and whether they made it into
        // FLBuilderModel's registered_modules array. Useful when a module
        // file is on disk but doesn't appear in the BB editor's module
        // picker (file/load/registration mismatch).
        register_rest_route($ns, '/diagnostics/bb-modules', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_bb_modules'),
            'permission_callback' => $auth,
        ));

        // Parent-role duplicate audit. Returns parent users grouped by
        // several duplicate signals (lowercased email, name, email local-
        // part) so the operator can review and merge/delete duplicates
        // before campaign sends.
        register_rest_route($ns, '/diagnostics/parent-duplicates', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_duplicates'),
            'permission_callback' => $auth,
        ));

        // Auction publish-time bulk shift: works on post_date (scheduled-
        // publish time) of `future` status auction products. Used for
        // "delay today's 8 AM publish to 11:59 AM" event-day adjustments.
        // GET previews; POST applies + re-schedules publish_future_post cron.
        register_rest_route($ns, '/diagnostics/auction-shift-publish', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'route_auction_shift_publish_preview'),
                'permission_callback' => $auth,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'route_auction_shift_publish_apply'),
                'permission_callback' => $auth,
            ),
        ));

        // Auction end-time bulk shift: GET previews matching auctions for a
        // given source date; POST shifts them all to a new end datetime and
        // re-schedules the finalize cron. Used for "we need to push today's
        // 8 AM auctions to 11:59 AM" event-day adjustments.
        register_rest_route($ns, '/diagnostics/auction-shift-end', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'route_auction_shift_preview'),
                'permission_callback' => $auth,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'route_auction_shift_apply'),
                'permission_callback' => $auth,
            ),
        ));

        // Auction email test: probe whether AcyMailing's mailer is available
        // and (optionally) send a small test email through the same code path
        // the auction module uses (Azure_Auction_Emails::send_via_best_path).
        // POST { "to": "you@example.com" } sends a test; GET reports availability.
        register_rest_route($ns, '/diagnostics/auction-email-test', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'route_auction_email_status'),
                'permission_callback' => $auth,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'route_auction_email_test'),
                'permission_callback' => $auth,
            ),
        ));

        // Idempotent register/refresh of the Parent role. POST writes,
        // GET reports current state. Used to repair sites where the
        // upgrade-path role registration was short-circuited.
        register_rest_route($ns, '/diagnostics/parent-role', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'route_parent_role_status'),
                'permission_callback' => $auth,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'route_parent_role_register'),
                'permission_callback' => $auth,
            ),
        ));

        // Provision-and-send-welcome for a single test user. Lets the
        // operator drive the new welcome flow end-to-end from the CLI
        // (e.g. an admin running curl with X-Diag-Key) without needing
        // to log in to wp-admin. Body params:
        //   email* (required), name, phone, child_name, child_grade,
        //   child_teacher, transport ('wp_mail'|'mailgun', default
        //   'wp_mail'), send (bool, default false), force (bool, default
        //   false). Returns {user_id, email, activation_url, sent, error}.
        register_rest_route($ns, '/diagnostics/parent-test-welcome', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_parent_test_welcome'),
            'permission_callback' => $auth,
        ));

        // Batched welcome blast: process up to ?limit=N (default 100)
        // unsent pending parents and send each the magic-link + temp
        // password welcome via the chosen transport. Driven from a
        // PowerShell loop that keeps calling until `remaining` hits 0.
        register_rest_route($ns, '/diagnostics/parent-welcome-blast', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_parent_welcome_blast'),
            'permission_callback' => $auth,
        ));

        // Read-only counterpart so the operator can see how many parents
        // are still in the unsent pool before kicking off another batch.
        register_rest_route($ns, '/diagnostics/parent-welcome-blast-status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_welcome_blast_status'),
            'permission_callback' => $auth,
        ));

        // Forensic audit of the welcome blast: confirms each parent who
        // was mailed has a UNIQUE token hash and reports activation state
        // (already activated, token still pending, token revoked, etc.).
        // Built specifically to prove/disprove the "all emails got the
        // same link" hypothesis after the 2026-05-06 blast.
        register_rest_route($ns, '/diagnostics/parent-welcome-token-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_welcome_token_audit'),
            'permission_callback' => $auth,
        ));

        // Server-side performance snapshot: OPcache stats, Redis object
        // cache stats, plugin count, autoload option size, wp-cron state.
        // Built 2026-05-06 to investigate the 5-7s origin TTFB observed
        // on mobile cold loads.
        register_rest_route($ns, '/diagnostics/perf-snapshot', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_perf_snapshot'),
            'permission_callback' => $auth,
        ));

        // Bulk demote/delete bloated autoload options (transient cache,
        // dead-plugin leftovers). Dry-run by default; pass execute=1 to
        // commit. Built 2026-05-06 to address the 2.1 MB autoload
        // payload the perf-snapshot endpoint surfaced.
        register_rest_route($ns, '/diagnostics/perf-autoload-cleanup', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_perf_autoload_cleanup'),
            'permission_callback' => $auth,
        ));

        // File-count breakdown by plugin / module — answers "how much is
        // Beaver Builder costing per request?" by bucketing the
        // get_included_files() list against active plugin slugs.
        register_rest_route($ns, '/diagnostics/perf-files-by-plugin', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_perf_files_by_plugin'),
            'permission_callback' => $auth,
        ));

        // Database content audit: post counts by type/status, postmeta
        // size, options size, table sizes, attachment + media library
        // stats, product/category counts, uploads folder fingerprint.
        // Answers "is the size of my media library/products/pages
        // contributing to slow page loads?"
        register_rest_route($ns, '/diagnostics/content-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_content_audit'),
            'permission_callback' => $auth,
        ));

        // Fetch a small, parent-migration-relevant slice of options so
        // the operator can confirm SSO + Newsletter wiring before triggering
        // a bulk send. Read-only; never returns secrets in cleartext.
        register_rest_route($ns, '/diagnostics/parent-migration-status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_migration_status'),
            'permission_callback' => $auth,
        ));

        // WooCommerce + Parent-role compatibility check. Verifies the
        // parent role has the caps WC checks at checkout, lists the
        // current user's WC orders if a user_id query param is provided,
        // and reports the union of caps needed vs granted.
        register_rest_route($ns, '/diagnostics/parent-wc-check', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_wc_check'),
            'permission_callback' => $auth,
        ));

        // Newsletter list audit + one-shot repair. GET returns a summary
        // of every list (id, name, type, criteria, dynamic count); POST
        // with ?action=repair_parents fixes the Parents list criteria to
        // the correct roles-array shape; POST with
        // ?action=delete&id=N deletes a custom list (and its members).
        // All operations are idempotent; safe to retry.
        register_rest_route($ns, '/diagnostics/newsletter-lists-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_newsletter_lists_audit'),
            'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/diagnostics/newsletter-lists-repair', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_newsletter_lists_repair'),
            'permission_callback' => $auth,
        ));

        // CLI-friendly batched AcyMailing import. POST with offset=N
        // processes up to BATCH_SIZE rows starting at that offset and
        // returns the next offset + per-bucket counts. Mirror of the
        // admin AJAX handler so we can drive the import from PowerShell
        // without an authenticated browser session.
        register_rest_route($ns, '/diagnostics/parent-acy-run', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_parent_acy_run'),
            'permission_callback' => $auth,
        ));

        // CLI-friendly batched subscriber→parent migration. POST with
        // limit=N (default 100) processes that many subscriber-only WP
        // users, attaches the `parent` role, and returns the count
        // remaining. Profile data is preserved (only role membership
        // changes; user_meta untouched).
        register_rest_route($ns, '/diagnostics/parent-subscriber-migrate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_parent_subscriber_migrate'),
            'permission_callback' => $auth,
        ));

        // Discovery: scan posts/pages for AcyMailing shortcodes so the
        // operator knows where to swap [pta_newsletter_signup] in. Also
        // returns the homepage post id for quick reference.
        register_rest_route($ns, '/diagnostics/find-acy-shortcodes', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_find_acy_shortcodes'),
            'permission_callback' => $auth,
        ));

        // Inspect AcyMailing's record for a recipient: does the user exist
        // in wp_acym_user, are they confirmed/unsubscribed/blocked, and
        // are there recent rows in wp_acym_user_stat (sends, opens,
        // bounces, fails). Useful when an AcyMailing transport send
        // claims success but the message never lands in the recipient's
        // inbox.
        register_rest_route($ns, '/diagnostics/acymailing-recipient-state', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_acymailing_recipient_state'),
            'permission_callback' => $auth,
        ));

        // Snapshot every AcyMailing list (id, name, active, visible,
        // subscriber count). Used to plan list consolidation before
        // bulk syncing the parent role into a fresh "All Parents"
        // list.
        register_rest_route($ns, '/diagnostics/acymailing-lists-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_acymailing_lists_audit'),
            'permission_callback' => $auth,
        ));

        // Consolidate AcyMailing lists: ensure an "All Parents" list
        // exists, ensure every parent-role WP user has a matching
        // wp_acym_user row + is subscribed to it, and deactivate
        // every other list except those in the keep list. Dry-run by
        // default; pass execute=1 to commit. Pass keep_ids=1,20 (etc.)
        // to override the keep list (default keeps Wilder Staff = id 20
        // plus the All Parents list itself).
        register_rest_route($ns, '/diagnostics/acymailing-consolidate', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_acymailing_consolidate'),
            'permission_callback' => $auth,
        ));

        // Peek at the most recent rows from the email_logs table so the
        // operator can verify which transport (Graph / HVE / ACS / SMTP /
        // AcyMailing / WordPress default) actually carried a given send.
        // GET ?limit=N&to=email — both optional; returns to_email,
        // from_email, subject, method, status, plugin_source, created_at.
        register_rest_route($ns, '/diagnostics/email-logs-tail', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_email_logs_tail'),
            'permission_callback' => $auth,
        ));

        // Inspect activation state for a user — does a token exist? when
        // does it expire? Read-only; never returns the token hash itself.
        register_rest_route($ns, '/diagnostics/parent-activation-state', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_parent_activation_state'),
            'permission_callback' => $auth,
        ));

        // Targeted shortcode swap: replace [acymailing_form_shortcode ...]
        // (and similar AcyMailing tags) with [pta_newsletter_signup] in
        // both post_content and Beaver Builder's _fl_builder_data /
        // _fl_builder_draft postmeta. Backs up the previous values so the
        // change is reversible. POST with post_id=N + dry_run=1|0.
        register_rest_route($ns, '/diagnostics/swap-acy-shortcode', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_swap_acy_shortcode'),
            'permission_callback' => $auth,
        ));

        // Cleanup audit: one-shot inventory of the plugins we may want
        // to retire — Printify, Advanced Product Fields for WC, TEC
        // Event Aggregator, BB PowerPack, WPCode — plus current WC
        // shipping zones/methods and DB traces of each. Read-only.
        register_rest_route($ns, '/diagnostics/cleanup-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_cleanup_audit'),
            'permission_callback' => $auth,
        ));

        // POST /cleanup-execute — execute a list of low-risk cleanup
        // actions (deactivate dormant plugins, unschedule orphan crons,
        // purge dead postmeta). Requires `confirm=yes-i-am-sure` AND
        // `dry_run=0` to actually mutate state. Backs up everything
        // it deletes to wp-content/uploads/pta-cleanup-backups/.
        register_rest_route($ns, '/diagnostics/cleanup-execute', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_cleanup_execute'),
            'permission_callback' => $auth,
        ));

        // GET /apf-inspect — dump the postmeta rows that Advanced
        // Product Fields owns, so we can see which products use it
        // and what fields they configure before deactivating the plugin.
        register_rest_route($ns, '/diagnostics/apf-inspect', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_apf_inspect'),
            'permission_callback' => $auth,
        ));

        // GET /bb-page-inspect?post_id=N — flat list of every BB
        // module on a post, with its type slug, so we can plan a
        // PowerPack-to-native rebuild module by module.
        register_rest_route($ns, '/diagnostics/bb-page-inspect', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_bb_page_inspect'),
            'permission_callback' => $auth,
        ));

        // GET /post-carousel-inspect?post_id=N — for a given page,
        // dump the FULL settings of every BB Post Carousel / Post Grid
        // / Post Feed module so we can see exactly what filter is in
        // effect (post_type, taxonomy, order, custom IDs).
        register_rest_route($ns, '/diagnostics/post-carousel-inspect', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_post_carousel_inspect'),
            'permission_callback' => $auth,
        ));

        // POST /post-type-convert — convert a post between post_type
        // values (page <-> post by default), preserving BB data, meta,
        // featured image, author, with optional category assignment,
        // optional post_date refresh, and optional 301 redirect from
        // the old permalink.
        register_rest_route($ns, '/diagnostics/post-type-convert', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_post_type_convert'),
            'permission_callback' => $auth,
        ));
    }

    // --- Route handlers ---

    public function route_health($request) {
        global $wpdb;

        $db_ok = (bool) $wpdb->get_var('SELECT 1');
        $upload_dir = wp_upload_dir();

        return rest_ensure_response(array(
            'status'      => 'ok',
            'wp_version'  => get_bloginfo('version'),
            'php_version' => phpversion(),
            'plugin_version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'unknown',
            'memory_limit'     => ini_get('memory_limit'),
            'max_upload_size'  => size_format(wp_max_upload_size()),
            'max_execution_time' => ini_get('max_execution_time'),
            'database'    => $db_ok ? 'connected' : 'error',
            'uploads_writable' => wp_is_writable($upload_dir['basedir']),
            'server_time' => current_time('mysql'),
            'utc_time'    => gmdate('Y-m-d H:i:s'),
            'active_plugins' => array_values(get_option('active_plugins', array())),
        ));
    }

    public function route_logs($request) {
        $lines  = (int) $request->get_param('lines') ?: 100;
        $level  = sanitize_text_field($request->get_param('level') ?: '');
        $module = sanitize_text_field($request->get_param('module') ?: '');

        $lines = min(max($lines, 10), 500);

        if (!class_exists('Azure_Logger') || !Azure_Logger::is_initialized()) {
            return rest_ensure_response(array('logs' => array(), 'error' => 'Logger not initialized'));
        }

        $logs = Azure_Logger::get_formatted_logs($lines, $level, $module);

        return rest_ensure_response(array(
            'count' => count($logs),
            'logs'  => $logs,
        ));
    }

    public function route_php_errors($request) {
        $lines = (int) $request->get_param('lines') ?: 50;
        $lines = min(max($lines, 10), 200);

        $log_path = ini_get('error_log');
        if (!$log_path || !file_exists($log_path) || !is_readable($log_path)) {
            $debug_log = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($debug_log) && is_readable($debug_log)) {
                $log_path = $debug_log;
            } else {
                return rest_ensure_response(array('lines' => array(), 'path' => $log_path ?: 'not set'));
            }
        }

        $all = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tail = array_slice($all, -$lines);

        return rest_ensure_response(array(
            'path'  => $log_path,
            'count' => count($tail),
            'lines' => $tail,
        ));
    }

    public function route_cron($request) {
        $crons = _get_cron_array();
        $plugin_hooks = array();

        $plugin_prefixes = array('azure_', 'onedrive_media_', 'pta_', 'backup_');

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                $is_plugin = false;
                foreach ($plugin_prefixes as $prefix) {
                    if (strpos($hook, $prefix) === 0) {
                        $is_plugin = true;
                        break;
                    }
                }
                if (!$is_plugin) {
                    continue;
                }
                foreach ($events as $key => $event) {
                    $plugin_hooks[] = array(
                        'hook'      => $hook,
                        'next_run'  => gmdate('Y-m-d H:i:s', $timestamp),
                        'schedule'  => $event['schedule'] ?: 'single',
                        'interval'  => isset($event['interval']) ? $event['interval'] : null,
                        'args'      => $event['args'],
                    );
                }
            }
        }

        usort($plugin_hooks, function ($a, $b) {
            return strcmp($a['next_run'], $b['next_run']);
        });

        return rest_ensure_response(array(
            'count' => count($plugin_hooks),
            'jobs'  => $plugin_hooks,
        ));
    }

    public function route_options($request) {
        $settings = Azure_Settings::get_all_settings();

        $safe = array();
        $sensitive = array('client_secret', 'password', 'secret', 'key', 'token');
        foreach ($settings as $k => $v) {
            $is_secret = false;
            foreach ($sensitive as $s) {
                if (stripos($k, $s) !== false) {
                    $is_secret = true;
                    break;
                }
            }
            $safe[$k] = $is_secret ? '***' : $v;
        }

        return rest_ensure_response($safe);
    }

    public function route_modules($request) {
        $modules = array(
            'sso'            => 'sso',
            'backup'         => 'backup',
            'onedrive_media' => 'onedrive_media',
            'calendar'       => 'calendar',
            'newsletter'     => 'newsletter',
            'pta_roles'      => 'pta_roles',
            'classes'        => 'classes',
            'tickets'        => 'tickets',
            'auction'        => 'auction',
        );

        $status = array();
        foreach ($modules as $label => $key) {
            $status[$label] = Azure_Settings::is_module_enabled($key);
        }

        return rest_ensure_response($status);
    }

    public function route_tables($request) {
        global $wpdb;

        $all_tables = $wpdb->get_col('SHOW TABLES');
        $prefix = $wpdb->prefix;

        $wp_core = array(
            'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
            'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users',
        );
        $core_set = array_flip(array_map(function ($t) use ($prefix) {
            return $prefix . $t;
        }, $wp_core));

        $core = array();
        $plugin = array();
        foreach ($all_tables as $table) {
            if (isset($core_set[$table])) {
                $core[] = $table;
            } else {
                $plugin[] = $table;
            }
        }

        return rest_ensure_response(array(
            'prefix'       => $prefix,
            'total'        => count($all_tables),
            'core_count'   => count($core),
            'plugin_count' => count($plugin),
            'core'         => $core,
            'plugin'       => $plugin,
        ));
    }

    public function route_subscribers($request) {
        global $wpdb;

        $result = array();

        $has_mailpoet = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mailpoet_subscribers'");
        if ($has_mailpoet) {
            $rows = $wpdb->get_results(
                "SELECT email, first_name, last_name, status, created_at FROM {$wpdb->prefix}mailpoet_subscribers ORDER BY email",
                ARRAY_A
            );
            $result['mailpoet'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['mailpoet'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $has_fluentcrm = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fc_subscribers'");
        if ($has_fluentcrm) {
            $rows = $wpdb->get_results(
                "SELECT email, first_name, last_name, status, created_at FROM {$wpdb->prefix}fc_subscribers ORDER BY email",
                ARRAY_A
            );
            $result['fluentcrm'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['fluentcrm'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $has_acymailing = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}acym_user'");
        if ($has_acymailing) {
            $rows = $wpdb->get_results(
                "SELECT email, name, active, confirmed, creation_date FROM {$wpdb->prefix}acym_user ORDER BY email",
                ARRAY_A
            );
            $result['acymailing'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['acymailing'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $emails = array();
        foreach (array('mailpoet', 'fluentcrm', 'acymailing') as $src) {
            foreach ($result[$src]['subscribers'] as $sub) {
                $e = strtolower(trim($sub['email']));
                if (!isset($emails[$e])) {
                    $emails[$e] = array();
                }
                $emails[$e][] = $src;
            }
        }

        $only_mailpoet = array();
        $only_fluentcrm = array();
        $in_all = array();
        foreach ($emails as $email => $sources) {
            $has_acym = in_array('acymailing', $sources);
            if (!$has_acym && in_array('mailpoet', $sources) && !in_array('fluentcrm', $sources)) {
                $only_mailpoet[] = $email;
            } elseif (!$has_acym && in_array('fluentcrm', $sources) && !in_array('mailpoet', $sources)) {
                $only_fluentcrm[] = $email;
            }
            if (count($sources) === 3) {
                $in_all[] = $email;
            }
        }

        $result['comparison'] = array(
            'total_unique_emails' => count($emails),
            'in_all_three'        => count($in_all),
            'only_in_mailpoet'    => $only_mailpoet,
            'only_in_fluentcrm'   => $only_fluentcrm,
            'missing_from_acymailing' => array_values(array_diff(
                array_keys($emails),
                array_map('strtolower', array_column($result['acymailing']['subscribers'], 'email'))
            )),
        );

        return rest_ensure_response($result);
    }

    public function route_drop_tables($request) {
        global $wpdb;

        $body = json_decode($request->get_body(), true);
        $tables = isset($body['tables']) ? $body['tables'] : array();

        if (empty($tables) || !is_array($tables)) {
            return new \WP_Error('invalid_request', 'Provide a "tables" array in the JSON body.', array('status' => 400));
        }

        $prefix = $wpdb->prefix;
        $core_tables = array(
            $prefix . 'commentmeta', $prefix . 'comments', $prefix . 'links', $prefix . 'options',
            $prefix . 'postmeta', $prefix . 'posts', $prefix . 'term_relationships',
            $prefix . 'term_taxonomy', $prefix . 'termmeta', $prefix . 'terms',
            $prefix . 'usermeta', $prefix . 'users',
        );

        $dropped = array();
        $skipped = array();
        $errors  = array();

        foreach ($tables as $table) {
            $table = sanitize_key($table);

            if (in_array($table, $core_tables)) {
                $skipped[] = $table . ' (core table — protected)';
                continue;
            }

            if (strpos($table, $prefix) !== 0) {
                $skipped[] = $table . ' (wrong prefix)';
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if (!$exists) {
                $skipped[] = $table . ' (not found)';
                continue;
            }

            // SHOW TABLES returns both tables and views. Detect views via
            // information_schema so we can pick the correct DROP statement —
            // DROP TABLE on a view fails with "Unknown table" on MySQL 8+.
            $is_view = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            )) === 'VIEW';

            $sql = $is_view
                ? "DROP VIEW IF EXISTS `{$table}`"
                : "DROP TABLE IF EXISTS `{$table}`";
            $result = $wpdb->query($sql);

            if ($result !== false) {
                $dropped[] = $is_view ? ($table . ' (view)') : $table;
            } else {
                $errors[] = $table . ': ' . $wpdb->last_error;
            }
        }

        return rest_ensure_response(array(
            'dropped' => count($dropped),
            'skipped' => count($skipped),
            'errors'  => count($errors),
            'details' => array(
                'dropped' => $dropped,
                'skipped' => $skipped,
                'errors'  => $errors,
            ),
        ));
    }

    public function route_media_audit($request) {
        global $wpdb;

        $year = $request->get_param('year') ?: '2026';
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.guid, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND pm.meta_value LIKE %s
             ORDER BY pm.meta_value",
            $year . '/%'
        ), ARRAY_A);

        $missing_file = array();
        $wrong_guid = array();
        $sharepoint_urls = array();
        $no_month = array();
        $ok = 0;

        foreach ($rows as $row) {
            $rel = $row['attached_file'];
            $full_path = $basedir . '/' . $rel;
            $expected_url = $baseurl . '/' . $rel;

            if (preg_match('|^https?://|', $rel)) {
                $sharepoint_urls[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                );
                continue;
            }

            if (!preg_match('|^\d{4}/\d{2}/|', $rel)) {
                $no_month[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                    'file_exists' => file_exists($full_path),
                );
                continue;
            }

            $issues = array();
            if (!file_exists($full_path)) {
                $issues[] = 'file_missing';
            }
            if ($row['guid'] !== $expected_url) {
                $issues[] = 'guid_mismatch';
            }

            if (!empty($issues)) {
                $entry = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                    'guid' => $row['guid'],
                    'expected_url' => $expected_url,
                    'issues' => $issues,
                );
                if (in_array('file_missing', $issues)) {
                    $missing_file[] = $entry;
                } else {
                    $wrong_guid[] = $entry;
                }
            } else {
                $ok++;
            }
        }

        $product_images = $wpdb->get_results(
            "SELECT p.ID, p.post_title,
                    tm.meta_value AS thumbnail_id,
                    pm2.meta_value AS thumbnail_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} tm ON p.ID = tm.post_id AND tm.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} pm2 ON tm.meta_value = pm2.post_id AND pm2.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND (tm.meta_value IS NULL OR tm.meta_value = '' OR pm2.meta_value IS NULL)",
            ARRAY_A
        );

        $products_no_image = array();
        foreach ($product_images as $pi) {
            $products_no_image[] = array(
                'product_id' => $pi['ID'],
                'title' => $pi['post_title'],
                'thumbnail_id' => $pi['thumbnail_id'],
            );
        }

        return rest_ensure_response(array(
            'year' => $year,
            'total_attachments' => count($rows),
            'ok' => $ok,
            'missing_file' => array('count' => count($missing_file), 'items' => array_slice($missing_file, 0, 50)),
            'wrong_guid' => array('count' => count($wrong_guid), 'items' => array_slice($wrong_guid, 0, 50)),
            'sharepoint_urls' => array('count' => count($sharepoint_urls), 'items' => array_slice($sharepoint_urls, 0, 20)),
            'no_month_subfolder' => array('count' => count($no_month), 'items' => array_slice($no_month, 0, 20)),
            'products_missing_image' => array('count' => count($products_no_image), 'items' => array_slice($products_no_image, 0, 20)),
        ));
    }

    public function route_fix_media_dates($request) {
        global $wpdb;
        @set_time_limit(300);

        $dry_run = $request->get_param('dry_run') !== 'false';

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_date, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             ORDER BY p.ID"
        );

        $updated = 0;
        $skipped = 0;
        $already_correct = 0;
        $no_date_path = 0;
        $samples = array();

        foreach ($rows as $row) {
            if (!preg_match('#^(\d{4})/(\d{2})/#', $row->attached_file, $m)) {
                $no_date_path++;
                continue;
            }

            $year  = (int) $m[1];
            $month = (int) $m[2];

            if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12) {
                $skipped++;
                continue;
            }

            $existing_year  = (int) date('Y', strtotime($row->post_date));
            $existing_month = (int) date('m', strtotime($row->post_date));

            if ($existing_year === $year && $existing_month === $month) {
                $already_correct++;
                continue;
            }

            $new_date = sprintf('%04d-%02d-01 00:00:00', $year, $month);

            if (!$dry_run) {
                $wpdb->update(
                    $wpdb->posts,
                    array(
                        'post_date'     => $new_date,
                        'post_date_gmt' => $new_date,
                    ),
                    array('ID' => $row->ID),
                    array('%s', '%s'),
                    array('%d')
                );
            }

            $updated++;
            if (count($samples) < 10) {
                $samples[] = array(
                    'id'        => (int) $row->ID,
                    'file'      => $row->attached_file,
                    'old_date'  => $row->post_date,
                    'new_date'  => $new_date,
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run'         => $dry_run,
            'total_checked'   => count($rows),
            'already_correct' => $already_correct,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'no_date_in_path' => $no_date_path,
            'samples'         => $samples,
        ));
    }

    public function route_content_url_scan($request) {
        global $wpdb;
        @set_time_limit(300);

        $all_like = array(
            'sharepoint'  => '%sharepoint.com%',
            'attachments' => '%attachments.office.net%',
            '1drv'        => '%1drv.ms%',
        );
        $all_php = array(
            'sharepoint'  => '#https?://[a-zA-Z0-9\-]+\.sharepoint\.com[^\"\'\s<>]+#i',
            'attachments' => '#https?://attachments\.office\.net[^\"\'\s<>]+#i',
            '1drv'        => '#https?://1drv\.ms[^\"\'\s<>]+#i',
        );

        $filter = $request->get_param('pattern');
        if ($filter && isset($all_like[$filter])) {
            $like_patterns = array($filter => $all_like[$filter]);
            $php_patterns  = array($filter => $all_php[$filter]);
        } else {
            $like_patterns = $all_like;
            $php_patterns  = $all_php;
        }

        $results = array();
        $summary = array();

        foreach ($like_patterns as $label => $like) {
            $rows = $wpdb->get_results(
                "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_content LIKE '{$like}' AND post_status IN ('publish','draft','private','pending') ORDER BY post_type, ID LIMIT 200"
            );

            $items = array();
            foreach ($rows as $row) {
                $content = get_post_field('post_content', $row->ID);
                preg_match_all($php_patterns[$label], $content, $matches);
                $unique_urls = array_values(array_unique($matches[0]));
                $items[] = array(
                    'post_id'    => (int) $row->ID,
                    'title'      => $row->post_title,
                    'post_type'  => $row->post_type,
                    'status'     => $row->post_status,
                    'url_count'  => count($unique_urls),
                    'sample_urls' => array_slice($unique_urls, 0, 3),
                );
            }

            $summary[$label] = count($items);
            if (!empty($items)) {
                $results[$label] = $items;
            }
        }

        if (!$filter || $filter === 'attachments_meta') {
            $guid_rows = $wpdb->get_results(
                "SELECT ID, post_title, guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (guid LIKE '%sharepoint.com%' OR guid LIKE '%attachments.office.net%' OR guid LIKE '%1drv.ms%') LIMIT 50"
            );
            $bad_guids = array();
            foreach ($guid_rows as $gr) {
                $bad_guids[] = array(
                    'attachment_id' => (int) $gr->ID,
                    'title'         => $gr->post_title,
                    'guid'          => $gr->guid,
                );
            }

            $meta_rows = $wpdb->get_results(
                "SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_attached_file' AND (pm.meta_value LIKE '%sharepoint.com%' OR pm.meta_value LIKE '%attachments.office.net%' OR pm.meta_value LIKE '%1drv.ms%') LIMIT 50"
            );
            $bad_meta = array();
            foreach ($meta_rows as $mr) {
                $bad_meta[] = array(
                    'attachment_id' => (int) $mr->ID,
                    'title'         => $mr->post_title,
                    'attached_file' => $mr->meta_value,
                );
            }
        }

        $response = array('summary' => $summary, 'content_matches' => $results);
        if (isset($bad_guids)) {
            $response['attachment_bad_guids'] = array('count' => count($bad_guids), 'items' => $bad_guids);
        }
        if (isset($bad_meta)) {
            $response['attachment_bad_meta'] = array('count' => count($bad_meta), 'items' => $bad_meta);
        }

        return rest_ensure_response($response);
    }

    public function route_featured_image_audit($request) {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        $post_type = $request->get_param('post_type') ?: 'post';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_type,
                    thumb.meta_value AS thumbnail_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             WHERE p.post_type = %s
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID",
            $post_type
        ), ARRAY_A);

        $ok = 0;
        $no_thumbnail = array();
        $broken = array();
        $never_had = 0;

        foreach ($rows as $row) {
            $tid = intval($row['thumbnail_id']);

            if (!$tid) {
                $no_thumbnail[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                );
                continue;
            }

            $att = get_post($tid);
            $file = get_post_meta($tid, '_wp_attached_file', true);

            if (!$att) {
                $broken[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                    'thumbnail_id' => $tid,
                    'issue' => 'attachment_deleted',
                );
            } elseif (!$file || !file_exists($basedir . '/' . $file)) {
                $broken[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                    'thumbnail_id' => $tid,
                    'attached_file' => $file,
                    'issue' => $file ? 'file_missing' : 'no_meta',
                );
            } else {
                $ok++;
            }
        }

        $published_no_thumb = array_filter($no_thumbnail, function ($i) { return $i['status'] === 'publish'; });
        $published_broken = array_filter($broken, function ($i) { return $i['status'] === 'publish'; });

        return rest_ensure_response(array(
            'post_type' => $post_type,
            'total' => count($rows),
            'ok' => $ok,
            'no_thumbnail_set' => array(
                'total' => count($no_thumbnail),
                'published' => count($published_no_thumb),
                'items' => array_values(array_slice($no_thumbnail, 0, 50)),
            ),
            'broken_thumbnail' => array(
                'total' => count($broken),
                'published' => count($published_broken),
                'items' => array_values(array_slice($broken, 0, 50)),
            ),
        ));
    }

    public function route_product_image_repair($request) {
        global $wpdb;

        $dry_run = $request->get_param('dry_run') !== 'false';
        $post_type = $request->get_param('post_type') ?: 'all';
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        $where_type = $post_type === 'all'
            ? "p.post_type IN ('post', 'page', 'product', 'tribe_events')"
            : $wpdb->prepare("p.post_type = %s", $post_type);

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_content, p.post_status, p.post_type,
                    thumb.meta_value AS thumbnail_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             WHERE {$where_type}
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (thumb.meta_value IS NULL OR thumb.meta_value = '' OR thumb.meta_value = '0')
             ORDER BY p.ID",
            ARRAY_A
        );

        $repaired = array();
        $unresolved = array();

        foreach ($rows as $row) {
            $found_id = $this->find_image_in_content($row['post_content'], $basedir, $wpdb);

            if ($found_id) {
                if (!$dry_run) {
                    update_post_meta($row['ID'], '_thumbnail_id', $found_id['id']);
                }
                $repaired[] = array(
                    'post_id' => $row['ID'],
                    'title' => $row['post_title'],
                    'type' => $row['post_type'],
                    'status' => $row['post_status'],
                    'attachment_id' => $found_id['id'],
                    'file' => $found_id['file'],
                    'method' => $found_id['method'],
                );
            } else {
                $unresolved[] = array(
                    'post_id' => $row['ID'],
                    'title' => $row['post_title'],
                    'type' => $row['post_type'],
                    'status' => $row['post_status'],
                    'has_images' => (bool) preg_match('/<img/i', $row['post_content']),
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run' => $dry_run,
            'post_type' => $post_type,
            'total_missing' => count($rows),
            'repaired' => array('count' => count($repaired), 'items' => array_slice($repaired, 0, 100)),
            'unresolved' => array('count' => count($unresolved), 'items' => array_slice($unresolved, 0, 100)),
        ));
    }

    private function find_image_in_content($content, $basedir, $wpdb) {
        if (preg_match_all('/wp-content\/uploads\/(\d{4}\/\d{2}\/[^\s"\'<>)]+\.(jpe?g|png|gif|webp))/i', $content, $matches)) {
            foreach ($matches[1] as $rel_path) {
                $rel_clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $rel_path);
                foreach (array($rel_path, $rel_clean) as $try_path) {
                    $att = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $try_path
                    ));
                    if ($att && file_exists($basedir . '/' . $try_path)) {
                        return array('id' => intval($att), 'file' => $try_path, 'method' => 'content_url');
                    }
                }
            }
        }

        if (preg_match_all('/wp-image-(\d+)/i', $content, $matches)) {
            foreach ($matches[1] as $att_id) {
                $att_id = intval($att_id);
                $file = get_post_meta($att_id, '_wp_attached_file', true);
                if ($file && file_exists($basedir . '/' . $file)) {
                    return array('id' => $att_id, 'file' => $file, 'method' => 'wp_image_class');
                }
            }
        }

        if (preg_match_all('/src=["\']([^"\']*\/wp-content\/uploads\/[^"\']+\.(jpe?g|png|gif|webp))["\']/', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (preg_match('/wp-content\/uploads\/(\d{4}\/\d{2}\/[^"\'?#]+)/i', $url, $m)) {
                    $rel = $m[1];
                    $rel_clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $rel);
                    foreach (array($rel, $rel_clean) as $try_path) {
                        $att = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta}
                             WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                            $try_path
                        ));
                        if ($att) {
                            return array('id' => intval($att), 'file' => $try_path, 'method' => 'img_src');
                        }
                    }
                }
            }
        }

        return null;
    }

    public function route_product_image_audit($request) {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $products = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status,
                    thumb.meta_value AS thumbnail_id,
                    gallery.meta_value AS gallery_ids
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} gallery ON p.ID = gallery.post_id AND gallery.meta_key = '_product_image_gallery'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID",
            ARRAY_A
        );

        $broken_thumb = array();
        $broken_gallery = array();
        $no_thumb = array();
        $ok = 0;

        foreach ($products as $prod) {
            $tid = intval($prod['thumbnail_id']);
            if (!$tid) {
                $no_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'status' => $prod['post_status'],
                );
                continue;
            }

            $att_file = get_post_meta($tid, '_wp_attached_file', true);
            $att_exists = get_post($tid);

            if (!$att_exists) {
                $broken_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'thumbnail_id' => $tid,
                    'issue' => 'attachment_post_missing',
                );
            } elseif (!$att_file || !file_exists($basedir . '/' . $att_file)) {
                $broken_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'thumbnail_id' => $tid,
                    'attached_file' => $att_file,
                    'issue' => $att_file ? 'file_missing_on_disk' : 'no_attached_file_meta',
                );
            } else {
                $ok++;
            }

            $gal = trim($prod['gallery_ids']);
            if ($gal) {
                $gal_ids = array_filter(array_map('intval', explode(',', $gal)));
                foreach ($gal_ids as $gid) {
                    $gatt = get_post($gid);
                    $gfile = get_post_meta($gid, '_wp_attached_file', true);
                    if (!$gatt || !$gfile || !file_exists($basedir . '/' . $gfile)) {
                        $broken_gallery[] = array(
                            'product_id' => $prod['ID'],
                            'title' => $prod['post_title'],
                            'gallery_attachment_id' => $gid,
                            'attached_file' => $gfile ?: null,
                            'issue' => !$gatt ? 'attachment_post_missing' : (!$gfile ? 'no_attached_file_meta' : 'file_missing_on_disk'),
                        );
                    }
                }
            }
        }

        return rest_ensure_response(array(
            'total_products' => count($products),
            'ok' => $ok,
            'no_thumbnail_set' => array('count' => count($no_thumb), 'items' => $no_thumb),
            'broken_thumbnail' => array('count' => count($broken_thumb), 'items' => array_slice($broken_thumb, 0, 30)),
            'broken_gallery' => array('count' => count($broken_gallery), 'items' => array_slice($broken_gallery, 0, 30)),
        ));
    }

    /**
     * GET /diagnostics/wc-order-ids[?product_names=a,b,c][&emails=x@y,z@w]
     *
     * Reports the MAX id across every WooCommerce table (legacy post-store
     * + HPOS) — useful when investigating HPOS sync drift or after a
     * partial backup-restore. Optional query params let callers look up
     * specific product titles or customer emails ad-hoc; nothing is
     * hardcoded in source.
     */
    public function route_wc_order_ids($request) {
        global $wpdb;

        $max_post_id           = $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        $max_wc_order_id       = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders");
        $max_addr_id           = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_order_addresses");
        $max_op_id             = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_order_operational_data");
        $max_item_id           = $wpdb->get_var("SELECT MAX(order_item_id) FROM {$wpdb->prefix}woocommerce_order_items");
        $max_itemmeta_id       = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->prefix}woocommerce_order_itemmeta");
        $max_ordermeta_id      = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders_meta");
        $max_postmeta_id       = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
        $max_stats_id          = $wpdb->get_var("SELECT MAX(order_id) FROM {$wpdb->prefix}wc_order_stats");
        $max_product_lookup_id = $wpdb->get_var("SELECT MAX(order_item_id) FROM {$wpdb->prefix}wc_order_product_lookup");

        $products  = array();
        $customers = array();

        $product_names_param = (string) $request->get_param('product_names');
        if ($product_names_param !== '') {
            $names = array_filter(array_map('trim', explode(',', $product_names_param)));
            foreach ($names as $name) {
                $found = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts}
                     WHERE post_type = 'product' AND post_title LIKE %s AND post_status = 'publish'
                     LIMIT 5",
                    '%' . $wpdb->esc_like($name) . '%'
                ), ARRAY_A);
                $products[$name] = $found;
            }
        }

        $emails_param = (string) $request->get_param('emails');
        if ($emails_param !== '') {
            $emails = array_filter(array_map('trim', explode(',', $emails_param)));
            foreach ($emails as $email) {
                if (!is_email($email)) continue;
                $cust = $wpdb->get_row($wpdb->prepare(
                    "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s LIMIT 1",
                    $email
                ), ARRAY_A);
                $customers[$email] = $cust ? intval($cust['customer_id']) : 0;
            }
        }

        return rest_ensure_response(array(
            'max_post_id'           => intval($max_post_id),
            'max_wc_order_id'       => intval($max_wc_order_id),
            'max_addr_id'           => intval($max_addr_id),
            'max_op_id'             => intval($max_op_id),
            'max_item_id'           => intval($max_item_id),
            'max_itemmeta_id'       => intval($max_itemmeta_id),
            'max_ordermeta_id'      => intval($max_ordermeta_id),
            'max_postmeta_id'       => intval($max_postmeta_id),
            'max_stats_id'          => intval($max_stats_id),
            'max_product_lookup_id' => intval($max_product_lookup_id),
            'products'              => $products,
            'customers'             => $customers,
        ));
    }

    public function route_insert_order($request) {
        global $wpdb;

        $body = $request->get_json_params();
        if (empty($body['sql_statements']) || !is_array($body['sql_statements'])) {
            return new WP_Error('missing_sql', 'Provide sql_statements array', array('status' => 400));
        }

        if (!empty($body['dry_run'])) {
            return rest_ensure_response(array(
                'dry_run' => true,
                'statement_count' => count($body['sql_statements']),
                'statements' => $body['sql_statements'],
            ));
        }

        $results = array();
        $wpdb->query('START TRANSACTION');

        foreach ($body['sql_statements'] as $i => $sql) {
            $ok = $wpdb->query($sql);
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('sql_error', "Statement $i failed: " . $wpdb->last_error, array('status' => 500));
            }
            $results[] = array('index' => $i, 'affected' => $ok);
        }

        $wpdb->query('COMMIT');

        return rest_ensure_response(array(
            'success' => true,
            'results' => $results,
        ));
    }

    /**
     * Fix Beaver Builder per-page JS cache breakage caused by HTML attribute values
     * stored with double quotes inside string fields (e.g. expire_message containing
     * <a href="https://...">). When BB serializes settings to per-page cache JS, it
     * wraps string values in double quotes, so any inner unescaped " ends the string
     * literal and breaks the file with "Unexpected identifier 'https'".
     *
     * Strategy: rewrite double-quoted HTML attributes inside _fl_builder_data and
     * _fl_builder_draft postmeta to single-quoted form (same byte length), then
     * delete the post's BB cache files so BB regenerates them on next view.
     *
     * Params:
     *   - post_id (required)
     *   - dry_run=1   - report what would change without writing
     *   - commit=1    - actually write (default behavior is commit; dry_run takes precedence)
     */
    public function route_fix_bb_html_quotes($request) {
        $post_id = (int) $request->get_param('post_id');
        if (!$post_id) {
            return new WP_Error('missing_post_id', 'post_id is required', array('status' => 400));
        }
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', "Post $post_id not found", array('status' => 404));
        }

        $dry_run = (bool) $request->get_param('dry_run');

        $report = array();
        $samples = array();
        foreach (array('_fl_builder_data', '_fl_builder_draft') as $meta_key) {
            $data = get_post_meta($post_id, $meta_key, true);
            if (empty($data)) {
                $report[$meta_key] = array('present' => false);
                continue;
            }

            $changed = 0;
            $sample_pairs = array();
            self::fix_bb_walk($data, $changed, $sample_pairs);

            $report[$meta_key] = array(
                'present'           => true,
                'strings_changed'   => $changed,
                'samples'           => array_slice($sample_pairs, 0, 5),
            );

            if ($changed > 0 && !$dry_run) {
                update_post_meta($post_id, $meta_key, $data);
            }
        }

        $cache_cleared = array();
        if (!$dry_run) {
            $upload = wp_upload_dir();
            $cache_dir = trailingslashit($upload['basedir']) . 'bb-plugin/cache/';
            if (is_dir($cache_dir)) {
                foreach (glob($cache_dir . $post_id . '-layout*') as $f) {
                    if (@unlink($f)) {
                        $cache_cleared[] = basename($f);
                    }
                }
            }
        }

        return rest_ensure_response(array(
            'post_id'       => $post_id,
            'dry_run'       => $dry_run,
            'report'        => $report,
            'cache_cleared' => $cache_cleared,
        ));
    }

    /**
     * Recursively walk BB data (array of objects with .settings) and rewrite
     * double-quoted HTML attributes to single-quoted form inside string values.
     * Preserves byte length so PHP's serialized string lengths stay valid even if
     * the value were ever stored as a serialized blob.
     */
    private static function fix_bb_walk(&$data, &$changed, &$samples) {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                self::fix_bb_walk($data[$k], $changed, $samples);
            }
            return;
        }
        if (is_object($data)) {
            foreach (get_object_vars($data) as $prop => $val) {
                self::fix_bb_walk($data->$prop, $changed, $samples);
            }
            return;
        }
        if (!is_string($data)) {
            return;
        }
        if (strpos($data, '<') === false || strpos($data, '"') === false) {
            return;
        }

        $original = $data;
        // Inside any HTML tag (between < and >), rewrite attr="value" to attr='value'.
        // Only matches when the attribute value contains no embedded double quotes,
        // which is the safe, common case (and the case that triggered our break).
        $data = preg_replace_callback(
            '/<([a-zA-Z][a-zA-Z0-9]*)([^<>]*)>/',
            function ($m) {
                $tag_name = $m[1];
                $attrs    = $m[2];
                $attrs    = preg_replace(
                    '/(\s+[a-zA-Z_][a-zA-Z0-9_:.\-]*)\s*=\s*"([^"]*)"/',
                    "$1='$2'",
                    $attrs
                );
                return '<' . $tag_name . $attrs . '>';
            },
            $data
        );

        if ($data !== $original) {
            $changed++;
            if (count($samples) < 10) {
                $samples[] = array(
                    'before' => mb_substr($original, 0, 240),
                    'after'  => mb_substr($data, 0, 240),
                );
            }
        }
    }

    /**
     * GET /diagnostics/registration
     * Reports the current values of users_can_register and default_role.
     */
    public function route_registration_status($request) {
        return rest_ensure_response(array(
            'users_can_register' => (bool) get_option('users_can_register'),
            'default_role'       => (string) get_option('default_role'),
            'available_roles'    => array_keys(wp_roles()->roles),
        ));
    }

    /**
     * POST /diagnostics/registration
     * Body: { "users_can_register": bool, "default_role": "customer"|"parent"|... }
     * Either field is optional — only the keys present in the body are
     * written. The default_role is validated against wp_roles() so we can't
     * leave the site in a state where new signups land in a non-existent
     * role.
     */
    public function route_registration_set($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('invalid_request', 'Body must be JSON.', array('status' => 400));
        }

        $changes = array();

        if (array_key_exists('users_can_register', $body)) {
            $val = !empty($body['users_can_register']) ? 1 : 0;
            update_option('users_can_register', $val);
            $changes['users_can_register'] = (bool) $val;
        }

        if (array_key_exists('default_role', $body)) {
            $role = sanitize_key((string) $body['default_role']);
            $available = array_keys(wp_roles()->roles);
            if (!in_array($role, $available, true)) {
                return new WP_Error(
                    'invalid_role',
                    'default_role must be one of: ' . implode(', ', $available),
                    array('status' => 400)
                );
            }
            update_option('default_role', $role);
            $changes['default_role'] = $role;
        }

        if (empty($changes)) {
            return new WP_Error('no_changes', 'No supported keys in body.', array('status' => 400));
        }

        return rest_ensure_response(array(
            'changed'            => $changes,
            'users_can_register' => (bool) get_option('users_can_register'),
            'default_role'       => (string) get_option('default_role'),
        ));
    }

    /**
     * GET /diagnostics/auction-shift-publish?date=YYYY-MM-DD
     * Returns auction products with post_status='future' whose post_date
     * (scheduled publish time, in WP-tz) falls on the given date. No mutation.
     */
    public function route_auction_shift_publish_preview($request) {
        $date = sanitize_text_field((string) $request->get_param('date'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('bad_request', 'Provide ?date=YYYY-MM-DD', array('status' => 400));
        }
        return rest_ensure_response($this->collect_future_auctions_publishing_on($date));
    }

    /**
     * POST /diagnostics/auction-shift-publish
     * Body: {
     *   "current_date": "2026-05-06",            // required, WP-tz date filter
     *   "new_publish":  "2026-05-06 11:59:00",   // required, WP-tz datetime
     *   "dry_run":      true|false               // optional, default false
     * }
     * For every future-status auction with post_date on current_date, updates
     * post_date + post_date_gmt to new_publish and re-arms the cron event
     * that auto-publishes scheduled posts.
     */
    public function route_auction_shift_publish_apply($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('bad_request', 'Body must be JSON.', array('status' => 400));
        }
        $current_date = isset($body['current_date']) ? sanitize_text_field($body['current_date']) : '';
        $new_publish  = isset($body['new_publish']) ? sanitize_text_field($body['new_publish']) : '';
        $dry_run      = !empty($body['dry_run']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_date)) {
            return new WP_Error('bad_request', 'current_date must be YYYY-MM-DD', array('status' => 400));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $new_publish)) {
            return new WP_Error('bad_request', 'new_publish must be YYYY-MM-DD HH:MM[:SS]', array('status' => 400));
        }
        if (substr_count($new_publish, ':') === 1) {
            $new_publish .= ':00';
        }

        $tz = wp_timezone();
        $new_local_dt = date_create_from_format('Y-m-d H:i:s', $new_publish, $tz);
        if (!$new_local_dt) {
            return new WP_Error('bad_request', 'new_publish could not be parsed.', array('status' => 400));
        }
        $new_local_str = $new_local_dt->format('Y-m-d H:i:s');
        $new_gmt_str   = (clone $new_local_dt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $matches = $this->collect_future_auctions_publishing_on($current_date);
        $changed = array();
        $errors  = array();

        if (!$dry_run) {
            foreach ($matches['auctions'] as $row) {
                $product_id = (int) $row['product_id'];
                if ($product_id <= 0) continue;

                // Clear the existing publish-future cron arm before mutating
                // post_date so the schedule_event hook in wp_update_post can
                // place a fresh one without dupes. The hook fires from
                // wp_publish_post() once the time is reached.
                wp_clear_scheduled_hook('publish_future_post', array($product_id));

                $update = array(
                    'ID'            => $product_id,
                    'post_date'     => $new_local_str,
                    'post_date_gmt' => $new_gmt_str,
                    'edit_date'     => true,
                    'post_status'   => 'future',
                );
                $result = wp_update_post($update, true);
                if (is_wp_error($result)) {
                    $errors[] = array(
                        'product_id' => $product_id,
                        'error'      => $result->get_error_message(),
                    );
                    continue;
                }

                // wp_update_post for status=future re-arms publish_future_post
                // automatically via check_and_publish_future_post(), but only
                // when the new date is in the future. Add a defensive arm
                // here for sites where that path is short-circuited.
                if (!wp_next_scheduled('publish_future_post', array($product_id))) {
                    wp_schedule_single_event(
                        strtotime($new_gmt_str . ' GMT'),
                        'publish_future_post',
                        array($product_id)
                    );
                }

                $changed[] = array(
                    'product_id'    => $product_id,
                    'title'         => $row['title'],
                    'old_post_date' => $row['post_date_local'],
                    'new_post_date' => $new_local_str,
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run'      => $dry_run,
            'wp_timezone'  => $tz->getName(),
            'current_date' => $current_date,
            'new_publish'  => $new_local_str,
            'new_gmt'      => $new_gmt_str,
            'matched'      => count($matches['auctions']),
            'changed'      => $changed,
            'errors'       => $errors,
            'preview'      => $matches['auctions'],
        ));
    }

    /**
     * Helper: find every future-status product of type 'auction' whose
     * post_date falls on the given YYYY-MM-DD in the WP timezone.
     */
    private function collect_future_auctions_publishing_on($date) {
        global $wpdb;

        // Filter on post_date (WP local-tz string) and on type=auction via
        // _auction_bidding_end existence (set on every auction product).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_date, p.post_date_gmt,
                    pm_end.meta_value AS bidding_end
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_end
                 ON pm_end.post_id = p.ID AND pm_end.meta_key = '_auction_bidding_end'
             WHERE p.post_type = 'product'
               AND p.post_status = 'future'
               AND DATE(p.post_date) = %s
             ORDER BY p.post_date ASC, p.ID ASC",
            $date
        ), ARRAY_A);

        $tz = wp_timezone();
        $auctions = array();
        foreach ((array) $rows as $row) {
            $next_publish = function_exists('wp_next_scheduled')
                ? wp_next_scheduled('publish_future_post', array((int) $row['ID']))
                : false;

            $auctions[] = array(
                'product_id'        => (int) $row['ID'],
                'title'             => $row['post_title'],
                'post_status'       => $row['post_status'],
                'post_date_local'   => $row['post_date'],
                'post_date_gmt'     => $row['post_date_gmt'],
                'bidding_end_raw'   => $row['bidding_end'],
                'next_publish_cron' => $next_publish ?: null,
            );
        }

        return array(
            'wp_timezone' => $tz->getName(),
            'date_filter' => $date,
            'auctions'    => $auctions,
        );
    }

    /**
     * GET /diagnostics/auction-shift-end?date=YYYY-MM-DD
     * Returns the auctions whose _auction_bidding_end falls on the given
     * date (in the WordPress timezone). No mutation. Use this before a POST
     * to confirm the matching set.
     */
    public function route_auction_shift_preview($request) {
        $date = sanitize_text_field((string) $request->get_param('date'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('bad_request', 'Provide ?date=YYYY-MM-DD', array('status' => 400));
        }
        return rest_ensure_response($this->collect_auctions_ending_on($date));
    }

    /**
     * POST /diagnostics/auction-shift-end
     * Body: {
     *   "current_date": "2026-05-06",            // required, WP-tz date filter
     *   "new_end":      "2026-05-06 11:59:00",   // required, WP-tz datetime
     *   "dry_run":      true|false               // optional, default false
     * }
     * For every auction with _auction_bidding_end falling on current_date,
     * updates the meta to new_end and re-schedules its azure_auction_finalize
     * one-shot cron event.
     */
    public function route_auction_shift_apply($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('bad_request', 'Body must be JSON.', array('status' => 400));
        }
        $current_date = isset($body['current_date']) ? sanitize_text_field($body['current_date']) : '';
        $new_end      = isset($body['new_end']) ? sanitize_text_field($body['new_end']) : '';
        $dry_run      = !empty($body['dry_run']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $current_date)) {
            return new WP_Error('bad_request', 'current_date must be YYYY-MM-DD', array('status' => 400));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $new_end)) {
            return new WP_Error('bad_request', 'new_end must be YYYY-MM-DD HH:MM[:SS]', array('status' => 400));
        }
        // Normalize new_end to include seconds to match how auction-product-type
        // saves the meta (date + ' ' + time + ':00').
        if (substr_count($new_end, ':') === 1) {
            $new_end .= ':00';
        }

        // Sanity-check the new datetime parses in WP timezone.
        $tz = wp_timezone();
        $new_end_dt = date_create_from_format('Y-m-d H:i:s', $new_end, $tz);
        if (!$new_end_dt) {
            return new WP_Error('bad_request', 'new_end could not be parsed.', array('status' => 400));
        }

        $matches = $this->collect_auctions_ending_on($current_date);
        $changed = array();
        $skipped = array();

        if (!$dry_run) {
            foreach ($matches['auctions'] as $row) {
                $product_id = (int) $row['product_id'];
                if ($product_id <= 0) {
                    continue;
                }
                update_post_meta($product_id, '_auction_bidding_end', $new_end);

                if (class_exists('Azure_Auction_Lifecycle')) {
                    Azure_Auction_Lifecycle::schedule_finalize_event($product_id, $new_end);
                }

                $changed[] = array(
                    'product_id' => $product_id,
                    'title'      => $row['title'],
                    'old_end'    => $row['bidding_end_raw'],
                    'new_end'    => $new_end,
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run'      => $dry_run,
            'wp_timezone'  => $tz->getName(),
            'current_date' => $current_date,
            'new_end'      => $new_end,
            'matched'      => count($matches['auctions']),
            'changed'      => $changed,
            'skipped'      => $skipped,
            'preview'      => $matches['auctions'],
        ));
    }

    /**
     * Helper: find every product of type 'auction' whose _auction_bidding_end
     * falls on the given YYYY-MM-DD in the WordPress site timezone.
     *
     * Stored values come in two flavors:
     *   - "YYYY-MM-DD HH:MM:SS" (string, treated as WP-tz local time)
     *   - "1715000000"          (unix timestamp)
     */
    private function collect_auctions_ending_on($date) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status, pm_end.meta_value AS bidding_end
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_end
                 ON pm_end.post_id = p.ID AND pm_end.meta_key = '_auction_bidding_end'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','private','draft','future')
               AND pm_end.meta_value <> ''",
            ARRAY_A
        );

        $tz = wp_timezone();
        $auctions = array();

        foreach ((array) $rows as $row) {
            $raw = (string) $row['bidding_end'];
            if (is_numeric($raw)) {
                $dt = (new DateTimeImmutable('@' . (int) $raw))->setTimezone($tz);
            } else {
                // "YYYY-MM-DD HH:MM:SS" stored without explicit tz — auction
                // product type writes it in WP-tz local time (whatever the
                // admin picked in the date/time inputs).
                $dt = date_create_immutable_from_format('Y-m-d H:i:s', $raw, $tz);
                if (!$dt) {
                    $stamp = strtotime($raw);
                    if (!$stamp) continue;
                    $dt = (new DateTimeImmutable('@' . $stamp))->setTimezone($tz);
                }
            }
            if ($dt->format('Y-m-d') !== $date) {
                continue;
            }

            // Surface the next-scheduled finalize cron for visibility.
            $next_finalize = function_exists('wp_next_scheduled')
                ? wp_next_scheduled('azure_auction_finalize', array((int) $row['ID']))
                : false;

            // For future-status auctions, post_date is the auto-publish time
            // (i.e. when the auction goes live for bidding). Surface it so
            // the operator can see/shift the start time alongside end time.
            $post = get_post((int) $row['ID']);
            $post_date_local = $post ? $post->post_date : null;

            $auctions[] = array(
                'product_id'         => (int) $row['ID'],
                'title'              => $row['post_title'],
                'post_status'        => $row['post_status'],
                'post_date_local'    => $post_date_local,
                'bidding_end_raw'    => $raw,
                'bidding_end_local'  => $dt->format('Y-m-d H:i:s'),
                'bidding_end_unix'   => $dt->getTimestamp(),
                'next_finalize_cron' => $next_finalize ?: null,
            );
        }

        return array(
            'wp_timezone' => $tz->getName(),
            'date_filter' => $date,
            'auctions'    => $auctions,
        );
    }

    /**
     * GET /diagnostics/auction-email-test
     * Reports which mail paths are available without sending anything.
     */
    public function route_auction_email_status($request) {
        $acy_class    = '\\AcyMailing\\Helpers\\MailerHelper';
        $acy_loaded   = class_exists($acy_class);
        $acy_setting  = (int) get_option('auction_email_use_acymailing', 1) === 1;
        $auction_emails_loaded = class_exists('Azure_Auction_Emails');

        return rest_ensure_response(array(
            'acymailing_class_loaded'    => $acy_loaded,
            'acymailing_setting_enabled' => $acy_setting,
            'auction_emails_class_ready' => $auction_emails_loaded,
            'preferred_path'             => ($acy_loaded && $acy_setting) ? 'acymailing' : 'wp_mail',
            'option_to_disable'          => 'auction_email_use_acymailing (set to 0 to force wp_mail)',
        ));
    }

    /**
     * POST /diagnostics/auction-email-test
     * Body: { "to": "addr@example.com", "subject"?: string }
     * Sends a tiny test email through Azure_Auction_Emails' send path so we
     * can verify AcyMailing routing end-to-end.
     */
    public function route_auction_email_test($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body) || empty($body['to'])) {
            return new WP_Error('bad_request', 'Body must be JSON with a "to" address.', array('status' => 400));
        }
        $to = sanitize_email($body['to']);
        if (!is_email($to)) {
            return new WP_Error('bad_request', 'Invalid "to" address.', array('status' => 400));
        }
        if (!class_exists('Azure_Auction_Emails')) {
            return new WP_Error('not_loaded', 'Azure_Auction_Emails class is not loaded on this request.', array('status' => 500));
        }

        // We invoke send_via_best_path through reflection so the test exercises
        // the exact same routing the real winner/outbid emails use.
        $emails = new Azure_Auction_Emails();
        $rc = new \ReflectionClass($emails);
        $m  = $rc->getMethod('send_via_best_path');
        $m->setAccessible(true);

        $subject = !empty($body['subject']) ? sanitize_text_field($body['subject']) : 'PTA Auction email test';
        $html_body = '<p>This is a test of the auction email send path.</p>'
                   . '<p>If you got this in your <strong>inbox</strong> (not spam), the AcyMailing routing is working.</p>'
                   . '<p>Sent at ' . esc_html(current_time('mysql')) . '.</p>';

        $result = $m->invoke($emails, $to, $subject, $html_body, array('context' => 'diag-test'));

        return rest_ensure_response(array(
            'ok'          => (bool) $result['ok'],
            'method_used' => $result['method'],
            'to'          => $to,
            'subject'     => $subject,
        ));
    }

    /**
     * GET /diagnostics/parent-role
     * Reports whether the Parent role exists in wp_user_roles plus its
     * current capability map.
     */
    public function route_parent_role_status($request) {
        $role = get_role('parent');
        $subscriber = get_role('subscriber');
        return rest_ensure_response(array(
            'exists'              => (bool) $role,
            'parent_capabilities' => $role ? $role->capabilities : null,
            'subscriber_capabilities' => $subscriber ? $subscriber->capabilities : null,
        ));
    }

    /**
     * POST /diagnostics/parent-role
     * Idempotent register/refresh of the Parent role using
     * Azure_Parent_Role::register_role(). Safe to call any number of times.
     */
    public function route_parent_role_register($request) {
        if (!class_exists('Azure_Parent_Role')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
            if (!file_exists($path)) {
                return new WP_Error('parent_role_missing', 'class-parent-role.php not found', array('status' => 500));
            }
            require_once $path;
        }
        if (!class_exists('Azure_Parent_Role')) {
            return new WP_Error('parent_role_class_missing', 'Azure_Parent_Role class did not load', array('status' => 500));
        }

        Azure_Parent_Role::register_role();
        $role = get_role('parent');

        return rest_ensure_response(array(
            'registered'          => (bool) $role,
            'parent_capabilities' => $role ? $role->capabilities : null,
        ));
    }

    /**
     * GET /diagnostics/parent-duplicates
     *
     * Buckets every user with the `parent` role by several duplicate
     * signals so the operator can spot and clean up duplicates before any
     * bulk send. Each signal is reported separately so the operator can
     * choose which to act on (some are exact, some are heuristic).
     *
     * Buckets:
     *   - by_email        : exact lowercased trimmed user_email matches
     *                       (theoretically WP-unique, but case/whitespace
     *                       collisions still happen on imports).
     *   - by_email_local  : same local-part before @ (catches alice@gmail.com
     *                       and alice@yahoo.com — same person, different
     *                       provider). Drops the bucket if all addresses are
     *                       the same exact email (already in by_email).
     *   - by_name         : same lowercased trimmed display_name OR same
     *                       lowercased "first_name last_name" from usermeta.
     *   - by_login        : same lowercased trimmed user_login.
     *
     * Only buckets with 2+ members are returned.
     *
     * Optional ?include_users=1 returns the full list of parent users too.
     */
    public function route_parent_duplicates($request) {
        $include_users = !empty($request->get_param('include_users'));

        $users = get_users(array(
            'role'   => 'parent',
            'fields' => array('ID', 'user_login', 'user_email', 'display_name', 'user_registered'),
            'orderby'=> 'ID',
            'order'  => 'ASC',
            'number' => -1,
        ));

        // Augment with first_name + last_name from usermeta in one shot so
        // we don't N+1 against the meta table.
        global $wpdb;
        $ids = array_map(function ($u) { return (int) $u->ID; }, $users);
        $names_by_user = array();
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_key, meta_value
                 FROM {$wpdb->usermeta}
                 WHERE meta_key IN ('first_name','last_name')
                   AND user_id IN ($placeholders)",
                $ids
            ), ARRAY_A);
            foreach ($rows as $r) {
                $names_by_user[(int) $r['user_id']][$r['meta_key']] = (string) $r['meta_value'];
            }
        }

        // Build flat list with normalized keys we'll bucket on.
        $parents = array();
        foreach ($users as $u) {
            $first = $names_by_user[(int) $u->ID]['first_name'] ?? '';
            $last  = $names_by_user[(int) $u->ID]['last_name'] ?? '';
            $combined_name = trim($first . ' ' . $last);

            $email_norm = strtolower(trim((string) $u->user_email));
            $local      = '';
            if ($email_norm !== '' && strpos($email_norm, '@') !== false) {
                $local = substr($email_norm, 0, strpos($email_norm, '@'));
            }

            $name_for_bucket = $combined_name !== '' ? $combined_name : (string) $u->display_name;
            $parents[] = array(
                'ID'                  => (int) $u->ID,
                'user_login'          => (string) $u->user_login,
                'user_email'          => (string) $u->user_email,
                'display_name'        => (string) $u->display_name,
                'first_name'          => $first,
                'last_name'           => $last,
                'user_registered'     => (string) $u->user_registered,
                '_norm_email'         => $email_norm,
                '_norm_email_local'   => $local,
                '_norm_login'         => strtolower(trim((string) $u->user_login)),
                '_norm_name'          => strtolower(trim($name_for_bucket)),
            );
        }

        // Group helper: $key_field => array of parent dicts.
        $group_by = function ($field) use ($parents) {
            $buckets = array();
            foreach ($parents as $p) {
                $k = $p[$field];
                if ($k === '' || $k === null) continue;
                $buckets[$k][] = $p;
            }
            return array_filter($buckets, function ($g) { return count($g) >= 2; });
        };

        $by_email_raw       = $group_by('_norm_email');
        $by_email_local_raw = $group_by('_norm_email_local');
        $by_name_raw        = $group_by('_norm_name');
        $by_login_raw       = $group_by('_norm_login');

        // For local-part buckets, drop ones where every member has the same
        // exact email (those are already exposed via by_email and would just
        // be noise here).
        $by_email_local = array();
        foreach ($by_email_local_raw as $local => $members) {
            $emails = array_unique(array_map(function ($m) { return $m['_norm_email']; }, $members));
            if (count($emails) >= 2) {
                $by_email_local[$local] = $members;
            }
        }

        $shape = function ($buckets) {
            $out = array();
            foreach ($buckets as $key => $members) {
                $clean_members = array();
                foreach ($members as $m) {
                    $clean_members[] = array(
                        'ID'              => $m['ID'],
                        'user_login'      => $m['user_login'],
                        'user_email'      => $m['user_email'],
                        'display_name'    => $m['display_name'],
                        'first_name'      => $m['first_name'],
                        'last_name'       => $m['last_name'],
                        'user_registered' => $m['user_registered'],
                    );
                }
                $out[] = array(
                    'key'     => (string) $key,
                    'count'   => count($members),
                    'members' => $clean_members,
                );
            }
            usort($out, function ($a, $b) { return $b['count'] <=> $a['count']; });
            return $out;
        };

        $response = array(
            'total_parent_users'     => count($parents),
            'duplicate_email'        => $shape($by_email_raw),
            'duplicate_email_local'  => $shape($by_email_local),
            'duplicate_name'         => $shape($by_name_raw),
            'duplicate_login'        => $shape($by_login_raw),
            'totals'                 => array(
                'duplicate_email_groups'        => count($by_email_raw),
                'duplicate_email_local_groups'  => count($by_email_local),
                'duplicate_name_groups'         => count($by_name_raw),
                'duplicate_login_groups'        => count($by_login_raw),
            ),
        );

        if ($include_users) {
            // Strip internal _norm_* keys before returning.
            $response['users'] = array_map(function ($p) {
                unset($p['_norm_email'], $p['_norm_email_local'], $p['_norm_login'], $p['_norm_name']);
                return $p;
            }, $parents);
        }

        return rest_ensure_response($response);
    }

    /**
     * GET /diagnostics/parent-login-state[?email=foo@bar.com]
     *
     * Answers "can parent-role users log in to the site right now?" by
     * checking:
     *   - Site-level: `users_can_register` and `default_role` options
     *   - Role-level: `parent` role exists, has `read` capability
     *   - Authenticate filter: `Azure_Parent_Role::block_disabled_logins`
     *     is registered (so disabled parents really get blocked, and
     *     active parents really get through)
     *   - Population-level: total parent users, broken down by
     *     `_pta_login_disabled` state
     *   - Spot-check: pick one currently-loggable parent and a sample
     *     of disabled ones to inspect manually
     *
     * If ?email= is supplied, returns a per-user verdict: would they pass
     * the login gate today? (Doesn't attempt the password — just walks
     * every block-rule in order.)
     */
    /**
     * GET /diagnostics/event-counts
     *
     * Phase 0+ visibility for the TEC -> pta_event migration.
     *
     * Reports:
     *   - Current `pta_calendar_owner` and `pta_calendar_data_source`
     *     flag values (drives migration phase behaviour).
     *   - Whether tribe_events / pta_event post types are registered
     *     in the current request context.
     *   - Counts of each post type by status (publish, future, draft,
     *     trash, ...).
     *   - Cross-link counts: how many TEC posts have a `_pta_event_mirror_id`
     *     pointer (set by Phase 2 dual-write or Phase 3 backfill).
     *   - Whether TEC plugin is active.
     */
    /**
     * GET /diagnostics/auction-active
     *
     * Returns the same list of active-auction items that the
     * BB Auction Carousel module and [auction-display] shortcode use.
     * Useful when the carousel renders empty on the front-end and
     * we need to confirm whether the upstream query has any rows.
     *
     * Response:
     *   - module_loaded:  bool  — Azure_Auction_Module class exists
     *   - wc_loaded:      bool  — WooCommerce class exists
     *   - count:          int   — number of items returned
     *   - sample:         array — up to 10 items with id/title/price/has_bids
     */
    public function route_auction_active($request) {
        $module_loaded = class_exists('Azure_Auction_Module');
        $wc_loaded     = class_exists('WooCommerce');

        if (!$module_loaded || !$wc_loaded) {
            return rest_ensure_response(array(
                'module_loaded' => $module_loaded,
                'wc_loaded'     => $wc_loaded,
                'count'         => 0,
                'sample'        => array(),
                'error'         => 'Auction module or WooCommerce not loaded in this request context.',
            ));
        }

        $items = Azure_Auction_Module::get_instance()->get_active_auction_display_items();
        $sample = array();
        foreach (array_slice($items, 0, 10) as $it) {
            $sample[] = array(
                'id'       => isset($it['id']) ? (int) $it['id'] : 0,
                'title'    => isset($it['title']) ? (string) $it['title'] : '',
                'price'    => isset($it['price']) ? (float) $it['price'] : 0.0,
                'has_bids' => !empty($it['has_bids']),
                'link'     => isset($it['link']) ? (string) $it['link'] : '',
            );
        }

        return rest_ensure_response(array(
            'module_loaded' => true,
            'wc_loaded'     => true,
            'count'         => count($items),
            'sample'        => $sample,
        ));
    }

    /**
     * POST /diagnostics/auction-product-reset
     *
     * Resets a single auction product: clears its bid history (audit
     * rows in wp_azure_auction_bids), and/or updates the starting bid
     * (_regular_price + _price meta). Useful after a test bid has been
     * placed and the auction needs to go live with a real starting
     * price.
     *
     * Body params (JSON):
     *   product_id   (int, required)  WC product ID
     *   starting_bid (float, optional) New starting bid in dollars.
     *                                  If omitted, price is left alone.
     *   clear_bids   (bool, optional, default true) Remove existing bids.
     *   dry_run      (bool, optional, default false) Report what would
     *                                  change without writing anything.
     */
    public function route_auction_product_reset($request) {
        global $wpdb;

        $body = $request->get_json_params();
        if (!is_array($body)) { $body = array(); }

        $product_id   = isset($body['product_id']) ? (int) $body['product_id'] : 0;
        $starting_bid = array_key_exists('starting_bid', $body) ? (float) $body['starting_bid'] : null;
        $clear_bids   = array_key_exists('clear_bids', $body) ? (bool) $body['clear_bids'] : true;
        $dry_run      = !empty($body['dry_run']);

        if ($product_id <= 0) {
            return new WP_Error(
                'azure_invalid_product',
                'product_id is required and must be a positive integer.',
                array('status' => 400)
            );
        }

        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') {
            return new WP_Error(
                'azure_not_a_product',
                'No WooCommerce product with that ID was found.',
                array('status' => 404)
            );
        }

        $bids_table = $wpdb->prefix . 'azure_auction_bids';
        $bid_count_before = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$bids_table} WHERE product_id = %d",
            $product_id
        ));

        $current_regular = (float) get_post_meta($product_id, '_regular_price', true);
        $current_price   = (float) get_post_meta($product_id, '_price', true);

        $actions = array();
        $actions[] = sprintf(
            'Found %d existing bid row(s) for product #%d (%s)',
            $bid_count_before,
            $product_id,
            get_the_title($post)
        );

        if ($clear_bids && $bid_count_before > 0) {
            if ($dry_run) {
                $actions[] = sprintf('Would delete %d bid row(s)', $bid_count_before);
            } else {
                $deleted = $wpdb->delete($bids_table, array('product_id' => $product_id), array('%d'));
                $actions[] = sprintf('Deleted %d bid row(s)', (int) $deleted);
            }
        } elseif (!$clear_bids) {
            $actions[] = 'clear_bids=false; bid history left intact';
        } else {
            $actions[] = 'No bids to clear';
        }

        if ($starting_bid !== null) {
            if ($starting_bid < 0) {
                return new WP_Error(
                    'azure_invalid_price',
                    'starting_bid must be >= 0.',
                    array('status' => 400)
                );
            }
            $formatted = number_format($starting_bid, 2, '.', '');
            if ($dry_run) {
                $actions[] = sprintf(
                    'Would update _regular_price/_price from %s/%s to %s',
                    $current_regular, $current_price, $formatted
                );
            } else {
                update_post_meta($product_id, '_regular_price', $formatted);
                update_post_meta($product_id, '_price', $formatted);
                wp_cache_delete($product_id, 'post_meta');
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($product_id);
                }
                $actions[] = sprintf(
                    'Updated _regular_price/_price from %s/%s to %s',
                    $current_regular, $current_price, $formatted
                );
            }
        } else {
            $actions[] = 'starting_bid not provided; price left unchanged';
        }

        $bid_count_after = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$bids_table} WHERE product_id = %d",
            $product_id
        ));

        return rest_ensure_response(array(
            'product_id'        => $product_id,
            'product_title'     => get_the_title($post),
            'permalink'         => get_permalink($post),
            'dry_run'           => $dry_run,
            'bids_before'       => $bid_count_before,
            'bids_after'        => $bid_count_after,
            'price_before'      => array(
                'regular' => $current_regular,
                'price'   => $current_price,
            ),
            'price_after'       => $dry_run ? null : array(
                'regular' => (float) get_post_meta($product_id, '_regular_price', true),
                'price'   => (float) get_post_meta($product_id, '_price', true),
            ),
            'actions'           => $actions,
        ));
    }

    /**
     * GET /diagnostics/newsletter-state
     *
     * Returns newsletter settings that the editor UI depends on, so
     * we can diagnose missing form fields (most commonly the "From"
     * dropdown, which only renders when sender addresses are
     * configured under Newsletter → Settings).
     */
    public function route_newsletter_state($request) {
        $settings = class_exists('Azure_Settings')
            ? Azure_Settings::get_all_settings()
            : array();

        $from_addresses = isset($settings['newsletter_from_addresses'])
            ? $settings['newsletter_from_addresses']
            : null;

        return rest_ensure_response(array(
            'newsletter_module_enabled' => !empty($settings['enable_newsletter']),
            'sending_service'           => isset($settings['newsletter_sending_service']) ? (string) $settings['newsletter_sending_service'] : null,
            'reply_to'                  => isset($settings['newsletter_reply_to']) ? (string) $settings['newsletter_reply_to'] : null,
            'from_addresses_type'       => is_array($from_addresses) ? 'array' : gettype($from_addresses),
            'from_addresses_count'      => is_array($from_addresses) ? count($from_addresses) : 0,
            'from_addresses'            => is_array($from_addresses) ? $from_addresses : null,
            'admin_email'               => get_option('admin_email'),
            'site_blogname'             => get_option('blogname'),
        ));
    }

    public function route_event_counts($request) {
        global $wpdb;

        $owner = class_exists('Azure_Settings')
            ? Azure_Settings::get_setting('pta_calendar_owner', 'tec')
            : 'tec';
        $reader = class_exists('Azure_Settings')
            ? Azure_Settings::get_setting('pta_calendar_data_source', 'tribe')
            : 'tribe';

        $tec_active = class_exists('Tribe__Events__Main');

        // Per-status counts.
        $count_by_status = function ($post_type) use ($wpdb) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_status, COUNT(*) AS c
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                 GROUP BY post_status
                 ORDER BY post_status ASC",
                $post_type
            ), ARRAY_A);
            $out = array();
            $total = 0;
            foreach ((array) $rows as $r) {
                $c = (int) $r['c'];
                $out[$r['post_status']] = $c;
                $total += $c;
            }
            $out['_total'] = $total;
            return $out;
        };

        // Mirror-pointer counts (set by Phase 2 dual-write / Phase 3 backfill).
        $tribe_with_mirror = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'tribe_events'
               AND pm.meta_key = '_pta_event_mirror_id'
               AND pm.meta_value > 0"
        );
        $pta_with_outlook = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'pta_event'
               AND pm.meta_key = '_outlook_event_id'
               AND pm.meta_value <> ''"
        );

        return rest_ensure_response(array(
            'flags' => array(
                'pta_calendar_owner'       => $owner,
                'pta_calendar_data_source' => $reader,
            ),
            'plugins' => array(
                'tec_active' => (bool) $tec_active,
            ),
            'post_types_registered' => array(
                'tribe_events'  => post_type_exists('tribe_events'),
                'tribe_venue'   => post_type_exists('tribe_venue'),
                'pta_event'     => post_type_exists('pta_event'),
                'pta_venue'     => post_type_exists('pta_venue'),
                'pta_organizer' => post_type_exists('pta_organizer'),
            ),
            'counts' => array(
                'tribe_events'  => $count_by_status('tribe_events'),
                'tribe_venue'   => $count_by_status('tribe_venue'),
                'pta_event'     => $count_by_status('pta_event'),
                'pta_venue'     => $count_by_status('pta_venue'),
                'pta_organizer' => $count_by_status('pta_organizer'),
            ),
            'mirror_links' => array(
                'tribe_events_with_mirror'  => $tribe_with_mirror,
                'pta_event_with_outlook_id' => $pta_with_outlook,
            ),
        ));
    }

    /**
     * GET /diagnostics/event-parity
     *
     * Phase 2+ dual-write verification.
     *
     * Returns three lists:
     *   - tribe_events_missing_mirror: published tribe_events posts with
     *     an _outlook_event_id but no `_pta_event_mirror_id` pointer
     *     (i.e. dual-write hasn't run for them yet, or failed)
     *   - pta_events_orphaned: pta_event posts with no `_tec_event_mirror_id`
     *     and no `_outlook_event_id` (shouldn't normally happen)
     *   - date_mismatches: pairs where `_EventStartDate` differs between
     *     tribe_events and its pta_event mirror (drift detector)
     *
     * Each list capped at 50 sample rows. Total counts always returned.
     */
    public function route_event_parity($request) {
        global $wpdb;

        $sample_cap = 50;

        // tribe_events with outlook ID but missing mirror pointer.
        $missing_mirror_total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = '_outlook_event_id' AND oid.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} mid ON mid.post_id = p.ID AND mid.meta_key = '_pta_event_mirror_id' AND CAST(mid.meta_value AS UNSIGNED) > 0
             WHERE p.post_type = 'tribe_events'
               AND p.post_status IN ('publish','future','draft')
               AND mid.meta_id IS NULL"
        );
        $missing_mirror_sample = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, oid.meta_value AS outlook_event_id
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = '_outlook_event_id' AND oid.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} mid ON mid.post_id = p.ID AND mid.meta_key = '_pta_event_mirror_id' AND CAST(mid.meta_value AS UNSIGNED) > 0
             WHERE p.post_type = 'tribe_events'
               AND p.post_status IN ('publish','future','draft')
               AND mid.meta_id IS NULL
             ORDER BY p.ID DESC
             LIMIT %d",
            $sample_cap
        ), ARRAY_A);

        // pta_event posts with no _tec_event_mirror_id and no _outlook_event_id.
        $orphan_total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} tid ON tid.post_id = p.ID AND tid.meta_key = '_tec_event_mirror_id' AND CAST(tid.meta_value AS UNSIGNED) > 0
             LEFT JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = '_outlook_event_id' AND oid.meta_value <> ''
             WHERE p.post_type = 'pta_event'
               AND tid.meta_id IS NULL
               AND oid.meta_id IS NULL"
        );
        $orphan_sample = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} tid ON tid.post_id = p.ID AND tid.meta_key = '_tec_event_mirror_id' AND CAST(tid.meta_value AS UNSIGNED) > 0
             LEFT JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = '_outlook_event_id' AND oid.meta_value <> ''
             WHERE p.post_type = 'pta_event'
               AND tid.meta_id IS NULL
               AND oid.meta_id IS NULL
             ORDER BY p.ID DESC
             LIMIT %d",
            $sample_cap
        ), ARRAY_A);

        // Date-mismatch detector: tribe_events with mirror, but
        // _EventStartDate differs between source and mirror.
        $date_mismatch = $wpdb->get_results($wpdb->prepare(
            "SELECT t.ID AS tec_id, t.post_title, mid.meta_value AS pta_id,
                    t_start.meta_value AS tec_start, p_start.meta_value AS pta_start
             FROM {$wpdb->posts} t
             JOIN {$wpdb->postmeta} mid ON mid.post_id = t.ID AND mid.meta_key = '_pta_event_mirror_id'
             JOIN {$wpdb->postmeta} t_start ON t_start.post_id = t.ID AND t_start.meta_key = '_EventStartDate'
             JOIN {$wpdb->postmeta} p_start ON p_start.post_id = mid.meta_value AND p_start.meta_key = '_EventStartDate'
             WHERE t.post_type = 'tribe_events'
               AND t_start.meta_value <> p_start.meta_value
             ORDER BY t.ID DESC
             LIMIT %d",
            $sample_cap
        ), ARRAY_A);

        return rest_ensure_response(array(
            'tribe_events_missing_mirror' => array(
                'total'  => $missing_mirror_total,
                'sample' => array_map(function ($r) {
                    return array(
                        'id'      => (int) $r['ID'],
                        'title'   => $r['post_title'],
                        'status'  => $r['post_status'],
                        'outlook' => $r['outlook_event_id'],
                    );
                }, (array) $missing_mirror_sample),
            ),
            'pta_events_orphaned' => array(
                'total'  => $orphan_total,
                'sample' => array_map(function ($r) {
                    return array(
                        'id'        => (int) $r['ID'],
                        'title'     => $r['post_title'],
                        'status'    => $r['post_status'],
                        'post_date' => $r['post_date'],
                    );
                }, (array) $orphan_sample),
            ),
            'date_mismatches' => array(
                'total'  => count((array) $date_mismatch),
                'sample' => array_map(function ($r) {
                    return array(
                        'tec_id'    => (int) $r['tec_id'],
                        'pta_id'    => (int) $r['pta_id'],
                        'title'     => $r['post_title'],
                        'tec_start' => $r['tec_start'],
                        'pta_start' => $r['pta_start'],
                    );
                }, (array) $date_mismatch),
            ),
        ));
    }

    /**
     * POST /diagnostics/event-mirror-test
     *
     * Manually trigger the dual-write mirror for a single tribe_events
     * post. Useful for verifying Phase 2 code paths without waiting for
     * the cron-driven Outlook sync.
     *
     * Body: { "tec_id": <int> }
     */
    public function route_event_mirror_test($request) {
        $tec_id = (int) $request->get_param('tec_id');
        if ($tec_id <= 0) {
            $body = json_decode($request->get_body(), true);
            if (is_array($body) && isset($body['tec_id'])) {
                $tec_id = (int) $body['tec_id'];
            }
        }
        if ($tec_id <= 0) {
            return new WP_Error('bad_request', 'tec_id is required (query or body)', array('status' => 400));
        }

        $tec = get_post($tec_id);
        if (!$tec || $tec->post_type !== 'tribe_events') {
            return new WP_Error('not_found', 'No tribe_events post with that ID.', array('status' => 404));
        }

        // The mirror method is private on the sync engine; we trigger
        // it via the same code path the cron sync uses. We can't call
        // private methods directly, so we invoke the public update path
        // which internally calls mirror_to_pta_event after a successful
        // update. To avoid actually re-querying Outlook, we use a
        // narrower probe: call mirror_to_pta_event via reflection on a
        // freshly constructed sync engine.
        if (!class_exists('Azure_TEC_Sync_Engine')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-tec-sync-engine.php';
        }
        if (!class_exists('Azure_TEC_Sync_Engine')) {
            return new WP_Error('not_loaded', 'Azure_TEC_Sync_Engine class not available.', array('status' => 500));
        }

        try {
            $engine = new Azure_TEC_Sync_Engine();
            $ref = new \ReflectionMethod($engine, 'mirror_to_pta_event');
            $ref->setAccessible(true);
            $result = $ref->invoke($engine, $tec_id);
        } catch (\Throwable $e) {
            return new WP_Error('mirror_exception', $e->getMessage(), array('status' => 500));
        }

        if ($result === false) {
            return rest_ensure_response(array(
                'tec_id'    => $tec_id,
                'mirrored'  => false,
                'reason'    => 'Mirror skipped or failed (see logs). Possible causes: pta_calendar_owner=tec, no _outlook_event_id on source, or pta_event post type not registered.',
                'flags'     => array(
                    'pta_calendar_owner' => Azure_Settings::get_setting('pta_calendar_owner', 'tec'),
                ),
                'pta_event_registered' => post_type_exists('pta_event'),
                'has_outlook_id'       => (bool) get_post_meta($tec_id, '_outlook_event_id', true),
            ));
        }

        $pta_id = (int) $result;
        $pta_post = get_post($pta_id);

        return rest_ensure_response(array(
            'tec_id'   => $tec_id,
            'pta_id'   => $pta_id,
            'mirrored' => true,
            'pta_post' => $pta_post ? array(
                'title'         => $pta_post->post_title,
                'status'        => $pta_post->post_status,
                'slug'          => $pta_post->post_name,
                'start_date'    => get_post_meta($pta_id, '_EventStartDate', true),
                'end_date'      => get_post_meta($pta_id, '_EventEndDate', true),
                'venue'         => get_post_meta($pta_id, '_EventVenue', true),
                'all_day'       => get_post_meta($pta_id, '_EventAllDay', true),
                'outlook_id'    => get_post_meta($pta_id, '_outlook_event_id', true),
                'tec_mirror_id' => (int) get_post_meta($pta_id, '_tec_event_mirror_id', true),
                'categories'    => wp_get_object_terms($pta_id, 'pta_event_category', array('fields' => 'names')),
            ) : null,
        ));
    }

    /**
     * GET /diagnostics/event-compare
     *
     * Phase 4 read-side parity check. Runs the SAME WP_Query parameters
     * against both `tribe_events` and `pta_event` post types for events
     * starting in the next N days, and reports the differences.
     *
     * Reveals any divergence introduced by:
     *   - TEC's `pre_get_posts` filter (which we don't replicate on
     *     pta_event queries)
     *   - `_EventHideFromUpcoming` and other TEC-specific meta filters
     *   - Recurring-event collapsing
     *   - Any post that exists on one side but not the other
     *
     * Query params:
     *   days  int  Date range in days from today, default 14, max 90
     */
    public function route_event_compare($request) {
        $days = (int) $request->get_param('days');
        if ($days <= 0) { $days = 14; }
        $days = min(90, $days);

        $start = (new \DateTime('today', wp_timezone()))->format('Y-m-d H:i:s');
        $end   = (new \DateTime("+{$days} days", wp_timezone()))->format('Y-m-d H:i:s');

        $base_args = array(
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_key'       => '_EventStartDate',
            'meta_query'     => array(
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $start,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $end,
                    'compare' => '<',
                    'type'    => 'DATETIME',
                ),
            ),
        );

        $build = function ($post_type) use ($base_args) {
            $args = $base_args;
            $args['post_type'] = $post_type;
            $q = new \WP_Query($args);
            $rows = array();
            foreach ((array) $q->posts as $p) {
                $rows[] = array(
                    'id'             => (int) $p->ID,
                    'title'          => $p->post_title,
                    'start'          => get_post_meta($p->ID, '_EventStartDate', true),
                    'all_day'        => get_post_meta($p->ID, '_EventAllDay', true),
                    'hide_upcoming'  => get_post_meta($p->ID, '_EventHideFromUpcoming', true),
                    'outlook_id'     => substr((string) get_post_meta($p->ID, '_outlook_event_id', true), 0, 16),
                    'tec_mirror_id'  => (int) get_post_meta($p->ID, '_tec_event_mirror_id', true),
                    'pta_mirror_id'  => (int) get_post_meta($p->ID, '_pta_event_mirror_id', true),
                );
            }
            wp_reset_postdata();
            return $rows;
        };

        $tribe = $build('tribe_events');
        $pta   = $build('pta_event');

        // Build cross-reference. Map tribe IDs to their pta mirror IDs
        // (and vice versa) so the diff lists are accurate even when
        // identical events have different post IDs in each store.
        $tribe_to_pta = array();
        foreach ($tribe as $r) {
            if ($r['pta_mirror_id'] > 0) {
                $tribe_to_pta[$r['id']] = $r['pta_mirror_id'];
            }
        }
        $pta_tec_set = array();
        foreach ($pta as $r) {
            if ($r['tec_mirror_id'] > 0) {
                $pta_tec_set[$r['tec_mirror_id']] = $r['id'];
            }
        }

        $only_in_tribe = array();
        foreach ($tribe as $r) {
            if (!isset($pta_tec_set[$r['id']])) {
                $only_in_tribe[] = $r;
            }
        }
        $only_in_pta = array();
        foreach ($pta as $r) {
            if ($r['tec_mirror_id'] === 0 || !isset($tribe_to_pta[$r['tec_mirror_id']])) {
                $only_in_pta[] = $r;
            }
        }

        return rest_ensure_response(array(
            'window' => array(
                'start' => $start,
                'end'   => $end,
                'days'  => $days,
            ),
            'tribe_events' => array(
                'total' => count($tribe),
                'rows'  => $tribe,
            ),
            'pta_event' => array(
                'total' => count($pta),
                'rows'  => $pta,
            ),
            'diff' => array(
                'tribe_minus_pta_count' => count($only_in_tribe),
                'pta_minus_tribe_count' => count($only_in_pta),
                'only_in_tribe'         => array_slice($only_in_tribe, 0, 50),
                'only_in_pta'           => array_slice($only_in_pta, 0, 50),
            ),
        ));
    }

    /**
     * GET /diagnostics/calendar-state
     *
     * Triage helper for the calendar-embed module. Reports:
     *   - Settings: calendar_embed_user_email, calendar_embed_mailbox_email
     *   - Tokens: presence, expiry, refresh-token availability for each
     *     user_email referenced by settings (queries the email_tokens table
     *     directly so we don't depend on Graph being callable)
     *   - Class availability: which calendar classes are actually loaded
     *     in the current request (helps spot the "Calendar service is
     *     not available" lazy-load regression)
     *   - Optional live fetch: if ?calendar_id=<id> is given, calls
     *     Azure_Calendar_GraphAPI::get_calendar_events(...) over a
     *     ?days=N (default 30) window and reports count + first 5 titles
     *     + the raw last-response status from the cache layer.
     */
    /**
     * GET /diagnostics/all-day-events
     *
     * Returns every all-day event in tribe_events and pta_event,
     * cross-referenced by Outlook ID and mirror pointers. This makes
     * the gap visible between:
     *   - Events that exist in the WP backend (tribe_events / pta_event)
     *   - Events that exist in Outlook (and thus show in [azure_calendar])
     *
     * Output shape:
     * {
     *   counts: { tribe_total, tribe_all_day, pta_total, pta_all_day,
     *             with_outlook_id, local_only, in_window, ... },
     *   tribe_only_in_db: [ { id, title, start, end, status, outlook_id?, mirror_id? }, ... ],
     *   pta_only_in_db:   [ ... ],
     * }
     *
     * Query params:
     *   ?back_days=180 (default 365)
     *   ?days=365      (default 365)
     *   ?include_timed=1 (also include non-all-day events for context)
     */
    public function route_all_day_events($request) {
        global $wpdb;

        $back_days     = max(0,   min(3650, (int) $request->get_param('back_days') ?: 365));
        $days          = max(1,   min(3650, (int) $request->get_param('days')      ?: 365));
        $include_timed = (bool) $request->get_param('include_timed');

        $now_local = current_time('Y-m-d H:i:s');
        $start_dt  = date('Y-m-d 00:00:00', strtotime("-{$back_days} days", strtotime($now_local)));
        $end_dt    = date('Y-m-d 23:59:59', strtotime("+{$days} days",       strtotime($now_local)));

        $collect = function ($post_type) use ($wpdb, $start_dt, $end_dt, $include_timed) {
            $tbl = $wpdb->posts;
            $mtbl = $wpdb->postmeta;

            $allday_join = "INNER JOIN {$mtbl} AS mad ON mad.post_id = p.ID AND mad.meta_key = '_EventAllDay' AND mad.meta_value IN ('yes','1','true')";
            if ($include_timed) {
                $allday_join = "LEFT JOIN {$mtbl} AS mad ON mad.post_id = p.ID AND mad.meta_key = '_EventAllDay'";
            }

            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_status, p.post_type,
                        ms.meta_value AS start_dt,
                        me.meta_value AS end_dt,
                        IFNULL(mad.meta_value, '') AS all_day,
                        IFNULL(mout.meta_value, '') AS outlook_id,
                        IFNULL(mmir.meta_value, '') AS mirror_id
                 FROM {$tbl} p
                 INNER JOIN {$mtbl} AS ms ON ms.post_id = p.ID AND ms.meta_key = '_EventStartDate'
                 LEFT  JOIN {$mtbl} AS me ON me.post_id = p.ID AND me.meta_key = '_EventEndDate'
                 {$allday_join}
                 LEFT  JOIN {$mtbl} AS mout ON mout.post_id = p.ID AND mout.meta_key = '_outlook_event_id'
                 LEFT  JOIN {$mtbl} AS mmir ON mmir.post_id = p.ID AND mmir.meta_key IN ('_pta_event_mirror_id','_tec_event_mirror_id')
                 WHERE p.post_type = %s
                   AND p.post_status IN ('publish','future','draft','private')
                   AND ms.meta_value BETWEEN %s AND %s
                 ORDER BY ms.meta_value ASC
                 LIMIT 500",
                $post_type, $start_dt, $end_dt
            );
            return $wpdb->get_results($sql, ARRAY_A) ?: array();
        };

        $tribe_rows = post_type_exists('tribe_events') ? $collect('tribe_events') : array();
        $pta_rows   = post_type_exists('pta_event')    ? $collect('pta_event')    : array();

        $tribe_all_day = array_filter($tribe_rows, function ($r) { return !empty($r['all_day']) && $r['all_day'] !== 'no'; });
        $pta_all_day   = array_filter($pta_rows,   function ($r) { return !empty($r['all_day']) && $r['all_day'] !== 'no'; });

        $tribe_with_outlook  = array_filter($tribe_all_day, function ($r) { return !empty($r['outlook_id']); });
        $tribe_local_only    = array_filter($tribe_all_day, function ($r) { return empty($r['outlook_id']); });
        $pta_with_outlook    = array_filter($pta_all_day,   function ($r) { return !empty($r['outlook_id']); });
        $pta_local_only      = array_filter($pta_all_day,   function ($r) { return empty($r['outlook_id']); });

        $shape = function ($rows) {
            $out = array();
            foreach ($rows as $r) {
                $out[] = array(
                    'id'         => (int) $r['ID'],
                    'title'      => $r['post_title'],
                    'status'     => $r['post_status'],
                    'start'      => $r['start_dt'],
                    'end'        => $r['end_dt'],
                    'all_day'    => $r['all_day'],
                    'outlook_id' => $r['outlook_id'] ?: null,
                    'mirror_id'  => $r['mirror_id'] ? (int) $r['mirror_id'] : null,
                );
            }
            return $out;
        };

        return rest_ensure_response(array(
            'window' => array('start' => $start_dt, 'end' => $end_dt),
            'counts' => array(
                'tribe_total'        => count($tribe_rows),
                'tribe_all_day'      => count($tribe_all_day),
                'tribe_with_outlook' => count($tribe_with_outlook),
                'tribe_local_only'   => count($tribe_local_only),
                'pta_total'          => count($pta_rows),
                'pta_all_day'        => count($pta_all_day),
                'pta_with_outlook'   => count($pta_with_outlook),
                'pta_local_only'     => count($pta_local_only),
            ),
            'tribe_all_day' => $shape($tribe_all_day),
            'pta_all_day'   => $shape($pta_all_day),
        ));
    }

    public function route_calendar_state($request) {
        global $wpdb;

        $calendar_id = sanitize_text_field((string) $request->get_param('calendar_id'));
        $days        = max(1, min(365, (int) $request->get_param('days') ?: 30));

        $settings = class_exists('Azure_Settings')
            ? Azure_Settings::get_all_settings()
            : (array) get_option('azure_plugin_settings', array());

        $cfg = array(
            'enable_calendar'                => !empty($settings['enable_calendar']),
            'calendar_embed_user_email'      => $settings['calendar_embed_user_email']    ?? '',
            'calendar_embed_mailbox_email'   => $settings['calendar_embed_mailbox_email'] ?? '',
            'tec_calendar_user_email'        => $settings['tec_calendar_user_email']      ?? '',
            'calendar_default_timezone'      => $settings['calendar_default_timezone']    ?? '',
            'calendar_cache_duration'        => $settings['calendar_cache_duration']      ?? null,
            'calendar_max_events_per_calendar' => $settings['calendar_max_events_per_calendar'] ?? null,
        );

        $classes = array(
            'Azure_Calendar_Shortcode' => class_exists('Azure_Calendar_Shortcode'),
            'Azure_Calendar_GraphAPI'  => class_exists('Azure_Calendar_GraphAPI'),
            'Azure_Calendar_Auth'      => class_exists('Azure_Calendar_Auth'),
            'Azure_Calendar_Manager'   => class_exists('Azure_Calendar_Manager'),
        );

        // Force-load the calendar classes so we can run the live test
        // even if this request entered without them (e.g. REST/admin).
        if (!class_exists('Azure_Calendar_Auth') && file_exists(AZURE_PLUGIN_PATH . 'includes/class-calendar-auth.php')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-calendar-auth.php';
        }
        if (!class_exists('Azure_Calendar_GraphAPI') && file_exists(AZURE_PLUGIN_PATH . 'includes/class-calendar-graph-api.php')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-calendar-graph-api.php';
        }

        // Collect token rows for each email referenced by settings.
        $tokens = array();
        $emails = array_filter(array_unique(array(
            $cfg['calendar_embed_user_email'],
            $cfg['tec_calendar_user_email'],
        )));

        $tokens_table = class_exists('Azure_Database')
            ? Azure_Database::get_table_name('email_tokens')
            : null;

        foreach ($emails as $email) {
            $row = null;
            if ($tokens_table) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT user_email, expires_at, LENGTH(access_token) AS access_token_len, LENGTH(refresh_token) AS refresh_token_len, scope, created_at, updated_at
                     FROM {$tokens_table}
                     WHERE user_email = %s",
                    $email
                ));
            }
            if (!$row) {
                $tokens[$email] = array(
                    'has_row'          => false,
                    'has_access_token' => false,
                    'has_refresh_token' => false,
                );
                continue;
            }
            $expires_ts = $row->expires_at ? strtotime($row->expires_at) : 0;
            $tokens[$email] = array(
                'has_row'           => true,
                'has_access_token'  => ((int) $row->access_token_len) > 0,
                'has_refresh_token' => ((int) $row->refresh_token_len) > 0,
                'expires_at'        => $row->expires_at,
                'expires_in_seconds' => $expires_ts ? ($expires_ts - time()) : null,
                'is_expired'        => $expires_ts > 0 && $expires_ts <= time(),
                'scope'             => $row->scope,
                'created_at'        => $row->created_at,
                'updated_at'        => $row->updated_at,
            );
        }

        $result = array(
            'settings' => $cfg,
            'classes_loaded_at_request_start' => $classes,
            'classes_loaded_after_force'      => array(
                'Azure_Calendar_Auth'     => class_exists('Azure_Calendar_Auth'),
                'Azure_Calendar_GraphAPI' => class_exists('Azure_Calendar_GraphAPI'),
            ),
            'tokens' => $tokens,
        );

        // List available mailbox calendars (helps locate calendar_id
        // values for the live-fetch step). Cheap call: re-uses the
        // same access token and Graph endpoint as the admin page.
        if (class_exists('Azure_Calendar_GraphAPI') && !empty($cfg['calendar_embed_user_email'])) {
            $api = new \Azure_Calendar_GraphAPI();
            $u   = $cfg['calendar_embed_user_email'];
            $mb  = $cfg['calendar_embed_mailbox_email'];
            $cal_list = array();
            if (!empty($mb) && method_exists($api, 'get_mailbox_calendars')) {
                $cal_list = $api->get_mailbox_calendars($u, $mb);
            } elseif (method_exists($api, 'get_calendars')) {
                $cal_list = $api->get_calendars($u, true);
            }
            $list_compact = array();
            if (is_array($cal_list)) {
                foreach ($cal_list as $cal) {
                    $list_compact[] = array(
                        'id'    => $cal['id']    ?? '',
                        'name'  => $cal['name']  ?? ($cal['displayName'] ?? ''),
                        'owner' => $cal['owner'] ?? null,
                    );
                }
            }
            $result['calendars'] = array(
                'count'  => is_array($cal_list) ? count($cal_list) : 0,
                'list'   => $list_compact,
                'method' => !empty($mb) ? 'get_mailbox_calendars' : 'get_calendars',
            );
        }

        // Optional live-fetch test
        if ($calendar_id !== '' && class_exists('Azure_Calendar_GraphAPI')) {
            $api = new \Azure_Calendar_GraphAPI();

            // Allow caller to override the window with explicit
            // start/end. Useful when the default "now+N days" cut
            // skips events that exist further out (e.g. all-day
            // school events in September).
            $param_start = (string) $request->get_param('start');
            $param_end   = (string) $request->get_param('end');
            $back_days   = (int) $request->get_param('back_days');
            if ($back_days > 0) {
                $start = (new \DateTime("-{$back_days} days", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            } elseif ($param_start !== '') {
                $start = $param_start;
            } else {
                $start = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            }
            if ($param_end !== '') {
                $end = $param_end;
            } else {
                $end = (new \DateTime("+{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            }

            $user_email    = $cfg['calendar_embed_user_email'] ?: $cfg['tec_calendar_user_email'];
            $mailbox_email = $cfg['calendar_embed_mailbox_email'] ?: null;

            $events = $api->get_calendar_events(
                $calendar_id,
                $start,
                $end,
                250,
                true, // force_refresh — bypass cache so we see the real auth result
                $user_email ?: null,
                $mailbox_email
            );

            $all = array();
            $all_day_count = 0;
            if (is_array($events)) {
                foreach ($events as $ev) {
                    $is_all = !empty($ev['allDay']);
                    if ($is_all) { $all_day_count++; }
                    $all[] = array(
                        'title'  => $ev['title']  ?? '',
                        'start'  => $ev['start']  ?? '',
                        'end'    => $ev['end']    ?? '',
                        'allDay' => $is_all,
                    );
                }
            }

            $result['live_fetch'] = array(
                'calendar_id'   => $calendar_id,
                'window'        => array('start' => $start, 'end' => $end),
                'user_email'    => $user_email,
                'mailbox_email' => $mailbox_email,
                'events_type'   => is_array($events) ? 'array' : (is_bool($events) ? var_export($events, true) : gettype($events)),
                'events_count'  => is_array($events) ? count($events) : 0,
                'all_day_count' => $all_day_count,
                'events'        => array_slice($all, 0, 50),
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * POST /diagnostics/pta-categories-rebuild
     *
     * One-shot remediation for Phase 5: walks every existing
     * tribe_events -> pta_event mirror pair and re-mirrors category
     * terms by NAME.
     *
     * Background:
     *   The original mirror logic (prior to this fix) passed term IDs
     *   from `tribe_events_cat` to `wp_set_object_terms()` against the
     *   `pta_event_category` taxonomy. The two taxonomies have separate
     *   term_taxonomy_id rows, so passing tribe_events_cat IDs into
     *   pta_event_category silently assigned nothing — every mirrored
     *   pta_event ended up with zero categories.
     *
     * Fix:
     *   Pull term names from `tribe_events_cat`, hand them to
     *   `wp_set_object_terms($pta_id, $names, 'pta_event_category')`,
     *   which auto-creates the term in the target taxonomy if missing.
     *
     * Body params:
     *   dry_run  bool  preview only, no writes. Default false.
     *
     * Returns:
     *   pairs_scanned       int
     *   updated             int   pta_event posts whose category set
     *                              was rewritten
     *   no_source_terms     int   tec post had no tribe_events_cat
     *                              terms (mirror left empty / cleared)
     *   sample              array up to 20 { tec_id, pta_id,
     *                              source_terms[], applied_terms[] }
     */
    /**
     * BB Modules diagnostic — returns the state of Azure Plugin's Beaver
     * Builder modules: file presence, class declaration, and whether each
     * module made it into FLBuilderModel's registered_modules registry.
     *
     * The init hook in Azure_PTA_BeaverBuilder fires at priority 20 and
     * conditionally requires each module file. If a file is on disk but
     * the module isn't visible in the BB editor's module picker, this
     * endpoint pinpoints whether the issue is at the file level, class
     * level, or registration level.
     *
     * Returns:
     *   bb_loaded            bool   FLBuilder + FLBuilderModule available
     *   pta_bb_loaded        bool   Azure_PTA_BeaverBuilder loaded
     *   modules              array  per-module: file_exists, file_size,
     *                               class_exists, registered, group,
     *                               category, name, registered_keys
     *   registered_modules   array  full list of registered_modules slugs
     *                               (truncated to module slugs only)
     */
    public function route_bb_modules($request) {
        $modules = array(
            'PTARolesDirectoryModule'   => 'pta-roles-directory/pta-roles-directory.php',
            'PTADepartmentRolesModule'  => 'pta-department-roles/pta-department-roles.php',
            'PTAOrgChartModule'         => 'pta-org-chart/pta-org-chart.php',
            'PTAOpenPositionsModule'    => 'pta-open-positions/pta-open-positions.php',
            'PTAAuctionCarouselModule'  => 'auction-carousel/auction-carousel.php',
        );

        $bb_dir = AZURE_PLUGIN_PATH . 'includes/beaver-builder/';

        // The proper way to read BB's in-memory registry is via
        // FLBuilderModel::get_registered_modules(). Older versions
        // stored the array directly on a public static property.
        $registered = array();
        if (class_exists('FLBuilderModel')) {
            if (method_exists('FLBuilderModel', 'get_registered_modules')) {
                $reg = FLBuilderModel::get_registered_modules();
                if (is_array($reg)) {
                    foreach ($reg as $slug => $obj) {
                        $registered[$slug] = array(
                            'name'      => isset($obj->name) ? $obj->name : '',
                            'group'     => isset($obj->group) ? (is_array($obj->group) ? implode(',', $obj->group) : $obj->group) : '',
                            'category'  => isset($obj->category) ? $obj->category : '',
                            'enabled'   => !empty($obj->enabled),
                            'class'     => is_object($obj) ? get_class($obj) : '',
                        );
                    }
                }
            }
        }

        // Build a class-name -> slug map by introspecting each module's
        // declaring file (this is what FLBuilderModule::__construct uses
        // to compute the slug when no explicit slug is passed).
        $module_state = array();
        foreach ($modules as $class => $rel) {
            $abs = $bb_dir . $rel;
            $entry = array(
                'file_path'    => $abs,
                'file_exists'  => file_exists($abs),
                'file_size'    => file_exists($abs) ? filesize($abs) : 0,
                'class_exists' => class_exists($class),
                'registered'   => false,
                'computed_slug' => null,
                'declared_in'   => null,
            );

            if (class_exists($class)) {
                try {
                    $r = new ReflectionClass($class);
                    $declared_file = $r->getFileName();
                    $entry['declared_in']   = $declared_file ? str_replace(AZURE_PLUGIN_PATH, '', $declared_file) : null;
                    $entry['computed_slug'] = $declared_file ? basename($declared_file, '.php') : null;

                    if ($entry['computed_slug'] && isset($registered[$entry['computed_slug']])) {
                        $entry['registered'] = ($registered[$entry['computed_slug']]['class'] === $class);
                        $entry['name']       = $registered[$entry['computed_slug']]['name'];
                        $entry['group']      = $registered[$entry['computed_slug']]['group'];
                        $entry['category']   = $registered[$entry['computed_slug']]['category'];
                        $entry['enabled']    = $registered[$entry['computed_slug']]['enabled'];
                    }
                } catch (Exception $e) {
                    $entry['reflection_error'] = $e->getMessage();
                }
            }

            $module_state[$class] = $entry;
        }

        // Look for slug collisions: multiple Azure-Plugin classes whose
        // computed slug points to the same value. This is the most
        // common cause of "module file on disk but missing from picker"
        // when the CLASS is declared in a shared file (e.g. a stub in
        // class-pta-beaver-builder.php) instead of the per-module file.
        $slug_to_classes = array();
        foreach ($module_state as $class => $info) {
            if (!empty($info['computed_slug'])) {
                $slug_to_classes[$info['computed_slug']][] = $class;
            }
        }
        $collisions = array();
        foreach ($slug_to_classes as $slug => $classes) {
            if (count($classes) > 1) {
                $collisions[$slug] = $classes;
            }
        }

        // Optionally force-register each module from within this request
        // (?force=1) and observe what happens. Useful when the REST API
        // request doesn't naturally trigger BB's module registration but
        // we still want to verify our register_module() calls would succeed.
        $force_results = null;
        if (!empty($request->get_param('force')) && class_exists('FLBuilderModel')) {
            $force_results = array();

            // Read the private/public $modules registry via reflection so
            // we can see direct state, not the filtered get_registered_modules().
            $get_raw_modules = function () {
                if (!class_exists('FLBuilderModel')) { return array(); }
                try {
                    $rp = new ReflectionProperty('FLBuilderModel', 'modules');
                    $rp->setAccessible(true);
                    $val = $rp->getValue();
                    return is_array($val) ? $val : array();
                } catch (Exception $e) {
                    return array();
                }
            };

            $force_results['raw_before'] = array_keys($get_raw_modules());

            // Capture PHP errors / notices generated during registration.
            $captured = array();
            $prev_handler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$captured) {
                $captured[] = array(
                    'errno' => $errno, 'msg' => $errstr,
                    'file' => $errfile, 'line' => $errline,
                );
                return false;
            });

            foreach ($modules as $class => $rel) {
                if (!class_exists($class)) {
                    $force_results['per_module'][$class] = array('skipped' => 'class_missing');
                    continue;
                }
                try {
                    $before = array_keys($get_raw_modules());
                    FLBuilder::register_module($class, array(
                        'general' => array(
                            'title'    => 'General',
                            'sections' => array(),
                        ),
                    ));
                    $after = array_keys($get_raw_modules());
                    $force_results['per_module'][$class] = array(
                        'added'      => array_values(array_diff($after, $before)),
                        'after_keys' => $after,
                    );
                } catch (Throwable $t) {
                    $force_results['per_module'][$class] = array(
                        'exception' => $t->getMessage(),
                        'file'      => $t->getFile(),
                        'line'      => $t->getLine(),
                    );
                }
            }

            if ($prev_handler) {
                set_error_handler($prev_handler);
            } else {
                restore_error_handler();
            }

            $force_results['captured_errors'] = $captured;
            $force_results['raw_after']       = array_keys($get_raw_modules());
        }

        return rest_ensure_response(array(
            'bb_loaded'          => class_exists('FLBuilder') && class_exists('FLBuilderModule'),
            'pta_bb_loaded'      => class_exists('Azure_PTA_BeaverBuilder'),
            'modules'            => $module_state,
            'registered_count'   => count($registered),
            'registered_slugs'   => array_keys($registered),
            'slug_collisions'    => $collisions,
            'force_results'      => $force_results,
        ));
    }

    public function route_pta_categories_rebuild($request) {
        global $wpdb;

        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) { $body = array(); }
        $dry_run = !empty($body['dry_run']);

        // Find every (tribe_events, pta_event) pair via the
        // _tec_event_mirror_id pointer on pta_event posts. This is the
        // canonical cross-pointer set by the mirror.
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id AS pta_id, pm.meta_value AS tec_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type = %s
        ", '_tec_event_mirror_id', 'pta_event'));

        $pairs_scanned   = 0;
        $updated         = 0;
        $no_source_terms = 0;
        $sample          = array();

        foreach ($rows as $r) {
            $pairs_scanned++;
            $pta_id = (int) $r->pta_id;
            $tec_id = (int) $r->tec_id;
            if ($pta_id <= 0 || $tec_id <= 0) { continue; }

            $names = wp_get_object_terms($tec_id, 'tribe_events_cat', array('fields' => 'names'));
            if (is_wp_error($names) || empty($names)) {
                $no_source_terms++;
                if (!$dry_run) {
                    wp_set_object_terms($pta_id, array(), 'pta_event_category', false);
                }
                continue;
            }

            if (!$dry_run) {
                $result = wp_set_object_terms($pta_id, $names, 'pta_event_category', false);
                if (!is_wp_error($result)) {
                    $updated++;
                }
            } else {
                $updated++; // would-be update count
            }

            if (count($sample) < 20) {
                $sample[] = array(
                    'tec_id'         => $tec_id,
                    'pta_id'         => $pta_id,
                    'source_terms'   => $names,
                    'applied_terms'  => $names,
                );
            }
        }

        return rest_ensure_response(array(
            'pairs_scanned'   => $pairs_scanned,
            'updated'         => $updated,
            'no_source_terms' => $no_source_terms,
            'dry_run'         => (bool) $dry_run,
            'sample'          => $sample,
        ));
    }

    /**
     * POST /diagnostics/event-backfill
     *
     * Phase 3 historical backfill. Iterates tribe_events posts in
     * ascending ID order from `cursor`, mirrors each one to pta_event,
     * stops after `batch_size` posts processed.
     *
     * Designed to be called repeatedly until `has_more` is false. Each
     * call is a single HTTP request bounded by `batch_size` so we don't
     * hit App Service's 230s ingress timeout on large catalogs.
     *
     * Idempotent: re-running over already-mirrored events is a no-op
     * (mirror_one finds the existing pta_event via the cross-pointer
     * and updates in place).
     *
     * Body params:
     *   batch_size    int   1..500, default 50
     *   cursor        int   last_processed_id from previous call, default 0
     *   dry_run       bool  if true, count what WOULD be mirrored but
     *                       don't actually write. Default false.
     *   include_local bool  if true, also mirror tribe_events posts
     *                       with no `_outlook_event_id` (locally-authored
     *                       events). Default true (Phase 3 wants both).
     *
     * Returns:
     *   counts: { scanned, mirrored, skipped_no_outlook, skipped_already_mirrored,
     *             errors }
     *   last_processed_id: int   pass as cursor on next call
     *   has_more:          bool  true if more tribe_events exist beyond cursor
     *   sample_mirrored:   array up to 10 { tec_id, pta_id, title } pairs
     *                            from this batch (for sanity checking)
     *   errors:            array up to 10 { tec_id, message } from this batch
     */
    public function route_event_backfill($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) { $body = array(); }

        $batch_size = isset($body['batch_size']) ? (int) $body['batch_size'] : 50;
        $batch_size = max(1, min(500, $batch_size));

        $cursor = isset($body['cursor']) ? (int) $body['cursor'] : 0;
        $cursor = max(0, $cursor);

        $dry_run       = !empty($body['dry_run']);
        $include_local = !isset($body['include_local']) ? true : (bool) $body['include_local'];

        // Gate: Phase 3 backfill only makes sense when pta_event is
        // actually registered. If the owner flag is still 'tec' we'd
        // be writing to a non-registered post type.
        if (!class_exists('Azure_Event_CPT')) {
            return new WP_Error('not_loaded', 'Azure_Event_CPT class not available.', array('status' => 500));
        }
        if (!Azure_Event_CPT::is_pta_owner_active()) {
            return new WP_Error('owner_tec', 'pta_calendar_owner is "tec"; flip to "both" or "pta" before running backfill.', array('status' => 409));
        }
        if (!post_type_exists('pta_event')) {
            return new WP_Error('cpt_missing', 'pta_event post type is not registered.', array('status' => 500));
        }

        if (!class_exists('Azure_TEC_Sync_Engine')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-tec-sync-engine.php';
        }
        if (!class_exists('Azure_TEC_Sync_Engine')) {
            return new WP_Error('not_loaded', 'Azure_TEC_Sync_Engine class not available.', array('status' => 500));
        }

        $engine = new Azure_TEC_Sync_Engine();

        global $wpdb;

        // Pull a batch of tribe_events IDs strictly greater than cursor,
        // in ascending order, regardless of mirror status. Idempotency
        // means already-mirrored events are cheap to revisit.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'tribe_events'
               AND post_status IN ('publish','future','draft','private')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $cursor,
            $batch_size
        ));

        $counts = array(
            'scanned'                  => 0,
            'mirrored'                 => 0,
            'skipped_no_outlook'       => 0,
            'skipped_already_mirrored' => 0,
            'errors'                   => 0,
        );

        $sample_mirrored = array();
        $errors          = array();
        $last_id         = $cursor;

        foreach ((array) $ids as $tec_id) {
            $tec_id  = (int) $tec_id;
            $last_id = $tec_id;
            $counts['scanned']++;

            // Pre-flight read so we can categorise the outcome accurately.
            $outlook_id  = (string) get_post_meta($tec_id, '_outlook_event_id', true);
            $existing_id = (int) get_post_meta($tec_id, '_pta_event_mirror_id', true);

            // Skip locally-authored events when caller doesn't want them.
            if ($outlook_id === '' && !$include_local) {
                $counts['skipped_no_outlook']++;
                continue;
            }

            // Track already-mirrored separately so the report shows
            // backfill progress accurately.
            if ($existing_id > 0) {
                $counts['skipped_already_mirrored']++;
                if ($dry_run) { continue; }
                // For non-dry-run, still call mirror_one so we refresh
                // any meta that may have drifted (cheap update).
            }

            if ($dry_run) {
                // In dry-run we don't call mirror_one at all; we just
                // report the would-be outcome based on the meta read.
                if ($existing_id === 0) {
                    $counts['mirrored']++; // would-be mirror
                }
                continue;
            }

            try {
                $pta_id = $engine->mirror_one($tec_id, $include_local);
            } catch (\Throwable $e) {
                $counts['errors']++;
                if (count($errors) < 10) {
                    $errors[] = array(
                        'tec_id'  => $tec_id,
                        'message' => $e->getMessage(),
                    );
                }
                continue;
            }

            if ($pta_id === false) {
                // Mirror returned false — usually because the gate
                // tripped (no outlook + include_local=false), but
                // could also be a wp_insert_post failure already
                // logged by the engine.
                if ($outlook_id === '') {
                    $counts['skipped_no_outlook']++;
                } else {
                    $counts['errors']++;
                    if (count($errors) < 10) {
                        $errors[] = array(
                            'tec_id'  => $tec_id,
                            'message' => 'mirror_one returned false (see Azure_Logger TEC channel)',
                        );
                    }
                }
                continue;
            }

            if ($existing_id === 0) {
                $counts['mirrored']++;
            }
            // (else: already counted as skipped_already_mirrored above)

            if (count($sample_mirrored) < 10) {
                $tec_post = get_post($tec_id);
                $sample_mirrored[] = array(
                    'tec_id' => $tec_id,
                    'pta_id' => (int) $pta_id,
                    'title'  => $tec_post ? $tec_post->post_title : '',
                );
            }
        }

        // Determine has_more by checking if any tribe_events remain
        // beyond the last processed ID.
        $has_more = false;
        if ($last_id > 0) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'tribe_events'
                   AND post_status IN ('publish','future','draft','private')
                   AND ID > %d",
                $last_id
            ));
            $has_more = ($remaining > 0);
        }

        return rest_ensure_response(array(
            'cursor_in'         => $cursor,
            'batch_size'        => $batch_size,
            'dry_run'           => $dry_run,
            'include_local'     => $include_local,
            'counts'            => $counts,
            'last_processed_id' => $last_id,
            'has_more'          => $has_more,
            'sample_mirrored'   => $sample_mirrored,
            'errors'            => $errors,
        ));
    }

    /**
     * POST /diagnostics/event-flags
     *
     * Set the TEC migration feature flags. Both fields optional.
     *
     * Body:
     *   {
     *     "owner":  "tec" | "both" | "pta",
     *     "reader": "tribe" | "both" | "pta"
     *   }
     */
    public function route_event_flags($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) $body = array();

        $allowed_owner  = array('tec', 'both', 'pta');
        $allowed_reader = array('tribe', 'both', 'pta');

        $changes = array();
        $errors  = array();

        if (isset($body['owner'])) {
            $owner = is_string($body['owner']) ? strtolower(trim($body['owner'])) : '';
            if (!in_array($owner, $allowed_owner, true)) {
                $errors['owner'] = "must be one of: " . implode(', ', $allowed_owner);
            } else {
                $changes['pta_calendar_owner'] = $owner;
            }
        }

        if (isset($body['reader'])) {
            $reader = is_string($body['reader']) ? strtolower(trim($body['reader'])) : '';
            if (!in_array($reader, $allowed_reader, true)) {
                $errors['reader'] = "must be one of: " . implode(', ', $allowed_reader);
            } else {
                $changes['pta_calendar_data_source'] = $reader;
            }
        }

        if (!empty($errors)) {
            return new WP_Error('bad_request', 'Invalid flags', array('status' => 400, 'errors' => $errors));
        }

        if (empty($changes)) {
            return new WP_Error('bad_request', 'No flags supplied. Provide "owner" and/or "reader".', array('status' => 400));
        }

        $before = array(
            'pta_calendar_owner'       => Azure_Settings::get_setting('pta_calendar_owner', 'tec'),
            'pta_calendar_data_source' => Azure_Settings::get_setting('pta_calendar_data_source', 'tribe'),
        );

        foreach ($changes as $k => $v) {
            Azure_Settings::update_setting($k, $v);
        }

        // Rewrite rules cache MUST be flushed when CPTs/taxonomies start
        // or stop being registered, otherwise /event/<slug>/ 404s until
        // someone visits Settings -> Permalinks. Flushing here is fine
        // because the flag flip itself is a rare manual operation.
        if (isset($changes['pta_calendar_owner'])) {
            // Force the CPT class to re-evaluate and register on next
            // request. Rewrite flush deferred to one-shot transient.
            set_transient('pta_event_flush_rewrite_rules', 1, 5 * MINUTE_IN_SECONDS);
        }

        $after = array(
            'pta_calendar_owner'       => Azure_Settings::get_setting('pta_calendar_owner', 'tec'),
            'pta_calendar_data_source' => Azure_Settings::get_setting('pta_calendar_data_source', 'tribe'),
        );

        return rest_ensure_response(array(
            'before'  => $before,
            'after'   => $after,
            'changes' => $changes,
            'note'    => 'Visit any frontend page once to trigger rewrite rule rebuild for /event/<slug>/ permalinks.',
        ));
    }

    public function route_parent_login_state($request) {
        global $wpdb;

        $report = array(
            'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        );

        // Site-level.
        $report['site'] = array(
            'users_can_register' => (bool) get_option('users_can_register', 0),
            'default_role'       => (string) get_option('default_role', 'subscriber'),
        );

        // Role-level.
        $role = get_role('parent');
        $report['role'] = array(
            'exists'         => (bool) $role,
            'has_read_cap'   => $role ? !empty($role->capabilities['read']) : false,
            'capability_count' => $role ? count($role->capabilities) : 0,
            'wc_caps' => $role ? array(
                'read_private_shop_orders' => !empty($role->capabilities['read_private_shop_orders']),
            ) : null,
        );

        // Authenticate-filter wiring.
        global $wp_filter;
        $auth_hooked = false;
        if (isset($wp_filter['authenticate']) && is_object($wp_filter['authenticate'])) {
            foreach ($wp_filter['authenticate']->callbacks as $priority => $cbs) {
                foreach ($cbs as $cb) {
                    $func = $cb['function'] ?? null;
                    if (is_array($func) && count($func) === 2) {
                        $class = is_object($func[0]) ? get_class($func[0]) : (string) $func[0];
                        if ($class === 'Azure_Parent_Role' && $func[1] === 'block_disabled_logins') {
                            $auth_hooked = true; break 2;
                        }
                    }
                }
            }
        }
        $report['auth_filter'] = array(
            'block_disabled_logins_hooked' => $auth_hooked,
        );

        // Population breakdown. We count via direct SQL so a 700-user pull
        // doesn't load every WP_User into memory.
        $cap_key = $wpdb->prefix . 'capabilities';
        $disabled_meta_key = '_pta_login_disabled';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             WHERE cap.meta_value LIKE %s",
            $cap_key, '%"parent"%'
        ));

        $disabled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             INNER JOIN {$wpdb->usermeta} dis ON dis.user_id = u.ID AND dis.meta_key = %s
             WHERE cap.meta_value LIKE %s
               AND dis.meta_value <> '' AND dis.meta_value <> '0'",
            $cap_key, $disabled_meta_key, '%"parent"%'
        ));

        $force_pw = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             INNER JOIN {$wpdb->usermeta} fp  ON fp.user_id  = u.ID AND fp.meta_key  = %s
             WHERE cap.meta_value LIKE %s
               AND fp.meta_value <> '' AND fp.meta_value <> '0'",
            $cap_key, '_pta_force_password_change', '%"parent"%'
        ));

        $with_last_login = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID)
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             INNER JOIN {$wpdb->usermeta} ll  ON ll.user_id  = u.ID AND ll.meta_key  = %s
             WHERE cap.meta_value LIKE %s
               AND ll.meta_value <> ''",
            $cap_key, '_pta_last_login', '%"parent"%'
        ));

        $report['parents'] = array(
            'total'                        => $total,
            'login_disabled'               => $disabled,
            'login_enabled'                => max(0, $total - $disabled),
            'force_password_change_pending' => $force_pw,
            'have_logged_in_at_least_once' => $with_last_login,
        );

        // Sample loggable + disabled users so the operator can pick one to
        // test. We don't expose passwords or anything sensitive.
        $loggable = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.user_email, u.display_name,
                    u.user_registered,
                    ll.meta_value AS last_login
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             LEFT  JOIN {$wpdb->usermeta} dis ON dis.user_id = u.ID AND dis.meta_key = %s
             LEFT  JOIN {$wpdb->usermeta} ll  ON ll.user_id  = u.ID AND ll.meta_key  = %s
             WHERE cap.meta_value LIKE %s
               AND (dis.meta_value IS NULL OR dis.meta_value = '' OR dis.meta_value = '0')
             ORDER BY (ll.meta_value IS NOT NULL) DESC, ll.meta_value DESC, u.ID DESC
             LIMIT 5",
            $cap_key, $disabled_meta_key, '_pta_last_login', '%"parent"%'
        ), ARRAY_A);

        $disabled_sample = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.user_email, u.display_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
             INNER JOIN {$wpdb->usermeta} dis ON dis.user_id = u.ID AND dis.meta_key = %s
             WHERE cap.meta_value LIKE %s
               AND dis.meta_value <> '' AND dis.meta_value <> '0'
             ORDER BY u.ID DESC
             LIMIT 3",
            $cap_key, $disabled_meta_key, '%"parent"%'
        ), ARRAY_A);

        $report['samples'] = array(
            'loggable_parents'  => $loggable,
            'disabled_parents'  => $disabled_sample,
        );

        // Per-email verdict if requested.
        $email = trim((string) $request->get_param('email'));
        if ($email !== '') {
            $u = get_user_by('email', $email);
            if (!$u) {
                $report['per_user_check'] = array(
                    'email' => $email,
                    'verdict' => 'no_such_user',
                );
            } else {
                $is_parent = in_array('parent', (array) $u->roles, true);
                $disabled_meta = get_user_meta($u->ID, '_pta_login_disabled', true);
                $is_disabled = !empty($disabled_meta) && $disabled_meta !== '0';
                $force_pw_meta = get_user_meta($u->ID, '_pta_force_password_change', true);
                $needs_pw_change = !empty($force_pw_meta) && $force_pw_meta !== '0';
                $blockers = array();
                if (!$is_parent) {
                    // not strictly a blocker — they can still log in via their other roles
                }
                if ($is_disabled) {
                    $blockers[] = '_pta_login_disabled is set; block_disabled_logins will reject auth';
                }
                if (!$role || empty($role->capabilities['read'])) {
                    $blockers[] = 'parent role missing or has no `read` capability';
                }
                $report['per_user_check'] = array(
                    'email'              => $email,
                    'user_id'            => $u->ID,
                    'roles'              => (array) $u->roles,
                    'is_parent'          => $is_parent,
                    '_pta_login_disabled' => (string) $disabled_meta,
                    '_pta_force_password_change' => (string) $force_pw_meta,
                    'needs_password_change_on_next_login' => $needs_pw_change,
                    'last_login_utc'     => (string) get_user_meta($u->ID, '_pta_last_login', true),
                    'verdict'            => empty($blockers) ? 'can_log_in' : 'blocked',
                    'blockers'           => $blockers,
                );
            }
        }

        // Top-level summary verdict.
        $can_log_in = $report['role']['exists']
            && $report['role']['has_read_cap']
            && $report['auth_filter']['block_disabled_logins_hooked']
            && $report['parents']['login_enabled'] > 0;
        $report['summary'] = array(
            'parents_can_log_in_overall' => $can_log_in,
            'reason' => $can_log_in ? 'role exists, has read cap, gate hooked, ' . $report['parents']['login_enabled'] . ' active parent users'
                                    : 'one of: role missing, missing read cap, gate not hooked, or no login-enabled parents',
        );

        return rest_ensure_response($report);
    }

    /**
     * POST /diagnostics/rebuild-display-name
     *      body: { "user_id": int }
     */
    public function route_rebuild_display_name($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) $body = array();
        $user_id = (int) ($body['user_id'] ?? 0);
        if ($user_id <= 0) {
            return new WP_Error('bad_request', 'user_id required', array('status' => 400));
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('not_found', "user #$user_id not found", array('status' => 404));
        }
        $first = trim((string) get_user_meta($user_id, 'first_name', true));
        $last  = trim((string) get_user_meta($user_id, 'last_name',  true));
        $combined = trim($first . ' ' . $last);
        if ($combined === '') {
            return new WP_Error('no_name', 'first_name and last_name are both empty', array('status' => 400));
        }
        $r = wp_update_user(array('ID' => $user_id, 'display_name' => $combined));
        if (is_wp_error($r)) {
            return $r;
        }
        return rest_ensure_response(array(
            'user_id'             => $user_id,
            'old_display_name'    => $user->display_name,
            'new_display_name'    => $combined,
            'first_name'          => $first,
            'last_name'           => $last,
        ));
    }

    /**
     * GET  /diagnostics/merge-parents?winner=X&loser=Y[&dry_run=1]
     * POST /diagnostics/merge-parents
     *      body: { "winner": int, "loser": int, "dry_run": bool }
     *
     * Merges a "loser" parent user into a "winner" parent user, preserving:
     *   - WooCommerce orders (legacy post-store and HPOS, if present)
     *   - Connected-family + user-children rows
     *   - Non-conflicting user_meta (loser's meta is copied to winner only
     *     when winner doesn't already have that key)
     *   - Any remaining post_author rows (handled by wp_delete_user reassign)
     *
     * Both accounts must currently have the `parent` role, as a guard rail
     * against accidentally deleting an admin or staff account.
     *
     * GET always behaves as a dry-run preview (counts what would change).
     * POST with dry_run=true also previews. POST with dry_run=false applies.
     */
    public function route_merge_parents($request) {
        global $wpdb;

        $is_post = ($request->get_method() === 'POST');
        if ($is_post) {
            $body = json_decode($request->get_body(), true);
            if (!is_array($body)) $body = array();
            $winner_id = (int) ($body['winner'] ?? 0);
            $loser_id  = (int) ($body['loser']  ?? 0);
            $dry_run   = !empty($body['dry_run']);
        } else {
            $winner_id = (int) $request->get_param('winner');
            $loser_id  = (int) $request->get_param('loser');
            $dry_run   = true;
        }

        if ($winner_id <= 0 || $loser_id <= 0) {
            return new WP_Error('bad_request', 'winner and loser are required positive integers', array('status' => 400));
        }
        if ($winner_id === $loser_id) {
            return new WP_Error('bad_request', 'winner and loser must differ', array('status' => 400));
        }

        $winner = get_userdata($winner_id);
        $loser  = get_userdata($loser_id);
        if (!$winner) return new WP_Error('not_found', "winner user #$winner_id not found", array('status' => 404));
        if (!$loser)  return new WP_Error('not_found', "loser user #$loser_id not found",  array('status' => 404));

        $w_roles = (array) $winner->roles;
        $l_roles = (array) $loser->roles;
        if (!in_array('parent', $w_roles, true)) {
            return new WP_Error('not_parent', "winner #$winner_id does not have the 'parent' role", array('status' => 400));
        }
        if (!in_array('parent', $l_roles, true)) {
            return new WP_Error('not_parent', "loser #$loser_id does not have the 'parent' role", array('status' => 400));
        }
        if (in_array('administrator', $l_roles, true)) {
            return new WP_Error('forbidden', "refusing to delete administrator account", array('status' => 403));
        }

        $report = array(
            'winner' => array(
                'ID'           => $winner_id,
                'user_login'   => $winner->user_login,
                'user_email'   => $winner->user_email,
                'display_name' => $winner->display_name,
                'roles'        => $w_roles,
            ),
            'loser' => array(
                'ID'           => $loser_id,
                'user_login'   => $loser->user_login,
                'user_email'   => $loser->user_email,
                'display_name' => $loser->display_name,
                'roles'        => $l_roles,
            ),
            'dry_run' => $dry_run,
            'changes' => array(),
            'errors'  => array(),
        );

        $hpos_orders_table = $wpdb->prefix . 'wc_orders';
        $hpos_meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $hpos_addresses    = $wpdb->prefix . 'wc_order_addresses';
        $hpos_present = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_orders_table)) === $hpos_orders_table);

        // Pre-counts: what would change.
        $cnt_post_author = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ('shop_order','shop_order_refund')",
            $loser_id
        ));
        $cnt_customer_user_meta = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %s",
            (string) $loser_id
        ));
        $cnt_hpos_orders = 0;
        $cnt_hpos_addresses = 0;
        if ($hpos_present) {
            $cnt_hpos_orders = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$hpos_orders_table} WHERE customer_id = %d",
                $loser_id
            ));
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_addresses)) === $hpos_addresses) {
                $cnt_hpos_addresses = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$hpos_addresses} WHERE NULL"
                )); // placeholder; HPOS addresses key off order_id, not user_id
            }
        }
        $cnt_comments = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d",
            $loser_id
        ));

        $family_table   = $wpdb->prefix . 'azure_connected_family';
        $children_table = $wpdb->prefix . 'azure_user_children';
        $family_present   = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $family_table))   === $family_table);
        $children_present = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $children_table)) === $children_table);

        $cnt_family_primary   = 0;
        $cnt_family_secondary = 0;
        $cnt_children_user    = 0;
        if ($family_present) {
            $cnt_family_primary = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$family_table} WHERE primary_user_id = %d",   $loser_id));
            $cnt_family_secondary = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$family_table} WHERE secondary_user_id = %d", $loser_id));
        }
        if ($children_present) {
            $cnt_children_user = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$children_table} WHERE user_id = %d", $loser_id));
        }

        // Loser meta keys we would copy to winner (non-conflicting).
        $loser_meta = get_user_meta($loser_id);
        $winner_meta = get_user_meta($winner_id);
        $copy_meta_keys = array();
        $skip_meta_keys = array(
            // Per-site capability/role keys: scope tied to user; don't copy.
            'wp_capabilities', 'wp_user_level', 'session_tokens',
            'community-events-location', 'closedpostboxes_dashboard',
            'metaboxhidden_dashboard', 'show_admin_bar_front',
        );
        foreach ($loser_meta as $key => $values) {
            if (in_array($key, $skip_meta_keys, true)) continue;
            if (strpos($key, $wpdb->prefix) === 0) {
                // Site-prefixed caps/levels keys, skip.
                continue;
            }
            if (!isset($winner_meta[$key]) || $winner_meta[$key] === array() ||
                (count($winner_meta[$key]) === 1 && trim((string) $winner_meta[$key][0]) === '')) {
                $val = is_array($values) && count($values) > 0 ? maybe_unserialize($values[0]) : '';
                if ($val !== '' && $val !== null) {
                    $copy_meta_keys[$key] = is_scalar($val) ? (string) $val : '(complex)';
                }
            }
        }

        $report['changes'] = array(
            'orders_post_author_repointed'      => $cnt_post_author,
            'orders_customer_user_meta_repointed' => $cnt_customer_user_meta,
            'hpos_present'                      => $hpos_present,
            'hpos_orders_repointed'             => $cnt_hpos_orders,
            'comments_repointed'                => $cnt_comments,
            'family_primary_repointed'          => $cnt_family_primary,
            'family_secondary_repointed'        => $cnt_family_secondary,
            'children_user_id_repointed'        => $cnt_children_user,
            'meta_keys_to_copy'                 => $copy_meta_keys,
        );

        if ($dry_run) {
            return rest_ensure_response($report);
        }

        // ===== APPLY =====
        $applied = array(
            'orders_post_author'        => 0,
            'orders_customer_user_meta' => 0,
            'hpos_orders'               => 0,
            'comments'                  => 0,
            'family_primary'            => 0,
            'family_secondary'          => 0,
            'children_user_id'          => 0,
            'meta_keys_copied'          => array(),
            'wp_delete_user'            => null,
        );
        $errors = array();

        // 1) Re-point Woo legacy orders.
        $r = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_author = %d
             WHERE post_author = %d AND post_type IN ('shop_order','shop_order_refund')",
            $winner_id, $loser_id
        ));
        if ($r === false) { $errors[] = 'posts.post_author: ' . $wpdb->last_error; } else { $applied['orders_post_author'] = (int) $r; }

        $r = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s
             WHERE meta_key = '_customer_user' AND meta_value = %s",
            (string) $winner_id, (string) $loser_id
        ));
        if ($r === false) { $errors[] = 'postmeta._customer_user: ' . $wpdb->last_error; } else { $applied['orders_customer_user_meta'] = (int) $r; }

        // 2) Re-point HPOS orders.
        if ($hpos_present) {
            $r = $wpdb->query($wpdb->prepare(
                "UPDATE {$hpos_orders_table} SET customer_id = %d WHERE customer_id = %d",
                $winner_id, $loser_id
            ));
            if ($r === false) { $errors[] = 'wc_orders.customer_id: ' . $wpdb->last_error; } else { $applied['hpos_orders'] = (int) $r; }
        }

        // 3) Re-point comments.
        $r = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->comments} SET user_id = %d WHERE user_id = %d",
            $winner_id, $loser_id
        ));
        if ($r === false) { $errors[] = 'comments.user_id: ' . $wpdb->last_error; } else { $applied['comments'] = (int) $r; }

        // 4) Family / children re-point.
        if ($family_present) {
            // Primary: if winner is already in a family, we can't blindly
            // re-point because of unique-ish family membership. We scan and
            // either delete the loser's family rows when they overlap with
            // the winner's, or repoint when no overlap.
            $loser_primaries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$family_table} WHERE primary_user_id = %d", $loser_id
            ), ARRAY_A);
            foreach ($loser_primaries as $row) {
                $fam_id = (int) $row['id'];
                $sec    = (int) $row['secondary_user_id'];
                // If winner is already the secondary of this row, just delete the row (otherwise winner = primary AND secondary).
                if ($sec === $winner_id) {
                    $wpdb->delete($family_table, array('id' => $fam_id), array('%d'));
                    continue;
                }
                // If winner is already primary in another family with the same secondary, also delete to avoid dupes.
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$family_table}
                     WHERE primary_user_id = %d AND secondary_user_id = %d AND id <> %d
                     LIMIT 1",
                    $winner_id, $sec, $fam_id
                ));
                if ($existing > 0) {
                    $wpdb->delete($family_table, array('id' => $fam_id), array('%d'));
                    continue;
                }
                $wpdb->update($family_table,
                    array('primary_user_id' => $winner_id),
                    array('id' => $fam_id),
                    array('%d'), array('%d')
                );
                $applied['family_primary']++;
            }

            $loser_secondaries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$family_table} WHERE secondary_user_id = %d", $loser_id
            ), ARRAY_A);
            foreach ($loser_secondaries as $row) {
                $fam_id = (int) $row['id'];
                $pri    = (int) $row['primary_user_id'];
                if ($pri === $winner_id) {
                    // winner already primary of same family; just clear secondary.
                    $wpdb->update($family_table,
                        array('secondary_user_id' => 0),
                        array('id' => $fam_id),
                        array('%d'), array('%d')
                    );
                    continue;
                }
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$family_table}
                     WHERE primary_user_id = %d AND secondary_user_id = %d AND id <> %d
                     LIMIT 1",
                    $pri, $winner_id, $fam_id
                ));
                if ($existing > 0) {
                    $wpdb->delete($family_table, array('id' => $fam_id), array('%d'));
                    continue;
                }
                $wpdb->update($family_table,
                    array('secondary_user_id' => $winner_id),
                    array('id' => $fam_id),
                    array('%d'), array('%d')
                );
                $applied['family_secondary']++;
            }
        }
        if ($children_present) {
            $r = $wpdb->query($wpdb->prepare(
                "UPDATE {$children_table} SET user_id = %d WHERE user_id = %d",
                $winner_id, $loser_id
            ));
            if ($r === false) { $errors[] = 'azure_user_children.user_id: ' . $wpdb->last_error; } else { $applied['children_user_id'] = (int) $r; }
        }

        // 5) Copy non-conflicting meta from loser to winner.
        foreach ($copy_meta_keys as $key => $_v) {
            $vals = $loser_meta[$key] ?? array();
            if (empty($vals)) continue;
            $val = maybe_unserialize($vals[0]);
            $ok = update_user_meta($winner_id, $key, $val);
            if ($ok !== false) { $applied['meta_keys_copied'][] = $key; }
        }

        // 6a) Heuristic display_name cleanup. If winner.display_name looks
        //     auto-generated from its email (no space, contains hyphens
        //     where dots/@ used to be) and loser has a proper "First Last"
        //     style display_name, adopt the loser's. Same for first_name /
        //     last_name if winner is missing them.
        $applied['display_name_fixed'] = false;
        $w_display = (string) $winner->display_name;
        $l_display = (string) $loser->display_name;
        $looks_auto = function ($name, $email) {
            $name = trim((string) $name);
            if ($name === '') return true;
            if (strpos($name, ' ') !== false) return false;
            // Email-derived sanitize_user output replaces "@" and "." with "-"
            $local_part = $email && strpos($email, '@') !== false ? substr($email, 0, strpos($email, '@')) : '';
            if ($local_part !== '' && stripos($name, $local_part) === 0) return true;
            $sanitized_email = strtolower(str_replace(array('@', '.'), '-', (string) $email));
            return strtolower($name) === $sanitized_email;
        };
        $loser_first = (string) (get_user_meta($loser_id, 'first_name', true)); // before delete
        $loser_last  = (string) (get_user_meta($loser_id, 'last_name', true));
        if ($looks_auto($w_display, $winner->user_email) && strpos($l_display, ' ') !== false) {
            $update = array('ID' => $winner_id, 'display_name' => $l_display);
            $r2 = wp_update_user($update);
            if (!is_wp_error($r2)) {
                $applied['display_name_fixed'] = true;
                $applied['display_name_new']   = $l_display;
            }
        }
        $w_first = (string) get_user_meta($winner_id, 'first_name', true);
        $w_last  = (string) get_user_meta($winner_id, 'last_name', true);
        if ($w_first === '' && $loser_first !== '') {
            update_user_meta($winner_id, 'first_name', $loser_first);
            $applied['first_name_set'] = $loser_first;
        }
        if ($w_last === '' && $loser_last !== '') {
            update_user_meta($winner_id, 'last_name', $loser_last);
            $applied['last_name_set'] = $loser_last;
        }

        // 6b) wp_delete_user reassigns any remaining content (posts, etc.)
        //     and removes the loser's user row + meta.
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $deleted = wp_delete_user($loser_id, $winner_id);
        $applied['wp_delete_user'] = (bool) $deleted;
        if (!$deleted) {
            $errors[] = 'wp_delete_user returned false';
        }

        $report['applied'] = $applied;
        $report['errors']  = $errors;

        return rest_ensure_response($report);
    }

    /**
     * GET /diagnostics/parent-migration-status
     * Snapshot of the configuration the parent migration tools depend on.
     */
    public function route_parent_migration_status($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }

        $settings = Azure_Settings::get_all_settings();

        $newsletter = array(
            'sending_service'         => $settings['newsletter_sending_service'] ?? '(not set)',
            'mailgun_domain'          => $settings['newsletter_mailgun_domain'] ?? '',
            'mailgun_region'          => $settings['newsletter_mailgun_region'] ?? '',
            'mailgun_api_key_present' => !empty($settings['newsletter_mailgun_api_key']),
            'from_addresses'          => $settings['newsletter_from_addresses'] ?? '',
            'reply_to'                => $settings['newsletter_reply_to'] ?? '',
        );

        $email_module = array(
            'enabled'                  => !empty($settings['enable_email']),
            'auth_method'              => $settings['email_auth_method'] ?? '',
            'override_wp_mail'         => !empty($settings['email_override_wp_mail']),
            'acs_endpoint_present'     => !empty($settings['email_acs_endpoint']),
            'acs_from_email'           => $settings['email_acs_from_email'] ?? '',
            'acs_override_wp_mail'     => !empty($settings['email_acs_override_wp_mail']),
            'hve_override_wp_mail'     => !empty($settings['email_hve_override_wp_mail']),
        );

        $sso = array(
            'role_slug'   => Azure_Parent_Migration::get_sso_role_slug(),
            'org_domain'  => Azure_Parent_Migration::get_sso_org_domain(),
            'school_domain' => Azure_Parent_Migration::get_school_staff_domain(),
        );

        $counts = array(
            'pending_parents' => Azure_Parent_Migration::count_pending_parents(),
            'acymailing_present' => Azure_Parent_Migration::acymailing_present(),
        );

        return rest_ensure_response(array(
            'sso'        => $sso,
            'newsletter' => $newsletter,
            'email_module' => $email_module,
            'counts'     => $counts,
        ));
    }

    /**
     * POST /diagnostics/parent-test-welcome
     * Provision (or attach) a single user, optionally send the welcome
     * email. Used by the operator to drive the new welcome flow from the
     * CLI without logging in to wp-admin.
     */
    public function route_parent_test_welcome($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }
        if (!class_exists('Azure_Parent_Activation')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $email = strtolower(trim((string) $request->get_param('email')));
        if (!$email || !is_email($email)) {
            return new WP_Error('invalid_email', 'email is required', array('status' => 400));
        }
        $name      = sanitize_text_field((string) $request->get_param('name'));
        $phone     = sanitize_text_field((string) $request->get_param('phone'));
        $child     = sanitize_text_field((string) $request->get_param('child_name'));
        $grade     = sanitize_text_field((string) $request->get_param('child_grade'));
        $teacher   = sanitize_text_field((string) $request->get_param('child_teacher'));
        $transport = sanitize_key((string) $request->get_param('transport'));
        if (!in_array($transport, array('wp_mail', 'mailgun', 'acymailing'), true)) {
            $transport = 'wp_mail';
        }
        $send  = (bool) $request->get_param('send');
        $force = (bool) $request->get_param('force');

        $existing = get_user_by('email', $email);
        $user_id = $existing ? (int) $existing->ID : 0;

        if ($existing && !$force) {
            // Attach + reissue token + capture extras.
            Azure_Parent_Migration::attach_existing_to_parent($user_id);
            update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
            update_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORTED_AT, current_time('mysql', true));
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, Azure_Parent_Migration::SOURCE_TEST);
            if ($name !== '') {
                wp_update_user(array('ID' => $user_id, 'display_name' => $name));
            }
            if ($phone !== '') {
                update_user_meta($user_id, 'billing_phone', $phone);
                update_user_meta($user_id, 'phone', $phone);
            }
            // No issue_token here — build_welcome_payload below will
            // issue exactly one fresh token. Issuing one here would
            // immediately get rotated by build_welcome_payload, and
            // any caller (or admin) staring at the previously-returned
            // URL would silently get a dead link.
        } elseif ($existing && $force) {
            // Same as the !force path above — explicit branch for clarity.
            Azure_Parent_Migration::attach_existing_to_parent($user_id);
            update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
            update_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORTED_AT, current_time('mysql', true));
            update_user_meta($user_id, Azure_Parent_Activation::META_IMPORT_SOURCE, Azure_Parent_Migration::SOURCE_TEST);
            if ($name !== '') {
                wp_update_user(array('ID' => $user_id, 'display_name' => $name));
            }
            if ($phone !== '') {
                update_user_meta($user_id, 'billing_phone', $phone);
                update_user_meta($user_id, 'phone', $phone);
            }
            // (See note above — no preliminary issue_token.)
        } else {
            $created = Azure_Parent_Migration::create_parent_user(
                $email,
                $name,
                Azure_Parent_Migration::SOURCE_TEST,
                array('billing_phone' => $phone, 'phone' => $phone)
            );
            if (is_wp_error($created)) {
                return new WP_Error('create_failed', $created->get_error_message(), array('status' => 500));
            }
            $user_id = (int) $created;
        }

        // Optional child capture via User Children module.
        if ($child !== '' && class_exists('Azure_User_Children') && method_exists('Azure_User_Children', 'ensure_family_for_user')) {
            try {
                $family_id = Azure_User_Children::ensure_family_for_user($user_id);
                if ($family_id && method_exists('Azure_User_Children', 'save_child')) {
                    Azure_User_Children::save_child($family_id, array(
                        'child_name' => $child,
                        'grade'      => $grade,
                        'teacher'    => $teacher,
                    ));
                }
            } catch (\Throwable $e) {
                // Non-fatal — log but keep the response shape clean.
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::warning('child capture failed: ' . $e->getMessage(), array('module' => 'ParentMigration'));
                }
            }
        }

        // Subscribe to the Parents newsletter list.
        Azure_Parent_Migration::subscribe_to_named_list(
            'Parents',
            $email,
            $user_id,
            Azure_Parent_Migration::first_name_of($name),
            Azure_Parent_Migration::last_name_of($name),
            'role',
            array('role' => Azure_Parent_Role::ROLE_SLUG)
        );

        // Test sends get a unique subject suffix (HH:MM PT) so the
        // operator can pick the most-recent email out of Gmail's
        // threaded view. Bulk welcome blast still uses the clean
        // subject.
        $suffix = 'test ' . wp_date('g:i A T');

        // CRITICAL: Build the payload ONCE and reuse. Each call to
        // build_welcome_payload() rotates the activation token (issues
        // a new one + invalidates the previous), so calling it multiple
        // times per request would send an email containing token N
        // while reporting token N+1 in the diagnostic response — and
        // the link in the email would already be dead by the time we
        // returned the response.
        $payload = Azure_Parent_Migration::build_welcome_payload($user_id, $suffix);
        if (is_wp_error($payload)) {
            return new WP_Error('payload_failed', $payload->get_error_message(), array('status' => 500));
        }

        $sent = false;
        $send_error = null;
        if ($send) {
            $send_result = Azure_Parent_Migration::send_welcome_email($user_id, $transport, $suffix, $payload);
            if (is_wp_error($send_result)) {
                $sent = false;
                $send_error = $send_result->get_error_message();
            } else {
                $sent = true;
                // Reuse the same $payload — its activation_url is the
                // exact link that was just emailed.
            }
        }

        return rest_ensure_response(array(
            'user_id'        => (int) $user_id,
            'email'          => $email,
            'name'           => $name,
            'transport'      => $transport,
            'send_attempted' => $send,
            'sent'           => $sent,
            'send_error'     => $send_error,
            'subject'        => is_array($payload) ? $payload['subject'] : null,
            'activation_url' => is_array($payload) ? $payload['activation_url'] : null,
            // Echo the temp password back so the operator can verify
            // end-to-end without opening their inbox. Only the diag
            // endpoint exposes this; the public flows never do.
            'temp_password'  => is_array($payload) && isset($payload['temp_password']) ? $payload['temp_password'] : null,
        ));
    }

    /**
     * GET /diagnostics/parent-welcome-blast-status
     *
     * Read-only counts so the operator (and the PowerShell loop driver)
     * can know:
     *   - pending_total      → parents with login_disabled=1 (i.e. not yet activated)
     *   - already_sent       → parents that have a META_WELCOME_SENT_AT row
     *   - remaining_unsent   → pending parents we have NOT mailed yet
     */
    public function route_parent_welcome_blast_status($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }

        return rest_ensure_response(array(
            'pending_total'    => Azure_Parent_Migration::count_pending_parents(),
            'already_sent'     => Azure_Parent_Migration::count_welcome_already_sent(),
            'remaining_unsent' => Azure_Parent_Migration::count_unsent_welcome_parents(),
            'time_utc'         => gmdate('Y-m-d\TH:i:s\Z'),
        ));
    }

    /**
     * POST /diagnostics/parent-welcome-blast
     *
     * Process up to ?limit=N (default 100) unsent pending parents and
     * send each the welcome email via the chosen transport (default
     * acymailing — uses AcyMailing's MailerHelper → SparkPost). Each
     * successful send writes a META_WELCOME_SENT_AT row so the next
     * batch picks up the next chunk.
     *
     * Params:
     *   - limit       int     1-500 (default 100)
     *   - transport   string  wp_mail | acymailing | mailgun (default acymailing)
     *   - dry_run     bool    if 1, identify but do NOT send
     *   - sleep_ms    int     ms to sleep between sends inside the batch (default 250)
     *   - stop_on_fail bool   if 1, abort batch on first failure
     */
    public function route_parent_welcome_blast($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }

        $limit       = (int) $request->get_param('limit');
        if ($limit <= 0) { $limit = 100; }
        $transport   = sanitize_key((string) $request->get_param('transport'));
        if (!in_array($transport, array('wp_mail', 'mailgun', 'acymailing'), true)) {
            $transport = 'acymailing';
        }
        $dry_run      = (bool) $request->get_param('dry_run');
        $sleep_ms     = $request->get_param('sleep_ms');
        $sleep_ms     = ($sleep_ms === null || $sleep_ms === '') ? 250 : max(0, (int) $sleep_ms);
        $stop_on_fail = (bool) $request->get_param('stop_on_fail');

        // Lift PHP limits a touch — we may chew through ~100 sends each
        // taking 200-500ms over SparkPost. Default 30s timeout is too tight.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }

        $result = Azure_Parent_Migration::send_welcome_batch(array(
            'limit'        => $limit,
            'transport'    => $transport,
            'dry_run'      => $dry_run,
            'sleep_ms'     => $sleep_ms,
            'stop_on_fail' => $stop_on_fail,
        ));

        return rest_ensure_response($result);
    }

    /**
     * GET /diagnostics/parent-welcome-token-audit
     *
     * Forensic check on the welcome blast: walks every parent that has
     * a META_WELCOME_SENT_AT row (i.e. we mailed them) and reports:
     *
     *   - Whether the activation token still exists (or was already
     *     consumed by a successful activation)
     *   - Whether two or more users share the same token hash (would
     *     indicate the "all emails got the same link" bug)
     *   - Per-bucket counts: activated, pending_token_present,
     *     pending_token_missing, expired
     *
     * Optional ?include_samples=N appends N sample rows from each bucket
     * (default 5) so the operator can pick a real user_id to test
     * manually.
     */
    public function route_parent_welcome_token_audit($request) {
        if (!class_exists('Azure_Parent_Activation')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Parent_Activation') || !class_exists('Azure_Parent_Migration')) {
            return new WP_Error('class_missing', 'parent activation/migration classes did not load', array('status' => 500));
        }

        global $wpdb;
        $sample_size = (int) $request->get_param('include_samples');
        if ($sample_size <= 0) { $sample_size = 5; }
        $sample_size = min(50, $sample_size);

        $sent_meta_key   = Azure_Parent_Migration::META_WELCOME_SENT_AT;
        $token_meta_key  = Azure_Parent_Activation::META_TOKEN_HASH;
        $expiry_meta_key = Azure_Parent_Activation::META_EXPIRES_AT;
        $disabled_key    = Azure_Parent_Role::META_LOGIN_DISABLED;

        // Pull all users we mailed plus their current token+activation
        // state in one query. LEFT JOIN so users whose token was revoked
        // (post-activation) still appear.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT  u.ID, u.user_email,
                     m_sent.meta_value AS sent_at,
                     m_tok.meta_value  AS token_hash,
                     m_exp.meta_value  AS expires_at,
                     m_dis.meta_value  AS login_disabled
             FROM    {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} m_sent ON m_sent.user_id = u.ID AND m_sent.meta_key = %s
             LEFT  JOIN {$wpdb->usermeta} m_tok  ON m_tok.user_id  = u.ID AND m_tok.meta_key  = %s
             LEFT  JOIN {$wpdb->usermeta} m_exp  ON m_exp.user_id  = u.ID AND m_exp.meta_key  = %s
             LEFT  JOIN {$wpdb->usermeta} m_dis  ON m_dis.user_id  = u.ID AND m_dis.meta_key  = %s
             ORDER BY u.ID ASC",
            $sent_meta_key,
            $token_meta_key,
            $expiry_meta_key,
            $disabled_key
        ));

        $now = time();
        $total = 0;
        $skipped_domain_block = 0;   // META_WELCOME_SENT_AT starts "...|skipped:domain_block:..."
        $sent_acy = 0;
        $sent_other = 0;
        $token_present = 0;
        $token_missing = 0;
        $token_expired = 0;
        $activated = 0; // login_disabled empty/0 AND token_hash empty
        $pending_active_token = 0; // login_disabled=1 AND token still present + valid

        // Hash counts to detect duplicates.
        $hash_counts = array();
        $duplicate_hashes = array();

        $samples = array(
            'activated'              => array(),
            'pending_active_token'   => array(),
            'pending_token_missing'  => array(),
            'pending_token_expired'  => array(),
            'skipped_domain_block'   => array(),
        );

        foreach ($rows as $r) {
            $total++;
            $sent_at = (string) $r->sent_at;
            $is_skip = (strpos($sent_at, '|skipped:') !== false);
            if ($is_skip) {
                $skipped_domain_block++;
                if (count($samples['skipped_domain_block']) < $sample_size) {
                    $samples['skipped_domain_block'][] = array(
                        'user_id' => (int) $r->ID, 'email' => $r->user_email,
                        'sent_at' => $sent_at,
                    );
                }
                continue;
            }
            if (strpos($sent_at, '|acymailing') !== false) {
                $sent_acy++;
            } else {
                $sent_other++;
            }

            $hash = (string) $r->token_hash;
            $exp  = (int) $r->expires_at;
            $disabled = !empty($r->login_disabled) && $r->login_disabled !== '0';

            if ($hash !== '') {
                $hash_counts[$hash] = isset($hash_counts[$hash]) ? $hash_counts[$hash] + 1 : 1;
                $token_present++;
            } else {
                $token_missing++;
            }

            if (!$disabled && $hash === '') {
                $activated++;
                if (count($samples['activated']) < $sample_size) {
                    $samples['activated'][] = array(
                        'user_id' => (int) $r->ID, 'email' => $r->user_email,
                        'sent_at' => $sent_at,
                    );
                }
                continue;
            }

            if ($hash === '') {
                if (count($samples['pending_token_missing']) < $sample_size) {
                    $samples['pending_token_missing'][] = array(
                        'user_id' => (int) $r->ID, 'email' => $r->user_email,
                        'sent_at' => $sent_at,
                    );
                }
                continue;
            }

            if ($exp > 0 && $exp < $now) {
                $token_expired++;
                if (count($samples['pending_token_expired']) < $sample_size) {
                    $samples['pending_token_expired'][] = array(
                        'user_id' => (int) $r->ID, 'email' => $r->user_email,
                        'sent_at' => $sent_at, 'expires_at_unix' => $exp,
                    );
                }
                continue;
            }

            $pending_active_token++;
            if (count($samples['pending_active_token']) < $sample_size) {
                $samples['pending_active_token'][] = array(
                    'user_id'             => (int) $r->ID,
                    'email'               => $r->user_email,
                    'sent_at'             => $sent_at,
                    'token_hash_prefix'   => substr($hash, 0, 12),
                    'expires_at_unix'     => $exp,
                    'expires_in_seconds'  => $exp ? max(0, $exp - $now) : 0,
                );
            }
        }

        // Find any token hash that appears more than once → that would be
        // the smoking gun for "everyone got the same link". We'd expect
        // ZERO duplicates.
        foreach ($hash_counts as $h => $c) {
            if ($c > 1) {
                $duplicate_hashes[] = array(
                    'hash_prefix' => substr($h, 0, 12),
                    'count'       => $c,
                );
            }
        }

        return rest_ensure_response(array(
            'total_marked_sent'      => $total,
            'sent_via_acymailing'    => $sent_acy,
            'sent_via_other'         => $sent_other,
            'skipped_domain_block'   => $skipped_domain_block,
            'token_present'          => $token_present,
            'token_missing'          => $token_missing,
            'token_expired'          => $token_expired,
            'activated'              => $activated,
            'pending_active_token'   => $pending_active_token,
            'distinct_token_hashes'  => count($hash_counts),
            'duplicate_hash_count'   => count($duplicate_hashes),
            'duplicate_hashes'       => $duplicate_hashes,
            'samples'                => $samples,
            'now_unix'               => $now,
        ));
    }

    /**
     * GET /diagnostics/perf-snapshot
     *
     * One-shot read-only snapshot of the things that drive uncached-page
     * TTFB on this site:
     *   - OPcache: enabled? validate_timestamps? hit_rate? memory used /
     *     cached scripts / wasted memory? (the big one — 4685 PHP files
     *     load per request, so OPcache health makes or breaks TTFB)
     *   - Object cache: drop-in present? Redis connected? hits/misses?
     *   - Plugins: active count + estimated file count for the heavy ones
     *   - Autoload options: count + total size in bytes (oversized
     *     autoload payload is a classic WP slow-bootstrap cause)
     *   - WP-Cron: DISABLE_WP_CRON constant + queue depth
     *   - Front Door / cache headers we send by default
     *
     * Read-only; safe to hit repeatedly. Run from a fresh request so the
     * OPcache numbers reflect steady state, not a single page render.
     */
    public function route_perf_snapshot($request) {
        global $wpdb;

        $out = array(
            'time_utc'    => gmdate('Y-m-d\TH:i:s\Z'),
            'php_version' => PHP_VERSION,
            'wp_version'  => get_bloginfo('version'),
        );

        // ── OPcache ────────────────────────────────────────────────
        $oc = array(
            'extension_loaded' => extension_loaded('Zend OPcache'),
            'function_exists'  => function_exists('opcache_get_status'),
        );
        if (function_exists('opcache_get_configuration')) {
            $cfg = @opcache_get_configuration();
            if (is_array($cfg) && !empty($cfg['directives'])) {
                $d = $cfg['directives'];
                $oc['enabled']                  = !empty($d['opcache.enable']);
                $oc['memory_consumption_mb']    = isset($d['opcache.memory_consumption']) ? (int) $d['opcache.memory_consumption'] : null;
                $oc['max_accelerated_files']    = isset($d['opcache.max_accelerated_files']) ? (int) $d['opcache.max_accelerated_files'] : null;
                $oc['validate_timestamps']      = !empty($d['opcache.validate_timestamps']);
                $oc['revalidate_freq']          = isset($d['opcache.revalidate_freq']) ? (int) $d['opcache.revalidate_freq'] : null;
                $oc['interned_strings_buffer']  = isset($d['opcache.interned_strings_buffer']) ? (int) $d['opcache.interned_strings_buffer'] : null;
                $oc['save_comments']            = !empty($d['opcache.save_comments']);
                $oc['file_cache']               = isset($d['opcache.file_cache']) ? (string) $d['opcache.file_cache'] : null;
            }
        }
        if (function_exists('opcache_get_status')) {
            $s = @opcache_get_status(false);
            if (is_array($s)) {
                $oc['cache_full']        = !empty($s['cache_full']);
                $oc['restart_pending']   = !empty($s['restart_pending']);
                if (!empty($s['memory_usage'])) {
                    $m = $s['memory_usage'];
                    $oc['mem_used_mb']     = round($m['used_memory'] / 1048576, 2);
                    $oc['mem_free_mb']    = round($m['free_memory'] / 1048576, 2);
                    $oc['mem_wasted_mb']   = round($m['wasted_memory'] / 1048576, 2);
                    $oc['mem_wasted_pct']  = isset($m['current_wasted_percentage']) ? round($m['current_wasted_percentage'], 2) : null;
                }
                if (!empty($s['interned_strings_usage'])) {
                    $is = $s['interned_strings_usage'];
                    $oc['interned_used_mb']  = round($is['used_memory'] / 1048576, 2);
                    $oc['interned_free_mb']  = round($is['free_memory'] / 1048576, 2);
                    $oc['interned_strings']  = (int) $is['number_of_strings'];
                }
                if (!empty($s['opcache_statistics'])) {
                    $st = $s['opcache_statistics'];
                    $oc['cached_scripts']    = (int) $st['num_cached_scripts'];
                    $oc['cached_keys']       = (int) $st['num_cached_keys'];
                    $oc['max_cached_keys']   = (int) $st['max_cached_keys'];
                    $oc['hits']              = (int) $st['hits'];
                    $oc['misses']            = (int) $st['misses'];
                    $oc['hit_rate_pct']      = round((float) $st['opcache_hit_rate'], 2);
                    $oc['oom_restarts']      = (int) $st['oom_restarts'];
                    $oc['hash_restarts']     = (int) $st['hash_restarts'];
                    $oc['manual_restarts']   = (int) $st['manual_restarts'];
                }
            }
        }
        $out['opcache'] = $oc;

        // ── Object cache (Redis drop-in) ───────────────────────────
        $obj = array(
            'wp_using_ext_object_cache' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : null,
            'dropin_present'            => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
        );
        if (class_exists('WP_Object_Cache')) {
            $obj['class'] = 'WP_Object_Cache (' . get_class($GLOBALS['wp_object_cache']) . ')';
        }
        if (function_exists('wp_cache_get_stats')) {
            $stats = @wp_cache_get_stats();
            if (is_array($stats)) {
                $obj['stats'] = $stats;
            }
        }
        // redis-cache plugin exposes \Rhubarb\RedisCache\Plugin::instance()
        // ->object_cache->info() — try a couple of shapes.
        if (class_exists('\\Rhubarb\\RedisCache\\Plugin')) {
            try {
                $plugin = \Rhubarb\RedisCache\Plugin::instance();
                if ($plugin && method_exists($plugin, 'get_status')) {
                    $obj['redis_cache_plugin_status'] = $plugin->get_status();
                }
            } catch (\Throwable $e) {
                $obj['redis_cache_plugin_error'] = $e->getMessage();
            }
        }
        if (!empty($GLOBALS['wp_object_cache']) && is_object($GLOBALS['wp_object_cache'])) {
            $woc = $GLOBALS['wp_object_cache'];
            foreach (array('cache_hits', 'cache_misses', 'redis_calls') as $prop) {
                if (property_exists($woc, $prop)) {
                    $obj['woc_' . $prop] = $woc->$prop;
                }
            }
            if (method_exists($woc, 'redis_status')) {
                try { $obj['woc_redis_status'] = (bool) $woc->redis_status(); } catch (\Throwable $e) {}
            }
        }
        $out['object_cache'] = $obj;

        // ── Active plugins + autoload payload ──────────────────────
        $active = (array) get_option('active_plugins', array());
        $out['plugins'] = array(
            'active_count' => count($active),
            'active_slugs' => array_values($active),
        );

        // Autoload size — classic perf killer on bloated sites.
        $auto = $wpdb->get_row(
            "SELECT COUNT(*) AS c, SUM(LENGTH(option_value)) AS bytes
             FROM {$wpdb->options} WHERE autoload IN ('yes','on')",
            ARRAY_A
        );
        $top = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS bytes
             FROM {$wpdb->options}
             WHERE autoload IN ('yes','on')
             ORDER BY bytes DESC
             LIMIT 10",
            ARRAY_A
        );
        $out['autoload'] = array(
            'count'         => isset($auto['c']) ? (int) $auto['c'] : 0,
            'total_bytes'   => isset($auto['bytes']) ? (int) $auto['bytes'] : 0,
            'total_kb'      => isset($auto['bytes']) ? round($auto['bytes'] / 1024, 1) : 0,
            'top_10_by_size' => array_map(function($r) {
                return array(
                    'option_name' => $r['option_name'],
                    'bytes'       => (int) $r['bytes'],
                    'kb'          => round($r['bytes'] / 1024, 1),
                );
            }, (array) $top),
        );

        // ── WP-Cron state ──────────────────────────────────────────
        $cron = array(
            'DISABLE_WP_CRON_defined' => defined('DISABLE_WP_CRON'),
            'DISABLE_WP_CRON_value'   => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : null,
            'ALTERNATE_WP_CRON'       => defined('ALTERNATE_WP_CRON') ? (bool) ALTERNATE_WP_CRON : null,
        );
        $cron_array = (array) get_option('cron', array());
        $upcoming = 0;
        $now_ts = time();
        foreach ($cron_array as $ts => $events) {
            if (!is_int($ts)) continue;
            $upcoming += is_array($events) ? count($events) : 0;
        }
        $cron['scheduled_events'] = $upcoming;
        $cron['next_event_in_seconds'] = false;
        if (!empty($cron_array)) {
            $next_ts = (int) min(array_filter(array_keys($cron_array), 'is_int'));
            $cron['next_event_in_seconds'] = max(0, $next_ts - $now_ts);
        }
        $out['wp_cron'] = $cron;

        // ── PHP request snapshot ──────────────────────────────────
        $out['php_request'] = array(
            'memory_limit'         => ini_get('memory_limit'),
            'memory_get_usage_mb'  => round(memory_get_usage(true) / 1048576, 2),
            'memory_peak_mb'       => round(memory_get_peak_usage(true) / 1048576, 2),
            'included_files'       => count(get_included_files()),
            'realpath_cache_size'  => ini_get('realpath_cache_size'),
            'realpath_cache_ttl'   => ini_get('realpath_cache_ttl'),
        );

        // ── DB stats ──────────────────────────────────────────────
        $out['db'] = array(
            'queries_so_far' => (int) $wpdb->num_queries,
            'host'           => preg_replace('/:.*/', '', (string) DB_HOST),
        );

        return rest_ensure_response($out);
    }

    /**
     * POST /diagnostics/perf-autoload-cleanup
     *
     * Targeted bloat removal for the autoload payload (which the
     * perf-snapshot endpoint surfaced as 2.1 MB on this site, dominated
     * by `_transient_dirsize_cache` and dead-plugin leftovers).
     *
     * Two action lists:
     *   - DELETE  : transients + options from uninstalled plugins (no
     *               longer reachable; safe to drop entirely)
     *   - DEMOTE  : options that still belong to active code but should
     *               not be in the autoload set (rare on this site, but
     *               we leave the slot open for future entries)
     *
     * Dry-run by default. Pass execute=1 to commit.
     *
     * Returns:
     *   - before {count, total_kb}
     *   - after  {count, total_kb}        (only if execute=1)
     *   - actions: per-option {action, found, bytes, executed}
     */
    public function route_perf_autoload_cleanup($request) {
        global $wpdb;

        $execute = (bool) $request->get_param('execute');

        // Curated list. EVERY entry was verified against the live
        // perf-snapshot output on 2026-05-06 — autoloaded data from
        // plugins the operator removed but whose options table rows
        // were never cleaned up.
        $delete = array(
            // WordPress core: dirsize cache should never be autoloaded.
            // wp_dir_size() rebuilds on demand; the transient was a
            // workaround that ballooned to 1+ MB on sites with many
            // upload subdirs. WP-Core ticket #19676.
            '_transient_dirsize_cache',
            '_transient_timeout_dirsize_cache',
            // WPMU Hummingbird Pro — uninstalled.
            'wphb_scripts_collection',
            'wphb_styles_collection',
            // WPMU Ultimate Branding — uninstalled.
            'ub_custom_admin_menu',
            'ub_saved_admin_menus',
            'udb_recent_admin_menu',
            // WPMU Pro Sites / network user-fields export leftovers.
            'remove_user_get_data_signup',
            'remove_user_get_data_fund',
            // BuddyBoss theme leftovers — site is on Kadence.
            // Audited 2026-05-08; theme was switched away long ago,
            // these rows are unreferenced.
            'buddyboss_theme_options',
            'old_buddyboss_theme_options_1_8_7',
            // Admin Menu Editor Pro caches detected Gutenberg blocks
            // here on every admin page-load; it's a 30 KB autoloaded
            // option that the plugin will rebuild on next admin visit.
            'ws_ame_detected_gtb_blocks',
        );

        // Demoted from autoload but kept as data. Use this when the
        // value is still needed by the plugin but doesn't need to ship
        // on every front-end pageload.
        $demote = array(
            // Freemius licence-cache for premium plugins (Forminator,
            // PowerPack). Plugins re-fetch on demand from /wp-admin;
            // 22 KB on every front-end request is wasted weight.
            'fs_accounts',
        );

        $before = $wpdb->get_row(
            "SELECT COUNT(*) AS c, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
             FROM {$wpdb->options} WHERE autoload IN ('yes','on')",
            ARRAY_A
        );

        $actions = array();

        foreach ($delete as $name) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name = %s",
                $name
            ), ARRAY_A);
            $entry = array(
                'option'   => $name,
                'action'   => 'delete',
                'found'    => (bool) $row,
                'autoload' => $row ? $row['autoload'] : null,
                'bytes'    => $row ? (int) $row['bytes'] : 0,
                'executed' => false,
            );
            if ($row && $execute) {
                delete_option($name);
                // Also delete the network counterpart on multisite, no-op otherwise.
                if (is_multisite()) {
                    delete_site_option($name);
                }
                $entry['executed'] = true;
            }
            $actions[] = $entry;
        }

        foreach ($demote as $name) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT autoload, option_value, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name = %s",
                $name
            ), ARRAY_A);
            $entry = array(
                'option'   => $name,
                'action'   => 'demote_autoload',
                'found'    => (bool) $row,
                'autoload' => $row ? $row['autoload'] : null,
                'bytes'    => $row ? (int) $row['bytes'] : 0,
                'executed' => false,
            );
            if ($row && $execute && $row['autoload'] !== 'no') {
                // Direct UPDATE on wp_options.autoload — `update_option()`
                // returns early when value is unchanged and never flips
                // the autoload column. We don't touch option_value, just
                // the autoload flag.
                $wpdb->update(
                    $wpdb->options,
                    array('autoload' => 'no'),
                    array('option_name' => $name),
                    array('%s'),
                    array('%s')
                );
                // Bust the alloptions cache so the next read reflects
                // the new autoload state.
                wp_cache_delete('alloptions', 'options');
                $entry['executed']    = true;
                $entry['old_autoload'] = $row['autoload'];
                $entry['new_autoload'] = 'no';
            }
            $actions[] = $entry;
        }

        // Bust object-cache so the next request reads fresh autoload.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }

        $after = null;
        if ($execute) {
            $after = $wpdb->get_row(
                "SELECT COUNT(*) AS c, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
                 FROM {$wpdb->options} WHERE autoload IN ('yes','on')",
                ARRAY_A
            );
        }

        return rest_ensure_response(array(
            'execute' => $execute,
            'before'  => array(
                'count'    => (int) $before['c'],
                'bytes'    => (int) $before['bytes'],
                'kb'       => round($before['bytes'] / 1024, 1),
            ),
            'after'   => $after ? array(
                'count'  => (int) $after['c'],
                'bytes'  => (int) $after['bytes'],
                'kb'     => round($after['bytes'] / 1024, 1),
                'saved_kb' => round(($before['bytes'] - $after['bytes']) / 1024, 1),
            ) : null,
            'actions' => $actions,
        ));
    }

    /**
     * GET /diagnostics/perf-files-by-plugin
     *
     * Walks get_included_files() and buckets paths by plugin slug
     * (via the wp-content/plugins/SLUG/... convention). Lets the
     * operator quantify, e.g., "how many of the 5145 included files
     * are Beaver Builder vs WooCommerce vs our azure-plugin?"
     *
     * Note: this endpoint runs inside a REST request, so the file count
     * and breakdown reflect the REST page-load profile, not the front-end
     * profile. WP loads the same plugins for both, so the relative
     * percentages are still informative for comparison purposes.
     */
    public function route_perf_files_by_plugin($request) {
        $files = get_included_files();
        $total = count($files);

        $plugins_dir = wp_normalize_path(WP_PLUGIN_DIR);
        $themes_dir  = wp_normalize_path(get_theme_root());
        $wp_includes = wp_normalize_path(ABSPATH . WPINC);
        $wp_admin    = wp_normalize_path(ABSPATH . 'wp-admin');
        $mu_dir      = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(WPMU_PLUGIN_DIR) : '';

        $bucket = array();
        $by_kind = array(
            'plugin'      => 0,
            'mu-plugin'   => 0,
            'theme'       => 0,
            'wp-includes' => 0,
            'wp-admin'    => 0,
            'other'       => 0,
        );

        foreach ($files as $f) {
            $n = wp_normalize_path($f);
            if (strpos($n, $plugins_dir . '/') === 0) {
                $by_kind['plugin']++;
                $rest = substr($n, strlen($plugins_dir) + 1);
                $slug = (strpos($rest, '/') !== false) ? substr($rest, 0, strpos($rest, '/')) : $rest;
                $key = 'plugins/' . $slug;
            } elseif ($mu_dir && strpos($n, $mu_dir . '/') === 0) {
                $by_kind['mu-plugin']++;
                $rest = substr($n, strlen($mu_dir) + 1);
                $slug = (strpos($rest, '/') !== false) ? substr($rest, 0, strpos($rest, '/')) : $rest;
                $key = 'mu-plugins/' . $slug;
            } elseif (strpos($n, $themes_dir . '/') === 0) {
                $by_kind['theme']++;
                $rest = substr($n, strlen($themes_dir) + 1);
                $slug = (strpos($rest, '/') !== false) ? substr($rest, 0, strpos($rest, '/')) : $rest;
                $key = 'themes/' . $slug;
            } elseif (strpos($n, $wp_includes . '/') === 0) {
                $by_kind['wp-includes']++;
                $key = 'wp-includes';
            } elseif (strpos($n, $wp_admin . '/') === 0) {
                $by_kind['wp-admin']++;
                $key = 'wp-admin';
            } else {
                $by_kind['other']++;
                $key = 'other';
            }
            $bucket[$key] = isset($bucket[$key]) ? $bucket[$key] + 1 : 1;
        }

        arsort($bucket);

        // Build a "top by file count" list, then derive a percent-of-total.
        $ranked = array();
        foreach ($bucket as $k => $count) {
            $ranked[] = array(
                'bucket'  => $k,
                'files'   => $count,
                'percent' => round(($count / max(1, $total)) * 100, 1),
            );
        }

        return rest_ensure_response(array(
            'total_included_files' => $total,
            'by_kind'              => $by_kind,
            'top_buckets'          => array_slice($ranked, 0, 30),
            'note'                 => 'Counts reflect a REST request; frontend pageloads load the same plugin set, so relative ranks transfer.',
        ));
    }

    /**
     * GET /diagnostics/content-audit
     *
     * Database content fingerprint built to answer "does my media
     * library / product catalog / page count slow down page loads?"
     *
     * Sections:
     *   - posts_by_type: row counts grouped by post_type + post_status
     *   - postmeta: total rows + total bytes + biggest meta_keys
     *   - options: total + autoload + biggest autoloaded values
     *   - tables: per-table size (data + index) from information_schema
     *   - media: attachment count, total size on disk (uploads dir
     *             fingerprint), avg metadata size per attachment
     *   - products: WC product/variation counts + meta size
     *   - revisions: post_revision count (often a hidden bloat source)
     *
     * Read-only; runs a few aggregate queries — none that scan the
     * whole posts table without a covering index. Safe to call any time.
     */
    public function route_content_audit($request) {
        global $wpdb;
        $started = microtime(true);

        // ── Posts by type + status ─────────────────────────────────
        $by_type = $wpdb->get_results(
            "SELECT post_type, post_status, COUNT(*) AS c
             FROM {$wpdb->posts}
             GROUP BY post_type, post_status
             ORDER BY c DESC",
            ARRAY_A
        );
        $posts_total = 0;
        $by_type_grouped = array();
        foreach ($by_type as $r) {
            $posts_total += (int) $r['c'];
            $t = $r['post_type'];
            if (!isset($by_type_grouped[$t])) {
                $by_type_grouped[$t] = array('total' => 0, 'by_status' => array());
            }
            $by_type_grouped[$t]['total'] += (int) $r['c'];
            $by_type_grouped[$t]['by_status'][$r['post_status']] = (int) $r['c'];
        }
        // Sort post_type buckets by total desc.
        uasort($by_type_grouped, function($a, $b) { return $b['total'] - $a['total']; });

        // ── postmeta totals + top meta_keys by size ────────────────
        $postmeta_totals = $wpdb->get_row(
            "SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(LENGTH(meta_value)),0) AS bytes
             FROM {$wpdb->postmeta}",
            ARRAY_A
        );
        $top_meta_keys = $wpdb->get_results(
            "SELECT meta_key,
                    COUNT(*) AS rows_count,
                    COALESCE(SUM(LENGTH(meta_value)),0) AS bytes,
                    COALESCE(AVG(LENGTH(meta_value)),0) AS avg_bytes
             FROM {$wpdb->postmeta}
             GROUP BY meta_key
             ORDER BY bytes DESC
             LIMIT 15",
            ARRAY_A
        );

        // ── usermeta totals (for completeness) ─────────────────────
        $usermeta_totals = $wpdb->get_row(
            "SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(LENGTH(meta_value)),0) AS bytes
             FROM {$wpdb->usermeta}",
            ARRAY_A
        );

        // ── Per-table size from information_schema ────────────────
        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME AS name,
                    TABLE_ROWS AS rows_estimate,
                    DATA_LENGTH AS data_bytes,
                    INDEX_LENGTH AS index_bytes,
                    (DATA_LENGTH + INDEX_LENGTH) AS total_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s
             ORDER BY total_bytes DESC
             LIMIT 25",
            DB_NAME
        ), ARRAY_A);

        // ── Media library + uploads folder fingerprint ─────────────
        $media = array();
        $media['attachment_count'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $media['attachment_metadata_rows'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
        );
        $media['attachment_metadata_total_bytes'] = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(LENGTH(meta_value)),0)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attachment_metadata'"
        );
        $media['attachment_metadata_avg_bytes'] = $media['attachment_metadata_rows']
            ? (int) round($media['attachment_metadata_total_bytes'] / $media['attachment_metadata_rows'])
            : 0;

        // Mime breakdown of attachments.
        $by_mime = $wpdb->get_results(
            "SELECT post_mime_type AS mime, COUNT(*) AS c
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             GROUP BY post_mime_type
             ORDER BY c DESC
             LIMIT 15",
            ARRAY_A
        );
        $media['by_mime'] = $by_mime;

        // Uploads folder size + file count. Cap walk to avoid timeouts.
        $upload_dir = wp_upload_dir();
        $base = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : null;
        $uploads_stats = array(
            'basedir'      => $base,
            'walk_capped'  => false,
            'file_count'   => 0,
            'total_bytes'  => 0,
            'top_dirs'     => array(),
        );
        if ($base && is_dir($base)) {
            $cap = 80000; // bail if uploads has crazy file count
            $count = 0;
            $bytes = 0;
            $dir_bytes = array();
            try {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($iter as $f) {
                    if ($f->isDir()) continue;
                    $count++;
                    $sz = (int) $f->getSize();
                    $bytes += $sz;
                    // Bucket by top-level subdir (yyyy/mm convention).
                    $rel = substr($f->getPathname(), strlen($base) + 1);
                    $top = (strpos($rel, DIRECTORY_SEPARATOR) !== false)
                        ? substr($rel, 0, strpos($rel, DIRECTORY_SEPARATOR))
                        : '_root';
                    $dir_bytes[$top] = isset($dir_bytes[$top]) ? $dir_bytes[$top] + $sz : $sz;
                    if ($count >= $cap) {
                        $uploads_stats['walk_capped'] = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $uploads_stats['walk_error'] = $e->getMessage();
            }
            $uploads_stats['file_count']  = $count;
            $uploads_stats['total_bytes'] = $bytes;
            $uploads_stats['total_mb']    = round($bytes / 1048576, 1);
            arsort($dir_bytes);
            $uploads_stats['top_dirs'] = array_slice(array_map(function($k, $v) {
                return array('name' => $k, 'bytes' => $v, 'mb' => round($v / 1048576, 1));
            }, array_keys($dir_bytes), array_values($dir_bytes)), 0, 10);
        }
        $media['uploads'] = $uploads_stats;

        // ── WC products + variations (if WC active) ────────────────
        $products = array(
            'wc_active' => class_exists('WooCommerce'),
        );
        $products['count_simple']    = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','private')"
        );
        $products['count_variation'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'"
        );
        $products['by_status'] = $wpdb->get_results(
            "SELECT post_status AS status, COUNT(*) AS c
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
             GROUP BY post_status",
            ARRAY_A
        );
        // Postmeta attached to products.
        $products['postmeta_bytes_for_products'] = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(LENGTH(pm.meta_value)),0)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type IN ('product','product_variation')"
        );
        $products['postmeta_kb_for_products'] = round($products['postmeta_bytes_for_products'] / 1024, 1);

        // ── Revisions / orphan content ─────────────────────────────
        $revisions = array(
            'count'  => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
            ),
            'auto_drafts' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
            ),
            'trashed' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
            ),
            'spam_comments' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
            ),
            'orphan_postmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.ID IS NULL"
            ),
        );

        // ── Beautify: top postmeta keys with KB instead of bytes ───
        $top_meta_keys_pretty = array_map(function($r) {
            return array(
                'meta_key'     => $r['meta_key'],
                'rows'         => (int) $r['rows_count'],
                'total_bytes'  => (int) $r['bytes'],
                'total_kb'     => round($r['bytes'] / 1024, 1),
                'avg_bytes'    => (int) $r['avg_bytes'],
            );
        }, (array) $top_meta_keys);

        $tables_pretty = array_map(function($r) {
            return array(
                'name'           => $r['name'],
                'rows_estimate'  => (int) $r['rows_estimate'],
                'data_kb'        => round($r['data_bytes'] / 1024, 1),
                'index_kb'       => round($r['index_bytes'] / 1024, 1),
                'total_kb'       => round($r['total_bytes'] / 1024, 1),
                'total_mb'       => round($r['total_bytes'] / 1048576, 2),
            );
        }, (array) $tables);

        return rest_ensure_response(array(
            'time_utc'       => gmdate('Y-m-d\TH:i:s\Z'),
            'elapsed_ms'     => (int) round((microtime(true) - $started) * 1000),
            'posts_total'    => $posts_total,
            'posts_by_type'  => $by_type_grouped,
            'postmeta'       => array(
                'rows'        => (int) $postmeta_totals['rows_count'],
                'total_kb'    => round($postmeta_totals['bytes'] / 1024, 1),
                'total_mb'    => round($postmeta_totals['bytes'] / 1048576, 2),
                'top_keys_by_size' => $top_meta_keys_pretty,
            ),
            'usermeta'       => array(
                'rows'      => (int) $usermeta_totals['rows_count'],
                'total_kb'  => round($usermeta_totals['bytes'] / 1024, 1),
            ),
            'tables_top25'   => $tables_pretty,
            'media'          => $media,
            'products'       => $products,
            'revisions'      => $revisions,
        ));
    }

    /**
     * GET /diagnostics/parent-wc-check
     *
     * Confirms the `parent` role is wired correctly for WooCommerce
     * checkout. Optional ?user_id=NN to inspect a specific account's
     * roles + last few orders.
     *
     * Returns:
     *   - parent_role_exists, parent_role_caps (full cap list)
     *   - customer_role_caps (for diff)
     *   - missing_customer_caps (caps customer has that parent lacks —
     *     should be empty after Azure_Parent_Role::register_role() runs)
     *   - sample_user (if user_id provided): roles, last 5 WC order ids
     *     with status, plus billing_email/first_name/last_name meta
     *   - wc_active: whether WooCommerce is loaded
     */
    public function route_parent_wc_check($request) {
        $parent   = get_role('parent');
        $customer = get_role('customer');
        $parent_caps   = $parent   ? $parent->capabilities   : array();
        $customer_caps = $customer ? $customer->capabilities : array();

        $missing = array();
        foreach ($customer_caps as $cap => $granted) {
            if (empty($parent_caps[$cap])) {
                $missing[] = $cap;
            }
        }

        $result = array(
            'wc_active'             => class_exists('WooCommerce'),
            'parent_role_exists'    => (bool) $parent,
            'parent_role_caps'      => $parent_caps,
            'customer_role_exists'  => (bool) $customer,
            'customer_role_caps'    => $customer_caps,
            'missing_customer_caps' => $missing,
        );

        $user_id = (int) $request->get_param('user_id');
        if ($user_id > 0) {
            $u = get_user_by('id', $user_id);
            if ($u) {
                $orders = array();
                if (function_exists('wc_get_orders')) {
                    $list = wc_get_orders(array(
                        'customer_id' => $user_id,
                        'limit'       => 5,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ));
                    foreach ($list as $o) {
                        if (!is_object($o) || !method_exists($o, 'get_id')) {
                            continue;
                        }
                        $orders[] = array(
                            'id'         => $o->get_id(),
                            'status'     => $o->get_status(),
                            'total'      => $o->get_total(),
                            'date'       => $o->get_date_created() ? $o->get_date_created()->date('c') : null,
                            'item_count' => $o->get_item_count(),
                        );
                    }
                }
                $result['sample_user'] = array(
                    'id'             => $u->ID,
                    'email'          => $u->user_email,
                    'roles'          => array_values((array) $u->roles),
                    'login_disabled' => (bool) get_user_meta($u->ID, Azure_Parent_Role::META_LOGIN_DISABLED, true),
                    'force_pw_reset' => (bool) get_user_meta($u->ID, Azure_Parent_Role::META_FORCE_PW_RESET, true),
                    'billing'        => array(
                        'email'      => get_user_meta($u->ID, 'billing_email', true),
                        'first_name' => get_user_meta($u->ID, 'billing_first_name', true),
                        'last_name'  => get_user_meta($u->ID, 'billing_last_name', true),
                        'phone'      => get_user_meta($u->ID, 'billing_phone', true),
                    ),
                    'orders' => $orders,
                );
            } else {
                $result['sample_user'] = array('error' => 'user not found');
            }
        }

        return rest_ensure_response($result);
    }

    /**
     * GET /diagnostics/newsletter-lists-audit
     *
     * Returns a snapshot of every newsletter list with the dynamic
     * subscriber count (the same count get_subscribers() would compute).
     * Use this to confirm the Parents list resolves the role-bound
     * member count correctly before/after a repair.
     */
    public function route_newsletter_lists_audit($request) {
        if (!class_exists('Azure_Newsletter_Lists')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-newsletter-lists.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Newsletter_Lists')) {
            return new WP_Error('newsletter_unavailable', 'Newsletter module not available', array('status' => 500));
        }
        $lists = new Azure_Newsletter_Lists();
        $rows  = $lists->get_all_lists();
        $out   = array();
        foreach ((array) $rows as $row) {
            $criteria = json_decode($row->criteria, true);
            $out[] = array(
                'id'              => (int) $row->id,
                'name'            => $row->name,
                'type'            => $row->type,
                'criteria_raw'    => $row->criteria,
                'criteria_parsed' => $criteria,
                'subscriber_count'=> $lists->get_subscriber_count($row->id),
                'created_at'      => $row->created_at,
            );
        }
        return rest_ensure_response(array('lists' => $out, 'count' => count($out)));
    }

    /**
     * POST /diagnostics/newsletter-lists-repair
     *
     * action=repair_parents  → updates the "Parents" list criteria to
     *                           {"roles":["parent"]} (the only shape
     *                           Azure_Newsletter_Lists::get_subscribers()
     *                           reads for type=role lists).
     * action=delete&id=N     → deletes a list by id (and its members).
     *                           Refuses to delete the default "All
     *                           WordPress Subscribers" all_users list.
     * action=delete_by_name&name=X → same as delete but matches name
     *                           case-insensitively.
     */
    public function route_newsletter_lists_repair($request) {
        if (!class_exists('Azure_Newsletter_Lists')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-newsletter-lists.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Newsletter_Lists')) {
            return new WP_Error('newsletter_unavailable', 'Newsletter module not available', array('status' => 500));
        }
        $lists  = new Azure_Newsletter_Lists();
        $action = sanitize_key($request->get_param('action') ?: '');

        if ($action === 'repair_parents') {
            $rows = $lists->get_all_lists();
            $repaired = array();
            foreach ((array) $rows as $row) {
                if (strtolower(trim($row->name)) === 'parents') {
                    $lists->update_list((int) $row->id, array(
                        'criteria' => array('roles' => array('parent')),
                    ));
                    $repaired[] = array(
                        'id'    => (int) $row->id,
                        'name'  => $row->name,
                        'after' => json_decode($lists->get_list($row->id)->criteria, true),
                        'count' => $lists->get_subscriber_count($row->id),
                    );
                }
            }
            return rest_ensure_response(array('action' => $action, 'repaired' => $repaired));
        }

        if ($action === 'delete' || $action === 'delete_by_name') {
            $list = null;
            if ($action === 'delete') {
                $id = (int) $request->get_param('id');
                if ($id <= 0) return new WP_Error('bad_id', 'id required', array('status' => 400));
                $list = $lists->get_list($id);
            } else {
                $name = strtolower(trim((string) $request->get_param('name')));
                if ($name === '') return new WP_Error('bad_name', 'name required', array('status' => 400));
                foreach ((array) $lists->get_all_lists() as $row) {
                    if (strtolower(trim($row->name)) === $name) { $list = $row; break; }
                }
            }
            if (!$list) {
                return new WP_Error('not_found', 'list not found', array('status' => 404));
            }
            if ($list->type === 'all_users') {
                return new WP_Error('refused', 'cannot delete the default all_users list', array('status' => 400));
            }
            $ok = $lists->delete_list((int) $list->id);
            return rest_ensure_response(array(
                'action'  => $action,
                'deleted' => (bool) $ok,
                'id'      => (int) $list->id,
                'name'    => $list->name,
                'type'    => $list->type,
            ));
        }

        return new WP_Error('bad_action', 'action must be one of: repair_parents, delete, delete_by_name', array('status' => 400));
    }

    /**
     * POST /diagnostics/parent-acy-run?offset=N
     *
     * Process one batch of AcyMailing rows starting at offset N. Returns
     * { processed, next_offset, total, done, results } so a CLI loop can
     * iterate until done=true. Idempotent: existing users are attached to
     * `parent` role rather than replaced; school_staff_domain and
     * org_domain emails are skipped per the bucketing rules in
     * Azure_Parent_Migration::plan_row().
     */
    public function route_parent_acy_run($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'acym_user';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return new WP_Error('acy_missing', 'AcyMailing table wp_acym_user not found', array('status' => 404));
        }
        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = max(1, min(500, (int) ($request->get_param('limit') ?: 100)));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, name FROM {$table}
             WHERE email IS NOT NULL AND email <> ''
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        $results = array(
            'created_parent'    => 0,
            'attached_existing' => 0,
            'skipped_school'    => 0,
            'skipped_sso'       => 0,
            'errors'            => 0,
            'invalid'           => 0,
            'created_user_ids'  => array(),
        );

        foreach ((array) $rows as $row) {
            $plan = Azure_Parent_Migration::plan_row($row['email'], $row['name'] ?? '', Azure_Parent_Migration::SOURCE_ACYMAILING);
            switch ($plan['action']) {
                case 'create_parent':
                    $uid = Azure_Parent_Migration::create_parent_user($plan['email'], $plan['name'], Azure_Parent_Migration::SOURCE_ACYMAILING);
                    if (is_wp_error($uid)) {
                        $results['errors']++;
                    } else {
                        $results['created_parent']++;
                        $results['created_user_ids'][] = (int) $uid;
                    }
                    break;
                case 'attach_existing':
                    $ok = Azure_Parent_Migration::attach_existing_to_parent($plan['existing_user_id']);
                    $ok ? $results['attached_existing']++ : $results['errors']++;
                    break;
                case 'skip_school_staff':
                    $results['skipped_school']++;
                    break;
                case 'skip_sso_domain':
                    $results['skipped_sso']++;
                    break;
                default:
                    $results['invalid']++;
                    break;
            }
        }

        $next_offset = $offset + count($rows);
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE email IS NOT NULL AND email <> ''");
        return rest_ensure_response(array(
            'processed'   => count($rows),
            'next_offset' => $next_offset,
            'total'       => $total,
            'done'        => ($next_offset >= $total),
            'results'     => $results,
        ));
    }

    /**
     * POST /diagnostics/parent-subscriber-migrate?limit=N
     *
     * Find the next N WP users whose ONLY role is `subscriber`, attach
     * the `parent` role (and remove `subscriber` per
     * attach_existing_to_parent rules). Profile data is preserved — only
     * role membership changes. Existing parents and users with stronger
     * roles (admin/editor/shop_manager/SSO) are left untouched. Returns
     * { processed, migrated, remaining } so a CLI loop can iterate.
     */
    public function route_parent_subscriber_migrate($request) {
        if (!class_exists('Azure_Parent_Migration')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Parent_Migration')) {
            return new WP_Error('migration_class_missing', 'Azure_Parent_Migration class did not load', array('status' => 500));
        }
        $limit = max(1, min(500, (int) ($request->get_param('limit') ?: 100)));

        $query = new WP_User_Query(array(
            'role__in' => array('subscriber'),
            'role__not_in' => array('parent'),
            'number'   => $limit,
            'fields'   => array('ID'),
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ));
        $users = $query->get_results();
        $migrated = 0;
        $errors = 0;
        $migrated_ids = array();
        foreach ((array) $users as $u) {
            $ok = Azure_Parent_Migration::attach_existing_to_parent((int) $u->ID);
            if ($ok) {
                $migrated++;
                $migrated_ids[] = (int) $u->ID;
            } else {
                $errors++;
            }
        }

        // Recount remaining subscriber-only users.
        $remaining_q = new WP_User_Query(array(
            'role__in' => array('subscriber'),
            'role__not_in' => array('parent'),
            'count_total' => true,
            'fields' => 'ID',
            'number' => 1,
        ));
        $remaining = (int) $remaining_q->get_total();

        return rest_ensure_response(array(
            'processed'    => count($users),
            'migrated'     => $migrated,
            'errors'       => $errors,
            'remaining'    => $remaining,
            'migrated_ids' => $migrated_ids,
            'done'         => ($remaining === 0),
        ));
    }

    /**
     * GET /diagnostics/find-acy-shortcodes
     *
     * Scan posts/pages/widgets for AcyMailing shortcodes + Beaver Builder
     * AcyMailing modules. Returns hits with post id, type, title, and a
     * snippet of the matching content. Used to identify where to swap
     * [pta_newsletter_signup] in. Always returns the homepage page id
     * for quick reference.
     */
    public function route_find_acy_shortcodes($request) {
        global $wpdb;
        $patterns = array(
            'acymailing',
            'acym_form',
            'acy_form',
            'acym',
        );
        $like_clauses = array();
        $like_args = array();
        foreach ($patterns as $p) {
            $like_clauses[] = 'post_content LIKE %s';
            $like_args[]    = '%' . $wpdb->esc_like($p) . '%';
        }
        $where = '(' . implode(' OR ', $like_clauses) . ")
                  AND post_status IN ('publish','private','draft')
                  AND post_type IN ('page','post','wp_block','fl-builder-template')";

        $sql = $wpdb->prepare(
            "SELECT ID, post_type, post_title, post_status, post_content
             FROM {$wpdb->posts}
             WHERE {$where}
             ORDER BY post_type, ID
             LIMIT 50",
            $like_args
        );
        $rows = $wpdb->get_results($sql);

        $hits = array();
        foreach ((array) $rows as $r) {
            $matches = array();
            foreach ($patterns as $p) {
                if (stripos($r->post_content, $p) !== false) {
                    $pos = stripos($r->post_content, $p);
                    $matches[] = array(
                        'pattern' => $p,
                        'snippet' => substr($r->post_content, max(0, $pos - 60), 240),
                    );
                }
            }
            $hits[] = array(
                'id'       => (int) $r->ID,
                'type'     => $r->post_type,
                'title'    => $r->post_title,
                'status'   => $r->post_status,
                'permalink'=> get_permalink($r->ID),
                'edit_url' => get_edit_post_link($r->ID, ''),
                'matches'  => $matches,
            );
        }

        // Also check Beaver Builder data (post meta `_fl_builder_data`).
        $bb_rows = $wpdb->get_results(
            "SELECT p.ID, p.post_type, p.post_title, p.post_status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE m.meta_key = '_fl_builder_data'
               AND (m.meta_value LIKE '%acymailing%' OR m.meta_value LIKE '%acym_form%')
               AND p.post_status IN ('publish','private','draft')
             LIMIT 30"
        );
        $bb_hits = array();
        foreach ((array) $bb_rows as $r) {
            $bb_hits[] = array(
                'id'       => (int) $r->ID,
                'type'     => $r->post_type,
                'title'    => $r->post_title,
                'status'   => $r->post_status,
                'permalink'=> get_permalink($r->ID),
                'edit_url' => get_edit_post_link($r->ID, ''),
            );
        }

        // Widgets — sidebars + active widget instances.
        $widget_options = array();
        foreach (array('widget_text', 'widget_block', 'widget_custom_html') as $opt) {
            $val = get_option($opt);
            if (is_array($val)) {
                foreach ($val as $key => $instance) {
                    if (!is_array($instance)) continue;
                    $blob = json_encode($instance);
                    if ($blob && stripos($blob, 'acymailing') !== false) {
                        $widget_options[] = array(
                            'option' => $opt,
                            'index'  => $key,
                            'snippet'=> substr($blob, 0, 200),
                        );
                    }
                }
            }
        }

        $front_page_id = (int) get_option('page_on_front');
        $blog_id       = (int) get_option('page_for_posts');
        $show_on_front = get_option('show_on_front');

        return rest_ensure_response(array(
            'show_on_front' => $show_on_front,
            'front_page_id' => $front_page_id,
            'blog_page_id'  => $blog_id,
            'post_content_hits' => $hits,
            'beaver_builder_hits' => $bb_hits,
            'widget_hits'   => $widget_options,
        ));
    }

    /**
     * GET /diagnostics/parent-activation-state?user_id=N | email=...
     *
     * Returns whether a magic-link activation token currently exists
     * for the user, when it expires, the SHA-256 fingerprint of the
     * stored hash (NOT the token itself — used only to compare two
     * separately-issued tokens), and the user's login_disabled +
     * force_password flags. Read-only; never reveals the raw token.
     */
    /**
     * Consolidate AcyMailing lists around a single "All Parents" list:
     *   1. Find or create the "All Parents" list (active=1, visible=1).
     *   2. For every WP user with the `parent` role, ensure a matching
     *      row exists in wp_acym_user (using their email + display name)
     *      and that the user is subscribed to "All Parents" with
     *      status=1. Existing AcyMailing rows are matched by email and
     *      reused — we never duplicate.
     *   3. Deactivate every other list (active=0) except those in the
     *      keep list. By default we keep "Wilder Staff" (id 20) and
     *      "All Parents". Pass keep_ids=1,5,20 to override.
     *
     * Dry-run by default. Pass execute=1 to commit.
     * Existing wp_acym_user_has_list rows on deactivated lists are
     * left intact — we only flip the list's active flag, so the
     * historical subscriber data is preserved.
     */
    public function route_acymailing_consolidate($request) {
        global $wpdb;
        $tbl_list = $wpdb->prefix . 'acym_list';
        $tbl_user = $wpdb->prefix . 'acym_user';
        $tbl_uhl  = $wpdb->prefix . 'acym_user_has_list';

        if (!(bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_list))) {
            return new WP_Error('no_acy_tables', 'AcyMailing tables not found', array('status' => 500));
        }

        $execute = (bool) $request->get_param('execute');
        $keep_ids_raw = (string) $request->get_param('keep_ids');
        $list_name = sanitize_text_field((string) ($request->get_param('list_name') ?: 'All Parents'));

        // Default keep list: Wilder Staff (20). The All Parents list id
        // is added below once we know it.
        $keep_ids = array(20);
        if ($keep_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $keep_ids_raw) as $id) {
                if (ctype_digit($id)) $keep_ids[] = (int) $id;
            }
        }

        // 1) Find / create the All Parents list.
        $all_parents_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tbl_list} WHERE name = %s LIMIT 1",
            $list_name
        ));
        $created_list = false;
        if (!$all_parents_id) {
            if ($execute) {
                $wpdb->insert($tbl_list, array(
                    'name'          => $list_name,
                    'active'        => 1,
                    'visible'       => 1,
                    'color'         => '#3498db',
                    'type'          => 'standard',
                    'creation_date' => current_time('mysql', true),
                ), array('%s','%d','%d','%s','%s','%s'));
                $all_parents_id = (int) $wpdb->insert_id;
            }
            $created_list = true;
        }
        if ($all_parents_id) {
            $keep_ids[] = $all_parents_id;
        }
        $keep_ids = array_values(array_unique(array_filter($keep_ids, function($v){ return $v > 0; })));

        // 2) Pull all parent-role WP users.
        if (!class_exists('Azure_Parent_Role')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
            if (file_exists($path)) require_once $path;
        }
        $parent_role = class_exists('Azure_Parent_Role') ? Azure_Parent_Role::ROLE_SLUG : 'parent';
        $parent_users = get_users(array(
            'role__in' => array($parent_role),
            'fields'   => array('ID', 'user_email', 'display_name'),
            'number'   => -1,
        ));

        $created_acy_users = 0;
        $reused_acy_users  = 0;
        $subscribed_now    = 0;
        $already_subscribed = 0;
        $skipped_no_email  = 0;
        $sample_emails     = array();

        if ($all_parents_id) {
            foreach ($parent_users as $u) {
                $email = strtolower(trim($u->user_email));
                if (!$email || !is_email($email)) {
                    $skipped_no_email++;
                    continue;
                }
                if (count($sample_emails) < 5) $sample_emails[] = $email;

                // Find or create AcyMailing user.
                $acy_user_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$tbl_user} WHERE email = %s LIMIT 1",
                    $email
                ));
                if (!$acy_user_id) {
                    if ($execute) {
                        $wpdb->insert($tbl_user, array(
                            'email'         => $email,
                            'name'          => $u->display_name ?: '',
                            'creation_date' => current_time('mysql', true),
                            'active'        => 1,
                            'confirmed'     => 1,
                            'cms_id'        => (int) $u->ID,
                            'source'        => 'parent-role-sync',
                        ), array('%s','%s','%s','%d','%d','%d','%s'));
                        $acy_user_id = (int) $wpdb->insert_id;
                    }
                    $created_acy_users++;
                } else {
                    $reused_acy_users++;
                }

                if (!$acy_user_id) continue; // dry-run path

                // Ensure subscribed to All Parents.
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT user_id, status FROM {$tbl_uhl} WHERE user_id = %d AND list_id = %d LIMIT 1",
                    $acy_user_id,
                    $all_parents_id
                ), ARRAY_A);
                if (!$row) {
                    if ($execute) {
                        $wpdb->insert($tbl_uhl, array(
                            'user_id'         => $acy_user_id,
                            'list_id'         => $all_parents_id,
                            'status'          => 1,
                            'subscription_date' => current_time('mysql', true),
                        ), array('%d','%d','%d','%s'));
                    }
                    $subscribed_now++;
                } elseif ((int) $row['status'] !== 1) {
                    if ($execute) {
                        $wpdb->update($tbl_uhl, array(
                            'status'            => 1,
                            'subscription_date' => current_time('mysql', true),
                        ), array(
                            'user_id' => $acy_user_id,
                            'list_id' => $all_parents_id,
                        ), array('%d','%s'), array('%d','%d'));
                    }
                    $subscribed_now++;
                } else {
                    $already_subscribed++;
                }
            }
        }

        // 3) Deactivate every other active list (status quo: active=1).
        $other_lists = $wpdb->get_results(
            "SELECT id, name, active FROM {$tbl_list} WHERE active = 1 ORDER BY id ASC",
            ARRAY_A
        ) ?: array();
        $deactivated = array();
        $kept = array();
        foreach ($other_lists as $list) {
            $lid = (int) $list['id'];
            if (in_array($lid, $keep_ids, true)) {
                $kept[] = array('id' => $lid, 'name' => $list['name']);
                continue;
            }
            if ($execute) {
                $wpdb->update($tbl_list, array('active' => 0), array('id' => $lid), array('%d'), array('%d'));
            }
            $deactivated[] = array('id' => $lid, 'name' => $list['name']);
        }

        return rest_ensure_response(array(
            'mode'                 => $execute ? 'EXECUTED' : 'DRY-RUN (pass execute=1 to commit)',
            'list_name'            => $list_name,
            'all_parents_list_id'  => $all_parents_id,
            'created_list'         => $created_list,
            'kept_lists'           => $kept,
            'deactivated_lists'    => $deactivated,
            'parent_users_seen'    => count($parent_users),
            'acy_user_created'     => $created_acy_users,
            'acy_user_reused'      => $reused_acy_users,
            'subscribed_now'       => $subscribed_now,
            'already_subscribed'   => $already_subscribed,
            'skipped_no_email'     => $skipped_no_email,
            'sample_emails'        => $sample_emails,
        ));
    }

    /**
     * Audit every AcyMailing list. Returns id, name, active flag,
     * visibility flag, the number of confirmed/active subscribers
     * currently on each list, and the matching column from
     * wp_acym_user_has_list. Read-only.
     */
    public function route_acymailing_lists_audit($request) {
        global $wpdb;
        $tbl_list = $wpdb->prefix . 'acym_list';
        $tbl_uhl  = $wpdb->prefix . 'acym_user_has_list';
        $tbl_user = $wpdb->prefix . 'acym_user';

        $exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_list));
        if (!$exists) {
            return new WP_Error('no_acy_tables', 'AcyMailing tables not found', array('status' => 500));
        }

        $lists = $wpdb->get_results(
            "SELECT id, name, active, visible, color, type, creation_date "
            . "FROM {$tbl_list} ORDER BY id ASC",
            ARRAY_A
        ) ?: array();

        $totals_per_list = array();
        $rows = $wpdb->get_results(
            "SELECT uhl.list_id, "
            . "       SUM(CASE WHEN uhl.status = 1 THEN 1 ELSE 0 END) AS subscribed, "
            . "       SUM(CASE WHEN uhl.status = 0 THEN 1 ELSE 0 END) AS unsubscribed, "
            . "       SUM(CASE WHEN u.confirmed = 1 AND u.active = 1 AND uhl.status = 1 THEN 1 ELSE 0 END) AS active_subscribed "
            . "FROM {$tbl_uhl} uhl "
            . "LEFT JOIN {$tbl_user} u ON u.id = uhl.user_id "
            . "GROUP BY uhl.list_id",
            ARRAY_A
        ) ?: array();
        foreach ($rows as $r) {
            $totals_per_list[(int) $r['list_id']] = $r;
        }

        foreach ($lists as &$list) {
            $lid = (int) $list['id'];
            $list['counts'] = isset($totals_per_list[$lid]) ? $totals_per_list[$lid] : array(
                'subscribed' => 0, 'unsubscribed' => 0, 'active_subscribed' => 0,
            );
        }
        unset($list);

        $total_users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl_user}");
        $active_users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl_user} WHERE active = 1 AND confirmed = 1");

        return rest_ensure_response(array(
            'lists'         => $lists,
            'user_totals'   => array(
                'all_in_acy'       => $total_users,
                'active_confirmed' => $active_users,
            ),
        ));
    }

    /**
     * Inspect AcyMailing's data for a recipient — used when an
     * acymailing-transport send returns success but the recipient
     * doesn't see it in their inbox. Reads wp_acym_user (confirmed,
     * active, source, creation date) and the most recent ten rows of
     * wp_acym_user_stat (per-message sends + opens + bounces).
     */
    public function route_acymailing_recipient_state($request) {
        global $wpdb;
        $email = strtolower(trim((string) $request->get_param('email')));
        if (!$email || !is_email($email)) {
            return new WP_Error('invalid_email', 'email is required', array('status' => 400));
        }

        $tbl_user  = $wpdb->prefix . 'acym_user';
        $tbl_stat  = $wpdb->prefix . 'acym_user_stat';
        $tbl_queue = $wpdb->prefix . 'acym_queue';

        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT id, email, name, active, confirmed, source, creation_date, last_sent, language "
            . "FROM {$tbl_user} WHERE email = %s LIMIT 1",
            $email
        ), ARRAY_A);

        $stats = array();
        $queue_rows = array();
        if ($user) {
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT mail_id, send_date, open_date, fail_date, bounce, sent "
                . "FROM {$tbl_stat} WHERE user_id = %d ORDER BY send_date DESC LIMIT 10",
                (int) $user['id']
            ), ARRAY_A) ?: array();
            $queue_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT mail_id, sending_date, priority, try, sent "
                . "FROM {$tbl_queue} WHERE user_id = %d ORDER BY sending_date DESC LIMIT 5",
                (int) $user['id']
            ), ARRAY_A) ?: array();
        }

        $tables_ok = array(
            'acym_user'      => (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_user)),
            'acym_user_stat' => (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_stat)),
            'acym_queue'     => (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_queue)),
        );

        return rest_ensure_response(array(
            'email'      => $email,
            'tables'     => $tables_ok,
            'user'       => $user,
            'stats'      => $stats,
            'queue'      => $queue_rows,
            // Also surface the From/Reply/DKIM that AcyMailing would
            // have stamped on the send — confirms it routed via the
            // configured AcyMailer/SparkPost path.
            'sender'     => (function_exists('acym_config')) ? array(
                'from_email'    => (string) acym_config()->get('from_email', ''),
                'from_name'     => (string) acym_config()->get('from_name', ''),
                'mailer_method' => (string) acym_config()->get('mailer_method', ''),
                'dkim_active'   => (bool)   acym_config()->get('dkim', 0),
            ) : null,
        ));
    }

    /**
     * Tail the email_logs table to confirm which transport actually
     * carried a recent send. The email logger (class-email-logger.php)
     * hooks the `wp_mail` filter and records every send with the
     * detected transport (Azure Graph / HVE / ACS, WP Mail SMTP, generic
     * PHPMailer, or "WordPress Default"). Note: AcyMailing, when
     * active, wraps wp_mail through its own mailer — those sends will
     * still show up here with whatever method the logger detects, plus
     * a plugin_source of 'AcyMailing' if AcyMailing's hook ran first.
     */
    public function route_email_logs_tail($request) {
        global $wpdb;
        if (!class_exists('Azure_Database')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-database.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_Database')) {
            return new WP_Error('db_class_missing', 'Azure_Database not loaded', array('status' => 500));
        }
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) {
            return new WP_Error('no_table', 'email_logs table not configured', array('status' => 500));
        }
        $limit = max(1, min(50, (int) $request->get_param('limit') ?: 10));
        $to    = sanitize_text_field((string) $request->get_param('to'));
        if ($to !== '') {
            $sql = $wpdb->prepare(
                "SELECT id, to_email, from_email, subject, method, status, plugin_source, error_message, created_at "
                . "FROM {$table} WHERE to_email LIKE %s ORDER BY id DESC LIMIT %d",
                '%' . $wpdb->esc_like($to) . '%',
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id, to_email, from_email, subject, method, status, plugin_source, error_message, created_at "
                . "FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            );
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return rest_ensure_response(array(
            'table'  => $table,
            'count'  => is_array($rows) ? count($rows) : 0,
            'rows'   => $rows ?: array(),
            // Echo the configured overrides so it's clear which path
            // wp_mail will take when no other plugin intercepts first.
            'config' => array(
                'auth_method'             => Azure_Settings::get_setting('email_auth_method', ''),
                'graph_override_wp_mail'  => (bool) Azure_Settings::get_setting('email_override_wp_mail', false),
                'hve_override_wp_mail'    => (bool) Azure_Settings::get_setting('email_hve_override_wp_mail', false),
                'acs_override_wp_mail'    => (bool) Azure_Settings::get_setting('email_acs_override_wp_mail', false),
                // is_plugin_active() lives in wp-admin/includes/plugin.php
                // which isn't always loaded in REST context, so check the
                // raw active_plugins option directly. We also surface the
                // priority of every callback registered against the
                // pre_wp_mail filter — that's the hook that lets a plugin
                // intercept and short-circuit wp_mail before it ever
                // reaches PHPMailer (which is how App Service Email,
                // WP Mail SMTP "Force From", and AcyMailing all swallow
                // sends without our logger ever seeing them).
                'acymailing_active'       => (function() {
                    $active = (array) get_option('active_plugins', array());
                    foreach ($active as $p) {
                        if (stripos($p, 'acymailing') === 0) return true;
                    }
                    return false;
                })(),
                'app_service_email_active' => (function() {
                    $active = (array) get_option('active_plugins', array());
                    foreach ($active as $p) {
                        if (stripos($p, 'app-service-email') !== false) return true;
                        if (stripos($p, 'azure-app-service-email') !== false) return true;
                        if (stripos($p, 'wp-azure-acs') !== false) return true;
                        if (stripos($p, 'app_service_email') !== false) return true;
                    }
                    return false;
                })(),
                'active_plugins'           => (array) get_option('active_plugins', array()),
                'mu_plugins'               => (function() {
                    $dir = WPMU_PLUGIN_DIR;
                    if (!is_dir($dir)) return array();
                    $files = glob($dir . '/*.php');
                    return array_map('basename', $files ?: array());
                })(),
                'acymailing_classes'       => (function() {
                    $candidates = array(
                        '\\AcyMailing\\Helpers\\MailerHelper',
                        '\\AcyMailing\\Classes\\MailClass',
                        '\\AcyMailing\\Classes\\UserClass',
                        'acymailing_get',
                        'acym_get',
                    );
                    $out = array();
                    foreach ($candidates as $c) {
                        if (function_exists($c)) {
                            $out[] = $c . ' (function)';
                        } elseif (class_exists($c)) {
                            $out[] = $c . ' (class)';
                        }
                    }
                    if (defined('ACYM_VERSION')) $out[] = 'ACYM_VERSION=' . ACYM_VERSION;
                    if (defined('ACYM_FOLDER')) $out[] = 'ACYM_FOLDER=' . ACYM_FOLDER;
                    return $out;
                })(),
                'acymailing_sender'        => (function() {
                    if (!function_exists('acym_config')) return array('configured' => false);
                    $cfg = acym_config();
                    return array(
                        'configured'    => true,
                        'from_email'    => (string) $cfg->get('from_email', ''),
                        'from_name'     => (string) $cfg->get('from_name', ''),
                        'reply_email'   => (string) $cfg->get('replyto_email', ''),
                        'bounce_email'  => (string) $cfg->get('bounce_email', ''),
                        'mailer_method' => (string) $cfg->get('mailer_method', ''),
                        'smtp_host'     => (string) $cfg->get('smtp_host', ''),
                        'dkim_domain'   => (string) $cfg->get('dkim_domain', ''),
                        'dkim_active'   => (bool) $cfg->get('dkim', 0),
                    );
                })(),
                'wp_mail_smtp_active'     => function_exists('wp_mail_smtp'),
                'pre_wp_mail_callbacks'    => (function() {
                    global $wp_filter;
                    if (empty($wp_filter['pre_wp_mail'])) return array();
                    $out = array();
                    foreach ($wp_filter['pre_wp_mail']->callbacks as $priority => $cbs) {
                        foreach ($cbs as $cb) {
                            $fn = $cb['function'];
                            if (is_array($fn)) {
                                $cls = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
                                $out[] = sprintf('p%d: %s::%s', $priority, $cls, $fn[1]);
                            } elseif (is_string($fn)) {
                                $out[] = sprintf('p%d: %s', $priority, $fn);
                            } elseif ($fn instanceof Closure) {
                                $out[] = sprintf('p%d: Closure', $priority);
                            } else {
                                $out[] = sprintf('p%d: <unknown>', $priority);
                            }
                        }
                    }
                    return $out;
                })(),
            ),
        ));
    }

    public function route_parent_activation_state($request) {
        if (!class_exists('Azure_Parent_Activation')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php';
            if (file_exists($path)) require_once $path;
        }
        $uid   = (int) $request->get_param('user_id');
        $email = trim((string) $request->get_param('email'));
        $user  = null;
        if ($uid > 0)         $user = get_user_by('id', $uid);
        elseif ($email !== '')$user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error('not_found', 'user not found', array('status' => 404));
        }
        $hash    = get_user_meta($user->ID, Azure_Parent_Activation::META_TOKEN_HASH, true);
        $expires = (int) get_user_meta($user->ID, Azure_Parent_Activation::META_EXPIRES_AT, true);
        return rest_ensure_response(array(
            'user_id'                => (int) $user->ID,
            'email'                  => $user->user_email,
            'roles'                  => array_values((array) $user->roles),
            'login_disabled'         => (bool) get_user_meta($user->ID, Azure_Parent_Role::META_LOGIN_DISABLED, true),
            'force_password_change'  => (bool) get_user_meta($user->ID, Azure_Parent_Role::META_FORCE_PW_RESET, true),
            'has_activation_token'   => !empty($hash),
            'token_hash_fingerprint' => $hash ? substr(hash('sha256', $hash), 0, 16) : null,
            'expires_at_unix'        => $expires,
            'expires_at_iso'         => $expires ? gmdate('c', $expires) : null,
            'expires_in_seconds'     => $expires ? max(0, $expires - time()) : 0,
            'now_unix'               => time(),
        ));
    }

    /**
     * POST /diagnostics/swap-acy-shortcode?post_id=N&dry_run=1
     *
     * Replaces AcyMailing shortcodes with [pta_newsletter_signup] in:
     *   - posts.post_content (the simple/fallback rendered HTML)
     *   - postmeta `_fl_builder_data` and `_fl_builder_draft` (Beaver
     *     Builder's serialized layout — modules with the shortcode in
     *     their `text` setting)
     *
     * Backs up the previous values into `_pta_acy_swap_backup` (assoc
     * array keyed by source) so the swap is reversible by writing the
     * backup back. Returns counts of replacements per source.
     *
     * Patterns matched (case-insensitive):
     *   [acymailing_form_shortcode ...]
     *   [acymailing_form ...]
     *   [acymailing ...]
     *   [acym_form ...]
     */
    public function route_swap_acy_shortcode($request) {
        $post_id = (int) $request->get_param('post_id');
        if ($post_id <= 0) {
            return new WP_Error('bad_post_id', 'post_id is required', array('status' => 400));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', 'post not found', array('status' => 404));
        }
        $dry = (bool) $request->get_param('dry_run');
        $replacement = '[pta_newsletter_signup]';

        // Single regex covers all AcyMailing variants. Matches both
        // self-closing tags and the rare paired form (no [/...] in
        // wild data so far, but tolerated).
        $pattern = '/\[(?:acymailing_form_shortcode|acymailing_form|acymailing|acym_form)\b[^\]]*\]/i';

        $report = array(
            'post_id' => $post_id,
            'dry_run' => $dry,
            'sources' => array(),
            'before_post_content_excerpt' => '',
            'after_post_content_excerpt'  => '',
        );

        // 1) post_content
        $orig_content = (string) $post->post_content;
        $count = 0;
        $new_content = preg_replace_callback($pattern, function($m) use (&$count, $replacement) {
            $count++;
            return $replacement;
        }, $orig_content);
        $report['sources']['post_content'] = array('replacements' => $count);
        if ($count > 0 && stripos($orig_content, 'acymailing') !== false) {
            $pos = stripos($orig_content, 'acymailing');
            $report['before_post_content_excerpt'] = substr($orig_content, max(0, $pos - 60), 240);
            $pos2 = stripos($new_content, 'pta_newsletter_signup');
            if ($pos2 !== false) {
                $report['after_post_content_excerpt'] = substr($new_content, max(0, $pos2 - 60), 240);
            }
        }

        // 2) _fl_builder_data and _fl_builder_draft postmeta
        $bb_keys = array('_fl_builder_data', '_fl_builder_draft');
        $bb_results = array();
        foreach ($bb_keys as $mk) {
            $raw = get_post_meta($post_id, $mk, true);
            if (empty($raw)) {
                $bb_results[$mk] = array('present' => false, 'replacements' => 0);
                continue;
            }
            // BB stores nested objects/arrays — use deep walk.
            $clone = $this->deep_unserialize_clone($raw);
            list($new_blob, $bb_count) = $this->deep_replace_acy($clone, $pattern, $replacement);
            $bb_results[$mk] = array('present' => true, 'replacements' => $bb_count);

            if (!$dry && $bb_count > 0) {
                // Save backup once.
                $backup_key = '_pta_acy_swap_backup_' . $mk;
                if (!metadata_exists('post', $post_id, $backup_key)) {
                    update_post_meta($post_id, $backup_key, $raw);
                }
                update_post_meta($post_id, $mk, $new_blob);
            }
        }
        $report['sources']['beaver_builder'] = $bb_results;

        // 3) Apply post_content change (after BB so BB sees consistent state).
        if (!$dry && $count > 0) {
            if (!metadata_exists('post', $post_id, '_pta_acy_swap_backup_post_content')) {
                update_post_meta($post_id, '_pta_acy_swap_backup_post_content', $orig_content);
            }
            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $new_content,
            ));
        }

        // Re-fetch to confirm what's stored.
        clean_post_cache($post_id);
        $after = get_post($post_id);
        $still_has_acy = $after ? (stripos($after->post_content, 'acymailing') !== false) : null;

        $report['post_status']             = $after ? $after->post_status : null;
        $report['still_has_acy_in_post']   = $still_has_acy;
        $report['note'] = $dry
            ? 'dry_run=1: nothing was written. Re-run with dry_run=0 to apply.'
            : 'Swap applied. Backups saved under _pta_acy_swap_backup_* postmeta.';
        return rest_ensure_response($report);
    }

    /**
     * Recursively walk a value (string/array/object) and replace any
     * AcyMailing shortcode strings with the replacement. Returns
     * [new_value, count_of_replacements].
     */
    private function deep_replace_acy($value, $pattern, $replacement) {
        $count = 0;
        if (is_string($value)) {
            $local = 0;
            $value = preg_replace_callback($pattern, function($m) use (&$local, $replacement) {
                $local++;
                return $replacement;
            }, $value);
            return array($value, $local);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                list($value[$k], $sub) = $this->deep_replace_acy($v, $pattern, $replacement);
                $count += $sub;
            }
            return array($value, $count);
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                list($new, $sub) = $this->deep_replace_acy($v, $pattern, $replacement);
                $value->$k = $new;
                $count += $sub;
            }
            return array($value, $count);
        }
        return array($value, 0);
    }

    /**
     * BB stores meta as a serialized blob; WP unserializes it on
     * get_post_meta() automatically. Make a deep clone so our mutations
     * don't ripple into other code that reads the same array.
     */
    private function deep_unserialize_clone($value) {
        // unserialize → serialize round-trip doesn't help us; the value
        // is already unserialized. Simplest deep clone:
        return unserialize(serialize($value));
    }

    /**
     * GET /diagnostics/cleanup-audit
     *
     * One-shot inventory for the "what can I uninstall safely" pass.
     * Read-only. Sections:
     *
     *   - active_plugins: every active plugin (slug, name, version, file
     *     count) — same data as perf-files-by-plugin but with header info
     *   - candidates: focused report on each known candidate plugin
     *     (printify, advanced-product-fields, event-aggregator,
     *     bb-powerpack, wpcode) — installed? active? files? DB rows?
     *   - shipping: WC shipping zones + methods + their settings, plus
     *     a count of products with `_virtual=yes` / `_downloadable=yes`
     *     / `_pickup_only` flags so we know the "no shipping" baseline
     *   - printify_traces: postmeta keys, wp_options, and tables that
     *     start with `wp_printify_` or contain "printify"
     *   - powerpack_pages: scan _fl_builder_data for `pp-*` BB module
     *     slugs and report which posts/pages depend on them
     *   - event_aggregator: TEC EA settings (active? scheduled imports?
     *     last import time?)
     *   - wpcode: number of published snippets + their titles, so we can
     *     decide if anything other than "disable all comments" still
     *     depends on it
     *
     * Cost: a handful of aggregate queries. None scan postmeta without
     * an indexed key prefix.
     */
    public function route_cleanup_audit($request) {
        global $wpdb;

        $report = array(
            'plugin_version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'unknown',
            'generated_at'   => gmdate('Y-m-d\\TH:i:s\\Z'),
        );

        // --- 1) Active plugins inventory ----------------------------------
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option('active_plugins', array());

        $active_inventory = array();
        foreach ($active_plugins as $plugin_file) {
            if (!isset($all_plugins[$plugin_file])) {
                $active_inventory[] = array(
                    'file'    => $plugin_file,
                    'name'    => '(missing header)',
                    'version' => '?',
                    'slug'    => dirname($plugin_file),
                );
                continue;
            }
            $h = $all_plugins[$plugin_file];
            $active_inventory[] = array(
                'file'    => $plugin_file,
                'slug'    => dirname($plugin_file),
                'name'    => isset($h['Name']) ? $h['Name'] : '',
                'version' => isset($h['Version']) ? $h['Version'] : '',
                'author'  => isset($h['Author']) ? wp_strip_all_tags($h['Author']) : '',
            );
        }
        $report['active_plugins_count'] = count($active_inventory);
        $report['active_plugins']       = $active_inventory;

        // --- 2) Candidates we explicitly want to evaluate -----------------
        $candidates = array(
            'printify' => array(
                'label'        => 'Printify',
                'slug_matches' => array('printify', 'printify-for-woocommerce'),
                'option_like'  => 'printify%',
                'meta_like'    => array('_printify%', 'printify_%'),
                'table_like'   => $wpdb->prefix . 'printify%',
            ),
            'printify_shipping' => array(
                'label'        => 'Printify Shipping Method (separate plugin)',
                'slug_matches' => array('printify-shipping', 'printify-shipping-method', 'wc-printify-shipping'),
                'option_like'  => 'printify_shipping%',
                'meta_like'    => array(),
                'table_like'   => '',
            ),
            'advanced_product_fields' => array(
                'label'        => 'Advanced Product Fields for WooCommerce (Studio Wombat)',
                'slug_matches' => array('advanced-product-fields-for-woocommerce', 'advanced-product-fields-pro-for-woocommerce', 'wcpa', 'woo-product-addon'),
                'option_like'  => 'wapf%',
                'meta_like'    => array('_wapf_%', 'wapf_%'),
                'table_like'   => $wpdb->prefix . 'wapf%',
            ),
            'event_aggregator' => array(
                'label'        => 'TEC Event Aggregator',
                'slug_matches' => array('event-aggregator'),
                'option_like'  => 'tribe_aggregator%',
                'meta_like'    => array('_tribe_aggregator_%', '_EventOrigin'),
                'table_like'   => '',
            ),
            'bb_powerpack' => array(
                'label'        => 'PowerPack for Beaver Builder',
                'slug_matches' => array('bb-powerpack', 'bbpowerpack', 'bb-powerpack-lite'),
                'option_like'  => 'bb_powerpack%',
                'meta_like'    => array(),
                'table_like'   => '',
            ),
            'wpcode' => array(
                'label'        => 'WPCode (Insert Headers, Footers and Code Snippets)',
                'slug_matches' => array('insert-headers-and-footers', 'wpcode'),
                'option_like'  => 'wpcode_%',
                'meta_like'    => array('_wpcode_%'),
                'table_like'   => '',
            ),
        );

        $candidate_report = array();
        foreach ($candidates as $key => $c) {
            $installed = false;
            $active    = false;
            $matched_file = null;
            foreach (array_keys($all_plugins) as $pf) {
                $slug = dirname($pf);
                if (in_array($slug, $c['slug_matches'], true)) {
                    $installed = true;
                    $matched_file = $pf;
                    if (in_array($pf, $active_plugins, true)) {
                        $active = true;
                    }
                    break;
                }
            }

            // Option footprint
            $option_count = 0;
            $option_bytes = 0;
            if (!empty($c['option_like'])) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS b
                     FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $c['option_like']
                ), ARRAY_A);
                if ($row) {
                    $option_count = (int) $row['n'];
                    $option_bytes = (int) $row['b'];
                }
            }

            // Postmeta footprint (sum across all listed prefixes)
            $meta_count = 0;
            $meta_bytes = 0;
            if (!empty($c['meta_like'])) {
                foreach ($c['meta_like'] as $like) {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(meta_value)),0) AS b
                         FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                        $like
                    ), ARRAY_A);
                    if ($row) {
                        $meta_count += (int) $row['n'];
                        $meta_bytes += (int) $row['b'];
                    }
                }
            }

            // Table footprint
            $tables = array();
            if (!empty($c['table_like'])) {
                $found = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $c['table_like']));
                foreach ((array) $found as $t) {
                    $size_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT TABLE_ROWS, (DATA_LENGTH + INDEX_LENGTH) AS bytes
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                        $t
                    ), ARRAY_A);
                    $tables[] = array(
                        'name'  => $t,
                        'rows'  => $size_row ? (int) $size_row['TABLE_ROWS'] : 0,
                        'bytes' => $size_row ? (int) $size_row['bytes'] : 0,
                    );
                }
            }

            $candidate_report[$key] = array(
                'label'         => $c['label'],
                'installed'     => $installed,
                'active'        => $active,
                'plugin_file'   => $matched_file,
                'options'       => array('rows' => $option_count, 'bytes' => $option_bytes),
                'postmeta'      => array('rows' => $meta_count, 'bytes' => $meta_bytes),
                'tables'        => $tables,
            );
        }
        $report['candidates'] = $candidate_report;

        // --- 3) Shipping config ------------------------------------------
        $shipping = array(
            'wc_active'                 => class_exists('WooCommerce'),
            'shipping_calc_enabled'     => get_option('woocommerce_enable_shipping_calc'),
            'ship_to_destination'       => get_option('woocommerce_ship_to_destination'),
            'enable_signup_and_login'   => null,
            'zones'                     => array(),
            'method_class_counts'       => array(),
            'product_baseline'          => array(),
        );
        if (class_exists('WC_Shipping_Zones')) {
            $zones = WC_Shipping_Zones::get_zones();
            // get_zones() omits the "Locations not covered by your other zones" zone (id=0).
            $rows  = $zones;
            // Append zone 0 manually.
            $zone_zero = WC_Shipping_Zones::get_zone(0);
            if ($zone_zero) {
                $rows[] = array(
                    'id'              => 0,
                    'zone_name'       => $zone_zero->get_zone_name(),
                    'zone_order'      => 0,
                    'shipping_methods'=> $zone_zero->get_shipping_methods(false, 'admin'),
                );
            }
            $class_counts = array();
            foreach ($rows as $z) {
                $methods_out = array();
                $methods = isset($z['shipping_methods']) ? $z['shipping_methods'] : array();
                foreach ($methods as $m) {
                    $cls = is_object($m) ? get_class($m) : '(unknown)';
                    $class_counts[$cls] = isset($class_counts[$cls]) ? $class_counts[$cls] + 1 : 1;
                    $methods_out[] = array(
                        'id'       => is_object($m) ? $m->id : null,
                        'title'    => is_object($m) ? $m->get_title() : null,
                        'enabled'  => is_object($m) ? ('yes' === $m->enabled) : null,
                        'class'    => $cls,
                        'instance' => is_object($m) ? $m->instance_id : null,
                    );
                }
                $shipping['zones'][] = array(
                    'id'      => isset($z['id']) ? (int) $z['id'] : (isset($z['zone_id']) ? (int) $z['zone_id'] : null),
                    'name'    => isset($z['zone_name']) ? $z['zone_name'] : '',
                    'methods' => $methods_out,
                );
            }
            $shipping['method_class_counts'] = $class_counts;
        }

        // Product baseline: how many products are virtual / downloadable
        // (no shipping needed) vs the total. Helps confirm "everything is
        // pickup only" is still true.
        $baseline = array();
        $baseline['products_total'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')"
        );
        $baseline['products_virtual'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_virtual' AND meta_value='yes'"
        );
        $baseline['products_downloadable'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_downloadable' AND meta_value='yes'"
        );
        $baseline['products_pickup_only_meta'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_pickup_only' AND meta_value='yes'"
        );
        $baseline['products_with_printify_meta'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_printify_blueprint_id'"
        );
        $shipping['product_baseline'] = $baseline;

        // Flat-rate cost — read every per-instance settings option and
        // surface its `cost` field so we know if Flat rate is actually $0
        // (== pickup) or a real shipping charge.
        $flat_rate_settings = array();
        $fr_options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'woocommerce_flat_rate_%_settings'
                OR option_name LIKE 'woocommerce_local_pickup_%_settings'
                OR option_name LIKE 'woocommerce_free_shipping_%_settings'",
            ARRAY_A
        );
        foreach ((array) $fr_options as $r) {
            $val = maybe_unserialize($r['option_value']);
            $flat_rate_settings[$r['option_name']] = is_array($val) ? array(
                'enabled' => isset($val['enabled']) ? $val['enabled'] : null,
                'title'   => isset($val['title']) ? $val['title'] : null,
                'tax_status' => isset($val['tax_status']) ? $val['tax_status'] : null,
                'cost'    => isset($val['cost']) ? $val['cost'] : null,
                'class_costs' => array_filter(
                    array_map(function($k) use ($val) {
                        return strpos($k, 'class_cost_') === 0 ? $val[$k] : null;
                    }, array_keys($val))
                ),
            ) : $val;
        }
        $shipping['method_settings'] = $flat_rate_settings;

        // Shipping classes (the taxonomy that WC Advanced Packages keys
        // off of for pickup routing).
        $classes = get_terms(array(
            'taxonomy'   => 'product_shipping_class',
            'hide_empty' => false,
        ));
        $class_rows = array();
        if (!is_wp_error($classes)) {
            foreach ($classes as $c) {
                $class_rows[] = array(
                    'slug'  => $c->slug,
                    'name'  => $c->name,
                    'count' => (int) $c->count,
                );
            }
        }
        $shipping['shipping_classes'] = $class_rows;

        // WC Advanced Packages settings (custom plugin)
        $wcap = get_option('wc_advanced_packages_settings', null);
        if ($wcap === null) {
            $wcap = get_option('wc_advanced_packages', null);
        }
        $shipping['advanced_packages_plugin'] = array(
            'plugin_active' => in_array('woocommerce-advanced-packages/woocommerce-advanced-packages.php', $active_plugins, true),
            'settings'      => $wcap,
        );

        $report['shipping'] = $shipping;

        // --- 4) Printify trace (what would deletion leave behind?) -------
        $printify_post_types = $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS n
             FROM {$wpdb->posts}
             WHERE post_type LIKE 'printify%' OR post_type='printify_product' OR post_type='printify_order'
             GROUP BY post_type",
            ARRAY_A
        );
        $printify_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'printify%' ORDER BY option_name LIMIT 40"
        );
        $printify_tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . '%printify%'));
        $report['printify_traces'] = array(
            'post_types'  => $printify_post_types ?: array(),
            'options'     => $printify_options ?: array(),
            'tables'      => $printify_tables ?: array(),
        );

        // --- 5) PowerPack BB module usage --------------------------------
        // BB stores _fl_builder_data as a serialized blob keyed by node;
        // PowerPack module slugs all live in the `settings.type` field
        // and start with `pp-`. We do a substring scan on the raw stored
        // string — fast because LIKE on indexed meta_value (with a
        // LIMIT) is cheap enough for a handful of pages.
        $bb_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ('_fl_builder_data','_fl_builder_draft')
                   AND pm.meta_value LIKE %s
                   AND p.post_status IN ('publish','draft','private','pending')
                 GROUP BY pm.post_id
                 ORDER BY p.post_type, p.post_title
                 LIMIT 500",
                '%' . $wpdb->esc_like('"pp-') . '%'
            ),
            ARRAY_A
        );
        $pp_pages = array();
        $pp_module_counts = array();
        foreach ((array) $bb_rows as $r) {
            $pid = (int) $r['post_id'];
            $modules_here = array();
            foreach (array('_fl_builder_data', '_fl_builder_draft') as $mk) {
                $blob = get_post_meta($pid, $mk, true);
                if (empty($blob)) {
                    continue;
                }
                // Walk the array to collect every settings->type that starts with pp-
                $this->collect_bb_module_types($blob, $modules_here);
            }
            $pp_only = array_values(array_filter($modules_here, function($t) {
                return is_string($t) && strpos($t, 'pp-') === 0;
            }));
            if (empty($pp_only)) {
                continue;
            }
            sort($pp_only);
            $unique_pp = array_values(array_unique($pp_only));
            foreach ($unique_pp as $t) {
                $pp_module_counts[$t] = isset($pp_module_counts[$t]) ? $pp_module_counts[$t] + 1 : 1;
            }
            $pp_pages[] = array(
                'post_id'     => $pid,
                'title'       => $r['post_title'],
                'type'        => $r['post_type'],
                'status'      => $r['post_status'],
                'edit_url'    => admin_url('post.php?post=' . $pid . '&action=edit'),
                'view_url'    => get_permalink($pid),
                'pp_modules'  => $unique_pp,
            );
        }
        arsort($pp_module_counts);
        $report['powerpack_pages'] = array(
            'pages_using_pp_modules' => count($pp_pages),
            'module_usage'           => $pp_module_counts,
            'pages'                  => $pp_pages,
        );

        // --- 6) Event Aggregator runtime status --------------------------
        $ea = array(
            'plugin_active'      => in_array('event-aggregator/event-aggregator.php', $active_plugins, true),
            'tec_active'         => class_exists('Tribe__Events__Main'),
            'records_total'      => 0,
            'records_by_status'  => array(),
            'last_import_at'     => null,
            'scheduled_imports'  => 0,
            'cron_events'        => array(),
        );
        // tribe-ea-record CPT was the EA record store
        $ea_rows = $wpdb->get_results(
            "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts}
             WHERE post_type='tribe-ea-record' GROUP BY post_status",
            ARRAY_A
        );
        foreach ((array) $ea_rows as $r) {
            $ea['records_by_status'][$r['post_status']] = (int) $r['n'];
            $ea['records_total'] += (int) $r['n'];
        }
        if ($ea['records_total'] > 0) {
            $latest = $wpdb->get_var(
                "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
                 WHERE post_type='tribe-ea-record'"
            );
            $ea['last_import_at'] = $latest;
        }
        $ea['scheduled_imports'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='tribe-ea-record' AND post_status='tribe-ea-schedule'"
        );
        // Tribe EA cron hooks
        $crons = _get_cron_array();
        if (is_array($crons)) {
            foreach ($crons as $ts => $hooks) {
                foreach ((array) $hooks as $hook_name => $details) {
                    if (strpos($hook_name, 'tribe_aggregator') !== false || strpos($hook_name, 'tribe_ea') !== false) {
                        $ea['cron_events'][] = array(
                            'hook' => $hook_name,
                            'next' => gmdate('Y-m-d\\TH:i:s\\Z', (int) $ts),
                        );
                    }
                }
            }
        }
        $report['event_aggregator'] = $ea;

        // --- 7) WPCode snippet inventory ---------------------------------
        $wpcode = array(
            'plugin_active'    => in_array('insert-headers-and-footers/ihaf.php', $active_plugins, true)
                                  || in_array('wpcode/wpcode.php', $active_plugins, true),
            'snippets_total'   => 0,
            'snippets_active'  => 0,
            'snippets'         => array(),
        );
        // WPCode 2.x stores snippets as `wpcode` post type
        $wp_rows = $wpdb->get_results(
            "SELECT ID, post_title, post_status FROM {$wpdb->posts}
             WHERE post_type='wpcode' ORDER BY post_status, post_title LIMIT 200",
            ARRAY_A
        );
        foreach ((array) $wp_rows as $r) {
            $wpcode['snippets_total']++;
            if ($r['post_status'] === 'publish') {
                $wpcode['snippets_active']++;
            }
            $wpcode['snippets'][] = array(
                'id'     => (int) $r['ID'],
                'title'  => $r['post_title'],
                'status' => $r['post_status'],
            );
        }
        $report['wpcode'] = $wpcode;

        return rest_ensure_response($report);
    }

    /**
     * Walk a Beaver Builder _fl_builder_data blob (deeply nested
     * array/object of nodes keyed by uuid) and collect every
     * `settings->type` value into &$out.
     *
     * BB schema: top-level array of nodes keyed by uuid; each node
     * has ->settings (object or array) with a `type` slug like
     * `pp-business-hours`, `heading`, `pp-row`, etc.
     */
    private function collect_bb_module_types($value, array &$out) {
        if (is_array($value)) {
            // Each top-level node has a 'settings' or 'type' field.
            if (isset($value['type']) && is_string($value['type'])) {
                $out[] = $value['type'];
            }
            if (isset($value['settings'])) {
                $this->collect_bb_module_types($value['settings'], $out);
            }
            foreach ($value as $k => $v) {
                if ($k === 'settings' || $k === 'type') {
                    continue;
                }
                $this->collect_bb_module_types($v, $out);
            }
            return;
        }
        if (is_object($value)) {
            if (isset($value->type) && is_string($value->type)) {
                $out[] = $value->type;
            }
            if (isset($value->settings)) {
                $this->collect_bb_module_types($value->settings, $out);
            }
            foreach (get_object_vars($value) as $k => $v) {
                if ($k === 'settings' || $k === 'type') {
                    continue;
                }
                $this->collect_bb_module_types($v, $out);
            }
        }
    }

    /**
     * POST /diagnostics/cleanup-execute
     *
     * Executes one or more zero-risk cleanup actions identified by the
     * cleanup-audit endpoint. Every mutation is backed up to a JSON
     * file under wp-content/uploads/pta-cleanup-backups/ before deletion.
     *
     * Required body params:
     *   confirm        = "yes-i-am-sure"     (mandatory safety string)
     *   dry_run        = 1|0  (default 1)
     *   actions        = comma-separated list of action keys
     *
     * Action keys:
     *   deactivate_ea_extension  — deactivate tribe-ext-ea-additional-options
     *   unschedule_ea_cron       — wp_unschedule_event for tribe_aggregator_cron
     *   purge_ea_postmeta        — DELETE postmeta with EA-prefixed keys
     *   delete_wpcode_plugin     — deactivate AND uninstall WPCode (plugin files removed)
     *   purge_wpcode_postmeta    — DELETE postmeta with WPCode-prefixed keys
     *   purge_wpcode_options     — DELETE wp_options with WPCode-prefixed keys
     */
    public function route_cleanup_execute($request) {
        $confirm = (string) $request->get_param('confirm');
        if ($confirm !== 'yes-i-am-sure') {
            return new WP_Error(
                'missing_confirm',
                'You must POST confirm=yes-i-am-sure to execute. Use dry_run=1 to preview.',
                array('status' => 400)
            );
        }
        $dry = $request->get_param('dry_run');
        $dry = ($dry === null) ? true : ((int) $dry !== 0);

        $actions_raw = (string) $request->get_param('actions');
        if ($actions_raw === '') {
            return new WP_Error('missing_actions', 'Provide actions=action1,action2', array('status' => 400));
        }
        $actions = array_map('trim', explode(',', $actions_raw));

        // Set up a backups dir under uploads.
        $up = wp_upload_dir();
        $backup_dir = trailingslashit($up['basedir']) . 'pta-cleanup-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        $stamp = gmdate('Ymd-His');

        $report = array(
            'plugin_version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'unknown',
            'generated_at'   => gmdate('Y-m-d\\TH:i:s\\Z'),
            'dry_run'        => $dry,
            'requested'      => $actions,
            'results'        => array(),
        );

        global $wpdb;

        foreach ($actions as $action) {
            $r = array('action' => $action, 'ok' => false, 'detail' => array());
            try {
                switch ($action) {
                    case 'deactivate_ea_extension':
                        $r = $this->action_deactivate_plugin('tribe-ext-ea-additional-options/tribe-ext-ea-additional-options.php', $dry);
                        break;

                    case 'unschedule_ea_cron':
                        $r = $this->action_unschedule_hook('tribe_aggregator_cron', $dry);
                        break;

                    case 'purge_ea_postmeta':
                        $r = $this->action_purge_postmeta(
                            array('_tribe_aggregator_%', '_EventOrigin'),
                            $backup_dir . "/ea-postmeta-{$stamp}.json",
                            $dry
                        );
                        break;

                    case 'delete_wpcode_plugin':
                        // First deactivate (idempotent if already inactive),
                        // then uninstall via delete_plugins() which removes
                        // the plugin files entirely.
                        $r = $this->action_delete_plugin('insert-headers-and-footers/ihaf.php', $dry);
                        break;

                    case 'purge_wpcode_postmeta':
                        $r = $this->action_purge_postmeta(
                            array('_wpcode_%'),
                            $backup_dir . "/wpcode-postmeta-{$stamp}.json",
                            $dry
                        );
                        break;

                    case 'purge_wpcode_options':
                        $r = $this->action_purge_options(
                            array('wpcode_%'),
                            $backup_dir . "/wpcode-options-{$stamp}.json",
                            $dry
                        );
                        break;

                    default:
                        $r = array('action' => $action, 'ok' => false, 'detail' => array('error' => 'unknown action'));
                }
            } catch (Exception $e) {
                $r = array('action' => $action, 'ok' => false, 'detail' => array('exception' => $e->getMessage()));
            }
            $report['results'][] = $r;
        }

        return rest_ensure_response($report);
    }

    private function action_deactivate_plugin($plugin_file, $dry) {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = is_plugin_active($plugin_file);
        $detail = array(
            'plugin'       => $plugin_file,
            'was_active'   => $active,
            'now_active'   => $active,
            'action_taken' => $active ? ($dry ? 'would deactivate' : 'deactivated') : 'no-op (already inactive)',
        );
        if ($active && !$dry) {
            deactivate_plugins(array($plugin_file), true);
            $detail['now_active'] = is_plugin_active($plugin_file);
        }
        return array('action' => 'deactivate:' . $plugin_file, 'ok' => true, 'detail' => $detail);
    }

    private function action_delete_plugin($plugin_file, $dry) {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $active   = is_plugin_active($plugin_file);
        $exists   = file_exists(WP_PLUGIN_DIR . '/' . $plugin_file);
        $detail = array(
            'plugin'      => $plugin_file,
            'was_active'  => $active,
            'file_exists' => $exists,
            'steps'       => array(),
        );
        if (!$exists) {
            $detail['steps'][] = 'no-op (plugin files not present)';
            return array('action' => 'delete:' . $plugin_file, 'ok' => true, 'detail' => $detail);
        }
        if ($active && !$dry) {
            deactivate_plugins(array($plugin_file), true);
            $detail['steps'][] = 'deactivated';
        } elseif ($active) {
            $detail['steps'][] = 'would deactivate';
        }
        if (!$dry) {
            // delete_plugins requires WP_Filesystem; init the direct one.
            if (!class_exists('WP_Filesystem_Direct')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            $result = delete_plugins(array($plugin_file));
            if (is_wp_error($result)) {
                $detail['steps'][] = 'delete_plugins error: ' . $result->get_error_message();
                return array('action' => 'delete:' . $plugin_file, 'ok' => false, 'detail' => $detail);
            }
            $detail['steps'][] = 'plugin files removed';
        } else {
            $detail['steps'][] = 'would delete plugin files';
        }
        return array('action' => 'delete:' . $plugin_file, 'ok' => true, 'detail' => $detail);
    }

    private function action_unschedule_hook($hook, $dry) {
        $crons = _get_cron_array();
        $found = array();
        if (is_array($crons)) {
            foreach ($crons as $ts => $hooks) {
                if (isset($hooks[$hook])) {
                    foreach ($hooks[$hook] as $sig => $event) {
                        $found[] = array(
                            'timestamp' => (int) $ts,
                            'when'      => gmdate('Y-m-d\\TH:i:s\\Z', (int) $ts),
                            'args'      => isset($event['args']) ? $event['args'] : array(),
                        );
                        if (!$dry) {
                            wp_unschedule_event((int) $ts, $hook, isset($event['args']) ? $event['args'] : array());
                        }
                    }
                }
            }
        }
        return array(
            'action' => 'unschedule:' . $hook,
            'ok'     => true,
            'detail' => array(
                'hook'              => $hook,
                'events_found'      => count($found),
                'events'            => $found,
                'action_taken'      => $dry ? 'would unschedule' : 'unscheduled',
            ),
        );
    }

    private function action_purge_postmeta(array $like_patterns, $backup_path, $dry) {
        global $wpdb;
        $rows = array();
        $row_count = 0;
        $bytes = 0;
        foreach ($like_patterns as $like) {
            $batch = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s
                 LIMIT 5000",
                $like
            ), ARRAY_A);
            foreach ((array) $batch as $r) {
                $rows[] = $r;
                $row_count++;
                $bytes += strlen((string) $r['meta_value']);
            }
        }
        if (!$dry && !empty($rows)) {
            file_put_contents($backup_path, wp_json_encode($rows, JSON_PRETTY_PRINT));
        }
        $deleted = 0;
        if (!$dry && !empty($rows)) {
            foreach ($like_patterns as $like) {
                $deleted += (int) $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                    $like
                ));
            }
        }
        return array(
            'action' => 'purge_postmeta:' . implode(',', $like_patterns),
            'ok'     => true,
            'detail' => array(
                'patterns'       => $like_patterns,
                'rows_found'     => $row_count,
                'bytes_found'    => $bytes,
                'rows_deleted'   => $deleted,
                'backup_file'    => $dry ? null : ($row_count > 0 ? $backup_path : null),
                'action_taken'   => $dry ? 'would delete' : 'deleted',
            ),
        );
    }

    private function action_purge_options(array $like_patterns, $backup_path, $dry) {
        global $wpdb;
        $rows = array();
        $row_count = 0;
        $bytes = 0;
        foreach ($like_patterns as $like) {
            $batch = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id, option_name, option_value, autoload
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                $like
            ), ARRAY_A);
            foreach ((array) $batch as $r) {
                $rows[] = $r;
                $row_count++;
                $bytes += strlen((string) $r['option_value']);
            }
        }
        if (!$dry && !empty($rows)) {
            file_put_contents($backup_path, wp_json_encode($rows, JSON_PRETTY_PRINT));
        }
        $deleted = 0;
        if (!$dry && !empty($rows)) {
            foreach ($like_patterns as $like) {
                $deleted += (int) $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                ));
            }
        }
        return array(
            'action' => 'purge_options:' . implode(',', $like_patterns),
            'ok'     => true,
            'detail' => array(
                'patterns'     => $like_patterns,
                'rows_found'   => $row_count,
                'bytes_found'  => $bytes,
                'rows_deleted' => $deleted,
                'backup_file'  => $dry ? null : ($row_count > 0 ? $backup_path : null),
                'action_taken' => $dry ? 'would delete' : 'deleted',
            ),
        );
    }

    /**
     * GET /diagnostics/apf-inspect
     *
     * Dumps every postmeta row owned by Advanced Product Fields for WC
     * (StudioWombat). Pairs each row with its post title + edit link
     * so the user can see what to migrate.
     */
    public function route_apf_inspect($request) {
        global $wpdb;
        $patterns = array('_wapf_%', 'wapf_%');
        $rows = array();
        foreach ($patterns as $like) {
            $batch = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value,
                        p.post_title, p.post_type, p.post_status
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key LIKE %s
                 ORDER BY pm.post_id, pm.meta_key
                 LIMIT 200",
                $like
            ), ARRAY_A);
            foreach ((array) $batch as $r) {
                $val = $r['meta_value'];
                $unser = maybe_unserialize($val);
                $rows[] = array(
                    'post_id'     => (int) $r['post_id'],
                    'title'       => $r['post_title'],
                    'type'        => $r['post_type'],
                    'status'      => $r['post_status'],
                    'edit_url'    => admin_url('post.php?post=' . (int) $r['post_id'] . '&action=edit'),
                    'meta_key'    => $r['meta_key'],
                    'meta_size'   => strlen((string) $val),
                    'meta_value'  => $unser,
                );
            }
        }
        return rest_ensure_response(array(
            'rows_total' => count($rows),
            'rows'       => $rows,
        ));
    }

    /**
     * GET /diagnostics/bb-page-inspect?post_id=N
     *
     * Returns a flat ordered list of every Beaver Builder node on a
     * post: rows, columns, modules. For each module we surface the
     * `type` slug and a small subset of useful settings (heading text,
     * image URL/id, caption) so we can plan a PowerPack-to-native
     * rebuild without opening BB itself.
     */
    public function route_bb_page_inspect($request) {
        $post_id = (int) $request->get_param('post_id');
        if ($post_id <= 0) {
            return new WP_Error('bad_post_id', 'post_id is required', array('status' => 400));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', 'post not found', array('status' => 404));
        }

        $modules = array();
        foreach (array('_fl_builder_data', '_fl_builder_draft') as $mk) {
            $blob = get_post_meta($post_id, $mk, true);
            if (empty($blob)) {
                continue;
            }
            // BB stores nodes keyed by uuid; each node has ->type
            // (row|column|column-group|module) and ->settings.
            if (is_array($blob)) {
                foreach ($blob as $uuid => $node) {
                    $nt = is_object($node) ? (isset($node->type) ? $node->type : null)
                                           : (isset($node['type']) ? $node['type'] : null);
                    if ($nt !== 'module') {
                        continue;
                    }
                    $settings = is_object($node) ? (isset($node->settings) ? $node->settings : null)
                                                 : (isset($node['settings']) ? $node['settings'] : null);
                    $type_slug = null;
                    if (is_object($settings) && isset($settings->type)) {
                        $type_slug = $settings->type;
                    } elseif (is_array($settings) && isset($settings['type'])) {
                        $type_slug = $settings['type'];
                    }
                    $is_pp = is_string($type_slug) && strpos($type_slug, 'pp-') === 0;

                    // Pull a few human-readable fields if present.
                    $brief = array();
                    foreach (array('heading', 'text', 'caption', 'photo_url', 'photo_src', 'image', 'link', 'cta_text', 'title') as $field) {
                        $val = is_object($settings) ? (isset($settings->$field) ? $settings->$field : null)
                                                    : (isset($settings[$field]) ? $settings[$field] : null);
                        if ($val !== null && $val !== '') {
                            $brief[$field] = is_string($val) ? wp_strip_all_tags(substr($val, 0, 200)) : $val;
                        }
                    }
                    $modules[] = array(
                        'source'    => $mk,
                        'uuid'      => $uuid,
                        'type'      => $type_slug,
                        'is_pp'     => $is_pp,
                        'brief'     => $brief,
                    );
                }
            }
        }
        return rest_ensure_response(array(
            'post_id'      => $post_id,
            'title'        => $post->post_title,
            'edit_url'     => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'modules_total'=> count($modules),
            'pp_modules'   => count(array_filter($modules, function($m) { return !empty($m['is_pp']); })),
            'modules'      => $modules,
        ));
    }

    /**
     * GET /diagnostics/post-carousel-inspect?post_id=N
     *
     * Walks the BB data for a post and, for every post-carousel /
     * post-grid / post-feed module, returns the full settings blob —
     * specifically the filter-relevant fields (`data_source`,
     * `posts_post`, `tax_post_post_tag`, `tax_post_category`, custom
     * post lists, order, etc.). This tells us with certainty whether
     * a converted page would appear in the loop.
     */
    public function route_post_carousel_inspect($request) {
        $post_id = (int) $request->get_param('post_id');
        if ($post_id <= 0) {
            return new WP_Error('bad_post_id', 'post_id is required', array('status' => 400));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', 'post not found', array('status' => 404));
        }

        // BB module type slugs that pull from a post loop. Includes the
        // first-party "post-carousel"/"posts" modules and PowerPack
        // variants used widely in the wild.
        $loop_types = array('post-carousel', 'posts', 'post-grid', 'post-feed', 'post-slider', 'pp-content-grid', 'pp-posts');

        $found = array();
        foreach (array('_fl_builder_data', '_fl_builder_draft') as $mk) {
            $blob = get_post_meta($post_id, $mk, true);
            if (!is_array($blob)) {
                continue;
            }
            foreach ($blob as $uuid => $node) {
                $nt = is_object($node) ? (isset($node->type) ? $node->type : null)
                                       : (isset($node['type']) ? $node['type'] : null);
                if ($nt !== 'module') continue;
                $settings = is_object($node) ? (isset($node->settings) ? $node->settings : null)
                                             : (isset($node['settings']) ? $node['settings'] : null);
                $type_slug = is_object($settings) ? (isset($settings->type) ? $settings->type : null)
                                                   : (is_array($settings) && isset($settings['type']) ? $settings['type'] : null);
                if (!in_array($type_slug, $loop_types, true)) continue;

                // Cast settings to an array so we can json-walk it.
                $arr = json_decode(json_encode($settings), true);
                if (!is_array($arr)) $arr = array();

                // Pull the high-signal fields by name.
                $filter = array();
                foreach (array(
                    'data_source','match','order','order_by','orderby','offset','number','posts_per_page',
                    'post_type','posts','users','users_matching',
                    'posts_post','users_matching_post','tax_post_category','tax_post_post_tag',
                    'tax_event_category','include_categories','exclude_categories',
                ) as $f) {
                    if (array_key_exists($f, $arr) && $arr[$f] !== '' && $arr[$f] !== null && $arr[$f] !== array()) {
                        $filter[$f] = $arr[$f];
                    }
                }

                $found[] = array(
                    'source'   => $mk,
                    'uuid'     => $uuid,
                    'type'     => $type_slug,
                    'filter'   => $filter,
                );
            }
        }
        return rest_ensure_response(array(
            'post_id'  => $post_id,
            'title'    => $post->post_title,
            'modules'  => $found,
            'count'    => count($found),
        ));
    }

    /**
     * POST /diagnostics/post-type-convert
     *
     * Body (JSON):
     *   post_id          (int, required)        Post to convert.
     *   to_type          (string, default post) Target post_type. Must
     *                                           exist and be public.
     *   dry_run          (bool, default true)   Preview only.
     *   set_post_date    (string, optional)     'now' to use current
     *                                           time, or YYYY-MM-DD HH:MM
     *                                           to set explicitly. Omit
     *                                           or 'keep' to leave alone.
     *   category_ids     (array<int>, optional) Categories to assign
     *                                           (when to_type=post).
     *   category_slugs   (array<string>, optional)
     *   add_redirect     (bool, default true)   Persist a slug→new-url
     *                                           redirect entry so any
     *                                           404 on the old slug 301s
     *                                           to the new permalink.
     *
     * Preserves: post_content, BB data (_fl_builder_data + _fl_builder_draft),
     * BB enabled flag, _thumbnail_id (featured image), all custom meta
     * EXCEPT WP-internal `_wp_page_template`, post_author, post_status.
     *
     * Returns: old/new permalinks, taxonomy assignments, redirect entry
     * written, dry-run preview when applicable.
     */
    public function route_post_type_convert($request) {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) $body = array();

        $post_id = isset($body['post_id']) ? (int) $body['post_id'] : 0;
        $to_type = isset($body['to_type']) ? sanitize_key($body['to_type']) : 'post';
        $dry_run = !isset($body['dry_run']) ? true : (bool) $body['dry_run'];
        $set_post_date = isset($body['set_post_date']) ? (string) $body['set_post_date'] : 'keep';
        $cat_ids   = isset($body['category_ids']) && is_array($body['category_ids']) ? array_map('intval', $body['category_ids']) : array();
        $cat_slugs = isset($body['category_slugs']) && is_array($body['category_slugs']) ? array_map('sanitize_title', $body['category_slugs']) : array();
        $add_redirect = !isset($body['add_redirect']) ? true : (bool) $body['add_redirect'];

        if ($post_id <= 0) {
            return new WP_Error('bad_post_id', 'post_id is required', array('status' => 400));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', 'post not found', array('status' => 404));
        }
        if (!post_type_exists($to_type)) {
            return new WP_Error('bad_to_type', "post_type '{$to_type}' does not exist", array('status' => 400));
        }
        if ($post->post_type === $to_type) {
            return new WP_Error('noop', "post is already type '{$to_type}'", array('status' => 400));
        }

        // Resolve category slugs -> ids.
        if (!empty($cat_slugs)) {
            foreach ($cat_slugs as $slug) {
                $term = get_term_by('slug', $slug, 'category');
                if ($term && !is_wp_error($term)) {
                    $cat_ids[] = (int) $term->term_id;
                }
            }
        }
        $cat_ids = array_values(array_unique(array_filter($cat_ids)));

        // Resolve set_post_date to old/new strings.
        $new_post_date_local = null;
        $new_post_date_gmt   = null;
        if ($set_post_date && $set_post_date !== 'keep') {
            $tz = wp_timezone();
            if ($set_post_date === 'now') {
                $dt = new DateTimeImmutable('now', $tz);
            } else {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $set_post_date, $tz);
                if (!$dt) $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $set_post_date, $tz);
                if (!$dt) {
                    return new WP_Error('bad_date', 'set_post_date must be "now", YYYY-MM-DD HH:MM, or YYYY-MM-DD HH:MM:SS', array('status' => 400));
                }
            }
            $new_post_date_local = $dt->format('Y-m-d H:i:s');
            $new_post_date_gmt   = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $old_permalink = get_permalink($post_id);
        $old_slug      = $post->post_name;
        $old_type      = $post->post_type;
        $old_post_date = $post->post_date;

        // BB markers (informational only — we don't touch them).
        $has_bb_data  = (bool) get_post_meta($post_id, '_fl_builder_data', true);
        $has_bb_draft = (bool) get_post_meta($post_id, '_fl_builder_draft', true);
        $bb_enabled   = (string) get_post_meta($post_id, '_fl_builder_enabled', true);

        // Existing categories on the post (whether or not the source
        // type uses them — we just record).
        $existing_cat_ids = array_map('intval', wp_get_object_terms($post_id, 'category', array('fields' => 'ids')));

        $plan = array(
            'post_id'        => $post_id,
            'title'          => $post->post_title,
            'old' => array(
                'post_type'     => $old_type,
                'permalink'     => $old_permalink,
                'slug'          => $old_slug,
                'post_date'     => $old_post_date,
                'categories'    => $existing_cat_ids,
                'has_bb_data'   => $has_bb_data,
                'has_bb_draft'  => $has_bb_draft,
                'bb_enabled'    => $bb_enabled,
            ),
            'new' => array(
                'post_type'     => $to_type,
                'post_date'     => $new_post_date_local ? $new_post_date_local : $old_post_date,
                'post_date_gmt' => $new_post_date_gmt   ? $new_post_date_gmt   : $post->post_date_gmt,
                'categories'    => !empty($cat_ids) ? $cat_ids : $existing_cat_ids,
                'add_redirect'  => $add_redirect,
            ),
        );

        if ($dry_run) {
            $plan['dry_run'] = true;
            $plan['note']    = 'No changes applied. Post by setting dry_run=false to commit.';
            return rest_ensure_response($plan);
        }

        // ---- Apply ----
        global $wpdb;

        // 1. post_type swap. We do this with a direct UPDATE so we
        //    don't trip wp_update_post's slug-uniqueness handling
        //    (which would rename the slug if a same-named post exists).
        $update_data = array('post_type' => $to_type);
        $update_format = array('%s');
        if ($new_post_date_local && $new_post_date_gmt) {
            $update_data['post_date']     = $new_post_date_local;
            $update_data['post_date_gmt'] = $new_post_date_gmt;
            $update_format[] = '%s';
            $update_format[] = '%s';
        }
        $rows = $wpdb->update($wpdb->posts, $update_data, array('ID' => $post_id), $update_format, array('%d'));
        if ($rows === false) {
            return new WP_Error('db_error', 'Failed to update wp_posts: ' . $wpdb->last_error, array('status' => 500));
        }
        clean_post_cache($post_id);

        // 2. Page-template meta is meaningless on `post`; remove only
        //    when target is `post` so we don't surprise other CPTs.
        if ($to_type === 'post') {
            delete_post_meta($post_id, '_wp_page_template');
        }

        // 3. Apply categories if provided OR if target=post and post
        //    had none. (WordPress will auto-assign the default
        //    category if we leave it untouched, which is usually
        //    fine — we only act if explicitly asked.)
        $taxonomy_result = null;
        if ($to_type === 'post' && !empty($cat_ids)) {
            $tax_set = wp_set_object_terms($post_id, $cat_ids, 'category', false);
            if (is_wp_error($tax_set)) {
                $taxonomy_result = array('error' => $tax_set->get_error_message());
            } else {
                $taxonomy_result = array('term_taxonomy_ids' => array_map('intval', $tax_set));
            }
        }

        // 4. Persist a slug-based redirect entry for graceful 301s.
        $redirect_entry = null;
        if ($add_redirect && $old_slug) {
            $opt_key = 'pta_post_type_redirects';
            $map = get_option($opt_key, array());
            if (!is_array($map)) $map = array();
            // Map BOTH the old type-aware permalink path AND the slug,
            // so we can match on either.
            $old_path = parse_url((string) $old_permalink, PHP_URL_PATH);
            $new_url  = get_permalink($post_id);
            $entry = array(
                'old_path'  => $old_path,
                'old_slug'  => $old_slug,
                'old_type'  => $old_type,
                'new_url'   => $new_url,
                'new_type'  => $to_type,
                'post_id'   => $post_id,
                'created'   => gmdate('c'),
            );
            $map[$old_slug] = $entry;
            update_option($opt_key, $map, false);
            $redirect_entry = $entry;
        }

        // 5. Final state read-back.
        $after = get_post($post_id);
        $new_permalink = get_permalink($post_id);

        return rest_ensure_response(array(
            'dry_run'      => false,
            'plan'         => $plan,
            'applied' => array(
                'post_type_now'  => $after ? $after->post_type : null,
                'new_permalink'  => $new_permalink,
                'post_date_now'  => $after ? $after->post_date : null,
                'taxonomy'       => $taxonomy_result,
                'redirect_entry' => $redirect_entry,
            ),
            'next_steps' => array(
                'flush_rewrite' => 'Visit Settings -> Permalinks (or call flush_rewrite_rules()) to refresh URL routing.',
                'verify_url'    => $new_permalink,
            ),
        ));
    }
}
