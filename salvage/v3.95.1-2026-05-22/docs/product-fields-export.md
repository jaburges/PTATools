# Product Fields – export contract

PTA Tools writes product-field values to WooCommerce orders using a stable
machine-friendly meta-key scheme so that order export tools (Advanced Order
Export For WooCommerce, etc.) can produce clean, deduplicated columns
regardless of how the field's display label is later edited.

## Order line item meta

Each line item written by `Azure_Product_Fields_Module` carries:

| Meta key                        | Source                | Purpose                                              |
|---------------------------------|-----------------------|------------------------------------------------------|
| `<Display Label>`               | the field's label     | Human-readable, shown in admin order screen / emails |
| `_pta_<field_key>`              | the field's `field_key` | **Machine-stable.** Use this for exports/reports    |
| `_azure_product_fields_raw`     | full structured array | Internal debug / reprocessing                        |
| `_azure_pf_child_id`            | `Azure_User_Children` | Which child the parent selected at purchase time     |

`field_key` is set on `wp_azure_product_fields.field_key` and is **immutable
after first save**. The display label can be edited freely without breaking
historical exports.

## How to plug into an order-export tool

```php
$columns = Azure_Product_Fields_Module::get_export_columns();
// $columns = ['child_name' => 'Child Name', 'allergies' => 'Allergies', …]

foreach ($columns as $field_key => $label) {
    // Register a column "$label" sourced from line-item meta key
    // "_pta_$field_key" on WC_Order_Item_Product.
}
```

Hook the filter `pta_product_fields_export_columns` to add or rename columns
without editing the plugin:

```php
add_filter('pta_product_fields_export_columns', function ($columns) {
    $columns['child_name'] = 'Student';   // rename in export only
    return $columns;
});
```

## Profile meta keys

When `save_to_profile` is enabled on a field, values are also persisted under:

| Scope    | Storage                                                     |
|----------|-------------------------------------------------------------|
| `parent` | `usermeta` row keyed by `pta_pf_<field_key>`                |
| `child`  | `azure_user_children_meta` row keyed by `pta_pf_<field_key>` for the selected child |

These keys mirror the line-item `_pta_<field_key>` exactly, so a single
`field_key` is the join across orders, parent profiles, and child profiles.

## Migration from legacy / Product Input Fields

Run **Selling → Consolidate** to map old visible labels (e.g. `Child name`,
`Childs name`, `Child(s) name`) onto a single canonical `field_key`. Apply is
gated behind a dry-run preview, and old labels are kept until you remove the
old plugin manually.
