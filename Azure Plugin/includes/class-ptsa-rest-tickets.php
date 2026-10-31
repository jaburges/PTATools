<?php
/**
 * Door check-in for the PTSA Board app: /wp-json/ptsa/v1/tickets/*
 *
 * Routes are registered by Azure_PTSA_REST_API with its Entra sign-in
 * check, so every caller is a signed-in board account. Anyone who can sign
 * in to the app may scan; there is no further capability check.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTSA_REST_Tickets {

    /** Performances that ended longer ago than this drop off the door list. */
    const PAST_GRACE = DAY_IN_SECONDS;

    public static function register($ns, $auth) {
        register_rest_route($ns, '/tickets/performances', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'list_performances'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/performances/(?P<id>\d+)', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'get_performance'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/checkin', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'checkin'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/lookup', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'lookup'), 'permission_callback' => $auth,
        ));
        register_rest_route($ns, '/tickets/(?P<id>\d+)/undo', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'undo'), 'permission_callback' => $auth,
        ));
    }

    public static function list_performances(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        $ids = wc_get_products(array(
            'type'   => 'ticket',
            'status' => 'publish',
            'limit'  => 50,
            'return' => 'ids',
        ));
        $cutoff = Azure_Tickets_Config::now() - self::PAST_GRACE;
        $out = array();
        foreach ((array) $ids as $id) {
            foreach (Azure_Tickets_Config::performances((int) $id) as $performance) {
                if (isset($out[$performance]) || !Azure_Tickets_Config::is_performance($performance)) {
                    continue;
                }
                $row = self::performance($performance);
                if ($row['starts_at'] && $row['starts_at'] < $cutoff) {
                    continue;
                }
                $out[$performance] = $row;
            }
        }
        $out = array_values($out);
        usort($out, function ($a, $b) {
            return ($a['starts_at'] ?: PHP_INT_MAX) <=> ($b['starts_at'] ?: PHP_INT_MAX) ?: $a['id'] <=> $b['id'];
        });
        return rest_ensure_response($out);
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
        $code = Azure_Tickets_Module::scanned_code((string) ($body['code'] ?? ''));
        if ($code === '') {
            return rest_ensure_response(array(
                'success' => false,
                'status'  => 'not_found',
                'message' => __('That isn\'t a ticket code', 'azure-plugin'),
            ));
        }
        $checkin = new Azure_Tickets_Checkin();
        $result = $check_in ? $checkin->check_in($code, get_current_user_id(), $product_id) : $checkin->look_up($code, $product_id);
        $result['stats'] = self::stats($product_id);
        return rest_ensure_response($result);
    }

    public static function undo(WP_REST_Request $req) {
        if ($off = self::module_off()) {
            return $off;
        }
        $checkin = new Azure_Tickets_Checkin();
        $result = $checkin->undo_checkin((int) $req['id'], get_current_user_id());
        if (empty($result['success'])) {
            return new WP_Error('ptsa_tickets_undo', $result['message'] ?? 'Could not undo.', array('status' => 409));
        }
        global $wpdb;
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}azure_tickets WHERE id = %d", (int) $req['id']));
        $result['ticket'] = $checkin->ticket_payload($ticket);
        $result['stats'] = self::stats((int) $ticket->product_id);
        return rest_ensure_response($result);
    }

    /**
     * @return array{id:int,name:string,starts_at:int,starts:string,when:string,venue:string,stats:array{sold:int,checked_in:int}}
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
