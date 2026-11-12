<?php
/**
 * AI Connectors Stripe tools (against a fake WC_Stripe_API) and the saved
 * report argument handling.
 *
 * Run: php tests/test-ai-connectors-finance-reports.php
 */

require_once __DIR__ . '/ai-connectors-shim.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-private-storage.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ai-connectors-module.php';
Azure_AI_Connectors_Module::load();

function wp_date($format, $ts = null) {
    return (new DateTimeImmutable('@' . (int) $ts))->setTimezone(wp_timezone())->format($format);
}

class Fake_Order {
    public $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_order_number() { return 'N' . $this->id; }
}
function wc_get_product($id = 0) { return false; }
function wc_get_order($id) { return in_array((int) $id, array(501, 502, 503), true) ? new Fake_Order((int) $id) : false; }
function wc_get_orders($args = array()) {
    $by_txn = array('ch_refunded' => 502, 'ch_disputed' => 503);
    return isset($args['transaction_id'], $by_txn[$args['transaction_id']]) ? array($by_txn[$args['transaction_id']]) : array();
}

class WC_Stripe_API {
    public static $key = 'sk_test_x';
    public static $responses = array();
    public static $calls = array();
    public static function get_secret_key() { return self::$key; }
    public static function retrieve($api) {
        self::$calls[] = $api;
        foreach (self::$responses as $prefix => $response) {
            if (strpos($api, $prefix) === 0) {
                return is_callable($response) ? $response($api) : $response;
            }
        }
        return json_decode('{"error":{"message":"No such thing"}}');
    }
}

class Azure_Orders_Reports_Columns {
    public static function for_granularity($granularity) {
        $cols = array('order_id' => array('label' => 'Order'), 'order_total' => array('label' => 'Total'));
        if ($granularity === 'line_item') {
            $cols['product_name'] = array('label' => 'Product');
        }
        return $cols;
    }
}

$t = new TestRunner('AI Connectors finance and reports');
$S = 'Azure_AI_Connectors_Stripe_Tools';

function obj($data) {
    return json_decode(json_encode($data));
}

function stripe_tool($user_id, $name, array $args = array()) {
    AI_Test::login($user_id);
    return Azure_AI_Connectors_MCP::handle_payload(
        array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array('name' => $name, 'arguments' => $args)),
        array('user' => wp_get_current_user(), 'token' => (object) array('id' => 1, 'token_type' => 'pat', 'name' => 't', 'scope' => 'read write'), 'scopes' => Azure_AI_Connectors_Policy::scopes(), 'client_label' => 'Test')
    )['result'];
}

AI_Test::reset();
WP_Shim::$settings = array('enable_ai_connectors' => true);
AI_Test::add_user(1, array('administrator'), 'admin');
AI_Test::add_user(2, array('shop_manager', 'azuread'), 'shop');
AI_Test::add_user(3, array('finance', 'azuread'), 'treasurer');

// ─── Who gets Stripe ─────────────────────────────────────────────────

$names = function ($id) {
    AI_Test::login($id);
    return array_keys(Azure_AI_Connectors_Registry::available(Azure_AI_Connectors_Policy::scopes()));
};
$t->check(in_array('pta_stripe_fees', $names(3), true), 'finance gets the Stripe tools');
$t->check(in_array('pta_stripe_fees', $names(1), true), 'administrators get the Stripe tools');
$t->check(!in_array('pta_stripe_fees', $names(2), true), 'shop managers do not');
foreach (Azure_AI_Connectors_Registry::all() as $name => $tool) {
    if (strpos($name, 'pta_stripe_') === 0) {
        $t->check($tool['kind'] === 'read' && $tool['open_world'] === true, "{$name} is a read-only, outside-service tool");
    }
}
$t->check(!array_filter(array_keys(Azure_AI_Connectors_Registry::all()), function ($n) { return strpos($n, 'refund') !== false; }), 'there is no refund tool');
WC_Stripe_API::$key = '';
$t->check(!in_array('pta_stripe_fees', $names(1), true), 'Stripe tools disappear when the gateway has no key');
WC_Stripe_API::$key = 'sk_test_x';

// ─── Balance and errors ──────────────────────────────────────────────

