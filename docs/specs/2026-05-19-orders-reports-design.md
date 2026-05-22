# PTA Tools — Orders Reports Module — Design Spec

**Date:** 2026-05-19
**Status:** Approved (`design_verdict: approve_write_spec` + `change_storage: CPT confirmed`)
**Author:** Jamie Burgess (with Cursor)

## 1. Goal

Replace the third-party "Export Orders" plugin (which the user finds too complicated) with a focused, in-house **Orders Reports** module inside the PTA Tools plugin. The module lets a Shop Manager build, save, preview, and Excel-export WooCommerce order reports — including custom Product Fields data captured at checkout — and later, schedule those reports to be emailed automatically.

This is being shipped in two phases:

- **Ship 1 (this spec):** Manual report builder, preview, Excel export, saved-reports library.
- **Ship 2 (separate spec):** Scheduled automation that runs a saved report on a cadence and emails the resulting file to a recipient list.

## 2. Scope

### In scope (Ship 1)

- Date-range filter (from / to with datetime pickers; "to" defaults to the current time; quick preset buttons).
- Filter by Products, Categories, Tags, and Order Status (multi-select for each). Default order-status set: `processing`, `on-hold`, `completed`, `pending`.
- User-configurable column list with drag-and-drop reorder. Columns grouped by category in the UI.
- Live preview rendering (in-page HTML table, first 100 matched rows).
- Export to Excel — delivered as CSV with `.xls` extension, UTF-8 BOM, `application/vnd.ms-excel` Content-Type. Opens cleanly in Excel, Numbers, and Google Sheets.
- Saved reports stored as a Custom Post Type, shared across all users with `manage_woocommerce`.
- Saved reports list with Run, Edit, Duplicate, Delete actions.
- Two row-granularity modes: **one row per line item** (default) or one row per order.
- Optional inclusion of every field defined in the existing Product Fields module as a column.

### In scope (Ship 2 — referenced but specified separately)

