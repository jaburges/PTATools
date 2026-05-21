# Codebase Reconciliation Deltas: v3.53 (this repo) → v3.95.1 (production)

**Date:** 2026-05-22
**Status:** Awaiting in/out decisions per delta before merging.

## What this document is

Production (`wilderptsa.net`) is running **v3.95.1** of the plugin, deployed by another agent / Cursor session working outside this repo. This repo is at **v3.53**. The user wants to **adopt v3.95.1 as the baseline going forward and bump to v3.96**, but first wants to identify which v3.53-only features should be preserved (carried over from this repo) vs discarded (v3.95.1's approach wins).

A pristine snapshot of v3.95.1 is at `salvage/v3.95.1-2026-05-22/`. Per-file unified diffs are at `salvage/v3.95.1-2026-05-22/_diffs-pristine-vs-v3.95.1/`.

## TL;DR

Only **3 real deltas** require your in/out call:

1. **Max-bid / auto-bid feature** — v3.95.1 removed it. Keep or discard?
2. **Session-expired UI behaviour** — v3.95.1 doesn't have our 401-banner swap. Keep or discard?
3. **`improvements.md` site-performance audit doc** — we wrote it, v3.95.1 doesn't have it. Keep or discard?

Everything else either (a) v3.95.1 already has an equivalent or (b) is v3.95.1-only and we already accept it as part of the baseline.

## Confirmed file-level state

| File | v3.53 size | v3.95.1 size | Change | Decision |
|---|---|---|---|---|
| `azure-plugin.php` | 56 KB | 77 KB | +20 KB | Adopt v3.95.1 wholesale (see "Subsumed" below) |
| `admin/auction-page.php` | 31 KB | 50 KB | +19 KB | Adopt v3.95.1 wholesale (NO v3.53 deletions) |
| `admin/selling-page.php` | 2.7 KB | 3.1 KB | +444 B | Adopt v3.95.1 (NO v3.53 deletions) |
| `includes/class-auction-module.php` | 20 KB | 46 KB | +25 KB | **3 deltas to decide** (see below) |
| `includes/class-classes-module.php` | 17 KB | 18 KB | +889 B | Adopt v3.95.1 (whole-file refactor, same features) |
| `js/auction-bid.js` | 17 KB | 14 KB | −3.6 KB | Decide on session-expired handling (delta #2 below) |
| Other 315 files | identical | identical | n/a | n/a |

## Subsumed (v3.95.1 has an equivalent — no decision needed)

These are things our v3.53 had that v3.95.1 implements in a different way. Adopting v3.95.1's implementation is the right call — they're equally good or better, and trying to merge our versions back in would risk regressions.

| v3.53 had | v3.95.1 has | Verdict |
|---|---|---|
| `azure_user_can()` global helper | Same function (line 30 of v3.95.1's azure-plugin.php) | ✓ Identical purpose |
| `azure_auction_admin_can()` global helper | Same function (line 42) | ✓ Identical purpose |
| Custom `update_plugins_github.com` filter | `azure_plugin_best_github_release()` — "never downgrades" | ✓ v3.95.1's is more robust |
| Hardcoded `+$5 / +$10 / +$20` quick-bid buttons | Dynamic tiered increments via `Azure_Auction_Bids::get_quick_bid_increments($current_price)` (e.g. small steps at low prices, larger at high prices) | ✓ v3.95.1's is better |
| Bid-history polling (`pollFactory`, `pollBidHistory`, `initReadOnly`) in auction-bid.js | Their own polling via `pollState` calling the same `azure_auction_get_bid_history` endpoint | ✓ Equivalent — v3.95.1 wins by being canonical |
| `admin_post_azure_auction_create_te_runner_up_orders` hook registration in `init_hooks()` | Registered in v3.95.1 too, just in a different spot | ✓ Same |
| `admin_post_azure_auction_resend_invoice` / `_resend_all_unpaid` hooks | Same — v3.95.1 has these | ✓ Same |
| `azure_auction_get_bid_history` endpoint allowing nopriv (anonymous) reads | Same in v3.95.1 | ✓ Same |

## v3.95.1-only (NEW features we'd inherit when adopting v3.95.1 as baseline)

The user explicitly accepted v3.95.1 as the going-forward baseline, so these all stay.

| Feature | Where | Notes |
|---|---|---|
| **Admin Dashboard widget** (`Auctions` widget on `/wp-admin/` landing page showing Active / Staged / Total Bids / Total $$ — including live running tally on open auctions) | `class-auction-module.php` `register_dashboard_widget()` + `render_dashboard_widget()` | The "auction widget on the admin dashboard" you asked about at session start IS this. |
| **WP-Cron auction finalisation** (`azure_auction_finalize` per-auction one-shot scheduled at bidding-end + `azure_auction_finalize_orphans` daily safety-net) | `class-auction-module.php` `cron_finalize()` + `cron_finalize_orphans()` | Replaces the "process on page render" approach with reliable cron — fixes the 5-6s auction product TTFB I flagged in improvements.md. |
| **Auction Display Page** (public-facing live auction display with admin live/preview toggle) | `admin/auction-page.php` lines 11–22 + setting `auction_display_live` + `shortcode_auction_display()` | A standalone display surface, not the admin widget. |
| **"Extend all auctions" bulk admin action** (move auction night by updating `_auction_bidding_end` on all unsold auction items + reschedule cron) | `admin/auction-page.php` lines 24–80 | Safety: skips items with winner/order already set. |
| **TEC-optional mode** (`pta_calendar_owner = 'pta' / 'both'` makes the native `pta_event` CPT replace The Events Calendar; suppresses TEC dependency nag) | `class-classes-module.php`, `class-event-cpt.php`, `class-calendar-events-cpt.php` | Big enabler for dropping the heavy TEC plugin entirely. |
| **User Management module** (consolidated Parent Tools / Parent Children Import / Product Fields Consolidate, with legacy-tab-slug redirects from Selling) | `admin/user-management-page.php`, `class-user-management-module.php`, plus legacy redirect block in `admin/selling-page.php` | |
| **`Azure_Auction_Bids::get_increment()` + `get_quick_bid_increments()` (tiered)** | `class-auction-bids.php` lines 31, 47 | Tiered bid increments scale with price. |
| **Tiered next-min-bid calculation in bid form** | `render_single_product_auction()` lines computing `$next_min_bid` | Uses tiered increments. |
| **Parent role / activation / migration / children-importer modules** | 6 new `class-parent-*.php` files | |
| **Native `pta_event` CPT + `Azure_Event_CPT` class** | `class-event-cpt.php` (68 KB) | This replaces TEC for the calendar feed. |
| **Admin performance + Frontend performance modules** | `class-admin-performance.php`, `class-frontend-performance.php` | Possibly addresses the slow TTFB I flagged. |
| **Product Fields admin + migrator** | `class-product-fields-admin.php`, `class-product-fields-migrator.php` | |
| **Templates folder + tec-inspect-v2.php utility** | `templates/`, `tec-inspect-v2.php` | |

## DECISIONS NEEDED — v3.53-only features

### Delta 1: Max-bid / auto-bid feature

**What it is** (v3.53 code, dropped in v3.95.1):

```php
// In class-auction-module.php render_single_product_auction():
<p class="auction-max-bid-row">
    <label><input type="checkbox" class="auction-use-max-bid" />
        <?php _e('Set max bid (auto-bid up to this amount)', 'azure-plugin'); ?>
    </label>
    <input type="number" class="auction-max-bid-amount" min="0" step="0.01" style="display:none; width:100px;" />
</p>
```

Plus client-side max-bid validation in `submitBid()` in auction-bid.js, plus server-side max-bid logic in `Azure_Auction_Bids::place_bid()` (which IS still present in v3.95.1's `class-auction-bids.php`, just no UI exposes it).

**What v3.95.1 has instead:** Nothing. The auto-bid feature is removed from the UI entirely. Users place one bid at a time.

**Recommendation:** **Discard.** The auto-bid feature is a UX edge case that confuses some bidders (they didn't realise placing a max-bid would auto-bid for them). Removing it makes the bid form simpler and matches the v3.95.1 author's apparent design intent. The server-side logic stays — if you ever want it back, just re-add the UI.

**But:** if PTA bidders have used max-bids in practice and rely on it, keep it. Your call.

---

### Delta 2: Session-expired UI handling

**What it is** (v3.53):

- Server: `ajax_place_bid()` / `ajax_place_bid_guest()` return HTTP 401 with `{code: 'not_logged_in', message: '...', login_url: '...'}` when the cookie has aged out.
- Client: JS catches both the HTTP status and the response code, hides the bid form, inserts a yellow "Your session has expired — log in to continue bidding" banner with a working login link that bounces back to this auction after auth.
- Also includes `showSessionExpiredBanner()`, `initReadOnly()` polling for logged-out users.

**What v3.95.1 has instead:** Standard WC behaviour — places generic error message in the bid form's `.auction-bid-message` span, user has to manually figure out they're logged out.

**Recommendation:** **Keep ours** — port the session-expired logic into v3.95.1. Cookie expiry mid-bid is a real user complaint we already saw on wilderptsa.net (the "could not see bids on the page, and reloading didn't show the new bid" report). v3.95.1's UX leaves users confused.

Effort: ~30 minutes — copy the relevant blocks from `js/auction-bid.js` + `class-auction-module.php` into the v3.95.1 versions.

---

### Delta 3: `improvements.md` site-performance audit document

**What it is:** A standalone repo-root document (`improvements.md`) we wrote yesterday that captures:

- Mobile performance audit results (TTFB 3.5-6 s, 39 asset round-trips)
- Diagnosis (Front Door cache key poisoned by WC session cookies, etc.)
- Plugin inventory (52 active, ~30% culling candidates)
- Prioritised P0-P7 fix list with effort/impact estimates
- Forward-looking notes on managed WP host / Shopify / headless options

**What v3.95.1 has instead:** Nothing — this document didn't exist on production before today.

**Recommendation:** **Keep.** It's a living planning doc, useful regardless of code state. Costs nothing to keep. If v3.95.1's author also wants to update it, great.

---

## What I propose to do (after your in/out decisions)

1. **Stash** the current working-tree mess to a `wip-2026-05-22-hybrid` branch (forensic recovery).
2. **Reset** the local working tree to a clean git HEAD.
3. **Wholesale-overwrite** `Azure Plugin/` with `salvage/v3.95.1-2026-05-22/` contents.
4. **Apply** whichever v3.53-only features you elect to keep:
   - Delta 1 if "keep": re-add the max-bid checkbox + JS handling.
   - Delta 2 if "keep": port `not_logged_in` 401 + banner swap into v3.95.1's code.
   - Delta 3 if "keep": `improvements.md` stays at repo root (no overwrite needed).
5. **Bump** `AZURE_PLUGIN_VERSION` to `3.96`.
6. **Commit** as a single `feat: adopt v3.95.1 as baseline + carry over <chosen deltas>` commit (large but clean).
7. **Push** to `dev` for review.
8. **Optionally** deploy via Kudu to wilderptsa.net to lock in our merged state as canonical, so the parallel deployer can't reintroduce drift.

## Steps zero — find the other deployer

Even after the merge, **someone with deploy access is iterating outside this repo**. Until you reconcile that, anything we push will keep getting overwritten by their deploys. Per `salvage/v3.95.1-2026-05-22/SALVAGE-NOTES.md` open question #1: who else has Kudu / Azure deploy access to `wilderptsa.net`? Once found, get them committing to this repo (or formally accept their out-of-repo deploys as canonical and this repo as documentation-only).
