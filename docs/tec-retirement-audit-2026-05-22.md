# TEC Retirement Audit — 2026-05-22

Production state probe (2026-05-22 18:46 UTC):

```text
pta_calendar_owner:       'both'   ← CPT registration: pta_event + tribe_events both registered
pta_calendar_data_source: 'pta'    ← read paths: pta_event only
enable_tec_integration:   true     ← legacy flag, no longer meaningful
tec_plugin_active:        FALSE    ← The Events Calendar plugin is NOT installed

Post type counts:
  tribe_events:    129    ← legacy posts (the TEC plugin doesn't exist; these are zombies)
  pta_event:        87    ← current data
  tribe_venue:       7
  tribe_organizer:   1
  pta_venue:         0
  pta_organizer:     0

User-facing impact of dropping TEC:
  Volunteer sheets:                       0 records   (nothing to migrate)
  Class products with _class_event_ids:   1           (1 product to rewire)
  Calendar mapping rows:                  2           (in wp_azure_tec_calendar_mappings)
  TEC DB tables:                          4           (mappings, conflicts, history, queue)
```

Read paths are already 100% on `pta_event` (the `pta_calendar_data_source = 'pta'` flag flipped to `'pta'` at some earlier point in the v3.9x reconciliation). All visible-to-end-user behaviour already runs on `pta_event`. The remaining TEC code is **either dead** (no callers because The Events Calendar isn't installed) **or dual-writing** (Classes module still creates `tribe_events` posts that nothing reads).

This document categorises every TEC reference and explains what to do with each.

## Bucket A — Working code (KEEP)

Code that runs in production today and uses `pta_event`. No changes needed.

```
Azure Plugin/includes/class-event-cpt.php
Azure Plugin/includes/class-calendar-events-cpt.php
Azure Plugin/includes/class-calendar-events-shortcode.php
Azure Plugin/templates/single-pta_event.php
Azure Plugin/templates/archive-pta_event.php
Azure Plugin/css/pta-event-single.css
Azure Plugin/css/pta-event-archive.css
Azure Plugin/css/pta-event-shared.css
```

Internally these still mention `tribe_events_cat` because the design **deliberately shares the taxonomy slug** between TEC and pta_event so term IDs survive the migration (see `class-event-cpt.php:43-47`). Those references stay.

## Bucket B — Retireable files (DELETE)

Whole files that exist only to integrate with TEC. With TEC not installed, every class in these files short-circuits at construction time and does nothing.

```
Azure Plugin/includes/class-tec-sync-scheduler.php
Azure Plugin/includes/class-tec-sync-engine.php
Azure Plugin/includes/class-tec-integration.php
Azure Plugin/includes/class-tec-integration-minimal.php
Azure Plugin/includes/class-tec-integration-ajax.php
Azure Plugin/includes/class-tec-calendar-mapping-manager.php
Azure Plugin/includes/class-tec-data-mapper.php
Azure Plugin/includes/class-tec-integration-test.php
Azure Plugin/admin/tec-integration-page.php
Azure Plugin/js/tec-admin.js
Azure Plugin/tec-inspect-v2.php                          ← root-level debug script, never should have shipped
Azure Plugin/includes/class-calendar-auth.php.broken     ← already broken, dead file
```

## Bucket C — Cross-reference files (REWIRE then keep)

Files that have legitimate non-TEC purpose but still contain TEC references. These get rewired in place.

| File | What changes |
|---|---|
| `Azure Plugin/azure-plugin.php` | Strip `init_tec_components()`, the TEC AJAX init block, all `tec_*` settings defaults. Change `pta_calendar_owner` default to `'pta'` (was implicit `'tec'`). Bump version to 3.97. |
| `Azure Plugin/includes/class-volunteer-signup.php` | `tec_event_id` → `pta_event_id` everywhere. `Tribe__Events__Main` checks → check pta_event CPT registered. `get_tec_events_for_dropdown()` → `get_pta_events_for_dropdown()`. Meta keys `_EventStartDate` / `_EventVenueID` stay (shared between TEC and pta_event per design). |
| `Azure Plugin/admin/volunteer-page.php` | Dropdown switched from TEC events to pta_event picker. ID + label changes. |
| `Azure Plugin/includes/class-classes-event-generator.php` | `'post_type' => 'tribe_events'` → `'post_type' => 'pta_event'`. Taxonomy slug stays `tribe_events_cat` (shared). Meta keys stay (shared). Strip comments saying "TEC". |
| `Azure Plugin/includes/class-classes-shortcodes.php` | Replace `tribe_get_full_address()` with a local helper that reads the same `_VenueAddress*` meta. `tribe_events_cat` taxonomy lookups stay (shared). |
| `Azure Plugin/includes/class-classes-module.php` | Drop the `tec_missing_notice` admin nag entirely. pta_event has no plugin dependency to nag about. |
| `Azure Plugin/includes/class-classes-product-type.php` | Audit + strip TEC references. |
| `Azure Plugin/admin/classes-page.php` | "TEC events are automatically created…" copy → "Events are automatically created…". `edit.php?post_type=tribe_venue` → `pta_venue`. |
| `Azure Plugin/admin/main-page.php` | Remove TEC module toggle from main dashboard. Rename module list "Calendar Sync (TEC)" → "Calendar Sync". Debug module enum `'TEC'` → drop. |
| `Azure Plugin/includes/class-database.php` | Drop the 4 TEC table definitions. Replace `tec_event_id` in volunteer_sheets schema with `pta_event_id`. Remove TEC entries from `get_table_name` map. |
| `Azure Plugin/includes/class-settings.php` | Remove `tec_*` settings keys from the registered settings list. |
| `Azure Plugin/includes/class-admin.php` | Strip TEC admin menu/tab registration. |
| `Azure Plugin/includes/class-upcoming-module.php` | Audit — likely reads `tribe_events` directly, switch to pta_event. |
| `Azure Plugin/includes/class-tickets-module.php` | Audit — likely uses TEC for ticket attachment to events, switch to pta_event. |
| `Azure Plugin/includes/class-tickets-product-type.php` | Audit + strip TEC references. |
| `Azure Plugin/includes/class-pta-cron.php` | Strip TEC cron hooks if any. |
| `Azure Plugin/includes/class-diagnostics-api.php` | Drop TEC diagnostics endpoints. |
| `Azure Plugin/includes/class-admin-performance.php` | Drop TEC profiling. |
| `Azure Plugin/includes/class-ptsa-rest-api.php` | Strip TEC REST routes. |
| `Azure Plugin/includes/class-calendar-shortcode.php` | If it queries `tribe_events`, switch to pta_event. |
| `Azure Plugin/includes/class-logger.php` | Drop `'TEC'` from the module enum. |
| `Azure Plugin/js/tickets-designer.js` | Strip TEC JS references. |

## Bucket D — Data migration (one-shot MU-plugin)

Live DB changes needed once the code change is deployed:

1. **Rewrite legacy posts:** `UPDATE wp_posts SET post_type='pta_event' WHERE post_type='tribe_events'` (129 rows). Same for `tribe_venue` → `pta_venue` (7 rows) and `tribe_organizer` → `pta_organizer` (1 row). Meta keys are already shared so no postmeta rewrite needed.
2. **Rename DB column:** `ALTER TABLE wp_azure_volunteer_sheets CHANGE tec_event_id pta_event_id BIGINT UNSIGNED DEFAULT 0` (0 affected rows, but keeps schema clean).
3. **Migrate calendar mappings:** Move the 2 rows from `wp_azure_tec_calendar_mappings` into either Calendar Sync settings or a new `wp_azure_calendar_mappings` table. The fields `tec_category_id` / `tec_category_name` become `event_category_id` / `event_category_name`.
4. **Drop TEC-only tables:** `DROP TABLE wp_azure_tec_calendar_mappings, wp_azure_tec_sync_conflicts, wp_azure_tec_sync_history, wp_azure_tec_sync_queue`.
5. **Clean settings:** delete the 9 `tec_*` option keys, delete `enable_tec_integration`, delete `pta_calendar_data_source` (no longer needed), set `pta_calendar_owner = 'pta'`.
6. **Unschedule TEC cron hooks:** `wp_clear_scheduled_hook( 'azure_tec_sync_run' )` and similar.

The migration MU-plugin is delivered in `infra/ops/retire-tec.php` and is **not auto-deployed** — the user runs it once after the code change ships.

## Bucket E — Documentation (UPDATE)

```
Azure Plugin/README.md         — drop TEC requirements section, drop migration callouts, add v3.97 changelog
Azure Plugin/Deadcode.md       — audit, remove TEC entries that are now actually retired
docs/internal/TECmigration.md  — mark as complete (or move into salvage/)
to-do.md                       — strike the TEC retirement section, leave the Page Holding planned item
```

Plugin header `Description:` in `azure-plugin.php` already reads:

> Microsoft 365 integration for WordPress — SSO with Entra ID claims mapping, automated backup to Azure Blob Storage, **Outlook calendar embedding** with shared mailbox support, **native PTA event calendar**, email via Microsoft Graph API, PTA role management with O365 Groups sync, WooCommerce class products with event scheduling, Auction module, Newsletter module, and OneDrive media integration.

No TEC mention, so the header description is already clean.

## Risk assessment

- **None for read paths.** `data_source = 'pta'` already on prod; the public site doesn't render any TEC content today.
- **Low for the 1 class product.** Worst case: existing event posts stay as orphan `tribe_events` posts. Migration step 1 covers this.
- **Low for calendar mappings.** Only 2 rows; user can manually reconfigure if migration misses them.
- **Low for volunteer sheets.** 0 records on prod.
- **None for TEC plugin compatibility.** TEC isn't installed.

## Verification checklist

After the work lands, prod should pass all of:

```bash
# Should return ONLY historical changelog entries and inline migration comments:
rg "TEC|the-events-calendar|tribe_" "Azure Plugin/" | rg -v "changelog|history|\\.md:"

# Production probe should show:
pta_calendar_owner:       'pta'
pta_calendar_data_source: (key gone)
enable_tec_integration:   (key gone)
tribe_events count:       0  (all migrated to pta_event)
wp_azure_tec_*_tables:    none
```
