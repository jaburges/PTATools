# Backup & Restore Guide

## Overview

The Azure Plugin backup system creates **split, component-based backups** stored in Azure Blob Storage. Each backup consists of separate archives for database, must-use plugins, plugins, themes, and other content — plus a manifest file that describes the complete backup set.

**Media files are not included in backups.** Media is sourced from SharePoint/OneDrive and synced into the WordPress media library during the restore process, avoiding duplication and date-stamping issues.

After every backup, an **automated validation** step verifies that all component files exist in Azure Storage and match expected sizes.

---

## Backup Process

### What Gets Backed Up

| Component | Contents | Format |
|-----------|----------|--------|
| **Database** | Full MySQL dump (all tables) | `.sql.gz` (gzip compressed) |
| **Must-Use Plugins** | `wp-content/mu-plugins/` directory | `.zip` |
| **Plugins** | All plugins (or selected subset) | `.zip` (split if >400MB) |
| **Themes** | All themes (or selected subset) | `.zip` (split if >400MB) |
| **Other Content** | Remaining `wp-content/` files (excluding plugins, themes, uploads, mu-plugins) | `.zip` (split if >400MB) |

> **Media / Uploads** are excluded from backups. They are managed by the OneDrive/SharePoint sync module and pulled during restore.

### How It Works

1. **Job Creation** — A backup job is created with a unique ID and stored in the `wp_azure_backup_jobs` database table.

2. **WP-Cron Chain** — The backup runs as a series of WP-Cron events. Each entity is processed one at a time, and after each completes, the next cron event is scheduled. This prevents PHP timeout issues.

3. **Database Backup** — Prefers `mysqldump` binary (fastest, most reliable). Falls back to PHP-based `SELECT ... LIMIT/OFFSET` chunked export. Output is gzip compressed.

4. **File Archives** — Uses `ZipArchive` to create zip files. Large components are automatically split into multiple archives when they approach the configured split size (default 400MB).

5. **Azure Upload** — Each archive is uploaded to Azure Blob Storage as it completes. Files larger than 64MB use chunked (block) uploads. Blob path structure:
   ```
   {site-name}/{YYYY/MM/DD}/{backup-id}/{backup-id}-{component}.{ext}
   ```

6. **Manifest** — A JSON manifest file listing all components, blob paths, sizes, and metadata.

7. **Post-Backup Validation** — HEAD requests verify all blobs exist and match expected sizes.

### Azure Storage Structure

```
your-site/
├── 2026/03/31/
│   └── backup_1774500269_FAQFor6H/
│       ├── backup_1774500269_FAQFor6H-manifest.json
│       ├── backup_1774500269_FAQFor6H-db.sql.gz
│       ├── backup_1774500269_FAQFor6H-mu-plugins.zip
│       ├── backup_1774500269_FAQFor6H-plugins.zip
│       ├── backup_1774500269_FAQFor6H-themes.zip
│       └── backup_1774500269_FAQFor6H-others.zip
```

### Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| Storage Account Name | — | Azure Storage account name |
| Storage Account Key | — | Azure Storage access key |
| Container Name | `wordpress-backups` | Blob container for backups |
| Archive Split Size | 400 MB | Max size per zip archive before splitting |
| Backup Schedule | Manual | Can be set to daily, weekly, or manual |

---

## Restore Process

### Restore Order

Components are always restored in a specific order:

1. **Database** — Restored first, as all other components depend on DB state
2. **Must-Use Plugins** — Restored before regular plugins
3. **Plugins** — Restored before themes in case of dependencies
4. **Themes** — Restored after plugins
5. **Other Content** — Restored last

This order is enforced automatically regardless of the order components appear in the manifest.

### Restoring on the Same Site

1. Navigate to **Azure Plugin → Backup**
2. In the **Recent Backup Jobs** table, click **Restore** on a completed backup
3. Select which components to restore (Database, Must-Use Plugins, Plugins, Themes, Other Content)
4. Click **Restore Selected** and wait for completion
5. Media can be synced separately from **OneDrive Media → Sync from OneDrive**

---

## Restoring on a Different Site (Restore Wizard)

The **Restore Wizard** provides a guided, step-by-step process for restoring a backup onto a new WordPress installation. It handles session management, migration (URL replacement), and media sync automatically.

