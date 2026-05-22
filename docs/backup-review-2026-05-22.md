# Backup Module Review — 2026-05-22

## TL;DR

The plugin's backup module **works correctly when configured correctly**. A smoke test triggered with `('database','mu-plugins','plugins','themes','content','uploads')` completed in 25 minutes and produced a clean 1.45 GB backup in Azure Blob Storage including all 4 uploads splits (1.16 GB of media).

But the **default configuration is broken in three independent ways** that together mean no daily backup has ever actually happened:

1. **Scheduled backups are DISABLED** (`backup_schedule_enabled = false`).
2. **Even if enabled, uploads would NOT be backed up** — the default `backup_types` list omits `'uploads'`.
3. **Retention is too short** at 5 days, with auto-cleanup deleting both the blob AND the DB record.

This is exactly why we lost 1,465 media files in the May 2026 incident — the only backups landing in storage were "Manual Backup" runs (March 31, April 2, May 9, May 21), and only the March 31 one happened to include media (because it used an old type name `'media'` that was later renamed to `'uploads'`).

## Smoke-test result (2026-05-22 18:43–19:08 UTC)

```text
Backup ID:      backup_1779475460_cHywAX4U
Status:         completed
Progress:       100%
Duration:       ~25 minutes
Total size:     1.45 GB
Types included: database, mu-plugins, plugins, themes, content, uploads
Blob path:      wilder-ptsa/2026/05/22/backup_1779475460_cHywAX4U/

Files landed in blob storage:
  db.sql.gz              26 MB
  mu-plugins.zip         19 KB
  plugins.zip           116 MB
  themes.zip             21 MB
  others.zip            121 MB    ← "content" entity (wp-content excluding the other 4)
  uploads.zip           363 MB    ← upload split 1
  uploads2.zip          361 MB    ← upload split 2
  uploads3.zip          354 MB    ← upload split 3
  uploads4.zip           83 MB    ← upload split 4
  manifest.json         2.4 KB
```

Storage connection test passed. Site remained responsive throughout. No errors logged.

## Issues to fix before turning on daily backups

### 🔴 P0 — Default `backup_types` omits `uploads`

**Where:** `Azure Plugin/includes/class-backup.php`, three locations:
- Line 215 (job-resume default)
- Line 531 (`ajax_start_backup` default)
- Line 869 (`run_scheduled_backup` default)

All three use:
```php
array('database', 'mu-plugins', 'plugins', 'themes', 'content')
```

**Fix:** change to:
```php
array('database', 'mu-plugins', 'plugins', 'themes', 'content', 'uploads')
```

**Risk:** none — `'uploads'` is already an implemented entity type at line 353–356. We just need to include it in the default list.

**Side effect:** scheduled backup duration will grow from ~5 min to ~25 min. Not a problem at 2 AM.

### 🔴 P0 — Schedule is disabled

**Where:** `backup_schedule_enabled` setting (currently `false` on prod).

**Fix:** Toggle to `true` in the plugin's Backup admin page, OR via one-off `update_option` script. Default in code is also `false` — consider changing that for new installs (but not strictly required since this is a one-deploy site).

### 🟠 P1 — Retention too short

**Where:** `backup_retention_days` setting (currently `5`).

**Recommended:** **30 days** minimum. The cleanup logic in `class-backup-scheduler.php` lines 187–246 deletes BOTH the blob in storage AND the DB row, so 5 days means you have 5 days to discover a problem before all evidence is gone. 30 days means a parent's complaint about a missing image still leaves a chance of recovery.

**Caveat:** more storage cost. At ~1.5 GB/day × 30 days = ~45 GB. At Azure Blob Storage Hot tier ~$0.018/GB/month = ~$0.81/month. Negligible.

### 🟠 P1 — Email notifications disabled

**Where:** `backup_email_notifications` setting (currently `false`).

**Fix:** Toggle to `true`. Recipient `jamieb@wilderptsa.net` is already configured. The scheduler sends notification on both success and failure (lines 251–282 of `class-backup-scheduler.php`).

