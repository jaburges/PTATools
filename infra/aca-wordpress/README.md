# WordPress on Azure Container Apps

> **Much of this document describes the original `wordpress-dev` experiment and
> is now out of date.** The live container site is **`wilderptsa-wp`**, defined by
> `containerapp-prod.yaml`. It runs a purpose-built image with the theme and
> plugins baked in and only `uploads` mounted from Azure Files.
>
> For anything about deploying code, read
> [`infra/wp-image/README.md`](../wp-image/README.md) instead. In particular,
> copying files to the `wp-content` share does **not** update `wilderptsa-wp`.
>
> Sections below that still apply to the live site: how it reaches MySQL over the
> VNet, the TLS requirement, and the `siteurl` handling. Sections about a blank
> site, an ephemeral `/var/www/html`, ingress IP allow-listing, and choosing
> between a custom image and an Azure Files volume are historical — that choice
> was made (custom image) on 2026-08-03, for the reasons in the image README.

A non-production WordPress instance running on Azure Container Apps against a
copy of the production database. Built to answer two questions: can WordPress
run on Container Apps against the existing MySQL server, and what would it
cost.

## What exists

| Resource | Name | Notes |
| --- | --- | --- |
| Container Apps environment | `wilderptsa-aca-env` | Workload profiles (v2), VNet-injected, Consumption profile only |
| Container app | `wordpress-dev` | Stock `wordpress:6.9.4-php8.3-apache`, 0.5 vCPU / 1 GiB, fixed at 1 replica |
| Subnet | `aca-subnet` — `10.0.1.128/27` | In `wilderptsa-c20b298090-vnet`, delegated to `Microsoft.App/environments` |
| Key Vault | `kv-wilderptsa-cad4c2` | RBAC-authorised, holds `mysql-small-ptsadbadmin-password` |
| Managed identity | `id-wilderptsa-aca` | User-assigned; has `Key Vault Secrets User` so the app resolves the DB password at deploy time |
| Database | `wilderptsa-wpdb-small` / `wilderptsa_c20b298090_database` | Pre-existing copy of production |

Logs go to the existing `wilderptsa-laws` workspace; no new workspace was
created.

Public hostname: `wordpress-dev.wittysky-40aa8bc1.westus2.azurecontainerapps.io`
Environment static outbound IP: `20.252.77.71`

## Deploying a change

The app is defined entirely by `containerapp.yaml`. Edit it and re-apply:

```bash
az containerapp update -g PTSAWebsite -n wordpress-dev \
  --yaml infra/aca-wordpress/containerapp.yaml
```

## Three things that were needed to make it connect

These were not obvious and are the reason the YAML looks the way it does.

**The database is only resolvable inside the VNet.** Both MySQL servers have
public access disabled, and `wilderptsa-wpdb-small.mysql.database.azure.com` is
a CNAME into the private zone
`wilderptsa-c20b298090-privatelink.mysql.database.azure.com`, which has no
public A record. The container app therefore has to be VNet-injected — a
Consumption-only (v1) environment would need a `/23`, and only a `/27` was
free, which is why this is a workload-profiles environment.

**MySQL requires TLS.** `require_secure_transport` is `ON`, and the stock image
does not enable TLS for `mysqli`. Hence `MYSQL_CLIENT_FLAGS` /
`MYSQLI_CLIENT_SSL` in `WORDPRESS_CONFIG_EXTRA`.

**The stored `siteurl` points at production.** Because the database is a copy,
WordPress would redirect every request to wilderptsa.net. `WP_HOME` and
`WP_SITEURL` are derived from `HTTP_HOST` instead. Container Apps also
terminates TLS at ingress, so `X-Forwarded-Proto` has to be mapped onto
`$_SERVER['HTTPS']` or WordPress emits `http://` URLs and redirect-loops.

## Safety constraints — please keep these

The copy database contains production `wp_options`: live Mailgun and ACS
credentials, valid Microsoft Graph refresh tokens, the production storage
account key, and real member email addresses.

- `DISABLE_WP_CRON` is set. Do not remove it. With cron enabled this instance
  could send real newsletters and write to real calendars and the production
  blob container.
- Ingress is allow-listed to a single operator IP. Add IPs to
  `ipSecurityRestrictions` rather than removing the restriction.
- **Do not visit `/wp-admin/upgrade.php`.** The image ships a newer WordPress
  than the database records, so WordPress redirects there and offers to migrate
  the schema. Accepting would rewrite the copy database and destroy its value
  as a restore-verification artifact.

## Known limitation: the filesystem is ephemeral

No volume is mounted, so `/var/www/html` is recreated from the image on every
restart and revision change. Consequences:

- The site renders blank. The database's active theme (Junotoys) and the Azure
  Plugin are not in the stock image, so there is no template to render. HTTP
  200 with `content-length: 0` is the expected result, not a fault.
- Anything installed through the dashboard disappears on restart.

To make this genuinely usable, pick one:

1. **Custom image** — bake the plugin and theme in. Needs a registry
   (Basic ACR, roughly $5/month). Loses in-dashboard updates.
2. **Azure Files volume for `wp-content`** — closest to how the App Service
   instance behaves today, and would let you measure whether SMB latency is
   tolerable for WordPress.

## Cost

Consumption pricing in westus2 at the time of writing: $0.000034 per
vCPU-second active, $0.000004 idle, $0.000004 per GiB-second, less a monthly
free grant of 180,000 vCPU-seconds and 360,000 GiB-seconds. A
workload-profiles environment using only the Consumption profile carries no
management fee; the Dedicated fee applies to private endpoints and planned
maintenance, and VNet injection is neither.

At 0.5 vCPU / 1 GiB pinned to one replica, a 30-day month costs about **$13**
if nearly all seconds bill at the idle rate and about **$47** in the worst
case where everything bills as active. Key Vault and the managed identity are
effectively free; Log Analytics ingestion adds a little. Budget roughly
**$15–22/month** for this environment as configured.

For comparison, the production App Service P1v3 plan is $110.67/month — but it
hosts production *and* the staging slot at that price, so a like-for-like
Container Apps replacement would need two apps and roughly double the
consumption. See the cost analysis in the linked chat for why migrating is not
an obvious saving.

## Teardown

```bash
az containerapp delete -g PTSAWebsite -n wordpress-dev --yes
az containerapp env delete -g PTSAWebsite -n wilderptsa-aca-env --yes
az network vnet subnet delete -g PTSAWebsite \
  --vnet-name wilderptsa-c20b298090-vnet -n aca-subnet
az identity delete -g PTSAWebsite -n id-wilderptsa-aca
# The vault has 7-day soft-delete retention.
az keyvault delete -g PTSAWebsite -n kv-wilderptsa-cad4c2
```

Deleting `wilderptsa-wpdb-small` as well would save roughly $35/month — more
than this environment costs to run.
