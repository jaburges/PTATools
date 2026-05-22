# Media Loss Incident — 2026-05-22

## Summary

A parallel Cursor agent session executed a destructive "clean" operation against the Azure App Service file system, deleting the contents of `/wp-content/uploads/`. As a result, **1,465 of 1,548 attachment files (94.6%)** are missing from the App Service filesystem.

The WordPress database (`wp_posts` with `post_type='attachment'`) still has all the attachment records, which is why the WP Media Library still LISTS them — but they all show as blank/broken thumbnails because the underlying files don't exist on disk.

## Damage by year

| Year | Orphaned files | Notes |
|---|---|---|
| 2018 | 283 | |
| 2019 | 159 | |
| 2020 | 76 | |
| 2021 | 42 | |
| 2023 | 34 | (2022 had no uploads in DB) |
| **2024** | **507** | Biggest cohort |
| 2025 | 216 | |
| 2026 | 146 | Including **all 21** of 2026/04 and **61 of 69** in 2026/05 (auction class-basket photos) |

## Why backups didn't save us

We investigated every backup path:

| Source | Result |
|---|---|
| **Azure App Service auto-snapshots** (hourly, ~30 days back) | ❌ Don't capture `/wp-content/uploads/` reliably — verified by restoring snapshots from May 15 and May 21 to a recovery slot. Both showed sparse upload trees. App Service snapshots are designed for code recovery, not media. |
| **UpdraftPlus local backup** in `/wp-content/updraft/` (1.14 GB, May 21 21:34) | ❌ Backup ran 7+ hours AFTER the deletion. The zip contains 2,780 files for 2026/03 (post-repopulation) and 44 for 2026/05 (just thumbnails) — but ZERO files for 2026/04. |
| **Azure Storage `wordpress-backups` container** (custom plugin backup module) | ⚠️ Has a full uploads backup from **2026-03-31** (4 zips, 1.1 GB) — captures everything pre-April 2026. Useful for 2018-2025 partial recovery, but 2026/04 and most of 2026/05 didn't exist yet. |
| **Wayback Machine** | ❌ Zero captures of `/wp-content/uploads/2026/04/*` or `/wp-content/uploads/2026/05/*`. |
| **Staging deployment slot** | ❌ Empty — CI only deploys plugin code, not WP core data. |
| **External CDN / WP Offload Media** | ❌ Not installed/configured. `WP_CONTENT_URL` is relative — primary store IS the App Service disk. |

## What's actually recoverable

- **Pre-April 2026 content** can be partially restored from the Azure Storage March 31 backup (`wordpress-backups/wilder-ptsa/2026/03/31/backup_1774996285_H3BvGD2A/`). Estimated coverage: most of 2018–2025, maybe up to 1,000 files.
- **2026/04 and 2026/05 content is unrecoverable** unless the parallel agent has a local copy on their machine.

## Actions taken on 2026-05-22

1. **Inventory** (this folder) — full audit of all 1,465 lost files saved as JSON/CSV/markdown for the PTA team's community re-collection effort.

2. **Cleanup of orphan attachment posts** — at user's explicit direction, deleted all 1,465 orphan `wp_posts` rows + cascade-deleted associated `wp_postmeta`, `wp_term_relationships`, and child posts. Done via `infra/ops/cleanup-orphan-attachments.php` (slow per-post via `wp_delete_post`) and `infra/ops/fast-cleanup-orphan-attachments.php` (fast direct SQL).

   **Post-cleanup state (verified 2026-05-22 11:30 PT):**
   - Total attachment posts in DB: **83** (down from 1,548)
   - Orphans remaining: **0**
   - Media Library now shows only valid, on-disk attachments — no more blank thumbnails.

   The 1,465 deleted attachments' metadata (titles, alt text, paths) is **preserved in this folder's CSV/JSON files**. If files are ever recovered, attachment posts can be reconstructed using `wp_insert_attachment()` with the original `_wp_attached_file` paths.

## Files in this folder

- `orphan-inventory.json` — full machine-readable dump (572 KB)
- `orphan-inventory.csv` — one row per orphan, for spreadsheet review (250 KB)
- `orphan-inventory.md` — human-readable, grouped by year/month (140 KB)
- `README.md` — this file

## Lesson for future backups

UpdraftPlus's local-staging-only configuration was inadequate. Recommend either:

- Configuring UpdraftPlus with a **remote destination** (Azure Blob, Google Drive, S3) and at least 90-day retention
- Wiring the existing `class-backup-azure-storage.php` module to back up uploads on a daily cadence (currently it appears to only back up code + DB based on the snapshot pattern in the `wordpress-backups` container)
- Configuring native [Azure App Service backups](https://learn.microsoft.com/en-us/azure/app-service/manage-backup) (separate from auto-snapshots), which DO include user-uploaded files

The performance audit in `improvements.md` (repo root) also recommends moving to a managed WordPress host like Kinsta or WP Engine — they all include daily off-machine backups by default.
