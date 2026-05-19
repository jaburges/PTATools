<?php
/**
 * Plugin Name: One-off — Find duplicate "Celebration book" orders
 * Description: READ-ONLY. Lists all WooCommerce orders from the last 7
 *              days that contain a line item whose product name matches
 *              "celebration" (case-insensitive), groups by customer
 *              (preferring billing email, then user_id, then customer
 *              IP+name as a fallback for guest orders), and flags
 *              customers with more than one such order. Designed to
 *              find people who placed duplicate orders because the ACS
 *              email outage swallowed their order-confirmation email.
 *
 *              Does NOT modify any order, send any email, or refund
 *              anything. JSON output only.
 *
 *              HPOS-safe — uses wc_get_orders rather than wp_posts.
 *
 * Deploy to:   wp-content/mu-plugins/celebration-dup-check.php
 * Trigger:     GET /?pta_celebration_dup_check=<TOKEN>[&days=N][&needle=foo]
 *              ?days=N   how many days back to look (default 7)
 *              ?needle=  product-name substring to match (default 'celebration')
 *
 *              Does NOT self-delete — re-run as needed. Remove manually
 *              from wp-content/mu-plugins/ when investigation is complete.
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_celebration_dup_check'])) {
        return;
    }

    $expected_token = 'd4d4306c0c8ddc80312c3554aefbedbd';
    $provided_token = (string) $_GET['pta_celebration_dup_check'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    if (!function_exists('wc_get_orders')) {
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode(array('error' => 'WooCommerce not loaded'), JSON_PRETTY_PRINT);
        exit;
    }

    $days   = isset($_GET['days'])   ? max(1, (int) $_GET['days'])   : 7;
    $needle = isset($_GET['needle']) ? strtolower(sanitize_text_field((string) $_GET['needle'])) : 'celebration';
    $mode   = isset($_GET['mode'])   ? sanitize_key((string) $_GET['mode'])                     : 'dupes';

    $since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

    // CATALOG MODE: list distinct product names + price + order count
    // across orders in the window. Lets us identify what the "celebration
    // book" product is actually named without scanning line-item details.
    if ($mode === 'catalog') {
        $catalog = array();
        $page = 1; $per_page = 100; $scanned = 0;
        do {
            $batch = wc_get_orders(array(
                'limit'        => $per_page,
                'page'         => $page,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'date_created' => '>=' . $since,
                'status'       => array_keys(wc_get_order_statuses()),
            ));
            if (empty($batch)) break;
            $scanned += count($batch);
            foreach ($batch as $order) {
                foreach ($order->get_items() as $item) {
                    $name = (string) $item->get_name();
                    $pid  = (int) $item->get_product_id();
                    $unit = $item->get_quantity() > 0 ? round((float) $item->get_total() / (int) $item->get_quantity(), 2) : 0;
                    $key  = $pid . '::' . $name;
                    if (!isset($catalog[$key])) {
                        $catalog[$key] = array(
                            'product_id' => $pid,
                            'name'       => $name,
                            'unit_price' => $unit,
                            'orders'     => 0,
                            'qty'        => 0,
                            'revenue'    => 0.0,
                        );
                    }
                    $catalog[$key]['orders']++;
                    $catalog[$key]['qty']     += (int) $item->get_quantity();
                    $catalog[$key]['revenue'] += (float) $item->get_total();
                }
            }
            if (count($batch) < $per_page) break;
            $page++;
        } while ($page < 50);

        usort($catalog, function ($a, $b) { return $b['orders'] <=> $a['orders']; });

        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode(array(
            'mode'           => 'catalog',
            'window_days'    => $days,
            'since'          => $since,
            'orders_scanned' => $scanned,
            'unique_lines'   => count($catalog),
            'catalog'        => array_values($catalog),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $matching_orders = array();
    $page = 1;
    $per_page = 100;
    $scanned = 0;

    do {
        $batch = wc_get_orders(array(
            'limit'        => $per_page,
            'page'         => $page,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'date_created' => '>=' . $since,
            'status'       => array_keys(wc_get_order_statuses()),
        ));
        if (empty($batch)) {
            break;
        }
        $scanned += count($batch);
        $skipped_errors = isset($skipped_errors) ? $skipped_errors : array();
        foreach ($batch as $order) {
            try {
                $items = $order->get_items();
                $matched_lines = array();
                foreach ($items as $item) {
                    $name = (string) $item->get_name();
                    if (stripos($name, $needle) !== false) {
                        $matched_lines[] = array(
                            'product_id' => (int) $item->get_product_id(),
                            'name'       => $name,
                            'qty'        => (int) $item->get_quantity(),
                            'subtotal'   => (float) $item->get_subtotal(),
                            'total'      => (float) $item->get_total(),
                        );
                    }
                }
                if (empty($matched_lines)) {
                    continue;
                }

                $email  = strtolower(trim((string) $order->get_billing_email()));
                $uid    = (int) $order->get_customer_id();
                $first  = (string) $order->get_billing_first_name();
                $last   = (string) $order->get_billing_last_name();

                $date_created_obj = $order->get_date_created();
                $date_created     = ($date_created_obj instanceof WC_DateTime) ? $date_created_obj->date('Y-m-d H:i:s') : '';

                $date_paid_obj = $order->get_date_paid();
                $date_paid     = ($date_paid_obj instanceof WC_DateTime) ? $date_paid_obj->date('Y-m-d H:i:s') : '';

                $qty_sum = 0; $total_sum = 0.0;
                foreach ($matched_lines as $ml) {
                    $qty_sum   += (int) $ml['qty'];
                    $total_sum += (float) $ml['total'];
                }

                $matching_orders[] = array(
                    'order_id'      => (int) $order->get_id(),
                    'status'        => $order->get_status(),
                    'date_created'  => $date_created,
                    'date_paid'     => $date_paid,
                    'total'         => (float) $order->get_total(),
                    'customer_id'   => $uid,
                    'billing_email' => $email,
                    'billing_name'  => trim($first . ' ' . $last),
                    'payment_method'=> (string) $order->get_payment_method_title(),
                    'transaction_id'=> (string) $order->get_transaction_id(),
                    'matched_lines' => $matched_lines,
                    'matched_qty'   => $qty_sum,
                    'matched_total' => $total_sum,
                );
            } catch (\Throwable $e) {
                $skipped_errors[] = array(
                    'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : null,
                    'error'    => $e->getMessage(),
                    'file'     => basename($e->getFile()),
                    'line'     => $e->getLine(),
                );
            }
        }
        if (count($batch) < $per_page) {
            break;
        }
        $page++;
    } while ($page < 50);

    // Union-find: two orders are in the same group if they share EITHER a
    // (lowercased trimmed) billing email OR a (lowercased trimmed) name. This
    // catches duplicates where someone used a different email on the retry.
    $n = count($matching_orders);
    $parent = range(0, $n - 1);
    $find = function ($x) use (&$parent, &$find) {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]];
            $x = $parent[$x];
        }
        return $x;
    };
    $union = function ($a, $b) use (&$parent, &$find) {
        $ra = $find($a); $rb = $find($b);
        if ($ra !== $rb) $parent[$ra] = $rb;
    };

    $normalize_name = function ($s) {
        $s = strtolower(trim((string) $s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    };
    $normalize_email = function ($s) {
        return strtolower(trim((string) $s));
    };

    $by_email = array();
    $by_name  = array();
    foreach ($matching_orders as $idx => $o) {
        $em = $normalize_email($o['billing_email']);
        $nm = $normalize_name($o['billing_name']);
        if ($em !== '') {
            if (isset($by_email[$em])) {
                $union($idx, $by_email[$em]);
            } else {
                $by_email[$em] = $idx;
            }
        }
        if ($nm !== '') {
            if (isset($by_name[$nm])) {
                $union($idx, $by_name[$nm]);
            } else {
                $by_name[$nm] = $idx;
            }
        }
    }

    $groups_by_root = array();
    foreach ($matching_orders as $idx => $o) {
        $root = $find($idx);
        if (!isset($groups_by_root[$root])) {
            $groups_by_root[$root] = array(
                'emails'        => array(),
                'names'         => array(),
                'customer_ids'  => array(),
                'order_count'   => 0,
                'matched_qty'   => 0,
                'matched_total' => 0.0,
                'orders'        => array(),
            );
        }
        if (!empty($o['billing_email'])) {
            $groups_by_root[$root]['emails'][$normalize_email($o['billing_email'])] = $o['billing_email'];
        }
        if (!empty($o['billing_name'])) {
            $groups_by_root[$root]['names'][$normalize_name($o['billing_name'])] = $o['billing_name'];
        }
        if (!empty($o['customer_id'])) {
            $groups_by_root[$root]['customer_ids'][(int) $o['customer_id']] = (int) $o['customer_id'];
        }
        $groups_by_root[$root]['order_count']++;
        $groups_by_root[$root]['matched_qty']   += $o['matched_qty'];
        $groups_by_root[$root]['matched_total'] += $o['matched_total'];
        $groups_by_root[$root]['orders'][]      = $o;
    }

    $groups = array();
    foreach ($groups_by_root as $g) {
        $g['emails']       = array_values($g['emails']);
        $g['names']        = array_values($g['names']);
        $g['customer_ids'] = array_values($g['customer_ids']);
        // Annotate why each group is a dupe (email match, name match, or both)
        $g['match_signals'] = array();
        foreach ($g['orders'] as $o1) {
            foreach ($g['orders'] as $o2) {
                if ($o1['order_id'] === $o2['order_id']) continue;
                if ($normalize_email($o1['billing_email']) !== '' &&
                    $normalize_email($o1['billing_email']) === $normalize_email($o2['billing_email'])) {
                    $g['match_signals']['email'] = true;
                }
                if ($normalize_name($o1['billing_name']) !== '' &&
                    $normalize_name($o1['billing_name']) === $normalize_name($o2['billing_name'])) {
                    $g['match_signals']['name'] = true;
                }
            }
        }
        $g['match_signals'] = array_keys($g['match_signals']);
        $groups[] = $g;
    }

    $duplicates = array();
    $singles    = array();
    foreach ($groups as $g) {
        usort($g['orders'], function ($a, $b) {
            return strcmp($a['date_created'], $b['date_created']);
        });
        if ($g['order_count'] >= 2) {
            $duplicates[] = $g;
        } else {
            $singles[] = $g;
        }
    }
    usort($duplicates, function ($a, $b) {
        return $b['order_count'] <=> $a['order_count'];
    });
    usort($singles, function ($a, $b) {
        $ae = isset($a['emails'][0]) ? $a['emails'][0] : '';
        $be = isset($b['emails'][0]) ? $b['emails'][0] : '';
        return strcmp($ae, $be);
    });

    $out = array(
        'as_of'                  => current_time('mysql'),
        'window_days'            => $days,
        'since'                  => $since,
        'needle'                 => $needle,
        'orders_scanned'         => $scanned,
        'orders_matched'         => count($matching_orders),
        'orders_skipped_errors'  => isset($skipped_errors) ? $skipped_errors : array(),
        'unique_customers'       => count($groups),
        'customers_with_dupes'   => count($duplicates),
        'duplicate_customers'    => $duplicates,
        'single_order_customers' => $singles,
    );

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});
