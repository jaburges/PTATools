<?php
/**
 * PTA Event CPT
 *
 * Native Custom Post Types for events, venues, and organizers, plus the
 * matching taxonomy. Replaces the dependency on The Events Calendar (TEC)
 * by giving PTA Tools its own event domain model.
 *
 * Phase 0 of the TEC -> pta_event migration. See docs/internal/TECmigration.md
 * for the full plan.
 *
 * Feature-flag gated:
 *   - `pta_calendar_owner = tec`   -> nothing registers (current behaviour)
 *   - `pta_calendar_owner = both`  -> registers pta_* alongside tribe_*
 *   - `pta_calendar_owner = pta`   -> registers pta_* only (post-cutover)
 *
 * The flag lives in the `azure_plugin_settings` option and is read via
 * Azure_Settings::get_setting() so it shares the per-request cache with
 * every other module setting.
 *
 * Meta key compatibility:
 *   We deliberately keep TEC's meta key names (_EventStartDate, etc.) on
 *   pta_event so the backfill is a straight row copy with no rewrite, and
 *   so any rollback to TEC is non-destructive. Meta keys remain stable
 *   across the entire migration.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Event_CPT {

    private static $instance = null;

    const POST_TYPE_EVENT     = 'pta_event';
    const POST_TYPE_VENUE     = 'pta_venue';
    const POST_TYPE_ORGANIZER = 'pta_organizer';

    // Internal taxonomy name for register_taxonomy(). The PUBLIC slug is
    // 'tribe_events_cat' (set in $rewrite['slug']) so existing category
    // URLs and term IDs survive the migration. This taxonomy attaches to
    // BOTH tribe_events and pta_event so terms are shared.
    const TAXONOMY_CATEGORY = 'pta_event_category';
    const TAXONOMY_PUBLIC_SLUG = 'tribe_events_cat';

    // Settings keys.
    const FLAG_OWNER       = 'pta_calendar_owner';
    const FLAG_DATA_SOURCE = 'pta_calendar_data_source';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the current owner flag. Reads from Azure_Settings if
     * available (cached per-request), falls back to raw get_option()
     * otherwise.
     *
     * @return string One of 'tec', 'both', 'pta'.
     */
    public static function get_owner() {
        if (class_exists('Azure_Settings')) {
            $val = Azure_Settings::get_setting(self::FLAG_OWNER, 'tec');
        } else {
            $opts = get_option('azure_plugin_settings', array());
            $val = is_array($opts) && isset($opts[self::FLAG_OWNER])
                ? $opts[self::FLAG_OWNER]
                : 'tec';
        }
        $val = is_string($val) ? strtolower(trim($val)) : 'tec';
        if (!in_array($val, array('tec', 'both', 'pta'), true)) {
            $val = 'tec';
        }
        return $val;
    }

    public static function is_pta_owner_active() {
        return self::get_owner() !== 'tec';
    }

    /**
     * Returns the current read-side data source flag.
     *
     * Controls which post type consumer-facing shortcodes/templates
     * read from. Independent of the writer-side `owner` flag so we can
     * keep dual-writing while still reading the old data, then flip to
     * `pta` only when we're confident the new data path works.
     *
     * @return string One of 'tribe' (default), 'both', 'pta'.
     */
    public static function get_data_source() {
        if (class_exists('Azure_Settings')) {
            $val = Azure_Settings::get_setting(self::FLAG_DATA_SOURCE, 'tribe');
        } else {
            $opts = get_option('azure_plugin_settings', array());
            $val = is_array($opts) && isset($opts[self::FLAG_DATA_SOURCE])
                ? $opts[self::FLAG_DATA_SOURCE]
                : 'tribe';
        }
        $val = is_string($val) ? strtolower(trim($val)) : 'tribe';
        if (!in_array($val, array('tribe', 'both', 'pta'), true)) {
            $val = 'tribe';
        }
        return $val;
    }

    /**
     * Returns the post type a consumer should query for events given
     * the current data_source flag.
     *
     * 'tribe' -> 'tribe_events'  (Phase 1-4 default)
     * 'both'  -> 'tribe_events'  (still prefer tribe; pta is just a mirror)
     * 'pta'   -> 'pta_event'     (Phase 5 cutover)
     *
     * Consumers should use this rather than hard-coding the post type
     * name so a single flag flip migrates the whole site at once.
     *
     * @return string Post type slug.
     */
    public static function query_post_type() {
        $src = self::get_data_source();
        if ($src === 'pta') {
            return self::POST_TYPE_EVENT;
        }
        return 'tribe_events';
    }

    /**
     * Returns the taxonomy a consumer should query for event categories.
     *
     * Both taxonomies share term IDs in our setup (Phase 0 attached the
     * pta_event_category taxonomy to BOTH post types using the same
     * public slug 'tribe_events_cat'), so for category filters by NAME
     * either works. For tax_query lookups we pick the matching
     * taxonomy name to keep WP's term_relationships joins efficient.
     *
     * @return string Taxonomy slug.
     */
    public static function query_taxonomy() {
        $src = self::get_data_source();
        if ($src === 'pta') {
            return self::TAXONOMY_CATEGORY;
        }
        return self::TAXONOMY_PUBLIC_SLUG; // 'tribe_events_cat'
    }

    /**
     * Tells consumers whether TEC is required as a hard dependency.
     *
     * Returns true unless data_source is 'pta' (Phase 5+) AND TEC's
     * Tribe__Events__Main class is unavailable. Lets shortcodes drop
     * the legacy "TEC must be active" guards at cutover time without
     * changing behaviour now.
     *
     * @return bool
     */
    public static function tec_required() {
        return self::get_data_source() !== 'pta';
    }

    private function __construct() {
        // CPT/taxonomy registration must happen on `init`. We register
        // at priority 20 because the parent plugin's bootstrap calls
        // get_instance() from INSIDE its own `init` callback (priority 10).
        // Once WP has dispatched a priority, you cannot add a callback
        // for an EARLIER priority and have it fire — so registering at
        // a HIGHER priority (20) is the safe choice.
        add_action('init', array($this, 'register_types'), 20);
        // One-shot rewrite-rules flush after a flag flip. The diagnostics
        // endpoint sets a transient when the owner flag changes, and we
        // honour it here on the next request (any context) by calling
        // flush_rewrite_rules() once and clearing the transient.
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 99);

        // Phase 4 admin UI: meta-box + list-table columns so editors
        // can author and triage pta_event posts without TEC's UI. Only
        // wire these up in admin/REST contexts where they actually
        // matter — front-end requests pay nothing.
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            add_action('add_meta_boxes_' . self::POST_TYPE_EVENT, array($this, 'register_event_metabox'));
            add_action('save_post_' . self::POST_TYPE_EVENT, array($this, 'save_event_metabox'), 10, 2);
            add_filter('manage_' . self::POST_TYPE_EVENT . '_posts_columns', array($this, 'event_admin_columns'));
            add_action('manage_' . self::POST_TYPE_EVENT . '_posts_custom_column', array($this, 'event_admin_column_value'), 10, 2);
            add_filter('manage_edit-' . self::POST_TYPE_EVENT . '_sortable_columns', array($this, 'event_admin_sortable_columns'));
            add_action('pre_get_posts', array($this, 'event_admin_orderby'));
        }

        // Phase 5 frontend single-event view: when data_source=pta,
        // hijack /event/<slug>/ requests so they resolve to pta_event
        // (otherwise WP picks tribe_events first because TEC registered
        // its rewrite rule earlier in `init`). Then load our template
        // override so the page renders with PTA-Tools markup.
        if (!is_admin() && !(defined('REST_REQUEST') && REST_REQUEST) && !wp_doing_ajax()) {
            add_action('pre_get_posts', array($this, 'maybe_swap_to_pta_event_on_single'));
            // We use `template_include` (the last filter in the
            // template-resolution chain) at high priority so we beat
            // anything the theme or other plugins (like TEC) hook
            // earlier. `single_template` alone gets overwritten by
            // theme defaults that hook the same filter at priority 10.
            add_filter('single_template', array($this, 'maybe_load_single_template'), 99);
            add_filter('template_include', array($this, 'maybe_load_single_template'), 99);
            add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_single_event_styles'));
            // .ics download handler — `?pta_ical=<id>` on any URL.
            add_action('init', array($this, 'maybe_serve_ics'), 99);
        }

        // If the parent plugin's `init` has already fired (e.g., we were
        // instantiated late by a REST request), register synchronously
        // so the post type is available for the rest of THIS request.
        // Idempotent: WP register_post_type() silently no-ops re-registers.
        if (did_action('init')) {
            $this->register_types();
        }
    }

    public function maybe_flush_rewrite_rules() {
        if (!get_transient('pta_event_flush_rewrite_rules')) {
            return;
        }
        delete_transient('pta_event_flush_rewrite_rules');
        flush_rewrite_rules(false);
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(
                'Event CPT: rewrite rules flushed after pta_calendar_owner flag change',
                array('module' => 'Events')
            );
        }
    }

    /**
     * Register pta_event, pta_venue, pta_organizer, and the shared
     * category taxonomy. Only fires when the owner flag enables PTA.
     */
    public function register_types() {
        if (!self::is_pta_owner_active()) {
            return;
        }

        $this->register_event_post_type();
        $this->register_venue_post_type();
        $this->register_organizer_post_type();
        $this->register_category_taxonomy();

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug(
                'Event CPT: registered pta_event/pta_venue/pta_organizer (owner=' . self::get_owner() . ')',
                array('module' => 'Events')
            );
        }
    }

    private function register_event_post_type() {
        $labels = array(
            'name'                  => 'Events',
            'singular_name'         => 'Event',
            'menu_name'             => 'Events',
            'name_admin_bar'        => 'Event',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Event',
            'edit_item'             => 'Edit Event',
            'new_item'              => 'New Event',
            'view_item'             => 'View Event',
            'view_items'            => 'View Events',
            'search_items'          => 'Search Events',
            'not_found'             => 'No events found.',
            'not_found_in_trash'    => 'No events found in trash.',
            'all_items'             => 'All Events',
            'archives'              => 'Event Archives',
            'attributes'            => 'Event Attributes',
            'insert_into_item'      => 'Insert into event',
            'uploaded_to_this_item' => 'Uploaded to this event',
            'featured_image'        => 'Event image',
            'set_featured_image'    => 'Set event image',
            'remove_featured_image' => 'Remove event image',
            'use_featured_image'    => 'Use as event image',
        );

        $args = array(
            'labels'              => $labels,
            'description'         => 'PTA Tools events synced from Outlook and authored locally.',
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'show_in_rest'        => true,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-calendar-alt',
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'has_archive'         => 'events',
            'rewrite'             => array(
                'slug'       => 'event',
                'with_front' => false,
                'feeds'      => true,
                'pages'      => true,
            ),
            'query_var'           => true,
            'supports'            => array(
                'title',
                'editor',
                'author',
                'thumbnail',
                'excerpt',
                'revisions',
                'custom-fields',
            ),
        );

        /**
         * Filter the pta_event post type registration args.
         *
         * @param array $args Args passed to register_post_type().
         */
        $args = apply_filters('pta_event_post_type_args', $args);

        register_post_type(self::POST_TYPE_EVENT, $args);
    }

    private function register_venue_post_type() {
        $labels = array(
            'name'               => 'Venues',
            'singular_name'      => 'Venue',
            'menu_name'          => 'Venues',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Venue',
            'edit_item'          => 'Edit Venue',
            'new_item'           => 'New Venue',
            'view_item'          => 'View Venue',
            'search_items'       => 'Search Venues',
            'not_found'          => 'No venues found.',
            'not_found_in_trash' => 'No venues found in trash.',
            'all_items'          => 'All Venues',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            // Tucked under Events admin menu.
            'show_in_menu'        => 'edit.php?post_type=' . self::POST_TYPE_EVENT,
            'show_in_rest'        => true,
            'has_archive'         => false,
            'rewrite'             => array('slug' => 'venue', 'with_front' => false),
            'query_var'           => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => array('title', 'editor', 'thumbnail', 'custom-fields'),
        );

        $args = apply_filters('pta_venue_post_type_args', $args);

        register_post_type(self::POST_TYPE_VENUE, $args);
    }

    private function register_organizer_post_type() {
        $labels = array(
            'name'               => 'Organizers',
            'singular_name'      => 'Organizer',
            'menu_name'          => 'Organizers',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Organizer',
            'edit_item'          => 'Edit Organizer',
            'new_item'           => 'New Organizer',
            'view_item'          => 'View Organizer',
            'search_items'       => 'Search Organizers',
            'not_found'          => 'No organizers found.',
            'not_found_in_trash' => 'No organizers found in trash.',
            'all_items'          => 'All Organizers',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=' . self::POST_TYPE_EVENT,
            'show_in_rest'        => true,
            'has_archive'         => false,
            'rewrite'             => array('slug' => 'organizer', 'with_front' => false),
            'query_var'           => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => array('title', 'editor', 'thumbnail', 'custom-fields'),
        );

        $args = apply_filters('pta_organizer_post_type_args', $args);

        register_post_type(self::POST_TYPE_ORGANIZER, $args);
    }

    /**
     * Register the shared category taxonomy.
     *
     * The taxonomy NAME is `pta_event_category` (internal) but the
     * public rewrite slug is `tribe_events_cat` so that existing URLs
     * like /tribe_events_cat/fundraisers/ keep working. The taxonomy
     * attaches to BOTH tribe_events (legacy) and pta_event (new) so
     * terms are shared during the migration window.
     */
    private function register_category_taxonomy() {
        $labels = array(
            'name'              => 'Event Categories',
            'singular_name'     => 'Event Category',
            'search_items'      => 'Search Event Categories',
            'all_items'         => 'All Event Categories',
            'parent_item'       => 'Parent Event Category',
            'parent_item_colon' => 'Parent Event Category:',
            'edit_item'         => 'Edit Event Category',
            'update_item'       => 'Update Event Category',
            'add_new_item'      => 'Add New Event Category',
            'new_item_name'     => 'New Event Category Name',
            'menu_name'         => 'Categories',
        );

        // Attach to pta_event always; ALSO attach to tribe_events when
        // TEC is active so terms are shared between the two post types.
        $object_types = array(self::POST_TYPE_EVENT);
        if (post_type_exists('tribe_events')) {
            $object_types[] = 'tribe_events';
        }

        $public_slug = apply_filters(
            'pta_event_category_slug',
            self::TAXONOMY_PUBLIC_SLUG
        );

        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array(
                'slug'         => $public_slug,
                'with_front'   => false,
                'hierarchical' => true,
            ),
        );

        $args = apply_filters('pta_event_category_taxonomy_args', $args);

        register_taxonomy(self::TAXONOMY_CATEGORY, $object_types, $args);
    }

    // =====================================================================
    // PHASE 4 ADMIN UI
    //
    // Minimal meta-box and list-table columns so editors can author
    // pta_event posts without depending on TEC's classic-editor UI.
    // We deliberately keep TEC's meta key names (_EventStartDate, etc.)
    // so anything saved here is also readable by tribe_events code paths
    // and survives a future TEC removal.
    // =====================================================================

    /**
     * Register the Event Details meta-box on the pta_event editor.
     */
    public function register_event_metabox() {
        add_meta_box(
            'pta_event_details',
            __('Event Details', 'azure-plugin'),
            array($this, 'render_event_metabox'),
            self::POST_TYPE_EVENT,
            'normal',
            'high'
        );
    }

    /**
     * Render the meta-box. Reads existing _Event* meta and shows simple
     * form fields. The save handler writes them back into the same keys.
     *
     * Datetimes use HTML5 datetime-local inputs; the format we send to
     * the input is `Y-m-d\TH:i` and we round-trip back to `Y-m-d H:i:s`
     * on save.
     *
     * @param \WP_Post $post Current event post.
     */
    public function render_event_metabox($post) {
        wp_nonce_field('pta_event_details_save', 'pta_event_details_nonce');

        $start    = get_post_meta($post->ID, '_EventStartDate', true);
        $end      = get_post_meta($post->ID, '_EventEndDate', true);
        $all_day  = get_post_meta($post->ID, '_EventAllDay', true) === 'yes';
        $venue    = get_post_meta($post->ID, '_EventVenue', true);
        $url      = get_post_meta($post->ID, '_EventURL', true);
        $cost     = get_post_meta($post->ID, '_EventCost', true);
        $hide_up  = get_post_meta($post->ID, '_EventHideFromUpcoming', true) === 'yes';

        // datetime-local wants 'Y-m-d\TH:i'; tolerate empty.
        $to_dtl = function ($s) {
            if (!$s) return '';
            $ts = strtotime($s);
            return $ts ? date('Y-m-d\TH:i', $ts) : '';
        };

        $outlook_id    = get_post_meta($post->ID, '_outlook_event_id', true);
        $tec_mirror_id = (int) get_post_meta($post->ID, '_tec_event_mirror_id', true);

        ?>
        <style>
            .pta-event-grid { display:grid; grid-template-columns:160px 1fr; gap:10px 16px; align-items:center; max-width:760px; }
            .pta-event-grid label { font-weight:600; }
            .pta-event-grid input[type="text"], .pta-event-grid input[type="datetime-local"], .pta-event-grid input[type="url"] { width:100%; max-width:380px; }
            .pta-event-mirror-info { margin-top:14px; padding:10px 12px; background:#f6f7f7; border-left:3px solid #2271b1; font-size:12px; color:#50575e; }
            .pta-event-mirror-info code { background:rgba(0,0,0,0.05); padding:1px 5px; }
        </style>
        <div class="pta-event-grid">
            <label for="pta_event_start"><?php esc_html_e('Start date/time', 'azure-plugin'); ?></label>
            <input type="datetime-local" id="pta_event_start" name="pta_event_start" value="<?php echo esc_attr($to_dtl($start)); ?>">

            <label for="pta_event_end"><?php esc_html_e('End date/time', 'azure-plugin'); ?></label>
            <input type="datetime-local" id="pta_event_end" name="pta_event_end" value="<?php echo esc_attr($to_dtl($end)); ?>">

            <label for="pta_event_all_day"><?php esc_html_e('All day', 'azure-plugin'); ?></label>
            <label><input type="checkbox" id="pta_event_all_day" name="pta_event_all_day" value="yes" <?php checked($all_day); ?>> <?php esc_html_e('This is an all-day event', 'azure-plugin'); ?></label>

            <label for="pta_event_venue"><?php esc_html_e('Venue', 'azure-plugin'); ?></label>
            <input type="text" id="pta_event_venue" name="pta_event_venue" value="<?php echo esc_attr($venue); ?>" placeholder="<?php esc_attr_e('e.g. School Campus', 'azure-plugin'); ?>">

            <label for="pta_event_url"><?php esc_html_e('Event URL', 'azure-plugin'); ?></label>
            <input type="url" id="pta_event_url" name="pta_event_url" value="<?php echo esc_attr($url); ?>" placeholder="https://...">

            <label for="pta_event_cost"><?php esc_html_e('Cost', 'azure-plugin'); ?></label>
            <input type="text" id="pta_event_cost" name="pta_event_cost" value="<?php echo esc_attr($cost); ?>" placeholder="<?php esc_attr_e('Free, $5, etc.', 'azure-plugin'); ?>">

            <label for="pta_event_hide_upcoming"><?php esc_html_e('Hide from upcoming', 'azure-plugin'); ?></label>
            <label><input type="checkbox" id="pta_event_hide_upcoming" name="pta_event_hide_upcoming" value="yes" <?php checked($hide_up); ?>> <?php esc_html_e('Don\'t show in upcoming-events lists/widgets', 'azure-plugin'); ?></label>
        </div>

        <?php if ($outlook_id || $tec_mirror_id): ?>
            <div class="pta-event-mirror-info">
                <strong><?php esc_html_e('Sync info (read-only):', 'azure-plugin'); ?></strong><br>
                <?php if ($outlook_id): ?>
                    <?php esc_html_e('Outlook event ID:', 'azure-plugin'); ?> <code><?php echo esc_html(substr($outlook_id, 0, 32) . (strlen($outlook_id) > 32 ? '…' : '')); ?></code><br>
                <?php endif; ?>
                <?php if ($tec_mirror_id): ?>
                    <?php
                    printf(
                        /* translators: %d is the tribe_events post ID */
                        esc_html__('Mirrored from tribe_events #%d', 'azure-plugin'),
                        $tec_mirror_id
                    );
                    ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Persist the meta-box fields back to postmeta. Handles the standard
     * autosave/permission guards.
     *
     * Note: we don't fight the dual-write mirror here. If this post is
     * a Phase 2 mirror of a tribe_events post, edits made via this UI
     * land on pta_event but the next Outlook sync will overwrite them
     * (Outlook is the system of record). For locally-authored events
     * with no _outlook_event_id, edits stick.
     *
     * @param int      $post_id The pta_event ID being saved.
     * @param \WP_Post $post    The post object.
     */
    public function save_event_metabox($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (wp_is_post_revision($post_id)) { return; }
        if (!isset($_POST['pta_event_details_nonce']) || !wp_verify_nonce($_POST['pta_event_details_nonce'], 'pta_event_details_save')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

        // datetime-local sends 'Y-m-d\TH:i'. Convert to TEC's stored
        // format 'Y-m-d H:i:s'. Empty values clear the meta (WP's
        // update_post_meta with '' just stores empty; we delete instead
        // to keep the row clean).
        $parse_dtl = function ($v) {
            if ($v === '' || $v === null) return '';
            $ts = strtotime((string) $v);
            return $ts ? date('Y-m-d H:i:s', $ts) : '';
        };

        $fields = array(
            '_EventStartDate'        => $parse_dtl(isset($_POST['pta_event_start']) ? $_POST['pta_event_start'] : ''),
            '_EventEndDate'          => $parse_dtl(isset($_POST['pta_event_end']) ? $_POST['pta_event_end'] : ''),
            '_EventVenue'            => isset($_POST['pta_event_venue']) ? sanitize_text_field($_POST['pta_event_venue']) : '',
            '_EventURL'              => isset($_POST['pta_event_url']) ? esc_url_raw($_POST['pta_event_url']) : '',
            '_EventCost'             => isset($_POST['pta_event_cost']) ? sanitize_text_field($_POST['pta_event_cost']) : '',
            '_EventAllDay'           => !empty($_POST['pta_event_all_day']) ? 'yes' : 'no',
            '_EventHideFromUpcoming' => !empty($_POST['pta_event_hide_upcoming']) ? 'yes' : 'no',
        );

        foreach ($fields as $key => $value) {
            if ($value === '' || $value === 'no') {
                // Clean up: don't keep empty-string or default-no rows.
                if ($value === 'no' && in_array($key, array('_EventAllDay', '_EventHideFromUpcoming'), true)) {
                    delete_post_meta($post_id, $key);
                } elseif ($value === '') {
                    delete_post_meta($post_id, $key);
                } else {
                    update_post_meta($post_id, $key, $value);
                }
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }

        // Update derived UTC versions when the start/end dates were saved.
        // _EventStartDateUTC and _EventEndDateUTC are how TEC indexes
        // events for queries; keeping them in sync means our pta_event
        // posts stay queryable through any TEC-style lookup.
        $tz = get_option('timezone_string', 'UTC');
        if (empty($tz)) { $tz = 'UTC'; }
        foreach (array(
            '_EventStartDate' => '_EventStartDateUTC',
            '_EventEndDate'   => '_EventEndDateUTC',
        ) as $local_key => $utc_key) {
            $local = $fields[$local_key];
            if ($local) {
                try {
                    $dt = new \DateTime($local, new \DateTimeZone($tz));
                    $dt->setTimezone(new \DateTimeZone('UTC'));
                    update_post_meta($post_id, $utc_key, $dt->format('Y-m-d H:i:s'));
                } catch (\Exception $e) {
                    update_post_meta($post_id, $utc_key, $local);
                }
            } else {
                delete_post_meta($post_id, $utc_key);
            }
        }

        // _EventDuration in seconds (some TEC views look this up).
        $start = $fields['_EventStartDate'];
        $end   = $fields['_EventEndDate'];
        if ($start && $end) {
            $duration = strtotime($end) - strtotime($start);
            update_post_meta($post_id, '_EventDuration', max(0, $duration));
        }
    }

    /**
     * Replace the default columns on the pta_event admin list with
     * Title / Start / Venue / Category / Source. Date is moved to the
     * end so admins see event-relevant info first.
     *
     * @param array $columns Default WP columns.
     * @return array
     */
    public function event_admin_columns($columns) {
        $new = array();
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['pta_event_start']    = __('Starts', 'azure-plugin');
                $new['pta_event_venue']    = __('Venue', 'azure-plugin');
                $new['pta_event_category'] = __('Category', 'azure-plugin');
                $new['pta_event_source']   = __('Source', 'azure-plugin');
            }
        }
        return $new;
    }

    /**
     * Render values for our custom columns.
     *
     * @param string $column_name Column slug.
     * @param int    $post_id     Current post ID.
     */
    public function event_admin_column_value($column_name, $post_id) {
        switch ($column_name) {
            case 'pta_event_start':
                $start = get_post_meta($post_id, '_EventStartDate', true);
                if (!$start) { echo '<em>—</em>'; break; }
                $ts = strtotime($start);
                if (!$ts) { echo esc_html($start); break; }
                $all_day = get_post_meta($post_id, '_EventAllDay', true) === 'yes';
                if ($all_day) {
                    echo esc_html(date_i18n(get_option('date_format', 'M j, Y'), $ts));
                    echo ' <span style="color:#888;">(' . esc_html__('all day', 'azure-plugin') . ')</span>';
                } else {
                    echo esc_html(date_i18n(get_option('date_format', 'M j, Y'), $ts));
                    echo ' <span style="color:#888;">' . esc_html(date_i18n(get_option('time_format', 'g:i a'), $ts)) . '</span>';
                }
                break;

            case 'pta_event_venue':
                $venue = get_post_meta($post_id, '_EventVenue', true);
                echo $venue ? esc_html($venue) : '<em>—</em>';
                break;

            case 'pta_event_category':
                $terms = get_the_terms($post_id, self::TAXONOMY_CATEGORY);
                if (!$terms || is_wp_error($terms)) { echo '<em>—</em>'; break; }
                $names = array_map(function ($t) { return esc_html($t->name); }, $terms);
                echo implode(', ', $names);
                break;

            case 'pta_event_source':
                $outlook_id    = get_post_meta($post_id, '_outlook_event_id', true);
                $tec_mirror_id = (int) get_post_meta($post_id, '_tec_event_mirror_id', true);
                if ($outlook_id) {
                    echo '<span title="' . esc_attr__('Synced from Outlook', 'azure-plugin') . '" style="color:#2271b1;">📅 Outlook</span>';
                } elseif ($tec_mirror_id) {
                    echo '<span title="' . esc_attr(sprintf(__('Mirrored from tribe_events #%d', 'azure-plugin'), $tec_mirror_id)) . '" style="color:#9c27b0;">🔁 Mirrored</span>';
                } else {
                    echo '<span style="color:#999;">' . esc_html__('Local', 'azure-plugin') . '</span>';
                }
                break;
        }
    }

    /**
     * Make our Starts column sortable by `_EventStartDate`.
     *
     * @param array $columns
     * @return array
     */
    public function event_admin_sortable_columns($columns) {
        $columns['pta_event_start'] = 'pta_event_start';
        return $columns;
    }

    /**
     * Honor the Starts-column sort by routing the query orderby to the
     * _EventStartDate meta_key. Only fires on the pta_event admin list.
     *
     * @param \WP_Query $query
     */
    public function event_admin_orderby($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== self::POST_TYPE_EVENT) {
            return;
        }
        if ($query->get('orderby') === 'pta_event_start') {
            $query->set('meta_key', '_EventStartDate');
            $query->set('orderby', 'meta_value');
        }
    }

    // =====================================================================
    // PHASE 5 FRONTEND SINGLE-EVENT VIEW
    //
    // The /event/<slug>/ URL is registered as a rewrite rule by BOTH
    // tribe_events (TEC) and pta_event (us) — they collide. WP keeps
    // both rules in the rewrite array, but on a single-post lookup it
    // resolves to whichever post type registered its rewrite first,
    // which is tribe_events because TEC hooks `init` earlier than us.
    //
    // To honour the `pta_calendar_data_source = pta` flag, we:
    //   1. swap the main query's post_type from tribe_events to
    //      pta_event in `pre_get_posts` (preserving the slug match)
    //   2. load our own template via `single_template` so the page
    //      renders without depending on TEC's templates at all
    //
    // When data_source != 'pta' we don't touch anything; TEC stays in
    // charge of /event/<slug>/.
    // =====================================================================

    /**
     * Re-route singular AND archive event URLs to pta_event when
     * data_source=pta.
     *
     * Both `/event/<slug>/` and `/events/` get registered against TWO
     * post types — tribe_events (TEC) and pta_event (us). TEC's
     * rewrite rules win the lookup because TEC hooks `init` earlier
     * than we do. This filter catches the resulting `tribe_events`
     * query and rewrites it to `pta_event`.
     *
     * @param \WP_Query $query
     */
    public function maybe_swap_to_pta_event_on_single($query) {
        if (!$query->is_main_query()) { return; }
        if ($query->is_admin) { return; }
        if ($query->is_feed()) { return; }
        if (self::get_data_source() !== 'pta') { return; }

        $pt = $query->get('post_type');
        // The TEC rewrite-rule resolution always sets post_type to
        // tribe_events on /event/<slug>/ and on /events/ archive.
        if ($pt !== 'tribe_events') { return; }

        $is_single  = (string) $query->get('name') !== '' || (int) $query->get('p') !== 0;
        $is_archive = !empty($query->is_post_type_archive)
            || ((string) $query->get('post_type') === 'tribe_events' && empty($query->get('name')) && empty($query->get('p')));

        if (!$is_single && !$is_archive) {
            return;
        }

        $query->set('post_type', self::POST_TYPE_EVENT);
        $query->set('tribe_events', null);

        if ($is_archive) {
            // Default the archive query to upcoming events ordered by
            // _EventStartDate ascending, with a sensible page size.
            // The archive template can override this via its own
            // WP_Query if it wants a different cut.
            $query->set('orderby', 'meta_value');
            $query->set('meta_key', '_EventStartDate');
            $query->set('order', 'ASC');
            $query->set('posts_per_page', 50);

            // Restrict to events from the START of today onward unless
            // the URL asks for a specific month (?pta_month=YYYY-MM).
            $month = isset($_GET['pta_month']) ? (string) $_GET['pta_month'] : '';
            if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                $start = $month . '-01 00:00:00';
                $end   = date('Y-m-d 23:59:59', strtotime($month . '-01 +1 month -1 day'));
            } else {
                $start = date('Y-m-d 00:00:00', current_time('timestamp'));
                $end   = '2099-12-31 23:59:59';
            }
            $query->set('meta_query', array(
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $start,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $end,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ),
            ));
        }
    }

    /**
     * Load our single-event OR archive template when WP is about to
     * render a pta_event request.
     *
     * @param string $template Theme-resolved template path.
     * @return string Possibly-overridden template path.
     */
    public function maybe_load_single_template($template) {
        if (is_singular(self::POST_TYPE_EVENT)) {
            $custom = AZURE_PLUGIN_PATH . 'templates/single-pta_event.php';
            if (file_exists($custom)) {
                return $custom;
            }
        }
        if (is_post_type_archive(self::POST_TYPE_EVENT)) {
            $custom = AZURE_PLUGIN_PATH . 'templates/archive-pta_event.php';
            if (file_exists($custom)) {
                return $custom;
            }
        }
        return $template;
    }

    /**
     * Enqueue the single-event / archive stylesheet only on the
     * relevant pages.
     */
    public function maybe_enqueue_single_event_styles() {
        // Shared Join-meeting button + card-grid styles. Loaded on
        // both single + archive so the helper-rendered button looks
        // right in either context.
        if (is_singular(self::POST_TYPE_EVENT) || is_post_type_archive(self::POST_TYPE_EVENT)) {
            wp_enqueue_style(
                'pta-event-shared',
                AZURE_PLUGIN_URL . 'css/pta-event-shared.css',
                array(),
                AZURE_PLUGIN_VERSION
            );
        }
        if (is_singular(self::POST_TYPE_EVENT)) {
            wp_enqueue_style(
                'pta-event-single',
                AZURE_PLUGIN_URL . 'css/pta-event-single.css',
                array('pta-event-shared'),
                AZURE_PLUGIN_VERSION
            );
        }
        if (is_post_type_archive(self::POST_TYPE_EVENT)) {
            wp_enqueue_style(
                'pta-event-archive',
                AZURE_PLUGIN_URL . 'css/pta-event-archive.css',
                array('pta-event-shared'),
                AZURE_PLUGIN_VERSION
            );
        }
    }

    /**
     * Build a Google Maps embed URL from a free-form address. Public
     * (no API key) embed via the `maps.google.com/maps?q=...&output=embed`
     * pattern. Caller is responsible for sizing the iframe.
     *
     * @param string $address
     * @return string URL safe to drop into an iframe src.
     */
    public static function google_map_embed_url($address) {
        if (empty($address)) { return ''; }
        return 'https://maps.google.com/maps?q=' . rawurlencode($address) . '&output=embed';
    }

    /**
     * Compose a "venue display block" from a tribe_venue post (or, in
     * future phases, a pta_venue post) — name, street, city/state/zip,
     * Google Map link, and a map embed URL.
     *
     * Returns false if the venue can't be resolved.
     *
     * @param int $venue_id
     * @return array|false
     */
    public static function get_venue_block($venue_id) {
        $venue_id = (int) $venue_id;
        if ($venue_id <= 0) { return false; }
        $post = get_post($venue_id);
        if (!$post) { return false; }

        $address = (string) get_post_meta($venue_id, '_VenueAddress', true);
        $city    = (string) get_post_meta($venue_id, '_VenueCity', true);
        $state   = (string) get_post_meta($venue_id, '_VenueStateProvince', true);
        if ($state === '') {
            $state = (string) get_post_meta($venue_id, '_VenueState', true);
        }
        $zip     = (string) get_post_meta($venue_id, '_VenueZip', true);
        $country = (string) get_post_meta($venue_id, '_VenueCountry', true);

        $line2_parts = array();
        if ($city)  { $line2_parts[] = $city . ($state ? ',' : ''); }
        if ($state) { $line2_parts[] = $state; }
        if ($zip)   { $line2_parts[] = $zip; }
        $line2 = trim(implode(' ', $line2_parts));

        $map_query_parts = array_filter(array($post->post_title, $address, $line2, $country));
        $map_query = trim(implode(', ', $map_query_parts));

        return array(
            'id'              => $venue_id,
            'name'            => $post->post_title,
            'permalink'       => get_permalink($venue_id),
            'address'         => $address,
            'city_state_zip'  => $line2,
            'country'         => $country,
            'map_query'       => $map_query,
            'map_embed_url'   => self::google_map_embed_url($map_query),
            'map_link_url'    => $map_query
                ? 'https://maps.google.com/maps?q=' . rawurlencode($map_query)
                : '',
        );
    }

    /**
     * Format an event's date/time as TEC does on the single-event view:
     * "May 14 @ 6:00 PM - 8:00 PM" or "May 14" (all-day).
     *
     * @param int $post_id
     * @return string
     */
    public static function format_event_datetime_heading($post_id) {
        $start   = get_post_meta($post_id, '_EventStartDate', true);
        $end     = get_post_meta($post_id, '_EventEndDate', true);
        $all_day = get_post_meta($post_id, '_EventAllDay', true) === 'yes';
        if (!$start) { return ''; }

        $date_fmt = get_option('date_format', 'F j');
        $time_fmt = get_option('time_format', 'g:i A');

        $start_ts = strtotime($start);
        $end_ts   = $end ? strtotime($end) : $start_ts;

        if ($all_day) {
            // Same-day all-day -> "May 14"; multi-day -> "May 14 - May 16"
            $sd = date_i18n($date_fmt, $start_ts);
            if ($end_ts && date('Ymd', $end_ts) !== date('Ymd', $start_ts)) {
                return $sd . ' - ' . date_i18n($date_fmt, $end_ts);
            }
            return $sd;
        }

        $sd = date_i18n($date_fmt, $start_ts);
        $st = date_i18n($time_fmt, $start_ts);
        $et = date_i18n($time_fmt, $end_ts);

        if ($end_ts && date('Ymd', $end_ts) !== date('Ymd', $start_ts)) {
            // Multi-day with times: "May 14 @ 6:00 PM - May 15 @ 8:00 PM"
            return $sd . ' @ ' . $st . ' - ' . date_i18n($date_fmt, $end_ts) . ' @ ' . $et;
        }
        return $sd . ' @ ' . $st . ' - ' . $et;
    }

    /**
     * Build a Google Calendar template URL for an event so visitors
     * can drop it into their personal calendar with one click.
     *
     * @param int $post_id
     * @return string
     */
    public static function google_calendar_url($post_id) {
        $title = get_the_title($post_id);
        $start = get_post_meta($post_id, '_EventStartDate', true);
        $end   = get_post_meta($post_id, '_EventEndDate', true);
        $venue_name = (string) get_post_meta($post_id, '_EventVenue', true);
        if (!$venue_name) {
            $vid = (int) get_post_meta($post_id, '_EventVenueID', true);
            if ($vid > 0) {
                $venue_block = self::get_venue_block($vid);
                $venue_name  = $venue_block ? $venue_block['name'] : '';
            }
        }
        $description = wp_strip_all_tags(get_the_excerpt($post_id));

        $tz = get_option('timezone_string') ?: 'UTC';
        $fmt_for_gcal = function ($s) use ($tz) {
            if (!$s) return '';
            try {
                $dt = new \DateTime($s, new \DateTimeZone($tz));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                return $dt->format('Ymd\THis\Z');
            } catch (\Exception $e) {
                return '';
            }
        };
        $start_g = $fmt_for_gcal($start);
        $end_g   = $fmt_for_gcal($end);

        $params = array(
            'action'   => 'TEMPLATE',
            'text'     => $title,
            'dates'    => $start_g . '/' . $end_g,
            'details'  => $description,
            'location' => $venue_name,
        );
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * Look up sibling events in the same category, excluding the
     * current post. Used by the Related Events carousel on the single
     * event view.
     *
     * @param int $post_id
     * @param int $limit
     * @return \WP_Post[]
     */
    public static function get_related_events($post_id, $limit = 3) {
        $terms = wp_get_object_terms($post_id, self::TAXONOMY_CATEGORY, array('fields' => 'ids'));
        if (is_wp_error($terms) || empty($terms)) {
            // Fall back to upcoming events generally if the post has no
            // category — better than an empty carousel.
            $args = array(
                'post_type'      => self::POST_TYPE_EVENT,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'post__not_in'   => array($post_id),
                'meta_key'       => '_EventStartDate',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => '_EventStartDate',
                        'value'   => current_time('Y-m-d H:i:s'),
                        'compare' => '>=',
                        'type'    => 'DATETIME',
                    ),
                ),
            );
        } else {
            $args = array(
                'post_type'      => self::POST_TYPE_EVENT,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'post__not_in'   => array($post_id),
                'meta_key'       => '_EventStartDate',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'tax_query'      => array(array(
                    'taxonomy' => self::TAXONOMY_CATEGORY,
                    'field'    => 'term_id',
                    'terms'    => $terms,
                )),
                'meta_query'     => array(
                    array(
                        'key'     => '_EventStartDate',
                        'value'   => current_time('Y-m-d H:i:s'),
                        'compare' => '>=',
                        'type'    => 'DATETIME',
                    ),
                ),
            );
        }
        $q = new \WP_Query($args);
        return (array) $q->posts;
    }

    /**
     * If a request comes in with ?pta_ical=<id>, stream a single
     * .ics file for that pta_event (or its TEC mirror) so visitors
     * can drop the event into iCal / Outlook / Apple Calendar.
     *
     * If `?pta_ical_feed=1` is present we instead emit a full-calendar
     * VCALENDAR containing every published pta_event in a wide window
     * — that's what calendar apps subscribe to via webcal://.
     *
     * Hooked very late on `init` to short-circuit before any theme
     * output starts.
     */
    public function maybe_serve_ics() {
        if (!empty($_GET['pta_ical_feed'])) {
            $this->serve_ics_feed();
            return;
        }
        if (empty($_GET['pta_ical'])) { return; }
        $id = (int) $_GET['pta_ical'];
        if ($id <= 0) { return; }

        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish') {
            status_header(404);
            exit;
        }
        if (!in_array($post->post_type, array(self::POST_TYPE_EVENT, 'tribe_events'), true)) {
            status_header(404);
            exit;
        }

        $title = $post->post_title;
        $start = get_post_meta($id, '_EventStartDate', true);
        $end   = get_post_meta($id, '_EventEndDate', true);
        $tz    = get_option('timezone_string') ?: 'UTC';
        $venue = (string) get_post_meta($id, '_EventVenue', true);
        if (!$venue) {
            $vid = (int) get_post_meta($id, '_EventVenueID', true);
            if ($vid > 0) {
                $vb = self::get_venue_block($vid);
                if ($vb) {
                    $venue = trim($vb['name'] . ', ' . $vb['address'] . ', ' . $vb['city_state_zip']);
                }
            }
        }
        $description = wp_strip_all_tags(get_the_excerpt($post));
        $url = get_permalink($post);

        $fmt = function ($s) use ($tz) {
            if (!$s) return '';
            try {
                $dt = new \DateTime($s, new \DateTimeZone($tz));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                return $dt->format('Ymd\THis\Z');
            } catch (\Exception $e) {
                return '';
            }
        };
        $ics_start = $fmt($start);
        $ics_end   = $fmt($end);
        if (!$ics_start) {
            status_header(400);
            exit;
        }

        $ics_escape = function ($s) {
            return preg_replace(
                array('/\\\\/','/,/','/;/',"/\r\n|\r|\n/"),
                array('\\\\', '\\,', '\\;', '\\n'),
                (string) $s
            );
        };

        $uid = 'pta-event-' . $id . '@' . parse_url(home_url(), PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');

        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//PTA Tools//Single Event//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $ics_start,
            'DTEND:' . ($ics_end ?: $ics_start),
            'SUMMARY:' . $ics_escape($title),
            'DESCRIPTION:' . $ics_escape($description),
            'LOCATION:' . $ics_escape($venue),
            'URL:' . $url,
            'END:VEVENT',
            'END:VCALENDAR',
        );

        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_title($title) . '.ics"');
        echo implode("\r\n", $lines);
        exit;
    }

    /**
     * Stream the full calendar feed (all published pta_event posts in
     * a wide window) as an .ics file. This is what `webcal://` apps
     * subscribe to — they re-fetch the URL on a schedule and any
     * additions, edits, or deletions in pta_event flow through.
     *
     * Uses a 5-minute transient to avoid rebuilding the file on every
     * subscriber poll. The ETag/Last-Modified headers let well-behaved
     * clients (Apple Calendar, Outlook, Google) skip the body when
     * nothing changed.
     */
    private function serve_ics_feed() {
        if (!post_type_exists(self::POST_TYPE_EVENT)) {
            status_header(404);
            exit;
        }

        $cache_key = 'pta_ics_feed_v2';
        $cached    = get_transient($cache_key);

        if (!is_array($cached) || empty($cached['body']) || empty($cached['etag'])) {
            try {
                $cached = $this->build_ics_feed();
                set_transient($cache_key, $cached, 5 * MINUTE_IN_SECONDS);
            } catch (\Throwable $e) {
                // Surface the underlying error to the WordPress
                // debug log so we can triage feed regressions without
                // needing to flip WP_DEBUG_DISPLAY on.
                if (function_exists('error_log')) {
                    error_log('[pta_ics_feed] build_ics_feed failed: '
                        . $e->getMessage() . ' @ '
                        . $e->getFile() . ':' . $e->getLine());
                }
                status_header(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "ICS feed build failed.\n";
                echo $e->getMessage() . "\n@ " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
                exit;
            }
        }

        // Conditional GET — let subscribers skip the body when the
        // feed hasn't changed since their last poll.
        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';
        if ($client_etag !== '' && $client_etag === $cached['etag']) {
            status_header(304);
            header('ETag: "' . $cached['etag'] . '"');
            header('Cache-Control: public, max-age=300');
            exit;
        }

        header('Content-Type: text/calendar; charset=utf-8');
        $ics_filename = sanitize_title(get_bloginfo('name')) . '-events.ics';
        if ($ics_filename === '-events.ics') { $ics_filename = 'events.ics'; }
        header('Content-Disposition: inline; filename="' . $ics_filename . '"');
        header('ETag: "' . $cached['etag'] . '"');
        header('Cache-Control: public, max-age=300');
        echo $cached['body'];
        exit;
    }

    /**
     * Build a full-calendar VCALENDAR body from pta_event posts.
     *
     * Date handling:
     *   - Timed events  → DTSTART/DTEND as floating UTC (Ymd\THis\Z)
     *   - All-day events → DTSTART/DTEND as VALUE=DATE (YYYYMMDD).
     *     iCal's DTEND is exclusive for all-day too, so we bump
     *     end-of-day timestamps by one day to match calendar-app
     *     expectations.
     *
     * @return array{ body:string, etag:string, count:int }
     */
    private function build_ics_feed() {
        $back_days = 180;
        $fwd_days  = 540;
        $start_w   = date('Y-m-d 00:00:00', strtotime("-{$back_days} days"));
        $end_w     = date('Y-m-d 23:59:59', strtotime("+{$fwd_days} days"));

        $query = new \WP_Query(array(
            'post_type'              => self::POST_TYPE_EVENT,
            'post_status'            => array('publish'),
            'posts_per_page'         => 1000,
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'meta_query'             => array(
                array(
                    'key'     => '_EventStartDate',
                    'value'   => array($start_w, $end_w),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
            'orderby'  => 'meta_value',
            'meta_key' => '_EventStartDate',
            'order'    => 'ASC',
        ));

        $tz_string = get_option('timezone_string') ?: 'UTC';
        $host      = parse_url(home_url(), PHP_URL_HOST);
        $now_utc   = gmdate('Ymd\THis\Z');

        $escape = function ($s) {
            return preg_replace(
                array('/\\\\/', '/,/', '/;/', "/\r\n|\r|\n/"),
                array('\\\\', '\\,', '\\;', '\\n'),
                (string) $s
            );
        };

        $fmt_utc = function ($local) use ($tz_string) {
            if (!$local) { return ''; }
            try {
                $dt = new \DateTime($local, new \DateTimeZone($tz_string));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                return $dt->format('Ymd\THis\Z');
            } catch (\Exception $e) {
                return '';
            }
        };

        $blog_name = wp_strip_all_tags(get_bloginfo('name'));

        $lines = array();
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//PTA Tools//Calendar Feed//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $escape($blog_name . ' Events');
        $lines[] = 'X-WR-TIMEZONE:' . $escape($tz_string);
        $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
        $lines[] = 'X-PUBLISHED-TTL:PT1H';

        $count = 0;
        if (!empty($query->posts)) {
            foreach ($query->posts as $post) {
                $start  = (string) get_post_meta($post->ID, '_EventStartDate', true);
                $end    = (string) get_post_meta($post->ID, '_EventEndDate',   true);
                $allday = strtolower((string) get_post_meta($post->ID, '_EventAllDay', true));
                $is_all = in_array($allday, array('yes', '1', 'true'), true);

                if ($is_all) {
                    $sd = substr($start, 0, 10);
                    if ($sd === '') { continue; }
                    $ed_date = substr($end, 0, 10);
                    $ed_time = substr($end, 11);
                    if ($ed_date === '') {
                        $ed_emit = date('Ymd', strtotime($sd . ' +1 day'));
                    } elseif ($ed_time !== '00:00:00' && $ed_time !== '') {
                        $ed_emit = date('Ymd', strtotime($ed_date . ' +1 day'));
                    } else {
                        $ed_emit = date('Ymd', strtotime($ed_date));
                    }
                    $sd_emit = date('Ymd', strtotime($sd));
                    $dtstart_line = 'DTSTART;VALUE=DATE:' . $sd_emit;
                    $dtend_line   = 'DTEND;VALUE=DATE:'   . $ed_emit;
                } else {
                    $sd_emit = $fmt_utc($start);
                    $ed_emit = $end ? $fmt_utc($end) : $sd_emit;
                    if (!$sd_emit) { continue; }
                    $dtstart_line = 'DTSTART:' . $sd_emit;
                    $dtend_line   = 'DTEND:'   . ($ed_emit ?: $sd_emit);
                }

                $venue = (string) get_post_meta($post->ID, '_EventVenue', true);
                if (!$venue) {
                    $vid = (int) get_post_meta($post->ID, '_EventVenueID', true);
                    if ($vid > 0) {
                        $vb = self::get_venue_block($vid);
                        if ($vb) {
                            $venue = trim($vb['name'] . ', ' . $vb['address'] . ', ' . $vb['city_state_zip']);
                        }
                    }
                }

                $cats     = wp_get_post_terms($post->ID, self::TAXONOMY_CATEGORY, array('fields' => 'names'));
                $cats_str = is_array($cats) ? implode(',', array_map($escape, $cats)) : '';

                $description = wp_strip_all_tags(get_the_excerpt($post));
                $url         = get_permalink($post);
                $modified    = strtotime($post->post_modified_gmt . ' UTC') ?: time();
                $uid         = 'pta-event-' . $post->ID . '@' . $host;

                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:' . $uid;
                $lines[] = 'DTSTAMP:' . $now_utc;
                $lines[] = 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', $modified);
                $lines[] = $dtstart_line;
                $lines[] = $dtend_line;
                $lines[] = 'SUMMARY:' . $escape($post->post_title);
                if ($description !== '') {
                    $lines[] = 'DESCRIPTION:' . $escape($description);
                }
                if ($venue !== '') {
                    $lines[] = 'LOCATION:' . $escape($venue);
                }
                if ($cats_str !== '') {
                    $lines[] = 'CATEGORIES:' . $cats_str;
                }
                $lines[] = 'URL:' . $url;
                $lines[] = 'END:VEVENT';
                $count++;
            }
        }
        $lines[] = 'END:VCALENDAR';

        $body = implode("\r\n", $lines);
        return array(
            'body'  => $body,
            'etag'  => substr(md5($body), 0, 16),
            'count' => $count,
        );
    }

    /**
     * Public URLs (https + webcal) for the full calendar feed.
     * Returns array{ ics:string, webcal:string } or null when the
     * pta_event CPT isn't active.
     */
    public static function get_feed_urls() {
        if (!post_type_exists(self::POST_TYPE_EVENT)) {
            return null;
        }
        $base = add_query_arg(array('pta_ical_feed' => '1'), home_url('/'));
        $webcal = preg_replace('#^https?://#i', 'webcal://', $base);
        return array(
            'ics'    => $base,
            'webcal' => $webcal,
        );
    }

    /**
     * Find a Teams / Zoom / Google Meet / Webex / etc. URL on an event.
     *
     * The Outlook -> pta_event sync writes the event body (with the
     * "Join Microsoft Teams Meeting" link) into post_content, but does
     * not pull `onlineMeeting.joinUrl` into its own meta key — so we
     * have to scrape it back out at render time.
     *
     * Lookup order (first match wins):
     *   1) Dedicated meta key `_pta_online_meeting_url` (set manually
     *      by editors, or by a future sync-engine improvement that
     *      requests `onlineMeeting/joinUrl` from Graph).
     *   2) The post body / excerpt — both <a href> and bare-text URLs.
     *   3) The `_EventURL` meta (TEC's "Event Website").
     *   4) The venue display name and venue post body (Outlook sometimes
     *      stores the meeting URL as the location string).
     *
     * Only known meeting-provider hostnames are returned. Generic URLs
     * land elsewhere on the page (Website, body content) and shouldn't
     * be promoted to a "Join meeting" button.
     *
     * @param int $post_id pta_event (or tribe_events) post ID.
     * @return string URL, or '' if no meeting link found.
     */
    public static function extract_online_meeting_url($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }

        // 1) Manually-curated meta — the source of truth if present.
        $manual = (string) get_post_meta($post_id, '_pta_online_meeting_url', true);
        if ($manual !== '') {
            $manual = esc_url_raw($manual);
            if ($manual) { return $manual; }
        }

        // Collect every plausible URL source from the post + venue and
        // run it through the meeting-host filter. Bare-text URLs (from
        // Outlook's body) are picked up by the second regex; <a href=>
        // links survive the body strip via the first regex.
        $candidates = array(
            (string) get_post_field('post_content', $post_id),
            (string) get_post_field('post_excerpt', $post_id),
            (string) get_post_meta($post_id, '_EventURL',   true),
            (string) get_post_meta($post_id, '_EventVenue', true),
        );
        $venue_id = (int) get_post_meta($post_id, '_EventVenueID', true);
        if ($venue_id > 0) {
            $candidates[] = (string) get_the_title($venue_id);
            $candidates[] = (string) get_post_field('post_content', $venue_id);
            $candidates[] = (string) get_post_meta($venue_id, '_VenueURL',     true);
            $candidates[] = (string) get_post_meta($venue_id, '_VenueAddress', true);
        }

        $urls = array();
        foreach ($candidates as $text) {
            if ($text === '') { continue; }
            $decoded = html_entity_decode($text, ENT_QUOTES, get_bloginfo('charset'));

            // <a href="..."> links survive even when the rest of the
            // HTML is stripped by wp_strip_all_tags() upstream.
            if (preg_match_all("~href=[\"'](https?://[^\"']+)[\"']~i", $decoded, $m)) {
                foreach ($m[1] as $u) { $urls[] = $u; }
            }

            // Bare-text URLs (Outlook body pastes the join link inline).
            $plain = wp_strip_all_tags($decoded);
            if (preg_match_all('~https?://[^\s<>"\']+~i', $plain, $m2)) {
                foreach ($m2[0] as $u) { $urls[] = $u; }
            }
        }

        if (empty($urls)) {
            return '';
        }

        // Normalise (trim trailing punctuation, dedupe) and pick the
        // first known meeting provider.
        $seen = array();
        foreach ($urls as $u) {
            $u = rtrim($u, '.,;:!?)]}');
            $u = esc_url_raw($u);
            if (!$u || isset($seen[$u])) { continue; }
            $seen[$u] = true;

            $host = strtolower((string) wp_parse_url($u, PHP_URL_HOST));
            if ($host === '') { continue; }

            $needles = array(
                'teams.microsoft.com', 'teams.live.com',
                'zoom.us', 'zoomgov.com',
                'meet.google.com',
                'webex.com',
                'gotomeeting.com', 'goto.com',
                'bluejeans.com',
                'skype.com',
                'whereby.com',
            );
            foreach ($needles as $needle) {
                if (strpos($host, $needle) !== false) {
                    return $u;
                }
            }
        }

        return '';
    }

    /**
     * Provider label for a meeting URL ("Teams", "Zoom", etc.) for
     * display next to the Join button. Falls back to "Online meeting"
     * when the provider can't be identified.
     *
     * @param string $url
     * @return string
     */
    public static function online_meeting_provider_label($url) {
        if (!is_string($url) || $url === '') { return ''; }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') { return __('Online meeting', 'azure-plugin'); }

        $map = array(
            'teams.microsoft.com' => 'Microsoft Teams',
            'teams.live.com'      => 'Microsoft Teams',
            'zoom.us'             => 'Zoom',
            'zoomgov.com'         => 'Zoom (Gov)',
            'meet.google.com'     => 'Google Meet',
            'webex.com'           => 'Webex',
            'gotomeeting.com'     => 'GoToMeeting',
            'goto.com'            => 'GoTo Meeting',
            'bluejeans.com'       => 'BlueJeans',
            'skype.com'           => 'Skype',
            'whereby.com'         => 'Whereby',
        );
        foreach ($map as $needle => $label) {
            if (strpos($host, $needle) !== false) {
                return $label;
            }
        }
        return __('Online meeting', 'azure-plugin');
    }

    /**
     * Render the standard "Join meeting" button used across single-event,
     * archive list, and shortcode renderers. Returns '' when the event
     * has no detectable meeting URL so callers can drop it in
     * unconditionally without wrapping themselves.
     *
     * @param int    $post_id pta_event ID.
     * @param string $variant 'inline' (default, small inline button) or
     *                        'block' (full-width card button).
     * @return string HTML or empty string.
     */
    public static function render_join_meeting_button($post_id, $variant = 'inline') {
        $url = self::extract_online_meeting_url($post_id);
        if ($url === '') {
            return '';
        }
        $label = self::online_meeting_provider_label($url);
        $cls   = $variant === 'block' ? 'pta-join-meeting pta-join-meeting--block'
                                      : 'pta-join-meeting';
        return sprintf(
            '<a class="%s" href="%s" target="_blank" rel="noopener noreferrer" data-provider="%s">'
                . '<span class="pta-join-meeting-icon" aria-hidden="true">&#128249;</span>'
                . '<span class="pta-join-meeting-label">%s</span>'
                . '<span class="pta-join-meeting-provider">%s</span>'
                . '</a>',
            esc_attr($cls),
            esc_url($url),
            esc_attr($label),
            esc_html__('Join meeting', 'azure-plugin'),
            esc_html($label)
        );
    }
}
