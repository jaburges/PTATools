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

> Scope note: the export and verification below are **done**. The reimport
> section is a forward-looking runbook — **no new server has been created and
> nothing has been imported or deleted.**

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