### How It Works

The wizard is accessible from:
- **Azure Plugin → Setup Wizard → "Restore from Backup"** (on fresh installs)
- **Azure Plugin → Restore Wizard** (direct menu item when active)

### Wizard Steps

#### Step 1: Connect to Azure Storage
Enter your Azure Blob Storage credentials (account name, key, container). The wizard validates the connection before proceeding.

#### Step 2: Select Backup
The wizard lists all available backups grouped by site and date. Select the backup you want to restore. Split (v2) and legacy (v1) formats are both supported.

#### Step 3: Restore Database
Before restoring, the wizard displays:
- **Backup metadata** — source site URL, WordPress version, PHP version, creation date
- **Migration warnings** — if the backup is from a different URL, or a different PHP/WP version
- **Component list** — what's included in the backup
- **"Search and replace" checkbox** — URL migration is on by default

When you click **Restore**, the database is overwritten. The wizard:
- **Preserves Azure Storage credentials** so you can continue the restore
- **Creates a temporary admin account** with credentials displayed on screen
- **Marks the setup wizard as completed** so you're not redirected to onboarding

After DB restore, your session is invalidated. Copy the temporary credentials, then click **Log In & Continue** to resume the wizard.

#### Step 4: Re-Authenticate
Confirms the database was restored successfully. You're logged in with the temporary admin account.

#### Step 5: Restore Files
Restores must-use plugins, plugins, themes, and other content files from the backup. Features:
- **Progress sidebar** — shows each component's status (pending → active → done)
- **Live activity log** — real-time display of download progress, file counts, and extraction results
- **Per-component tracking** — each archive is downloaded, extracted, and overlaid individually

#### Step 6: Media Sync
Instead of restoring media from the backup (which would have incorrect dates and duplicate content), the wizard:
1. Temporarily sets OneDrive sync direction to **SharePoint → WordPress (one-way pull)**
2. Triggers a full sync from your SharePoint/OneDrive library
3. After completion, switches back to **two-way sync**

You can skip this step and sync media manually later from the OneDrive Media settings.

#### Step 7: Complete
Shows a summary of what was restored and a post-restore checklist. Clicking **Finish**:
- Removes the temporary admin account
- Clears the restore wizard state
- Marks the setup wizard as completed
- Redirects to the plugin dashboard

### Post-Restore Checklist

- [ ] Verify the site loads correctly on the front end
- [ ] Log in with your regular admin account from the source site
- [ ] Verify media images appear correctly
- [ ] Update permalinks (Settings → Permalinks → Save)
- [ ] Check Azure SSO configuration (update redirect URIs for new URL)
- [ ] Review OneDrive sync settings
- [ ] Verify cron jobs are running

---

## Troubleshooting

| Issue | Cause | Fix |
|-------|-------|-----|
| "This backup contains no restorable files" | Backup had archive failures | Run a new backup on the source site |
| Restore completes but site shows old content | Cached pages | Clear all caches, flush Redis/object cache |
| "files appear to be missing" error | Orphaned `object-cache.php` from caching plugin | Delete `wp-content/object-cache.php` |
| W3TC Redis "Connection refused" | Redis not configured on new environment | Disable W3TC or configure Redis |
| Setup wizard appears after restore | `setup_wizard_completed` not set | The restore wizard handles this automatically; if using manual restore, set the flag in settings |
| Logged out during restore | DB restore invalidated session | Use the Restore Wizard which creates a temp admin account |
| Media has wrong dates | Media was included in backup | Use OneDrive sync instead (media is excluded from backups by default) |

### Important Notes

- **Media is excluded from backups** — SharePoint/OneDrive is the source of truth for media files. During restore, media is pulled fresh via the OneDrive sync module.
- **Two sites, one storage account** — The backup listing shows backups from all sites in the container. Identify the correct backup by site name prefix.
- **Database restore is destructive** — Restoring the database overwrites all existing tables. The restore preserves the current site's `siteurl` and `home`, and Azure Storage credentials.
- **wp-config.php is never overwritten** — Database credentials, salts, and other configuration remain as-is.
- **Selective restore** — You can restore individual components without touching others.
- **Temporary admin account** — The Restore Wizard creates a temporary admin for re-authentication after DB restore. It is automatically removed on completion.
