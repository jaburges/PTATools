# School-year WordPress stack — Azure deployment plan

## Goal

Bicep to **respin** a full Wilder PTSA WordPress environment in `PTSAWebsite` (West US 2) after summer park, without depending on the current live names if they are deleted.

## Non-goals (this pass)

- Do **not** delete or replace the current live App Service / MySQL / Redis yet.
- Summer public site: Free SWA is live; AFD cutover to SWA is in progress (see `infra/school-year-redeploy/scripts/afd-switch-origin.sh`).

## Delivered artifacts

- `infra/school-year-redeploy/main.bicep` + modules (compiles clean)
- `parameters.school-year.bicepparam`
- `scripts/afd-switch-origin.sh` (REST-based; preserves custom domains)
- `README.md`

## Target resources (parameterized)

| Resource | Default SKU / notes |
|----------|---------------------|
| VNet + subnets | app (Web), db (MySQL), aci |
| Private DNS | `privatelink.mysql.database.azure.com` |
| MySQL Flexible Server | B1ms, 64 GB, private, Entra + password bootstrap admin via secure param |
| User-assigned MI | WordPress DB / storage identity |
| App Service Plan | **B1** Linux default (override to P1v3 when slots needed) |
| Web App + optional staging slot | Linux PHP 8.3 (WordPress content deployed separately) |
| Redis | Basic C0 (optional toggle) |
| Storage | backups + media blob container |
| Front Door Standard | endpoint + origin group for App Service + custom domain hooks |
| Log Analytics + App Insights | 30-day retention |
| Free SWA | optional module for summer placeholder (existing resource can be imported/referenced) |

## Deploy command (after approval / when school year)

```bash
az deployment group create -g PTSAWebsite \
  -f infra/school-year-redeploy/main.bicep \
  -p infra/school-year-redeploy/parameters.school-year.bicepparam
```

## Post-deploy (not in Bicep)

1. Restore MySQL from verified dump (`.local-backups` / blob).
2. Deploy wwwroot from `jaburges/wilderwebsite` + PTA Tools plugin from this repo.
3. Point AFD origin group back from SWA → App Service.
4. Smoke: `infra/post-change-smoke.sh`.