**Why critical:** Without notifications, if WP-Cron fails to fire for a week, no one would know until the next disaster.

### 🟡 P2 — `backup_types` historical inconsistency

**Where:** Storage account contains a March 31 backup with `["database","content","media","plugins","themes"]` — note `"media"`. The current code uses `"uploads"`. Indicates a rename happened.

**Risk:** if any restore-from-old-backup code still expects `"media"`, restore from March 31 might fail. Worth a quick `grep -r 'media' includes/class-backup-restore.php` audit. (Not done in this review — for follow-up.)

### 🟡 P2 — WP-Cron reliability

WP-Cron only fires when there's traffic. On a low-traffic site at 2 AM, a daily backup might miss its window. Two options:

1. **Real cron** — Azure App Service's "WebJobs" or App Service-side cron hitting `https://wilderptsa.net/wp-cron.php?doing_wp_cron=1` once a minute. Bulletproof.
2. **Action Scheduler** — WooCommerce installs Action Scheduler; the plugin could use that instead of WP-Cron. More resilient for low-traffic sites.

**Recommendation:** option 1 — set up a WebJob. The plugin code as-is should fire fine because the WooCommerce site DOES have enough traffic, but it's worth being deliberate.

### 🟢 P3 — Class loading on non-admin requests

**Observation:** my probe had to manually `require_once` the backup module files even though `enable_backup` was `true`. The `init_backup_components()` path in `azure-plugin.php` may not load them in all contexts (especially anonymous front-end requests, which is where WP-Cron sometimes runs).

**Risk:** when WP-Cron fires the `azure_backup_scheduled` hook, the callback class may not exist. Need to verify.

**Mitigation:** the existing code already handles this in `class-backup-scheduler.php` line 162 by checking `class_exists('Azure_Backup')`. But if the class isn't loaded when cron fires, the backup silently fails. Worth testing once we enable scheduling.

## Recommended fix sequence

To get daily backups working reliably:

### Phase 1 — Quick code+settings fix (~30 min total)

1. **Code change in `class-backup.php`** — add `'uploads'` to the three default `backup_types` arrays. Commit + push + deploy.
2. **Settings update via WP admin** (or one-off `update_option`):
   - `backup_schedule_enabled` → `true`
   - `backup_types` → `["database","mu-plugins","content","plugins","themes","uploads"]`
   - `backup_retention_days` → `30`
   - `backup_email_notifications` → `true`
   - Verify `backup_schedule_time` is `02:00` (or whatever low-traffic window suits the site)
3. **Verify schedule activated** — re-probe shows `next_backup_at` populated.

### Phase 2 — Reliability hardening (~1 hour)

4. **Audit `class-backup-restore.php`** for any references to `"media"` entity type — update to `"uploads"`.
5. **Set up an Azure WebJob** that hits `wp-cron.php` once a minute. Eliminates WP-Cron-via-traffic uncertainty.
6. **Wait for first scheduled backup to fire** (next 2 AM) + verify blob lands.

### Phase 3 — Monitoring (~30 min)

7. **Wire backup failure into Slack/Teams/PagerDuty** if you have any of those, OR set up a Logic App to alert on missing daily blob in `wordpress-backups` container.
8. **Add a "Last successful backup" widget** to the WP admin dashboard so the next person looking after the site can verify-at-a-glance.

## Files referenced in this review

```
Azure Plugin/includes/class-backup.php              (39 KB) — main orchestrator
Azure Plugin/includes/class-backup-engine.php       (18 KB) — zip creation + DB dump
Azure Plugin/includes/class-backup-azure-storage.php(37 KB) — blob upload
Azure Plugin/includes/class-backup-scheduler.php    (14 KB) — WP-Cron wiring + cleanup
Azure Plugin/includes/class-backup-restore.php      (40 KB) — restore logic
Azure Plugin/admin/backup-page.php                  (63 KB) — admin UI
infra/ops/backup-probe.php                          —       — diagnostic (uploaded then removed)
```

## Smoke-test artifact remains in blob storage

