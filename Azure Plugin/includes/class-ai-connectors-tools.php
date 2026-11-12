<?php
/**
 * AI Connectors built-in tools.
 *
 * Store and calendar tools call the same handlers the PTSA mobile API
 * (/wp-json/ptsa/v1) uses, through a synthetic WP_REST_Request, so product,
 * order and event data looks the same everywhere and keeps the same
 * capability gates. Calendar sync runs Azure_Calendar_Sync_Ajax::run_manual_sync(),
 * the code behind the admin "Sync now" button; report export uses the
 * Orders Reports exporter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Tools {

    const CONTENT_TYPES = array('post', 'page');
    const EDITABLE_STATUSES = array('draft', 'pending', 'publish', 'private');
    const CREATE_STATUSES = array('draft', 'pending');

    /** Modules pta_list_modules reports on. */
    const MODULES = array(
        'sso', 'backup', 'calendar', 'email', 'pta', 'onedrive_media', 'classes', 'newsletter', 'tickets',
        'auction', 'product_fields', 'donations', 'volunteer', 'rsvp', 'forms', 'home_screen', 'ai_connectors',
    );

    /** @var Azure_PTSA_REST_API|null */
    private static $ptsa = null;

    public static function register_defaults() {
        $r = 'Azure_AI_Connectors_Registry';
        $P = 'Azure_AI_Connectors_Policy';
        $self = __CLASS__;

        // ─── Site ────────────────────────────────────────────────────
        $r::register('pta_whoami', array(
            'group'        => 'site',
            'title'        => 'Who am I',
            'description'  => 'Site details, the WordPress account these tools act as, its roles, and which tools it can use. Call this first.',
            'kind'         => $P::KIND_READ,
            'permission'   => array('read'),
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'whoami'),
        ));

        // ─── Pages and posts ─────────────────────────────────────────
        $type_prop = array('type' => 'string', 'enum' => self::CONTENT_TYPES, 'description' => 'post or page. Default post.');
        $content_caps = array('edit_posts', 'edit_pages');
        $brand = Azure_AI_Connectors_Brand_Guide::active() ? ' ' . Azure_AI_Connectors_Brand_Guide::instruction() : '';

        $r::register(Azure_AI_Connectors_Brand_Guide::TOOL, array(
            'group'        => 'content',
            'title'        => 'Get the brand guide',
            'description'  => 'The site brand guide chosen by an administrator: the agent prompt to follow, brand colours, fonts and example images. Call it before creating or updating any page, post or product description, and follow it.',
            'kind'         => $P::KIND_READ,
            'permission'   => array('edit_posts', 'edit_pages', 'edit_products'),
            'access'       => '',
            'available'    => array('Azure_AI_Connectors_Brand_Guide', 'active'),
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'get_brand_guide'),
        ));

        $r::register('pta_list_content', array(
            'group'        => 'content',
            'title'        => 'List pages or posts',
            'description'  => 'List posts or pages you can edit, newest first. Filter by status or search text.',
            'kind'         => $P::KIND_READ,
            'permission'   => $content_caps,
            'access'       => 'content',
            'input_schema' => array('type' => 'object', 'properties' => array(
                'type'     => $type_prop,
                'status'   => array('type' => 'string', 'enum' => array('any', 'publish', 'draft', 'pending', 'private', 'future')),
                'search'   => array('type' => 'string', 'maxLength' => 200),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'page'     => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'list_content'),
        ));
        $r::register('pta_get_content', array(
            'group'        => 'content',
            'title'        => 'Get a page or post',
            'description'  => 'Full content of one post or page by id, including its raw block markup.',
            'kind'         => $P::KIND_READ,
            'permission'   => $content_caps,
            'access'       => 'content',
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'get_content'),
        ));
        $r::register('pta_create_content', array(
            'group'        => 'content',
            'title'        => 'Create a page or post',
            'description'  => 'Create a post or page as a draft (or pending review). New content is never published straight away: once the user has checked it, publish it with pta_update_content.' . $brand,
            'kind'         => $P::KIND_CREATE,
            'permission'   => $content_caps,
            'access'       => 'content_create',
            'input_schema' => array('type' => 'object', 'required' => array('title'), 'properties' => array(
                'type'    => $type_prop,
                'title'   => array('type' => 'string', 'maxLength' => 300),
                'content' => array('type' => 'string', 'description' => 'HTML or block markup.'),
                'excerpt' => array('type' => 'string'),
                'status'  => array('type' => 'string', 'enum' => self::CREATE_STATUSES, 'description' => 'draft (default) or pending.'),
                'slug'    => array('type' => 'string', 'maxLength' => 200),
                'parent'  => array('type' => 'integer', 'minimum' => 0, 'description' => 'Parent page id (pages only).'),
            )),
            'callback'     => array($self, 'create_content'),
        ));
        $r::register('pta_update_content', array(
            'group'        => 'content',
            'title'        => 'Update a page or post',
            'description'  => 'Change the title, content, excerpt, slug, parent or status of a post or page. Only the fields you send change. Set status to publish to publish it, or draft to unpublish it.' . $brand,
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $content_caps,
            'access'       => 'content',
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array(
                'id'      => array('type' => 'integer', 'minimum' => 1),
                'title'   => array('type' => 'string', 'maxLength' => 300),
                'content' => array('type' => 'string'),
                'excerpt' => array('type' => 'string'),
                'status'  => array('type' => 'string', 'enum' => self::EDITABLE_STATUSES),
                'slug'    => array('type' => 'string', 'maxLength' => 200),
                'parent'  => array('type' => 'integer', 'minimum' => 0),
            )),
            'callback'     => array($self, 'update_content'),
        ));
        $r::register('pta_trash_content', array(
            'group'        => 'content',
            'title'        => 'Move a page or post to the trash',
            'description'  => 'Move a post or page to the trash. It can be restored from Trash in wp-admin; nothing is permanently deleted.',
            'kind'         => $P::KIND_DESTRUCTIVE,
            'permission'   => array('delete_posts', 'delete_pages'),
            'access'       => 'content_trash',
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'trash_content'),
        ));

        // ─── Calendar ────────────────────────────────────────────────
        $r::register('pta_list_events', array(
            'group'        => 'calendar',
            'title'        => 'List calendar events',
            'description'  => 'Events on the site calendar between two dates (default: last 7 days to 90 days ahead), soonest first.',
            'kind'         => $P::KIND_READ,
            'permission'   => array('read'),
            'input_schema' => array('type' => 'object', 'properties' => array(
                'from'         => array('type' => 'string', 'description' => 'Start date, e.g. 2026-09-01.'),
                'to'           => array('type' => 'string', 'description' => 'End date, e.g. 2026-09-30.'),
                'calendar_ids' => array('type' => 'string', 'description' => 'Comma-separated Outlook calendar ids to limit to.'),
                'per_page'     => array('type' => 'integer', 'minimum' => 1, 'maximum' => 500),
            )),
            'callback'     => array($self, 'list_events'),
        ));
        $r::register('pta_calendar_sync', array(
            'group'        => 'calendar',
            'title'        => 'Sync calendars from Outlook now',
            'description'  => 'Run the Outlook calendar sync now, the same as Calendar > Sync > Sync now. Events removed in Outlook are removed from the site calendar.',
            'kind'         => $P::KIND_ADMIN,
            'permission'   => array('manage_options'),
            'access'       => 'calendar_sync',
            'available'    => function () {
                return class_exists('Azure_Settings') && Azure_Settings::is_module_enabled('calendar');
            },
            'open_world'   => true,
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'calendar_sync'),
        ));

        // ─── Store (WooCommerce) ─────────────────────────────────────
        $wc = array($self, 'woocommerce_active');
        $order_caps = array('edit_shop_orders', 'manage_woocommerce');
        $coupon_caps = array('edit_shop_coupons', 'manage_woocommerce');

        $r::register('pta_list_orders', array(
            'group'        => 'store',
            'title'        => 'List orders',
            'description'  => 'Store orders, newest first, with customer, items and totals. Filter by status (e.g. processing, completed) or search text.',
            'kind'         => $P::KIND_READ,
            'permission'   => $order_caps,
            'access'       => 'orders',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'status'   => array('type' => 'string', 'maxLength' => 40),
                'search'   => array('type' => 'string', 'maxLength' => 200),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'page'     => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'list_orders'),
        ));
        $r::register('pta_get_order', array(
            'group'        => 'store',
            'title'        => 'Get an order',
            'description'  => 'One order by id.',
            'kind'         => $P::KIND_READ,
            'permission'   => $order_caps,
            'access'       => 'orders',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'get_order'),
        ));
        $r::register('pta_list_coupons', array(
            'group'        => 'store',
            'title'        => 'List coupons',
            'description'  => 'Store coupons with discount, limits, usage and expiry.',
            'kind'         => $P::KIND_READ,
            'permission'   => $coupon_caps,
            'access'       => 'coupons',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'search'   => array('type' => 'string', 'maxLength' => 100),
                'status'   => array('type' => 'string', 'enum' => array('any', 'publish', 'draft', 'pending', 'future')),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'page'     => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'list_coupons'),
        ));
        $r::register('pta_create_coupon', array(
            'group'        => 'store',
            'title'        => 'Create a coupon',
            'description'  => 'Create a coupon code. It is live straight away unless status is draft.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => $coupon_caps,
            'access'       => 'coupons',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('code', 'amount'), 'properties' => array(
                'code'                 => array('type' => 'string', 'maxLength' => 60),
                'discount_type'        => array('type' => 'string', 'enum' => array('percent', 'fixed_cart', 'fixed_product')),
                'amount'               => array('type' => 'number', 'minimum' => 0),
                'description'          => array('type' => 'string', 'maxLength' => 500),
                'expires'              => array('type' => 'string', 'description' => 'Expiry date, YYYY-MM-DD.'),
                'usage_limit'          => array('type' => 'integer', 'minimum' => 0),
                'usage_limit_per_user' => array('type' => 'integer', 'minimum' => 0),
                'individual_use'       => array('type' => 'boolean'),
                'free_shipping'        => array('type' => 'boolean'),
                'minimum_amount'       => array('type' => 'string', 'maxLength' => 20),
                'maximum_amount'       => array('type' => 'string', 'maxLength' => 20),
                'product_ids'          => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 200),
                'email_restrictions'   => array('type' => 'array', 'items' => array('type' => 'string'), 'maxItems' => 200),
                'status'               => array('type' => 'string', 'enum' => array('publish', 'draft')),
            )),
            'callback'     => array($self, 'create_coupon'),
        ));
        // ─── Admin ───────────────────────────────────────────────────
        $admin_check = array($P, 'can_administer');
        $r::register('pta_list_modules', array(
            'group'               => 'admin',
            'title'               => 'List PTA Tools modules',
            'description'         => 'Which PTA Tools modules are switched on.',
            'kind'                => $P::KIND_READ,
            'permission'          => array('manage_options'),
            'access'              => Azure_AI_Connectors_Access::ADMIN_GROUP,
            'permission_callback' => $admin_check,
            'input_schema'        => array('type' => 'object', 'properties' => array()),
            'callback'            => array($self, 'list_modules'),
        ));

        Azure_AI_Connectors_Report_Tools::register();
        Azure_AI_Connectors_Store_Tools::register();
        Azure_AI_Connectors_Catalog_Tools::register();
        Azure_AI_Connectors_Stripe_Tools::register();
    }

    // ─── Shared helpers ──────────────────────────────────────────────

    public static function woocommerce_active() {
        return function_exists('wc_get_product') && function_exists('wc_get_orders');
    }

    public static function ptsa() {
        if (self::$ptsa === null) {
            foreach (array('class-ptsa-jwt.php', 'class-ptsa-rest-api.php') as $file) {
                $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
                if (file_exists($path)) {
                    require_once $path;
                }
            }
            self::$ptsa = class_exists('Azure_PTSA_REST_API') ? new Azure_PTSA_REST_API() : false;
        }
        return self::$ptsa ?: null;
    }

    /**
     * Call a PTSA mobile API handler as the current user.
     *
     * @return mixed|WP_Error Response data.
     */
    public static function ptsa_call($method, array $params = array(), array $url_params = array(), $json = null) {
        $api = self::ptsa();
        if (!$api || !method_exists($api, $method)) {
            return new WP_Error('pta_ai_unavailable', 'This part of the site is not available.');
        }
        $request = new WP_REST_Request($json === null ? 'GET' : 'PUT', '/ptsa/v1/ai-connectors');
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        if ($url_params) {
            $request->set_url_params($url_params);
        }
        if ($json !== null) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($json));
        }
        $response = call_user_func(array($api, $method), $request);
        if (is_wp_error($response)) {
            return $response;
        }
        return $response instanceof WP_REST_Response ? $response->get_data() : $response;
    }

    private static function type_object($type) {
        $type = in_array($type, self::CONTENT_TYPES, true) ? $type : 'post';
        return get_post_type_object($type);
    }

    private static function content_summary($post) {
        $author = get_userdata((int) $post->post_author);
        return array(
            'id'       => (int) $post->ID,
            'type'     => $post->post_type,
            'title'    => get_the_title($post),
            'status'   => $post->post_status,
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'author'   => $author ? $author->display_name : '',
            'link'     => get_permalink($post),
        );
    }

    private static function content_post($id) {
        $post = get_post((int) $id);
        if (!$post || !in_array($post->post_type, self::CONTENT_TYPES, true)) {
            return new WP_Error('pta_ai_not_found', 'No post or page with that id.');
        }
        return $post;
    }

    // ─── Site ────────────────────────────────────────────────────────

    public static function whoami(array $args, array $context) {
        $user = wp_get_current_user();
        $scopes = (array) ($context['scopes'] ?? array());
        $tools = array();
        foreach (Azure_AI_Connectors_Registry::available($scopes) as $tool) {
            $tools[] = array(
                'name'             => $tool['name'],
                'kind'             => $tool['kind'],
                'requires_confirm' => Azure_AI_Connectors_Policy::requires_confirm($tool),
            );
        }
        $token = $context['token'] ?? null;
        return array(
            'site' => array(
                'name'           => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                'url'            => home_url('/'),
                'mcp_url'        => Azure_AI_Connectors_Module::mcp_url(),
                'timezone'       => function_exists('azure_wp_timezone_string') ? azure_wp_timezone_string() : 'UTC',
                'plugin_version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '',
            ),
            'user' => array(
                'id'           => (int) $user->ID,
                'login'        => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'roles'        => array_values((array) $user->roles),
            ),
            'connection' => array(
                'type'   => ($token && $token->token_type === Azure_AI_Connectors_Tokens::TYPE_PAT) ? 'personal_access_token' : 'oauth',
                'client' => (string) ($context['client_label'] ?? ''),
                'scopes' => $scopes,
            ),
            'access' => array(
                'groups' => array_map(function ($group) {
                    return array('group' => $group, 'label' => Azure_AI_Connectors_Access::group_label($group));
                }, Azure_AI_Connectors_Access::groups_for($user)),
                'note'   => 'A site administrator chooses what each role can do through AI Connectors. Someone with several roles gets everything any of them allows.',
            ),
            'brand_guide' => Azure_AI_Connectors_Brand_Guide::summary(),
            'tools' => $tools,
        );
    }

    // ─── Pages and posts ─────────────────────────────────────────────

    public static function get_brand_guide(array $args) {
        return Azure_AI_Connectors_Brand_Guide::read();
    }

    public static function list_content(array $args) {
        $type_obj = self::type_object($args['type'] ?? 'post');
        if (!$type_obj || !current_user_can($type_obj->cap->edit_posts)) {
            return new WP_Error('pta_ai_forbidden', 'You cannot edit ' . ($type_obj ? $type_obj->labels->name : 'that type') . '.');
        }
        $status = $args['status'] ?? 'any';
        $query = new WP_Query(array(
            'post_type'           => $type_obj->name,
            'post_status'         => $status === 'any' ? array('publish', 'draft', 'pending', 'private', 'future') : $status,
            's'                   => (string) ($args['search'] ?? ''),
            'posts_per_page'      => (int) ($args['per_page'] ?? 20),
            'paged'               => (int) ($args['page'] ?? 1),
            'orderby'             => 'modified',
            'order'               => 'DESC',
            'perm'                => 'readable',
            'ignore_sticky_posts' => true,
        ));
        $items = array();
        foreach ($query->posts as $post) {
            if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $items[] = self::content_summary($post);
        }
        return array('items' => $items, 'total' => (int) $query->found_posts, 'page' => (int) ($args['page'] ?? 1));
    }

    public static function get_content(array $args) {
        $post = self::content_post($args['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        $can = current_user_can('edit_post', $post->ID)
            || ($post->post_status === 'publish' && current_user_can('read_post', $post->ID));
        if (!$can) {
            return new WP_Error('pta_ai_forbidden', 'You cannot view that item.');
        }
        return self::content_summary($post) + array(
            'slug'    => $post->post_name,
            'parent'  => (int) $post->post_parent,
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            'can_edit' => current_user_can('edit_post', $post->ID),
        );
    }

    public static function create_content(array $args) {
        $type_obj = self::type_object($args['type'] ?? 'post');
        $caps = $type_obj ? $type_obj->cap : null;
        if (!$caps || !current_user_can($caps->create_posts ?? $caps->edit_posts)) {
            return new WP_Error('pta_ai_forbidden', 'You cannot create ' . ($type_obj ? $type_obj->labels->name : 'that type') . '.');
        }
        $status = in_array($args['status'] ?? '', self::CREATE_STATUSES, true) ? $args['status'] : 'draft';
        $postarr = array(
            'post_type'    => $type_obj->name,
            'post_title'   => (string) $args['title'],
            'post_content' => (string) ($args['content'] ?? ''),
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
        );
        if (!empty($args['slug'])) {
            $postarr['post_name'] = sanitize_title($args['slug']);
        }
        if ($type_obj->name === 'page' && !empty($args['parent'])) {
            $postarr['post_parent'] = (int) $args['parent'];
        }
        $id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $post = get_post($id);
        return self::content_summary($post) + array(
            'edit_link' => admin_url('post.php?post=' . (int) $id . '&action=edit'),
            'note'      => 'Saved as ' . $status . '. Show the user, then publish with pta_update_content when they are happy.',
        );
    }

    public static function update_content(array $args) {
        $post = self::content_post($args['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        if ($post->post_status === 'trash') {
            return new WP_Error('pta_ai_trashed', 'That item is in the trash. Restore it in wp-admin first.');
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('pta_ai_forbidden', 'You cannot edit that item.');
        }
        $postarr = array('ID' => (int) $post->ID);
        foreach (array('title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt') as $in => $field) {
            if (array_key_exists($in, $args)) {
                $postarr[$field] = (string) $args[$in];
            }
        }
        if (isset($args['slug'])) {
            $postarr['post_name'] = sanitize_title($args['slug']);
        }
        if (isset($args['parent']) && $post->post_type === 'page') {
            $postarr['post_parent'] = (int) $args['parent'];
        }
        if (isset($args['status']) && $args['status'] !== $post->post_status) {
            $type_obj = get_post_type_object($post->post_type);
            if (in_array($args['status'], array('publish', 'private'), true) && !current_user_can($type_obj->cap->publish_posts)) {
                return new WP_Error('pta_ai_forbidden', 'You cannot publish ' . $type_obj->labels->name . '.');
            }
            $postarr['post_status'] = $args['status'];
        }
        if (count($postarr) === 1) {
            return new WP_Error('pta_ai_nothing', 'Send at least one field to change.');
        }
        $result = wp_update_post(wp_slash($postarr), true);
        if (is_wp_error($result)) {
            return $result;
        }
        return self::content_summary(get_post($post->ID));
    }

    public static function trash_content(array $args) {
        $post = self::content_post($args['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        if (!current_user_can('delete_post', $post->ID)) {
            return new WP_Error('pta_ai_forbidden', 'You cannot delete that item.');
        }
        if (defined('EMPTY_TRASH_DAYS') && !EMPTY_TRASH_DAYS) {
            return new WP_Error('pta_ai_no_trash', 'The trash is turned off on this site, so moving to trash would delete permanently. Refusing.');
        }
        if ($post->post_status === 'trash') {
            return array('id' => (int) $post->ID, 'status' => 'trash', 'message' => 'Already in the trash.');
        }
        $result = wp_trash_post($post->ID);
        if (!$result) {
            return new WP_Error('pta_ai_trash_failed', 'WordPress did not move it to the trash.');
        }
        return array(
            'id'      => (int) $post->ID,
            'title'   => get_the_title($post->ID),
            'status'  => 'trash',
            'message' => 'Moved to the trash. Restore it from Trash in wp-admin if this was a mistake.',
        );
    }

    // ─── Calendar ────────────────────────────────────────────────────

    public static function list_events(array $args) {
        $data = self::ptsa_call('get_events', array(
            'from'         => (string) ($args['from'] ?? ''),
            'to'           => (string) ($args['to'] ?? ''),
            'calendar_ids' => (string) ($args['calendar_ids'] ?? ''),
            'per_page'     => (int) ($args['per_page'] ?? 100),
        ));
        if (is_wp_error($data)) {
            return $data;
        }
        $can_private = current_user_can('read_private_posts');
        $events = array();
        foreach ((array) $data as $event) {
            if (!$can_private && get_post_status((int) ($event['id'] ?? 0)) === 'private') {
                continue;
            }
            $events[] = $event;
        }
        return array('events' => $events, 'count' => count($events));
    }

    public static function calendar_sync(array $args) {
        foreach (array(
            'class-calendar-connections.php',
            'class-calendar-auth.php',
            'class-calendar-graph-api.php',
            'class-calendar-mapping-manager.php',
            'class-calendar-sync-engine.php',
            'class-calendar-sync-ajax.php',
        ) as $file) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Azure_Calendar_Sync_Ajax') || !method_exists('Azure_Calendar_Sync_Ajax', 'run_manual_sync')) {
            return new WP_Error('pta_ai_unavailable', 'Calendar sync is not available.');
        }
        $results = Azure_Calendar_Sync_Ajax::run_manual_sync();
        if (is_wp_error($results)) {
            return $results;
        }
        $calendars = array();
        foreach ((array) ($results['calendar_results'] ?? array()) as $key => $r) {
            $calendars[] = array(
                'calendar'       => (string) ($r['calendar_name'] ?? $key),
                'success'        => !empty($r['success']),
                'events_synced'  => (int) ($r['events_synced'] ?? 0),
                'events_deleted' => (int) ($r['events_deleted'] ?? 0),
                'errors'         => (int) ($r['errors'] ?? 0),
                'error_message'  => (string) ($r['error_message'] ?? ''),
            );
        }
        return array(
            'success'        => !empty($results['success']),
            'calendars'      => (int) ($results['total_calendars'] ?? 0),
            'events_synced'  => (int) ($results['total_events_synced'] ?? 0),
            'events_deleted' => (int) ($results['total_events_deleted'] ?? 0),
            'errors'         => (int) ($results['total_errors'] ?? 0),
            'message'        => (string) ($results['message'] ?? ''),
            'by_calendar'    => $calendars,
        );
    }

    // ─── Store ───────────────────────────────────────────────────────

    public static function list_orders(array $args) {
        $data = self::ptsa_call('list_orders', array(
            'status'   => (string) ($args['status'] ?? ''),
            'search'   => (string) ($args['search'] ?? ''),
            'per_page' => (int) ($args['per_page'] ?? 25),
            'page'     => (int) ($args['page'] ?? 1),
        ));
        return is_wp_error($data) ? $data : array('orders' => array_values((array) $data));
    }

    public static function get_order(array $args) {
        return self::ptsa_call('get_order', array(), array('id' => (int) $args['id']));
    }

    private static function coupon_to_array($coupon) {
        $expires = $coupon->get_date_expires();
        return array(
            'id'                   => (int) $coupon->get_id(),
            'code'                 => $coupon->get_code(),
            'status'               => get_post_status($coupon->get_id()),
            'discount_type'        => $coupon->get_discount_type(),
            'amount'               => (float) $coupon->get_amount(),
            'description'          => $coupon->get_description(),
            'expires'              => $expires ? $expires->date('Y-m-d') : null,
            'usage_count'          => (int) $coupon->get_usage_count(),
            'usage_limit'          => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
            'usage_limit_per_user' => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
            'individual_use'       => (bool) $coupon->get_individual_use(),
            'free_shipping'        => (bool) $coupon->get_free_shipping(),
            'minimum_amount'       => $coupon->get_minimum_amount(),
            'maximum_amount'       => $coupon->get_maximum_amount(),
            'product_ids'          => array_map('intval', (array) $coupon->get_product_ids()),
            'email_restrictions'   => array_values((array) $coupon->get_email_restrictions()),
        );
    }

    public static function list_coupons(array $args) {
        $status = $args['status'] ?? 'any';
        $posts = get_posts(array(
            'post_type'      => 'shop_coupon',
            'post_status'    => $status === 'any' ? array('publish', 'draft', 'pending', 'future') : $status,
            's'              => (string) ($args['search'] ?? ''),
            'posts_per_page' => (int) ($args['per_page'] ?? 25),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        $out = array();
        foreach ($posts as $post) {
            $out[] = self::coupon_to_array(new WC_Coupon($post->ID));
        }
        return array('coupons' => $out);
    }

    public static function create_coupon(array $args) {
        $code = wc_format_coupon_code((string) $args['code']);
        if ($code === '') {
            return new WP_Error('pta_ai_bad_args', 'The coupon code is empty.');
        }
        if (wc_get_coupon_id_by_code($code)) {
            return new WP_Error('pta_ai_exists', 'A coupon with that code already exists.');
        }
        $type = $args['discount_type'] ?? 'percent';
        $amount = (float) $args['amount'];
        if ($type === 'percent' && $amount > 100) {
            return new WP_Error('pta_ai_bad_args', 'A percentage discount cannot be more than 100.');
        }
        $status = $args['status'] ?? 'publish';
        if ($status === 'publish' && !current_user_can('publish_shop_coupons') && !current_user_can('manage_woocommerce')) {
            $status = 'draft';
        }

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($type);
        $coupon->set_amount($amount);
        if (isset($args['description'])) {
            $coupon->set_description(sanitize_textarea_field($args['description']));
        }
        if (!empty($args['expires'])) {
            $ts = strtotime((string) $args['expires'] . ' 23:59:59');
            if ($ts === false) {
                return new WP_Error('pta_ai_bad_args', 'expires must be a date like 2026-12-31.');
            }
            $coupon->set_date_expires($ts);
        }
        if (isset($args['usage_limit'])) {
            $coupon->set_usage_limit((int) $args['usage_limit']);
        }
        if (isset($args['usage_limit_per_user'])) {
            $coupon->set_usage_limit_per_user((int) $args['usage_limit_per_user']);
        }
        if (isset($args['individual_use'])) {
            $coupon->set_individual_use((bool) $args['individual_use']);
        }
        if (isset($args['free_shipping'])) {
            $coupon->set_free_shipping((bool) $args['free_shipping']);
        }
        if (isset($args['minimum_amount'])) {
            $coupon->set_minimum_amount(wc_format_decimal($args['minimum_amount']));
        }
        if (isset($args['maximum_amount'])) {
            $coupon->set_maximum_amount(wc_format_decimal($args['maximum_amount']));
        }
        if (!empty($args['product_ids'])) {
            $coupon->set_product_ids(array_map('absint', $args['product_ids']));
        }
        if (!empty($args['email_restrictions'])) {
            $coupon->set_email_restrictions(array_values(array_filter(array_map('sanitize_email', $args['email_restrictions']))));
        }
        $coupon->set_status($status);
        $id = $coupon->save();
        if (!$id) {
            return new WP_Error('pta_ai_coupon_failed', 'The coupon could not be saved.');
        }
        return self::coupon_to_array(new WC_Coupon($id));
    }

    // ─── Admin ───────────────────────────────────────────────────────

    public static function list_modules(array $args) {
        $settings = Azure_Settings::get_all_settings();
        $modules = array();
        foreach (self::MODULES as $module) {
            $modules[] = array('module' => $module, 'enabled' => !empty($settings['enable_' . $module]));
        }
        return array('modules' => $modules);
    }
}