WC_Stripe_API::$responses = array('balance' => obj(array(
    'livemode'  => false,
    'available' => array(array('amount' => 123456, 'currency' => 'usd')),
    'pending'   => array(array('amount' => 5099, 'currency' => 'usd')),
)));
$bal = stripe_tool(3, 'pta_stripe_balance');
$t->check($bal['isError'] === false, 'balance runs for finance');
$t->equals(array(array('amount' => '1234.56', 'currency' => 'USD')), $bal['structuredContent']['available'], 'amounts in cents become dollars');
$t->equals('50.99', $bal['structuredContent']['pending'][0]['amount'], 'pending balance is reported');
$t->check($bal['structuredContent']['test_mode'] === true, 'test mode is flagged');
$t->equals('500', $S::money(500, 'jpy'), 'zero-decimal currencies are not divided');

WC_Stripe_API::$responses = array('balance' => null);
$err = stripe_tool(3, 'pta_stripe_balance');
$t->check($err['isError'] === true && strpos($err['content'][0]['text'], 'API key') !== false, 'a rejected key is reported clearly');
WC_Stripe_API::$responses = array('balance' => new WP_Error('stripe_error', 'There was a problem connecting to the Stripe API endpoint.'));
$err = stripe_tool(3, 'pta_stripe_balance');
$t->check($err['isError'] === true && strpos($err['content'][0]['text'], 'problem connecting') !== false, 'an outage is reported');
WC_Stripe_API::$responses = array();
$err = stripe_tool(3, 'pta_stripe_balance');
$t->check($err['isError'] === true && strpos($err['content'][0]['text'], 'No such thing') !== false, 'Stripe error messages are passed on');
$denied = stripe_tool(2, 'pta_stripe_balance');
$t->check($denied['isError'] === true, 'a shop manager calling a Stripe tool directly is refused');

// ─── Payouts ─────────────────────────────────────────────────────────

WC_Stripe_API::$calls = array();
WC_Stripe_API::$responses = array('payouts?' => obj(array('has_more' => true, 'data' => array(
    array('id' => 'po_1', 'amount' => 20000, 'currency' => 'usd', 'arrival_date' => 1788220800, 'created' => 1788048000, 'status' => 'paid', 'method' => 'standard'),
))));
$list = stripe_tool(3, 'pta_stripe_list_payouts', array('from' => '2026-09-01', 'to' => '2026-09-30', 'status' => 'paid', 'limit' => 5));
$t->equals('200.00', $list['structuredContent']['payouts'][0]['amount'], 'payouts list amounts');
$t->check($list['structuredContent']['has_more'] === true, 'and whether there are more');
parse_str(parse_url('x?' . explode('?', WC_Stripe_API::$calls[0], 2)[1], PHP_URL_QUERY), $q);
$t->check($q['limit'] === '5' && $q['status'] === 'paid' && isset($q['arrival_date']['gte'], $q['arrival_date']['lte']), 'filters are sent to Stripe');
$t->check((int) $q['arrival_date']['lte'] - (int) $q['arrival_date']['gte'] === 30 * DAY_IN_SECONDS - 1, 'the range covers whole days');
$bad = stripe_tool(3, 'pta_stripe_list_payouts', array('from' => '2026-10-01', 'to' => '2026-09-01'));
$t->check($bad['isError'] === true, 'a backwards date range is refused');

$t->check(stripe_tool(3, 'pta_stripe_get_payout', array('payout_id' => '../balance'))['isError'] === true, 'payout ids are validated');

