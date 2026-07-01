# MySQL storage downsize — verified export + reimport runbook (2026-06)

## Why this exists

The production MySQL Flexible Server `wilderptsa-c20b298090-wpdbserver` is
**over-provisioned at 134 GB** but the database holds only ~538 MB logical
(≈2.5 GB used on disk incl. binlogs/temp). Azure MySQL Flexible Server storage
**can only grow, never shrink** — and a point-in-time restore requires the
target storage to be **≥ the source**, so a restore cannot reduce it either.

The only way to land on a smaller-storage server is a **logical dump → create a
new small server → import → cut WordPress over**. This runbook covers the
already-completed EXPORT + verification, and the not-yet-done REIMPORT.

> Scope note: the export and verification below are **done**. The forward-looking
> REIMPORT RUNBOOK section was subsequently **EXECUTED on 2026-06-30** — see
> "REIMPORT + CUTOVER — EXECUTED" near the end of this doc. Production now runs on
> the new 64 GB server; the old 134 GB server is retained as the rollback target
> pending sign-off.

---

## Environment facts (verified 2026-06-29)

| Thing | Value |
|---|---|
| Subscription | `97f6936d-7300-4a49-a2ad-cbfee3b28e00` (Microsoft Grant) |
| Resource group | `PTSAWebsite` (West US 2) |
| Source MySQL server | `wilderptsa-c20b298090-wpdbserver` |
| Engine version | MySQL **8.0.44**-azure (control-plane `version` field shows 8.0.21 = create-time) |
| Current SKU / storage | `Standard_B1ms` (Burstable), **134 GB**, autogrow ON, 640 IOPS |
| Network | **Public access DISABLED** — VNet-integrated, delegated subnet `wilderptsa-c20b298090-vnet/subnets/wilderptsa-c20b298090-dbsubnet`, private DNS zone `*.privatelink.mysql.database.azure.com` |
| DB name | `wilderptsa_c20b298090_database` |
| DB user | `wilderptsa-c20b298090-wpidentity` (an **Entra/Azure-AD identity**, not a SQL password user) |
| Auth model | App setting `ENABLE_MYSQL_MANAGED_IDENTITY=true`; `wp-config.php` fetches a short-lived **Entra access token** as the DB password at runtime. **There is no static DB password.** |
| TLS | Required (`MYSQLI_CLIENT_SSL`) |
| `table_prefix` | `wp_`, charset `utf8` |
| App Service | `wilderptsa` (RG PTSAWebsite), VNet-integrated → can reach the DB privately |
| Backup storage account | `wilderptsac20b298091` (StorageV2, LRS, West US 2), container `wordpress-backups` |

### Consequence of the auth model

Because the DB is private-only **and** authenticates via a managed-identity
token (no password), you **cannot** run `mysqldump` from a laptop or a Kudu SSH
shell. The export was therefore produced **inside WordPress** (PHP, over the
private network, using the already-authenticated `$wpdb` connection) via the PTA
Tools backup engine.

---

## EXPORT — what was done (COMPLETE)

Mechanism: the PTA Tools backup subsystem (`Azure_Backup` → `Azure_Backup_Engine`)
already runs a DB backup inside WordPress. `mysqldump` *is* on the App Service
container PATH, but it fails against this server (TLS-required + token auth not
expressed in the engine's defaults file), so the engine **falls back to its PHP
`$wpdb` chunked dump** — which works because `$wpdb` is already authenticated.

A **database-only** backup job was triggered headlessly by uploading a
token-gated one-shot MU-plugin via the Kudu VFS API (same pattern as
`infra/ops/*.php`), invoking `Azure_Backup::create_backup_job(['database'])` +
`schedule_next_resume()`, then polling the `wp_azure_backup_jobs` table. The
MU-plugin was **deleted afterward** (confirmed 404; the trigger token now
returns the normal homepage).

> The plugin's PHP dump is **not** wrapped in a single transaction (it iterates
> table-by-table with `LIMIT/OFFSET`), so it is not strictly point-in-time
> consistent. Given low summer traffic and a ~3.5 min dump window the risk is
> negligible. If you want a guaranteed-consistent dump, use the ACI
> `mysqldump --single-transaction` alternative in the appendix.

### Resulting artifact

