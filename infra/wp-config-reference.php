<?php
/**
 * wp-config.php REFERENCE for wilderptsa.net (Azure WordPress on App Service Premium).
 *
 * THIS FILE IS NOT LOADED. It is documentation of the live wp-config.php
 * on each deployment slot, in this repo so we can re-apply it after a
 * disaster recover and so any future agent sees the intended pattern.
 *
 * The live file lives at /home/site/wwwroot/wp-config.php on EACH slot
 * (prod and staging) and is NOT swapped on slot swap because it sits
 * inside wwwroot — meaning each slot's wp-config.php travels with its
 * filesystem when you do a slot swap. After a swap, the wp-config.php
 * that USED to be on prod is now on staging and vice versa. App Service
 * env vars (`AFD_DOMAIN`, `DATABASE_NAME`, `WP_REDIS_DATABASE`, ...)
 * are slot-sticky and DO stay with the slot, so the same wp-config.php
 * reads different env values depending on which slot is serving it.
 *
 * The KEY pattern below is the Redis isolation block (Redis section).
 * Without it, both slots share the same Redis instance, same logical
 * database, and same WP_CACHE_KEY_SALT, which causes cache-level
 * cross-contamination: an update_option() on staging immediately leaks
 * into prod's get_option() reads. We hit this on 2026-05-23 when an
 * in-admin WP 7.0 upgrade on staging made prod *think* it was on the
 * new schema (it wasn't — only Redis was lying).
 *
 * To verify isolation after editing wp-config.php on a slot:
 *   1. `az webapp restart -g PTSAWebsite -n wilderptsa [--slot staging]`
 *   2. Hit a probe endpoint on each slot that calls wp_cache_set() with
 *      a unique sentinel, then wp_cache_get() on the OTHER slot. Cross
 *      reads must return false.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/'); // reference-only guard
}

// ---------- Database (env-driven, slot-sticky) ----------
$connectstr_dbhost     = getenv('DATABASE_HOST');
$connectstr_dbname     = getenv('DATABASE_NAME');
$connectstr_dbusername = getenv('DATABASE_USERNAME');
$connectstr_dbpassword = getenv('DATABASE_PASSWORD');

define('DB_NAME',     $connectstr_dbname);
define('DB_USER',     $connectstr_dbusername);
define('DB_PASSWORD', $connectstr_dbpassword);
define('DB_HOST',     $connectstr_dbhost);
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');

$table_prefix = 'wp_';

// ---------- Redis Object Cache (CRITICAL: per-slot isolation) ----------
//
// Both slots talk to the same Azure managed Redis instance. To stop them
// from sharing keys we:
//   (a) Pick a different logical database number per slot via
//       WP_REDIS_DATABASE env var (slot-sticky). Prod = 0, staging = 1.
//   (b) Salt every key with the slot's AFD_DOMAIN env var (slot-sticky)
//       so even if (a) is overridden somewhere, keys still don't collide.
// Either alone is enough; both together is belt-and-braces.
//
// Slot-sticky settings (Azure Portal -> App Service -> Configuration ->
// "Application settings" -> tick "Deployment slot setting" for each):
//   AFD_DOMAIN          (prod: "wilderptsa.net", staging: "wilderptsa-staging-*.azurefd.net")
//   WP_REDIS_DATABASE   (prod: "0",              staging: "1")
//   DATABASE_NAME       (prod: "..._database",   staging: "..._database_staging")
//   DATABASE_HOST       (same on both — managed MySQL)
//   BLOB_CONTAINER_NAME (prod: prod container,   staging: staging container)
//
define('WP_REDIS_HOST',         'wilderptsa-redis.redis.cache.windows.net');
define('WP_REDIS_PORT',         6380);
define('WP_REDIS_SCHEME',       'tls');
define('WP_REDIS_PASSWORD',     getenv('WP_REDIS_PASSWORD') ?: '__SET_VIA_APP_SETTING__');
define('WP_REDIS_TIMEOUT',      1);
define('WP_REDIS_READ_TIMEOUT', 1);
define('WP_CACHE_KEY_SALT',     (getenv('AFD_DOMAIN') ?: 'wilderptsa.net') . ':');
define('WP_REDIS_DATABASE',     intval(getenv('WP_REDIS_DATABASE') ?: 0));
define('WP_REDIS_MAXTTL',       86400);

// ---------- Standard WordPress defines ----------
define('WP_DEBUG',         false);
define('WP_DEBUG_LOG',     false);
define('WP_DEBUG_DISPLAY', false);
define('FS_METHOD',        'direct');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
