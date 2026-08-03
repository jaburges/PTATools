# Cutover plan: pointing wilderptsa.net at the Container Apps site

Status: **prepared, not executed.** The hostname blocker — the one that would
actually have broken the site — is fixed and verified against the live container
by replaying Front Door's headers. What remains before flipping the route is
locking down direct access to the container, and the content URL rewrite in 1b.
The switch itself is one command and reverses in about a minute; the risk is
entirely in the preparation.

## The good news: no DNS change is involved

`wilderptsa.net` already resolves to Front Door anycast (`150.171.110.147`), and
`www` is an alias of the apex. DNS is **not** hosted in Azure — there is no zone
in `PTSAWebsite`, so it lives at the registrar and nobody needs to touch it.

Cutover is therefore a Front Door route change, nothing more:

```
wilderptsa.net ──► WilderPTSAAFD / wilderptsa-c20b298090 ──► default-route
                                                                 │
                             now: summer-swa-origin-group ────────┤
                          target: wilderptsa-aca-origin-group ────┘
```

That means rollback is fast and total, with no TTL to wait out. It also means the
blast radius of a mistake is the live domain, immediately.

## Current state

| Thing | Where it points |
| --- | --- |
| `wilderptsa.net`, `www.wilderptsa.net` | Front Door → `summer-swa-origin-group` → summer placeholder SWA (~1.6 KB page) |
| App Service origin group | Still configured, **not routed** for the production domain |
| Staging endpoint | → App Service staging slot, no custom domain attached |
| Container site | Reachable only on its own hostname, `wilderptsa-wp.…azurecontainerapps.io` |

Route caching is currently disabled (`queryStringCachingBehavior: None`). Leave
it that way through cutover — turning on edge caching at the same time as
changing origins would make any problem much harder to attribute, and this site
runs WooCommerce, where a wrongly cached cart or checkout page is a data leak
rather than a performance bug.

## Blockers: fix these first

### 1. WordPress will emit the wrong hostname — FIXED 2026-08-03

Container Apps ingress answers **404** to any `Host` other than its own FQDN, so
Front Door must override the origin host header (the `container` target in
`afd-switch-origin.sh` does that). WordPress then sees the ACA hostname and, as
measured, does **not** read `X-Forwarded-Host` — the page came back full of
`https://wilderptsa-wp.…azurecontainerapps.io/wp-content/…` URLs.

Fixed in `WORDPRESS_CONFIG_EXTRA` (revision 7). `WP_HOME`/`WP_SITEURL` now prefer
`X-Forwarded-Host`, gated on `X-Azure-FDID` matching
`863cc6c3-7117-4e08-9159-c86b5feb4911` and checked against a fixed list of
`wilderptsa.net` / `www.wilderptsa.net`. Ungated it would let any visitor set the
site's URLs, including the ones in password reset mail. The canonical domain is
the fallback, so it works whether or not Front Door forwards the original host.
The plain `HTTP_HOST` path is kept so the ACA hostname still works for debugging
instead of bouncing to the live domain.

**Setting the constants alone was not enough, and failed in a way that looks fine
until the domain is switched.** `redirect_canonical()` builds the requested URL
from `HTTP_HOST` — still the container's hostname after the edge rewrite — and
compares it to `home_url()`, so it 301s to the public domain, which comes back
through Front Door unchanged and redirects again. A simulated Front Door request
returned exactly that: `301 → https://wilderptsa.net/`. `HTTP_HOST` and
`SERVER_NAME` are therefore rewritten too.

Verified against the live container by replaying the headers Front Door will send:

| Request | Result |
| --- | --- |
| Valid FDID + `X-Forwarded-Host: wilderptsa.net` | `200`, canonical `https://wilderptsa.net/`, 159 public URLs |
| Valid FDID + `www.wilderptsa.net` | `200`, canonical on `www` |
| Valid FDID, no `X-Forwarded-Host` | falls back to `wilderptsa.net` |
| `X-Forwarded-Host` with **no** FDID | ignored, stays on container host |
| Valid FDID + host not on the list | ignored, falls back to `wilderptsa.net` |

### 1b. Absolute container URLs in content

`WP_HOME` is computed per request, so it needs no database change — but URLs baked
into content do, or the public site will hotlink `azurecontainerapps.io`. One
already showed up in the verified response: a placeholder image in the Home page.

Counted 2026-08-03 via `job-db-audit-versions.yaml`:

| Location | Rows |
| --- | --- |
| `wp_posts.post_content` | 30 |
| `wp_options` | 7 |
| postmeta / termmeta / usermeta / excerpt | 0 |

