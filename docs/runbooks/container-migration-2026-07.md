# Migrating wilderptsa.net to Azure Container Apps

Replaces the App Service deployment with a Container App. Content came from the
redesigned site on `wp.theburgessfamily.us`: the database by direct dump and
import, the files by archive upload and extraction. UpdraftPlus was the original
plan and was not used — direct transfer needed no plugin, no browser upload limit
and no admin session.

## Status

**Complete and live.** Both phases are done, `wilderptsa.net` cut over to the
container app, and App Service has been deleted. Verified 2026-08-06.

| Thing | Value |
| --- | --- |
| Container app | `wilderptsa-wp` (revision `--0000018`) |
| Public URL | https://wilderptsa.net and `www`, via Front Door `WilderPTSAAFD` |
| Origin URL | https://wilderptsa-wp.wittysky-40aa8bc1.westus2.azurecontainerapps.io — returns 403 direct; Front Door only |
| Database | `wilderptsa_wp` on `wilderptsa-wpdb-small` — B1ms Burstable, 64 GB, MySQL 8.0.21, **no public access** |
| Image | `wilderptsaacr.azurecr.io/wilderptsa-wp:<plugin version>` — `wp-content` baked in |
| Mount | Azure Files share `wp-content`, `subPath: uploads`, at `wp-content/uploads` only |
| Secrets | Key Vault references to `mysql-small-ptsadbadmin-password` and `redis-primary-key` |
| Sizing | **0.5 vCPU / 1 GiB, min = max = 1 replica** |
| Cron | `wilderptsa-wpcron` job, hourly |

Verified end to end: pretty permalinks resolve, media serves, missing paths
return a real 404, `/wp-admin/` returns 302, and the smoke script passes all six
checks.

Two corrections to earlier drafts of this document, both worth knowing because
they change what you would conclude from it:

- **Sizing is 0.5 vCPU / 1 GiB, not the 1 vCPU / 2 GiB recorded during Phase 1.**
  It was reduced at some point after cutover. That halving is the main reason
  TTFB now sits near a second — see Performance below.
- **The App Service is gone**, so the deployment procedure in this repository's
  `.cursor/rules/` is not merely outdated, it cannot succeed. See Deploying.

## What was actually migrated, and how

Source was MariaDB 12.3.2 against MySQL 8.0.21 on the Azure side. That
combination is usually the risk in a WordPress move, so it was checked before
dumping: all 92 tables used `utf8mb4_unicode_520_ci` with three `utf8mb4_bin`
columns — both exist in MySQL 8 — and there were no views, routines or triggers.
Had the source used MariaDB's newer `uca1400` collations, MySQL 8 would have
rejected the dump outright.

1. **Database.** `mariadb-dump --single-transaction` (18 MB), MariaDB's three
   `/*M!...*/` executable comments stripped, uploaded to blob, then imported by
   `job-db-import.yaml`. The import has to run in the cluster: the MySQL server
   is private, so no workstation can reach it.
2. **Theme and plugins.** Only four plugins were active
   (`Azure Plugin`, `templatespare`, `woocommerce`, `woocommerce-payments`) plus
   the `chromenews` theme, so 233 MB of plugin directory was never copied. The
   three public ones were fetched from wordpress.org at the exact versions the
   source ran, which was verified by comparing version headers.
3. **Uploads.** 220 MB tarred and moved by blob.
4. **URLs.** `job-url-rewrite.yaml` (WP-CLI) made 2,522 replacements.

### Use blob storage, not the file share, for large transfers

Uploading to the Azure Files share was unusable: a 220 MB file timed out, and a
retry loop over 20 MB parts hung for hours. The same content went to blob
storage in 47 seconds with `--max-connections 8`. Jobs then pull from blob using
the managed identity (`Storage Blob Data Reader`), so no key or SAS is committed.

### Two traps that cost real time