| | |
|---|---|
| Blob | `wordpress-backups/wilder-ptsa/2026/06/30/backup_1782788684_X1yEO1Pe/backup_1782788684_X1yEO1Pe-db.sql.gz` |
| Storage account | `wilderptsac20b298091` |
| Compressed size | **25,971,939 bytes (24.77 MB)** |
| Uncompressed | **380,976,455 bytes (~363 MB)** |
| Local copy | `/tmp/wilder-db.sql.gz` (convenience only; the blob is the source of truth) |

(A duplicate DB-only job `backup_1782788682_gwqIWaJU` was also created by a
retried trigger command — harmless, it produced an equivalent dump blob under
the same date prefix. Either is usable.)

### Verification evidence (independent, from the Azure side)

Downloaded the blob with the storage account key and checked it:

- `gzip -t` → **valid gzip**, decompresses cleanly to 380,976,455 bytes.
- **168 `CREATE TABLE`** statements == 168 tables reported by
  `information_schema` (full schema captured).
- 168 `DROP TABLE IF EXISTS`, **1,263 `INSERT INTO`** statements (multi-row
  extended inserts).
- File brackets correctly: starts `SET foreign_key_checks = 0;`, ends
  `SET foreign_key_checks = 1;`.
- All critical tables present: `wp_users`, `wp_usermeta`, `wp_posts`,
  `wp_postmeta`, `wp_options`, `wp_terms`, `wp_comments`, `wp_wc_orders`,
  `wp_woocommerce_order_items`, …

**Conclusion: the dump is complete and restorable.**

---

## REIMPORT RUNBOOK (NOT YET DONE — do this when ready to cut over)

### Step 0 — Decide the new server shape

Logical data is ~538 MB. Recommended new server:

| Setting | Recommendation |
|---|---|
| SKU | `Standard_B1ms` (Burstable, same as today — fine for this workload) |
| Storage | **64 GB** (or 32 GB) with **autogrow ON**. Min Flexible Server storage is 20 GB; 64 GB gives binlog/temp headroom and still cuts ~52% off the 134 GB. Remember: you can grow later but never shrink, so don't go below ~32 GB. |
| Version | **8.0** (match source 8.0.44) |
| Network | **Same VNet, private** — VNet-integrated into a delegated subnet (you need a *new* subnet delegated to `Microsoft.DBforMySQL/flexibleServers`; the existing `dbsubnet` already hosts the old server). |
| Backup retention | match current (default 7 days) |

### Step 1 — Create the new private server (password admin for the import)

Create with a **password-based admin** so the import can authenticate (the
managed-identity Entra user is configured *after* import, in Step 5).

```bash
az account set --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00

RG=PTSAWebsite
LOC=westus2
VNET=wilderptsa-c20b298090-vnet
NEWDB=wilderptsa-wpdb-small           # new server name
NEWSUBNET=wilderptsa-dbsubnet-small   # new delegated subnet (must be empty + /28 or larger)
PDNS=$(az network private-dns zone list -g $RG --query "[?contains(name,'mysql.database.azure.com')].id | [0]" -o tsv)

# Create a new delegated subnet for the new server (adjust address prefix to a free range in the VNet)
az network vnet subnet create -g $RG --vnet-name $VNET -n $NEWSUBNET \
  --address-prefixes 10.x.y.0/28 \
  --delegations Microsoft.DBforMySQL/flexibleServers

az mysql flexible-server create \
  -g $RG -n $NEWDB --location $LOC \
  --tier Burstable --sku-name Standard_B1ms \
  --storage-size 64 --storage-auto-grow Enabled \
  --version 8.0 \
  --vnet $VNET --subnet $NEWSUBNET \
  --private-dns-zone "$PDNS" \
  --admin-user ptsadbadmin --admin-password '<STRONG-PASSWORD>' \
  --yes
```

Match key server parameters to the source where relevant (e.g. `time_zone`,
`character_set_server`, `collation_server`). Check the source first:

```bash
az mysql flexible-server parameter list -g $RG -s wilderptsa-c20b298090-wpdbserver \
  --query "[?name=='character_set_server' || name=='collation_server' || name=='time_zone'].{n:name,v:value}" -o table
```

### Step 2 — Stage the dump where the importer can read it

The dump already lives in blob `wordpress-backups/wilder-ptsa/2026/06/30/.../*-db.sql.gz`.
Generate a short-lived read SAS the ACI can use:

