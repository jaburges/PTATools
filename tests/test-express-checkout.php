<?php
/**
 * Express wallets vs required product fields and Free shipping.
 *
 * Run: php tests/test-express-checkout.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-express-checkout.php';

$t = new TestRunner('Express checkout wallets');

$t->equals(true, Azure_Express_Checkout::hide_product_page_wallets(true), 'required fields hide product-page Apple/Google/Amazon Pay');
$t->equals(false, Azure_Express_Checkout::hide_product_page_wallets(false), 'products without required fields keep product-page wallets');

$t->equals(true, Azure_Express_Checkout::keep_zero_fulfillment_for_types(array('staff')), 'staff membership keeps Free shipping for any wallet address');
$t->equals(true, Azure_Express_Checkout::keep_zero_fulfillment_for_types(array('family')), 'family membership keeps Free shipping');
$t->equals(true, Azure_Express_Checkout::keep_zero_fulfillment_for_types(array('individual')), 'individual membership keeps Free shipping');
$t->equals(false, Azure_Express_Checkout::keep_zero_fulfillment_for_types(array()), 'non-membership carts do not force Free shipping');
$t->equals(false, Azure_Express_Checkout::keep_zero_fulfillment_for_types(array('other')), 'unknown types do not force Free shipping');

$t->equals(
    array('free_shipping:1' => 'free_shipping'),
    Azure_Express_Checkout::preferred_zero_rate_keys(array(
        'flat_rate:2' => 'flat_rate',
        'free_shipping:1' => 'free_shipping',
    )),
    'paid rates are dropped so wallets only see Free shipping / pickup'
);
$t->equals(
    array(),
    Azure_Express_Checkout::preferred_zero_rate_keys(array('flat_rate:2' => 'flat_rate')),
    'no zero rate means the cart helper will inject one'
);

$option = Azure_Express_Checkout::stripe_fulfillment_option('free_shipping:1', 'Free shipping', 0);
$params = Azure_Express_Checkout::apply_fulfillment_option_to_stripe_params(
    array(
        'checkout' => array(
            'default_shipping_option' => array('id' => 'pending', 'displayName' => 'Pending', 'amount' => 0),
        ),
        'cart' => array('requestShipping' => true),
        'product' => array('requestShipping' => true, 'shippingOptions' => array(array('id' => 'pending'))),
    ),
    $option
);
$t->equals('free_shipping:1', $params['checkout']['default_shipping_option']['id'], 'Stripe pending option is replaced with Free shipping');
$t->equals('free_shipping:1', $params['cart']['shippingOptions'][0]['id'], 'cart wallets receive the same Free shipping id');
$t->equals('free_shipping:1', $params['product']['shippingOptions'][0]['id'], 'product wallets receive the same Free shipping id');

exit($t->finish() === 0 ? 0 : 1);
