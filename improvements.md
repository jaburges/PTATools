# PTA Tools — Site Performance & Platform Improvements

**Status:** Audit complete · Implementation pending
**Author:** Jamie Burgess (with Cursor)
**Last updated:** 2026-05-21

> Living document. Updated as items are completed or new findings emerge.
> Tracks the prioritised work to make `wilderptsa.net` faster on mobile,
> reduce platform cost, and decide whether to stay on Azure App Service
> or move elsewhere.

## TL;DR

- The platform (Azure App Service **P1v3 × 2**) is **not** the bottleneck. It's a generously-sized SKU for a site doing $10–20k/year.
- **Don't move to a VM or container.** The "Docker feels faster locally" intuition is real but misleading — your local Docker doesn't have the 52 plugins, Front Door cache misses, mobile JS chains, or remote MySQL hops the production site does. A migration would carry all of that with you for 5–10× the ops burden.
- Mobile users currently wait **3.5–9 seconds** before pages start rendering. Healthy WordPress should be **< 1 second**.
- The single biggest win is **fixing Azure Front Door cache hit rate** — a configuration change, not code. Likely impact: 50–90% mobile load-time improvement for anonymous visitors.
- The auction product page has a server-side processing time of **5–6 seconds** independent of cache, which is the symptom behind "I couldn't see my bid show up" reports. A separate profiling task to find why.

## Current state (audited 2026-05-21)

### Infrastructure

| | Value | Notes |
|---|---|---|
| Resource Group | `PTSAWebsite` | Azure subscription `Microsoft Grant` (97f6936d-…) |
| App Service Plan | **P1v3 (2 vCPU / 8 GB RAM)** × 2 instances | Generous; ~$500/mo for plan alone |
| Region | West US 2 | |
| WordPress | 6.9.4 | Current |
| WooCommerce | 10.7.0 | Current |
| PHP | 8.3.29 | Current |
| Active plugins | **52** | Way too many — see "Plugin inventory" |
| CDN | Azure Front Door (standard staging URL + custom domain) | Configured but not effectively caching anonymous traffic |
| Page cache | W3 Total Cache | Configured but interacting badly with WooCommerce session cookies |
| Object cache | None (DB only) | Easy quick-win to add Redis |

### Performance audit results

Mobile UA (Pixel 7, Chrome 121), cold + warm timing, hitting via Azure Front Door:

| Page | Cold TTFB | Warm TTFB | CDN status | Verdict |
|---|---|---|---|---|
| `/` (homepage) | 3.5 s | 3.8 s | `TCP_MISS` | Cache broken |
| `/shop/` | 2.9 s | 3.7 s | `TCP_MISS` | Cache broken |
| `/product/class-project-paper-art-wolf-johnson/` (auction) | **5.2 s** | **5.9 s** | `TCP_MISS` | **Catastrophic** |
| `/cart/` | 2.7 s | 3.7 s | `CONFIG_NOCACHE` | Correct (uncacheable) |

**For context** — a healthy WordPress site has TTFB under 200 ms warm, under 800 ms cold. Yours is 15–30× slower on key pages. The auction product page is the worst offender.

**Cache is broken for anonymous visitors** — every probe above shows `TCP_MISS` even on warm requests. Front Door is hitting the WP origin on every request instead of serving from edge. The likely cause is WooCommerce session cookies (`wp_woocommerce_session_*`, `woocommerce_cart_hash`) being included in Front Door's cache-key calculation, so every "anonymous" visitor gets a unique cache entry that hasn't been seen before.

### Page weight (homepage, mobile)

- HTML: 102 KB (fine)
- **20 CSS files** (way too many)
- **19 JS files** (way too many)

On a 4G mobile connection (~150 ms RTT), 39 asset round-trips = **~5.8 s of network alone**, on top of the 3.5 s TTFB. Mobile users see ~9 s before the page starts rendering.

## Plugin inventory (52 active)

Categorised by what they do — **roughly half can be removed, consolidated, or replaced**.

### Definitely keep (core / business-critical) — 8

```
woocommerce
woocommerce-gateway-stripe         (the active payment gateway)
Azure Plugin                       (our custom plugin)
Volunteer-Sign-Ups-Plugin          (active fundraiser tool)
the-events-calendar
akismet                            (comment spam)
updraftplus                        (backups)
wp-mail-smtp                       (SMTP relay)
```

