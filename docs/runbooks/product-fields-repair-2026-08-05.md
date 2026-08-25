# Product Fields repair — 2026-08-05

## Summary

Fixed the non-functional "+ Child" button on product pages, restored 265 wiped
child names, and repaired 17 column-shifted rows in `wp_azure_product_fields`.

Reported symptom: on a product page with no children in the "Child's Name"
dropdown, clicking **+ Child** did nothing.

## Root causes

Four independent faults, each sufficient to break the flow on its own.

### 1. Frontend assets were never enqueued

`Azure_Product_Fields_Module::enqueue_frontend_assets()` ran on
`wp_enqueue_scripts` and bailed on `!$product instanceof WC_Product`. That global
is not populated until `the_post` fires inside the loop, which is *after*
`wp_enqueue_scripts`, so the check always failed and neither
`product-fields-frontend.js` nor its CSS ever loaded. Confirmed by grepping the
live HTML: zero occurrences of either asset.

The markup still rendered, because it is emitted on
`woocommerce_before_add_to_cart_button` — inside the loop, where the global *is*
set. The result was a button with no click handler bound.

Fixed by resolving the product from `wc_get_product(get_queried_object_id())`,
which is available at enqueue time.

### 2. Every child row was invisible

All 277 rows in `wp_azure_user_children` had `is_active = 127` while every lookup
filters on `is_active = 1`, so no parent could see any child. `family_id` was
also `0` on all 277 rows despite 220 families existing, so the family-join path
found nothing either.

`is_active` is `tinyint(1)` — a display width, not a boolean — so 127 was stored
without complaint. The table also holds `0000-00-00` datetimes, so this database
has been written with a permissive `sql_mode`; an out-of-range value was silently
clamped to the `tinyint` maximum rather than rejected.

### 3. Child names had been wiped

`child_name` was empty on all 277 rows, though the per-child meta survived
(873 rows across 268 children, holding real grades and teachers).

Names were recovered from order history: 738 line items carry
`_azure_pf_child_id`, and 897 carry `_pta_child_name` alongside legacy label keys
(`Childs Name`, `Child Name`, `Child's Name`, `Child&#039;s Name`,
`Student Name`). 265 of 277 children were recoverable with **zero conflicting
names**, so no judgement calls were needed.

### 4. Field definitions were column-shifted

17 of 21 rows in `wp_azure_product_fields` had values one column out of place.
The tell is a datetime in `user_meta_key`. The shift:

| Column | Actually held |
| --- | --- |
| `field_key` | the field type (`select`, `text`, …) |
| `field_type` | `options_json` |
| `scope` | legacy help text / placeholder |
| `save_to_profile` | `sort_order` |
| `placeholder` | `is_required` |
| `options_json` | legacy per-field `is_active` |
| `user_meta_key` | `created_at` |

Live effect: the Grade field rendered as
`<input type="[&quot;K&quot;,&quot;1&quot;,…]">`, so the K–5 choices ended up in
the `type` attribute and browsers fell back to a free-text box. Teacher and the
duplicate `Child's Name` rendered as `<input type="">`.

Only `child_name` (id 1) and three stray Parent rows in the inactive Enrichment
group (ids 4, 8, 9) were intact.

## Changes

### Code

- `enqueue_frontend_assets()` resolves the product from the queried object.
- Quick-add modal gained a **Grade** select and **Teacher** input.
- `ajax_quick_add_child()` accepts and persists both, validating grade against
  the configured choices so a tampered POST cannot write arbitrary values.
- New `get_child_profile_field_keys()` resolves the grade/teacher storage keys
  from the registry instead of hardcoding, because this install uses
  `childsgrade` while `class-database.php` seeds `child_grade`. Rows whose
  `field_key` is a field-type slug are skipped so it cannot latch onto a
  corrupted row.
- New `get_grade_options()` reads the Grade field's own `options_json`, falling
  back to `K,1,2,3,4,5`.

### Data

Backups first — `wp_azure_product_fields_bak_20260805` (21 rows) and
`wp_azure_user_children_bak_20260805` (277 rows).

| Step | Rows |
| --- | --- |
| Names restored from order history | 265 |
| `is_active` set to 1 (named children) | 265 |
| `is_active` set to 0 (nameless leftovers) | 12 |
| `family_id` backfilled from `connected_family` | 277 |
| Field rows rewritten to canonical values | 17 |
| Corrupted duplicate `Child's Name` (id 21) deleted | 1 |

`field_key` values were chosen to match the meta keys already in use —
`childsgrade` (268 values) and `child_teacher` (236) — so existing saved data
keeps hydrating. Field id 21 was safe to delete: no order itemmeta references
`azure_pf_21`.

Child Core sort order was normalised to Name 10, Grade 20, Teacher 30. Grade
keeps its historical `required = 1`.

## Verification

```
VERIFY_CHILDREN|active=265|inactive=12|named=265|fam_zero=0|total=277
VERIFY_STILL_BROKEN|0
VERIFY_META_MATCH|grade=268|teacher=236
```

`VERIFY_STILL_BROKEN` counts rows with a datetime in `user_meta_key` or a
field-type slug in `field_key`.

## Rollback

```sql
-- Restore either table wholesale from its backup:
DELETE FROM wp_azure_user_children;
INSERT INTO wp_azure_user_children SELECT * FROM wp_azure_user_children_bak_20260805;
```

Drop the `_bak_20260805` tables once the change has been live long enough to
trust. For the code, reactivate the previous container revision.

## Follow-ups

Twelve children could not be named. Three belong to real accounts and hold meta
worth preserving, so they are left `is_active = 0` rather than deleted:

| child_id | account | meta rows |
| --- | --- | --- |
| 96 | bethany@oregonpattern.com (Bethany Maloney) | 5 |
| 110 | shannon.antonsen@outlook.com | 4 |
| 158 | pracs28@gmail.com (Prachi Chitnis) | 4 |

The remaining nine (ids 229–237) all belong to `user_id = 179`, which does not
exist in `wp_users`, and carry no meta. They are junk and can be deleted.

Worth doing separately: `is_active` should be constrained, or writes to it
validated, so a clamped value cannot silently hide every row again.