**macOS AppleDouble files.** Copying from an SMB mount on a Mac produces `._name`
sidecars holding extended attributes. They are invisible to `ls` on that mount
because the SMB client folds them back into xattrs, so they are easy to ship
without noticing — 9,787 of them reached the share. Most were harmless, but
WordPress loads *every* `.php` in `mu-plugins`, so `._pta-header-account.php` was
executed, emitted binary output before any headers, and produced
`Cannot modify header information`. The visible symptoms were indirect:
`/wp-admin/` returned 200 instead of redirecting to login, and missing pages
returned 200 with the 404 template — a soft 404. Set `COPYFILE_DISABLE=1` before
`tar` on macOS, and verify with `find . -name '._*'` rather than trusting a
grep pattern (BSD `grep` does not support GNU `\|` alternation in a basic regex,
so a check written that way silently matches nothing).

**Small files on SMB are slow.** Writing ~9,000 code files to the share ran at
roughly 5 files/second — about 30 minutes — while the 220 MB of uploads took 2.
Reading was equally bad in the other direction: tarring the same tree from a Mac
over SMB was on track for ~30 minutes. This is the strongest argument for
Phase 2, independent of OPcache.

## Pretty permalinks need Apache config, not .htaccess

The imported site had `permalink_structure` empty, while live wilderptsa.net uses
`/%postname%/`. Cutting over without fixing that would have 404'd every existing
link and search result. 35 of 39 live top-level URLs match a page slug in the new
database, so the redesign mostly preserved them.

Setting the option is not sufficient in a container. WordPress wants rewrite
rules in `.htaccess` at the document root, but that path is in the container's
ephemeral layer, so anything written there is lost on the next restart or
revision. The rules are therefore installed by the container's startup command on
every boot, in `containerapp-prod.yaml`. Do not "fix" this by saving Permalinks
in wp-admin: it appears to work and then silently reverts on restart.

## Why containers, honestly

Not for raw compute speed. Measured server-side PHP time via the plugin's
`X-PTA-Trace` header, five samples each:

| | App Service (old site) | Unraid (new site) |
| --- | --- | --- |
| PHP time | 358–428 ms, consistent | 423–2006 ms, erratic |
| Files loaded | 2,325 | 1,836 |
| Queries | 61 | 66 |

The database was ruled out as well: I/O consumption averages 0.78% and
Burstable CPU credits never deplete, so B1ms is not a constraint.

The move is justified by cost — roughly $83/month against $252.96 — plus one
real performance lever that only an immutable image can pull, described under
Phase 2. Separately, Front Door caching is currently disabled on every route
(`cacheConfiguration: null`), which remains the single largest untapped win and
is free.

## The jobs, and why each exists

All defined in `infra/aca-wordpress/`. Define jobs in YAML, not CLI flags: the
CLI's `--args` cannot carry a script containing spaces, and quoting failures
there produced silent misbehaviour rather than errors.

| Job | Purpose |
| --- | --- |
| `job-db-import.yaml` | Import the dump. `mysql:8` client; the server is private so this must run in-cluster. |
| `job-content-extract.yaml` | Pull archives from blob and unpack onto the share. |
| `job-url-rewrite.yaml` | WP-CLI `search-replace`. Re-run at cutover. |
| `job-permalinks.yaml` | Set `permalink_structure`; also lists page slugs. |
| `job-cleanup-appledouble.yaml` | Delete `._*` and `.DS_Store` from the share. |
| `job-db-sql.yaml` | Read-only survey of URL state. |

Three things these jobs had to work around, all of which will recur:

- **`azure-cli` has no `tar`.** Extraction uses Python's `tarfile` instead, which
  is guaranteed present because the CLI is written in Python. The `data` filter
  also drops ownership metadata, which suits an SMB mount where `chown` fails.
- **WP-CLI needs an explicit PHP memory limit.** `/usr/local/bin/wp` is the phar,
  not the shell wrapper, so `WP_CLI_PHP_ARGS` is ignored and the 128 MB default
  applies — too small even to unpack core. Invoke
  `php -d memory_limit=1024M /usr/local/bin/wp`.