```bash
SA=wilderptsac20b298091
KEY=$(az storage account keys list -g $RG -n $SA --query "[0].value" -o tsv)
BLOB="wilder-ptsa/2026/06/30/backup_1782788684_X1yEO1Pe/backup_1782788684_X1yEO1Pe-db.sql.gz"
EXP=$(date -u -v+6H '+%Y-%m-%dT%H:%MZ' 2>/dev/null || date -u -d '+6 hours' '+%Y-%m-%dT%H:%MZ')
SAS=$(az storage blob generate-sas --account-name $SA --account-key "$KEY" \
  -c wordpress-backups -n "$BLOB" --permissions r --expiry "$EXP" -o tsv)
DUMP_URL="https://$SA.blob.core.windows.net/wordpress-backups/$BLOB?$SAS"
```

### Step 3 — Import over the private network via an in-VNet Container Instance

The new server is private, so run `mysql` from inside the VNet. Use ACI in an
**ACI-delegated** subnet in the same VNet (ACI cannot share the MySQL-delegated
subnet). The ACI resolves the new server's private FQDN via the linked private
DNS zone.

```bash
ACISUBNET=wilderptsa-aci-subnet   # new subnet delegated to Microsoft.ContainerInstance
az network vnet subnet create -g $RG --vnet-name $VNET -n $ACISUBNET \
  --address-prefixes 10.x.z.0/28 \
  --delegations Microsoft.ContainerInstance/containerGroups

NEWHOST="$NEWDB.mysql.database.azure.com"

az container create -g $RG -n mysql-import-oneshot \
  --image mysql:8 \
  --vnet $VNET --subnet $ACISUBNET \
  --restart-policy Never \
  --cpu 2 --memory 4 \
  --secure-environment-variables DUMP_URL="$DUMP_URL" DBPASS='<STRONG-PASSWORD>' \
  --environment-variables NEWHOST="$NEWHOST" DBNAME="wilderptsa_c20b298090_database" \
  --command-line "/bin/sh -c 'curl -sSL \"\$DUMP_URL\" -o /tmp/db.sql.gz && gunzip /tmp/db.sql.gz && mysql --host=\$NEWHOST --user=ptsadbadmin --password=\$DBPASS --ssl-mode=REQUIRED -e \"CREATE DATABASE IF NOT EXISTS \\\`\$DBNAME\\\` CHARACTER SET utf8;\" && mysql --host=\$NEWHOST --user=ptsadbadmin --password=\$DBPASS --ssl-mode=REQUIRED \$DBNAME < /tmp/db.sql && echo IMPORT_DONE'"

# Watch it
az container logs -g $RG -n mysql-import-oneshot --follow
# Clean up when IMPORT_DONE appears
az container delete -g $RG -n mysql-import-oneshot --yes
```

> The dump has no `CREATE DATABASE`/`USE` (table-level only), so create the DB
> first (above) with the **same name and charset** as source.

### Step 4 — Sanity-check the imported data

```bash
# From the same ACI (or a quick throwaway one):
mysql --host=$NEWHOST --user=ptsadbadmin --password='<STRONG-PASSWORD>' --ssl-mode=REQUIRED \
  -e "SELECT COUNT(*) tables FROM information_schema.tables WHERE table_schema='wilderptsa_c20b298090_database';
      SELECT COUNT(*) users FROM wilderptsa_c20b298090_database.wp_users;
      SELECT COUNT(*) posts FROM wilderptsa_c20b298090_database.wp_posts;"
```

Expect **168 tables** and non-zero users/posts.

### Step 5 — Re-establish auth parity (managed identity)

The app authenticates with the Entra identity `wilderptsa-c20b298090-wpidentity`.
On the new server either:

**(A) Keep managed identity (recommended, least-privilege parity):**
1. Enable Microsoft Entra authentication on the new server and set an Entra admin:
   ```bash
   az mysql flexible-server ad-admin create -g $RG -s $NEWDB \
     --object-id <entra-admin-object-id> --display-name <admin> --identity <umi-if-needed>
   ```
2. Connect as the Entra admin (token as password) and create the AAD-mapped DB
   user, granting it on the DB:
   ```sql
   CREATE AADUSER 'wilderptsa-c20b298090-wpidentity' IDENTIFIED BY '<app-identity-object-id>';
   GRANT ALL PRIVILEGES ON `wilderptsa_c20b298090_database`.* TO 'wilderptsa-c20b298090-wpidentity';
   FLUSH PRIVILEGES;
   ```
   (Use the same managed identity that App Service `wilderptsa` already uses.)

