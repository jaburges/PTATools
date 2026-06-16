<?php
/**
 * PTSA REST API — /wp-json/ptsa/v1/*
 *
 * First-party mobile / SSO REST surface for the Wilder PTSA Board iOS app.
 * Every route is gated by Azure_PTSA_JWT validating an Entra ID id_token
 * whose audience is our iOS app's client_id. The caller is mapped to a
 * local WP user by email and `wp_set_current_user()`'d so downstream
 * WP / WooCommerce code sees them as that user.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTSA_REST_API {

    const NAMESPACE_V1 = 'ptsa/v1';

    /** @var Azure_PTSA_JWT */ private $jwt;
    /** @var string */         private $allowed_domain;
    /** @var WP_User|null */   private $caller = null;

    public function __construct() {
        list($tenant_id, $client_id, $allowed_domain) = $this->load_config();
        $this->allowed_domain = $allowed_domain;
        if (!class_exists('Azure_PTSA_JWT')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-ptsa-jwt.php';
            if (file_exists($path)) require_once $path;
        }
        if (class_exists('Azure_PTSA_JWT') && $tenant_id && $client_id) {
            $this->jwt = new Azure_PTSA_JWT($tenant_id, $client_id);
        }
    }

    private function load_config() {
        $tenant = defined('PTSA_REST_TENANT_ID') ? PTSA_REST_TENANT_ID : '';
        $client = defined('PTSA_REST_CLIENT_ID') ? PTSA_REST_CLIENT_ID : '';
        $domain = 'wilderptsa.net';

        if (empty($tenant)) {
            $env_tenant = getenv('PTSA_REST_TENANT_ID');
            if (is_string($env_tenant) && $env_tenant !== '') $tenant = $env_tenant;
        }
        if (empty($client)) {
            $env_client = getenv('PTSA_REST_CLIENT_ID');
            if (is_string($env_client) && $env_client !== '') $client = $env_client;
        }
        $env_domain = getenv('PTSA_REST_ALLOWED_DOMAIN');
        if (is_string($env_domain) && $env_domain !== '') $domain = $env_domain;

        if ((empty($tenant) || empty($client)) && class_exists('Azure_Settings')) {
            $creds = Azure_Settings::get_credentials('sso');
            if (is_array($creds)) {
                if (empty($tenant)) $tenant = (string) ($creds['tenant_id'] ?? '');
                if (empty($client)) {
                    // Prefer a dedicated mobile client ID if the admin configured one.
                    $ios = (string) get_option('ptsa_rest_ios_client_id', '');
                    $client = $ios !== '' ? $ios : (string) ($creds['client_id'] ?? '');
                }
            }
        }
        $opt_domain = (string) get_option('ptsa_rest_allowed_domain', '');
        if ($opt_domain !== '') $domain = $opt_domain;

        return array($tenant, $client, $domain);
    }

    /* =================================================================
     * Routes
     * ================================================================= */

    public function register_routes() {
        $auth = array($this, 'authorize');
        $ns   = self::NAMESPACE_V1;

        register_rest_route($ns, '/me', array(
            'methods' => 'GET', 'callback' => array($this, 'whoami'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/calendars', array(
            'methods' => 'GET', 'callback' => array($this, 'get_calendars'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/events', array(
            'methods' => 'GET', 'callback' => array($this, 'get_events'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/orders', array(
            'methods' => 'GET', 'callback' => array($this, 'list_orders'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/orders/(?P<id>\d+)', array(
            array('methods' => 'GET', 'callback' => array($this, 'get_order'),    'permission_callback' => $auth),
            array('methods' => 'PUT', 'callback' => array($this, 'update_order'), 'permission_callback' => $auth),
        ));
        register_rest_route($ns, '/orders/(?P<id>\d+)/refunds', array(
            'methods' => 'POST', 'callback' => array($this, 'refund_order'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/orders/(?P<id>\d+)/notes', array(
            'methods' => 'POST', 'callback' => array($this, 'add_order_note'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/products', array(
            array('methods' => 'GET',  'callback' => array($this, 'list_products'),   'permission_callback' => $auth),
            array('methods' => 'POST', 'callback' => array($this, 'create_product'),  'permission_callback' => $auth),
        ));
        register_rest_route($ns, '/products/(?P<id>\d+)', array(
            'methods' => 'PUT', 'callback' => array($this, 'update_product'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/media', array(
            'methods' => 'POST', 'callback' => array($this, 'upload_media'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/users', array(
            'methods' => 'GET', 'callback' => array($this, 'list_users'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/users/(?P<id>\d+)', array(
            array('methods' => 'GET', 'callback' => array($this, 'get_user'),    'permission_callback' => $auth),
            array('methods' => 'PUT', 'callback' => array($this, 'update_user'), 'permission_callback' => $auth),
        ));
        register_rest_route($ns, '/users/reset-password', array(
            'methods' => 'POST', 'callback' => array($this, 'reset_password_for'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/users/reset-password-self', array(
            'methods' => 'POST', 'callback' => array($this, 'reset_password_self'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/wp-roles', array(
            'methods' => 'GET', 'callback' => array($this, 'list_wp_roles'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/pta-roles/org', array(
            'methods' => 'GET', 'callback' => array($this, 'get_pta_roles_org'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/pta-roles/assignments', array(
            'methods' => 'POST', 'callback' => array($this, 'create_pta_role_assignment'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/pta-roles/assignments/(?P<id>\d+)', array(
            'methods' => 'DELETE', 'callback' => array($this, 'delete_pta_role_assignment'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/todos', array(
            array('methods' => 'GET',  'callback' => array($this, 'list_todos'),  'permission_callback' => $auth),
            array('methods' => 'POST', 'callback' => array($this, 'create_todo'), 'permission_callback' => $auth),
        ));
        register_rest_route($ns, '/todos/(?P<id>\d+)', array(
            array('methods' => 'PUT',    'callback' => array($this, 'update_todo'), 'permission_callback' => $auth),
            array('methods' => 'DELETE', 'callback' => array($this, 'delete_todo'), 'permission_callback' => $auth),
        ));

        register_rest_route($ns, '/auction/email-items', array(
            'methods' => 'POST', 'callback' => array($this, 'auction_email_items'), 'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/orders-reports', array(
            'methods' => 'GET', 'callback' => array($this, 'list_orders_reports'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/orders-reports/(?P<id>\d+)/export', array(
            'methods' => 'GET', 'callback' => array($this, 'export_orders_report'), 'permission_callback' => $auth,
        ));
    }

    /* =================================================================
     * Auth (permission_callback)
     * ================================================================= */

    public function authorize(WP_REST_Request $request) {
        if (!$this->jwt) {
            return new WP_Error('ptsa_rest_not_configured',
                'PTSA REST: tenant_id or client_id not configured. Set the SSO credentials, configure ptsa_rest_ios_client_id, or define PTSA_REST_TENANT_ID/PTSA_REST_CLIENT_ID.',
                array('status' => 500));
        }

        $header = (string) $request->get_header('authorization');
        if ($header === '' && function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    if (strcasecmp($k, 'authorization') === 0) { $header = (string) $v; break; }
                }
            }
        }
        if ($header === '' || stripos($header, 'Bearer ') !== 0) {
            return new WP_Error('ptsa_rest_missing_bearer',
                'Missing Authorization: Bearer <id_token> header.',
                array('status' => 401));
        }
        $jwt = trim(substr($header, 7));

        $payload = $this->jwt->validate($jwt);
        if (is_wp_error($payload)) return $payload;

        // Extract caller email.
        $email = '';
        foreach (array('upn', 'preferred_username', 'email') as $claim) {
            if (!empty($payload[$claim])) { $email = strtolower((string) $payload[$claim]); break; }
        }
        if ($email === '') {
            return new WP_Error('ptsa_rest_no_email',
                'JWT did not contain a usable email claim (upn/preferred_username/email).',
                array('status' => 401));
        }
        $domain = $this->allowed_domain;
        if ($domain !== '' && !preg_match('/@' . preg_quote(strtolower($domain), '/') . '$/', $email)) {
            return new WP_Error('ptsa_rest_domain_denied',
                "Sign-in domain not permitted. Expected @$domain.",
                array('status' => 403, 'email' => $email));
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_Error('ptsa_rest_no_wp_user',
                "Microsoft sign-in succeeded for $email but no matching WordPress user exists.",
                array('status' => 403));
        }
        $this->caller = $user;
        wp_set_current_user($user->ID);
        return true;
    }

    /* =================================================================
     * /me — debug + identity probe
     * ================================================================= */

    public function whoami(WP_REST_Request $req) {
        $u = $this->caller;
        if (!$u) return $this->forbidden();
        return rest_ensure_response(array(
            'id'           => $u->ID,
            'email'        => $u->user_email,
            'display_name' => $u->display_name,
            'roles'        => array_values($u->roles),
        ));
    }

    /* =================================================================
     * Calendars (Outlook mappings) + Events (from pta_event CPT)
     * Calendar mappings table renamed from azure_tec_calendar_mappings to
     * azure_calendar_mappings in the v3.97 TEC retirement. Column names
     * dropped the `tec_` prefix at the same time (category_id, category_name).
     * ================================================================= */

    public function get_calendars(WP_REST_Request $req) {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_calendar_mappings';
        $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY sync_enabled DESC, outlook_calendar_name ASC", ARRAY_A);
        if (!is_array($rows)) $rows = array();

        // Count events per calendar so the iOS picker can show "(N events)".
        $count_by_id = array();
        foreach ($rows as $row) {
            $cid = (string) ($row['outlook_calendar_id'] ?? '');
            if ($cid === '') continue;
            $q = new WP_Query(array(
                'post_type'      => 'pta_event',
                'post_status'    => array('publish', 'private'),
                'meta_query'     => array(array('key' => '_outlook_calendar_id', 'value' => $cid)),
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'no_found_rows'  => false,
            ));
            $count_by_id[$cid] = (int) $q->found_posts;
        }

        $out = array();
        foreach ($rows as $row) {
            $cid = (string) ($row['outlook_calendar_id'] ?? '');
            // Read new column names, fall back to legacy `tec_*` for a release
            // in case the migration MU-plugin hasn't run yet.
            $cat_id   = $row['category_id']   ?? $row['tec_category_id']   ?? null;
            $cat_name = $row['category_name'] ?? $row['tec_category_name'] ?? '';
            $out[] = array(
                'id'             => (int) $row['id'],
                'calendar_id'    => $cid,
                'name'           => (string) ($row['outlook_calendar_name'] ?? ''),
                'category_id'    => $cat_id !== null ? (int) $cat_id : null,
                'category_name'  => (string) $cat_name,
                'sync_enabled'   => !empty($row['sync_enabled']),
                'last_sync'      => $row['last_sync'] ?? null,
                'event_count'    => $count_by_id[$cid] ?? 0,
            );
        }
        return rest_ensure_response($out);
    }

    public function get_events(WP_REST_Request $req) {
        $from         = (string) $req->get_param('from');
        $to           = (string) $req->get_param('to');
        $calendar_ids = (string) $req->get_param('calendar_ids');
        $per_page     = (int) $req->get_param('per_page');
        if ($per_page <= 0 || $per_page > 500) $per_page = 200;

        $from_ts = $from !== '' ? strtotime($from) : strtotime('-7 days');
        $to_ts   = $to   !== '' ? strtotime($to)   : strtotime('+90 days');
        if ($from_ts === false || $to_ts === false || $to_ts < $from_ts) {
            return new WP_Error('ptsa_events_bad_range', 'Invalid from/to date range.', array('status' => 400));
        }

        $post_type = 'pta_event';

        $meta_query = array(
            'relation' => 'AND',
            array(
                'key'     => '_EventStartDate',
                'value'   => date('Y-m-d H:i:s', $to_ts),
                'compare' => '<=',
                'type'    => 'DATETIME',
            ),
            array(
                'key'     => '_EventEndDate',
                'value'   => date('Y-m-d H:i:s', $from_ts),
                'compare' => '>=',
                'type'    => 'DATETIME',
            ),
        );

        $ids_filter = array();
        if ($calendar_ids !== '') {
            $ids_filter = array_values(array_filter(array_map('trim', explode(',', $calendar_ids))));
        }
        if (!empty($ids_filter)) {
            $meta_query[] = array(
                'key'     => '_outlook_calendar_id',
                'value'   => $ids_filter,
                'compare' => 'IN',
            );
        }

        $q = new WP_Query(array(
            'post_type'      => $post_type,
            'post_status'    => array('publish', 'private'),
            'posts_per_page' => $per_page,
            'meta_query'     => $meta_query,
            'meta_key'       => '_EventStartDate',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));

        // Pre-fetch calendar id → name map.
        global $wpdb;
        $table = $wpdb->prefix . 'azure_calendar_mappings';
        $name_rows = $wpdb->get_results("SELECT outlook_calendar_id, outlook_calendar_name FROM $table", ARRAY_A);
        $name_by_id = array();
        foreach ((array) $name_rows as $r) {
            $name_by_id[(string) $r['outlook_calendar_id']] = (string) $r['outlook_calendar_name'];
        }

        $out = array();
        foreach ($q->posts as $post) {
            $cid = (string) get_post_meta($post->ID, '_outlook_calendar_id', true);
            $start = (string) get_post_meta($post->ID, '_EventStartDate', true);
            $end   = (string) get_post_meta($post->ID, '_EventEndDate', true);
            $venue = (string) get_post_meta($post->ID, '_EventVenue', true);
            if ($venue === '') {
                $venue_id = (int) get_post_meta($post->ID, '_EventVenueID', true);
                if ($venue_id) {
                    $venue_post = get_post($venue_id);
                    if ($venue_post) $venue = $venue_post->post_title;
                }
            }
            $all_day = (string) get_post_meta($post->ID, '_EventAllDay', true) === 'yes';
            $excerpt = wp_strip_all_tags(has_excerpt($post) ? get_the_excerpt($post) : $post->post_content);
            if (strlen($excerpt) > 240) $excerpt = substr($excerpt, 0, 237) . '...';

            $out[] = array(
                'id'             => (int) $post->ID,
                'subject'        => (string) get_the_title($post),
                'body_preview'   => $excerpt,
                'permalink'      => get_permalink($post),
                'start'          => $this->to_iso8601($start),
                'end'            => $this->to_iso8601($end),
                'all_day'        => $all_day,
                'location'       => $venue,
                'calendar_id'    => $cid,
                'calendar_name'  => $name_by_id[$cid] ?? '',
                'outlook_event_id' => (string) get_post_meta($post->ID, '_outlook_event_id', true),
            );
        }
        return rest_ensure_response($out);
    }

    private function to_iso8601($mysql_dt) {
        if (!is_string($mysql_dt) || $mysql_dt === '') return null;
        $ts = strtotime($mysql_dt);
        if ($ts === false) return null;
        // pta_event stores dates in the site's timezone; emit as ISO with offset.
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(wp_timezone());
        return $dt->format(DateTime::ATOM);
    }

    /* =================================================================
     * WooCommerce: orders
     * ================================================================= */

    public function list_orders(WP_REST_Request $req) {
        if (!$err = $this->require_wc()) return $this->forbidden();
        $args = array(
            'limit'   => max(1, min(100, (int) ($req->get_param('per_page') ?: 25))),
            'paged'   => max(1, (int) ($req->get_param('page') ?: 1)),
            'type'    => 'shop_order',
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        $status = (string) $req->get_param('status');
        if ($status !== '' && $status !== 'any') $args['status'] = $status;
        $search = (string) $req->get_param('search');
        if ($search !== '') $args['s'] = $search;

        $orders = wc_get_orders($args);
        $out = array();
        foreach ($orders as $o) {
            $row = $this->order_to_array($o);
            if ($row !== null) $out[] = $row;
        }
        return rest_ensure_response($out);
    }

    public function get_order(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $id = (int) $req['id'];
        $o = wc_get_order($id);
        if (!$o) return new WP_Error('ptsa_order_not_found', "Order $id not found", array('status' => 404));
        return rest_ensure_response($this->order_to_array($o));
    }

    public function update_order(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $id = (int) $req['id'];
        $o = wc_get_order($id);
        if (!$o) return new WP_Error('ptsa_order_not_found', "Order $id not found", array('status' => 404));

        $body = $req->get_json_params();
        if (!is_array($body)) $body = array();
        if (isset($body['status']) && is_string($body['status'])) {
            $o->update_status(sanitize_text_field($body['status']));
        }
        $o->save();
        return rest_ensure_response($this->order_to_array(wc_get_order($id)));
    }

    public function refund_order(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $id = (int) $req['id'];
        $o = wc_get_order($id);
        if (!$o) return new WP_Error('ptsa_order_not_found', "Order $id not found", array('status' => 404));

        $body = $req->get_json_params() ?: array();
        $amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;
        $reason = isset($body['reason']) ? sanitize_text_field((string) $body['reason']) : '';
        $api    = isset($body['api_refund']) ? (bool) $body['api_refund'] : true;
        if ($amount <= 0) {
            return new WP_Error('ptsa_refund_bad_amount', 'amount must be > 0', array('status' => 400));
        }
        $refund = wc_create_refund(array(
            'amount'         => $amount,
            'reason'         => $reason,
            'order_id'       => $o->get_id(),
            'refund_payment' => $api,
        ));
        if (is_wp_error($refund)) return $refund;
        return rest_ensure_response(array(
            'id'     => $refund->get_id(),
            'amount' => (float) $refund->get_amount(),
            'reason' => $refund->get_reason(),
        ));
    }

    public function add_order_note(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $id = (int) $req['id'];
        $o = wc_get_order($id);
        if (!$o) return new WP_Error('ptsa_order_not_found', "Order $id not found", array('status' => 404));
        $body = $req->get_json_params() ?: array();
        $note = isset($body['note']) ? (string) $body['note'] : '';
        $customer_visible = !empty($body['customer_note']);
        if (trim($note) === '') {
            return new WP_Error('ptsa_note_empty', 'note required', array('status' => 400));
        }
        $note_id = $o->add_order_note(wp_kses_post($note), $customer_visible ? 1 : 0);
        return rest_ensure_response(array('id' => (int) $note_id));
    }

    private function order_to_array($o) {
        if (!$o) return null;
        // wc_get_orders() can return WC_Order_Refund objects unless the query
        // is constrained to shop_order. Refunds do not implement the full
        // order API (for example get_order_number()), so never serialize them
        // through this order endpoint.
        if (function_exists('wc_get_order_types') && method_exists($o, 'get_type')) {
            if ($o->get_type() !== 'shop_order') return null;
        }
        $items = array();
        foreach ($o->get_items() as $item) {
            $items[] = array(
                'id'         => (int) $item->get_id(),
                'name'       => $item->get_name(),
                'quantity'   => (int) $item->get_quantity(),
                'subtotal'   => (float) $item->get_subtotal(),
                'total'      => (float) $item->get_total(),
                'product_id' => (int) $item->get_product_id(),
            );
        }
        return array(
            'id'              => (int) $o->get_id(),
            'number'          => $o->get_order_number(),
            'status'          => $o->get_status(),
            'currency'        => $o->get_currency(),
            'date_created'    => $o->get_date_created() ? $o->get_date_created()->format(DateTime::ATOM) : null,
            'total'           => (float) $o->get_total(),
            'subtotal'        => (float) $o->get_subtotal(),
            'total_tax'       => (float) $o->get_total_tax(),
            'shipping_total'  => (float) $o->get_shipping_total(),
            'payment_method'  => $o->get_payment_method_title(),
            'customer_id'     => (int) $o->get_customer_id(),
            'customer_email'  => $o->get_billing_email(),
            'customer_name'   => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
            'customer_note'   => $o->get_customer_note(),
            'items'           => $items,
        );
    }

    /* =================================================================
     * WooCommerce: products
     * ================================================================= */

    public function list_products(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $args = array(
            'limit'   => max(1, min(100, (int) ($req->get_param('per_page') ?: 50))),
            'page'    => max(1, (int) ($req->get_param('page') ?: 1)),
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        $search = (string) $req->get_param('search');
        if ($search !== '') $args['s'] = $search;
        $type = (string) $req->get_param('type');
        if ($type !== '' && $type !== 'any') $args['type'] = $type;
        $status = (string) $req->get_param('status');
        if ($status !== '' && $status !== 'any') $args['status'] = $status;

        $products = wc_get_products($args);
        $out = array();
        foreach ($products as $p) $out[] = $this->product_to_array($p);
        return rest_ensure_response($out);
    }

    public function create_product(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $body = $req->get_json_params() ?: array();
        $type = isset($body['type']) ? sanitize_text_field((string) $body['type']) : 'simple';
        $class = 'WC_Product_' . ucfirst($type);
        if (!class_exists($class)) $class = 'WC_Product_Simple';
        $p = new $class();
        $this->apply_product_patch($p, $body);
        $p->save();
        if (isset($body['auction']) && is_array($body['auction'])) {
            $p = wc_get_product($p->get_id());
            $this->apply_auction_patch($p, $body['auction']);
            $p->save();
        }
        return rest_ensure_response($this->product_to_array(wc_get_product($p->get_id())));
    }

    public function update_product(WP_REST_Request $req) {
        if (!$this->require_wc()) return $this->forbidden();
        $id = (int) $req['id'];
        $p = wc_get_product($id);
        if (!$p) return new WP_Error('ptsa_product_not_found', "Product $id not found", array('status' => 404));
        $body = $req->get_json_params() ?: array();
        $this->apply_product_patch($p, $body);
        $p->save();
        return rest_ensure_response($this->product_to_array(wc_get_product($id)));
    }

    private function apply_product_patch($p, array $body) {
        if (isset($body['name']))         $p->set_name(sanitize_text_field((string) $body['name']));
        if (isset($body['description']))  $p->set_description(wp_kses_post((string) $body['description']));
        if (isset($body['short_description'])) $p->set_short_description(wp_kses_post((string) $body['short_description']));
        if (isset($body['sku']))          $p->set_sku(sanitize_text_field((string) $body['sku']));
        if (isset($body['regular_price'])) $p->set_regular_price((string) $body['regular_price']);
        if (isset($body['sale_price']))    $p->set_sale_price((string) $body['sale_price']);
        if (isset($body['status']))       $p->set_status(sanitize_text_field((string) $body['status']));
        if (isset($body['stock_quantity'])) $p->set_stock_quantity((int) $body['stock_quantity']);
        if (isset($body['manage_stock']))  $p->set_manage_stock((bool) $body['manage_stock']);
        if (isset($body['image_ids']) && is_array($body['image_ids'])) {
            $ids = array_map('intval', $body['image_ids']);
            if (!empty($ids)) {
                $p->set_image_id((int) $ids[0]);
                if (count($ids) > 1) $p->set_gallery_image_ids(array_slice($ids, 1));
            }
        }
        if (isset($body['images']) && is_array($body['images'])) {
            $ids = array();
            foreach ($body['images'] as $image) {
                if (!is_array($image)) continue;
                $id = isset($image['id']) ? (int) $image['id'] : 0;
                if (!$id && !empty($image['src']) && function_exists('attachment_url_to_postid')) {
                    $id = (int) attachment_url_to_postid((string) $image['src']);
                }
                if ($id > 0) $ids[] = $id;
            }
            if (!empty($ids)) {
                $p->set_image_id((int) $ids[0]);
                $p->set_gallery_image_ids(array_slice($ids, 1));
            }
        }
        if ($p->get_id() && isset($body['auction']) && is_array($body['auction'])) {
            $this->apply_auction_patch($p, $body['auction']);
        }
    }

    private function product_to_array($p) {
        if (!$p) return null;
        $images = $this->product_images($p);
        $img = !empty($images) ? (string) ($images[0]['src'] ?? '') : '';
        return array(
            'id'             => (int) $p->get_id(),
            'name'           => $p->get_name(),
            'type'           => $p->get_type(),
            'status'         => $p->get_status(),
            'sku'            => $p->get_sku(),
            'price'          => (float) $p->get_price(),
            'regular_price'  => $p->get_regular_price(),
            'sale_price'     => $p->get_sale_price(),
            'stock_quantity' => $p->get_stock_quantity(),
            'manage_stock'   => $p->get_manage_stock(),
            'image'          => $img,
            'images'         => $images,
            'auction'        => $this->auction_to_array($p),
            'permalink'      => get_permalink($p->get_id()),
            'short_description' => $p->get_short_description(),
        );
    }

    private function product_images($p) {
        $ids = array();
        if ($p->get_image_id()) $ids[] = (int) $p->get_image_id();
        foreach ((array) $p->get_gallery_image_ids() as $gid) {
            $gid = (int) $gid;
            if ($gid > 0 && !in_array($gid, $ids, true)) $ids[] = $gid;
        }
        $out = array();
        foreach ($ids as $id) {
            $src = wp_get_attachment_image_url($id, 'medium');
            if (!$src) $src = wp_get_attachment_url($id);
            if (!$src) continue;
            $out[] = array(
                'id'   => $id,
                'src'  => (string) $src,
                'name' => get_the_title($id),
                'alt'  => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            );
        }
        return $out;
    }

    private function auction_to_array($p) {
        if (!$p || $p->get_type() !== 'auction') return null;
        $id = (int) $p->get_id();
        return array(
            'starting_bid'                  => (string) get_post_meta($id, '_regular_price', true),
            'bidding_end'                   => (string) get_post_meta($id, '_auction_bidding_end', true),
            'buy_it_now_enabled'            => get_post_meta($id, '_auction_buy_it_now_enabled', true) === 'yes',
            'buy_it_now_price'              => (string) get_post_meta($id, '_auction_buy_it_now_price', true),
            'buy_it_now_pay_immediately'    => get_post_meta($id, '_auction_buy_it_now_pay_immediately', true) === 'yes',
            'status'                        => (string) get_post_meta($id, '_auction_status', true),
        );
    }

    private function apply_auction_patch($p, array $auction) {
        $id = (int) $p->get_id();
        if (isset($auction['starting_bid'])) {
            $value = $auction['starting_bid'] === '' ? '' : wc_format_decimal((string) $auction['starting_bid']);
            $p->set_regular_price($value);
        }
        if (isset($auction['bidding_end'])) {
            update_post_meta($id, '_auction_bidding_end', sanitize_text_field((string) $auction['bidding_end']));
        }
        if (isset($auction['buy_it_now_enabled'])) {
            update_post_meta($id, '_auction_buy_it_now_enabled', !empty($auction['buy_it_now_enabled']) ? 'yes' : 'no');
        }
        if (isset($auction['buy_it_now_price'])) {
            update_post_meta($id, '_auction_buy_it_now_price', wc_format_decimal((string) $auction['buy_it_now_price']));
        }
        if (isset($auction['buy_it_now_pay_immediately'])) {
            update_post_meta($id, '_auction_buy_it_now_pay_immediately', !empty($auction['buy_it_now_pay_immediately']) ? 'yes' : 'no');
        }
    }

    /* =================================================================
     * Media (product image upload)
     * ================================================================= */

    public function upload_media(WP_REST_Request $req) {
        if (!current_user_can('upload_files')) return $this->forbidden();
        $body = $req->get_body();
        if (!is_string($body) || strlen($body) < 16) {
            return new WP_Error('ptsa_media_empty', 'Request body is empty.', array('status' => 400));
        }
        $disposition = (string) $req->get_header('content-disposition');
        $filename = 'upload.jpg';
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $filename = sanitize_file_name($m[1]);
        }
        $upload = wp_upload_bits($filename, null, $body);
        if (!empty($upload['error'])) {
            return new WP_Error('ptsa_media_save_failed', $upload['error'], array('status' => 500));
        }
        $filetype = wp_check_filetype($upload['file']);
        $attach = array(
            'guid'           => $upload['url'],
            'post_mime_type' => $filetype['type'] ?: ((string) $req->get_header('content-type') ?: 'image/jpeg'),
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment($attach, $upload['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $meta);
        return rest_ensure_response(array(
            'id'  => (int) $attach_id,
            'src' => (string) $upload['url'],
        ));
    }

    /* =================================================================
     * Users
     * ================================================================= */

    public function list_users(WP_REST_Request $req) {
        if (!current_user_can('list_users')) return $this->forbidden();
        $args = array(
            'number'  => max(1, min(100, (int) ($req->get_param('per_page') ?: 25))),
            'paged'   => max(1, (int) ($req->get_param('page') ?: 1)),
            'orderby' => 'display_name',
            'order'   => 'ASC',
        );
        $search = (string) $req->get_param('search');
        if ($search !== '') $args['search'] = '*' . esc_attr($search) . '*';

        $q = new WP_User_Query($args);
        $out = array();
        foreach ($q->get_results() as $u) $out[] = $this->user_to_array($u);
        return rest_ensure_response($out);
    }

    public function get_user(WP_REST_Request $req) {
        if (!current_user_can('list_users')) return $this->forbidden();
        $id = (int) $req['id'];
        $u = get_user_by('id', $id);
        if (!$u) return new WP_Error('ptsa_user_not_found', "User $id not found", array('status' => 404));
        return rest_ensure_response($this->user_to_array($u));
    }

    public function update_user(WP_REST_Request $req) {
        if (!current_user_can('edit_users')) return $this->forbidden();
        $id = (int) $req['id'];
        $u = get_user_by('id', $id);
        if (!$u) return new WP_Error('ptsa_user_not_found', "User $id not found", array('status' => 404));
        $body = $req->get_json_params() ?: array();
        if (isset($body['roles']) && is_array($body['roles'])) {
            $valid_roles = function_exists('wp_roles') ? array_keys((array) wp_roles()->roles) : array();
            $requested_roles = array();
            foreach ($body['roles'] as $role) {
                $slug = sanitize_key((string) $role);
                if ($slug !== '') $requested_roles[] = $slug;
            }
            $invalid_roles = array_values(array_diff($requested_roles, $valid_roles));
            if (!empty($invalid_roles)) {
                return new WP_Error(
                    'ptsa_user_invalid_roles',
                    'One or more roles are not assignable: ' . implode(', ', $invalid_roles),
                    array('status' => 400)
                );
            }
            $u->set_role(''); // clear
            foreach ($requested_roles as $role) {
                $u->add_role($role);
            }
        }
        return rest_ensure_response($this->user_to_array(get_user_by('id', $id)));
    }

    public function reset_password_for(WP_REST_Request $req) {
        if (!current_user_can('edit_users')) return $this->forbidden();
        $body = $req->get_json_params() ?: array();
        $email = isset($body['email']) ? sanitize_email((string) $body['email']) : '';
        if ($email === '') return new WP_Error('ptsa_reset_bad_email', 'email required', array('status' => 400));
        $u = get_user_by('email', $email);
        if (!$u) return new WP_Error('ptsa_reset_no_user', 'No user with that email', array('status' => 404));
        $ok = retrieve_password($u->user_login);
        if (is_wp_error($ok)) return $ok;
        return rest_ensure_response(array('ok' => true));
    }

    public function reset_password_self(WP_REST_Request $req) {
        $u = $this->caller;
        if (!$u) return $this->forbidden();
        $ok = retrieve_password($u->user_login);
        if (is_wp_error($ok)) return $ok;
        return rest_ensure_response(array('ok' => true));
    }

    private function user_to_array($u) {
        if (!$u) return null;
        $first = (string) get_user_meta($u->ID, 'first_name', true);
        $last  = (string) get_user_meta($u->ID, 'last_name', true);
        $display = (string) $u->display_name;
        if ($display === '' && ($first !== '' || $last !== '')) {
            $display = trim($first . ' ' . $last);
        }
        return array(
            'id'             => (int) $u->ID,
            'email'          => $u->user_email,
            'display_name'   => $display,
            'name'           => $display,
            'username'       => $u->user_login,
            'first_name'     => $first,
            'last_name'      => $last,
            'roles'          => array_values($u->roles),
            'registered_date'=> $u->user_registered,
        );
    }

    public function list_wp_roles(WP_REST_Request $req) {
        if (!current_user_can('promote_users') && !current_user_can('edit_users')) {
            return $this->forbidden();
        }
        if (!function_exists('wp_roles')) {
            return rest_ensure_response(array());
        }
        $out = array();
        foreach ((array) wp_roles()->roles as $slug => $role) {
            $out[] = array(
                'slug' => sanitize_key($slug),
                'name' => translate_user_role((string) ($role['name'] ?? $slug)),
            );
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return rest_ensure_response($out);
    }

    public function get_pta_roles_org(WP_REST_Request $req) {
        if (!current_user_can('list_users')) return $this->forbidden();
        $manager = $this->pta_manager();
        if (!$manager) return new WP_Error('ptsa_pta_manager_missing', 'PTA roles manager is unavailable.', array('status' => 500));

        $departments = $manager->get_departments(true);
        $users = array();
        $department_out = array();
        foreach ((array) $departments as $dept) {
            $roles = array();
            foreach ((array) ($dept->roles ?? array()) as $role) {
                $assignments = array();
                foreach ((array) ($role->assignments ?? array()) as $assignment) {
                    $user = get_user_by('id', (int) $assignment->user_id);
                    if ($user && !isset($users[$user->ID])) {
                        $users[$user->ID] = $this->pta_user_identity($user);
                    }
                    $assignments[] = array(
                        'id'         => (int) $assignment->id,
                        'role_id'    => (int) $assignment->role_id,
                        'user_id'    => (int) $assignment->user_id,
                        'is_primary' => !empty($assignment->is_primary),
                        'status'     => (string) ($assignment->status ?? 'active'),
                        'user'       => $user ? $this->pta_user_identity($user) : null,
                    );
                }
                $max = (int) ($role->max_occupants ?? 1);
                $assigned = count($assignments);
                $roles[] = array(
                    'id'             => (int) $role->id,
                    'department_id'  => (int) $role->department_id,
                    'name'           => (string) $role->name,
                    'slug'           => (string) ($role->slug ?? ''),
                    'description'    => (string) ($role->description ?? ''),
                    'max_occupants'  => $max,
                    'assigned_count' => $assigned,
                    'vacancy_count'  => max(0, $max - $assigned),
                    'status'         => (string) ($role->status ?? ''),
                    'assignments'    => $assignments,
                );
            }
            $vp = !empty($dept->vp_user_id) ? get_user_by('id', (int) $dept->vp_user_id) : null;
            if ($vp && !isset($users[$vp->ID])) {
                $users[$vp->ID] = $this->pta_user_identity($vp);
            }
            $department_out[] = array(
                'id'          => (int) $dept->id,
                'name'        => (string) $dept->name,
                'slug'        => (string) ($dept->slug ?? ''),
                'description' => (string) ($dept->description ?? ''),
                'vp_user_id'  => !empty($dept->vp_user_id) ? (int) $dept->vp_user_id : null,
                'vp_user'     => $vp ? $this->pta_user_identity($vp) : null,
                'roles'       => $roles,
            );
        }

        return rest_ensure_response(array(
            'departments' => $department_out,
            'users'       => array_values($users),
        ));
    }

    public function create_pta_role_assignment(WP_REST_Request $req) {
        if (!current_user_can('edit_users')) return $this->forbidden();
        $manager = $this->pta_manager();
        if (!$manager) return new WP_Error('ptsa_pta_manager_missing', 'PTA roles manager is unavailable.', array('status' => 500));
        $body = $req->get_json_params() ?: array();
        $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        $role_id = isset($body['role_id']) ? (int) $body['role_id'] : 0;
        $is_primary = !empty($body['is_primary']);
        if ($user_id <= 0 || $role_id <= 0) {
            return new WP_Error('ptsa_assignment_bad_request', 'user_id and role_id are required.', array('status' => 400));
        }
        try {
            $assignment_id = $manager->assign_user_to_role($user_id, $role_id, $is_primary, get_current_user_id());
            return rest_ensure_response(array('ok' => true, 'id' => (int) $assignment_id));
        } catch (Exception $e) {
            return new WP_Error('ptsa_assignment_failed', $e->getMessage(), array('status' => 400));
        }
    }

    public function delete_pta_role_assignment(WP_REST_Request $req) {
        if (!current_user_can('edit_users')) return $this->forbidden();
        $manager = $this->pta_manager();
        if (!$manager) return new WP_Error('ptsa_pta_manager_missing', 'PTA roles manager is unavailable.', array('status' => 500));
        $assignment = $this->pta_assignment_by_id((int) $req['id']);
        if (!$assignment) {
            return new WP_Error('ptsa_assignment_not_found', 'Assignment not found.', array('status' => 404));
        }
        try {
            $manager->remove_user_from_role((int) $assignment->user_id, (int) $assignment->role_id);
            return rest_ensure_response(array('ok' => true));
        } catch (Exception $e) {
            return new WP_Error('ptsa_assignment_remove_failed', $e->getMessage(), array('status' => 400));
        }
    }

    private function pta_manager() {
        if (!class_exists('Azure_PTA_Manager')) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-pta-manager.php';
            if (file_exists($path)) require_once $path;
        }
        if (!class_exists('Azure_PTA_Manager')) return null;
        return Azure_PTA_Manager::get_instance();
    }

    private function pta_user_identity($u) {
        return array(
            'id'           => (int) $u->ID,
            'email'        => $u->user_email,
            'display_name' => $u->display_name,
            'username'     => $u->user_login,
        );
    }

    private function pta_assignment_by_id($id) {
        global $wpdb;
        if ($id <= 0 || !class_exists('Azure_PTA_Database')) return null;
        $table = Azure_PTA_Database::get_table_name('assignments');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'active'", $id));
    }

    /* =================================================================
     * Tech backlog (todos) — uses wp_options for tiny payload, no schema
     * ================================================================= */

    const TODO_OPT = 'ptsa_rest_todos_v1';

    public function list_todos(WP_REST_Request $req) {
        $items = get_option(self::TODO_OPT, array());
        if (!is_array($items)) $items = array();
        usort($items, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });
        return rest_ensure_response(array_values($items));
    }

    public function create_todo(WP_REST_Request $req) {
        $u = $this->caller;
        $body = $req->get_json_params() ?: array();
        $title = isset($body['title']) ? sanitize_text_field((string) $body['title']) : '';
        if ($title === '') return new WP_Error('ptsa_todo_empty', 'title required', array('status' => 400));
        $items = get_option(self::TODO_OPT, array());
        if (!is_array($items)) $items = array();
        $id = (int) (microtime(true) * 1000);
        $item = array(
            'id'         => $id,
            'title'      => $title,
            'details'    => isset($body['details']) ? wp_kses_post((string) $body['details']) : (isset($body['notes']) ? wp_kses_post((string) $body['notes']) : ''),
            'due_date'   => isset($body['dueDate']) ? sanitize_text_field((string) $body['dueDate']) : (isset($body['due_date']) ? sanitize_text_field((string) $body['due_date']) : null),
            'priority'   => isset($body['priority']) ? sanitize_key((string) $body['priority']) : 'normal',
            'completed'  => false,
            'created_at' => current_time('c'),
            'created_by_email' => $u ? $u->user_email : '',
            'created_by_name'  => $u ? $u->display_name : '',
        );
        $github = $this->create_github_issue_for_todo($item);
        if (is_wp_error($github)) {
            $item['github_issue_error'] = $github->get_error_message();
        } elseif (is_array($github)) {
            $item = array_merge($item, $github);
        }
        $items[] = $item;
        update_option(self::TODO_OPT, $items, false);
        return rest_ensure_response($item);
    }

    public function update_todo(WP_REST_Request $req) {
        $id = (int) $req['id'];
        $body = $req->get_json_params() ?: array();
        $items = get_option(self::TODO_OPT, array());
        if (!is_array($items)) $items = array();
        $found = false;
        foreach ($items as &$it) {
            if ((int) ($it['id'] ?? 0) === $id) {
                if (isset($body['title']))     $it['title']     = sanitize_text_field((string) $body['title']);
                if (isset($body['details']))   $it['details']   = wp_kses_post((string) $body['details']);
                if (isset($body['notes']))     $it['details']   = wp_kses_post((string) $body['notes']);
                if (isset($body['dueDate']))   $it['due_date']  = sanitize_text_field((string) $body['dueDate']);
                if (isset($body['due_date']))  $it['due_date']  = sanitize_text_field((string) $body['due_date']);
                if (isset($body['priority']))  $it['priority']  = sanitize_key((string) $body['priority']);
                if (isset($body['completed'])) $it['completed'] = (bool) $body['completed'];
                if (isset($body['completedAt'])) $it['completed_at'] = sanitize_text_field((string) $body['completedAt']);
                if (isset($body['completedByEmail'])) $it['completed_by_email'] = sanitize_email((string) $body['completedByEmail']);
                $found = true; break;
            }
        }
        if (!$found) return new WP_Error('ptsa_todo_not_found', "Todo $id not found", array('status' => 404));
        update_option(self::TODO_OPT, $items, false);
        return rest_ensure_response($it);
    }

    public function delete_todo(WP_REST_Request $req) {
        $id = (int) $req['id'];
        $items = get_option(self::TODO_OPT, array());
        if (!is_array($items)) $items = array();
        $new = array();
        foreach ($items as $it) {
            if ((int) ($it['id'] ?? 0) !== $id) $new[] = $it;
        }
        update_option(self::TODO_OPT, array_values($new), false);
        return rest_ensure_response(array('ok' => true));
    }

    private function create_github_issue_for_todo(array $item) {
        $token = getenv('PTSA_GITHUB_TOKEN');
        if (!is_string($token) || $token === '') {
            $token = (string) get_option('ptsa_github_token', '');
        }
        if ($token === '') {
            return new WP_Error('ptsa_github_not_configured', 'PTSA_GITHUB_TOKEN is not configured.');
        }
        $title = 'iOS app - ' . (string) ($item['title'] ?? 'Backlog item');
        $body = "Created from the PTATools iOS app.\n\n";
        if (!empty($item['details'])) {
            $body .= (string) $item['details'] . "\n\n";
        }
        $body .= '- Priority: ' . (string) ($item['priority'] ?? 'normal') . "\n";
        if (!empty($item['due_date'])) {
            $body .= '- Due: ' . (string) $item['due_date'] . "\n";
        }
        if (!empty($item['created_by_email'])) {
            $body .= '- Created by: ' . (string) $item['created_by_email'] . "\n";
        }
        $response = wp_remote_post('https://api.github.com/repos/jaburges/PTATools/issues', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'PTATools-iOS-Backlog',
                'X-GitHub-Api-Version' => '2022-11-28',
            ),
            'body' => wp_json_encode(array(
                'title' => $title,
                'body'  => $body,
            )),
        ));
        if (is_wp_error($response)) return $response;
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return new WP_Error('ptsa_github_issue_failed', 'GitHub issue creation failed with HTTP ' . $code);
        }
        return array(
            'github_issue_number' => isset($data['number']) ? (int) $data['number'] : null,
            'github_issue_url'    => isset($data['html_url']) ? esc_url_raw((string) $data['html_url']) : null,
            'github_issue_state'  => isset($data['state']) ? sanitize_text_field((string) $data['state']) : null,
        );
    }

    /* =================================================================
     * Orders Reports — saved report list + mobile export
     * ================================================================= */

    public function list_orders_reports(WP_REST_Request $req) {
        if (!$this->require_orders_reports_cap()) {
            return $this->forbidden();
        }
        if (!$this->load_orders_reports_dependencies()) {
            return new WP_Error('ptsa_orders_reports_unavailable', 'Orders Reports module is unavailable.', array('status' => 503));
        }

        $out = array();
        foreach (Azure_Orders_Reports_Storage::list_all() as $r) {
            $out[] = array(
                'id'                 => (int) $r['id'],
                'name'               => (string) $r['name'],
                'modified'           => (string) $r['modified'],
                'last_exported_at'   => !empty($r['last_exported_at']) ? (string) $r['last_exported_at'] : null,
                'last_exported_rows' => (int) $r['last_exported_rows'],
            );
        }
        return rest_ensure_response($out);
    }

    public function export_orders_report(WP_REST_Request $req) {
        if (!$this->require_orders_reports_cap()) {
            return $this->forbidden();
        }
        if (!$this->load_orders_reports_dependencies()) {
            return new WP_Error('ptsa_orders_reports_unavailable', 'Orders Reports module is unavailable.', array('status' => 503));
        }

        $report_id = (int) $req['id'];
        $loaded = Azure_Orders_Reports_Storage::load($report_id);
        if (!$loaded) {
            return new WP_Error('ptsa_orders_report_not_found', "Report $report_id not found.", array('status' => 404));
        }

        $config = $this->orders_report_config_as_of_today($loaded['config']);
        $query  = new Azure_Orders_Reports_Query();
        $rows   = $query->count($config);

        // Build the CSV in a buffer first so we know the byte length and so
        // a mid-stream failure can't emit a half-written file.
        $temp = fopen('php://temp', 'w+');
        if ($temp === false) {
            return new WP_Error('ptsa_orders_report_export_failed', 'Could not open export buffer.', array('status' => 500));
        }
        $exporter = new Azure_Orders_Reports_Export();
        $exporter->write_to_handle($config, $temp);
        rewind($temp);
        $content = stream_get_contents($temp);
        fclose($temp);
        if (!is_string($content)) {
            return new WP_Error('ptsa_orders_report_export_failed', 'Could not read export buffer.', array('status' => 500));
        }

        Azure_Orders_Reports_Storage::mark_exported($report_id, $rows);

        // IMPORTANT: do NOT return a WP_REST_Response with the CSV as its body.
        // The REST stack JSON-encodes every response body, so the client would
        // receive the CSV as a single escaped JSON string (one broken row in
        // Excel). Instead emit the raw bytes and exit, exactly like the web
        // admin-post export does, bypassing JSON serialization entirely.
        $safe_name = sanitize_file_name($loaded['name'] . '-' . gmdate('Ymd-His') . '.xls');

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        // Drop any output buffers WP/REST may have opened so nothing is
        // prepended to the binary stream.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header(200);
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('Content-Length: ' . strlen($content));
        header('X-Export-Rows: ' . (int) $rows);
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }

    private function require_orders_reports_cap() {
        return current_user_can('manage_woocommerce');
    }

    private function load_orders_reports_dependencies() {
        $files = array(
            'class-orders-reports-cpt.php',
            'class-orders-reports-columns.php',
            'class-orders-reports-query.php',
            'class-orders-reports-export.php',
            'class-orders-reports-storage.php',
        );
        foreach ($files as $f) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $f;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Azure_Orders_Reports_Storage')
            && class_exists('Azure_Orders_Reports_Export')
            && class_exists('Azure_Orders_Reports_Query');
    }

    /**
     * Match the dashboard widget's `run_as_of=today` behaviour: honour
     * saved presets/filters but force the end boundary to right-now.
     */
    private function orders_report_config_as_of_today(array $config) {
        if (!isset($config['date_range']) || !is_array($config['date_range'])) {
            $config['date_range'] = array(
                'from'     => null,
                'to'       => null,
                'preset'   => null,
                'to_today' => true,
            );
        } else {
            $config['date_range']['to_today'] = true;
        }
        return $config;
    }

    /* =================================================================
     * Auction email items (best-effort: delegates if module is loaded)
     * ================================================================= */

    public function auction_email_items(WP_REST_Request $req) {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return $this->forbidden();
        }
        $body = $req->get_json_params() ?: array();
        $to = isset($body['to']) && is_array($body['to']) ? array_values(array_filter(array_map('sanitize_email', $body['to']))) : array();
        if (empty($to)) return new WP_Error('ptsa_email_no_recipients', 'to[] required', array('status' => 400));
        $subject = isset($body['subject']) && is_string($body['subject']) ? sanitize_text_field($body['subject']) : 'Wilder PTSA Auction — items list';

        $html = '<p>Latest auction items list.</p>';
        // Defer to the auction emails module if it exposes a renderer.
        if (class_exists('Azure_Auction_Emails')) {
            $maybe = Azure_Auction_Emails::get_instance();
            if (is_object($maybe) && method_exists($maybe, 'render_items_email_html')) {
                $html = (string) $maybe->render_items_email_html();
            }
        }
        $ok = wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
        return rest_ensure_response(array('ok' => (bool) $ok, 'recipients' => $to));
    }

    /* =================================================================
     * Helpers
     * ================================================================= */

    private function require_wc() {
        return function_exists('wc_get_orders');
    }

    private function forbidden() {
        return new WP_Error('ptsa_rest_forbidden', 'Forbidden for current user.', array('status' => 403));
    }
}