- **WP-CLI needs the TLS flag.** Azure MySQL runs
  `require_secure_transport=ON`. `wp db check` passes without it because it
  shells out to the `mysql` client, which negotiates TLS itself — so the check is
  misleading. WordPress's own `mysqli` connection needs
  `MYSQL_CLIENT_FLAGS = MYSQLI_CLIENT_SSL` injected via `--extra-php`.

### Rewriting URLs

Use WP-CLI, never SQL `REPLACE()`. Three affected options
(`azure_plugin_settings`, `fs_accounts`, `jetpack_plugin_api_action_links`) hold
serialized PHP, where substituting a string of a different length corrupts the
length prefixes. WP-CLI unserializes and re-serializes correctly.

`siteurl` and `home` matter less than they look: `WP_HOME` and `WP_SITEURL` are
derived from the request host in `wp-config.php` and take precedence, which is
what let the site answer correctly on the container hostname immediately after
the import.

One residual match is expected and deliberate: `fs_accounts` retains the bare
hostname `wp.theburgessfamily.us`. That is the Freemius SDK's own record of where
the plugin was activated, not a rendered URL. Freemius detects host changes
itself; editing its state by hand risks confusing its licence handling.

Worth knowing for other sites even though it did not apply here: JSON-encoded
values escape their slashes (`https:\/\/host`), so a plain `https://host` search
never matches them. This database was checked and had none.

### At cutover, re-run the rewrite

*Done — kept because the sequencing is the reusable part.*

Content URLs pointed at the container hostname, so at cutover `NEW_URL` in
`job-url-rewrite.yaml` was set to `https://wilderptsa.net` and `OLD_URL` to the
container hostname, and the job re-run. Rewriting straight to the final domain
*before* cutover was rejected on purpose: wilderptsa.net was still served by the
parked placeholder SWA at that point, so every image would have 404'd during
verification. Two rewrites was the cost of being able to verify the site at all.

## Deploying — the only supported path

**Plugin and theme changes ship as a new image.** The code layer is read-only,
so there is no zip push and no dashboard update. Three steps:

```bash
# 1. Commit first. build.sh derives the tag from the plugin version header,
#    so an uncommitted tree produces an image that matches no commit.
git commit -am "…"

# 2. Build and push. Tag comes from "Version:" in Azure Plugin/azure-plugin.php.
./infra/wp-image/build.sh

# 3. Point the app at the new tag.
az containerapp update -g PTSAWebsite -n wilderptsa-wp \
  --image wilderptsaacr.azurecr.io/wilderptsa-wp:<version>
```

Then verify, because a healthy revision does not prove the new code is serving:

```bash
# Wait for the revision to reach Healthy at 100% traffic, then confirm the
# asset actually changed. Use --compressed: without it curl writes the gzip
# body to disk and every grep silently finds nothing.
curl -s --compressed \
  "https://wilderptsa.net/wp-content/plugins/Azure%20Plugin/assets/pta-shortcodes.js" \
  | grep -c "<a string only the new code has>"
bash infra/post-change-smoke.sh
```

Mid-rollout requests can still hit the old replica, so a single stale response
right after `containerapp update` is expected rather than a failed deploy.
Re-check once traffic shows 100% on the new revision.

`build.sh` pushes to **ACR** (`wilderptsaacr`), not GHCR as an earlier draft of
this document said, and tags both `:<version>` and `:latest`.

### The rules files in this repo are wrong

`.cursor/rules/deployment.mdc` and `.cursor/rules/deployment-safety.mdc` both
describe `az webapp deploy -g PTSAWebsite -n wilderptsa`. That App Service no
longer exists. Everything those files say about `--clean true`, relative
`--target-path` and Kudu VFS applies to a host that is gone. Until they are
rewritten, an agent that follows them will fail — and their genuinely useful
warnings now protect nothing. Rewriting them is the highest-value item left.

