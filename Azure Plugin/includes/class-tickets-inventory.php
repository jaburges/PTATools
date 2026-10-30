<?php
/**
 * Seat inventory per performance (one ticket product = one performance).
 *
 * One row per taken place. The unique key (product, section, seat) is what
 * stops two people getting the same seat: claiming is an INSERT IGNORE, so
 * of two simultaneous buyers exactly one row wins.
 *
 *   held     tapped on the seat map or in someone's cart. A buyer's holds
 *            for a performance share one window: the first runs hold_ttl(),
 *            and seats added while it is open end with it. Seeing the cart
 *            again does not extend it.
 *   ordered  on an order awaiting payment; resolved by cleanup()
 *   sold     paid
 *
 * General admission places use seat keys "1".."capacity".
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Inventory {

    /** Default hold window; Tickets > Settings > Seat Reservation Timeout overrides it. */
    const HOLD_TTL = 600;
    const ORDER_TTL = 3600;
    const DB_VERSION = '1';
    const DB_VERSION_OPTION = 'azure_tickets_inventory_db';

    /** @var int|null Fixed clock for tests. */
    public static $now = null;

    /** Seconds a buyer has from their first seat, 5 to 60 minutes. */
    public static function hold_ttl() {
        $minutes = class_exists('Azure_Settings') ? (int) Azure_Settings::get_setting('tickets_reservation_timeout', 0) : 0;
        return $minutes > 0 ? 60 * max(5, min(60, $minutes)) : self::HOLD_TTL;
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'azure_ticket_inventory';
    }

    public static function maybe_create_table() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            section_id varchar(40) NOT NULL,
            seat_key varchar(16) NOT NULL,
            status varchar(10) NOT NULL,
            hold_key varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY place (product_id,section_id,seat_key),
            KEY hold_key (hold_key),
            KEY order_id (order_id),
            KEY status_expires (status,expires_at)
        ) " . $wpdb->get_charset_collate() . ';');
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    private static function now($offset = 0) {
        return gmdate('Y-m-d H:i:s', (self::$now ?? time()) + $offset);
    }

    /**
     * Hold places. All or nothing: if any place is taken by someone else,
     * nothing new is held. Places this hold key already has keep their
     * expiry, new ones end with the key's open window for this performance,
     * and a lapsed one is held afresh if still free.
     *
     * @param array $places list of ['section_id' => string, 'seat_key' => string]
     * @return array{ok:bool, taken:array, renewed:int} taken lists the places
     *         someone else has; renewed counts places newly held.
     */
    public static function claim($product_id, array $places, $hold_key, $user_id = 0) {
        global $wpdb;
        $table = self::table();
        $product_id = (int) $product_id;
        self::purge_expired_holds($product_id);

        $inserted = array();
        $taken = array();
        $expires = self::window_expiry($product_id, $hold_key);
        foreach ($places as $p) {
            $section = (string) $p['section_id'];
            $seat = (string) $p['seat_key'];
            $added = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (product_id, section_id, seat_key, status, hold_key, user_id, order_id, expires_at, created_at) VALUES (%d, %s, %s, 'held', %s, %d, 0, %s, %s)",
                $product_id, $section, $seat, $hold_key, (int) $user_id, $expires, self::now()
            ));
            if ($added) {
                $inserted[] = $p;
                continue;
            }
            if (!$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND section_id = %s AND seat_key = %s AND status = 'held' AND hold_key = %s",
                $product_id, $section, $seat, $hold_key
            ))) {
                $taken[] = $p;
            }
        }
        if ($taken) {
            self::delete_places($product_id, $inserted, $hold_key);
            return array('ok' => false, 'taken' => $taken, 'renewed' => 0);
        }
        return array('ok' => true, 'taken' => array(), 'renewed' => count($inserted));
    }

    /**
     * When the earliest live hold of these hold keys runs out.
     *
     * @param string[] $hold_keys
     * @return int Unix time, or 0 when nothing is held.
     */
    public static function hold_expires(array $hold_keys) {
        $hold_keys = array_values(array_unique(array_filter(array_map('strval', $hold_keys))));
        if (!$hold_keys) {
            return 0;
        }
        global $wpdb;
        $in = implode(', ', array_fill(0, count($hold_keys), '%s'));
        $at = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(expires_at) FROM " . self::table() . " WHERE status = 'held' AND expires_at >= %s AND hold_key IN ({$in})",
            array_merge(array(self::now()), $hold_keys)
        ));
        return $at ? (int) strtotime($at . ' UTC') : 0;
    }

    /**
     * When this hold key's window for the performance ends, or 0 when it
     * holds nothing there.
     */
    public static function window_ends($product_id, $hold_key) {
        if ((string) $hold_key === '') {
            return 0;
        }
        global $wpdb;
        $at = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(expires_at) FROM " . self::table() . " WHERE product_id = %d AND status = 'held' AND hold_key = %s AND expires_at >= %s",
            (int) $product_id, (string) $hold_key, self::now()
        ));
        return $at ? (int) strtotime($at . ' UTC') : 0;
    }

    /**
     * Whether this hold key still has every one of these places: held with
     * time left, or already moved onto its order.
     */
    public static function still_held($product_id, $hold_key, array $places) {
        if ((string) $hold_key === '' || !$places) {
            return false;
        }
        global $wpdb;
        foreach ($places as $p) {
            if (!$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE product_id = %d AND section_id = %s AND seat_key = %s AND hold_key = %s AND (status <> 'held' OR expires_at >= %s)",
                (int) $product_id, (string) $p['section_id'], (string) $p['seat_key'], (string) $hold_key, self::now()
            ))) {
                return false;
            }
        }
        return true;
    }

    private static function window_expiry($product_id, $hold_key) {
        $ends = self::window_ends($product_id, $hold_key);
        return $ends ? gmdate('Y-m-d H:i:s', $ends) : self::now(self::hold_ttl());
    }

    /**
     * Places this hold key has right now at the performance.
     *
     * @return array list of ['section_id' => string, 'seat_key' => string]
     */
    public static function held_by($product_id, $hold_key) {
        if ((string) $hold_key === '') {
            return array();
        }
        global $wpdb;
        return array_map(function ($r) {
            return array('section_id' => (string) $r['section_id'], 'seat_key' => (string) $r['seat_key']);
        }, (array) $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, seat_key FROM " . self::table() . " WHERE product_id = %d AND status = 'held' AND hold_key = %s AND expires_at >= %s ORDER BY id",
            (int) $product_id, (string) $hold_key, self::now()
        ), ARRAY_A));
    }

    /**
     * Pick and hold $count free general-admission places.
     *
     * @return string[]|false Seat keys held, or false when there aren't enough.
     */
    public static function claim_general($product_id, $section_id, $count, $capacity, $hold_key, $user_id = 0) {
        global $wpdb;
        $table = self::table();
        self::purge_expired_holds($product_id);
        $used = array_flip((array) $wpdb->get_col($wpdb->prepare(
            "SELECT seat_key FROM {$table} WHERE product_id = %d AND section_id = %s",
            (int) $product_id, (string) $section_id
        )));
        $got = array();
        $expires = self::window_expiry($product_id, $hold_key);
        for ($n = 1; $n <= (int) $capacity && count($got) < $count; $n++) {
            if (isset($used[(string) $n])) {
                continue;
            }
            $added = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (product_id, section_id, seat_key, status, hold_key, user_id, order_id, expires_at, created_at) VALUES (%d, %s, %s, 'held', %s, %d, 0, %s, %s)",
                (int) $product_id, (string) $section_id, (string) $n, $hold_key, (int) $user_id, $expires, self::now()
            ));
            if ($added) {
                $got[] = (string) $n;
            }
        }
        if (count($got) < $count) {
            self::delete_places($product_id, array_map(function ($k) use ($section_id) {
                return array('section_id' => $section_id, 'seat_key' => $k);
            }, $got), $hold_key);
            return false;
        }
        return $got;
    }

    /**
     * Let go of held places (cart line removed, or a failed add).
     */
    public static function release($hold_key, $product_id, array $places) {
        self::delete_places($product_id, $places, $hold_key);
    }

    /**
     * Held places move onto an order awaiting payment.
     *
     * @return int Places moved; fewer than count($places) means some hold was lost.
     */
    public static function attach_order($order_id, $hold_key, $product_id, array $places) {
        global $wpdb;
        $table = self::table();
        $moved = 0;
        foreach ($places as $p) {
            $moved += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'ordered', order_id = %d, expires_at = %s WHERE product_id = %d AND section_id = %s AND seat_key = %s AND hold_key = %s AND status = 'held'",
                (int) $order_id, self::now(self::ORDER_TTL), (int) $product_id, (string) $p['section_id'], (string) $p['seat_key'], $hold_key
            ));
        }
        return $moved;
    }

    /**
     * The order is paid: its places are sold. Places that were let go in the
     * meantime are claimed again if still free.
     *
     * @param array $places_by_product product_id => list of places on the order
     * @return array Places that someone else now has (double-booked).
     */
    public static function mark_sold($order_id, array $places_by_product) {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'sold', expires_at = NULL WHERE order_id = %d",
            (int) $order_id
        ));
        $lost = array();
        foreach ($places_by_product as $product_id => $places) {
            foreach ($places as $p) {
                $owner = $wpdb->get_var($wpdb->prepare(
                    "SELECT order_id FROM {$table} WHERE product_id = %d AND section_id = %s AND seat_key = %s",
                    (int) $product_id, (string) $p['section_id'], (string) $p['seat_key']
                ));
                if ((int) $owner === (int) $order_id) {
                    continue;
                }
                $added = $owner === null && $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (product_id, section_id, seat_key, status, hold_key, user_id, order_id, expires_at, created_at) VALUES (%d, %s, %s, 'sold', '', 0, %d, NULL, %s)",
                    (int) $product_id, (string) $p['section_id'], (string) $p['seat_key'], (int) $order_id, self::now()
                ));
                if (!$added) {
                    $lost[] = $p + array('product_id' => (int) $product_id);
                }
            }
        }
        return $lost;
    }

    /**
     * Give seats straight to an order paid for something else (a VIP cast &
     * crew ticket whose seats are chosen by hand). All or nothing.
     *
     * @return array{ok:bool, taken:array} taken lists places someone else has.
     */
    public static function assign($order_id, $product_id, array $places) {
        global $wpdb;
        $table = self::table();
        self::purge_expired_holds($product_id);
        $added = array();
        $taken = array();
        foreach ($places as $p) {
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (product_id, section_id, seat_key, status, hold_key, user_id, order_id, expires_at, created_at) VALUES (%d, %s, %s, 'sold', '', 0, %d, NULL, %s)",
                (int) $product_id, (string) $p['section_id'], (string) $p['seat_key'], (int) $order_id, self::now()
            ));
            if ($ok) {
                $added[] = $p;
                continue;
            }
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$table} WHERE product_id = %d AND section_id = %s AND seat_key = %s AND status = 'sold'",
                (int) $product_id, (string) $p['section_id'], (string) $p['seat_key']
            ));
            if ((int) $owner !== (int) $order_id) {
                $taken[] = $p;
            }
        }
        if ($taken) {
            self::unassign($order_id, $product_id, $added);
            return array('ok' => false, 'taken' => $taken);
        }
        return array('ok' => true, 'taken' => array());
    }

    public static function unassign($order_id, $product_id, array $places) {
        global $wpdb;
        foreach ($places as $p) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM " . self::table() . " WHERE order_id = %d AND product_id = %d AND section_id = %s AND seat_key = %s",
                (int) $order_id, (int) $product_id, (string) $p['section_id'], (string) $p['seat_key']
            ));
        }
    }

    public static function release_order($order_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE order_id = %d", (int) $order_id));
    }

    /**
     * Places nobody else can take right now.
     *
     * @return array<string,array<string,string>> section_id => seat_key => "taken" | "mine"
     */
    public static function taken($product_id, $hold_key = '') {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, seat_key, status, hold_key FROM " . self::table() . " WHERE product_id = %d AND (status IN ('sold', 'ordered') OR (status = 'held' AND expires_at >= %s))",
            (int) $product_id, self::now()
        ));
        $out = array();
        foreach ($rows as $r) {
            $mine = $hold_key !== '' && $r->status === 'held' && $r->hold_key === $hold_key;
            $out[$r->section_id][$r->seat_key] = $mine ? 'mine' : 'taken';
        }
        return $out;
    }

    /**
     * Places taken in the given sections, optionally only one buyer's, not
     * counting $exclude_hold_key's own holds.
     */
    public static function count_taken($product_id, array $section_ids, $exclude_hold_key = '', $user_id = 0) {
        if (!$section_ids) {
            return 0;
        }
        global $wpdb;
        $in = implode(', ', array_fill(0, count($section_ids), '%s'));
        $sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE product_id = %d AND section_id IN ({$in})"
            . " AND (status IN ('sold', 'ordered') OR (status = 'held' AND expires_at >= %s)) AND NOT (status = 'held' AND hold_key = %s)";
        $args = array_merge(array((int) $product_id), array_values($section_ids), array(self::now(), (string) $exclude_hold_key));
        if ($user_id) {
            $sql .= ' AND user_id = %d';
            $args[] = (int) $user_id;
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
    }

    /**
     * Expired holds go; orders past ORDER_TTL are settled through
     * $order_state(order_id): "paid" sells, "open" leaves them, anything
     * else releases.
     */
    public static function cleanup(callable $order_state) {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE status = 'held' AND expires_at < %s", self::now()));
        $orders = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$table} WHERE status = 'ordered' AND expires_at < %s",
            self::now()
        ));
        foreach ($orders as $order_id) {
            $state = $order_state((int) $order_id);
            if ($state === 'paid') {
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'sold', expires_at = NULL WHERE order_id = %d", (int) $order_id));
            } elseif ($state !== 'open') {
                self::release_order((int) $order_id);
            }
        }
    }

    private static function purge_expired_holds($product_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE product_id = %d AND status = 'held' AND expires_at < %s",
            (int) $product_id, self::now()
        ));
    }

    private static function delete_places($product_id, array $places, $hold_key) {
        global $wpdb;
        foreach ($places as $p) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM " . self::table() . " WHERE product_id = %d AND section_id = %s AND seat_key = %s AND hold_key = %s AND status = 'held'",
                (int) $product_id, (string) $p['section_id'], (string) $p['seat_key'], $hold_key
            ));
        }
    }
}