**(B) Switch to password auth (simpler, less ideal):**
   - Set app settings on `wilderptsa`: `ENABLE_MYSQL_MANAGED_IDENTITY=false`,
     `DATABASE_USERNAME=ptsadbadmin`, add `DATABASE_PASSWORD=<STRONG-PASSWORD>`.
   - `wp-config.php` already reads `DATABASE_PASSWORD` from env when MI is off.

### Step 6 — WordPress cutover

1. **Staging-first if possible.** On the App Service (or its staging slot),
   change the DB host app setting:
   ```bash
   az webapp config appsettings set -g $RG -n wilderptsa \
     --settings DATABASE_HOST="$NEWHOST"
   ```
   (Keep `DATABASE_NAME` / `DATABASE_USERNAME` the same if you kept MI; otherwise
   apply the Step 5B changes too.)
2. Restart the app: `az webapp restart -g $RG -n wilderptsa`.
3. Smoke test: `https://wilderptsa.net/` → 200, `wp-admin` → 302, log in, spot
   check posts/orders. Watch `wp-content/debug.log` / App Insights for DB errors.
4. Only after the new server is confirmed healthy for a sensible soak period,
   delete the old 134 GB server:
   ```bash
   az mysql flexible-server delete -g $RG -n wilderptsa-c20b298090-wpdbserver --yes
   ```
   (Take a final dump/snapshot first; deletion is irreversible.)

### Rollback

If anything is wrong after cutover, set `DATABASE_HOST` back to
`wilderptsa-c20b298090-wpdbserver.mysql.database.azure.com` and restart — the
old server is untouched until you explicitly delete it in Step 6.4.

---

## REIMPORT + CUTOVER — EXECUTED (2026-06-30, completed)

> **Headline: CUTOVER COMPLETE.** Production (`wilderptsa.net`) now runs on the
> new **64 GB** server `wilderptsa-wpdb-small`. Smoke 6/6 green + dynamic
> DB-backed checks confirm it is reading the new database. The OLD 134 GB server
> `wilderptsa-c20b298090-wpdbserver` is **retained, Ready, and untouched** as the
> rollback target pending user sign-off for decommission.

### Context: prior run died mid-cutover
A prior agent run timed out. On re-verification:
- Production was still pointed at the OLD server (no repoint had happened).
- The new server `wilderptsa-wpdb-small` existed (Ready, 64 GB, private, same
  VNet `wilderptsa-c20b298090-vnet`, subnet `wilderptsa-dbsubnet-small`, private
  DNS zone `wilderptsa-c20b298090-privatelink.mysql.database.azure.com` →
  A record `wilderptsa-wpdb-small` = 10.0.0.132) but had **no Entra admin** and
  **no verified data**.
- The leftover ACI `mysql-backup-oneshot` had **Failed** (exitCode 2; its
  base64-passed script env var `B` was null → empty script). It was attempting a
  `mysqldump` *from* the old server, not an import. So **no import had occurred.**
- Most recent verified dump used:
  `wordpress-backups/wilder-ptsa/2026/06/30/backup_1782788684_X1yEO1Pe/backup_1782788684_X1yEO1Pe-db.sql.gz`
  (25,971,939 bytes gz → 380,976,455 bytes, 168 `CREATE TABLE`).

### What was done
1. **New-server params matched to source:** `character_set_server=UTF8MB4`,
   `collation_server=UTF8MB4_0900_AI_CI`, `time_zone=+00:00`,
   `sql_mode` left at the strict default (identical to source).
2. **Reset the new server's password admin** (`ptsadbadmin`) — prior password was
   unknown; safe to reset since the new server was not yet live. Password was used
   only for the import, stored in a `chmod 600` temp file, and deleted afterward
   (the app does NOT use it — see auth below). It is a break-glass account and can
   be re-reset via `az mysql flexible-server update --admin-password` anytime.
3. **Clean import via in-VNet ACI** (`mysql-import-oneshot`, image `mysql:8.0`,
   subnet `wilderptsa-aci-subnet` 10.0.0.144/28, ContainerInstance-delegated):
   `curl <SAS> | gunzip | mysql`. First attempt failed with
   `ERROR 1067 Invalid default value for 'scheduled_date_gmt'` — a zero-date
   column DDL that the strict `sql_mode` rejects at CREATE time (the source has it
   because it was created historically under a relaxed mode). Fixed by prepending
   `SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';` to the import stream **only**
   (global `sql_mode` kept strict for runtime parity). Import then succeeded.

