# Salvage Notes — v3.95.1 (2026-05-22)

## Why this folder exists

On 2026-05-22 we discovered production was running **v3.95.1** while our local `dev` branch was at **v3.53**. The v3.95.1 codebase has never been pushed to this GitHub repo. We don't know who's been working on it (likely another Cursor session, another machine, or a different developer using direct Kudu deploys).

This folder is a **read-only snapshot** of the entire v3.95.1 plugin folder pulled directly off `wilderptsa.net` via Kudu, plus a per-file unified diff against our `dev` branch.

**Do not edit files inside this folder.** It exists so we can:
1. Recover from accidental overwrite if anyone deploys our `dev` work and wipes v3.95.1.
2. Inspect what features were built in v3.95.1 that we don't have in `dev`.
3. Eventually merge v3.95.1's features back into the canonical repo properly.

## Inventory

```
salvage/v3.95.1-2026-05-22/
├── _full-snapshot.zip          (1.5 MB — pristine zip download from Kudu)
├── _diffs-vs-repo-v3.53/       (per-file unified diffs vs `dev` HEAD)
│   ├── azure-plugin.php.diff                       (88 KB)
│   ├── admin_auction-page.php.diff                 (20 KB)
│   ├── admin_selling-page.php.diff                 (851 B)
│   ├── includes_class-auction-module.php.diff      (37 KB)
│   ├── includes_class-classes-module.php.diff      (1 KB)
│   └── js_auction-bid.js.diff                      (22 KB)
└── (full v3.95.1 tree — 321 files, 5.9 MB)
```

## What files actually differ?

Out of 321 files in the plugin tree, **only 7 differ** between v3.95.1 (prod) and `dev` v3.53 (ours). Nothing exists in one that doesn't exist in the other — the file structure is identical.

| File | Direction | Delta | Notes |
|---|---|---|---|
| `azure-plugin.php` | prod **+20 KB** | Major | GitHub-releases auto-updater + other architectural additions |
| `admin/auction-page.php` | prod **+19 KB** | Major | "Auction Display Page" public-facing live view + "Extend all auctions" admin bulk action |
| `admin/selling-page.php` | prod **+444 B** | Minor | Adds 7-line redirect for legacy tab slugs |
| `includes/class-auction-module.php` | prod **+25 KB** | Major | New admin-post handlers (display toggle, extend all, etc.) |
| `includes/class-classes-module.php` | prod **+451 B** | Minor | Suppresses TEC dependency notice when `pta_calendar_owner` is set |
| `js/auction-bid.js` | **`dev` +3.6 KB** | Moderate | Implementation difference, NOT a missing feature (see below) |
| `logs.md` | n/a | n/a | Just an activation-history difference; not behaviour-bearing |

Everything else — including the entire `class-auction-winners-report.php` (35 KB) — is **bit-identical**. Same MD5.

## Features in v3.95.1 that `dev` doesn't have

### 1. Auction Display Page (public-facing live view)

Confirmed at `admin/auction-page.php` lines 8–22 of the diff:

```php
if (isset($_POST['azure_auction_display_toggle']) && current_user_can('manage_options') && ...) {
    $new_live = !empty($_POST['auction_display_live']) ? true : false;
    Azure_Settings::update_setting('auction_display_live', $new_live);
    $display_toggle_notice = $new_live
        ? 'Auction display page is now LIVE — visible to the public.'
        : 'Auction display page is in preview mode — admins only.';
}
```

There's a setting `auction_display_live` (boolean) and an inline POST handler on the Selling > Auction tab that toggles it. The corresponding front-end display surface lives elsewhere — search v3.95.1 for `auction_display_live` to find the render path.

### 2. "Extend all auctions" bulk action

`admin/auction-page.php` lines 24–80 of the diff. Admin POST that:

- Reads a new end date/time from the form
- Optionally allows reopening already-ended items
- Iterates every published `product_type=auction` product
- **Skips items with winner/order set** (safety — won't accidentally clobber a closed auction that's already had notifications + orders created)
- Updates `_auction_bidding_end` postmeta
- Reschedules per-auction finalize cron jobs

This is the "auction night moved, push everything to the new end time" workflow. Worth keeping.

### 3. GitHub Releases auto-updater (`azure_plugin_best_github_release`)

