# Child fields consolidation — Phase 2 (2026-06-02)

Builds on `child-name-consolidation-2026-06-02.md` (Phase 1). Phase 1 created/linked `azure_user_children` rows so every Yearbook line item has a child record. Phase 2 copies all the **legacy WAPF-era meta values** into canonical, machine-stable keys so we can retire the old WooCommerce Advanced Product Fields plugin without losing data.

## Why

Before Phase 2 the legacy meta keys were the only source for things like grade and teacher. Reports had to fall back to label matching, and the canonical Child profile (`azure_user_children_meta`) had no grade/teacher entries for Yearbook orders. After Phase 2:

- **Each order line item** carries snapshot canonical keys (`_pta_child_name`, `_pta_childsgrade`, `_pta_child_teacher`). The Reports resolver always reads these FIRST — never label-matching the legacy keys.
- **Each linked child profile** has `pta_pf_childsgrade` and `pta_pf_child_teacher` populated so the My Children profile UI reflects what's known.
- **Legacy WAPF meta keys remain untouched** as a rollback / archival record.
- The WAPF plugin can be safely deactivated.

## Single source of truth model

| Layer | Holds | Source |
|-------|-------|--------|
| Order line item meta | `_pta_child_name`, `_pta_childsgrade`, `_pta_child_teacher` | Snapshot at order time, written by the modern Product Fields flow OR this backfill |
| Child profile (`azure_user_children` + `azure_user_children_meta`) | `child_name`, `pta_pf_childsgrade`, `pta_pf_child_teacher` | Editable by parent in My Children. Auto-saved from completed orders. |
| Legacy WAPF meta keys on order items | Original values, archival | Frozen — never modified after this migration |

The Reports column resolver chain (in `Azure_Orders_Reports_Columns::product_field_columns()`) walks:

1. `_pta_<field_key>` ← always wins after Phase 2
2. `_azure_product_fields_raw` (modern in-form payload)
3. `_azure_pf_child_id` → canonical profile lookup
4. Per-field legacy meta aliases (from Phase 1)
5. Direct label-match meta lookup

## Numbers landed on prod (2026-06-02 17:01 UTC)

| Metric | Count |
|--------|-------|
| Candidate line items processed | 844 |
| `_pta_child_name` written | **844** (100%) |
| `_pta_childsgrade` written | **844** (100%, normalized) |
| `_pta_child_teacher` written | **632** (where any teacher value existed) |
| Linked items with `_azure_pf_child_id` | 737 (87.3%) |
| Child profiles updated with grade | **40** (697 already had it from Class enrollments) |
| Child profiles updated with teacher | **50** (524 already had it) |
| Errors | 0 |

## Grade normalization

The grade column on the order is now one of: **PreK, K, 1, 2, 3, 4, 5**. Distribution after migration:

| Grade | Items |
|-------|-------|
| 5 | 215 |
| K | 141 |
| 2 | 136 |
| 1 | 135 |
| 3 | 104 |
| 4 | 102 |
| PreK | 11 |
| **Total** | **844** |

Normalization rules (applied to `Child(s) Grade` / `Child's Grade` / `Childs Grade` / `Grade` plus the grade component of `Teacher and Grade` / `Teacher/Grade`):

| Input examples | → Output |
|----------------|----------|
| `Pre-K`, `PreK`, `Pre K`, `Preschool`, `Pre-Kindergarten` | `PreK` |
| `K`, `Kindergarten`, `Kinder` | `K` |
| `5th Grade`, `5th`, `5`, `Grade 5`, `fifth` | `5` |
| same pattern for 1–4 |

## Teacher / grade splitting

The combined keys `Teacher and Grade`, `Teacher/Grade`, `Student's Teacher and Grade` get split via the same normalizer. Examples that parsed cleanly during the migration:

| Input | Teacher | Grade |
|-------|---------|-------|
| `Hartwell (5th)` | Hartwell | 5 |
| `Schneider (K)` | Schneider | K |
| `Jensen (1st)` | Jensen | 1 |
| `2nd Doherty` | Doherty | 2 |
| `Kinder Johnson` | Johnson | K |
| `4th Newell` | Newell | 4 |

## Non-destructive guarantees

- The 20 legacy WAPF meta keys (`Childs Name`, `Child Name`, `Child(s) Grade`, `Teacher and Grade`, etc.) are NEVER deleted by this migration. They stay on every order item forever as historical record.
- `_pta_<field_key>` snapshots are written with `wc_add_order_item_meta($id, $key, $val, $unique=true)` so re-running never duplicates. The MU-plugin's idempotent "skip if already canonical" check ensures re-runs are no-ops.
- Profile meta uses `Azure_User_Children::update_child_meta()` which upserts (existing row updated, missing row inserted). The migration skips writes when a profile already has a non-empty value, so manually-entered profile data is never clobbered.

## How to roll back

Single SQL per layer:

```sql
-- Drop the canonical line-item snapshots
DELETE FROM wp_woocommerce_order_itemmeta
 WHERE meta_key IN ('_pta_child_name', '_pta_childsgrade', '_pta_child_teacher');

-- Drop the canonical profile entries written by THIS migration
-- (manually identifiable: created_at within ±1 minute of the commit time,
--  or where they came from items linked to azure_user_children.id between
--  the post-Phase-1 ID range)
DELETE FROM wp_azure_user_children_meta
 WHERE meta_key IN ('pta_pf_childsgrade', 'pta_pf_child_teacher')
   AND updated_at >= '2026-06-02 17:00:00'
   AND updated_at <= '2026-06-02 17:05:00';
```

The legacy WAPF meta is untouched throughout, so nothing else needs reversal.

## Retiring the WAPF plugin

After Phase 2 you can safely deactivate **WooCommerce Advanced Product Fields** without losing data. Steps:

1. Spot-check the Reports module: rebuild the Yearbook saved report with `Child's Name`, `Childs Grade`, `Child Teacher`, `Parent 1 Name`, `Parent 1 Email`. Confirm all 200+ rows populate.
2. Spot-check My Account → My Children for several parents: Greyson Burgess and others — confirm grade + teacher show.
3. WP Admin → Plugins → Deactivate "WooCommerce Advanced Product Fields".
4. Watch the next few Yearbook product page loads — they should now use the new Product Fields module's UI (dropdown + + Child + modal) for any future orders.
5. If anything breaks, reactivate WAPF — the historical meta is still there and the plugin will pick up where it left off.

## What this does NOT touch (yet)

These fields are still uncopied. They live in `_azure_product_fields_raw` and label-keyed meta for now; the Reports resolver already reads them via the alias chain so they show up in exports:

- `Allergies` (265 items) — child-scope
- `Self Carry Epi Pen` (450 items) — child-scope
- `Do you allow photos to be taken of your child` (450 items) — child-scope
- `Parent Email` (263 items) — already resolvable via the parent_1_email alias chain + billing fallback
- `Parent Phone Number` (51 items) — already resolvable via parent_1_cell aliases
- `Emergency Contact Name` (450 items) — family-scope; not in the migration scope

If you want to consolidate those too (Phase 3), the same MU-plugin pattern applies. Let me know.