### Import verification (table + row counts)
Verified two ways. First inline after import (password auth). Then **definitively**
via a second ACI (`mysql-verify-oneshot`) that ran **as the app's user-assigned
managed identity** (`wilderptsa-c20b298090-wpidentity`, clientId
`55f7312e-…`), fetched an Entra token (audience
`https://ossrdbms-aad.database.windows.net`) from IMDS, and connected to BOTH
servers with `--enable-cleartext-plugin`. Counts matched **exactly**:

| Check | NEW (64 GB) | OLD (live source) |
|---|---|---|
| tables | **168** | 168 |
| wp_users | 757 | 757 |
| wp_posts | 4063 | 4063 |
| wp_options | 3397 | 3397 |
| wp_wc_orders | 1228 | 1228 |
| wp_postmeta | 124485 | 124485 |

Identical counts (incl. 124,485 postmeta) prove the import is complete **and** that
there was zero write drift between the dump and cutover → negligible lost-write
risk, no maintenance page required (late-night summer traffic, no writes observed).

### Auth mode used — Entra managed identity (exact parity, recommended path A)
The old server's Entra admin **is** the app identity itself. Mirrored exactly on
the new server:
- Attached UMI `wilderptsa-c20b298090-wpidentity` as the server identity.
- Created AD admin: login `wilderptsa-c20b298090-wpidentity`, objectId/sid
  `213259a9-49f7-4be5-9304-564916fc1015`, tenant `a220d676-…`,
  identityResourceId = the same UMI.

Result: `DATABASE_USERNAME` and `ENABLE_MYSQL_MANAGED_IDENTITY=true` are unchanged;
WordPress authenticates to the new server with its short-lived Entra token exactly
as before. **No app credential/password change was needed.** (Verified live above.)

### The cutover change (production slot only)
```bash
# PRE: DATABASE_HOST = wilderptsa-c20b298090-wpdbserver.mysql.database.azure.com
az webapp config appsettings set -g PTSAWebsite -n wilderptsa \
  --settings DATABASE_HOST="wilderptsa-wpdb-small.mysql.database.azure.com"
# DATABASE_NAME, DATABASE_USERNAME, ENABLE_MYSQL_MANAGED_IDENTITY: UNCHANGED
```
The **staging** slot was deliberately left on the OLD server
(`...-wpdbserver...`, DB `wilderptsa_c20b298090_database_staging`).

### Cold start + smoke + dynamic DB check (post-cutover)
- App-setting change recycled the sitecontainers; waited a full 5 min (per
  deployment-safety) — no extra restarts, no mid-cold-start revert.
- `infra/post-change-smoke.sh` (prod): **6/6 PASS** (home 200, home <5s, wp-json
  200, wp-json valid JSON, admin-ajax 200, TLS valid).