## Phase 2 — bake an image, for the performance win

*Done.* `wp-content` now lives in the image and the share is mounted only at
`wp-content/uploads`. Recorded here for the reasoning.

The site loads roughly 1,800–2,300 PHP files per request. On a mutable
filesystem OPcache must `stat()` each one on every hit, and over SMB that is
expensive — this is the actual reason App Service felt slow. An immutable image
allows `opcache.validate_timestamps=0`, removing those checks entirely.

`infra/wp-image/` already contains this, test-built and verified for
`linux/amd64`:

- `Dockerfile` — official WordPress base, `wp-content` baked in, uploads excluded
- `php-opcache.ini` — `validate_timestamps=0`, 30,000 file slots, 256 MB
- `php-wordpress.ini` — 512 MB memory, 64 MB uploads, generous realpath cache
- `healthz.php` — liveness endpoint that deliberately never touches MySQL
- `build.sh` — assembles the context and pushes to ACR `wilderptsaacr`. GHCR was
  the original plan; ACR is what shipped.

Point `build.sh` at the migrated `wp-content` from the share, publish, then
switch the app to that image and reduce the mount to `wp-content/uploads` only.

The trade-off: plugin and theme updates through the dashboard stop working,
because the code layer is read-only. Updates become a rebuild. That is the
price of `validate_timestamps=0`, and it is worth it here.

## Cutover — the static site was the switch

The domain was moved using a **free Static Web App as a placeholder origin**
behind Front Door. Front Door already held `wilderptsa.net`, `www`, and the TLS
certificates, so the cutover was an origin swap rather than a DNS change: point
the route at the placeholder, then at the container app once verified. That
keeps the domain and certificate bindings untouched throughout, and makes
rollback a single origin switch instead of a DNS propagation wait.

The placeholder has since been **deleted deliberately** — it was scaffolding for
the switch, not a permanent rollback target. An earlier draft of this document
said to keep it as the rollback and summer-park placeholder; that is no longer
the arrangement, and no Static Web App exists in the subscription now.

Consequence worth stating plainly: **there is currently no parked placeholder to
switch to.** If the park/rollback capability is wanted again — for next summer,
or for an incident — it has to be recreated. `infra/school-year-redeploy/`
contains a `summer-swa.bicep` module and an `afd-switch-origin.sh` script, which
is the same pattern and the natural starting point.

## Performance — measured, and the one free win

Measured 2026-08-06 from a residential connection, medians of six cache-busted
requests per URL:

| URL | TTFB | Range |
| --- | --- | --- |
| `/` | 1,265 ms | 1,214–1,634 |
| `/ptsa/` | 1,189 ms | 895–1,445 |
| `/art-docent/` | 1,011 ms | 931–1,242 |
| `/shop/` | 950 ms | 817–1,047 |

The network is not the problem. DNS, TCP and TLS together account for roughly
40 ms; essentially all of the remainder is origin think time. Two findings
explain it:

**Front Door caching was entirely disabled.** Every route had
`cacheConfiguration: null`, and responses carried `x-cache: CONFIG_NOCACHE`, so
every visit to every page was a full PHP render. Compression at the edge was off
too. For a site that is mostly static pages this was the largest available win
and it cost nothing. It was switched on the same day — see *Edge caching* below
for the arrangement, the measured result and the traps.

**0.5 vCPU is the binding constraint.** During the measurement above — a single
*sequential* request loop, no concurrency — container CPU averaged 0.29 vCPU and
peaked at 0.43 against its 0.50 limit, about 86% of ceiling. One visitor at a
time nearly saturates the app, and with `minReplicas = maxReplicas = 1` there is
no headroom for a second. Memory is comfortable at ~390 MB of 1,024. Raising CPU
to 1.0 and allowing 2–3 replicas is the obvious next step, and restores the
Phase 1 sizing this document originally recorded.

