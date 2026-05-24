# Redis isolation between prod and staging slots

## Why this exists

wilderptsa.net runs two App Service deployment slots (`production` and `staging`) against one shared Azure managed Redis instance (`wilderptsa-redis`). The slots already have separate MySQL databases (slot-sticky `DATABASE_NAME`) and separate blob containers (slot-sticky `BLOB_CONTAINER_NAME`), but the Redis instance is shared.

Without per-slot isolation the two slots write into the same Redis logical database under the same key prefix, so any `update_option()` (or other `wp_cache_set()`) on one slot leaks into the other slot's `get_option()` reads.

We hit this on **2026-05-23** during the WP 7.0 upgrade dry-run: the in-admin upgrade on staging wrote `db_version=61833` to staging's MySQL **and** to shared Redis. Prod, reading `db_version` from Redis, briefly believed its schema was already at 7.0 — even though the prod MySQL row was untouched (it was still 60717). That caused `/wp-admin/` on prod to redirect to `upgrade.php` for every request, and made it look like the prod DB had been corrupted by a staging operation. It hadn't — only Redis was lying.

Full incident timeline lives in the agent transcripts; this runbook documents the permanent fix.

## How isolation works

We use **two** independent isolation mechanisms so a regression in either still leaves the other holding the line:

### 1. Per-slot Redis logical database (`WP_REDIS_DATABASE`)
Azure managed Redis exposes logical DBs 0–15. We set:

| Slot | `WP_REDIS_DATABASE` (env, slot-sticky) | Effective Redis DB |
|------|----------------------------------------|---------------------|
| `production` | not set → defaults to `0` | DB 0 |
| `staging`    | `1`                         | DB 1 |

`wp-config.php` reads the env var and defines the constant the Redis Object Cache plugin checks:

```php
define( 'WP_REDIS_DATABASE', intval( getenv('WP_REDIS_DATABASE') ?: 0 ) );
```

Keys on DB 0 and DB 1 are completely separate within Redis.

### 2. Per-slot key salt (`WP_CACHE_KEY_SALT`)
Even if a plugin or future drop-in bypasses `WP_REDIS_DATABASE`, the key salt differs per slot so reads/writes still don't collide:

```php
define( 'WP_CACHE_KEY_SALT', ( getenv('AFD_DOMAIN') ?: 'wilderptsa.net' ) . ':' );
```

`AFD_DOMAIN` is already slot-sticky and naturally differs between slots:

| Slot | `AFD_DOMAIN` | Resulting salt |
|------|--------------|----------------|
| `production` | `wilderptsa.net` | `wilderptsa.net:` |
| `staging`    | `wilderptsa-staging-c20b298090-drccadb2badebhh5.z02.azurefd.net` | `wilderptsa-staging-...azurefd.net:` |

## Files involved

| File | Purpose |
|------|---------|
| `infra/wp-config-reference.php` | Documented template of the live wp-config.php pattern. Not loaded — copy this verbatim to `/home/site/wwwroot/wp-config.php` on each slot when rebuilding from scratch. |
| `Azure Plugin/includes/class-platform-sync.php` | Adds `get_redis_isolation_status()` for the Danger Zone UI to surface a warning if a slot is sharing Redis with another. |
| `Azure Plugin/admin/logs-page.php` | Renders the Redis isolation status under PTA Tools → System → Critical → Danger Zone. |

## How to re-apply on a fresh install

```bash
# 1) Mark slot-sticky on both slots
az webapp config appsettings set -g PTSAWebsite -n wilderptsa \
  --slot-settings "WP_REDIS_DATABASE=0"
az webapp config appsettings set -g PTSAWebsite -n wilderptsa --slot staging \
  --slot-settings "WP_REDIS_DATABASE=1"

# 2) Patch wp-config.php on each slot — see infra/wp-config-reference.php
#    for the two define() lines that matter:
#      define('WP_CACHE_KEY_SALT', (getenv('AFD_DOMAIN') ?: 'wilderptsa.net') . ':');
#      define('WP_REDIS_DATABASE', intval(getenv('WP_REDIS_DATABASE') ?: 0));

# 3) Restart both slots
az webapp restart -g PTSAWebsite -n wilderptsa
az webapp restart -g PTSAWebsite -n wilderptsa --slot staging
```

## How to verify isolation

The Danger Zone (PTA Tools → System → Critical) shows a live "Redis isolation" status block. Both slots should show **isolated** with the slot-specific salt + DB number.

To verify by hand:

1. On prod admin, run any operation that writes a cached option (or use `wp_cache_set('pta_iso_check', 'prod-only', 'options', 60)` via a one-off mu-plugin probe).
2. On staging admin, read the same key. Should return `false` (not the prod value).
3. Swap roles. Each slot should only see its own writes.

## What to do if isolation breaks

Symptoms:
- Options written on one slot appear in `get_option()` reads on the other.
- The Danger Zone shows **shared** instead of **isolated**.

Recovery:
1. Confirm `WP_REDIS_DATABASE` is slot-sticky and differs between slots via `az webapp config appsettings list ...`.
2. Confirm `AFD_DOMAIN` is slot-sticky and differs.
3. Confirm wp-config.php contains both `define()` calls. If a wp-config.php overwrite (slot swap, deploy, manual edit) reverted it, restore from `infra/wp-config-reference.php`.
4. Restart both slots.
5. Flush WP object cache on each slot (`wp cache flush` or admin → Redis Cache → Flush Cache).