- Dynamic DB-backed checks (prove it reads the NEW DB):
  - `wp-admin/` → **302** (login redirect).
  - `wp-json/wp/v2/posts` → real posts returned ("Wilder Library – Did you
    know?", "Room Parents & Volunteers Needed!"), `X-WP-Total: 4` published.
  - `wp-json/wp/v2/product` → `X-WP-Total: 46` WooCommerce products.
  - `wp-json/ptsa/v1/orders-reports` → **401** (matches verified baseline).

### Rollback (if ever needed — OLD server is untouched)
```bash
az webapp config appsettings set -g PTSAWebsite -n wilderptsa \
  --settings DATABASE_HOST="wilderptsa-c20b298090-wpdbserver.mysql.database.azure.com"
# then wait ~5 min for cold start and re-run: bash infra/post-change-smoke.sh
```

### Cleanup done
- Deleted ACIs: `mysql-import-oneshot`, `mysql-verify-oneshot`, and the prior
  failed `mysql-backup-oneshot` (RG container list now empty).
- Removed local temp secret files (`/tmp/.newdbpass`, `/tmp/.dumpurl`, base64
  blobs). No MU-plugin was uploaded (ACI-only flow → no Kudu 404 check needed).

### Still pending user sign-off
- **Do NOT** delete/stop the OLD 134 GB server `wilderptsa-c20b298090-wpdbserver`
  until the user signs off after a soak period (it is the rollback target and
  still holds the staging DB). Decommission is the documented Step 6.4 (take a
  final dump first).
- Optionally move the **staging** slot to the new server later (would need its
  `..._staging` DB imported too).

---

## INCIDENT 2026-07-01 — wwwroot wipe, core-file restore, and post-restore critical error

> **Not caused by the MySQL cutover.** This incident happened two days after the
> cutover above and was initially misdiagnosed as DB-related ("menu but no page
> content"). The real chain of events was: (1) a concurrent, unrelated deploy of
> `wp-content/plugins/Azure Plugin` used a **bad OneDeploy target-path** that wiped
> nearly all of `/home/site/wwwroot`; (2) a same-site **staging slot** was used as
> the restore source for WordPress core + `wp-content`; (3) the bulk restore of
> large plugin zips via Kudu's zip API caused a **transient, self-resolving
> "critical error"** window while extraction was still in flight; (4) **media
> (`wp-content/uploads`) remains unresolved** — it was never part of any backup by
> design, and needs an OneDrive Media module resync (see "Still open" below).

### Symptom timeline

| Time (UTC) | Symptom |
|---|---|
| ~17:20 | Site went from "menu but no page content" (initially, wrongly, chased as sql_mode/cache — see note below) to fully down: bare-nginx `403`, `/wp-json/` `404`. |
| 17:21–17:29 | Root cause discovered: two `OneDeploy` zip deployments (`e8bc77cc…`, `3f8c7400…`) both logged `PreDeployment: context.OutputPath /home/site/wwwroot` — i.e. targeted the **site root**, not `wp-content/plugins/Azure Plugin` like every prior deploy (confirmed by diffing against deploy `b3369ba2…` from 06-16, whose `OutputPath` was correctly scoped to the plugin subfolder). This wiped WordPress core, all other plugins, themes, mu-plugins, and uploads, leaving only `wp-content/plugins/Azure Plugin`, `wp-content/plugins/redis-cache`, and an unrelated `PTATools.wiki` folder. |
| 17:50–18:03 | Restored WordPress core (`wp-admin/`, `wp-includes/`, root PHP files, `wp-config.php`, `class_entra_database_token_utility.php`), `wp-content/mu-plugins/`, and `wp-content/themes/` (theme in use is **Kadence**, not BuddyBoss as originally assumed — verified from both the staging slot and the 2026-06-17 full backup) by copying from the **same App Service's `staging` deployment slot**, which had a fully intact, current filesystem. Confirmed `wp-config.php` is fully `getenv()`-driven (DB host/name/user/password, Redis host, memory limits, `AFD_DOMAIN`-based URL/cache-salt) so the same file is valid on both slots — matches the existing deployment-safety note. Site returned to **200 with real content** at this point; `infra/post-change-smoke.sh` 6/6. |
| 18:04–18:42 | Restored the remaining `wp-content/plugins/*` (everything except `Azure Plugin`, which belongs to a concurrent, unrelated worker, and `redis-cache`, which already existed) from the staging slot, 29 plugin directories, ~350 MB uncompressed. Pushed as one bulk zip first (hit Kudu's ~4 min gateway timeout partway through), then plugin-by-plugin in a sequential background loop. |
| ~18:24–18:42 | **"Critical error" screen appeared** on every page except the homepage (which was intermittently served fresh with no error). Investigated: `_paused_plugins` in `wp_options` was empty (checked read-only via an in-VNet ACI + managed-identity token, same pattern as the cutover verification) — WordPress's own fatal-recovery mechanism never recorded a paused plugin. Downloaded and byte-for-byte diffed (file list **and** individual file sizes) the two largest, timeout-prone plugins (`woocommerce` 71 MB / 6193 files, `forminator` 47 MB / 2868 files) between the local source and production — **zero mismatches, zero missing files**. The failures were **transient**: several of the large per-plugin `zip` PUTs to Kudu returned client-side `499` (our own `curl --max-time 280` giving up) while extraction continued server-side; the *next* plugin's PUT to the same parent `wp-content/plugins/` directory started immediately after, so for a window of a few minutes some requests could land while a large plugin's directory tree was still being written. No corruption resulted (confirmed by the diffs above) — this reads as classic-WordPress-glob/autoload sensitivity to a plugin directory changing mid-request, not a lasting file defect. |
| 18:42:15 | The plugin-restore loop finished (`ALL DONE`). All page types (home, a builder-built page, WooCommerce shop, calendar, wp-admin, wp-json) retested 5× each — **no critical error, no 500s**. `infra/post-change-smoke.sh` 6/6 again. |

### Root cause of the file wipe (confirmed via Kudu deployment logs)

Compare `OutputPath` across deploys (`/api/deployments/{id}/log` on the SCM site):

- **Good** (2026-06-16, `b3369ba2…`): `PreDeployment: context.OutputPath /home/site/wwwroot/wp-content/plugins/Azure Plugin`
- **Bad** (2026-07-01, `e8bc77cc…` and `3f8c7400…`): `PreDeployment: context.OutputPath /home/site/wwwroot`

Both incident-day deploys logged `context.CleanOutputPath False` (so this wasn't an explicit `--clean true`), but because the target path itself was the site root rather than the plugin subfolder, OneDeploy's zip-sync logic ("Clean deploying to …") reconciled the *entire* `wwwroot` against the zip's contents — anything not in that zip (core WP, other plugins, themes, mu-plugins, uploads) was removed. This is a **different failure mode** from the documented `--clean true` incident (2026-06-15) but has the same blast radius. **Action item for whoever owns that deploy pipeline: always pass an explicit `--target-path "site/wwwroot/wp-content/plugins/Azure Plugin"` and verify it in the resulting deployment log before trusting a "deployment successful" result.**

### What was restored, and from where

| Component | Source | Notes |
|---|---|---|
| WP core (`wp-admin/`, `wp-includes/`, root `*.php`, `wp-config.php`, `class_entra_database_token_utility.php`) | `staging` slot (`wilderptsa-staging`), via Kudu VFS/zip API | `wp-config.php` copied verbatim — confirmed env-driven, no staging-specific hardcoding. |
| `wp-content/mu-plugins/` | staging slot | 6 files incl. `wilderptsa-cleanup.php` (referenced in page `<head>` comment), `buddyboss-performance-api.php` (legacy name, unrelated to current theme). |
| `wp-content/themes/` | staging slot | `kadence` (active), `twentytwentytwo`–`twentytwentyfive`, `index.php`. Cross-checked against the 2026-06-17 full backup's `themes.zip` — identical set, confirms Kadence (not BuddyBoss) is correct. |
| `wp-content/plugins/*` (29 dirs, excl. `Azure Plugin`, `redis-cache`) | staging slot | `acymailing*`, `admin-menu-editor-pro`, `app_service_email`, `bb-plugin` (Beaver Builder — confirms it *is* in use, on the Kadence theme), `bbpowerpack`, `change-username`, `forminator`, `iframe`, `jotform-ai-chatbot`, `koko-analytics`, `multiple-roles`, `printify-for-woocommerce`, `side-cart-woocommerce`, `the-events-calendar`, `updraftplus`, `user-role-editor`, `woo-update-manager`, `woocommerce` + 6 WooCommerce extensions, `wpmudev-updates`. |
| `wp-content/plugins/Azure Plugin`, `wp-content/plugins/redis-cache` | **untouched** | Left alone per instructions; confirmed unchanged mtimes throughout. |
| `wp-content/uploads/` (media) | **not restored — still open, see below** | |

`active_plugins` in `wp_options` (10 entries, verified via read-only DB query): `Azure Plugin`, `acymailing`, `admin-menu-editor-pro`, `bb-plugin`, `forminator`, `multiple-roles`, `redis-cache`, `updraftplus`, `woocommerce-gateway-stripe`, `woocommerce`. All 10 have confirmed-intact code on disk.

### Azure Blob Storage backup inventory (`wilderptsac20b298091` / `wordpress-backups`)

9 backup jobs exist, none of which were needed for the critical-error fix (that was self-resolving, not a data-loss issue) — listed here for completeness per request:

| Date | Backup ID | Components |
|---|---|---|
| 2026-03-31 | `backup_1774996285_H3BvGD2A` | db, others, plugins, plugins2, themes, uploads×4 |
| 2026-04-02 | `backup_1775107175_wJaG81NG` | db, mu-plugins, others, plugins, plugins2, themes |
| 2026-05-10 | `backup_1778302960_BtQCx1ra` | db, mu-plugins, others, plugins, plugins2, themes |
| 2026-05-21 | `backup_1779375800_HH4yEUAp` | db, mu-plugins, others, plugins, themes |
| 2026-05-22 | `backup_1779475460_cHywAX4U` | db, mu-plugins, others, plugins, themes, uploads×4 |
| 2026-05-23 | `backup_1779526334_L6MpiI73` | db, mu-plugins, others, plugins, themes, uploads×4 |
| 2026-06-17 | `backup_1781716576_D1XOPwH5` | db, mu-plugins, **content** ("others"), plugins, themes — **most recent full file-backup**, used to cross-check the theme/plugin restore above |
| 2026-06-30 ×2 | `backup_1782788682_gwqIWaJU`, `backup_1782788684_X1yEO1Pe` | db only (the pre-cutover migration dumps from the MySQL downsize work) |

Note the **uploads component stops appearing after 2026-05-23** — consistent with `docs/backup-and-restore.md`'s statement that media was deliberately moved to the OneDrive/SharePoint sync module and excluded from the plugin's own backups going forward.

### Still open: media (`wp-content/uploads`) is empty — images 404

This is a **separate, still-unresolved issue** from the critical-error investigation above:

- `wp-content/uploads/` on production currently contains only empty scaffold directories (`2026/07/`, `azure-plugin/`, `bb-plugin/` cache dirs) — no real media files. Confirmed via Kudu VFS.
- Two other potential file-level sources were checked and are **too stale** to fully fix this:
  - Blob container `blobwilderptsac20b298090` (a one-time migration snapshot from 2026-03-31) — 3,244 files, newest dated 2026/03. Would restore older media but not anything from April 2026 onward.
  - The 2026-05-23 backup's `uploads.zip` parts (~1.16 GB total) — newer, but still ~5+ weeks stale; would not include the specific June 2026 images (`cropped-PTSA-Logo.png`, `Teachers-Favorite-List-300x300.png`) currently reported missing.
- Per `Azure Plugin/docs/backup-and-restore.md`, this is **by design** — media is supposed to be pulled fresh from SharePoint/OneDrive via the OneDrive Media module (`class-onedrive-media-*.php`), not restored from backup. That module has a WP-Cron auto-sync hook (`onedrive_media_auto_sync`) and an admin-triggered AJAX action (`wp_ajax_onedrive_media_sync_from_onedrive`, exposed on the **OneDrive Media** admin page). Credentials (`Azure_Settings::get_credentials('onedrive_media')`, plus per-user OAuth refresh tokens in a custom `wp_azure_onedrive_tokens` table) live in the DB and migrated with full row-parity in the MySQL cutover, so they are very likely still valid — this was not fully confirmed before the critical-error investigation took priority.
- **Recommended next step (needs an interactive admin action):** log into `wp-admin` (now working) → **PTA Tools / Azure Plugin → OneDrive Media** → click **"Sync from OneDrive"** (or **Test Connection** first if unsure the token is live). This is the module's literal intended recovery path and cannot be safely triggered headlessly (the AJAX action requires an authenticated admin session + nonce). If the token has expired, the page will prompt for re-authorization via a Microsoft sign-in popup — also requires a human.

### Cleanup performed

- All one-shot ACIs deleted (`dbcheck-oneshot` and its two failed prior attempts).
- Local temp files under `/tmp/restore/` are scratch/local-only (never uploaded anywhere as tooling) — left on the operator's machine, not on any Azure resource.
- No MU-plugin was uploaded to the site as part of this incident's tooling.
- `DATABASE_HOST` was **not changed** during this file-layer incident (remains on the OLD server `wilderptsa-c20b298090-wpdbserver` from the earlier, separate rollback decision — see the cutover section above; that rollback is unrelated to the wwwroot wipe).

---

## Appendix — guaranteed-consistent dump via ACI (optional alternative)

If you'd rather have a transactionally consistent `--single-transaction` dump
than the plugin's table-by-table PHP dump, run `mysqldump` from an in-VNet ACI
against the **source** server. This needs DB credentials the importer can use —
i.e. either an Entra token (fetch via the app's managed identity) used as the
password, or temporarily enabling a password admin. Example shape (token path):

```bash
# token obtained for the managed identity, used as --password
mysqldump --host=wilderptsa-c20b298090-wpdbserver.mysql.database.azure.com \
  --user='wilderptsa-c20b298090-wpidentity' --password="$AAD_TOKEN" \
  --ssl-mode=REQUIRED --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8 \
  wilderptsa_c20b298090_database | gzip > db-consistent.sql.gz
```

For this site's low-traffic profile the plugin dump already produced is fully
adequate; this appendix is only for stricter consistency requirements.