Do not read these numbers against the "App Service 358–428 ms" figure under *Why
containers, honestly* above: that was server-side PHP time from `X-PTA-Trace`,
which excludes web server overhead and the network, so it is not comparable to
wire TTFB — comparing the two would overstate the regression. The same header
today reports 108–411 ms elapsed at plugin-init, which is only the bootstrap
portion of the request — theme and WooCommerce rendering happen after it, and
account for most of the remaining time.

## Edge caching

Switched on 2026-08-06 in plugin v3.144.2. Anonymous page views are served from
the Front Door edge; everything personal or transactional is not.

Measured immediately after, medians of six requests per URL from a residential
connection. Cached figures are repeat requests to a warm URL, uncached are
cache-busted:

| URL | Cached TTFB | Uncached TTFB |
| --- | --- | --- |
| `/` | 567 ms | 1,228 ms |
| `/ptsa/` | 257 ms | 1,217 ms |
| `/art-docent/` | 477 ms | 1,021 ms |
| `/shop/` | 358 ms | 1,284 ms |

Median across pages: **418 ms cached against 1,222 ms uncached**, so roughly a
third of the previous time, and the origin stops seeing the request at all. The
spread is wide (145–1,474 ms) because the measurement is over a home connection;
treat the medians as the signal.

### How correctness is arranged

Three layers, in order of how much they are trusted.

**The origin decides, and it is the only guarantee.** `Azure_Edge_Cache` sends
`Cache-Control: private, no-store` on every response rendered for a signed-in
user, and on anonymous responses that are not shareable — cart, checkout,
account, previews, search, 404s, password-protected posts, and any visitor
holding a cart, WooCommerce session, `wp-postpass_` or commenter cookie.
Everything else gets `public, max-age=0, s-maxage=300`. Front Door honours this:
a `no-store` response reports `x-cache: PRIVATE_NOSTORE` and is neither stored
nor served. Front Door assigns its own default TTL to a response carrying no
cache directives at all, which is why both branches are explicit rather than one
of them staying silent.

**Front Door rules are defence in depth, not the guarantee.** They are
configuration, changeable in the portal without review, so nothing
safety-critical rests on them alone. Rule set `wpcachebypass` on the route
disables caching for `/wp-admin/`, `/wp-login.php`, `/wp-cron.php`, `/wp-json/`,
`/xmlrpc.php`, `/cart/`, `/checkout/`, `/my-account/`, for requests carrying
`wordpress_logged_in_`, `woocommerce_items_in_cart`, `woocommerce_cart_hash`,
`wp_woocommerce_session_` or `wp-postpass_` cookies, and for `add-to-cart`,
`wc-ajax`, `removed_item` and `undo_item` query strings. Ordinary cookies do not
bypass: a request carrying only `_ga` still gets `TCP_HIT`, which is what keeps
the hit ratio worth having.

**The header hydrates client-side, so a cached page fits any visitor.** The one
personal element on an otherwise generic page was the account dropdown. For
anonymous requests the shortcode now renders a guest shell that is identical for
everybody, and a small script swaps in the real account menu when the
`pta_signed_in` marker cookie is present. The marker exists because WordPress
sets `wordpress_logged_in_` HttpOnly, so a cached page cannot otherwise tell; it
carries presence only, never identity. Signed-in requests bypass the cache and
render the real menu inline, so they pay no extra round trip.

That last layer is what makes the whole thing safe to get wrong. If a cookie
rule fails to apply and a signed-in visitor is served the cached anonymous copy,
they still end up with their own account menu instead of a stranger's.

Hydration uses `admin-ajax.php`, not the REST API. A REST request authenticated
only by cookies and carrying no `X-WP-Nonce` resolves to user 0, so the endpoint
would always answer "not signed in" — and the nonce cannot be embedded in the
page, because the page is shared between visitors and the nonce expires.

