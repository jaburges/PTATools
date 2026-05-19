<?php
/**
 * Auction Winners Report
 *
 * Backend for the Selling > Auction admin widget:
 *   - Lists every ended/sold auction with winner, winning bid, WC order, payment state
 *   - Identifies "Teacher Experience" auctions (post_title LIKE 'Teacher Experience%')
 *     and computes their 2nd and 3rd place runner-up bidders
 *   - Creates wc-pending orders + sends invoice emails to the 2nd and 3rd
 *     runners-up, idempotently (records per-product postmeta to prevent double-runs)
 *
 * The standard top-1 winner flow is handled by Azure_Auction_Lifecycle and is
 * NOT touched by this class.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Auction_Winners_Report {

    const TE_TITLE_PREFIX = 'Teacher Experience';

    const META_TE_SECOND_USER_ID    = '_auction_te_second_user_id';
    const META_TE_SECOND_ORDER_ID   = '_auction_te_second_order_id';
    const META_TE_SECOND_AMOUNT     = '_auction_te_second_amount';
    const META_TE_SECOND_CREATED_AT = '_auction_te_second_created_at';
    const META_TE_SECOND_EMAILED_AT = '_auction_te_second_emailed_at';

    const META_TE_THIRD_USER_ID     = '_auction_te_third_user_id';
    const META_TE_THIRD_ORDER_ID    = '_auction_te_third_order_id';
    const META_TE_THIRD_AMOUNT      = '_auction_te_third_amount';
    const META_TE_THIRD_CREATED_AT  = '_auction_te_third_created_at';
    const META_TE_THIRD_EMAILED_AT  = '_auction_te_third_emailed_at';

    public static function is_te_title($title) {
        return $title !== '' && strpos($title, self::TE_TITLE_PREFIX) === 0;
    }

    /**
     * One row per auction product whose _auction_status is 'ended' or 'sold'.
     * Sorted most-recently-ended first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_ended_auction_rows() {
        global $wpdb;

        $product_ids = $wpdb->get_col("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p
                ON p.ID = pm.post_id
               AND p.post_type = 'product'
            WHERE pm.meta_key = '_auction_status'
              AND pm.meta_value IN ('ended','sold')
        ");

        $rows = array();
        foreach ($product_ids as $pid) {
            $rows[] = $this->build_winner_row((int) $pid);
        }

        usort($rows, function ($a, $b) {
            return strcmp((string) $b['ended_at'], (string) $a['ended_at']);
        });

        return $rows;
    }

    /**
     * Same shape as get_ended_auction_rows() but filtered to Teacher Experience items.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_te_auction_rows() {
        return array_values(array_filter($this->get_ended_auction_rows(), function ($r) {
            return !empty($r['is_te']);
        }));
    }

    private function build_winner_row($product_id) {
        $title          = get_the_title($product_id);
        $auction_status = (string) get_post_meta($product_id, '_auction_status', true);
        $ended_at       = (string) get_post_meta($product_id, '_auction_ended_at', true);
        $sold_at        = (string) get_post_meta($product_id, '_auction_sold_at', true);
        $winner_user_id = (int) get_post_meta($product_id, '_auction_winner_user_id', true);
        $winning_amount = get_post_meta($product_id, '_auction_winning_amount', true);
        $winner_order_id = (int) get_post_meta($product_id, '_auction_winner_order_id', true);
        $sold_order_id  = (int) get_post_meta($product_id, '_auction_sold_order_id', true);
        $order_id       = $winner_order_id ?: $sold_order_id;

        $user_login = $user_email = $user_name = '';
        if ($winner_user_id) {
            $u = get_userdata($winner_user_id);
            if ($u) {
                $user_login = $u->user_login;
                $user_email = $u->user_email;
                $user_name  = $u->display_name;
            }
        }

        $order_payload = $this->resolve_order_payment($order_id);

        return array(
            'product_id'      => $product_id,
            'title'           => $title,
            'is_te'           => self::is_te_title($title),
            'auction_status'  => $auction_status,
            'ended_at'        => $ended_at ?: $sold_at,
            'winner_user_id'  => $winner_user_id,
            'winner_login'    => $user_login,
            'winner_email'    => $user_email,
            'winner_name'     => $user_name,
            'winning_amount'  => $winning_amount !== '' ? (float) $winning_amount : null,
            'order_id'        => $order_id,
            'order_status'    => $order_payload['status'],
            'order_total'     => $order_payload['total'],
            'paid_date'       => $order_payload['paid_date'],
            'transaction_id'  => $order_payload['transaction_id'],
            'payment_state'   => $order_payload['state'],
            'is_paid'         => $order_payload['is_paid'],
            'order_edit_url'  => $order_payload['edit_url'],
        );
    }

    /**
     * Returns the admin edit URL for a WooCommerce order, HPOS-aware.
     * Falls back to the classic post.php URL if WC isn't available.
     */
    public static function order_edit_url($order_id) {
        $order_id = (int) $order_id;
        if (!$order_id) {
            return '';
        }
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && method_exists($order, 'get_edit_order_url')) {
                return $order->get_edit_order_url();
            }
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    /**
     * @return array{status:string,total:?float,paid_date:string,transaction_id:string,state:string,is_paid:?bool,edit_url:string}
     */
    private function resolve_order_payment($order_id) {
        $empty = array(
            'status'         => '',
            'total'          => null,
            'paid_date'      => '',
            'transaction_id' => '',
            'state'          => 'NO ORDER CREATED',
            'is_paid'        => null,
            'edit_url'       => '',
        );
        if (!$order_id || !function_exists('wc_get_order')) {
            return $empty;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return array_merge($empty, array(
                'state' => 'ORDER ID SET BUT ORDER MISSING (' . (int) $order_id . ')',
            ));
        }

        $status        = $order->get_status();
        $total         = (float) $order->get_total();
        $txn_id        = (string) $order->get_transaction_id();
        $date_paid_obj = $order->get_date_paid();
        $paid_date     = $date_paid_obj ? $date_paid_obj->date('Y-m-d H:i:s') : '';

        $paid_statuses = function_exists('wc_get_is_paid_statuses')
            ? wc_get_is_paid_statuses()
            : array('processing', 'completed');
        $is_paid = in_array($status, $paid_statuses, true) || ($paid_date !== '');

        if ($is_paid) {
            $state = 'PAID';
        } elseif ($status === 'pending') {
            $state = 'NOT PAID (pending)';
        } elseif ($status === 'on-hold') {
            $state = 'NOT PAID (on hold)';
        } elseif ($status === 'cancelled') {
            $state = 'NOT PAID (cancelled)';
        } elseif ($status === 'failed') {
            $state = 'NOT PAID (failed)';
        } elseif ($status === 'refunded') {
            $state = 'REFUNDED';
        } else {
            $state = 'UNKNOWN (' . $status . ')';
        }

        return array(
            'status'         => $status,
            'total'          => $total,
            'paid_date'      => $paid_date,
            'transaction_id' => $txn_id,
            'state'          => $state,
            'is_paid'        => $is_paid,
            'edit_url'       => method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . (int) $order_id . '&action=edit'),
        );
    }

    /**
     * Top 2 distinct bidders for a TE item EXCLUDING the recorded winner — i.e.
     * positions 2 and 3 by each user's highest bid.
     *
     * @return array{
     *   second: ?array<string,mixed>,
     *   third:  ?array<string,mixed>,
     *   stored: array{second:?array,third:?array}
     * }
     */
    public function get_te_runners_up($product_id) {
        global $wpdb;
        $bids_table = Azure_Database::get_table_name('auction_bids');

        $winner_user_id = (int) get_post_meta($product_id, '_auction_winner_user_id', true);

        $excluded = array(0);
        if ($winner_user_id) {
            $excluded[] = $winner_user_id;
        }
        $placeholders = implode(',', array_fill(0, count($excluded), '%d'));

        $sql = "
            SELECT user_id,
                   MAX(bid_amount)  AS max_bid,
                   MAX(created_at)  AS last_bid_at
            FROM {$bids_table}
            WHERE product_id = %d
              AND user_id NOT IN ($placeholders)
            GROUP BY user_id
            ORDER BY max_bid DESC, last_bid_at DESC
            LIMIT 2
        ";
        $args = array_merge(array($product_id), $excluded);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

        $second = isset($rows[0]) ? $this->expand_bidder($rows[0], 2) : null;
        $third  = isset($rows[1]) ? $this->expand_bidder($rows[1], 3) : null;

        return array(
            'second' => $second,
            'third'  => $third,
            'stored' => array(
                'second' => $this->get_stored_runner_up($product_id, 'second'),
                'third'  => $this->get_stored_runner_up($product_id, 'third'),
            ),
        );
    }

    private function expand_bidder($row, $position) {
        $user_id = (int) $row->user_id;
        $u = get_userdata($user_id);
        return array(
            'position'    => $position,
            'user_id'     => $user_id,
            'login'       => $u ? $u->user_login : '',
            'email'       => $u ? $u->user_email : '',
            'name'        => $u ? $u->display_name : '',
            'bid_amount'  => (float) $row->max_bid,
            'last_bid_at' => (string) $row->last_bid_at,
        );
    }

    private function get_stored_runner_up($product_id, $position) {
        $keys = $this->meta_keys_for_position($position);
        if (!$keys) {
            return null;
        }
        $order_id = (int) get_post_meta($product_id, $keys['order_id'], true);
        if (!$order_id) {
            return null;
        }
        return array(
            'user_id'    => (int) get_post_meta($product_id, $keys['user_id'], true),
            'order_id'   => $order_id,
            'amount'     => (float) get_post_meta($product_id, $keys['amount'], true),
            'created_at' => (string) get_post_meta($product_id, $keys['created_at'], true),
            'emailed_at' => (string) get_post_meta($product_id, $keys['emailed_at'], true),
        );
    }

    private function meta_keys_for_position($position) {
        if ((int) $position === 2) {
            return array(
                'user_id'    => self::META_TE_SECOND_USER_ID,
                'order_id'   => self::META_TE_SECOND_ORDER_ID,
                'amount'     => self::META_TE_SECOND_AMOUNT,
                'created_at' => self::META_TE_SECOND_CREATED_AT,
                'emailed_at' => self::META_TE_SECOND_EMAILED_AT,
            );
        }
        if ((int) $position === 3) {
            return array(
                'user_id'    => self::META_TE_THIRD_USER_ID,
                'order_id'   => self::META_TE_THIRD_ORDER_ID,
                'amount'     => self::META_TE_THIRD_AMOUNT,
                'created_at' => self::META_TE_THIRD_CREATED_AT,
                'emailed_at' => self::META_TE_THIRD_EMAILED_AT,
            );
        }
        return null;
    }

    /**
     * Idempotent: creates wc-pending orders + sends invoice emails for the
     * 2nd and 3rd place runner-up bidders on a single TE product.
     *
     * Positions already processed (postmeta `_auction_te_{second|third}_order_id`
     * set) are skipped — re-running is safe.
     *
     * @return array{
     *   product_id:int,
     *   actions: array<int,array{position:int,result:string,order_id:?int,user_id:?int,amount:?float,error:?string}>,
     *   totals:  array{created:int,emailed:int,skipped:int,errors:int}
     * }
     */
    public function create_runner_up_orders($product_id) {
        $product_id = (int) $product_id;
        $title      = get_the_title($product_id);
        $summary = array(
            'product_id' => $product_id,
            'title'      => $title,
            'actions'    => array(),
            'totals'     => array('created' => 0, 'emailed' => 0, 'skipped' => 0, 'errors' => 0),
        );

        if (!self::is_te_title($title)) {
            $summary['actions'][] = array(
                'position' => 0, 'result' => 'error', 'order_id' => null,
                'user_id'  => null, 'amount' => null,
                'error'    => 'Product is not a Teacher Experience.',
            );
            $summary['totals']['errors']++;
            return $summary;
        }

        if (!class_exists('Azure_Auction_Lifecycle') || !class_exists('Azure_Auction_Emails')) {
            $summary['actions'][] = array(
                'position' => 0, 'result' => 'error', 'order_id' => null,
                'user_id'  => null, 'amount' => null,
                'error'    => 'Auction lifecycle/emails classes not loaded.',
            );
            $summary['totals']['errors']++;
            return $summary;
        }

        $runners = $this->get_te_runners_up($product_id);
        $lifecycle = new Azure_Auction_Lifecycle();
        $emails    = new Azure_Auction_Emails();

        foreach (array(2, 3) as $position) {
            $key = $position === 2 ? 'second' : 'third';
            $bidder = $runners[$key];
            $stored = $runners['stored'][$key];

            if ($stored && !empty($stored['order_id'])) {
                $summary['actions'][] = array(
                    'position' => $position,
                    'result'   => 'skipped_already_done',
                    'order_id' => (int) $stored['order_id'],
                    'user_id'  => (int) $stored['user_id'],
                    'amount'   => (float) $stored['amount'],
                    'error'    => null,
                );
                $summary['totals']['skipped']++;
                continue;
            }

            if (!$bidder) {
                $summary['actions'][] = array(
                    'position' => $position,
                    'result'   => 'skipped_no_bidder',
                    'order_id' => null, 'user_id' => null, 'amount' => null,
                    'error'    => 'No bidder at this position.',
                );
                $summary['totals']['skipped']++;
                continue;
            }

            try {
                $order = $lifecycle->create_winner_order(
                    $product_id,
                    (int) $bidder['user_id'],
                    (float) $bidder['bid_amount']
                );
                if (!$order) {
                    throw new \RuntimeException('create_winner_order returned null');
                }

                $keys = $this->meta_keys_for_position($position);
                update_post_meta($product_id, $keys['user_id'],    (int) $bidder['user_id']);
                update_post_meta($product_id, $keys['order_id'],   (int) $order->get_id());
                update_post_meta($product_id, $keys['amount'],     (float) $bidder['bid_amount']);
                update_post_meta($product_id, $keys['created_at'], current_time('mysql'));

                $order->add_order_note(sprintf(
                    'Auction Teacher Experience runner-up (position %d). Auto-created at bidder\'s max bid (%s).',
                    $position,
                    function_exists('wc_price') ? wp_strip_all_tags(wc_price($bidder['bid_amount'])) : (string) $bidder['bid_amount']
                ));

                $summary['totals']['created']++;

                $emailed = $emails->send_winner_email($order, $product_id, (float) $bidder['bid_amount']);
                if ($emailed) {
                    update_post_meta($product_id, $keys['emailed_at'], current_time('mysql'));
                    $summary['totals']['emailed']++;
                }

                $summary['actions'][] = array(
                    'position' => $position,
                    'result'   => $emailed ? 'created_and_emailed' : 'created_email_failed',
                    'order_id' => (int) $order->get_id(),
                    'user_id'  => (int) $bidder['user_id'],
                    'amount'   => (float) $bidder['bid_amount'],
                    'error'    => $emailed ? null : 'wp_mail returned false',
                );

                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info('Auction TE runner-up order created', array(
                        'product_id' => $product_id,
                        'position'   => $position,
                        'order_id'   => (int) $order->get_id(),
                        'user_id'    => (int) $bidder['user_id'],
                        'amount'     => (float) $bidder['bid_amount'],
                        'emailed'    => (bool) $emailed,
                    ));
                }
                // Bust cached "no runner-up" reads so the admin widget shows
                // the new postmeta on the very next page load. Without this,
                // W3 Total Cache's object cache will keep serving the stale
                // empty result until its TTL expires (we hit exactly this:
                // orders + emails went out, postmeta was written, but the
                // widget showed "not yet created" for hours because get_post_meta
                // was returning the cached pre-write null).
                clean_post_cache($product_id);
                if (function_exists('w3tc_flush_post')) {
                    @w3tc_flush_post($product_id);
                }
                if (function_exists('w3tc_objectcache_flush')) {
                    @w3tc_objectcache_flush();
                }
            } catch (\Throwable $e) {
                $summary['totals']['errors']++;
                $summary['actions'][] = array(
                    'position' => $position,
                    'result'   => 'error',
                    'order_id' => null,
                    'user_id'  => (int) $bidder['user_id'],
                    'amount'   => (float) $bidder['bid_amount'],
                    'error'    => $e->getMessage(),
                );
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::error('Auction TE runner-up order failed: ' . $e->getMessage(), array(
                        'product_id' => $product_id,
                        'position'   => $position,
                    ));
                }
            }
        }

        return $summary;
    }

    /**
     * Order meta key recording the last time we (re-)triggered the WC
     * Customer Invoice email for an order via this admin widget.
     */
    const ORDER_META_INVOICE_RESENT_AT = '_azure_last_invoice_resent_at';

    /**
     * Trigger WooCommerce's built-in "Customer Invoice / Order Details"
     * email for a single order. Same email WC sends when an admin uses
     * "Order actions → Email invoice / order details" on the order edit
     * screen. For unpaid orders the email includes a pay-now link.
     *
     * Defensively ensures the order has a billing email by copying from
     * the customer's user meta / user record if missing (some orders
     * created via wc_create_order(['customer_id' => N]) without copying
     * billing fields would otherwise send to an empty address).
     *
     * @return array{
     *   order_id:int,
     *   result:string,           sent|skipped_no_order|skipped_no_email|error
     *   to:string,
     *   error:?string,
     *   resent_at:?string
     * }
     */
    public function resend_customer_invoice($order_id) {
        $order_id = (int) $order_id;
        $res = array(
            'order_id'  => $order_id,
            'result'    => 'error',
            'to'        => '',
            'error'     => null,
            'resent_at' => null,
        );
        if (!$order_id || !function_exists('wc_get_order')) {
            $res['error'] = 'WooCommerce not loaded.';
            return $res;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            $res['result'] = 'skipped_no_order';
            $res['error'] = 'Order ' . $order_id . ' not found.';
            return $res;
        }

        $this->ensure_order_billing_from_user($order);

        $to = (string) $order->get_billing_email();
        if (empty($to)) {
            $res['result'] = 'skipped_no_email';
            $res['error'] = 'Order has no billing_email and customer user has no email either.';
            return $res;
        }
        $res['to'] = $to;

        try {
            if (!function_exists('WC') || !WC()->mailer()) {
                throw new \RuntimeException('WC mailer not available.');
            }
            $emails = WC()->mailer()->get_emails();
            if (!isset($emails['WC_Email_Customer_Invoice'])) {
                throw new \RuntimeException('WC_Email_Customer_Invoice not registered.');
            }
            $emails['WC_Email_Customer_Invoice']->trigger($order_id);

            $now = current_time('mysql');
            $order->update_meta_data(self::ORDER_META_INVOICE_RESENT_AT, $now);
            $order->save();
            $order->add_order_note(sprintf(
                'Invoice email re-sent to %s via Selling > Auction admin widget.',
                $to
            ));

            $res['result']    = 'sent';
            $res['resent_at'] = $now;

            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Auction widget: invoice resent', array(
                    'order_id' => $order_id,
                    'to'       => $to,
                ));
            }
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Auction widget: invoice resend failed: ' . $e->getMessage(), array(
                    'order_id' => $order_id,
                ));
            }
        }

        return $res;
    }

    /**
     * Resend the WC Customer Invoice email to every customer who has an
     * unpaid order linked to an ended/sold auction product — main winners
     * AND Teacher Experience 2nd/3rd-place runner-up orders.
     *
     * "Unpaid" = order status in {pending, on-hold, failed} AND no
     * _paid_date. Cancelled / refunded / paid orders are skipped.
     *
     * @return array{
     *   totals: array{eligible:int, sent:int, skipped:int, errors:int},
     *   actions: array<int,array<string,mixed>>
     * }
     */
    public function resend_invoices_for_unpaid_auctions() {
        $summary = array(
            'totals'  => array('eligible' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0),
            'actions' => array(),
        );

        $eligible_ids = $this->get_unpaid_auction_order_ids();
        $summary['totals']['eligible'] = count($eligible_ids);

        foreach ($eligible_ids as $order_id) {
            $r = $this->resend_customer_invoice($order_id);
            if ($r['result'] === 'sent') {
                $summary['totals']['sent']++;
            } elseif ($r['result'] === 'error') {
                $summary['totals']['errors']++;
            } else {
                $summary['totals']['skipped']++;
            }
            $summary['actions'][] = $r;
        }

        return $summary;
    }

    /**
     * Enumerate WC order IDs that (a) belong to an ended/sold auction
     * product (winner or TE 2nd/3rd runner-up) AND (b) are currently
     * unpaid (status pending/on-hold/failed, no _paid_date).
     *
     * @return int[]
     */
    public function get_unpaid_auction_order_ids() {
        $unpaid_statuses = array('pending', 'on-hold', 'failed');
        $candidates = array();

        foreach ($this->get_ended_auction_rows() as $row) {
            if (!empty($row['order_id'])) {
                $candidates[(int) $row['order_id']] = true;
            }
            $runners = $this->get_te_runners_up((int) $row['product_id']);
            foreach (array('second', 'third') as $key) {
                $stored = $runners['stored'][$key] ?? null;
                if (!empty($stored['order_id'])) {
                    $candidates[(int) $stored['order_id']] = true;
                }
            }
        }

        $unpaid = array();
        foreach (array_keys($candidates) as $oid) {
            if (!function_exists('wc_get_order')) {
                break;
            }
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }
            $status = $order->get_status();
            $date_paid = $order->get_date_paid();
            if (in_array($status, $unpaid_statuses, true) && !$date_paid) {
                $unpaid[] = (int) $oid;
            }
        }
        sort($unpaid);
        return $unpaid;
    }

    /**
     * If an order is missing billing_email, copy it (plus first/last name)
     * from the customer's WC billing meta or fall back to the WP user
     * record. Persists changes on the order.
     */
    private function ensure_order_billing_from_user($order) {
        if (!$order) return;
        if (!empty($order->get_billing_email())) return;
        $customer_id = (int) $order->get_customer_id();
        if (!$customer_id) return;
        $user = get_userdata($customer_id);
        if (!$user) return;

        $email = get_user_meta($customer_id, 'billing_email', true);
        if (empty($email)) $email = $user->user_email;
        $first = get_user_meta($customer_id, 'billing_first_name', true);
        if (empty($first)) $first = $user->first_name;
        $last  = get_user_meta($customer_id, 'billing_last_name', true);
        if (empty($last))  $last  = $user->last_name;

        if (!empty($email)) {
            $order->set_billing_email($email);
            if (!empty($first)) $order->set_billing_first_name($first);
            if (!empty($last))  $order->set_billing_last_name($last);
            $order->save();
        }
    }

    /**
     * Convenience accessor: last-resent-at timestamp for an order, or ''.
     */
    public static function get_invoice_resent_at($order_id) {
        if (!$order_id || !function_exists('wc_get_order')) {
            return '';
        }
        $order = wc_get_order((int) $order_id);
        return $order ? (string) $order->get_meta(self::ORDER_META_INVOICE_RESENT_AT, true) : '';
    }

    /**
     * @return array{
     *   item_count:int,
     *   sum_winning:float,
     *   sum_paid:float,
     *   sum_outstanding:float,
     *   sum_unknown:float
     * }
     */
    public static function compute_totals(array $rows) {
        $totals = array(
            'item_count'       => count($rows),
            'sum_winning'      => 0.0,
            'sum_paid'         => 0.0,
            'sum_outstanding'  => 0.0,
            'sum_unknown'      => 0.0,
        );
        foreach ($rows as $r) {
            $amount = isset($r['order_total']) && $r['order_total'] !== null
                ? (float) $r['order_total']
                : (float) ($r['winning_amount'] ?? 0);
            $totals['sum_winning'] += $amount;

            if ($r['is_paid'] === true) {
                $totals['sum_paid'] += $amount;
            } elseif ($r['is_paid'] === false) {
                $totals['sum_outstanding'] += $amount;
            } else {
                $totals['sum_unknown'] += $amount;
            }
        }
        return $totals;
    }
}
