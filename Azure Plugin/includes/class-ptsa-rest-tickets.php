<?php
/**
 * Door check-in for the PTSA Board app: /wp-json/ptsa/v1/tickets/*
 *
 * Routes are registered by Azure_PTSA_REST_API with its Entra sign-in
 * check, so most callers are signed-in board accounts; anyone who can sign
 * in to the app may scan, with no further capability check.
 *
 * Volunteers without a sign-in exchange a performance's door PIN for a
 * door token (POST /tickets/door) and send it as `Authorization: Door
 * <token>`. That only reaches checkin, lookup and undo, only for that
 * performance, and the answers leave out the counter, seat and who
 * scanned. The performance list and door list stay sign-in only, and the
 * seat map (buyers' contact details) also needs manage_woocommerce.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tickets-door.php';

class Azure_PTSA_REST_Tickets {

    /** Performances that ended longer ago than this drop off the door list. */
    const PAST_GRACE = DAY_IN_SECONDS;

    /** What a door-token caller sees of a ticket. */
    const DOOR_TICKET_FIELDS = array('id', 'code', 'attendee', 'event', 'product_id', 'status', 'checked_in_at', 'checked_in_time');

    /** @var int Performance the current request's door token is for; 0 for signed-in callers. */
    private static $door = 0;

    public static function register($ns, $auth) {
        $door_or_auth = function ($request) use ($auth) {
            return self::authorize_door_or($auth, $request);
        };
        register_rest_route($ns, '/tickets/performances', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'list_performances'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/performances/(?P<id>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'get_performance'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/performances/(?P<id>\d+)/seats', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'get_seats'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/checkin', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'checkin'), 'permission_callback' => $door_or_auth,
        ));
        register_rest_route($ns, '/tickets/lookup', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'lookup'), 'permission_callback' => $door_or_auth,
        ));
        register_rest_route($ns, '/tickets/(?P<id>\d+)/undo', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'undo'), 'permission_callback' => $door_or_auth,
        ));
        register_rest_route($ns, '/tickets/door', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'open_door'), 'permission_callback' => '__return_true',
        ));
    }

    /**
     * A valid `Authorization: Door <token>` lets the request through for its
     * performance; anything else goes to the normal sign-in check.
     */
    public static function authorize_door_or($auth, $request) {
        self::$door = 0;
        $header = method_exists($request, 'get_header') ? (string) $request->get_header('authorization') : '';
        if (stripos($header, 'Door ') !== 0) {
            return call_user_func($auth, $request);
        }
        $id = Azure_Tickets_Door::verify(trim(substr($header, 5)));
        if (!$id) {
            return new WP_Error('ptsa_tickets_door_expired', 'This scanner session has ended. Enter the door PIN again.', array('status' => 401));
        }
        self::$door = $id;
        return true;
    }

    /**
     * Body: {pin}. Answers with a door token for the performance the PIN
     * opens right now, and that performance's name, date and venue.
     */
    public static function open_door(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        if (Azure_Tickets_Door::locked_out()) {
            return new WP_Error('ptsa_tickets_door_locked', 'Too many wrong PINs. Wait 15 minutes, or ask a board member to scan.', array('status' => 429));
        }
        $body = $req->get_json_params() ?: array();
        $id = Azure_Tickets_Door::performance_for_pin((string) ($body['pin'] ?? ''), self::performance_ids());
        $session = $id ? Azure_Tickets_Door::issue($id) : null;
        if (!$session) {
            Azure_Tickets_Door::record_failure();
            return new WP_Error('ptsa_tickets_door_pin', 'That PIN isn\'t open for a performance right now. Check it with a board member.', array('status' => 401));
        }
        $performance = self::performance($id);
        unset($performance['stats'], $performance['door_pin'], $performance['has_seats']);
        return rest_ensure_response(array(
            'token'       => $session['token'],
            'expires_at'  => $session['expires_at'],
            'expires'     => wp_date('c', $session['expires_at']),
            'performance' => $performance,
        ));
    }

    public static function list_performances(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        $cutoff = Azure_Tickets_Config::now() - self::PAST_GRACE;
        $out = array();
        foreach (self::performance_ids() as $performance) {
            $row = self::performance($performance);
            if ($row['starts_at'] && $row['starts_at'] < $cutoff) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            return ($a['starts_at'] ?: PHP_INT_MAX) <=> ($b['starts_at'] ?: PHP_INT_MAX) ?: $a['id'] <=> $b['id'];
        });
        return rest_ensure_response($out);
    }

    /**
     * @return int[] Every performance of every published ticket product.
     */
    private static function performance_ids() {
        $ids = wc_get_products(array(
            'type'   => 'ticket',
            'status' => 'publish',
            'limit'  => 50,
            'return' => 'ids',
        ));
        $out = array();
        foreach ((array) $ids as $id) {
            foreach (Azure_Tickets_Config::performances((int) $id) as $performance) {
                if (!isset($out[$performance]) && Azure_Tickets_Config::is_performance($performance)) {
                    $out[$performance] = (int) $performance;
                }
            }
        }
        return array_values($out);
    }

    public static function get_performance(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        $id = (int) $req['id'];
        if (!Azure_Tickets_Config::is_performance($id)) {
            return self::not_found();
        }
        $row = self::performance($id);
        $row['tickets'] = (new Azure_Tickets_Checkin())->performance_tickets($id);
        return rest_ensure_response($row);
    }

    /**
     * The Visualizer's seat map for one performance, laid out for the app.
     * It shows buyers' names, emails and phone numbers, so like the
     * Visualizer tab it needs manage_woocommerce.
     */
    public static function get_seats(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        if (!class_exists('Azure_Tickets_Visualizer')) {
            require_once __DIR__ . '/class-tickets-visualizer.php';
        }
        if (!current_user_can(Azure_Tickets_Visualizer::CAP)) {
            return new WP_Error('ptsa_tickets_seats_forbidden', 'The seat map shows buyers\' details, so only shop managers and administrators can see it.', array('status' => 403));
        }
        $id = (int) $req['id'];
        if (!Azure_Tickets_Config::is_performance($id)) {
            return self::not_found();
        }
        $snap = Azure_Tickets_Visualizer::snapshot($id);
        if (!$snap) {
            return new WP_Error('ptsa_tickets_no_seats', 'This performance has no seating plan.', array('status' => 404));
        }
        $map = Azure_Tickets_Visualizer::app_map($snap);
        $map['performance'] = self::performance($id);
        return rest_ensure_response($map);
    }

    /**
     * Body: {code: scanned text or typed code, product_id: performance at the door}.
     * Always 200 with the check-in outcome, so the app shows the reason
     * (already used, other night, cancelled) instead of a generic error.
     */
    public static function checkin(WP_REST_Request $req) {
        return self::scan($req, true);
    }

    /**
     * Same body and answers as checkin, without checking in: `valid` for a
     * ticket that would be let in.
     */
    public static function lookup(WP_REST_Request $req) {
        return self::scan($req, false);
    }

    private static function scan(WP_REST_Request $req, $check_in) {
        if ($off = self::module_off()) {
            return $off;
        }
        $body = $req->get_json_params() ?: array();
        $product_id = (int) ($body['product_id'] ?? 0);
        if (!Azure_Tickets_Config::is_performance($product_id)) {
            return new WP_Error('ptsa_tickets_performance', 'Choose a performance first.', array('status' => 400));
        }
        if (self::$door && $product_id !== self::$door) {
            return self::door_scope();
        }
        $code = Azure_Tickets_Module::scanned_code((string) ($body['code'] ?? ''));
        if ($code === '') {
            return rest_ensure_response(array(
                'success' => false,
                'status'  => 'not_found',
                'message' => __('That isn\'t a ticket code', 'azure-plugin'),
            ));
        }
        $checkin = new Azure_Tickets_Checkin();
        $user = self::$door ? 0 : get_current_user_id();
        $result = $check_in ? $checkin->check_in($code, $user, $product_id) : $checkin->look_up($code, $product_id);
        $result['stats'] = self::stats($product_id);
        return rest_ensure_response(self::for_caller($result));
    }

    public static function undo(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        global $wpdb;
        $id = (int) $req['id'];
        if (self::$door) {
            $product_id = (int) $wpdb->get_var($wpdb->prepare("SELECT product_id FROM {$wpdb->prefix}azure_tickets WHERE id = %d", $id));
            if ($product_id !== self::$door) {
                return self::door_scope();
            }
        }
        $checkin = new Azure_Tickets_Checkin();
        $result = $checkin->undo_checkin($id, self::$door ? 0 : get_current_user_id());
        if (empty($result['success'])) {
            return new WP_Error('ptsa_tickets_undo', $result['message'] ?? 'Could not undo.', array('status' => 409));
        }
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}azure_tickets WHERE id = %d", $id));
        $result['ticket'] = $checkin->ticket_payload($ticket);
        $result['stats'] = self::stats((int) $ticket->product_id);
        return rest_ensure_response(self::for_caller($result));
    }

    /**
     * Door-token callers get the outcome, ticket number and name only:
     * no counter, seat or who scanned.
     */
    private static function for_caller(array $result) {
        if (!self::$door) {
            return $result;
        }
        unset($result['stats']);
        if (isset($result['ticket']) && is_array($result['ticket'])) {
            $result['ticket'] = array_intersect_key($result['ticket'], array_flip(self::DOOR_TICKET_FIELDS));
        }
        return $result;
    }

    private static function door_scope() {
        return new WP_Error('ptsa_tickets_door_scope', 'This scanner is only open for one performance.', array('status' => 403));
    }

    /**
     * @return array{id:int,name:string,starts_at:int,starts:string,when:string,venue:string,stats:array{sold:int,checked_in:int},door_pin:string,has_seats:bool}
     */
    public static function performance($product_id) {
        $product = wc_get_product($product_id);
        $ts = Azure_Tickets_Config::event_ts($product_id);
        $venue_id = Azure_Tickets_Config::venue_id($product_id);
        return array(
            'id'        => (int) $product_id,
            'name'      => $product ? $product->get_name() : '',
            'starts_at' => $ts,
            'starts'    => $ts ? wp_date('c', $ts) : '',
            'when'      => $ts ? wp_date('D j M, g:ia', $ts) : '',
            'venue'     => $venue_id ? (string) get_the_title($venue_id) : '',
            'stats'     => self::stats($product_id),
            'door_pin'  => Azure_Tickets_Door::pin($product_id),
            'has_seats' => (bool) $venue_id,
        );
    }

    /**
     * @return array{sold:int,checked_in:int}
     */
    public static function stats($product_id) {
        $s = (new Azure_Tickets_Checkin())->get_checkin_stats($product_id);
        return array(
            'sold'       => $s['active'] + $s['checked_in'],
            'checked_in' => $s['checked_in'],
        );
    }

    private static function module_off() {
        if (class_exists('Azure_Tickets_Checkin') && class_exists('Azure_Tickets_Config') && function_exists('wc_get_products')) {
            return null;
        }
        return new WP_Error('ptsa_tickets_off', 'Event Tickets is turned off on the website.', array('status' => 404));
    }

    private static function not_found() {
        return new WP_Error('ptsa_tickets_not_found', 'Performance not found.', array('status' => 404));
    }
}