Rewrite these as part of cutover, after the route is switched, and keep
`siteurl`/`home` consistent with the new domain while doing it.

### 2. The container is directly reachable on the internet

Anyone who knows the ACA hostname bypasses Front Door entirely, which defeats
WAF, rate limiting and any future edge caching. The YAML already notes that the
useful restriction at cutover is limiting the origin to Front Door rather than
allow-listing an operator IP.

Container Apps ingress cannot filter on headers and does not accept service tags,
so the check belongs in the image's Apache config: require the `X-Azure-FDID`
value above, and reject anything else.

**Do not do this without handling cron.** The `wilderptsa-wpcron` job curls
`/wp-cron.php` on the ACA hostname directly, so a blanket rule silently kills all
scheduled work — including the OneDrive backup queue. Either exempt
`/wp-cron.php`, or move the job to call through the domain. Same applies to
`/healthz.php`, which the Front Door health probe needs.

### 3. Decide what `siteurl` should be in the database

The database still holds `wp.theburgessfamily.us`, currently masked by the
`WP_HOME`/`WP_SITEURL` constants. That was a migration convenience. Once the real
domain is live it is cleaner to write `https://wilderptsa.net` into the
`siteurl`/`home` options so the database is self-consistent, and to stop relying
on request-derived constants for the production path.

## Runbook

Prerequisites: blockers 1–3 resolved and deployed as a new image and revision;
the revision `Healthy`; site verified on the ACA hostname.

```bash
# 1. Switch the route. Creates the origin group and origin on first run, then
#    purges the endpoint. Uses ARM REST because `az afd route update` can drop
#    the custom domains off the route.
./infra/aca-wordpress/scripts/afd-switch-origin.sh container

# 2. Verify the live domain is actually being served by the container.
curl -sS -o /dev/null -w 'code=%{http_code} ttfb=%{time_starttransfer}\n' https://wilderptsa.net/
curl -sS https://wilderptsa.net/ | grep -o 'https://[a-z0-9.-]*/wp-content' | sort -u
#    Expect wilderptsa.net in those URLs, NOT the azurecontainerapps.io hostname.

# 3. Check the things that break quietly.
curl -sS -o /dev/null -w 'www=%{http_code}\n' https://www.wilderptsa.net/
curl -sS -o /dev/null -w 'admin=%{http_code}\n' https://wilderptsa.net/wp-admin/   # expect 302
curl -sS -o /dev/null -w 'media=%{http_code}\n' https://wilderptsa.net/wp-content/uploads/2026/07/cropped-Wilder-PTSA-Header-1-1.png
```

Then, in a browser, because these cannot be checked with curl: log in, load the
dashboard, view a product page, add to cart, and confirm the cart survives a page
load. Cart behaviour is the single most likely casualty of a host-header or
caching mistake.

### Rollback

```bash
./infra/aca-wordpress/scripts/afd-switch-origin.sh swa    # back to the placeholder
./infra/aca-wordpress/scripts/afd-switch-origin.sh wordpress  # or to App Service
```

The script purges the endpoint on the way through. Because no DNS changes, this
takes effect in about a minute.

## Watch for afterwards

- **Concurrency.** The container runs at 0.5 vCPU / 1.0 GiB, right-sized against
  single-request latency at a time when only operators were hitting it. Real
  traffic is the untested direction. Watch `UsageNanoCores` and
  `WorkingSetBytes`, and note that `maxReplicas` is pinned to 1, so there is no
  horizontal headroom at all.
- **Scale-out is not currently safe.** Raising `maxReplicas` needs thought:
  multiple replicas share the uploads file share, and the object cache is shared
  in Redis, but nothing has been tested that way.
- **Edge caching** is the natural follow-up once the origin is proven, and is
  where the remaining ~0.9s goes away for anonymous visitors. Treat WooCommerce
  and logged-in sessions as bypass rules from the start, not as an afterthought.

## Decision already taken: the container is the target

`infra/school-year-redeploy/` used to sit alongside this, provisioning a whole new
App Service stack (B1) for the fall reopen. It was removed on 2026-08-03: App
Service is being retired, and the measurements had made that plan hard to justify
anyway — the container serves its homepage in ~0.9s against 1.0–1.4s from the
existing P1v3, and the redeploy plan specified B1, which is weaker still.

`scripts/afd-switch-origin.sh` came across from that directory because it manages
the live domain's route and is not tied to App Service. Its `wordpress` target is
kept deliberately: until the App Service is actually deleted it remains the
fastest rollback if the container has a bad day. Once App Service is gone, that
target should go with it, leaving `container` and `swa`.