WC_Stripe_API::$calls = array();
WC_Stripe_API::$responses = array(
    'payouts/po_1' => obj(array('id' => 'po_1', 'amount' => 9420, 'currency' => 'usd', 'arrival_date' => 1788220800, 'status' => 'paid')),
    'balance_transactions?' => function ($api) {
        if (strpos($api, 'starting_after') === false) {
            return obj(array('has_more' => true, 'data' => array(
                array('id' => 'txn_1', 'type' => 'charge', 'amount' => 5000, 'fee' => 175, 'net' => 4825, 'currency' => 'usd', 'created' => 1788000000,
                    'source' => array('object' => 'charge', 'id' => 'ch_a', 'metadata' => array('order_id' => '501'))),
                array('id' => 'txn_2', 'type' => 'charge', 'amount' => 6000, 'fee' => 204, 'net' => 5796, 'currency' => 'usd', 'created' => 1788000100,
                    'source' => array('object' => 'charge', 'id' => 'ch_refunded', 'metadata' => array())),
            )));
        }
        return obj(array('has_more' => false, 'data' => array(
            array('id' => 'txn_3', 'type' => 'refund', 'amount' => -1200, 'fee' => 0, 'net' => -1200, 'currency' => 'usd', 'created' => 1788000200,
                'source' => array('object' => 'refund', 'id' => 're_1', 'charge' => 'ch_refunded')),
            array('id' => 'txn_po', 'type' => 'payout', 'amount' => -9421, 'fee' => 0, 'net' => -9421, 'currency' => 'usd', 'created' => 1788000300, 'source' => 'po_1'),
        )));
    },
);
$po = stripe_tool(3, 'pta_stripe_get_payout', array('payout_id' => 'po_1'))['structuredContent'];
$t->equals('94.20', $po['amount'], 'the payout amount');
$t->equals(3, count($po['transactions']), 'every transaction across pages is listed, without the payout itself');
$t->equals(array(501, 502, 502), array_column($po['transactions'], 'order_id'), 'charges and refunds are matched to orders by metadata or transaction id');
$t->equals('N501', $po['transactions'][0]['order_number'], 'with the order number');
$by_type = array_column($po['totals_by_type'], null, 'type');
$t->equals(array('count' => 2, 'gross' => '110.00', 'fee' => '3.79', 'net' => '106.21'), array_intersect_key($by_type['charge'], array_flip(array('count', 'gross', 'fee', 'net'))), 'charge totals');
$t->equals('-12.00', $by_type['refund']['net'], 'refund totals');
$t->check(strpos(WC_Stripe_API::$calls[1], 'payout=po_1') !== false && strpos(WC_Stripe_API::$calls[1], 'expand') !== false, 'transactions are fetched for the payout with their sources');
$t->check(strpos(WC_Stripe_API::$calls[2], 'starting_after=txn_2') !== false, 'paging continues from the last transaction');

// ─── Fees and net income ─────────────────────────────────────────────

$sep = strtotime('2026-09-10 12:00:00 UTC');
$oct = strtotime('2026-10-05 12:00:00 UTC');
$summary = $S::summarize(array(
    array('type' => 'charge', 'reporting_category' => 'charge', 'amount' => 10000, 'fee' => 320, 'net' => 9680, 'currency' => 'usd', 'created' => $sep),
    array('type' => 'refund', 'reporting_category' => 'refund', 'amount' => -2000, 'fee' => 0, 'net' => -2000, 'currency' => 'usd', 'created' => $sep),
    array('type' => 'adjustment', 'reporting_category' => 'dispute', 'amount' => -1500, 'fee' => 1500, 'net' => -3000, 'currency' => 'usd', 'created' => $oct),
    array('type' => 'stripe_fee', 'reporting_category' => 'fee', 'amount' => -100, 'fee' => 0, 'net' => -100, 'currency' => 'usd', 'created' => $oct),
    array('type' => 'payout', 'reporting_category' => 'payout', 'amount' => -7680, 'fee' => 0, 'net' => -7680, 'currency' => 'usd', 'created' => $oct),
    array('type' => 'charge', 'amount' => 4000, 'fee' => 146, 'net' => 3854, 'currency' => 'usd', 'created' => $oct),
), 'month');
$t->equals(array('2026-09', '2026-10'), array_keys($summary), 'grouped by month, in order');
$t->equals(10000, $summary['2026-09']['sales'], 'sales');
$t->equals(-2000, $summary['2026-09']['refunds'], 'refunds');
$t->equals(320, $summary['2026-09']['fees'], 'fees');
$t->equals(7680, $summary['2026-09']['net'], 'net after fees and refunds');
$t->equals(-1500, $summary['2026-10']['disputes'], 'disputes');
$t->equals(1500 + 100 + 146, $summary['2026-10']['fees'], 'dispute fees and Stripe billing fees count as fees');
$t->equals(4000, $summary['2026-10']['sales'], 'older transactions without reporting_category are classified by type');
$t->equals(-3000 - 100 + 3854, $summary['2026-10']['net'], 'payouts are not counted as income or spending');
$t->equals(array('2026-09-10', '2026-10-05'), array_keys($S::summarize(array(
    array('type' => 'charge', 'amount' => 1, 'fee' => 0, 'net' => 1, 'currency' => 'usd', 'created' => $sep),
    array('type' => 'charge', 'amount' => 1, 'fee' => 0, 'net' => 1, 'currency' => 'usd', 'created' => $oct),
), 'day')), 'grouped by day');

