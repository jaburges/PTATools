# Backup verification — 2026-07-13 (pre-park)

Both Azure MySQL Flexible Servers were exported and **verified locally** before any delete.

## Artifacts

| Label | Server | Blob path | Local path | SHA256 |
|-------|--------|-----------|------------|--------|
| OLD (live) | `wilderptsa-c20b298090-wpdbserver` | `wordpress-backups/wilder-ptsa/2026/07/13/backup_1783965029_gZqQTwxm/…-db.sql.gz` | `.local-backups/2026-07-13/OLD-…sql.gz` | `83ce341382867629bba98cd91e0def7b506a23496bebcfe88e3dda3759e7f694` |
| SMALL | `wilderptsa-wpdb-small` | `wordpress-backups/wilder-ptsa/2026/07/13/SMALL-wpdb-small-mysqldump.sql.gz` | `.local-backups/2026-07-13/SMALL-….sql.gz` | `1e570c821c82695a549ccdb378d774d495721557b8caa930d548a0cb385c513d` |

Storage account: `wilderptsac20b298091`.

## Verification (local gzip + SQL scan)

| Check | OLD | SMALL |
|-------|-----|-------|
| `gzip -t` | OK | OK |
| `CREATE TABLE` count | **168** | **168** |
| Critical tables | posts/postmeta/users/options/wc_orders/order_items present | same |
| Pages (`post_type=page`) | **177** | **177** |
| Legacy orders (`shop_order`) | **1024** | **1024** |
| HPOS orders (`wp_wc_orders`) | **1228** | **1228** |
| Products | **97** | **97** |
| Users | ~749 insert rows | probe **756** |

Canonical for school-year restore: **OLD / live** (prod still uses that host).

## Not in these dumps

- Media files on disk (`wp-content/uploads`) — use May 2026 `uploads*.zip` blobs + OneDrive
- Full wwwroot code tree — clone into `jaburges/wilderwebsite` via Kudu before tearing down App Service

## Public placeholder

- SWA Free: `wilderptsa-summer-placeholder` → https://polite-plant-0f2630a1e.7.azurestaticapps.net  
- Copy: “Website offline whilst we build new exciting things.”
- DNS cutover to SWA still pending

## ACS (email)

Azure Communication Services — used to send mail from the site. Idle with 0 email is **~$0** (June showed ~$0.02). **Not deleting for cost**; can delete later for cleanup once email is retired.
