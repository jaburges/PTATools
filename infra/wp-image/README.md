# The wilderptsa WordPress container image

The Container Apps site (`wilderptsa-wp`) runs a purpose-built image rather than
the stock WordPress one. Theme, plugins and mu-plugins are baked in; only
`uploads` is mounted from Azure Files.

**A code change means building a new image.** Copying files to the `wp-content`
file share no longer affects the running site at all — see
[Why file-copy deploys are a no-op](#why-file-copy-deploys-are-a-no-op).

## Deploying a code change

```bash
# Tags the image from the plugin version in Azure Plugin/azure-plugin.php.
./infra/wp-image/build.sh

# Point the app at the new tag. This creates a revision and shifts all traffic.
az containerapp update -g PTSAWebsite -n wilderptsa-wp \
  --image wilderptsaacr.azurecr.io/wilderptsa-wp:<tag>
```

`build.sh --local` builds without pushing, which is the quickest way to inspect
what would ship:

```bash
docker run --rm --platform linux/amd64 --entrypoint bash \
  wilderptsaacr.azurecr.io/wilderptsa-wp:latest -c \
  'grep -m1 Version: "/var/www/html/wp-content/plugins/Azure Plugin/azure-plugin.php"'
```

Always verify the revision reached `Healthy` before considering a deploy done:

```bash
az containerapp revision list -g PTSAWebsite -n wilderptsa-wp \
  --query "[-1].{name:name,health:properties.healthState,run:properties.runningState}" -o tsv
```

To roll back, reactivate the previous revision — the old image tag is still in
the registry, and a snapshot of the last known-good app definition is kept under
`.tmp/rollback/`.

## WordPress core updates do not work from wp-admin

Core lives in the container's own filesystem layer. The base image keeps
WordPress at `/usr/src/wordpress` and the entrypoint copies it into
`/var/www/html` at every container start, so an update applied from wp-admin is
overwritten on the next restart or revision.

This is not theoretical: 7.0.2 was applied from wp-admin twice in early August
2026 and both times the site came back on the image's version.

**To change the WordPress version, change the `FROM` tag** in the Dockerfile and
rebuild:

```dockerfile
FROM wordpress:7.0.2-php8.3-apache
```

Then deploy, and complete the database half of the upgrade, which does *not*
happen by itself on the front end:

```bash
curl -sS "https://<host>/wp-admin/upgrade.php?step=upgrade_db"
```

Verify both halves agree afterwards — the version WordPress reports and the
`db_version` option:

```bash
curl -sS https://<host>/ | grep -o 'content="WordPress [0-9.]*'
# and via infra/aca-wordpress/job-db-audit-versions.yaml:
#   db_version should match the release (7.0.2 = 61833, 6.9.4 = 60717)
```

The order matters. Run the database upgrade *after* the new code is live: doing it
first would migrate the schema ahead of the files.

A mismatch is the one genuinely bad outcome here. wp-admin compares
`db_version` against the code for *inequality*, not "older than", so a database
left ahead of the files sends every admin page to `upgrade.php`. That is what
would have happened if one of those wp-admin updates had completed its schema
migration before the container recycled.

The `000-container-core-pin.php` mu-plugin therefore hides core update offers and
explains why, rather than leaving a button that cannot work.

## Why this exists: the site was eight seconds slower without it

Measured on 2026-08-03, before the change. Every PHP request took roughly the
same time regardless of how much work it did, which is the signature of a
bootstrap cost rather than a rendering or query cost:

| Request | Before | After |
| --- | --- | --- |
| Homepage (202 KB) | 8.5–9.3 s | 0.87–1.02 s |
| `wp-login.php` (9 KB) | 7.8 s | 0.30 s |
| `/shop/` | 8.4 s | 0.50 s |
| A 404 | 8.6 s | 0.57 s |
| Static PNG | 43 ms | 43 ms |

The cause was hosting *code* on the SMB mount. Per-file latency there is far
worse than on the container's own layer:

| Operation | Container layer | Azure Files | Ratio |
| --- | --- | --- | --- |
| `stat` | 0.004 ms | 4.76 ms | ~1,200× |
| Read | 0.46 ms | 33.2 ms | ~70× |

A warm homepage request loaded 1,379 PHP files, **955 of them from the share**.
OPcache was hitting 98.9%, so it was not recompiling — but
`opcache.validate_timestamps` was on, and the mount is set `actimeo=1`, so the
attribute cache had always expired by the next request. That meant ~955 stats
over SMB on virtually every hit: 955 × 4.76 ms ≈ **4.5 seconds of pure
"has this file changed?"**, with the remainder going to reads on cache misses.

Static assets were never affected, because one large sequential read is cheap;
it is thousands of small metadata round trips that are not.

For context, the App Service instance this replaces serves its homepage in
1.0–1.4 s, so the container site was ~7× slower than the platform it was meant
to improve on. It is now faster than both.

## Why file-copy deploys are a no-op

Two independent reasons, either of which is sufficient:

1. **The code is not there any more.** `wp-content/themes`, `plugins` and
   `mu-plugins` come from the image. Only `wp-content/uploads` is mounted, via
   `subPath: uploads` on the existing `wpcontent` share.
2. **OPcache never re-reads files.** The image sets
   `opcache.validate_timestamps=0`, which is what removes the 4.5 s penalty. A
   changed file on disk would be ignored until the process restarts.

The old code is still sitting on the share, unused. It is deliberately left as a
rollback aid; it is not read by anything.

This is also why `DISALLOW_FILE_EDIT` is set and why installing a plugin from
wp-admin will appear to work and then vanish on the next revision. Plugin and
theme changes go through `build.sh`.

## What is in the image

- `wordpress:6.9.4-php8.3-apache` as the base.
- The theme, plugins and mu-plugins from the migration export, with the
  `Azure Plugin` overlaid from this repository so the released version wins.
- `twentytwentyfive`, kept purely as a fallback so a fatal error in the active
  theme still leaves a route into wp-admin.
- `phpredis` plus the Redis Object Cache drop-in at
  `wp-content/object-cache.php`. The drop-in has to be baked in: anything the
  plugin writes at runtime would not survive a restart.
- `multiple-roles` (WordPress.org) so the user-edit screen can assign more
  than one role. Same reason: a dashboard install does not survive a revision.
- Apache config at `/etc/apache2/conf-enabled/zz-wordpress.conf` carrying the
  permalink rewrite rules, long-lived caching headers for static assets, and a
  deny rule for PHP under `uploads` — that directory is writable at runtime, so
  executing PHP from it would turn a media upload into remote code execution.
- OPcache sized for the real file count: 30,000 accelerated files and 256 MB,
  against the ~5,500 PHP files that ship in `wp-content`.

The permalink rules being in the image is why the app no longer needs a custom
`command`/`args` block to write Apache config at every start.

## Object cache

Redis (`wilderptsa-redis`, Basic C0) was already provisioned and completely idle
before this change. It is now the object cache. Under a 40-request burst it
served 11,090 hits against 487 misses — about a 96% hit rate.

The credential is a Key Vault reference (`redis-primary-key`) resolved by the
`id-wilderptsa-aca` identity, matching how the database password is handled; no
secret is stored in the app definition.

`WP_CACHE_KEY_SALT` is set to `wilderptsa_prod:`. That matters because something
else — most likely the App Service site — was already connected to this cache,
and the salt keeps the two from reading each other's entries.

Note that this is an object cache, not a page cache: it removes repeated database
work but every request still boots WordPress and renders the theme.
