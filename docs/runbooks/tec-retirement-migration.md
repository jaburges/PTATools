# TEC Retirement Migration — `wilderptsa.net` Method

> Status:
> - **`wilderptsa.net`**: cutover complete, plugin v3.91.10, post-cutover state (Phase 5/6, writer still `both`).
> - **`lwptsa.net`**: cutover complete + writer flipped + TEC folder deleted (Phase 6 complete, plugin v3.91.12). See [Final state on `lwptsa.net`](#final-state-on-lwptsanet).

This runbook documents how `wilderptsa.net` was moved off **The Events Calendar (TEC)** plugin and onto a native PTA Tools event system (`pta_event` CPT), so the migration can be replicated on `lwptsa.net` and any other PTA Tools site.

---

## TL;DR

The migration uses a **two-flag, five-phase, dual-write-then-flip** pattern:

1. Ship migration code (gated behind a flag — installs but does nothing by default).
2. Flip writer flag → `tribe_events` and `pta_event` get written together (dual-write).
3. Backfill historical `tribe_events` to `pta_event` (page-able REST endpoint).
4. Verify parity (every `tribe_events` has a clean mirror).
5. Flip reader flag → frontend reads from `pta_event`. TEC plugin can be deactivated.

`tribe_events` posts stay in the database as a rollback safety net. The flag flips are reversible at every step until cleanup runs.

---

## Why this was done

TEC adds non-trivial cost and runtime overhead (extra cron jobs, REST routes, admin UI, schema, JS, etc.) for a feature that — at PTA Tools sites — really only needs to: (a) show events synced from an Outlook shared mailbox on the front end, and (b) let editors author the occasional local event. PTA Tools already owns the Outlook sync, calendar UI, and shortcodes. Retiring TEC removes one plugin from the autoload path, removes its cron load, and consolidates the event domain model under PTA Tools.

---

## Architecture

### Two-flag state machine

Both flags live in the `azure_plugin_settings` option and are read via `Azure_Settings::get_setting()` (per-request cache, Redis-backed).

| Flag | Allowed values | What it controls | Default |
|---|---|---|---|
| `pta_calendar_owner` | `tec` / `both` / `pta` | **Writer-side**: which post types get registered + dual-write target during sync | `tec` |
| `pta_calendar_data_source` | `tribe` / `both` / `pta` | **Reader-side**: which post type front-end shortcodes/templates query | `tribe` |

The flags are **independent** so the writer can dual-write for weeks while the reader stays on `tribe_events`, then switch only the reader once parity is proven.

### Post types and taxonomy

| Post type | Slug | Notes |
|---|---|---|
| `pta_event` | `/event/<slug>/` | New native event CPT. Shares the `_Event*` meta key names with TEC for non-destructive copy. |
| `pta_venue` | `/venue/<slug>/` | Venue CPT (Phase 0+; not actively used yet — Wilder has 0 rows). |
| `pta_organizer` | `/organizer/<slug>/` | Organizer CPT (Phase 0+; not actively used yet — Wilder has 0 rows). |

Categories use a single taxonomy `pta_event_category` with the **public rewrite slug `tribe_events_cat`** (so old `/tribe_events_cat/<slug>/` URLs keep working). The taxonomy attaches to **both** `tribe_events` and `pta_event` while TEC is loaded, so terms are shared by name during the transition.

### Cross-pointers

Each `tribe_events` ↔ `pta_event` mirror pair has bidirectional postmeta:

| On post type | Meta key | Points to |
|---|---|---|
| `tribe_events` | `_pta_event_mirror_id` | the mirrored `pta_event` ID |
| `pta_event` | `_tec_event_mirror_id` | the source `tribe_events` ID |

These are written by `Azure_TEC_Sync_Engine::mirror_to_pta_event()` so subsequent dual-writes are O(1) lookups (no `meta_query`).

### Mirrored postmeta keys (intentionally unchanged)

`pta_event` keeps TEC's exact key names (`_EventStartDate`, `_EventEndDate`, `_EventStartDateUTC`, `_EventEndDateUTC`, `_EventAllDay`, `_EventTimezone`, `_EventTimezoneAbbr`, `_EventDuration`, `_EventVenue`, `_EventVenueID`, `_EventOrganizer`, `_EventOrganizerID`, `_EventURL`, `_EventCost`, `_EventCurrencySymbol`, `_EventCurrencyPosition`, `_EventShowMap`, `_EventShowMapLink`, `_EventHideFromUpcoming`) plus our sync bookkeeping keys (`_outlook_event_id`, `_outlook_sync_status`, `_outlook_last_sync`, `_outlook_last_modified`, `_outlook_calendar_id`, `_sync_direction`) and `_thumbnail_id`.

This is the key design choice that makes the migration **non-destructive and reversible** — flipping flags doesn't touch data; the same meta is readable from both post types.

### Code split

The migration is implemented across these files in the deployed plugin (`v3.91.10` on `wilderptsa.net`):

| File | Role |
|---|---|
| `includes/class-event-cpt.php` (60 KB) | **NEW** — registers `pta_event`/`pta_venue`/`pta_organizer` and the shared taxonomy. Hosts the flag accessors `get_owner()`, `get_data_source()`, `query_post_type()`, `query_taxonomy()`, `tec_required()`. Owns the Phase 4 admin meta-box and the Phase 5 single-event template override. |
| `includes/class-tec-sync-engine.php` (60 KB; +~34 KB vs v3.51) | Outlook → `tribe_events` sync, **plus** `mirror_to_pta_event()` and the public `mirror_one()` entry point used by the backfill endpoint. |
| `includes/class-diagnostics-api.php` | Hosts the migration REST endpoints (`event-counts`, `event-flags`, `event-mirror-test`, `event-parity`, `event-backfill`, `all-day-events`, `cleanup-audit`, `cleanup-execute`, `merge-parents`, `content-audit`, `content-url-scan`, `insert-order`, `drop-tables`). |
| `includes/class-calendar-shortcode.php` (34 KB; +15 KB vs v3.51) | Calendar Embed front-end. Heavily extended to read `pta_event` when `data_source=pta`. |
| `includes/class-pta-cron.php` (15.5 KB) | **NEW** — central cron registry (not migration-specific, but new in this version). |

> ⚠ **None of the migration code is in the local Git repo at `v3.51`.** The migration was built and deployed directly to production via a different machine/agent. Reconciling this back into Git is a follow-up — see [Reconcile prod into Git](#reconcile-prod-into-git).

---

## Phases

### Phase 0 — Install migration code (no behaviour change)

- Deploy plugin code that contains `Azure_Event_CPT` and the sync-engine `mirror_to_pta_event()` method.
- `pta_calendar_owner` defaults to `tec` → `Azure_Event_CPT::register_types()` no-ops, no new post types, no dual-write.
- Site behaviour is **identical** to before. Safe to ship to prod.

### Phase 1 — Verify CPT registers when flag is flipped

```bash
# Smoke test: temporarily flip to 'both' on a low-traffic moment
curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/event-flags?key=$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"owner":"both"}'

# Verify
curl -sS "$BASE/wp-json/pta-tools/v1/diagnostics/event-counts?key=$KEY" | jq .post_types_registered
# Expected: { "tribe_events": true, "pta_event": true, "pta_venue": true, "pta_organizer": true }
```

After this flip, **the next request triggers `flush_rewrite_rules()` once** via a one-shot transient (`pta_event_flush_rewrite_rules`). The new `/event/<slug>/`, `/venue/<slug>/`, `/organizer/<slug>/` rewrites become live without anyone visiting **Settings → Permalinks**.

### Phase 2 — Dual-write (live syncs mirror to `pta_event`)

With `owner=both`, every successful Outlook → `tribe_events` write inside `Azure_TEC_Sync_Engine::create_tec_event_from_outlook()` and `update_tec_event_from_outlook_with_category()` calls `mirror_to_pta_event($tec_event_id)`. That:

1. Looks up an existing mirror via `_pta_event_mirror_id` (cheap), then via `_outlook_event_id` `meta_query` fallback.
2. Either `wp_update_post()` or `wp_insert_post()`s a `pta_event` mirror.
3. Copies the meta keys listed above (deletes empty values rather than storing empty strings).
4. Re-applies category terms by **name** to `pta_event_category` (term IDs differ across taxonomies; names are the bridge).
5. Writes both cross-pointers.

Non-destructive on the source — `tribe_events` is unchanged.

Verify dual-write is firing by triggering a manual sync and inspecting parity:

```bash
curl -sS "$BASE/wp-json/pta-tools/v1/diagnostics/event-parity?key=$KEY" | jq
# Expected (steady state): { "tribe_events_missing_mirror": { "total": 0 }, ... }
```

You can also force a single-event mirror without running the full sync:

```bash
curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/event-mirror-test?key=$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"tec_event_id": 12345}'
```

### Phase 3 — Backfill historical events

For events that existed before the dual-write started, run the backfill endpoint in batches. It iterates `tribe_events` posts in ascending ID order, paginated via `cursor`. Each call is bounded by `batch_size` so it can't hit App Service's 230s ingress timeout.

```bash
# Dry-run first to see what will be touched
curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/event-backfill?key=$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"batch_size": 100, "cursor": 0, "dry_run": true, "include_local": true}'

# Real run, loop until has_more=false
CURSOR=0
while :; do
  RESP=$(curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/event-backfill?key=$KEY" \
    -H 'Content-Type: application/json' \
    -d "{\"batch_size\": 100, \"cursor\": $CURSOR, \"include_local\": true}")
  echo "$RESP" | jq '{counts, last_processed_id, has_more, errors}'
  HAS_MORE=$(echo "$RESP" | jq -r '.has_more')
  CURSOR=$(echo "$RESP" | jq -r '.last_processed_id')
  [ "$HAS_MORE" = "true" ] || break
done
```

Each batch returns:

```json
{
  "counts": {
    "scanned": 100,
    "mirrored": 87,
    "skipped_no_outlook": 0,
    "skipped_already_mirrored": 13,
    "errors": 0
  },
  "last_processed_id": 31999,
  "has_more": true,
  "sample_mirrored": [ { "tec_id": 31790, "pta_id": 26177, "title": "SPRING BREAK - NO SCHOOL" } ],
  "errors": []
}
```

Idempotent — re-running over an already-mirrored event refreshes meta but does not duplicate. `include_local: true` (default) also mirrors locally-authored TEC events with no `_outlook_event_id`.

### Phase 4 — Author UI on `pta_event` (admin-only)

`Azure_Event_CPT` registers a meta-box (`pta_event_details`) and list-table columns on `pta_event` so editors can create/edit events without TEC's classic-editor UI. Not needed for the cutover itself; ship before flipping the reader so the admin experience stays the same when TEC's editor goes away.

### Phase 5 — Cutover (reader flips to `pta_event`)

```bash
curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/event-flags?key=$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"reader":"pta"}'

# Visit any frontend page once to trigger the rewrite-rules rebuild for /event/<slug>/.
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "$BASE/"
```

After this:

- `/event/<slug>/` permalinks resolve to `pta_event` instead of `tribe_events`.
- `Azure_Event_CPT::maybe_swap_to_pta_event_on_single()` rewrites the main query in `pre_get_posts` so the same slug resolves cleanly.
- `Azure_Event_CPT::maybe_load_single_template()` hooks `template_include` at priority 99 so PTA's single-event template wins over the theme's default and over TEC's. This is critical — `single_template` alone gets overwritten by themes that hook the same filter at priority 10.
- Calendar Embed shortcodes (`[azure_calendar]`, `[azure_calendar_events]`) read from `pta_event`.
- Old `/events/` archive and `/tribe_events_cat/<slug>/` URLs keep working because the rewrite slug is intentionally preserved on the new taxonomy.

### Phase 6 — Deactivate TEC plugin

Once Phase 5 has been stable for a few days:

```bash
# Via WP-CLI on the App Service (or the WP admin UI)
wp plugin deactivate the-events-calendar
```

Notes:

- WordPress doesn't validate `post_type` against the registered list when calling `wp_insert_post()`. The TEC sync engine continues to write `tribe_events` posts (which become invisible to `WP_Query` because the type isn't registered) and immediately mirrors them to `pta_event`. The orphan `tribe_events` rows are harmless — they're rollback bait.
- The `tribe/*` REST namespaces and TEC's cron jobs go away, which is the actual cost reduction.
- `tribe_events_cat` taxonomy is **still registered by `Azure_Event_CPT`** at this point (the `if (post_type_exists('tribe_events'))` branch goes false and `tribe_events` is no longer attached, but the taxonomy itself remains under the public slug for `pta_event`). Old category URLs keep working.

### Phase 7 — (Optional, much later) Cleanup

After months of stable operation, if rollback is no longer plausible, use the cleanup endpoints to drop orphan `tribe_events` posts:

```bash
# Dry-run audit: what would be removed
curl -sS "$BASE/wp-json/pta-tools/v1/diagnostics/cleanup-audit?key=$KEY" | jq

# Actually delete (every mutation is backed up to JSON in /wp-content/backups/)
curl -sS -X POST "$BASE/wp-json/pta-tools/v1/diagnostics/cleanup-execute?key=$KEY" \
  -H 'Content-Type: application/json' \
  -d '{"actions": ["delete_orphan_tribe_events"], "dry_run": false}'
```

**Don't run Phase 7 lightly.** Keep `tribe_events` posts indefinitely if they're not causing problems — they're a few MB of meta and zero runtime cost once TEC is deactivated.

---

## Rollback paths

Because the meta keys are shared and the flags are independent, every step is reversible:

| If something goes wrong at... | Rollback action |
|---|---|
| Phase 1 (CPT registers but breaks site) | `POST event-flags {"owner":"tec"}`. CPTs unregister on next request. |
| Phase 2 (dual-write fails) | Same — flip `owner` back to `tec`. `pta_event` posts stay; just no longer written. |
| Phase 3 (backfill produces bad data) | The backfill is idempotent. Fix the bug in `mirror_to_pta_event()`, re-run. Or `cleanup-execute` the bad `pta_event` rows. |
| Phase 5 (frontend looks wrong on `pta_event`) | `POST event-flags {"reader":"tribe"}`. Rewrite rules re-flush; `/event/<slug>/` resolves to `tribe_events` again. |
| Phase 6 (something needs TEC re-enabled) | Reactivate the TEC plugin. The `tribe_events` posts are still there with all their meta intact. |

There is **no point of no return** until Phase 7 cleanup.

---

## Current state on `wilderptsa.net` (snapshot 2026-05-12)

```
plugin_version       3.91.10
TEC plugin           DEACTIVATED
pta_calendar_owner   both          # writer still dual-writes (rollback safety)
pta_calendar_data_source pta       # reader is on pta_event (cutover done)

Post types registered:
  tribe_events       false
  tribe_venue        false
  pta_event          true
  pta_venue          true
  pta_organizer      true

Counts:
  tribe_events  publish=77 draft=10 tribe-ignored=42  total=129
  tribe_venue                                         total=7
  pta_event     publish=77 draft=10                   total=87
  pta_venue                                           total=0
  pta_organizer                                       total=0

Mirror integrity:
  tribe_events_with_mirror   87
  pta_event_with_outlook_id  46
  tribe_events_missing_mirror   0
  pta_events_orphaned           0
  date_mismatches               0
```

Wilder is in **Phase 5/6** — reader cut over, TEC deactivated, but writer still set to `both` so any glitch in the new path is recoverable by flipping the reader back. The `tribe-ignored` 42 are TEC posts intentionally not mirrored (legacy "ignore" status from TEC's own recurring-event handling).

---

## Replication on `lwptsa.net`

`lwptsa.net` is **pre-migration**:

```
plugin_version       3.67          # 24 versions behind wilder
TEC plugin           ACTIVE
pta_calendar_owner   tec  (default — flag never set)
pta_calendar_data_source tribe (default)
event-counts diagnostic   404 (migration code not deployed)
```

So lwptsa needs **all of Phases 0–6** done in order, with one extra step at the start: shipping the migration code.

### Step 0 — Ship `v3.91.10`+ plugin code to `lwptsa.net`

The migration code lives entirely in the PTA Tools plugin (`Azure Plugin/`), with no theme or DB schema changes. Two safe ways to deploy:

**Option A — Mirror the deployed plugin from wilder (fastest, exactly what's on prod today):**

```bash
# From this repo / laptop
SRC_SCM="https://wilderptsa.scm.azurewebsites.net"
DST_SCM="https://lwptsa.scm.azurewebsites.net"

# (a) Pull current deployed plugin from wilder
az webapp deployment source download -g PTSAWebsite -n wilderptsa \
  --output-file /tmp/wilder-azure-plugin.zip
# OR use Kudu zip API:
curl -u "\$wilderptsa:$WILDER_PUB_PASS" \
  -o /tmp/wilder-azure-plugin.zip \
  "$SRC_SCM/api/zip/site/wwwroot/wp-content/plugins/azure-plugin/"

# (b) Deploy to lwptsa staging slot (if it has one) or production with a ~10s blip
curl -u "\$lwptsa:$LW_PUB_PASS" -X POST \
  --data-binary @/tmp/wilder-azure-plugin.zip \
  -H 'Content-Type: application/zip' \
  "$DST_SCM/api/zipdeploy?path=site/wwwroot/wp-content/plugins/azure-plugin"
```

**Option B — Reconcile the migration code into this Git repo first, then deploy via the existing `dev → staging → prod` GitHub Actions pipeline.** Slower but produces a Git audit trail. See [Reconcile prod into Git](#reconcile-prod-into-git).

### Step 1 — Verify plugin loaded and CPTs gated off

```bash
LW_KEY="bApWaSloxQJ04VBaZ5TNFnQLVDYb0UtIRqUmFHencnVNtRS7"
LW="https://lwptsa.net"

curl -sS "$LW/wp-json/pta-tools/v1/diagnostics/event-counts?key=$LW_KEY" | jq
# Expected immediately after deploy:
#   "flags": { "pta_calendar_owner": "tec", "pta_calendar_data_source": "tribe" }
#   "post_types_registered": { "pta_event": false, "tribe_events": true }
```

If `event-counts` returns `rest_no_route`, the plugin didn't reload — try `az webapp restart` to bust opcache.

### Step 2 — Phase 1+2: flip writer to `both`

```bash
curl -sS -X POST "$LW/wp-json/pta-tools/v1/diagnostics/event-flags?key=$LW_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"owner":"both"}'

# Hit any front-end page once to trigger the rewrite-rules rebuild
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "$LW/"

# Verify
curl -sS "$LW/wp-json/pta-tools/v1/diagnostics/event-counts?key=$LW_KEY" | jq .post_types_registered
# Expected: { "tribe_events": true, "pta_event": true, "pta_venue": true, "pta_organizer": true }
```

From this moment the **next Outlook → TEC sync** (the existing hourly `azure_tec_mapping_sync_1` cron) starts dual-writing. New events get a `pta_event` mirror automatically.

### Step 3 — Phase 3: backfill the 33 already-synced events (and the 31 legacy)

The previous lwptsa investigation found 63 `tribe_events`, of which 33 were Outlook-synced and 31 were legacy local-only. Backfill all of them:

```bash
CURSOR=0
while :; do
  RESP=$(curl -sS -X POST "$LW/wp-json/pta-tools/v1/diagnostics/event-backfill?key=$LW_KEY" \
    -H 'Content-Type: application/json' \
    -d "{\"batch_size\": 100, \"cursor\": $CURSOR, \"include_local\": true}")
  echo "$RESP" | jq '{counts, last_processed_id, has_more}'
  HAS_MORE=$(echo "$RESP" | jq -r '.has_more')
  CURSOR=$(echo "$RESP" | jq -r '.last_processed_id')
  [ "$HAS_MORE" = "true" ] || break
done

# Should converge in 1 batch (lwptsa has only ~63 events).
```

### Step 4 — Verify parity

```bash
curl -sS "$LW/wp-json/pta-tools/v1/diagnostics/event-parity?key=$LW_KEY" | jq
# Want: tribe_events_missing_mirror.total = 0
#       pta_events_orphaned.total = 0
#       date_mismatches.total = 0
```

If anything is non-zero, **stop**. Inspect the `sample` arrays in the response, fix the offender (usually a missing meta key or a date-format edge case), and re-run backfill (idempotent).

### Step 5 — Phase 5: flip reader to `pta`

```bash
curl -sS -X POST "$LW/wp-json/pta-tools/v1/diagnostics/event-flags?key=$LW_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"reader":"pta"}'

curl -sS -o /dev/null -w 'HTTP %{http_code}\n' "$LW/"
```

**Manual check:** open the front-end calendar page, confirm events render correctly; click into a single event, confirm `/event/<slug>/` resolves and the page renders with the PTA single-event template.

If anything looks wrong, immediately:

```bash
curl -sS -X POST "$LW/wp-json/pta-tools/v1/diagnostics/event-flags?key=$LW_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"reader":"tribe"}'
```

### Step 6 — Phase 6: deactivate TEC plugin

After ~24–72 h of stable Phase 5 with no front-end issues:

```bash
# Via WP admin: Plugins → The Events Calendar → Deactivate
# OR via WP-CLI on the App Service:
az webapp ssh -g <RG> -n lwptsa --command "wp plugin deactivate the-events-calendar --path=/home/site/wwwroot"
```

Confirm:

```bash
curl -sS "$LW/wp-json/pta-tools/v1/diagnostics/event-counts?key=$LW_KEY" | jq .plugins
# Expected: { "tec_active": false }
```

The hourly `azure_tec_mapping_sync_1` cron continues to run; the sync engine continues to "create" `tribe_events` posts (now invisible to WP_Query) and immediately mirrors them to `pta_event`. Front-end users see no difference.

### Step 7 — Done

You can leave `pta_calendar_owner=both` indefinitely (matches Wilder's current state). It costs ~1ms per sync (one extra `wp_insert_post`). Don't run Phase 7 cleanup unless you're sure rollback is no longer needed.

---

## Reconcile prod into Git

The migration code on `wilderptsa.net` (v3.91.10) is **not in this Git repo** (currently v3.51). Replicating on `lwptsa.net` either pulls plugin code straight off `wilderptsa` (Option A above — works but bypasses Git) or first reconciles the prod code into Git.

The reconciliation is its own project. The minimum diff is:

| File | Action |
|---|---|
| `Azure Plugin/includes/class-event-cpt.php` | **Add** (new file, 60 KB, copy from prod) |
| `Azure Plugin/includes/class-pta-cron.php` | **Add** (new file, 15 KB, copy from prod) |
| `Azure Plugin/includes/class-tec-sync-engine.php` | **Replace** with prod version (adds `mirror_one()`, `mirror_to_pta_event()`, `pta_event_mirrored_meta_keys()`, `find_pta_event_by_outlook_id()`) |
| `Azure Plugin/includes/class-diagnostics-api.php` | **Replace** with prod version (adds the migration REST routes) |
| `Azure Plugin/includes/class-calendar-shortcode.php` | **Replace** with prod version (heavily extended for `pta_event` reads) |
| `Azure Plugin/includes/class-pta-manager.php` | Diff against prod (~78 byte diff) |
| `Azure Plugin/includes/class-pta-sync-engine.php` | Diff against prod (~492 byte diff) |
| `Azure Plugin/includes/class-pta-groups-manager.php` | Diff against prod (~797 byte diff) |
| `Azure Plugin/includes/class-pta-beaver-builder.php` | **Local is bigger** — investigate which fork wins (1.8 KB prod vs 4.7 KB local) |
| `Azure Plugin/azure-plugin.php` | Bump version to match (and verify the `require_once` list includes `class-event-cpt.php` and `class-pta-cron.php`) |
| `Azure Plugin/admin/calendar-page.php` | Diff against prod (likely UI updates) |
| Plus ~24 versions of unrelated drift in other files (settings, newsletter, classes, auction, donations, parent, product fields, etc.) | One commit per logical change is ideal. |

Recommended approach: pull the entire deployed `Azure Plugin/` tree from wilder into a scratch worktree, run `git diff` against the local repo, group changes by feature, and land them as a sequence of small PRs. The `dev` → `main` workflow we set up handles the actual deployment.

---

## REST endpoint cheat sheet

All migration endpoints live under `/wp-json/pta-tools/v1/diagnostics/` and require `?key=$WORDPRESS_DIAGNOSTIC_KEY`.

| Method | Path | Use |
|---|---|---|
| `GET` | `/event-counts` | Snapshot: flags, post-type registration, post counts, mirror counts, TEC active state. |
| `POST` | `/event-flags` | Body `{"owner":"tec\|both\|pta", "reader":"tribe\|both\|pta"}`. Both fields optional. |
| `GET` | `/event-parity` | Lists tribe_events_missing_mirror, pta_events_orphaned, date_mismatches. |
| `POST` | `/event-mirror-test` | Body `{"tec_event_id": <int>}`. Manually mirrors one TEC post to `pta_event`. |
| `POST` | `/event-backfill` | Body `{"batch_size":<1..500>, "cursor":<int>, "dry_run":<bool>, "include_local":<bool>}`. |
| `GET` | `/all-day-events` | Lists all-day events on both post types in a window (sanity check for timezone bugs). |
| `GET` | `/cleanup-audit` | What's left to clean up (orphan posts, drop-able tables, etc.). |
| `POST` | `/cleanup-execute` | Body `{"actions":[...], "dry_run":<bool>}`. Every mutation backed up to `/wp-content/backups/`. |
| `GET` | `/content-audit` | Front-end content scan (find pages still using old shortcodes / TEC blocks). |
| `GET` | `/content-url-scan` | Find references to old `/events/` URLs in post_content. |
| `GET` | `/insert-order` | TEC vs PTA insert order for a given event (debug timing issues). |
| `GET\|POST` | `/merge-parents` | Merge a duplicate `pta_event` (winner+loser). Used for cleanup. |

---

## Final state on `lwptsa.net`

As of plugin v3.91.12 (deployed 2026-05-20):

```
plugin_version             3.91.12
TEC plugin                 DEACTIVATED + folder deleted from /wp-content/plugins/
pta_calendar_owner         pta          # writer no longer creates tribe_events on sync
pta_calendar_data_source   pta          # reader on pta_event
event-tickets plugin       still present on disk (deactivated, used to pair with TEC Tickets)
all-in-one-event-calendar  still present on disk (deactivated, 754 legacy ai1ec_event posts in DB)

Post types registered      pta_event/pta_venue/pta_organizer = true
                           tribe_events/tribe_venue          = false

Counts (frozen):
  tribe_events (orphan)    publish 63, draft 1, trash 1, _total 65   # kept as rollback safety net
  tribe_venue (orphan)     publish 20
  pta_event                publish 54, draft 1
  pta_venue/pta_organizer  0

Parity (clean):
  tribe_events_missing_mirror   0
  pta_events_orphaned           0
  date_mismatches               0
```

### Display / UX additions (v3.91.11)

- Single event page (`/event/<slug>/`):
  - Featured image renders above the title when set (`.pta-event-hero`)
  - **Join meeting** block-style button when a Teams/Zoom/Meet/etc. URL is found in the body, venue, or `_EventURL` (`.pta-event-join`)
- Events archive (`/events/`):
  - List view: optional featured-image thumb on each card + **Join meeting** inline button per card
  - Calendar grid: small camera icon on event chips when an online meeting is detected (`.pta-events-calendar-chip.has-meeting`)
- `[azure_calendar_events]` shortcode:
  - Now reads directly from `pta_event` when `data_source=pta` (was Outlook-only before — placeholder stub `Azure_Calendar_EventsShortcode` was overriding the real implementation; that conflict is now resolved)
  - New attributes: `show_image`, `show_join_meeting`, `source` (`pta`/`outlook`/auto)
  - Renders as a card grid with image + Join button per event
- `[azure_calendar]` shortcode:
  - `id` is now optional when reading from `pta_event` (no Outlook calendar to map to)
  - `source` attribute is now properly declared (was being silently filtered out by `shortcode_atts`)
- `/calendar/` page content rewritten to use `[azure_calendar source="pta"]` (month grid) + `[azure_calendar_events source="pta" limit="8" show_image="true" show_join_meeting="true"]` (upcoming cards)
- New static helper `Azure_Event_CPT::extract_online_meeting_url($post_id)` scrapes Teams/Zoom/Meet/Webex/etc. URLs from post body / venue / event URL. Used by every renderer so the rules stay consistent.

### Calendar Sync admin UI + category propagation fix (v3.91.12)

Three follow-on issues surfaced after TEC was fully removed; all fixed in this version.

1. **Admin UI was screaming at you for no reason.** The `Azure Plugin → Calendar Sync` tab still hard-checked for `Tribe__Events__Main` and rendered "The Events Calendar Plugin Not Found" + "Install The Events Calendar" CTA + a global `admin_notices` red bar across every admin page, even though the sync was happily writing to `pta_event` underneath. Fixed:
   - `Azure_TEC_Integration::__construct` no longer registers the orange `tec_dependency_notice` admin nag when `Azure_Event_CPT::is_pta_owner_active()` is true.
   - `admin/tec-integration-page.php` rebranded from "TEC Integration" to "Outlook Calendar Sync"; the red "TEC plugin not found" error block is now a green success banner explaining that we're in native mode (sync target is `pta_event`, not TEC); column / label text updated ("TEC Category" → "Event Category", etc.).
2. **Category mapping AJAX endpoints were dead.** `ajax_get_tec_categories` / `ajax_create_tec_category` in `class-tec-integration-ajax.php` queried the hard-coded `tribe_events_cat` taxonomy, which is no longer registered post-TEC-removal. Switched both to use `Azure_Event_CPT::query_taxonomy()` (resolves to `pta_event_category` in pta mode). Same fix in `Azure_TEC_Calendar_Mapping_Manager::ensure_tec_category_exists`.
3. **Categories were silently being dropped during sync.** The sync engine's `create_tec_event_from_outlook_with_category` / `update_tec_event_from_outlook_with_category` wrote category terms ONLY to `tribe_events_cat`. After TEC was removed, that taxonomy lookup failed, the assignment silently no-op'd, and `mirror_to_pta_event` then read empty terms back from it and wrote empty terms to `pta_event`. Result: all 24 Outlook-synced `pta_event` posts had no category. Fix:
   - New `Azure_TEC_Sync_Engine::assign_event_category($tec_event_id, $category_name)` helper:
     - Always writes a canonical `_pta_event_category_name` postmeta value (post-type-agnostic, survives plugin churn).
     - Writes the term to every registered event-category taxonomy that's attached to `tribe_events` (so it works in tec, both, and pta modes).
     - Logs a warning when no taxonomy is reachable (instead of silently dropping).
   - `mirror_to_pta_event()` now resolves category via a 4-step fallback chain: `_pta_event_category_name` postmeta → terms in `tribe_events_cat` (legacy events) → terms in `pta_event_category` (transition events) → calendar-mapping lookup by `_outlook_calendar_id`. First non-empty wins; result is written to `pta_event_category` by name and cached back to postmeta.
   - `_pta_event_category_name` added to the mirrored-meta-keys list so it propagates from `tribe_events` to `pta_event` automatically.
   - One-shot backfill (deleted after run) categorised all 24 already-synced `pta_event` posts that were missing their category.

### Remaining cleanup options on `lwptsa.net` (NOT executed)

Run these later only if rollback is no longer needed:

| What | How | Recovers |
|---|---|---|
| Drop 64 orphan `tribe_events` posts | `POST /cleanup-execute {"actions":["delete_orphan_tribe_events"],"dry_run":false}` | ~a few MB DB + zero runtime cost (already invisible to WP_Query). Removes rollback safety net. |
| Drop 20 orphan `tribe_venue` posts | Same endpoint with `delete_orphan_tribe_venues` (if available; else SQL) | ~a few KB. |
| Delete `event-tickets/` plugin folder | `DELETE` via Kudu vfs | ~5 MB on disk. Confirm Tickets module isn't planned for use. |
| Delete `all-in-one-event-calendar/` plugin folder | Same | ~3 MB on disk. |
| Drop 754 `ai1ec_event` posts (legacy All-in-One Event Calendar) | SQL: `DELETE FROM wp_posts WHERE post_type='ai1ec_event'` + `DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT ID FROM wp_posts)` | ~a few MB DB. Completely safe — ai1ec plugin is deactivated, posts are invisible. |

## Open follow-ups

- [ ] Reconcile `v3.91.11` plugin code back into this Git repo so future deploys go through `dev` → `main` instead of bypassing source control. The diff is now ~25 versions (v3.52 → v3.91.11) of drift plus the migration code (`class-event-cpt.php`, `class-pta-cron.php`, heavily extended `class-tec-sync-engine.php` / `class-calendar-shortcode.php` / `class-diagnostics-api.php`) plus the v3.91.11 calendar/UX additions documented above.
- [ ] After ~3 months of stable Phase 6 on Wilder, decide whether to flip Wilder's writer flag to `pta` (single-write, no more `tribe_events` writes) or run cleanup. Currently Wilder writes both; cost is negligible so no urgency. lwptsa is now at `pta` — Wilder can match when comfortable.
- [ ] Document the `tribe-ignored` post status (42 posts on Wilder). It's a TEC artifact for legacy recurring events; needs verification that they're correctly excluded from sync and from front-end queries on `pta_event`.
- [ ] The 7 `tribe_venue` posts on Wilder (20 on lwptsa) were not migrated to `pta_venue` (zero rows). Decide whether venues are worth migrating or whether they can be inlined into `_EventVenue` meta on `pta_event`.
- [ ] Consider extending `Azure_Calendar_GraphAPI::get_calendar_events()` to request `onlineMeeting/joinUrl,isOnlineMeeting` from Graph and store the URL as dedicated `_pta_online_meeting_url` postmeta. The current renderer scrapes it from `post_content` at render time, which works but is slightly less robust than a dedicated field. The `extract_online_meeting_url()` helper already prefers `_pta_online_meeting_url` when present, so this is a forward-compatible enhancement.