### Purge on publish

Publishing a public post, or saving a nav menu, purges the endpoint. Debounced to
one purge per 30 s so a burst of edits does not fire repeatedly, dispatched
non-blocking so the editor never waits on ARM, and gated on `edit_posts` so
parent account activity cannot trigger it. `s-maxage=300` is the backstop if a
purge is ever lost.

The container authenticates with the `id-wilderptsa-aca` user-assigned identity.
Two things had to be fixed for this to work at all:

- **Container Apps does not answer on the flat IMDS address.** The existing code
  asked `http://169.254.169.254/metadata/identity/...`, which only responds on
  VM-hosted platforms, so the purge had been silently failing since cutover.
  Container Apps injects `IDENTITY_ENDPOINT` (observed as
  `http://localhost:12356/msi/token`) and `IDENTITY_HEADER`, and expects
  `X-IDENTITY-HEADER`. The plugin now prefers that and keeps the flat address as
  a fallback.
- **No built-in role grants AFD purge narrowly.** `CDN Endpoint Contributor`
  covers classic CDN `profiles/endpoints/*` and does *not* include
  `Microsoft.Cdn/profiles/afdEndpoints/purge/action` — it returns 403. The only
  built-in role that does is `CDN Profile Contributor`, via `Microsoft.Cdn/
  profiles/*`, which would also let a compromised WordPress rewrite origins,
  routes and custom domains. A custom role, **Front Door Purge Only**, grants
  purge plus reads and nothing else, assigned at the profile scope.

Env vars carrying this config on the container app: `AZURE_CLIENT_ID`,
`AZURE_SUBSCRIPTION_ID`, `AZURE_RESOURCE_GROUP`, `AFD_ENABLED`,
`AFD_PROFILE_NAME`, `AFD_ENDPOINT_NAME`, `AFD_DOMAIN`.

To verify the Azure half without involving WordPress, run the
`wp-afd-purge-check` job (`infra/aca-wordpress/job-afd-purge-check.yaml`). It
reproduces the token fetch and the purge call and prints `RESULT=purge-accepted`,
`RESULT=forbidden-check-role-assignment` or `RESULT=no-token`, which localises a
failure to the platform rather than the plugin.

### Rule changes do not apply until the route re-associates the rule set

This is the trap that cost the most time, and it will mislead anyone who changes
these rules.

Editing a rule inside a rule set — creating, deleting, changing values — reports
`provisioningState: Succeeded` and takes effect at the edge **not at all** until
the route is updated to reference the rule set again:

```bash
az afd route update -g PTSAWebsite --profile-name WilderPTSAAFD \
  --endpoint-name wilderptsa-c20b298090 --route-name default-route \
  --rule-sets wpcachebypass
```

Until that runs, the edge keeps enforcing the previous generation of the rule
set. Deleted rules keep working and new rules do nothing, which reads exactly
like "my condition is wrong". Two wrong conclusions were reached this way: first
that cookie conditions match when the header is absent (they do not — that was
deleted rules still being enforced), then that cookie conditions never work at
all (they do, once the route re-associates). `deploymentStatus` sits at
`NotStarted` throughout and is not a useful signal.

After re-associating, allow about five minutes and only then test. Verify with
the cookie matrix rather than a single request, because a partial rollout gives
different answers per attempt:

```bash
# cookieless and _ga must HIT; wordpress_logged_in_ and cart cookies must not
for C in "" "_ga=GA1.2.1.1" "wordpress_logged_in_abc=u|1|t|h" "woocommerce_cart_hash=abc"; do
  printf '%-34s ' "${C:-<none>}"
  curl -sS -D - -o /dev/null --compressed ${C:+-b "$C"} https://wilderptsa.net/ptsa/ \
    | grep -i '^x-cache:'
done
```

`--clean true` is not involved anywhere here, and must never be: see
`.cursor/rules/deployment-safety.mdc`.

