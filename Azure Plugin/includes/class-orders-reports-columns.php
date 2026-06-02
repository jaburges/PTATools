<?php
/**
 * Orders Reports — Column Registry & Value Resolvers.
 *
 * One source of truth for every column the Orders Reports module can
 * surface. Static columns (Order, Customer, etc.) are hard-coded here;
 * dynamic Product Fields columns are synthesised at runtime from the
 * {prefix}azure_product_fields table.
 *
 * Each column has:
 *   key         machine slug (stable across versions, used in saved configs)
 *   label       human-readable header text in the CSV / preview
 *   category    UI grouping (order|order_amounts|customer|billing|shipping
 *                |line_item|aggregated|product_fields)
 *   granularity which row modes the column is valid in (line_item, order)
 *   resolver    callable(WC_Order $order, WC_Order_Item_Product|null $item,
 *                        array $ctx) : scalar
 *
 * Resolvers MUST be pure functions of their arguments. No DB calls inside
 * the per-row loop are allowed except for $item->get_meta() / $order->get_meta()
 * (both HPOS-safe and locally cached by WC). $ctx may carry pre-fetched
 * data shared across columns within the same export (e.g. the Product
 * Fields registry).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_Columns {

    /** Stored once per request, populated by ::all() on first call. */
    private static $cached_all = null;

    /**
     * Return the full column registry: static columns plus the dynamic
     * Product Fields columns. Cached per request.
     *
     * @return array<string,array{key:string,label:string,category:string,granularity:array<string>,resolver:callable}>
     */
    public static function all() {
        if (self::$cached_all !== null) {
            return self::$cached_all;
        }
        $cols = array_merge(
            self::static_columns(),
            self::product_field_columns()
        );
        $out = array();
        foreach ($cols as $c) {
            $out[$c['key']] = $c;
        }
        self::$cached_all = $out;
        return $out;
    }

    /** Force a refresh on the next ::all() call. */
    public static function invalidate_cache() {
        self::$cached_all = null;
    }

    /**
     * Human-readable category labels for the UI grouping.
     *
     * @return array<string,string>
     */
    public static function categories() {
        return array(
            'order'          => __('Order', 'azure-plugin'),
            'order_amounts'  => __('Order Amounts', 'azure-plugin'),
            'customer'       => __('Customer', 'azure-plugin'),
            'billing'        => __('Billing Address', 'azure-plugin'),
            'shipping'       => __('Shipping Address', 'azure-plugin'),
            'line_item'      => __('Line Item', 'azure-plugin'),
            'aggregated'     => __('Aggregated (per-order mode only)', 'azure-plugin'),
            'product_fields' => __('Product Fields', 'azure-plugin'),
        );
    }

    private static function fmt_money($v) {
        return $v === null ? '' : number_format((float) $v, 2, '.', '');
    }

    private static function fmt_date($d) {
        if (!$d) return '';
        if ($d instanceof WC_DateTime) return $d->date('Y-m-d H:i:s');
        return (string) $d;
    }

    private static function static_columns() {
        return array(
            // ── Order ────────────────────────────────────────────────────
            array('key' => 'order_id',             'label' => 'Order ID',             'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_id(); }),
            array('key' => 'order_number',         'label' => 'Order Number',         'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_order_number(); }),
            array('key' => 'order_status',         'label' => 'Status',               'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_status(); }),
            array('key' => 'order_currency',       'label' => 'Currency',             'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_currency(); }),
            array('key' => 'customer_note',        'label' => 'Customer Note',        'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_customer_note(); }),
            array('key' => 'payment_method',       'label' => 'Payment Method (slug)','category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_payment_method(); }),
            array('key' => 'payment_method_title', 'label' => 'Payment Method',       'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_payment_method_title(); }),
            array('key' => 'transaction_id',       'label' => 'Transaction ID',       'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_transaction_id(); }),
            array('key' => 'date_created',         'label' => 'Date Created',         'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_date($o->get_date_created()); }),
            array('key' => 'date_paid',            'label' => 'Date Paid',            'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_date($o->get_date_paid()); }),
            array('key' => 'date_completed',       'label' => 'Date Completed',       'category' => 'order', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_date($o->get_date_completed()); }),

            // ── Order Amounts ────────────────────────────────────────────
            array('key' => 'subtotal',             'label' => 'Subtotal',             'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_subtotal()); }),
            array('key' => 'discount_total',       'label' => 'Discount',             'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_discount_total()); }),
            array('key' => 'shipping_total',       'label' => 'Shipping',             'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_shipping_total()); }),
            array('key' => 'total_tax',            'label' => 'Tax',                  'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_total_tax()); }),
            array('key' => 'total',                'label' => 'Total',                'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_total()); }),
            array('key' => 'total_refunded',       'label' => 'Total Refunded',       'category' => 'order_amounts', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return self::fmt_money($o->get_total_refunded()); }),

            // ── Customer ─────────────────────────────────────────────────
            array('key' => 'customer_id',           'label' => 'Customer ID',          'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_customer_id(); }),
            array('key' => 'customer_login',        'label' => 'Customer Login',       'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { $u = $o->get_customer_id() ? get_userdata($o->get_customer_id()) : null; return $u ? $u->user_login : ''; }),
            array('key' => 'customer_display_name', 'label' => 'Customer Name',        'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); }),
            array('key' => 'billing_first_name',    'label' => 'Billing First Name',   'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_first_name(); }),
            array('key' => 'billing_last_name',     'label' => 'Billing Last Name',    'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_last_name(); }),
            array('key' => 'billing_email',         'label' => 'Email',                'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_email(); }),
            array('key' => 'billing_phone',         'label' => 'Phone',                'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_phone(); }),
            array('key' => 'billing_company',       'label' => 'Company',              'category' => 'customer', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_company(); }),

            // ── Billing Address ──────────────────────────────────────────
            array('key' => 'billing_address_1',     'label' => 'Billing Address 1',    'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_address_1(); }),
            array('key' => 'billing_address_2',     'label' => 'Billing Address 2',    'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_address_2(); }),
            array('key' => 'billing_city',          'label' => 'Billing City',         'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_city(); }),
            array('key' => 'billing_state',         'label' => 'Billing State',        'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_state(); }),
            array('key' => 'billing_postcode',      'label' => 'Billing Postcode',     'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_postcode(); }),
            array('key' => 'billing_country',       'label' => 'Billing Country',      'category' => 'billing', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_billing_country(); }),

            // ── Shipping Address ─────────────────────────────────────────
            array('key' => 'shipping_first_name',   'label' => 'Shipping First Name',  'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_first_name(); }),
            array('key' => 'shipping_last_name',    'label' => 'Shipping Last Name',   'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_last_name(); }),
            array('key' => 'shipping_address_1',    'label' => 'Shipping Address 1',   'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_address_1(); }),
            array('key' => 'shipping_address_2',    'label' => 'Shipping Address 2',   'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_address_2(); }),
            array('key' => 'shipping_city',         'label' => 'Shipping City',        'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_city(); }),
            array('key' => 'shipping_state',        'label' => 'Shipping State',       'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_state(); }),
            array('key' => 'shipping_postcode',     'label' => 'Shipping Postcode',    'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_postcode(); }),
            array('key' => 'shipping_country',      'label' => 'Shipping Country',     'category' => 'shipping', 'granularity' => array('line_item','order'),
                  'resolver' => function ($o, $i, $c) { return $o->get_shipping_country(); }),

            // ── Line Item (line_item granularity only) ───────────────────
            array('key' => 'product_id',            'label' => 'Product ID',           'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? $i->get_product_id() : ''; }),
            array('key' => 'product_name',          'label' => 'Product Name',         'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? $i->get_name() : ''; }),
            array('key' => 'product_sku',           'label' => 'SKU',                  'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) {
                      if (!$i) return '';
                      $p = $i->get_product();
                      return $p ? $p->get_sku() : '';
                  }),
            array('key' => 'qty',                   'label' => 'Quantity',             'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? (int) $i->get_quantity() : ''; }),
            array('key' => 'item_subtotal',         'label' => 'Item Subtotal',        'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? self::fmt_money($i->get_subtotal()) : ''; }),
            array('key' => 'item_total',            'label' => 'Item Total',           'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? self::fmt_money($i->get_total()) : ''; }),
            array('key' => 'item_tax',              'label' => 'Item Tax',             'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) { return $i ? self::fmt_money($i->get_total_tax()) : ''; }),
            array('key' => 'item_refunded',         'label' => 'Item Refunded',        'category' => 'line_item', 'granularity' => array('line_item'),
                  'resolver' => function ($o, $i, $c) {
                      if (!$i || !method_exists($o, 'get_total_refunded_for_item')) return '';
                      return self::fmt_money($o->get_total_refunded_for_item($i->get_id()));
                  }),

            // ── Aggregated (order granularity only) ──────────────────────
            array('key' => 'items_summary',         'label' => 'Items',                'category' => 'aggregated', 'granularity' => array('order'),
                  'resolver' => function ($o, $i, $c) {
                      $parts = array();
                      foreach ($o->get_items() as $it) {
                          $parts[] = $it->get_name() . ' x' . (int) $it->get_quantity();
                      }
                      return implode(', ', $parts);
                  }),
            array('key' => 'total_items',           'label' => 'Total Items (qty sum)','category' => 'aggregated', 'granularity' => array('order'),
                  'resolver' => function ($o, $i, $c) {
                      $sum = 0;
                      foreach ($o->get_items() as $it) {
                          $sum += (int) $it->get_quantity();
                      }
                      return $sum;
                  }),
        );
    }

    /**
     * Legacy meta-key aliases per canonical product field.
     *
     * Historical products (Yearbook, Celebration Book, etc.) wrote
     * their per-line-item meta under inconsistent labels that don't
     * match the canonical labels registered in `wp_azure_product_fields`.
     * Each entry maps a `field_key` → list of legacy meta keys to check
     * as fallbacks. The resolver walks them in order and returns the
     * first non-empty value.
     *
     * Add new aliases as more legacy products are discovered. Values
     * are HTML-entity-decoded on read.
     */
    private static function field_legacy_aliases() {
        return array(
            'child_name'      => array('Childs Name', 'Child Name', "Child's Name", 'Child&#039;s Name', 'Student Name'),
            'childsgrade'     => array('Child(s) Grade', "Child's Grade", "Childs Grade", "Student's Grade", 'Students Grade', 'Grade'),
            'child_teacher'   => array("Child's Teacher", 'Childs Teacher', 'Teacher', "Student's Teacher", "Student's Teacher and Grade", 'Teacher and Grade', 'Teacher/Grade'),
            'parent_1_email'  => array('Parent Email', "Parent's Email"),
            'parent_1_name'   => array('Parent Name', "Parent's Name"),
            'parent_1_cell'   => array('Parent Cell', "Parent's Cell", 'Parent Phone', "Parent's Phone"),
            'allergies'       => array('Allergies', 'Allergy', 'Food Allergies'),
        );
    }

    /**
     * Parent-scope fields where order billing data is a reasonable
     * fallback when neither modern payload nor legacy meta is present.
     * Maps `field_key` → callable($order):string|null.
     *
     * Skipped for refund objects (WC_Order_Refund subclass), which
     * don't expose billing methods. The Reports query feeds refunds
     * through as their own row but they should never have parent
     * contact info attached.
     */
    private static function parent_billing_fallback($field_key, $order) {
        if (!($order instanceof WC_Order)) return null;
        switch ($field_key) {
            case 'parent_1_email':
                $v = $order->get_billing_email();
                return $v ?: null;
            case 'parent_1_name':
                $first = $order->get_billing_first_name();
                $last  = $order->get_billing_last_name();
                $combined = trim($first . ' ' . $last);
                return $combined !== '' ? $combined : null;
            case 'parent_1_cell':
                $v = $order->get_billing_phone();
                return $v ?: null;
        }
        return null;
    }

    /**
     * Synthesise one column per row in {prefix}azure_product_fields so
     * users can pick "Child's name", "Grade", etc. as columns.
     *
     * Resolver priority chain (stop at first non-empty value):
     *   1. `_pta_<field_key>`              — canonical machine-stable meta
     *   2. `_azure_product_fields_raw`     — modern field-renderer payload
     *   3. (child_name only) `_azure_pf_child_id` → look up
     *      `azure_user_children.child_name`
     *   4. **Legacy meta-key aliases** for this `field_key` (see
     *      `field_legacy_aliases()`). HTML-entity-decoded.
     *   5. Direct meta lookup by the canonical display label.
     *   6. (parent_1_* only) Order billing-data fallback so reports
     *      surface SOMETHING for guest-checkout Yearbook orders that
     *      never went through the new flow.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function product_field_columns() {
        $cols = array();
        if (!class_exists('Azure_Database')) {
            return $cols;
        }
        global $wpdb;
        $table = Azure_Database::get_table_name('product_fields');
        if (!$table) {
            return $cols;
        }
        $rows = $wpdb->get_results("SELECT id, label, field_key FROM {$table} WHERE 1=1 ORDER BY sort_order ASC, id ASC");
        if (empty($rows)) {
            return $cols;
        }
        foreach ($rows as $row) {
            $label     = (string) $row->label;
            $field_key = (string) ($row->field_key ?? '');
            if ($label === '') continue;
            $key = 'product_field:' . $label;
            $cols[] = array(
                'key'         => $key,
                'label'       => $label,
                'category'    => 'product_fields',
                'granularity' => array('line_item'),
                'resolver'    => function ($o, $i, $c) use ($label, $field_key) {
                    if (!$i) return '';

                    // 1. Canonical machine-stable meta key.
                    if ($field_key !== '') {
                        $v = $i->get_meta('_pta_' . $field_key, true);
                        if ($v !== '' && $v !== null) {
                            return (string) $v;
                        }
                    }

                    // 2. Modern field-renderer payload.
                    $raw = $i->get_meta('_azure_product_fields_raw', true);
                    if (is_array($raw) && !empty($raw)) {
                        foreach ($raw as $f) {
                            $f_label = isset($f['label']) ? (string) $f['label'] : '';
                            $f_key   = isset($f['field_key']) ? (string) $f['field_key'] : '';
                            $matches = ($f_label === $label) || ($field_key !== '' && $f_key === $field_key);
                            if ($matches && isset($f['value']) && $f['value'] !== '') {
                                return (string) $f['value'];
                            }
                        }
                    }

                    // 3. child_name special: resolve via the canonical
                    //    azure_user_children FK if linked.
                    if ($field_key === 'child_name' || strtolower($label) === "child's name") {
                        $cid = (int) $i->get_meta('_azure_pf_child_id', true);
                        if ($cid > 0 && class_exists('Azure_Database')) {
                            global $wpdb;
                            $children_table = Azure_Database::get_table_name('user_children');
                            if ($children_table) {
                                $name = $wpdb->get_var($wpdb->prepare(
                                    "SELECT child_name FROM {$children_table} WHERE id = %d",
                                    $cid
                                ));
                                if ($name !== null && $name !== '') {
                                    return (string) $name;
                                }
                            }
                        }
                    }

                    // 4. Legacy meta-key aliases for this field.
                    $aliases = self::field_legacy_aliases();
                    if (isset($aliases[$field_key])) {
                        foreach ($aliases[$field_key] as $legacy_key) {
                            $v = $i->get_meta($legacy_key, true);
                            if ($v !== '' && $v !== null) {
                                $decoded = trim(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                                if ($decoded !== '') return $decoded;
                            }
                        }
                    }

                    // 5. Direct meta lookup by display label.
                    $v = $i->get_meta($label, true);
                    if ($v !== '' && $v !== null) {
                        return (string) $v;
                    }

                    // 6. Parent-scope billing fallback. Lets reports show
                    //    Parent 1 Name/Email/Cell for legacy guest orders
                    //    by reading the order's own billing fields.
                    $billing = self::parent_billing_fallback($field_key, $o);
                    if ($billing !== null && $billing !== '') {
                        return $billing;
                    }

                    return '';
                },
            );
        }
        return $cols;
    }

    /**
     * Filter the registry to only columns valid for the given granularity.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function for_granularity($granularity) {
        $out = array();
        foreach (self::all() as $k => $c) {
            if (in_array($granularity, $c['granularity'], true)) {
                $out[$k] = $c;
            }
        }
        return $out;
    }

    /**
     * Default column set when a user clicks "New Report".
     *
     * @return array<int,string>  column keys
     */
    public static function default_columns_for_granularity($granularity) {
        if ($granularity === 'order') {
            return array(
                'order_id', 'date_paid', 'customer_display_name',
                'billing_email', 'order_status', 'total', 'items_summary',
            );
        }
        return array(
            'order_id', 'date_paid', 'customer_display_name',
            'billing_email', 'product_name', 'qty', 'item_total',
        );
    }
}