### Probably keep — 7

```
w3-total-cache                     (needs tuning, but real benefit)
wp-smushit                         (image compression — verify it's actually running)
forminator                         (contact / RSVP forms — heavy but used)
events-calendar-pro                (TEC Pro features for event publishing)
woocommerce-services               (USPS/UPS rates — only if shipping is sold)
acymailing                         (newsletter — used)
admin-menu-editor-pro              (admin UX)
```

### Consolidate / pick one — 5 (cull 3+)

```
malcare-security        }
wp-defender             }  TWO security plugins — pick ONE
                       
woocommerce-payments    }
woocommerce-paypal-     }  THREE WC payment gateways. Stripe handles cards,
  payments              }  Apple Pay, Google Pay, Link, Amazon Pay. You almost
                       }  certainly don't need all three.
```

### Probably remove (likely unused / dead) — 14

```
acychecker                                  (utility used once?)
acymailing-integration-for-the-events-calendar  (TEC + Acy — used?)
acymailing-integration-for-woocommerce      (WC + Acy — used?)
app_service_email                           (legacy SMTP attempt?)
bb-ultimate-addon                           (Beaver Builder addon — used?)
change-username
extended-user-search-in-wp-admin
iframe
jotform-ai-chatbot                          (AI chatbot — actively used?)
member-plus
printify-for-woocommerce                    (POD products — sold any?)
tribe-ext-ea-additional-options             (TEC addon — used?)
woo-update-manager                          (auto-update — overlaps WC)
woocommerce-advanced-packages
woocommerce-store-credit
wp-toolbar-editor
wpmudev-updates
zestard-easy-donations                      (we have our own Donations module now)
```

### Replace with in-house — 1

```
woocommerce-order-export    →    PTA Tools → Selling → Reports (Ship 1 already deployed)
```

### Plugins generating most of the page-weight (suspected)

```
bb-plugin + bb-ultimate-addon + bbpowerpack   (Beaver Builder + 2 addons)
forminator                                    (forms — every page when "in footer" assets are enqueued)
events-calendar-pro                           (lots of CSS/JS)
side-cart-woocommerce                         (slide-out cart on every page)
woocommerce + WC blocks                       (15+ assets on shop/product pages)
```

## Prioritised improvements

Effort and impact estimates are conservative.

### 🔴 P0 — Front Door cache config

| | |
|---|---|
| Effort | 1–2 hours, no code change |
| Impact | **50–90% mobile load-time improvement** for anonymous visitors |
| Risk | Low (purely a config change, instantly reversible) |

Configure Azure Front Door to:

1. Strip WC session cookies from cache-key calculation for anonymous users on cacheable URLs.
2. Honour `Cache-Control` headers W3TC sends, but verify W3TC isn't sending `private` on pages that should be public-cached.
3. Set explicit cache rules per URL prefix:
   - `/`, `/shop/`, `/product/*`, `/*-class-project-*`, `/*-basket-*` → cache 5–60 min for anonymous
   - `/cart/`, `/checkout/`, `/my-account/`, `/wp-admin/*` → no cache
   - `/wp-content/*` → cache 30 days
4. Validate by re-running the audit — expect `TCP_HIT` on warm requests for cacheable URLs.

### 🟠 P1 — Profile auction product page

| | |
|---|---|
| Effort | 1 hour profiling + 1–2 hours fix |
| Impact | Drops logged-in bidder TTFB from 5–6 s → ~1 s |
| Risk | Low |

The auction product page takes 5–6 seconds of pure server processing per request, *even without cache*. Cache helps anonymous visitors but logged-in bidders (the ones who matter) will always hit origin.

Hypotheses to confirm or refute:

- `Azure_Auction_Lifecycle::maybe_process_ended_auction()` running synchronously on every product page render. If this hits a slow path for ended auctions, every render pays the cost.
- A missing index on `wp_azure_auction_bids(product_id, bid_amount DESC)` causing the "current high bid" query to do a full table scan.
- `get_masked_bid_history()` doing 10 + 1 user lookups per render (one for each masked bid).
- Beaver Builder or another page-builder doing slow render-time work on the product layout.
- HPOS off and order meta lookups going through `wp_postmeta` (slower than HPOS tables).

