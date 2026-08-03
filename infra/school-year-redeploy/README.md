# School-year redeploy (Bicep)

Provisions a **new** lean WordPress stack in `PTSAWebsite` for fall reopen. Uses a separate name prefix (`wilderptsa-sy` by default) so it does **not** collide with the current live resources while summer SWA is still in front of `wilderptsa.net`.

## What it creates

| Module | Resources |
|--------|-----------|
| `networking` | VNet + app/db/aci subnets + MySQL private DNS zone |
| `mysql` | Flexible Server B1ms (param), private access, DB, Entra admin = UMI |
| `appservice` | Linux plan (default **B1**) + PHP 8.3 web app + optional staging slot |
| `storage` | StorageV2 + `wordpress-backups` / `wordpress-media` + Blob Data Contributor for UMI |
| `monitoring` | Log Analytics (0.5 GB/day cap) + App Insights |
| `redis` | Optional Basic C0 |
| `frontdoor` | Optional new Standard AFD profile/endpoint for the **new** web app |
| `summer-swa` | Optional Free SWA (off by default — summer placeholder already exists) |

WordPress files, DB restore, Redis password app settings, and custom domain binding are **post-deploy** steps (see below).

## Deploy (do not run until school-year cutover)

```bash
# Preview
az deployment group what-if -g PTSAWebsite \
  -f infra/school-year-redeploy/main.bicep \
  -p infra/school-year-redeploy/parameters.school-year.bicepparam \
  -p mysqlAdminPassword='<strong-secret>'

# Apply
az deployment group create -g PTSAWebsite \
  -f infra/school-year-redeploy/main.bicep \
  -p infra/school-year-redeploy/parameters.school-year.bicepparam \
  -p mysqlAdminPassword='<strong-secret>'
```

Override SKU when you need slots / more headroom:

```bash
-p appServiceSkuName=P1v3 deployStagingSlot=true
```

## Summer AFD ↔ SWA switch (existing profile)

Live custom domains stay on `WilderPTSAAFD`. To point the homepage at the Free SWA without deleting WordPress:

```bash
chmod +x infra/school-year-redeploy/scripts/afd-switch-origin.sh
./infra/school-year-redeploy/scripts/afd-switch-origin.sh swa        # summer
./infra/school-year-redeploy/scripts/afd-switch-origin.sh wordpress  # school reopen
```

SWA direct URL (always): https://polite-plant-0f2630a1e.7.azurestaticapps.net/

## Post-deploy checklist

1. Import verified DB dump into the new MySQL (`docs/runbooks/backup-verification-2026-07-13.md`).
2. Create AAD user for UMI inside MySQL and `GRANT` on the WP database (same pattern as `mysql-storage-downsize` runbook).
3. Deploy wwwroot from `jaburges/wilderwebsite` + latest `Azure Plugin` from this repo.
4. Set Redis app settings (`WP_REDIS_*`) if Redis module enabled.
5. Switch AFD origin to the new (or existing) App Service host.
6. Run `infra/post-change-smoke.sh`.

## Safety

- Default prefix `wilderptsa-sy` + VNet `10.1.0.0/23` avoids clobbering live `10.0.0.0/23` resources.
- Never pass `--clean true` to `az webapp deploy` against this site.
- Do not delete live MySQL / App Service until SWA+AFD homepage is confirmed and backups are verified.