The 1.45 GB backup `backup_1779475460_cHywAX4U` is now in `wilder-ptsa/2026/05/22/` in the `wordpress-backups` container. This is the **first complete backup including uploads since March 31** — keep it. Don't let the 5-day retention auto-delete it before we bump the retention setting.

## 🚨 MAJOR DISCOVERY — OneDrive Media is the missing backup, and most files are likely still there

The Backup admin page explicitly states **"Media is synced from SharePoint/OneDrive after restore"** (line 478 and 941 of `admin/backup-page.php`). The backup module deliberately excludes `uploads`/`media` from the WP backup zip **because the architecture assumes the OneDrive Media integration is the backup path for media.**

Probing the OneDrive Media subsystem on prod reveals:

```text
wp_azure_onedrive_files table:    1,447 rows, ALL sync_status='synced'
wp_azure_onedrive_tokens table:   1 row, expired 2026-04-02 01:42
wp_azure_onedrive_sync_queue:     empty
Settings — enable_onedrive_media: false  ← currently OFF
SharePoint folder:                /WordPress Media/2026/
Latest sync activity:             2026-04-02 ~00:26
```

**Reconstructed timeline:**
1. **Early 2026** — someone configured the OneDrive Media integration. 1,447 files synced from `/wp-content/uploads/2026/` to SharePoint `/WordPress Media/2026/`.
2. **2026-04-02** — the OneDrive auth token expired. **The plugin does not auto-refresh.** All sync silently stopped.
3. **2026-04-02 → ~2026-05-14** — 18 new media uploads (`1465 - 1447 = 18`) happened on the WP site but never made it to SharePoint. These are gone for good.
4. **At some point** — `enable_onedrive_media` was toggled off (likely after someone noticed it was failing).
5. **~2026-05-14** — parallel agent's "clean" wiped `/wp-content/uploads/`. 1,465 files vanished from the WP file system.

**Recovery opportunity:** the 1,447 files **should still be in SharePoint at `/WordPress Media/2026/`** — deleting from WP doesn't delete from SharePoint, and the only reason the WP↔SharePoint sync stopped was the token expiring on the WP side. Need to verify with a fresh Graph API token, but the data shape strongly suggests near-total recovery is possible.

### Recovery plan (separate from backup module work)

1. **Verify SharePoint copies exist** — get fresh Graph API token, list `/WordPress Media/2026/`, count items.
2. **Reconstruct year/month structure** — `wp-content/uploads/` uses `YYYY/MM/` subdirs but SharePoint stores them flat under `2026/`. Cross-reference the `last_modified` timestamp in `wp_azure_onedrive_files` to determine the right destination month.
3. **Bulk download + place files** — for each row, download from SharePoint, write to `/home/site/wwwroot/wp-content/uploads/{year}/{month}/{filename}`.
4. **Re-create attachment records** — we deleted the 1,465 orphan `wp_posts` rows already. To make files visible in the Media Library again, re-create attachments via `wp_insert_attachment()` + `wp_update_attachment_metadata()` — OR leave files in place if URLs are referenced directly in post content (which is the common case).

This is **much more important than getting daily backups running**, because it's potentially the difference between losing 18 files vs losing 1,465 files. **Recommend doing the recovery first.**

### Going-forward fix for OneDrive Media

Independent of recovery:
- **Add auto-refresh for the OneDrive auth token** in `class-onedrive-media-auth.php` — should be a refresh-token flow with Graph API, not just stash + use until expiry.
- **Email alert when token expires** — same notification path as backup failures.
- **Re-enable the integration** once token is healthy.
- **Add a "Sync now" button** + scheduled hourly resync so files don't drift.

## Open follow-ups

- `wilderptsa-cleanup.php` MU-plugin on the server (uploaded by the parallel agent) is still untouched. Worth a look to confirm it's not what caused the original media wipe.
- Run a restore test FROM today's smoke-test backup INTO a recovery slot to verify the full backup→restore round trip works end-to-end. Not done as part of this review.
- Verify SharePoint copies of the 1,447 files actually exist (Graph API list call) before promising any recovery.
