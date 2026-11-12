<?php
/**
 * AI Connectors Stripe tools for finance: balance, payouts (with the orders
 * in each), fees and net income by period, and disputes. Read only: there
 * are no refund, payout or dispute-response tools.
 *
 * Requests go through the WooCommerce Stripe Gateway's own API client
 * (WC_Stripe_API), so they use the keys and mode set in WooCommerce and
 * this module never handles the secret key itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Stripe_Tools {

    /** Pages of 100 fetched at most per call; 2,000 transactions covers a PTA year. */
    const MAX_PAGES = 20;

    const ZERO_DECIMAL = array('bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf');

    /** @var array<string,array|null> charge id => order */
    private static $orders = array();

    public static function register() {
        $r = 'Azure_AI_Connectors_Registry';
        $P = 'Azure_AI_Connectors_Policy';
        $self = __CLASS__;
        $common = array(
            'group'      => 'finance',
            'kind'       => $P::KIND_READ,
            'permission' => array('manage_pta_finance'),
            'access'     => 'stripe',
            'available'  => array($self, 'stripe_active'),
            'open_world' => true,
        );
        $range = array(
            'from' => array('type' => 'string', 'maxLength' => 20, 'description' => 'Start date, e.g. 2026-09-01.'),
            'to'   => array('type' => 'string', 'maxLength' => 20, 'description' => 'End date (inclusive).'),
        );

        $r::register('pta_stripe_balance', $common + array(
            'title'        => 'Stripe balance',
            'description'  => 'Money in the Stripe account: available to pay out now, and pending (not yet settled).',
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'balance'),
        ));
        $r::register('pta_stripe_list_payouts', $common + array(
            'title'        => 'List Stripe payouts',
            'description'  => 'Payouts from Stripe to the bank account, newest first, by arrival date.',
            'input_schema' => array('type' => 'object', 'properties' => $range + array(
                'status' => array('type' => 'string', 'enum' => array('paid', 'pending', 'in_transit', 'canceled', 'failed')),
                'limit'  => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.'),
            )),
            'callback'     => array($self, 'list_payouts'),
        ));
        $r::register('pta_stripe_get_payout', $common + array(
            'title'        => 'Get a Stripe payout with its orders',
            'description'  => 'One payout and every transaction in it (charges, refunds, fees) with the store order each belongs to, plus totals. Use it to match a bank deposit to orders.',
            'input_schema' => array('type' => 'object', 'required' => array('payout_id'), 'properties' => array(
                'payout_id' => array('type' => 'string', 'maxLength' => 100, 'description' => 'e.g. po_...'),
            )),
            'callback'     => array($self, 'get_payout'),
        ));
        $r::register('pta_stripe_fees', $common + array(
            'title'        => 'Stripe fees and net income',
            'description'  => 'Gross sales, refunds, Stripe fees and net for a date range, by month, week or day, from Stripe\'s balance transactions. Payouts to the bank are not income and are left out of the totals.',
            'input_schema' => array('type' => 'object', 'properties' => $range + array(
                'group_by' => array('type' => 'string', 'enum' => array('month', 'week', 'day'), 'description' => 'Default month.'),
            )),
            'callback'     => array($self, 'fees'),
        ));
        $r::register('pta_stripe_list_disputes', $common + array(
            'title'        => 'List Stripe disputes',
            'description'  => 'Disputes and chargebacks, newest first, with amount, reason, status, the evidence deadline and the order.',
            'input_schema' => array('type' => 'object', 'properties' => array(
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.'),
            )),
            'callback'     => array($self, 'list_disputes'),
        ));
    }

    public static function stripe_active() {
        return class_exists('WC_Stripe_API') && (string) WC_Stripe_API::get_secret_key() !== '';
    }

    public static function reset_cache() {
        self::$orders = array();
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * GET from the Stripe API as an array.
     *
     * @return array|WP_Error
     */
    private static function get($path, array $query = array()) {
        $url = $path . ($query ? '?' . http_build_query($query, '', '&') : '');
        $result = WC_Stripe_API::retrieve($url);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === null) {
            return new WP_Error('pta_ai_stripe', 'Stripe did not accept the site\'s API key. Check WooCommerce > Settings > Payments > Stripe.');
        }
        $data = json_decode(wp_json_encode($result), true);
        if (!is_array($data)) {
            return new WP_Error('pta_ai_stripe', 'Stripe sent an unexpected response.');
        }
        if (isset($data['error'])) {
            return new WP_Error('pta_ai_stripe', 'Stripe: ' . (string) ($data['error']['message'] ?? 'request failed.'));
        }
        return $data;
    }

    /**
     * Every item of a list endpoint, up to MAX_PAGES pages.
     *
     * @return array{items:array,truncated:bool}|WP_Error
     */
    private static function get_all($path, array $query) {
        $query['limit'] = 100;
        $items = array();
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = self::get($path, $query);
            if (is_wp_error($data)) {
                return $data;
            }
            $batch = (array) ($data['data'] ?? array());
            $items = array_merge($items, $batch);
            if (empty($data['has_more']) || !$batch) {
                return array('items' => $items, 'truncated' => false);
            }
            $last = end($batch);
            $query['starting_after'] = (string) $last['id'];
        }
        return array('items' => $items, 'truncated' => true);
    }

    public static function money($amount, $currency) {
        $currency = strtolower((string) $currency);
        $value = in_array($currency, self::ZERO_DECIMAL, true) ? (float) $amount : $amount / 100;
        return number_format($value, in_array($currency, self::ZERO_DECIMAL, true) ? 0 : 2, '.', '');
    }

    private static function date($ts) {
        return $ts ? wp_date('Y-m-d', (int) $ts) : null;
    }

    /**
     * @return array{0:int|null,1:int|null}|WP_Error Unix start and end (end of day).
     */
    private static function range(array $args, $default_days = 0) {
        $tz = wp_timezone();
        $out = array(null, null);
        foreach (array('from' => 0, 'to' => 1) as $key => $i) {
            if (empty($args[$key])) {
                continue;
            }
            try {
                $d = new DateTimeImmutable((string) $args[$key], $tz);
            } catch (Exception $e) {
                return new WP_Error('pta_ai_bad_args', $key . ' must be a date like 2026-09-01.');
            }
            $out[$i] = $i === 0 ? $d->setTime(0, 0, 0)->getTimestamp() : $d->setTime(23, 59, 59)->getTimestamp();
        }
        if ($default_days && $out[0] === null) {
            $out[0] = (new DateTimeImmutable('today', $tz))->modify('-' . $default_days . ' days')->getTimestamp();
        }
        if ($out[0] !== null && $out[1] !== null && $out[0] > $out[1]) {
            return new WP_Error('pta_ai_bad_args', 'from must be on or before to.');
        }
        return $out;
    }

    private static function range_query($field, $range) {
        $q = array();
        if ($range[0] !== null) {
            $q[$field . '[gte]'] = $range[0];
        }
        if ($range[1] !== null) {
            $q[$field . '[lte]'] = $range[1];
        }
        return $q;
    }

    /**
     * The store order a charge belongs to: the order_id the gateway writes
     * into the charge metadata, else the order whose transaction id is the
     * charge.
     *
     * @return array{order_id:int,order_number:string}|null
     */
    private static function order_for($charge_id, $metadata_order_id = 0) {
        $charge_id = (string) $charge_id;
        $key = $charge_id . '|' . (int) $metadata_order_id;
        if (array_key_exists($key, self::$orders)) {
            return self::$orders[$key];
        }
        $order = $metadata_order_id ? wc_get_order((int) $metadata_order_id) : null;
        if (!$order && $charge_id !== '') {
            $ids = wc_get_orders(array('transaction_id' => $charge_id, 'limit' => 1, 'return' => 'ids'));
            $order = $ids ? wc_get_order((int) $ids[0]) : null;
        }
        self::$orders[$key] = $order ? array('order_id' => (int) $order->get_id(), 'order_number' => (string) $order->get_order_number()) : null;
        return self::$orders[$key];
    }

    private static function transaction_order(array $txn) {
        $source = $txn['source'] ?? null;
        if (!is_array($source)) {
            return null;
        }
        if (($source['object'] ?? '') === 'charge') {
            return self::order_for((string) $source['id'], (int) ($source['metadata']['order_id'] ?? 0));
        }
        if (($source['object'] ?? '') === 'refund' && !empty($source['charge'])) {
            $charge = is_array($source['charge']) ? (string) ($source['charge']['id'] ?? '') : (string) $source['charge'];
            return self::order_for($charge, (int) ($source['metadata']['order_id'] ?? 0));
        }
        return null;
    }

    // ─── Tools ───────────────────────────────────────────────────────

    public static function balance(array $args) {
        $data = self::get('balance');
        if (is_wp_error($data)) {
            return $data;
        }
        $fmt = function ($rows) {
            return array_map(function ($row) {
                return array('amount' => self::money($row['amount'], $row['currency']), 'currency' => strtoupper((string) $row['currency']));
            }, (array) $rows);
        };
        return array(
            'available' => $fmt($data['available'] ?? array()),
            'pending'   => $fmt($data['pending'] ?? array()),
            'test_mode' => empty($data['livemode']),
        );
    }

    public static function payout_to_array(array $p) {
        return array(
            'id'          => (string) $p['id'],
            'amount'      => self::money($p['amount'], $p['currency']),
            'currency'    => strtoupper((string) $p['currency']),
            'arrival'     => self::date($p['arrival_date'] ?? 0),
            'created'     => self::date($p['created'] ?? 0),
            'status'      => (string) ($p['status'] ?? ''),
            'method'      => (string) ($p['method'] ?? ''),
            'description' => (string) ($p['description'] ?? ''),
            'statement_descriptor' => (string) ($p['statement_descriptor'] ?? ''),
            'failure_message' => (string) ($p['failure_message'] ?? ''),
        );
    }

    public static function list_payouts(array $args) {
        $range = self::range($args);
        if (is_wp_error($range)) {
            return $range;
        }
        $query = array('limit' => (int) ($args['limit'] ?? 20)) + self::range_query('arrival_date', $range);
        if (!empty($args['status'])) {
            $query['status'] = $args['status'];
        }
        $data = self::get('payouts', $query);
        if (is_wp_error($data)) {
            return $data;
        }
        return array(
            'payouts'  => array_map(array(__CLASS__, 'payout_to_array'), (array) ($data['data'] ?? array())),
            'has_more' => !empty($data['has_more']),
        );
    }

    public static function get_payout(array $args) {
        $id = (string) $args['payout_id'];
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $id)) {
            return new WP_Error('pta_ai_bad_args', 'payout_id looks like po_ followed by letters and numbers.');
        }
        $payout = self::get('payouts/' . $id);
        if (is_wp_error($payout)) {
            return $payout;
        }
        $all = self::get_all('balance_transactions', array('payout' => $id, 'expand' => array('data.source')));
        if (is_wp_error($all)) {
            return $all;
        }
        $transactions = array();
        $totals = array();
        foreach ($all['items'] as $txn) {
            $type = (string) ($txn['type'] ?? '');
            if ($type === 'payout') {
                continue;
            }
            $currency = (string) $txn['currency'];
            $order = self::transaction_order($txn);
            $transactions[] = array(
                'id'           => (string) $txn['id'],
                'type'         => $type,
                'date'         => self::date($txn['created'] ?? 0),
                'gross'        => self::money($txn['amount'], $currency),
                'fee'          => self::money($txn['fee'], $currency),
                'net'          => self::money($txn['net'], $currency),
                'description'  => (string) ($txn['description'] ?? ''),
                'order_id'     => $order ? $order['order_id'] : null,
                'order_number' => $order ? $order['order_number'] : null,
            );
            if (!isset($totals[$type])) {
                $totals[$type] = array('count' => 0, 'gross' => 0, 'fee' => 0, 'net' => 0, 'currency' => $currency);
            }
            $totals[$type]['count']++;
            $totals[$type]['gross'] += (int) $txn['amount'];
            $totals[$type]['fee'] += (int) $txn['fee'];
            $totals[$type]['net'] += (int) $txn['net'];
        }
        $by_type = array();
        foreach ($totals as $type => $t) {
            $by_type[] = array(
                'type'  => $type,
                'count' => $t['count'],
                'gross' => self::money($t['gross'], $t['currency']),
                'fee'   => self::money($t['fee'], $t['currency']),
                'net'   => self::money($t['net'], $t['currency']),
            );
        }
        $out = self::payout_to_array($payout) + array(
            'totals_by_type' => $by_type,
            'transactions'   => $transactions,
        );
        if ($all['truncated']) {
            $out['note'] = 'This payout has more than ' . (self::MAX_PAGES * 100) . ' transactions; only the first ones are listed.';
        }
        return $out;
    }

    /** Stripe's reporting_category for older transactions that lack it. */
    private static function category_for_type($type) {
        $map = array(
            'charge' => 'charge', 'payment' => 'charge',
            'refund' => 'refund', 'payment_refund' => 'refund',
            'stripe_fee' => 'fee',
            'payout' => 'payout', 'payout_cancel' => 'payout_reversal', 'payout_failure' => 'payout_reversal',
        );
        return $map[$type] ?? 'other';
    }

    private static function period_key($ts, $group_by) {
        switch ($group_by) {
            case 'day':
                return wp_date('Y-m-d', $ts);
            case 'week':
                return wp_date('o-\WW', $ts);
            default:
                return wp_date('Y-m', $ts);
        }
    }

    /**
     * Totals per period from balance transactions (amounts in the smallest
     * currency unit).
     *
     * @return array<string,array>
     */
    public static function summarize(array $transactions, $group_by) {
        $periods = array();
        foreach ($transactions as $txn) {
            $category = (string) ($txn['reporting_category'] ?? self::category_for_type((string) ($txn['type'] ?? '')));
            if (in_array($category, array('payout', 'payout_reversal'), true)) {
                continue;
            }
            $key = self::period_key((int) $txn['created'], $group_by);
            if (!isset($periods[$key])) {
                $periods[$key] = array('period' => $key, 'currency' => (string) $txn['currency'], 'sales' => 0, 'sales_count' => 0, 'refunds' => 0, 'refund_count' => 0, 'disputes' => 0, 'other' => 0, 'fees' => 0, 'net' => 0);
            }
            $p = &$periods[$key];
            $amount = (int) $txn['amount'];
            if ($category === 'charge') {
                $p['sales'] += $amount;
                $p['sales_count']++;
            } elseif ($category === 'refund' || $category === 'refund_failure') {
                $p['refunds'] += $amount;
                $p['refund_count'] += $category === 'refund' ? 1 : 0;
            } elseif ($category === 'dispute' || $category === 'dispute_reversal') {
                $p['disputes'] += $amount;
            } elseif ($category === 'fee') {
                $p['fees'] += -$amount;
            } else {
                $p['other'] += $amount;
            }
            $p['fees'] += (int) $txn['fee'];
            $p['net'] += (int) $txn['net'];
            unset($p);
        }
        ksort($periods);
        return $periods;
    }

    public static function fees(array $args) {
        $range = self::range($args, 30);
        if (is_wp_error($range)) {
            return $range;
        }
        $group_by = in_array($args['group_by'] ?? '', array('day', 'week'), true) ? $args['group_by'] : 'month';
        $all = self::get_all('balance_transactions', self::range_query('created', $range));
        if (is_wp_error($all)) {
            return $all;
        }
        $rows = array();
        $sum = array('sales' => 0, 'refunds' => 0, 'disputes' => 0, 'other' => 0, 'fees' => 0, 'net' => 0);
        $currency = 'usd';
        foreach (self::summarize($all['items'], $group_by) as $p) {
            $currency = $p['currency'];
            $row = array('period' => $p['period'], 'sales_count' => $p['sales_count'], 'refund_count' => $p['refund_count']);
            foreach ($sum as $key => $unused) {
                $row[$key] = self::money($p[$key], $currency);
                $sum[$key] += $p[$key];
            }
            $rows[] = $row;
        }
        $totals = array();
        foreach ($sum as $key => $value) {
            $totals[$key] = self::money($value, $currency);
        }
        $out = array(
            'from'     => self::date($range[0]),
            'to'       => self::date($range[1] ?? time()),
            'currency' => strtoupper($currency),
            'group_by' => $group_by,
            'periods'  => $rows,
            'totals'   => $totals,
            'note'     => 'Refunds and disputes are negative. fees is what Stripe charged; net is what the account kept after fees, refunds and disputes.',
        );
        if ($all['truncated']) {
            $out['note'] .= ' More than ' . (self::MAX_PAGES * 100) . ' transactions: only the most recent are counted, so use a shorter range.';
        }
        return $out;
    }

    public static function list_disputes(array $args) {
        $data = self::get('disputes', array('limit' => (int) ($args['limit'] ?? 20), 'expand' => array('data.charge')));
        if (is_wp_error($data)) {
            return $data;
        }
        $out = array();
        foreach ((array) ($data['data'] ?? array()) as $d) {
            $charge = $d['charge'] ?? null;
            $charge_id = is_array($charge) ? (string) ($charge['id'] ?? '') : (string) $charge;
            $order = self::order_for($charge_id, is_array($charge) ? (int) ($charge['metadata']['order_id'] ?? 0) : 0);
            $out[] = array(
                'id'              => (string) $d['id'],
                'amount'          => self::money($d['amount'], $d['currency']),
                'currency'        => strtoupper((string) $d['currency']),
                'status'          => (string) ($d['status'] ?? ''),
                'reason'          => (string) ($d['reason'] ?? ''),
                'created'         => self::date($d['created'] ?? 0),
                'evidence_due_by' => self::date($d['evidence_details']['due_by'] ?? 0),
                'order_id'        => $order ? $order['order_id'] : null,
                'order_number'    => $order ? $order['order_number'] : null,
            );
        }
        return array('disputes' => $out, 'has_more' => !empty($data['has_more']));
    }
}