### WooCommerce page IDs were stale, which this exposed

`woocommerce_cart_page_id` and `woocommerce_checkout_page_id` still pointed at
pages 9 and 10. The redesign deleted both and rebuilt cart and checkout as the
block-based pages 23140 and 23141. `woocommerce_myaccount_page_id` was correct at
11, which is why `is_account_page()` worked and `is_cart()` did not.

The consequence went beyond caching: while those options were wrong,
`is_cart()` and `is_checkout()` were false on the real cart and checkout pages,
so WooCommerce never sent its own no-cache headers there, and
`wc_get_cart_url()` could not resolve. Fixed by
`infra/aca-wordpress/job-fix-woo-page-ids.yaml`, followed by a Redis flush so the
options were re-read. `Azure_Edge_Cache` additionally detects cart and checkout
by their blocks and shortcodes, so the cache decision no longer depends on those
options staying correct.

## Remaining work

Ordered by value as of 2026-08-06. Items 1–3 are the live ones.

1. **Rewrite `.cursor/rules/deployment*.mdc` for the container path.** They
   currently instruct any agent to deploy to an App Service that no longer
   exists. See Deploying above.
2. **Enable Front Door caching and edge compression.** Free, and the largest
   remaining performance win. Exclude WooCommerce cart, checkout and
   authenticated sessions.
3. **Restore sizing to 1 vCPU / 2 GiB and allow 2–3 replicas.** A single
   sequential visitor already reaches 86% of the 0.5 vCPU ceiling.
4. ~~Replace WP-Cron with a scheduled Container Apps job.~~ **Done** —
   `wilderptsa-wpcron` runs hourly and is firing. `DISABLE_WP_CRON` remains set,
   which is correct.
5. Rotate the storage account key and move it to a Key Vault reference. The Redis
   password is already a Key Vault reference; the storage key is not, and it was
   exposed in plaintext App Service settings before that host was deleted.
6. Recreate a parked placeholder origin if the park/rollback capability is still
   wanted — see Cutover above.
7. Check the four live URLs with no matching page slug, and add redirects if they
   are still wanted: `/become-an-lwsd-approved-volunteer/`,
   `/meet-your-2025-26-ptsa-board-committee/`,
   `/room-parents-event-volunteers-needed-at-wilder/`,
   `/wilder-library-did-you-know/`. Only *pages* were compared, so these may
   exist as posts — confirm before treating them as missing.
3. Confirm the four active plugins behave on the new host, particularly
   WooCommerce and WooCommerce Payments. Payment gateway credentials and webhook
   URLs are host-specific and were not touched by the URL rewrite.
Item 1 also covers `deploy-staging.yml` and `promote-prod.yml`, which describe the
same App Service zip push and are equally dead.

### Closed at cutover

- **Custom domain and route switch.** `wilderptsa.net` and `www` are attached to
  Front Door and the single `default-route` sends `/*` to the container app.
- **Ingress restricted to Front Door.** Direct requests to the container FQDN
  return 403. Note the allow-list can only hold IPv4 — Container Apps rejects
  IPv6 ranges, and a stale entry presents as an Envoy `RBAC: access denied` 403
  that reads like an authentication failure.
- **URL rewrite re-run for the final domain.**
- **Plugin upgrade path exercised.** The concern was 3.142.0 in the repo against
  3.141.12 in the imported database; the site has since run 3.143.x and been
  deployed repeatedly against this data.
- **App Service spun down and deleted.**

## Cost

| Item | Now | After |
| --- | --- | --- |
| Compute | App Service P1v3 $110.67 | Container app ~$15–25 |
| MySQL | 2 × B1ms $70.32 | 1 × B1ms (64 GB) ~$20 |
| Front Door Standard | $34.37 | $34.37 — holds custom domains, TLS, park switch |
| Azure Monitor | $20.88 | ~$5 with a daily cap |
| Redis | $15.71 | Basic C0 in use — the object cache drop-in is live and connected |
| Registry | — | ACR Basic, ~$5 (1.17 GB of the 10 GB allowance) |
| Storage / DNS | $1.00 | ~$3 (adds the wp-content share) |
| **Total** | **$252.96** | **~$83** |

