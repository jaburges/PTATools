# wilderptsa.net Dev/Prod Workflow Runbook

> Last updated: 2026-05-09. Owner: site admins.

This is the operating manual for shipping plugin code to wilderptsa.net and for running infrastructure changes safely.

## Architecture overview

```
                                +-------------------------+
                                |  github.com/jaburges/   |
                                |       PTATools          |
                                +-----------+-------------+
                                            |
                       +--------------------+---------------------+
                       |                                          |
                  push to dev                              merge to main
                       |                                          |
                       v                                          v
              +-----------------+                      +---------------------+
              | deploy-staging  |                      |   promote-prod      |
              |   (auto run)    |                      | (manual approval)   |
              +--------+--------+                      +----------+----------+
                       |                                          |
                       v                                          v
              +-----------------+                      +---------------------+
              | App Service:    |   <----- swap -----> | App Service:        |
              | staging slot    |                      | production slot     |
              | wilderptsa-     |                      | wilderptsa.net      |
              | staging.azure*  |                      |                     |
              +-----------------+                      +---------------------+
                       |                                          |
                       |                                          |
              +--------v--------+                      +----------v----------+
              | wp-content/     |                      | wp-content/         |
              | plugins/        |   <-- swapped -->    | plugins/            |
              | (the only thing |                      | (everything else    |
              | that swaps)     |                      | stays put)          |
              +-----------------+                      +---------------------+

DB pointers, Front Door endpoints, blob containers are slot-sticky and DO NOT swap.
Each slot keeps its own DATABASE_NAME, DATABASE_HOST, BLOB_CONTAINER_NAME, AFD_DOMAIN, AFD_ENDPOINT.
```

## Daily workflow: shipping a plugin change

```bash
# 1. Switch to dev and pull latest
git checkout dev
git pull

# 2. Make changes in Azure Plugin/...

# 3. Commit and push
git add -A
git commit -m "feat: ..."
git push

# 4. GitHub Actions auto-deploys to staging slot
#    Watch progress: https://github.com/jaburges/PTATools/actions
#    Or:  gh run watch
```

After the deploy job completes, test on the staging URL:
- **Staging URL**: https://wilderptsa-staging-c20b298090-drccadb2badebhh5.z02.azurefd.net/
- Direct App Service URL (bypasses Front Door): https://wilderptsa-staging.azurewebsites.net/

When happy with staging, promote to production:

```bash
# Open a PR from dev -> main
gh pr create --base main --head dev --title "..." --body "..."

# Approve and merge (or use the GitHub UI)
gh pr merge --squash

# This triggers promote-prod.yml. The workflow pauses at the production environment
# approval gate. Approve via the GitHub UI:
#   https://github.com/jaburges/PTATools/actions
#   -> Click the running "Promote to production" workflow
#   -> Click "Review deployments" -> Approve
#
# After approval:
#   - Slot swap runs (staging -> production)
#   - Smoke test runs against wilderptsa.net
#   - If smoke fails: AUTO-ROLLBACK (slot swap reverses)
#   - If smoke passes: production is updated
```

## Rollback procedures

### Auto-rollback (preferred)
The `promote-prod.yml` workflow automatically rolls back if the post-swap smoke test against wilderptsa.net fails. You'll see a `Rollback complete` message in the workflow run summary. No action required from you.