In `azure-plugin.php`. Walks GitHub Releases, picks the newest one that ships `pta-tools.zip`, advertises it to WP's auto-update system. **Never downgrades** — has explicit logic to refuse a release with a lower version than what's installed (which explains why v3.95.1 stays installed even though GitHub's latest release is v3.51).

### 4. User Management module (consolidates legacy admin tabs)

`admin/selling-page.php` diff:

```php
// Legacy slugs (v3.67/v3.68) — the Parent Tools / Consolidate / CSV import
// surfaces moved out of Selling. Bounce visitors who follow old bookmarks
// to the new User Management page (in PTA Tools) so they don't 404.
if (in_array($active_tab, array('parent-tools', 'parent-children-import', 'product-fields-consolidate'), true)) {
    wp_safe_redirect(admin_url('admin.php?page=azure-plugin-user-management&tab=role-editor'));
    exit;
}
```

There's a `azure-plugin-user-management` page (with at least a `role-editor` tab) that didn't exist in v3.53. Implies a `class-user-management-module.php` — and yes, it's in v3.95.1's `includes/`. We have the file too (it was in our `dev` already), but our top-level admin menu may not surface it because the integration code is in the v3.95.1-unique parts of `azure-plugin.php`.

### 5. TEC-optional mode (`pta_calendar_owner`)

`class-classes-module.php` diff:

```php
$pta_owner_active = class_exists('Azure_Event_CPT') && Azure_Event_CPT::is_pta_owner_active();
if (!$pta_owner_active) {
    add_action('admin_notices', array($this, 'tec_missing_notice'));
}
```

A setting `pta_calendar_owner` lets the site run on the native `pta_event` CPT **without** The Events Calendar installed. When in PTA-owner mode, the TEC-missing nag is suppressed. Big enabler for dropping the (expensive, heavy) The Events Calendar plugin from the 52-plugin stack.

## "Missing" features that aren't actually missing

### Bid history polling

`dev` v3.53 commit `711f359` added `pollBidHistory()` / `initReadOnly()` / `showSessionExpiredBanner()` to `js/auction-bid.js`.

**v3.95.1 already has the same feature** with a different implementation — function named `pollState`, calling the same `azure_auction_get_bid_history` endpoint. So our v3.53 polling work is **not a regression v3.95.1 needs** — it's duplicated effort. Either implementation works; v3.95.1's is the canonical one.

When merging back, **discard our `js/auction-bid.js` changes** and keep v3.95.1's polling implementation.

## Recommended merge strategy (when ready)

Sequencing for a future "reconcile dev with v3.95.1" task:

1. **Stop deploying anything from `dev` to production.** Anything we push now overwrites v3.95.1 and loses the 5 prod-bigger files of work above.

2. **Find the other deployer** (whoever pushed v3.95.1). Either get them to push their work to this GitHub repo, or accept the snapshot in this folder as the new source of truth.

3. **Reverse-merge v3.95.1 INTO `dev`:** copy `salvage/v3.95.1-2026-05-22/` over `Azure Plugin/`, then audit the result against our `dev` HEAD for anything we want to preserve from `dev`. Likely nothing — v3.95.1 looks like a clean superset.

4. **Pick a single canonical version number** and bump from there. Don't restart at 3.54.

5. **Wire deploy-staging.yml to require a successful push to `main`** before allowing direct Kudu pushes, or document that Kudu pushes are the canonical path and CI is supplementary. The current ambiguity is what allowed two parallel codebases to drift.

## How this snapshot was taken

```bash
az account set --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00
az rest --method get \
  --uri "https://wilderptsa.scm.azurewebsites.net/api/zip/site/wwwroot/wp-content/plugins/Azure%20Plugin/" \
  --resource "https://management.azure.com" \
  --output-file /tmp/prod-plugin.zip
unzip -q /tmp/prod-plugin.zip -d /tmp/prod-plugin/
# then rsync /tmp/prod-plugin/ → salvage/v3.95.1-2026-05-22/
```

Per-file diffs were generated with `diff -u "Azure Plugin/$f" "$SAL/$f"`.

## Resolution (2026-05-22)

Open questions answered:

1. **Who else has Kudu / deploy access?** → A parallel Cursor agent session was working on the same plugin codebase, deploying via direct Kudu pushes without committing back to this repo. User confirmed 2026-05-22.

2. **Was a separate Cursor agent session used?** → Yes. The May 20 16:15 mtime cluster on ~50 files in our local working tree matches an rsync from that agent's working copy into ours at that moment.

3. **Were there more v3.95.1 features I missed?** → All deltas were catalogued in `CHANGE-DELTAS.md` (repo root). User reviewed and decided per-feature; the merge to v3.96 is committed in `cb7a371`.

## Going-forward policy

`CONTRIBUTING.md` (repo root) documents that **this repo is the single source of truth**. No more direct Kudu pushes from outside the repo. All deploys originate from a commit on `dev` or `main`. See that file for the workflow.

The forensic recovery branch is `wip-2026-05-22-hybrid` on GitHub — kept indefinitely for debugging only, never to be merged.