**Action:** Install Query Monitor temporarily (or enable WP_DEBUG + SAVEQUERIES). Render an auction product page and capture the top 20 slow queries + hook execution times. Fix the worst offender.

### 🟡 P2 — Asset bundling

| | |
|---|---|
| Effort | 1 hour |
| Impact | 25–30 saved mobile round-trips, ~3 s faster mobile |
| Risk | Medium (CSS/JS bundling occasionally breaks specific plugins) |

Enable W3TC Minify with **manual mode** (not auto — auto bundles too aggressively and breaks WC checkout). Group CSS into 2 bundles (admin / front-end), JS into 2–3 bundles. Test cart + checkout + auction-bid flow after enabling.

If W3TC Minify proves fragile, use **Perfmatters** ($25/year) or **FlyingPress** ($60/year) — both are tuned for WC and won't break the checkout.

### 🟡 P3 — Plugin cull

| | |
|---|---|
| Effort | 1–2 hours (deactivate + smoke test) |
| Impact | 0.5–1 s TTFB reduction (~15–30 ms per plugin × removed count) |
| Risk | Medium — requires confirming nothing visible breaks |

Per the "Plugin inventory" section above:

1. **Kill list (14 plugins)** — deactivate in WP admin first (don't delete files). Smoke-test the front-end + admin for 24 hours. If nothing breaks, delete.
2. **Consolidation (3+ plugins)** — pick one security plugin (Malcare or WP Defender), pick one or two payment gateways (Stripe is sufficient for 99% of WC stores).
3. **Replace woocommerce-order-export** — our new in-house Orders Reports module covers it. Migrate any saved Export Orders reports manually (one-off cost) then deactivate the plugin.

### 🟡 P4 — Add Redis object cache

| | |
|---|---|
| Effort | 0.5 day |
| Impact | 10–30% TTFB reduction (more on logged-in pages) |
| Risk | Low |
| Cost | ~$20/month (Azure Cache for Redis Basic C0) |

Provision Azure Cache for Redis (Basic C0 is sufficient — 250 MB, ~$20/mo). Install the `redis` PECL extension on App Service. Configure W3TC's Object Cache to use Redis. Drops all WP `wp_options`, transient, and user meta queries to memory hits.

### 🟢 P5 — Image optimization

| | |
|---|---|
| Effort | 0.5 day |
| Impact | 20–40% mobile LCP improvement |
| Risk | Low |

`wp-smushit` is installed but likely not configured aggressively. Actions:

1. Verify Smush is processing new uploads + has done a bulk pass over existing images.
2. Enable WebP generation in Smush (Pro feature) — or switch to the free `webp-express` plugin for WebP delivery.
3. Confirm lazy-loading is enabled for product gallery thumbnails.
4. Add explicit `width` + `height` attributes to product images (avoid CLS).

### 🟢 P6 — Critical CSS + defer non-critical JS

| | |
|---|---|
| Effort | 1 day |
| Impact | 10–25% FCP/LCP improvement |
| Risk | Medium |

Inline the above-the-fold CSS in `<head>`; defer the rest. Defer all non-essential JS (analytics, social, chat widgets) to `<body>` end. Plugins like Autoptimize or Perfmatters do this automatically with low risk; doing it manually is brittle.

### 🟢 P7 — Database (MySQL) tier review

| | |
|---|---|
| Effort | 0.5 day |
| Impact | 10–20% TTFB reduction on DB-heavy pages |
| Risk | Low |

Verify the MySQL backing this site is on **Flexible Server** (the modern Azure offering), not the deprecated Single Server. Confirm it's in the same Azure region as the App Service (cross-region MySQL adds 20–50 ms per query). If still on Single Server, migrate to Flexible Server (Azure provides tooling).

### Future / strategic

#### Stay on App Service — current trajectory
After completing P0–P7, expected mobile load time is ~1–2 seconds (from ~9 s today). Total monthly increase: ~$20 for Redis. Total effort: ~1 week.

#### Managed WordPress host (Kinsta, WP Engine, Pressable, Pantheon, Cloudways)
If after P0–P7 you're still unhappy, **before** considering self-managed VMs or alt-platform migration, try **moving to a managed WP host**:

- They sell pre-tuned infrastructure for WP specifically. Often noticeably faster than DIY Azure for the same plugin stack.
- Cost: $50–200/month (depends on traffic + provider).
- Migration: typically 1 day, low risk, easily reversible.
- Trade-off: vendor lock-in to their stack (often opaque), no Azure ecosystem integration (you'd lose seamless ACS / Entra ID hooks).
- Worth piloting on staging before committing.

#### Headless WordPress + static front-end
WP keeps being the admin/data store; product pages are pre-rendered React/Next.js served via Vercel or Cloudflare Pages.

- **Fastest possible mobile experience.** Sub-second mobile loads achievable.
- **Massive rebuild** — every dynamic WP feature on the front-end (cart, checkout, auction bidding, account, donations) has to be re-implemented in the React app or proxied to WP via API.
- 4–8 week project, $20–40k of engineering at standard rates.
- Loses your custom PTA Tools UI work for the front-end portions (admin keeps working).
- Reserved for "we've outgrown WP" scale, not "the site is slow today."

#### Shopify carve-out
Move the store (auctions, yearbook, spirit wear) to Shopify; keep WP for content / events / PTA roles / Acymailing.

- Shopify's store is **faster out of the box** than self-hosted WC.
- $40/month base + 2.9% + $0.30 per transaction (vs. ~2.9% + $0.30 at Stripe, same for cards).
- Loses our custom auction module entirely — Shopify has nothing equivalent. Would need a Shopify auction app (~$15–50/mo, varying quality) or build a custom app.
- Loses Teacher Experience runner-up logic, Product Fields integration, our Orders Reports module.
- Loses single-account experience (a parent buying yearbook + bidding on auction would have two accounts).
- Roughly 4 weeks of work to migrate.
- Worth considering only if the auction-specific logic isn't seen as a long-term differentiator.

#### Switch to a self-managed Azure VM or AKS
**Not recommended.** Reasons:

- Carries the 52-plugin / 39-asset / cache-config problem with you.
- 5–10× higher ops burden (OS patching, security updates, log management, backup tooling).
- Modest perf gain (~10–15% best case) for 4× the operational complexity.
- Loses App Service's deployment-slot + auto-restart + integrated monitoring.
- Only worth doing if you specifically need OS-level access (e.g. a non-PHP service co-resident with WP).

## Decisions log

| When | Decision | Rationale |
|---|---|---|
| 2026-05-21 | Audit found platform NOT under-provisioned (P1v3 × 2). | Direct `az appservice plan show` query showed PremiumV3. |
| 2026-05-21 | Audit found Azure Front Door not effectively caching anonymous traffic. | All probes returned `TCP_MISS` even on warm; suspected WC session cookie key poisoning. |
| 2026-05-21 | Audit found 52 active plugins, ~30% likely culling candidates. | `vfs` enumeration of `wp-content/plugins/`. |
| 2026-05-21 | Recommended P0–P7 fixes; recommended **against** VM/container migration. | Platform is not the bottleneck; migration cost not justified. |
| TBD | P0 (Front Door cache) implementation | Pending user decision. |

## How to re-run the audit

```bash
UA_MOBILE="Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.6167.184 Mobile Safari/537.36"

probe() {
  local url="$1"
  echo "=== $url ==="
  local nonce=$(date +%s%N)
  curl -s -A "$UA_MOBILE" -H "Cache-Control: no-cache" -o /dev/null \
    -w "  cold: ttfb=%{time_starttransfer}s  total=%{time_total}s  size=%{size_download}B  status=%{http_code}\n" \
    --max-time 30 "${url}?_cb=${nonce}"
  curl -s -A "$UA_MOBILE" -o /dev/null \
    -w "  warm: ttfb=%{time_starttransfer}s  total=%{time_total}s  size=%{size_download}B  status=%{http_code}\n" \
    --max-time 30 "${url}"
  curl -s -I -A "$UA_MOBILE" --max-time 10 "${url}" | grep -i '^x-cache:' | tr -d '\r'
}

probe "https://wilderptsa.net/"
probe "https://wilderptsa.net/shop/"
probe "https://wilderptsa.net/cart/"
# Add an active auction product URL here
```

A successful P0 fix will show `TCP_HIT` on warm requests for the homepage / shop / product pages, with warm TTFB dropping from 3.5–6 s to 50–150 ms.