- Schedule a saved report to run on a cadence (Daily / Weekly / Monthly / Custom WP-Cron).
- Recipients (comma-separated email list).
- Date-range preset per schedule (e.g. "Previous calendar month" — common case: send May's orders on June 1st).
- Email delivery via `wp_mail` (which is routed through ACY's configured SMTP).

### Out of scope (deferred to "future")

- Native XLSX (with formatting, multi-sheet, frozen panes). CSV-as-xls covers the workflow.
- PDF export.
- Per-user-owned reports / row-level permissions. All reports are shared across `manage_woocommerce` users.
- A granular `manage_pta_orders_reports` capability. We gate on `manage_woocommerce` for now; can be tightened later via `Azure_Capabilities`.
- Migration of existing "Export Orders" plugin saved reports — users re-build them in the new UI.
- Slack / Teams / webhook notifications.
- Custom HTML email body templating beyond simple placeholders.
- Cell-level CSV formatting (colours, bold, etc.) — out of scope by virtue of CSV format choice.

## 3. User scenarios

Concrete narrated flows the module must support.

### Scenario A — One-off ad-hoc export

> "Treasurer wants a CSV of all completed orders since the start of the auction so she can reconcile against the bank statement."

1. Shop Manager opens **Selling → Reports**.
2. Clicks **New Report**.
3. Sets date range From = 2026-04-15 00:00, To = (defaults to now).
4. Status filter is already set to the default `processing, on-hold, completed, pending`.
5. Adds columns: Order ID, Date Paid, Customer Name, Email, Total, Payment Method.
6. Clicks **Preview** → sees first 100 rows in an HTML table; sees row count and total revenue summary.
7. Clicks **Export to Excel** → file `pta-orders-2026-05-19.xls` downloads.

No save needed.

### Scenario B — Build & save a reusable yearbook report

> "I want a report I can re-run every week of all yearbook orders, including the child's name and grade from product fields, that I can hand to the parent volunteer coordinating distribution."

1. Shop Manager opens **Selling → Reports → New Report**.
2. Filter Products = "2025-26 Wilder Yearbook".
3. Row granularity = "one row per line item".
4. Columns: Order ID, Customer Name, Email, Quantity, **Child's name** (Product Field), **Grade** (Product Field).
5. Clicks **Save report…**, names it "Yearbook fulfillment list".
6. Later weeks: opens **Saved Reports**, clicks **Run** on "Yearbook fulfillment list", clicks **Export**.

### Scenario C — Edit and duplicate

> "I want a 'Spirit Wear' report similar to the yearbook one."

1. Open Saved Reports → Find "Yearbook fulfillment list" → click **Duplicate**.
2. Edit the duplicate: rename "Spirit Wear fulfillment list", change Product filter to Spirit Wear items, save.

### Scenario D — Preview catches a bad filter before exporting

> "I built a date range starting from a year ago by mistake and almost exported 10,000 rows."

1. Preview shows `Total rows matched: 9,872`. Shop Manager realises the date range is wrong, corrects it, previews again, sees `Total rows matched: 45`, exports.

## 4. Architecture

### Tab placement

The module lives as a new tab on the existing `Selling` admin page:

```text
PTA Tools → Selling
  ├─ Auction
  ├─ Classes
  ├─ Product Fields
  ├─ Donations
  └─ Reports                ← new tab; URL: ?page=azure-plugin-selling&tab=reports
```

`admin/selling-page.php` gets one new tab entry; `admin/orders-reports-page.php` is included by the tab switch.

### Module bootstrap

`Azure_Orders_Reports_Module` (singleton, `class-orders-reports-module.php`) is loaded from `azure-plugin.php` only when `enable_orders_reports` setting is on AND `class_exists('WooCommerce')`. Mirrors the existing pattern used by Auction / Classes / Newsletter modules.

### File layout

```text
Azure Plugin/
├── admin/
│   └── orders-reports-page.php                   # tab view; routes to subtabs (new-report | saved | edit)
├── includes/
│   ├── class-orders-reports-module.php           # bootstrap, hook registration, admin-post handlers
│   ├── class-orders-reports-cpt.php              # CPT registration (azure_or_report; azure_or_schedule reserved for Ship 2)
│   ├── class-orders-reports-columns.php          # column registry + value resolvers (incl. Product Fields)
│   ├── class-orders-reports-query.php            # filter → wc_get_orders iterator (paginated, streaming)
│   ├── class-orders-reports-export.php           # streaming CSV writer (BOM, injection guard, .xls headers)
│   └── class-orders-reports-storage.php          # CPT CRUD + JSON config encode/decode helpers
├── js/
│   └── orders-reports-builder.js                 # column drag-and-drop, date presets, AJAX preview
└── css/
    └── orders-reports.css
```

One responsibility per file. None of the files should exceed ~400 lines.

### Data flow (export)

```text
[admin form POST]
   → handle_export_report() in module class
       → check_cap + verify_nonce
       → build report config (from POST or saved CPT)
       → Azure_Orders_Reports_Query::iter($config) [generator]
            → wc_get_orders(...) in batches of 200
               → yield each order, item-by-item or aggregated per config.granularity
       → Azure_Orders_Reports_Export::stream($iter, $config)
            → send HTTP headers
            → write UTF-8 BOM
            → write header row (column labels)
            → write one CSV row per yielded record (resolve each column's value)
            → flush + exit
```

No row is held in memory longer than required to write it out.

### Data flow (preview)

Same pipeline as export, but `iter` is wrapped in an early-return at 100 rows. The HTML is rendered server-side and injected via AJAX into a preview pane.

## 5. Data model

### Custom Post Types

#### `azure_or_report` (Ship 1)

- Hidden from main admin menu (we surface it via our own tab UI).
- `post_title` = report name (human-friendly).
- `post_author` = user ID who last saved it.
- `post_status` = `publish`.
- Postmeta keys:

| Key | Type | Notes |
|---|---|---|
| `_azure_or_config` | longtext (JSON) | The full report configuration. See schema below. |
| `_azure_or_last_exported_at` | datetime | Stamp updated on every export. |
| `_azure_or_last_exported_by` | int (user ID) | |
| `_azure_or_last_exported_rows` | int | Row count of the most recent export. |

#### `azure_or_schedule` (Ship 2 — registered but unused in Ship 1)

CPT defined and registered now so the data model is reserved; Ship 2 implements the cron dispatcher. Out of scope for this spec beyond reserving the slug.

### Report config JSON schema

```json
{
  "version": 1,
  "date_range": {
    "from": "2026-04-15 00:00:00",
    "to": null,
    "preset": null
  },
  "filters": {
    "statuses": ["processing", "on-hold", "completed", "pending"],
    "product_ids": [31613, 31942],
    "category_ids": [],
    "tag_ids": []
  },
  "granularity": "line_item",
  "columns": [
    {"key": "order_id"},
    {"key": "date_paid"},
    {"key": "customer_display_name"},
    {"key": "billing_email"},
    {"key": "product_name"},
    {"key": "qty"},
    {"key": "product_field:Child's name"},
    {"key": "product_field:Grade"}
  ]
}
```

- `date_range.to: null` is resolved to `now` at run time.
- `date_range.preset` is non-null when the user picked a preset (e.g. `"last_30_days"`); on re-run the preset is re-evaluated against the current clock. If `preset` is set, `from`/`to` are recomputed and ignored.
- `granularity` is `"line_item"` (default) or `"order"`.
- `columns[].key` is the column registry slug. Product Fields use the prefix `product_field:` followed by the field label as stored in the `{prefix}azure_product_fields` table.

### Column registry

Defined in `class-orders-reports-columns.php` as a static array of:

```php
[
    'key'        => 'order_id',
    'label'      => 'Order ID',
    'category'   => 'order',              // order | order_amounts | customer | billing | shipping | line_item | product_fields
    'granularity'=> ['order','line_item'], // which row modes this column is valid in
    'resolver'   => callable(order, item|null, ctx) => scalar,
]
```

The Product Fields columns are generated dynamically by querying `{prefix}azure_product_fields` and synthesising one entry per defined field, with `key = 'product_field:' . $label` and a resolver that reads `_azure_product_fields_raw` off the current line item.

Full registry enumeration is in **Appendix A** at the end of this doc.

## 6. UI specification

### `Selling → Reports` tab structure

Subtabs along the top of the tab content area:

- **New Report** (default landing)
- **Saved Reports**
- **Edit Report** (only when editing a specific saved report — accessed via the Saved Reports row action)

### New Report builder layout

```
┌────────────────────────────────────────────────────────────────────────┐
│ Report name: [____________________]                                    │
│                                                                        │
│ ┌─ Date range ─────────────────────────────────────────────────────┐  │
│ │ From: [date+time]   To: [date+time, default=now]                  │  │
│ │ Presets:  (Last 7 days) (Last 30 days) (Previous month)           │  │
│ │           (Previous quarter) (Previous year)                      │  │
│ └───────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│ ┌─ Filters ────────────────────────────────────────────────────────┐  │
│ │ Status (multi):     [☑ processing  ☑ on-hold  ☑ completed         │  │
│ │                      ☑ pending  ☐ cancelled  ☐ refunded  ☐ failed]│  │
│ │ Products (multi):   [autocomplete...]                             │  │
│ │ Categories (multi): [autocomplete...]                             │  │
│ │ Tags (multi):       [autocomplete...]                             │  │
│ └───────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│ ┌─ Row granularity ───────────────────────────────────────────────┐  │
│ │ (•) One row per line item   ( ) One row per order                 │  │
│ └───────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│ ┌─ Columns ────────────────────────────────────────────────────────┐  │
│ │ ┌──── Available ──────┐    ┌──── Selected (drag to reorder) ──┐  │  │
│ │ │ Order               │    │ ≡ Order ID                       │  │  │
│ │ │  ☐ Currency         │    │ ≡ Date Paid                      │  │  │
│ │ │  ☐ Customer Note    │    │ ≡ Customer Name                  │  │  │
│ │ │ Customer            │ →  │ ≡ Email                          │  │  │
│ │ │  ☐ Phone            │    │ ≡ Quantity                       │  │  │
│ │ │ ...                 │    │ ≡ Child's name (product field)   │  │  │
│ │ └─────────────────────┘    └──────────────────────────────────┘  │  │
│ └───────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│  [Preview]   [Save report]   [Save & export]   [Export to Excel]       │
└────────────────────────────────────────────────────────────────────────┘
```

- Drag-and-drop reorder via jQuery UI `sortable` (already shipped with WP core admin).
- "Available" pane is a categorised, collapsible list of columns not currently selected. Checking adds to "Selected"; un-checking removes.
- Preview button submits to an AJAX endpoint that returns a `<table>` HTML fragment of the first 100 matched rows plus a small summary: `Matched X rows · Y line items · Total $Z`.
- Save report prompts (modal or inline) for a name and persists the config to a new or existing `azure_or_report` CPT.
- Export immediately streams the file download.

### Saved Reports tab

A `WP_List_Table`-style list:

| Name | Granularity | Last modified by | Last modified | Last exported | Actions |
|---|---|---|---|---|---|
| Yearbook fulfillment list | per line item | Jamie B | 2026-05-12 | 2026-05-18 | [Run] [Edit] [Duplicate] [Delete] |

Actions:
- **Run** → loads the saved config into the builder (read-only summary at top) → preview and export from there.
- **Edit** → loads the saved config into the builder for editing.
- **Duplicate** → clones the post; opens the duplicate in Edit mode.
- **Delete** → wp_trash_post.

### CSS/JS

- `orders-reports.css`: minimal, mostly layout. Reuses existing `azure-*` admin styling.
- `orders-reports-builder.js`: column DnD, preset-button date filling, AJAX preview, modal save.
- No frontend dependencies beyond what WP admin already enqueues (jQuery, jQuery UI, dashicons).

## 7. Performance

Constraints (the user's words: "easy to use, low compute resource consumption"):

- **Paginated query**: `wc_get_orders` with `limit=200`, page through until exhausted. Never `limit=-1`.
- **Streaming output**: write CSV header → flush → loop the paginated batches → write rows → flush per batch. The PHP process holds at most one batch (200 orders) in memory at a time.
- **Per-item resolution**: column resolvers are pure functions of `(order, item|null, ctx)`. No DB calls inside the row loop except `$order->get_items()` and `$item->get_meta()`, both of which WC's HPOS-aware store handles efficiently.
- **Product Fields registry** is queried **once per request** at the start of the export and cached in a static property; not refetched per item.
- **Preview cap**: hard-coded 100 rows. The matched-rows total is computed via a fast separate count query, not by iterating.
- **No N+1 hooks**: we do not `apply_filters` inside the per-row loop.

## 8. Security

- All AJAX endpoints + admin-post handlers: `if (!current_user_can('manage_woocommerce')) wp_die(...);` + per-action nonces.
- The Reports tab inherits the Selling page's `manage_woocommerce` cap gate already in place.
- CSV-injection guard: any cell whose first character is `=`, `+`, `-`, `@` is prefixed with a single quote `'` before writing.
- All exported text is UTF-8; the BOM is the first three bytes of the file so Excel auto-detects encoding.
- The download endpoint validates the `report_id` (for saved exports) and the full posted config (for ad-hoc) before doing anything.

## 9. Future considerations

(Tracked for possible Ship 3+ work; not implemented now.)

- Native XLSX writer if formatting/multi-sheet requirements emerge.
- Per-user "My Reports" alongside the shared library.
- `manage_pta_orders_reports` capability via `Azure_Capabilities` for finer-grained access control.
- Inline summary statistics in the preview (count by status, sum by category, etc.).
- "Share report" → produces a token URL that emails a one-off CSV (without needing a schedule).
- Background processing for very large exports (> 50,000 rows): write to a temp file via Action Scheduler, email the user when ready.

## 10. Decisions log

| Decision | Choice | Rationale |
|---|---|---|
| Phasing | Ship 1 = Phases 1+2; Ship 2 = Phase 3 automation. | User preference. |
| Excel format | CSV with `.xls` extension + UTF-8 BOM. | Lightest path; opens cleanly in Excel/Numbers/Sheets. No PhpSpreadsheet dependency. |
| Email backend (Ship 2) | `wp_mail` | ACY is the configured SMTP relay; explicit ACY API is unnecessary. |
| Column reorder | jQuery UI `sortable` | Bundled with WP admin; no new JS dependency. |
| Date presets | `last_7_days`, `last_30_days`, `previous_month`, `previous_quarter`, `previous_year` | Covers user-stated workflow ("send May's orders on June 1st" = `previous_month`). |
| Sharing | All reports shared across `manage_woocommerce` users. | Simpler; matches small-team workflow. |
| Storage | Custom Post Type, not custom DB table. | Lower friction at this scale (dozens of reports); free admin UI + author tracking + cache. |
| Row granularity default | One row per line item. | Matches user's "we want the item, refund, price, number ordered" framing. Per-order is a toggle. |
| Automation tick (Ship 2) | One hourly WP-Cron dispatcher checking all schedules. | Simpler than per-schedule cron events; easy to reason about. |
| Capability | `manage_woocommerce` | Avoids new cap; matches Selling page's existing gate. |

## Appendix A — Initial column registry

Static keys defined in `class-orders-reports-columns.php`. (Dynamic Product Fields columns are added at runtime — one per row in `{prefix}azure_product_fields`.)

**Order**
- `order_id`, `order_number`, `order_status`, `order_currency`, `customer_note`, `payment_method`, `payment_method_title`, `transaction_id`, `date_created`, `date_paid`, `date_completed`

**Order Amounts**
- `subtotal`, `discount_total`, `shipping_total`, `total_tax`, `total`, `total_refunded`

**Customer**
- `customer_id`, `customer_login`, `customer_display_name`, `billing_first_name`, `billing_last_name`, `billing_email`, `billing_phone`, `billing_company`

**Billing Address**
- `billing_address_1`, `billing_address_2`, `billing_city`, `billing_state`, `billing_postcode`, `billing_country`

**Shipping Address**
- `shipping_first_name`, `shipping_last_name`, `shipping_address_1`, `shipping_address_2`, `shipping_city`, `shipping_state`, `shipping_postcode`, `shipping_country`

**Line Item** *(only available when `granularity = line_item`)*
- `product_id`, `product_name`, `product_sku`, `qty`, `item_subtotal`, `item_total`, `item_tax`, `item_refunded`

**Aggregated** *(only available when `granularity = order`)*
- `items_summary` — e.g. "Yearbook x1, Spirit Wear x2"
- `total_items` — total quantity across all items

**Product Fields** *(dynamic; `granularity = line_item` only)*
- `product_field:<Label>` — one column per defined field

## Open questions

None — all clarifying questions resolved during the brainstorm. If anything in this spec turns out wrong, we'll amend before merging Ship 1.