WC_Stripe_API::$responses = array('balance_transactions?' => obj(array('has_more' => false, 'data' => array(
    array('id' => 't1', 'type' => 'charge', 'reporting_category' => 'charge', 'amount' => 10000, 'fee' => 320, 'net' => 9680, 'currency' => 'usd', 'created' => $sep),
    array('id' => 't2', 'type' => 'charge', 'reporting_category' => 'charge', 'amount' => 5000, 'fee' => 175, 'net' => 4825, 'currency' => 'usd', 'created' => $oct),
))));
$fees = stripe_tool(3, 'pta_stripe_fees', array('from' => '2026-09-01', 'to' => '2026-10-31'))['structuredContent'];
$t->equals(2, count($fees['periods']), 'the fees tool returns a row per month');
$t->equals(array('sales' => '150.00', 'refunds' => '0.00', 'disputes' => '0.00', 'other' => '0.00', 'fees' => '4.95', 'net' => '145.05'), $fees['totals'], 'and totals in dollars');
$t->equals('USD', $fees['currency'], 'with the currency');

WC_Stripe_API::$calls = array();
WC_Stripe_API::$responses = array('balance_transactions?' => obj(array('has_more' => true, 'data' => array(
    array('id' => 'tx', 'type' => 'charge', 'amount' => 100, 'fee' => 3, 'net' => 97, 'currency' => 'usd', 'created' => $sep),
))));
$capped = stripe_tool(3, 'pta_stripe_fees', array())['structuredContent'];
$t->equals($S::MAX_PAGES, count(WC_Stripe_API::$calls), 'paging stops at the page cap');
$t->check(strpos($capped['note'], 'shorter range') !== false, 'and says to use a shorter range');
$t->check($capped['from'] !== null, 'with no dates, the last 30 days are used');

// ─── Disputes ────────────────────────────────────────────────────────

WC_Stripe_API::$responses = array('disputes?' => obj(array('has_more' => false, 'data' => array(
    array('id' => 'dp_1', 'amount' => 2500, 'currency' => 'usd', 'status' => 'needs_response', 'reason' => 'fraudulent', 'created' => $oct,
        'evidence_details' => array('due_by' => $oct + 7 * DAY_IN_SECONDS), 'charge' => array('id' => 'ch_x', 'metadata' => array('order_id' => '501'))),
    array('id' => 'dp_2', 'amount' => 900, 'currency' => 'usd', 'status' => 'lost', 'reason' => 'product_not_received', 'created' => $oct, 'charge' => 'ch_disputed'),
))));
$disputes = stripe_tool(3, 'pta_stripe_list_disputes')['structuredContent']['disputes'];
$t->equals(array('25.00', '9.00'), array_column($disputes, 'amount'), 'dispute amounts');
$t->equals(array(501, 503), array_column($disputes, 'order_id'), 'disputes are matched to orders');
$t->equals('2026-10-12', $disputes[0]['evidence_due_by'], 'the evidence deadline is shown');

// ─── Report arguments ────────────────────────────────────────────────

$R = 'Azure_AI_Connectors_Report_Tools';
$config = $R::apply_args(array(), array('granularity' => 'line_item', 'columns' => array('order_id', 'product_name', 'order_id'), 'date_preset' => 'this_month', 'statuses' => array('wc-completed')));
$t->equals(array(array('key' => 'order_id'), array('key' => 'product_name')), $config['columns'], 'columns are stored as keys, without duplicates');
$t->equals('this_month', $config['date_range']['preset'], 'a date preset is kept');
$t->equals(array('wc-completed'), $config['filters']['statuses'], 'filters are kept');

$bad = $R::apply_args(array(), array('granularity' => 'order', 'columns' => array('order_id', 'product_name')));
$t->check(is_wp_error($bad) && strpos($bad->get_error_message(), 'product_name') !== false, 'unknown columns for the granularity are refused, naming them');

$switched = $R::apply_args($config, array('granularity' => 'order'));
$t->equals(array(array('key' => 'order_id')), $switched['columns'], 'switching to order rows drops line-item columns');
$t->equals('this_month', $switched['date_range']['preset'], 'and keeps the rest of the report');

$dated = $R::apply_args($config, array('date_from' => '2026-09-01', 'date_to' => '2026-09-30'));
$t->check($dated['date_range']['preset'] === null && $dated['date_range']['from'] === '2026-09-01', 'explicit dates replace the preset');
$t->check(is_wp_error($R::apply_args($config, array('date_from' => 'next blursday'))), 'bad dates are refused');
$t->equals(array('wc-completed'), $R::apply_args($config, array('to_today' => true))['filters']['statuses'], 'a partial update keeps the filters');

exit($t->finish() > 0 ? 1 : 0);
