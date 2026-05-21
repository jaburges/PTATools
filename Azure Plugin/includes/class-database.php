<?php
/**
 * Azure Plugin Database Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // SSO Users table for user mapping
        $table_sso_users = $wpdb->prefix . 'azure_sso_users';
        $sql_sso_users = "CREATE TABLE $table_sso_users (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            wordpress_user_id bigint(20) UNSIGNED NOT NULL,
            azure_user_id varchar(255) NOT NULL,
            azure_email varchar(320) NOT NULL,
            azure_display_name varchar(255),
            last_login datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wordpress_user_id (wordpress_user_id),
            UNIQUE KEY azure_user_id (azure_user_id),
            KEY azure_email (azure_email)
        ) $charset_collate;";
        
        // Backup Jobs table
        $table_backup_jobs = $wpdb->prefix . 'azure_backup_jobs';
        $sql_backup_jobs = "CREATE TABLE $table_backup_jobs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            backup_id varchar(255) NOT NULL,
            job_name varchar(255) NOT NULL,
            backup_types longtext,
            entity_state longtext,
            status varchar(50) DEFAULT 'pending',
            progress int(11) DEFAULT 0,
            message longtext,
            file_path varchar(500),
            file_size bigint(20) DEFAULT 0,
            azure_blob_name varchar(500),
            started_at datetime,
            completed_at datetime,
            error_message longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY backup_id (backup_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Backup Files table
        $table_backup_files = $wpdb->prefix . 'azure_backup_files';
        $sql_backup_files = "CREATE TABLE $table_backup_files (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            job_id mediumint(9) NOT NULL,
            file_type varchar(50) NOT NULL,
            original_path varchar(1000) NOT NULL,
            backup_path varchar(1000) NOT NULL,
            file_size bigint(20) DEFAULT 0,
            checksum varchar(255),
            status varchar(50) DEFAULT 'pending',
            error_message longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY file_type (file_type),
            KEY status (status)
        ) $charset_collate;";
        
        // Calendar Embeds table
        $table_calendar_embeds = $wpdb->prefix . 'azure_calendar_embeds';
        $sql_calendar_embeds = "CREATE TABLE $table_calendar_embeds (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            embed_name varchar(255) NOT NULL,
            calendar_id varchar(255) NOT NULL,
            user_email varchar(320),
            view_type varchar(50) DEFAULT 'month',
            timezone varchar(100) DEFAULT 'UTC',
            color_theme varchar(50) DEFAULT 'blue',
            max_events int(11) DEFAULT 50,
            cache_duration int(11) DEFAULT 3600,
            shortcode_params longtext,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY calendar_id (calendar_id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Calendar Events Cache table
        $table_calendar_cache = $wpdb->prefix . 'azure_calendar_cache';
        $sql_calendar_cache = "CREATE TABLE $table_calendar_cache (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            calendar_id varchar(255) NOT NULL,
            event_data longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY calendar_id (calendar_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Email Queue table
        $table_email_queue = $wpdb->prefix . 'azure_email_queue';
        $sql_email_queue = "CREATE TABLE $table_email_queue (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            to_email varchar(320) NOT NULL,
            from_email varchar(320),
            subject varchar(500) NOT NULL,
            message longtext NOT NULL,
            headers longtext,
            attachments longtext,
            priority int(11) DEFAULT 5,
            status varchar(50) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            max_attempts int(11) DEFAULT 3,
            error_message longtext,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY priority (priority),
            KEY scheduled_at (scheduled_at)
        ) $charset_collate;";
        
        // Email Tokens table for OAuth tokens
        $table_email_tokens = $wpdb->prefix . 'azure_email_tokens';
        $sql_email_tokens = "CREATE TABLE $table_email_tokens (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_email varchar(320) NOT NULL,
            access_token longtext NOT NULL,
            refresh_token longtext,
            token_type varchar(50) DEFAULT 'Bearer',
            expires_at datetime NOT NULL,
            scope longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_email (user_email),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Email Logs table for tracking all emails sent through WordPress
        $table_email_logs = $wpdb->prefix . 'azure_email_logs';
        $sql_email_logs = "CREATE TABLE $table_email_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            to_email varchar(500) NOT NULL,
            from_email varchar(320),
            subject varchar(500) NOT NULL,
            message longtext,
            headers longtext,
            attachments longtext,
            method varchar(50) DEFAULT 'wp_mail',
            status varchar(50) DEFAULT 'sent',
            error_message varchar(1000),
            plugin_source varchar(100),
            user_id mediumint(9),
            ip_address varchar(45),
            user_agent varchar(500),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY to_email (to_email(100)),
            KEY from_email (from_email(100)),
            KEY status (status),
            KEY method (method),
            KEY plugin_source (plugin_source),
            FULLTEXT KEY search_content (subject, message)
        ) $charset_collate;";
        
        // Activity Log table for all modules
        $table_activity_log = $wpdb->prefix . 'azure_activity_log';
        $sql_activity_log = "CREATE TABLE $table_activity_log (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            module varchar(50) NOT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(100),
            object_id varchar(255),
            user_id bigint(20) UNSIGNED,
            ip_address varchar(45),
            user_agent varchar(500),
            details longtext,
            status varchar(50) DEFAULT 'success',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY module (module),
            KEY action (action),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";
        
        // TEC Sync History table for tracking sync operations
        $table_tec_sync_history = $wpdb->prefix . 'azure_tec_sync_history';
        $sql_tec_sync_history = "CREATE TABLE $table_tec_sync_history (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tec_event_id bigint(20) UNSIGNED NOT NULL,
            outlook_event_id varchar(255),
            sync_direction varchar(20) NOT NULL,
            sync_action varchar(50) NOT NULL,
            sync_status varchar(50) NOT NULL,
            sync_message longtext,
            data_before longtext,
            data_after longtext,
            conflict_resolution varchar(50),
            sync_timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tec_event_id (tec_event_id),
            KEY outlook_event_id (outlook_event_id),
            KEY sync_direction (sync_direction),
            KEY sync_status (sync_status),
            KEY sync_timestamp (sync_timestamp)
        ) $charset_collate;";
        
        // TEC Sync Conflicts table for manual resolution
        $table_tec_sync_conflicts = $wpdb->prefix . 'azure_tec_sync_conflicts';
        $sql_tec_sync_conflicts = "CREATE TABLE $table_tec_sync_conflicts (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tec_event_id bigint(20) UNSIGNED NOT NULL,
            outlook_event_id varchar(255) NOT NULL,
            conflict_type varchar(50) NOT NULL,
            tec_data longtext NOT NULL,
            outlook_data longtext NOT NULL,
            resolution_status varchar(50) DEFAULT 'pending',
            resolution_method varchar(50),
            resolved_by bigint(20) UNSIGNED,
            resolved_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tec_event_id (tec_event_id),
            KEY outlook_event_id (outlook_event_id),
            KEY resolution_status (resolution_status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // TEC Sync Queue table for batch processing
        $table_tec_sync_queue = $wpdb->prefix . 'azure_tec_sync_queue';
        $sql_tec_sync_queue = "CREATE TABLE $table_tec_sync_queue (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tec_event_id bigint(20) UNSIGNED NOT NULL,
            sync_direction varchar(20) NOT NULL,
            sync_action varchar(50) NOT NULL,
            priority int(11) DEFAULT 5,
            status varchar(50) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            max_attempts int(11) DEFAULT 3,
            error_message longtext,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tec_event_id (tec_event_id),
            KEY sync_direction (sync_direction),
            KEY status (status),
            KEY priority (priority),
            KEY scheduled_at (scheduled_at)
        ) $charset_collate;";
        
        // TEC Calendar Mappings table for Outlook calendar to TEC category mappings
        $table_tec_calendar_mappings = $wpdb->prefix . 'azure_tec_calendar_mappings';
        $sql_tec_calendar_mappings = "CREATE TABLE $table_tec_calendar_mappings (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            outlook_calendar_id varchar(255) NOT NULL,
            outlook_calendar_name varchar(255) NOT NULL,
            tec_category_id bigint(20) UNSIGNED,
            tec_category_name varchar(255) NOT NULL,
            sync_enabled tinyint(1) DEFAULT 1,
            schedule_enabled tinyint(1) DEFAULT 0,
            schedule_frequency varchar(20) DEFAULT 'hourly',
            schedule_lookback_days int(11) DEFAULT 30,
            schedule_lookahead_days int(11) DEFAULT 365,
            last_sync datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY outlook_calendar_id (outlook_calendar_id),
            KEY sync_enabled (sync_enabled),
            KEY schedule_enabled (schedule_enabled),
            KEY last_sync (last_sync)
        ) $charset_collate;";
        
        // OneDrive Media Files table for file mappings
        $table_onedrive_files = $wpdb->prefix . 'azure_onedrive_files';
        $sql_onedrive_files = "CREATE TABLE $table_onedrive_files (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) UNSIGNED,
            onedrive_id varchar(255) NOT NULL,
            onedrive_path text NOT NULL,
            file_name varchar(255) NOT NULL,
            file_size bigint(20) UNSIGNED NOT NULL,
            mime_type varchar(100),
            public_url text,
            download_url text,
            thumbnail_url text,
            folder_year varchar(4),
            last_modified datetime,
            sync_status varchar(20) DEFAULT 'synced',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY onedrive_id (onedrive_id),
            KEY attachment_id (attachment_id),
            KEY folder_year (folder_year),
            KEY sync_status (sync_status)
        ) $charset_collate;";
        
        // OneDrive Media Sync Queue table for batch operations
        $table_onedrive_sync_queue = $wpdb->prefix . 'azure_onedrive_sync_queue';
        $sql_onedrive_sync_queue = "CREATE TABLE $table_onedrive_sync_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            operation varchar(50) NOT NULL,
            file_id bigint(20) UNSIGNED,
            local_path text,
            onedrive_path text,
            status varchar(20) DEFAULT 'pending',
            retry_count int(11) DEFAULT 0,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY file_id (file_id)
        ) $charset_collate;";
        
        // OneDrive Media Tokens table for OAuth tokens
        $table_onedrive_tokens = $wpdb->prefix . 'azure_onedrive_tokens';
        $sql_onedrive_tokens = "CREATE TABLE $table_onedrive_tokens (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_email varchar(320) NOT NULL,
            access_token longtext NOT NULL,
            refresh_token longtext,
            token_type varchar(50) DEFAULT 'Bearer',
            expires_at datetime NOT NULL,
            scope longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_email (user_email),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Auction Bids table for audit trail
        $table_auction_bids = $wpdb->prefix . 'azure_auction_bids';
        $sql_auction_bids = "CREATE TABLE $table_auction_bids (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            bid_amount decimal(14,2) NOT NULL,
            max_bid decimal(14,2) DEFAULT NULL,
            is_auto_bid tinyint(1) DEFAULT 0,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Product Fields tables
        $table_pf_groups = $wpdb->prefix . 'azure_product_field_groups';
        $sql_pf_groups = "CREATE TABLE $table_pf_groups (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            sort_order int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $table_pf_fields = $wpdb->prefix . 'azure_product_fields';
        $sql_pf_fields = "CREATE TABLE $table_pf_fields (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id bigint(20) UNSIGNED NOT NULL,
            label varchar(255) NOT NULL,
            field_key varchar(100) NOT NULL DEFAULT '',
            scope varchar(10) NOT NULL DEFAULT 'child',
            field_type varchar(50) NOT NULL DEFAULT 'text',
            placeholder varchar(255) DEFAULT '',
            options_json longtext,
            required tinyint(1) DEFAULT 0,
            save_to_profile tinyint(1) DEFAULT 0,
            user_meta_key varchar(255) DEFAULT '',
            sort_order int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY group_id (group_id),
            KEY field_key (field_key)
        ) $charset_collate;";

        $table_pf_categories = $wpdb->prefix . 'azure_product_field_categories';
        $sql_pf_categories = "CREATE TABLE $table_pf_categories (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id bigint(20) UNSIGNED NOT NULL,
            term_id bigint(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY group_category (group_id, term_id),
            KEY term_id (term_id)
        ) $charset_collate;";

        // Donation Campaigns table
        $table_donation_campaigns = $wpdb->prefix . 'azure_donation_campaigns';
        $sql_donation_campaigns = "CREATE TABLE $table_donation_campaigns (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            goal_amount decimal(10,2) DEFAULT 0,
            raised_amount decimal(10,2) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Connected Family table (Parent 1 + Parent 2 link, possibly different addresses)
        $table_connected_family = $wpdb->prefix . 'azure_connected_family';
        $sql_connected_family = "CREATE TABLE $table_connected_family (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name varchar(255) NOT NULL DEFAULT '',
            primary_user_id bigint(20) UNSIGNED NOT NULL,
            secondary_user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY primary_user_id (primary_user_id),
            KEY secondary_user_id (secondary_user_id)
        ) $charset_collate;";

        // Connected Family Meta table (KV storage for emergency contact + future family-scope fields)
        $table_connected_family_meta = $wpdb->prefix . 'azure_connected_family_meta';
        $sql_connected_family_meta = "CREATE TABLE $table_connected_family_meta (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id bigint(20) UNSIGNED NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext,
            PRIMARY KEY (id),
            KEY family_id (family_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";

        // User Children table (parent → child profiles).
        // `user_id` retained for back-compat; `family_id` is the canonical link
        // going forward. The v3.67 backfill creates a single-parent family for
        // each existing row so lookups can always go through the family.
        $table_user_children = $wpdb->prefix . 'azure_user_children';
        $sql_user_children = "CREATE TABLE $table_user_children (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            family_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            child_name varchar(255) NOT NULL,
            date_of_birth date DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY family_id (family_id)
        ) $charset_collate;";

        // User Children Meta table (flexible key-value for child fields)
        $table_user_children_meta = $wpdb->prefix . 'azure_user_children_meta';
        $sql_user_children_meta = "CREATE TABLE $table_user_children_meta (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id bigint(20) UNSIGNED NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext,
            PRIMARY KEY (id),
            KEY child_id (child_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";

        // Volunteer Sheets table (links to TEC event or standalone)
        $table_volunteer_sheets = $wpdb->prefix . 'azure_volunteer_sheets';
        $sql_volunteer_sheets = "CREATE TABLE $table_volunteer_sheets (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            tec_event_id bigint(20) UNSIGNED DEFAULT 0,
            event_date datetime DEFAULT NULL,
            event_location varchar(500) DEFAULT '',
            status varchar(20) DEFAULT 'open',
            created_by bigint(20) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tec_event_id (tec_event_id),
            KEY status (status)
        ) $charset_collate;";

        // Volunteer Activities table (roles/slots within a sheet)
        $table_volunteer_activities = $wpdb->prefix . 'azure_volunteer_activities';
        $sql_volunteer_activities = "CREATE TABLE $table_volunteer_activities (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sheet_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            spots_needed int(11) NOT NULL DEFAULT 1,
            sort_order int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sheet_id (sheet_id)
        ) $charset_collate;";

        // Volunteer Signups table (user commitments)
        $table_volunteer_signups = $wpdb->prefix . 'azure_volunteer_signups';
        $sql_volunteer_signups = "CREATE TABLE $table_volunteer_signups (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            signed_up_at datetime DEFAULT CURRENT_TIMESTAMP,
            reminder_sent tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY activity_user (activity_id, user_id),
            KEY user_id (user_id),
            KEY reminder_sent (reminder_sent)
        ) $charset_collate;";

        // Donation Records table
        $table_donation_records = $wpdb->prefix . 'azure_donation_records';
        $sql_donation_records = "CREATE TABLE $table_donation_records (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) UNSIGNED DEFAULT 0,
            order_id bigint(20) UNSIGNED DEFAULT 0,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            amount decimal(10,2) NOT NULL,
            donation_type varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY donation_type (donation_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create all tables
        dbDelta($sql_sso_users);
        dbDelta($sql_backup_jobs);
        dbDelta($sql_backup_files);
        dbDelta($sql_calendar_embeds);
        dbDelta($sql_calendar_cache);
        dbDelta($sql_email_queue);
        dbDelta($sql_email_tokens);
        dbDelta($sql_email_logs);
        dbDelta($sql_activity_log);
        dbDelta($sql_tec_sync_history);
        dbDelta($sql_tec_sync_conflicts);
        dbDelta($sql_tec_sync_queue);
        dbDelta($sql_tec_calendar_mappings);
        dbDelta($sql_onedrive_files);
        dbDelta($sql_onedrive_sync_queue);
        dbDelta($sql_onedrive_tokens);
        dbDelta($sql_auction_bids);
        dbDelta($sql_pf_groups);
        dbDelta($sql_pf_fields);
        dbDelta($sql_pf_categories);
        dbDelta($sql_connected_family);
        dbDelta($sql_connected_family_meta);
        dbDelta($sql_user_children);
        dbDelta($sql_user_children_meta);
        dbDelta($sql_volunteer_sheets);
        dbDelta($sql_volunteer_activities);
        dbDelta($sql_volunteer_signups);
        dbDelta($sql_donation_campaigns);
        dbDelta($sql_donation_records);
        
        // One-time back-fill of new columns on the product fields table.
        // Safe to run on every dbDelta call: the option flag prevents repeats.
        self::backfill_product_fields_keys();

        // v3.67: backfill connected_family rows for existing user_children so
        // every child has a family_id. Self-gated by an option flag.
        self::backfill_connected_families();

        // v3.67: seed/restructure product field groups (rename Core → Child Core,
        // add Allergies, create Parent Core + Emergency Contact). Idempotent.
        self::seed_v367_field_groups();

        // v3.67.2: consolidate field duplicates left behind by older plugins
        // and by the v3.67 seed. Migrates orphan importer data to whichever
        // existing field config matches by normalized label. One-shot.
        self::consolidate_v367_field_duplicates();

        // Log successful table creation
        Azure_Logger::info('Azure Plugin database tables created successfully');
    }

    /**
     * v3.67.2: Consolidate duplicate child-scope fields and migrate orphan
     * meta entries written by the v3.67 importer to the user's pre-existing
     * field configuration.
     *
     * Why: v3.67's seed created canonical-keyed fields like `child_grade`
     * alongside pre-existing fields like "Childs Grade" (select dropdown).
     * Result: the admin saw two grade fields, the import wrote data to the
     * canonical text field, and the dropdown stayed empty. The same problem
     * applied to Enrichment data (`photos_ok`, `epi_pen`, `ymca`,
     * `other_notes_instructor`) where the importer's canonical keys never
     * had matching field configs.
     *
     * What this method does, gated by `azure_pf_v367_consolidation_done`:
     *   1. For each canonical child-scope concept, find every field whose
     *      normalized label matches a known synonym set.
     *   2. Pick a winner — preferring (a) non-canonical key (existing field),
     *      (b) presence of options_json, (c) lower id (older configuration).
     *   3. Migrate data: every loser's `pta_pf_<key>` row in
     *      `azure_user_children_meta` is moved to the winner's key. The
     *      importer's canonical key (which may have no field config at all)
     *      is migrated the same way.
     *   4. Delete every loser field config row so admin sees one row per
     *      concept.
     *
     * Conflict policy: if the winner key already has data for a given
     * child, the loser row is deleted (existing wins). Otherwise the loser
     * row is renamed to the winner's key (data preserved, just under the
     * canonical storage slug for the existing field).
     */
    public static function consolidate_v367_field_duplicates() {
        // v2 of the gate: the original normalizer didn't collapse the
        // no-apostrophe form ("childs") to "child", so "Childs Grade" was
        // never matched against the canonical synonyms and the duplicates
        // survived. Bumping the option name re-runs the migration on sites
        // where v1 already finished.
        if (get_option('azure_pf_v367_consolidation_done_v2') === 'yes') {
            return;
        }

        global $wpdb;
        $fld_table = self::get_table_name('product_fields');
        $cm_table  = self::get_table_name('user_children_meta');
        if (!$fld_table) {
            return;
        }

        // canonical_key => array(label_synonyms_after_normalization)
        // Each set is intentionally narrow — fuzzy matches risk merging
        // unrelated fields. Add to this list if a site has a label not
        // already captured.
        $synonyms = array(
            'child_name'      => array('child name', 'student name'),
            'child_grade'     => array('child grade', 'grade', 'student grade'),
            'child_teacher'   => array('child teacher', 'teacher', 'student teacher'),
            'photos_ok'       => array(
                'photos ok', 'photos approved', 'photo permission',
                'allow photos', 'photos of your child ok',
                'photos of your child', 'do you allow photos to be taken of your child',
            ),
            'epi_pen'         => array(
                'epi pen', 'epipen', 'self carry epi pen', 'carries epi pen',
                'carries epipen',
            ),
            'ymca'            => array(
                'ymca', 'attending ymca', 'is your child attending ymca that day',
                'is your child attending ymca',
            ),
            'other_notes_instructor' => array(
                'other notes instructor', 'other notes for instructor',
                'other notes for class instructor', 'instructor notes',
            ),
        );

        $candidates = $wpdb->get_results(
            "SELECT id, label, field_key, field_type, options_json, sort_order
             FROM {$fld_table}
             WHERE scope = 'child'"
        );
        if (empty($candidates)) {
            $candidates = array();
        }

        // Index candidates by normalized label so each concept's match is
        // O(synonyms) rather than O(fields × synonyms).
        $by_norm = array();
        foreach ($candidates as $c) {
            $norm = self::normalize_label_for_consolidation($c->label);
            if (!isset($by_norm[$norm])) {
                $by_norm[$norm] = array();
            }
            $by_norm[$norm][] = $c;
        }

        foreach ($synonyms as $canonical_key => $synonym_labels) {
            $matches = array();
            foreach ($synonym_labels as $syn) {
                if (!empty($by_norm[$syn])) {
                    foreach ($by_norm[$syn] as $f) {
                        $matches[$f->id] = $f;
                    }
                }
            }
            if (empty($matches)) {
                continue;
            }
            $matches = array_values($matches);

            usort($matches, function ($a, $b) use ($canonical_key) {
                // Prefer non-canonical (existing) field over the v3.67 seed.
                $a_canonical = ($a->field_key === $canonical_key) ? 1 : 0;
                $b_canonical = ($b->field_key === $canonical_key) ? 1 : 0;
                if ($a_canonical !== $b_canonical) {
                    return $a_canonical - $b_canonical;
                }
                // Prefer rows with select options configured.
                $a_opts = !empty($a->options_json) ? 1 : 0;
                $b_opts = !empty($b->options_json) ? 1 : 0;
                if ($a_opts !== $b_opts) {
                    return $b_opts - $a_opts;
                }
                return (int) $a->id - (int) $b->id;
            });

            $winner = $matches[0];
            $winner_key = 'pta_pf_' . $winner->field_key;
            $canonical_meta_key = 'pta_pf_' . $canonical_key;

            // Migrate orphan importer data (canonical key with no field row).
            if ($canonical_meta_key !== $winner_key) {
                self::move_meta_key($cm_table, 'child_id', $canonical_meta_key, $winner_key);
            }

            // Migrate every loser's data and delete its field row.
            for ($i = 1; $i < count($matches); $i++) {
                $loser = $matches[$i];
                $loser_key = 'pta_pf_' . $loser->field_key;
                if ($loser_key !== $winner_key) {
                    self::move_meta_key($cm_table, 'child_id', $loser_key, $winner_key);
                }
                $wpdb->delete($fld_table, array('id' => (int) $loser->id), array('%d'));
            }
        }

        update_option('azure_pf_v367_consolidation_done_v2', 'yes', false);
    }

    /**
     * Move every row in $table whose meta_key matches $from_key to $to_key,
     * keyed by $entity_col (e.g. child_id). If a row at $to_key already
     * exists for the same entity, the from-row is deleted (winner wins).
     */
    private static function move_meta_key($table, $entity_col, $from_key, $to_key) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, {$entity_col} AS entity_id FROM {$table} WHERE meta_key = %s",
            $from_key
        ));
        if (empty($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE {$entity_col} = %d AND meta_key = %s",
                (int) $row->entity_id, $to_key
            ));
            if ($exists) {
                $wpdb->delete($table, array('id' => (int) $row->id), array('%d'));
            } else {
                $wpdb->update(
                    $table,
                    array('meta_key' => $to_key),
                    array('id' => (int) $row->id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    /**
     * Normalize a field label for synonym matching. Mirrors the
     * normalization in Azure_User_Children for runtime dedup so that the
     * config layer and the runtime layer collapse the same variants.
     */
    private static function normalize_label_for_consolidation($label) {
        $norm = strtolower(trim((string) $label));
        // Strip apostrophes/parens/punctuation to spaces ("Child's" → "child s",
        // "Child(s)" → "child s ").
        $norm = preg_replace('/[^a-z0-9 ]+/', ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        // Collapse every "child" variant ("childs", "child s") into "child"
        // so "Childs Grade", "Child's Grade", "Child(s) Grade", "Child Grade"
        // all normalize to the same token sequence.
        $norm = preg_replace('/\bchild(s|\s+s)?\b/', 'child', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        return trim($norm);
    }

    /**
     * v3.67: For every existing `azure_user_children` row with `family_id = 0`,
     * create a single-parent connected_family (`primary_user_id = user_id`,
     * no secondary) and update the child's `family_id`. Idempotent: skips
     * rows that already have a family_id, gated by the
     * `azure_connected_family_backfill_done` option.
     */
    public static function backfill_connected_families() {
        global $wpdb;

        if (get_option('azure_connected_family_backfill_done') === 'yes') {
            return;
        }

        $children_table = self::get_table_name('user_children');
        $family_table   = self::get_table_name('connected_family');
        if (!$children_table || !$family_table) {
            return;
        }

        $children = $wpdb->get_results(
            "SELECT id, user_id FROM {$children_table} WHERE family_id = 0 AND user_id > 0"
        );
        if (empty($children)) {
            update_option('azure_connected_family_backfill_done', 'yes', false);
            return;
        }

        // Re-use one family per primary user — multiple children of the same
        // user join the same family.
        $user_to_family = array();
        foreach ($children as $child) {
            $user_id = (int) $child->user_id;
            if (!isset($user_to_family[$user_id])) {
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$family_table} WHERE primary_user_id = %d AND secondary_user_id = 0 LIMIT 1",
                    $user_id
                ));
                if ($existing) {
                    $user_to_family[$user_id] = $existing;
                } else {
                    $display = self::derive_family_display_name($user_id);
                    $wpdb->insert($family_table, array(
                        'display_name'    => $display,
                        'primary_user_id' => $user_id,
                    ), array('%s', '%d'));
                    $user_to_family[$user_id] = (int) $wpdb->insert_id;
                }
            }
            $wpdb->update(
                $children_table,
                array('family_id' => $user_to_family[$user_id]),
                array('id' => (int) $child->id),
                array('%d'),
                array('%d')
            );
        }

        update_option('azure_connected_family_backfill_done', 'yes', false);
    }

    /**
     * Best-effort family display name from a WP user record. Returns
     * "<Last> family" if a last name is present, else "<Display Name>".
     *
     * @param int $user_id
     * @return string
     */
    public static function derive_family_display_name($user_id) {
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return '';
        }
        $last = get_user_meta($user_id, 'last_name', true);
        if ($last) {
            return $last . ' family';
        }
        return $user->display_name ?: $user->user_login;
    }

    /**
     * v3.67: rename "Core" group → "Child Core", create "Parent Core" and
     * "Emergency Contact" groups, ensure every canonical field exists.
     * Idempotent: gated by the `azure_pf_v367_seed_done` option, and each
     * field is matched on `field_key` so re-runs are safe.
     */
    public static function seed_v367_field_groups() {
        global $wpdb;

        if (get_option('azure_pf_v367_seed_done') === 'yes') {
            return;
        }

        $groups_table = self::get_table_name('product_field_groups');
        $fields_table = self::get_table_name('product_fields');
        if (!$groups_table || !$fields_table) {
            return;
        }

        // 1. Rename existing "Core" group to "Child Core". Match case-insensitive.
        $core_group_id = (int) $wpdb->get_var(
            "SELECT id FROM {$groups_table} WHERE LOWER(name) = 'core' LIMIT 1"
        );
        if ($core_group_id) {
            $wpdb->update(
                $groups_table,
                array('name' => 'Child Core'),
                array('id' => $core_group_id),
                array('%s'),
                array('%d')
            );
        } else {
            $core_group_id = self::ensure_field_group($groups_table, 'Child Core', 'Child profile fields used at checkout', 10);
        }

        // 2. Ensure Child Core has the canonical child fields.
        self::ensure_field($fields_table, $core_group_id, 'Child Name', 'child_name', 'child', 'text', true, true, 10);
        self::ensure_field($fields_table, $core_group_id, 'Child Grade', 'child_grade', 'child', 'text', false, true, 20);
        self::ensure_field($fields_table, $core_group_id, 'Child Teacher', 'child_teacher', 'child', 'text', false, true, 30);

        // 3. Parent Core group + 6 fields (parent scope = stored in usermeta).
        $parent_group_id = self::ensure_field_group($groups_table, 'Parent Core', 'Parent contact details, pre-filled from profile', 20);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 1 Name',  'parent_1_name',  'parent', 'text',  false, true, 10);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 1 Email', 'parent_1_email', 'parent', 'email', false, true, 20);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 1 Cell',  'parent_1_cell',  'parent', 'text',  false, true, 30);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 2 Name',  'parent_2_name',  'parent', 'text',  false, true, 40);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 2 Email', 'parent_2_email', 'parent', 'email', false, true, 50);
        self::ensure_field($fields_table, $parent_group_id, 'Parent 2 Cell',  'parent_2_cell',  'parent', 'text',  false, true, 60);

        // 4. Enrichment group: ensure exists, add Allergies if missing. Existing
        // fields are matched on label (best-effort) since they predate field_key.
        $enrichment_group_id = (int) $wpdb->get_var(
            "SELECT id FROM {$groups_table} WHERE LOWER(name) = 'enrichment' LIMIT 1"
        );
        if (!$enrichment_group_id) {
            $enrichment_group_id = self::ensure_field_group($groups_table, 'Enrichment', 'Enrichment / activity-specific child fields', 30);
        }
        self::ensure_field($fields_table, $enrichment_group_id, 'Allergies', 'allergies', 'child', 'textarea', false, true, 100);

        // 5. Emergency Contact group + 3 family-scope fields.
        $emergency_group_id = self::ensure_field_group($groups_table, 'Emergency Contact', 'Family-level emergency contact (shared between co-parents)', 40);
        self::ensure_field($fields_table, $emergency_group_id, 'Emergency Contact Name',  'emergency_contact_name',  'family', 'text',  false, true, 10);
        self::ensure_field($fields_table, $emergency_group_id, 'Emergency Contact Email', 'emergency_contact_email', 'family', 'email', false, true, 20);
        self::ensure_field($fields_table, $emergency_group_id, 'Emergency Contact Cell',  'emergency_contact_cell',  'family', 'text',  false, true, 30);

        update_option('azure_pf_v367_seed_done', 'yes', false);
    }

    /**
     * Get an existing product field group by name (case-insensitive) or
     * create it. Returns the group id.
     */
    private static function ensure_field_group($groups_table, $name, $description, $sort_order) {
        global $wpdb;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$groups_table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
            $name
        ));
        if ($existing) {
            return $existing;
        }
        $wpdb->insert(
            $groups_table,
            array(
                'name'        => $name,
                'description' => $description,
                'sort_order'  => (int) $sort_order,
                'is_active'   => 1,
            ),
            array('%s', '%s', '%d', '%d')
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Get an existing field by `field_key` or insert it. `field_key` is
     * immutable so this is safe to re-run.
     */
    private static function ensure_field($fields_table, $group_id, $label, $field_key, $scope, $field_type, $required, $save_to_profile, $sort_order) {
        global $wpdb;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$fields_table} WHERE field_key = %s LIMIT 1",
            $field_key
        ));
        if ($existing) {
            return $existing;
        }
        $wpdb->insert(
            $fields_table,
            array(
                'group_id'        => (int) $group_id,
                'label'           => $label,
                'field_key'       => $field_key,
                'scope'           => $scope,
                'field_type'      => $field_type,
                'placeholder'     => '',
                'required'        => $required ? 1 : 0,
                'save_to_profile' => $save_to_profile ? 1 : 0,
                'user_meta_key'   => 'pta_pf_' . $field_key,
                'sort_order'      => (int) $sort_order,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d')
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Populate `field_key` (stable slug) and `scope` for any legacy
     * `azure_product_fields` rows that pre-date the v3.64 schema. Labels remain
     * the display string; `field_key` is the immutable storage identifier.
     *
     * Idempotent. Skips rows that already have a `field_key`.
     */
    public static function backfill_product_fields_keys() {
        global $wpdb;

        $done = get_option('azure_pf_field_key_backfill_done');
        if ($done === 'yes') {
            return;
        }

        $fld_table = self::get_table_name('product_fields');
        if (!$fld_table) {
            return;
        }

        $rows = $wpdb->get_results("SELECT id, label, field_key, user_meta_key FROM {$fld_table}");
        if (empty($rows)) {
            update_option('azure_pf_field_key_backfill_done', 'yes', false);
            return;
        }

        $used_keys = array();
        foreach ($rows as $row) {
            if (!empty($row->field_key)) {
                $used_keys[$row->field_key] = true;
            }
        }

        foreach ($rows as $row) {
            if (!empty($row->field_key)) {
                continue;
            }

            $base = '';
            if (!empty($row->user_meta_key)) {
                $base = preg_replace('/^(azure_pf_|pta_pf_)/', '', $row->user_meta_key);
            }
            if ($base === '') {
                $base = sanitize_key($row->label);
            }
            $base = trim($base, '_');
            if ($base === '') {
                $base = 'field_' . $row->id;
            }

            $candidate = $base;
            $i = 2;
            while (isset($used_keys[$candidate])) {
                $candidate = $base . '_' . $i;
                $i++;
            }
            $used_keys[$candidate] = true;

            $wpdb->update($fld_table, array('field_key' => $candidate), array('id' => $row->id));
        }

        update_option('azure_pf_field_key_backfill_done', 'yes', false);
    }

    public static function get_table_name($table) {
        global $wpdb;
        
        $tables = array(
            'sso_users' => $wpdb->prefix . 'azure_sso_users',
            'backup_jobs' => $wpdb->prefix . 'azure_backup_jobs',
            'backup_files' => $wpdb->prefix . 'azure_backup_files',
            'calendar_embeds' => $wpdb->prefix . 'azure_calendar_embeds',
            'calendar_cache' => $wpdb->prefix . 'azure_calendar_cache',
            'email_queue' => $wpdb->prefix . 'azure_email_queue',
            'email_tokens' => $wpdb->prefix . 'azure_email_tokens',
            'email_logs' => $wpdb->prefix . 'azure_email_logs',
            'activity_log' => $wpdb->prefix . 'azure_activity_log',
            'tec_sync_history' => $wpdb->prefix . 'azure_tec_sync_history',
            'tec_sync_conflicts' => $wpdb->prefix . 'azure_tec_sync_conflicts',
            'tec_sync_queue' => $wpdb->prefix . 'azure_tec_sync_queue',
            'tec_calendar_mappings' => $wpdb->prefix . 'azure_tec_calendar_mappings',
            'onedrive_files' => $wpdb->prefix . 'azure_onedrive_files',
            'onedrive_sync_queue' => $wpdb->prefix . 'azure_onedrive_sync_queue',
            'onedrive_tokens' => $wpdb->prefix . 'azure_onedrive_tokens',
            'auction_bids' => $wpdb->prefix . 'azure_auction_bids',
            'newsletters' => $wpdb->prefix . 'azure_newsletters',
            'newsletter_queue' => $wpdb->prefix . 'azure_newsletter_queue',
            'newsletter_stats' => $wpdb->prefix . 'azure_newsletter_stats',
            'newsletter_lists' => $wpdb->prefix . 'azure_newsletter_lists',
            'newsletter_list_members' => $wpdb->prefix . 'azure_newsletter_list_members',
            'newsletter_bounces' => $wpdb->prefix . 'azure_newsletter_bounces',
            'newsletter_templates' => $wpdb->prefix . 'azure_newsletter_templates',
            'newsletter_sending_config' => $wpdb->prefix . 'azure_newsletter_sending_config',
            'product_field_groups' => $wpdb->prefix . 'azure_product_field_groups',
            'product_fields' => $wpdb->prefix . 'azure_product_fields',
            'product_field_categories' => $wpdb->prefix . 'azure_product_field_categories',
            'user_children' => $wpdb->prefix . 'azure_user_children',
            'user_children_meta' => $wpdb->prefix . 'azure_user_children_meta',
            'connected_family' => $wpdb->prefix . 'azure_connected_family',
            'connected_family_meta' => $wpdb->prefix . 'azure_connected_family_meta',
            'volunteer_sheets' => $wpdb->prefix . 'azure_volunteer_sheets',
            'volunteer_activities' => $wpdb->prefix . 'azure_volunteer_activities',
            'volunteer_signups' => $wpdb->prefix . 'azure_volunteer_signups',
            'donation_campaigns' => $wpdb->prefix . 'azure_donation_campaigns',
            'donation_records' => $wpdb->prefix . 'azure_donation_records'
        );
        
        return isset($tables[$table]) ? $tables[$table] : false;
    }
    
    public static function log_activity($module, $action, $object_type = null, $object_id = null, $details = null, $status = 'success') {
        global $wpdb;
        
        $table = self::get_table_name('activity_log');
        if (!$table) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $ip_address = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        $data = array(
            'module' => sanitize_text_field($module),
            'action' => sanitize_text_field($action),
            'object_type' => $object_type ? sanitize_text_field($object_type) : null,
            'object_id' => $object_id ? sanitize_text_field($object_id) : null,
            'user_id' => $user_id ?: null,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'details' => $details ? wp_json_encode($details) : null,
            'status' => sanitize_text_field($status)
        );
        
        $formats = array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s');
        
        return $wpdb->insert($table, $data, $formats);
    }
    
    private static function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    public static function cleanup_old_records($days = 90) {
        global $wpdb;
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Clean up old backup jobs
        $backup_jobs_table = self::get_table_name('backup_jobs');
        if ($backup_jobs_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$backup_jobs_table} WHERE created_at < %s AND status IN ('completed', 'failed')",
                $date_threshold
            ));
        }
        
        // Clean up expired calendar cache
        $calendar_cache_table = self::get_table_name('calendar_cache');
        if ($calendar_cache_table) {
            $wpdb->query(
                "DELETE FROM {$calendar_cache_table} WHERE expires_at < NOW()"
            );
        }
        
        // Clean up old activity logs
        $activity_log_table = self::get_table_name('activity_log');
        if ($activity_log_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$activity_log_table} WHERE created_at < %s",
                $date_threshold
            ));
        }
        
        // Clean up sent emails from queue
        $email_queue_table = self::get_table_name('email_queue');
        if ($email_queue_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$email_queue_table} WHERE created_at < %s AND status = 'sent'",
                $date_threshold
            ));
        }
        
        // Clean up old TEC sync history
        $tec_sync_history_table = self::get_table_name('tec_sync_history');
        if ($tec_sync_history_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$tec_sync_history_table} WHERE created_at < %s",
                $date_threshold
            ));
        }
        
        // Clean up resolved TEC sync conflicts
        $tec_sync_conflicts_table = self::get_table_name('tec_sync_conflicts');
        if ($tec_sync_conflicts_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$tec_sync_conflicts_table} WHERE created_at < %s AND resolution_status = 'resolved'",
                $date_threshold
            ));
        }
        
        // Clean up processed TEC sync queue items
        $tec_sync_queue_table = self::get_table_name('tec_sync_queue');
        if ($tec_sync_queue_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$tec_sync_queue_table} WHERE created_at < %s AND status IN ('completed', 'failed')",
                $date_threshold
            ));
        }
    }
}
