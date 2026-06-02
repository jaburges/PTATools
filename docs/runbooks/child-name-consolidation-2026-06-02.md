# Child name consolidation — 2026-06-02

## What changed (single line summary)

Made `azure_user_children.child_name` the canonical source of "Child's Name" across orders, the My Family profile, and Selling > Reports. Adds a "Child's Name" Product Field row, backfills 737 legacy order line items, and replaces the free-text child-name input on product pages with a forced-dropdown + "+ Child" modal.

## Why

The Yearbook and Celebration Book products historically captured the child's name via legacy free-form Product Field labels written directly to `wp_woocommerce_order_itemmeta`:

| Legacy meta_key | Count on prod |
|---|---|
| `Childs Name` | 450 |
| `Child Name` | 212 |
| `Child's Name` | 3 |
| `Child&#039;s Name` | 28 |
| `Student Name` | 151 |
| **Total** | **844 line items across 613 orders** |

None of these were in the central `wp_azure_product_fields` registry, so the Selling > Reports column picker had no "Child's Name" entry, the My Family profile stayed empty even after a parent placed multiple orders, and there was no single authoritative spelling.

## The four-part fix (Parts 1-4 shipped, Part 5 shipped on top)

### Part 1 — One-off backfill MU-plugin (data)

`infra/ops/backfill-child-names.php` (token-gated, dry-run + commit modes, self-deletes on commit).

For each order item with one of the five legacy meta keys, resolve the parent and decide:

| Bucket | Rule | What was done |
|--------|------|---------------|
| Parent resolved by HPOS `customer_id` | direct | 24 line items |
| Parent resolved by `billing_email → wp_users.user_email` | guest checkout match | 792 line items |
| Truly guest (no matching user) | skip | 28 line items |
| **Case A** — parent has 0 children | CREATE new `azure_user_children` row + write `_azure_pf_child_id` on line item | **40 children created**, 40 items linked |
| **Case B** — parent has 1+ children, one matches the order's name (case-insensitive, HTML-entity-decoded) | LINK only (write `_azure_pf_child_id`) | **697 line items linked** |
| **Case C** — parent has children but no name match | REPORT — no DB writes | **79 mismatches** captured for human review |

**Result on prod 2026-06-02:**
- `azure_user_children`: 237 → **277 rows** (+40)
- Order items with `_azure_pf_child_id`: 0 → **737** (+737)
- Order items with legacy meta keys: still **844** (untouched — non-destructive)
- Errors: **0**

Mismatch list at `docs/runbooks/child-name-mismatches-2026-06-02.md`.

### Part 2 — Register "Child's Name" Product Field (registry)

One-off SQL inserted into prod + staging via a token-gated MU-plugin:

| Column | Value |
|---|---|
| id | 21 |
| group_id | 1 (Child Core) |
| label | `Child's Name` |
| field_key | `child_name` |
| scope | `child` |
| field_type | `text` |
| save_to_profile | 1 |
| sort_order | 1 |

The Child Core group was activated (`is_active` 0 → 1) at the same time. It was already mapped to product categories Yearbook, Spirit Wear, Events, Enrichment, so no category-mapping changes were needed.

### Part 3 — Reports resolver fallback chain (read)

Edit to `Azure_Orders_Reports_Columns::product_field_columns()` in v3.131.

For any column whose `field_key === 'child_name'`, the resolver walks this chain (stops at first non-empty):

1. `_pta_child_name` — canonical machine-stable line-item meta written by the modern field-renderer
2. `_azure_product_fields_raw` — modern field payload (looks up by `field_key` or label)
3. `_azure_pf_child_id` — FK into `azure_user_children`, returns `child_name`
4. Legacy text labels in order: `Childs Name`, `Child Name`, `Child's Name`, `Child&#039;s Name`, `Student Name` (HTML-entity decoded)
5. Direct meta lookup by display label

Verified end-to-end on prod: 10 random samples + scan of 100 line items → **100% non-empty resolution rate**.

### Part 4 — Runbook

This file.

## Part 5 — Forced dropdown + "+ Child" modal (UI)

Shipped in v3.132.

Replaces the legacy "type the child's name into a text input" UX with:

- **Always-rendered dropdown** of the parent's children — even with 0 children (shows `-- Select child --` placeholder only)
- **"+ Child" button** next to the dropdown that opens a modal
- **Inline modal** with a single "Child's name" input → `wp_ajax_azure_pf_quick_add_child` → creates a new `azure_user_children` row + auto-attaches to the parent's `connected_family` (creating one if missing) → JS appends the new option to the dropdown and auto-selects it
- **"-- Fill in manually --" option removed**
- The auto-generated `Child's Name` text input (from Part 2's field row) is suppressed on the product page when the dropdown is rendered (`$this->child_selector_rendered` flag) so there's no duplicate UI
- Logged-out / guest checkout still falls back to the text input (no profile to bind a dropdown to)

Files touched in Part 5:
- `Azure Plugin/includes/class-product-fields-module.php` — render + AJAX handler + render-skip flag
- `Azure Plugin/js/product-fields-frontend.js` — modal open/close + AJAX
- `Azure Plugin/css/product-fields-frontend.css` — `.azure-pf-select-row` + modal styles

## How to verify

### Reports column picker

1. Open **Selling > Reports > New Report**
2. Available Columns → **Product Fields** group
3. **"Child's Name"** appears
4. Build a report including `order_id`, `product_name`, `Child's Name`. Preview shows real names for Yearbook line items (mix of linked + legacy fallback).

### My Family / My Children profile

1. Log in as a parent who placed any Yearbook / Celebration Book order before 2026-06-02
2. **My Account → My Children**
3. Their child(ren) appear automatically (whether they were created by the backfill or already existed)

### Product page UI (logged in)

1. Log in as a parent
2. Open any product in the Yearbook / Spirit Wear / Events / Enrichment categories
3. See **Child's Name** as a dropdown labeled `-- Select child --` with the parent's children listed, plus a **+ Child** button to the right
4. Click **+ Child** → modal opens with a name input
5. Type a name, click **Add child** → modal closes, new child is appended to the dropdown and auto-selected, child-scope fields (grade, teacher, allergies) clear and become editable for this new child

### Product page UI (guest)

Loaded out: dropdown + button + modal NOT rendered. The "Child's Name" field row falls through to a regular text input.

## How to roll back

| Layer | Rollback |
|-------|----------|
| Part 5 UI | Revert v3.132 → v3.131 (deploy the older zip). All other parts keep working. |
| Part 3 resolver | Revert the changes to `Azure_Orders_Reports_Columns::product_field_columns()`. Reports column will only resolve via legacy meta-key match by label (about 3 line items would resolve). |
| Part 2 row | Delete row id=21 from `wp_azure_product_fields`. Picker will no longer show "Child's Name". |
| Part 1 backfill | Run `DELETE FROM wp_woocommerce_order_itemmeta WHERE meta_key = '_azure_pf_child_id' AND meta_id > <max_meta_id_before_2026-06-02_14:30>` and `DELETE FROM wp_azure_user_children WHERE id BETWEEN 238 AND 277`. The 40 newly-created `connected_family` rows can be left orphaned without harm. |

The legacy `Childs Name` / `Child Name` / `Student Name` / etc. order item meta was never modified by any part of this work, so rollback never touches actual order history.