### Manual rollback (if you spot a problem after the workflow already passed)
A slot swap is reversible — just swap again:
```bash
az webapp deployment slot swap \
  --resource-group PTSAWebsite \
  --name wilderptsa \
  --slot production \
  --target-slot staging
```
This puts the previous version back on prod (it's now in staging slot, since the original swap moved old prod there).

### Revert at the code level
If the bad code came from a merge to `main` and you also want to revert the source:
```bash
git checkout main
git revert HEAD
git push
# Then manually trigger promote-prod.yml again, or wait for next push
```

## When NOT to promote (red flags)

Hold the promotion (don't merge dev → main) if any of these are true:

- **The plugin change activates a new module that mutates the database on activation.** Run a manual `Run Now` backup via the Backup admin page first. If you proceed and it fails, restore from that backup.
- **A schema migration in the plugin code that isn't backwards-compatible.** Both staging and prod use different DBs but the same MySQL server. A migration on prod would not have run on staging's DB. Coordinate with a maintenance window.
- **Multisite or domain config changes.** These affect more than just the plugin code; they require coordination outside this workflow.
- **The smoke test on staging is failing for reasons other than the pre-existing WP install issue.** Investigate before promoting.

## Capacity scaling reference (zero-downtime ops)

These are run from your terminal with `az`. None require a maintenance window.

### Before a big event (membership drive, auction, registration)
```bash
# Scale plan from 1 to 3 instances. Zero downtime.
az appservice plan update \
  -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --number-of-workers 3
```

### After the event
```bash
az appservice plan update \
  -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --number-of-workers 1
```

### App Service Plan SKU change (e.g. P1v3 -> P0v3) without downtime
The trick is to keep at least 2 instances during the change so Microsoft does a rolling upgrade.
```bash
# 1. Ensure 2 instances first (if currently 1)
az appservice plan update \
  -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --number-of-workers 2

# 2. Change SKU
az appservice plan update \
  -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --sku P0V3

# 3. Verify health
./infra/post-change-smoke.sh

# 4. Scale back to 1 if desired
az appservice plan update \
  -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --number-of-workers 1
```

### Redis SKU change (no downtime, briefly slower)
WordPress falls through to MySQL on cache miss while Redis is changing tier. Site stays up.
```bash
az redis update \
  -g PTSAWebsite -n wilderptsa-redis \
  --sku Basic --vm-size C1

# Wait ~5-20 min for the cache to warm up.
./infra/post-change-smoke.sh
```

### MySQL storage scale-up (no downtime)
```bash
az mysql flexible-server update \
  -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --storage-size 50  # GB
```

## MySQL maintenance window procedure

This is the only change with real downtime (~5-15 min). Schedule it for 11pm PT or similar.

### 1. Pre-flight backup
Use the WordPress admin Backup module ("Run Now"), or via Kudu SSH:
```bash
# SSH into the App Service (Azure Portal -> App Service -> SSH)
wp cron event run azure_backup_scheduled
# Wait for it to complete. Verify the blob exists in
# wilderptsac20b298091 / blobwilderptsac20b298090.
```

### 2. Engage maintenance mode
WordPress core's built-in `.maintenance` file makes WP return 503 to all users.
```bash
# In Kudu SSH:
echo "<?php \$upgrading = time(); ?>" > /home/site/wwwroot/.maintenance
```
Verify by curling the site: should return 503 with "Briefly unavailable for scheduled maintenance".

### 3. Run the SKU change
```bash
az mysql flexible-server update \
  -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --tier Burstable --sku-name Standard_B2s
```

### 4. Wait for the server to be Ready
```bash
az mysql flexible-server show \
  -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --query state -o tsv
# Wait until output is "Ready" (poll every minute or two).
```

### 5. Smoke test
```bash
./infra/post-change-smoke.sh
```
The smoke test will hit the site, but the .maintenance file will still cause 503s. That's expected. The point is to ensure the App Service can reach the DB. Better: test inside Kudu SSH:
```bash
wp db query "SELECT 1"
# Should return 1.
```

### 6. Disengage maintenance mode
```bash
# In Kudu SSH:
rm /home/site/wwwroot/.maintenance
```

### 7. Final smoke test from outside
```bash
./infra/post-change-smoke.sh
# Should report all 6 checks PASSED.
```

If anything goes wrong: revert MySQL SKU back to the original (`Standard_D2ds_v4 GeneralPurpose`) and remove the `.maintenance` file.

## Health monitoring

### Continuous availability test
App Insights pings `https://wilderptsa.net/` every 5 minutes from 5 US regions. View results:
- Azure Portal → Resource Group `PTSAWebsite` → `wilderptsa-appinsights` → **Availability**
- Or query Log Analytics with KQL:
  ```kusto
  availabilityResults
  | where timestamp > ago(24h)
  | summarize SuccessRate = avgif(1.0, success == true) * 100 by bin(timestamp, 1h)
  | order by timestamp desc
  ```

### Setting up an alert (recommended, ~3 min one-time setup)
In Azure Portal: App Insights → Availability → click on the test → "Edit Alert" → set threshold to "≥ 2 of 5 locations failed in 5 min" → action group → email `admin@wilderptsa.net`. Free.

### Manual smoke test (after any change, run from your terminal)
```bash
./infra/post-change-smoke.sh                  # tests prod
TARGET=staging ./infra/post-change-smoke.sh   # tests staging via Front Door
TARGET=staging-direct ./infra/post-change-smoke.sh  # tests staging via direct hostname
```

## Useful commands cheat sheet

```bash
# View Azure resources at a glance
az resource list -g PTSAWebsite --query "[].{name:name, type:type, sku:sku.name}" -o table

# View slot states
az webapp deployment slot list -g PTSAWebsite --name wilderptsa \
  --query "[].{name:name, state:state}" -o table

# Verify slot-sticky settings (should list 5 names)
az webapp config appsettings list -g PTSAWebsite --name wilderptsa \
  --query "[?slotSetting].name" -o tsv

# Tail App Service logs in real time
az webapp log tail -g PTSAWebsite --name wilderptsa
az webapp log tail -g PTSAWebsite --name wilderptsa --slot staging

# Check what's in the staging slot's wp-content
# (via Kudu SSH at https://wilderptsa-staging.scm.azurewebsites.net)
ls -la /home/site/wwwroot/wp-content/plugins/azure-plugin/
```

## Reference: identifiers

| What | Value |
|---|---|
| Azure subscription | `97f6936d-7300-4a49-a2ad-cbfee3b28e00` (Microsoft Grant) |
| Azure tenant | `a220d676-fd60-4d01-8742-d18944f51a66` (Laura Ingalls Wilder PTSA) |
| Resource group | `PTSAWebsite` (westus2) |
| App Service | `wilderptsa` (slots: production, staging) |
| App Service Plan | `ASP-PTSAWebsite-a9e9` (currently P1v3 × 2 — see audit doc for rightsizing recommendations) |
| MySQL | `wilderptsa-c20b298090-wpdbserver` (databases: `wilderptsa_c20b298090_database`, `..._database_staging`) |
| Redis | `wilderptsa-redis` |
| Front Door profile | `WilderPTSAAFD` |
| GitHub Actions service principal | `github-actions-wilderptsa` (`fee99c11-6604-4efa-9aa8-40f6525a043c`) |
| GitHub repo | `https://github.com/jaburges/PTATools` |

## Known issues / follow-ups

- **Staging WooCommerce-family plugins are deactivated.** Staging's `wp-content/plugins/woocommerce/` is at version 10.6.2 while prod is on 10.7.0. WC 10.6.2 has a class load-order bug in its email-editor package (`Class "WP_Block_Templates_Registry" not found`) that fatals on every request once the plugin is active. To unblock staging, the following plugins were removed from the staging DB's `active_plugins`: `woocommerce`, `woocommerce-services`, `woocommerce-gateway-stripe`, `woocommerce-payments`, `woocommerce-paypal-payments`, `woocommerce-store-credit`, `woocommerce-order-export`, `woocommerce-advanced-packages`, `facebook-for-woocommerce`, `side-cart-woocommerce`, `woo-update-manager`, `advanced-product-fields-for-woocommerce`. The plugin **files** are still on disk and on prod they're active; only the staging DB's activation state was changed. To fully sync staging, copy `wp-content/plugins/` from prod to staging via Kudu (or a future `infra/sync-prod-plugins-to-staging.sh` script), then re-activate the plugins in the staging DB.
- **Staging is slow** (~4-7s per page load vs ~1-2s on prod). This is because Redis object cache is being repopulated on every request after the W3TC config rebuild, and the slot has fewer warmed PHP-FPM workers. Acceptable for code-testing purposes; tune later if needed.
- **DB clone is one-shot.** Staging DB was cloned from prod once on 2026-05-09 to bootstrap real content. There's no automated refresh. To re-sync: build the deferred `infra/sync-prod-to-dev.sh` (was Phase 1.2 in the original plan, deferred). Until then, content drift is expected.
- **Capacity scaling automation** is not built; current operator runbook is the only path. If event scaling becomes burdensome, build a small "schedule a future scale-out" feature.
