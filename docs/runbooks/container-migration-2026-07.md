# Migrating wilderptsa.net to Azure Container Apps

Replaces the App Service deployment with a Container App. Content came from the
redesigned site on `wp.theburgessfamily.us`: the database by direct dump and
import, the files by archive upload and extraction. UpdraftPlus was the original
plan and was not used — direct transfer needed no plugin, no browser upload limit
and no admin session.

## Status

Phase 1 complete. The site is fully populated and serving on the container URL.

| Thing | Value |
| --- | --- |
| Container app | `wilderptsa-wp` |
| URL | https://wilderptsa-wp.wittysky-40aa8bc1.westus2.azurecontainerapps.io |
| Database | `wilderptsa_wp` on `wilderptsa-wpdb-small` — 92 tables, 1,862 posts, 735 users |
| `wp-content` | Azure Files share `wp-content` — 13,780 files, theme + 4 plugins + 220 MB uploads |
| Image | Stock `wordpress:6.9.4-php8.3-apache` plus a startup Apache rewrite config |
| Ingress | Allow-listed to the operator IPv4 only |
| Sizing | 1 vCPU / 2 GiB, pinned to 1 replica |

Verified end to end: pages resolve on pretty permalinks (`/art-docent/`,
`/room-parents/`, `/volunteer/`) in 0.4–1.0 s, media serves from the share,
missing paths return a real 404, `/wp-admin/` returns a 302 to login, and no PHP
warnings are emitted.

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

Content URLs currently point at the container hostname. Set `NEW_URL` in
`job-url-rewrite.yaml` to `https://wilderptsa.net`, and `OLD_URL` to the
container hostname, then run it again. Rewriting straight to the final domain
before cutover was rejected on purpose: wilderptsa.net still serves the parked
SWA, so every image would have 404'd during verification.

## Phase 2 — bake an image, for the performance win

Once the migrated site is verified, move `wp-content` from the share into the
image. This is where the real speed improvement lives.

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
- `build.sh` — assembles the context and pushes to GHCR (free for private
  images, so no paid registry is needed)

Point `build.sh` at the migrated `wp-content` from the share, publish, then
switch the app to that image and reduce the mount to `wp-content/uploads` only.

The trade-off: plugin and theme updates through the dashboard stop working,
because the code layer is read-only. Updates become a rebuild. That is the
price of `validate_timestamps=0`, and it is worth it here.

## Remaining work

1. Replace WP-Cron with a scheduled Container Apps job. `DISABLE_WP_CRON` is
   set, so nothing currently fires scheduled work — newsletters, calendar sync
   and backups will not run until this exists. Use `job-db-import.yaml` as the
   structural template; define jobs in YAML, not CLI flags.
2. Check the four live URLs with no matching page slug, and add redirects if they
   are still wanted: `/become-an-lwsd-approved-volunteer/`,
   `/meet-your-2025-26-ptsa-board-committee/`,
   `/room-parents-event-volunteers-needed-at-wilder/`,
   `/wilder-library-did-you-know/`. Only *pages* were compared, so these may
   exist as posts — confirm before treating them as missing.
3. Confirm the four active plugins behave on the new host, particularly
   WooCommerce and WooCommerce Payments. Payment gateway credentials and webhook
   URLs are host-specific and were not touched by the URL rewrite.
4. The `Azure Plugin` on the share is 3.142.0 from this repo, while the imported
   database came from 3.141.12. That is a normal plugin upgrade path, but the
   upgrade routine has not been exercised against this data — check the plugin's
   own admin pages before cutover.
5. Attach the custom domain and switch the Front Door production route from
   `summer-swa-origin-group` to the container app. Rollback is switching it
   back, which is why the SWA placeholder should stay in place.
6. Re-run `job-url-rewrite.yaml` for the final domain — see "At cutover" above.
7. Remove the ingress IP allow-list at cutover, or restrict it to Front Door.
   Note it can only hold IPv4: Container Apps rejects IPv6 ranges, and a stale
   entry presents as an Envoy `RBAC: access denied` 403 that reads like an
   authentication failure.
8. Rewrite `deploy-staging.yml` / `promote-prod.yml` and the two `.cursor` rules
   files. They all describe `az webapp deploy` zip pushes to App Service, which
   no longer applies. **Until this is done those rules are actively misleading.**
9. Spin down App Service, then delete resources listed below.

## Cost

| Item | Now | After |
| --- | --- | --- |
| Compute | App Service P1v3 $110.67 | Container app ~$15–25 |
| MySQL | 2 × B1ms $70.32 | 1 × B1ms (64 GB) ~$20 |
| Front Door Standard | $34.37 | $34.37 — holds custom domains, TLS, park switch |
| Azure Monitor | $20.88 | ~$5 with a daily cap |
| Redis | $15.71 | $0 until the caching work happens |
| Registry | — | $0 (GHCR) |
| Storage / DNS | $1.00 | ~$3 (adds the wp-content share) |
| **Total** | **$252.96** | **~$83** |

## Safe to delete, in this order, after the new site is verified

1. `wordpress-dev` container app and the `migration` blob container — scaffolding.
2. The `wilderptsa_c20b298090_database` copy on `wilderptsa-wpdb-small`.
3. App Service `wilderptsa` and plan `ASP-PTSAWebsite-a9e9`.
4. `wilderptsa-c20b298090-wpdbserver` — **only** after archiving a verified
   dump. It holds the WooCommerce order history, which a PTA may be required to
   retain.

Keep the Free Static Web App: it is the rollback target and the summer park
placeholder.

## Also outstanding

The storage account key and Redis password are stored in plaintext App Service
app settings and were exposed during this work. Rotate both and move them to
Key Vault references as part of the cutover.