These "After" figures are **estimates made before cutover, not measurements**.
They have not been validated, and cannot be from the portal APIs: this is a
Microsoft Grant subscription, and the consumption API returns a null cost on
every usage record, so per-resource spend reads as $0.00. Treat the total as a
projection. Note also that the Redis and registry lines above have been corrected
— the original table assumed Redis would sit unused at $0 and that a free
registry would be used.

## Teardown status, as of 2026-08-06

Already deleted and confirmed absent from the group: App Service `wilderptsa`,
plan `ASP-PTSAWebsite-a9e9`, the `wordpress-dev` container app, the old
`wilderptsa-c20b298090-wpdbserver` MySQL server, and the placeholder Static Web
App.

> **Check before going further.** `wilderptsa-c20b298090-wpdbserver` was deleted,
> and this document said to do that *only* after archiving a verified dump
> because it held WooCommerce order history a PTA may be required to retain.
> Confirm that dump exists. The `wilderptsa_c20b298090_database` copy still on
> `wilderptsa-wpdb-small` may now be the last surviving copy, so do not drop it
> until the archive is verified.

Still present and safe to remove:

1. `wilderptsa-c20b298090-wpidentity` — the old App Service managed identity, no
   longer referenced by the app or any job.
2. The `blobwilder-origin-group-c20b298090` Front Door origin group — orphaned;
   only one route exists and it sends `/*` to the container app.
3. Blob containers `blobwilderptsac20b298090` and `…staging` — legacy media
   offload, ~3,200 blobs and 2.6 GB between them, 86% of it `2026/03`. Both were
   checked against the media still missing from the site and contain none of it.
4. The `migration` file share and blob container. Detach the `migrationfiles`
   storage definition from the environment first.
5. The `wp-uploads` share — not mounted; the environment only defines
   `migrationfiles` and `wpcontent`.
6. `flexibleserverdb` — the empty default database.
7. The ten spent one-shot jobs: `wp-db-import`, `wp-content-extract`,
   `wp-url-rewrite`, `wp-permalinks`, `wp-role-migrate`, `wp-vp-link`,
   `wp-cleanup-appledouble`, `wp-audit-versions`, and similar.
8. Three stale subnets: `wilderptsa-c20b298090-appsubnet`,
   `wilderptsa-c20b298090-dbsubnet`, `wilderptsa-aci-subnet`.

**Do not delete the VNet or the private DNS zone.** They look like App Service
leftovers and are not: MySQL has public access disabled and is delegated into
`wilderptsa-dbsubnet-small`, and the Container Apps environment is VNet-injected
into `aca-subnet`. Removing either severs all database connectivity. The two
subnets that matter are the two least obviously named.

Keep `wp-db-query`, `wp-db-sql` and `wp-redis-flush`. They are migration-era but
they are the only way to reach a private database at all, and idle jobs bill
nothing.

None of this saves meaningful money — jobs bill per execution, empty subnets are
free, and 2.6 GB of blobs is pennies. The reason to do it is that a group holding
ten spent jobs and two near-identical blob containers is one where the next
person deletes the wrong thing.

## Secrets

The storage account key and Redis password were stored in plaintext App Service
app settings and were exposed during this work. That App Service is now deleted,
so the exposure surface is gone, but **the credentials themselves were never
rotated** — deleting the host does not undo the exposure.

Current state on the container app: the MySQL password and Redis key are Key
Vault references (`mysql-small-ptsadbadmin-password`, `redis-primary-key`)
resolved through the app's managed identity. The storage account key is still a
plain secret value. Rotating it and converting it to a Key Vault reference is
item 5 under Remaining work.
