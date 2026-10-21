# PTA Tools — Dead Code Inventory

> Status: candidates only. **Do not delete anything in this list without an explicit per-item sign-off from a maintainer.** Every item below has been confirmed as not-loaded/not-referenced from any code path that we currently exercise, but several were once part of shipped features and may be referenced by:
>
> - Stale rows in `wp_options` (settings keys still present from old activations)
> - Per-site customisations or external code
> - Ongoing PRs or branches
>
> Treat this as a worklist: pick an item, verify on each production site, then delete.

Last reviewed: 2026-04-29 (plugin v3.59).

---

## 1. Tickets module — no longer dead

Wired into `azure-plugin.php` (`init_tickets_components`) in v3.147.184 and in use. Do not delete.

---

## 2. Calendar Auth `.broken` file

A backup of an earlier `class-calendar-auth.php` left in the includes directory.

- `includes/class-calendar-auth.php.broken` (~35 KB)

Not loaded by any code path (the actual class lives in `class-calendar-auth.php`, also ~30 KB). Safe to delete after a final visual diff to confirm there's nothing in the `.broken` copy that was never ported back.

---

## 3. TEC Integration Test stub

A debug version of `Azure_TEC_Integration` left in the includes directory. It declares the same class name as the real module file (`class-tec-integration.php`), so loading both would cause a fatal "Cannot redeclare class" error — confirmation that the test stub is not loaded anywhere.

- `includes/class-tec-integration-test.php`

Full of `error_log('TEC Integration Test: …')` lines and is clearly leftover from a debugging session. Safe to delete.

---

## 4. Setup / Restore Wizards — never instantiated by the plugin

Both files self-instantiate via `Class::get_instance()` at the bottom, but neither file is required from `azure-plugin.php`, `class-admin.php`, or any module's `init_X_components()`. The admin pages that consume them (`admin/setup-wizard-page.php`, `admin/restore-wizard-page.php`) reference the classes but only after `class_exists()` checks that always return `false` in the current loader chain.

- `includes/class-setup-wizard.php`
- `includes/class-restore-wizard.php`
- `admin/setup-wizard-page.php`
- `admin/restore-wizard-page.php`

**Caveat — needs manual confirmation:** these were originally activated by a different bootstrap pattern. Before deletion:
- [ ] Confirm the "Setup Wizard" button in `admin/main-page.php` does not show on either production site (the gate is `class_exists('Azure_Setup_Wizard')` — should always be false now)
- [ ] Confirm the "Restore Wizard" link inside `admin/backup-page.php` (or wherever it appears) is unreachable

If these were intentionally disabled but might come back, leave them; otherwise delete the four files together.

---

## 5. Orphaned methods removed in v3.59 (already gone)

For reference — these were removed as part of the cron centralization refactor and should not appear anywhere in the codebase. If a search turns them up they are stale code:

- `Azure_Newsletter_Module::schedule_cron_events()`
- `Azure_Newsletter_Module::register_cron_schedules()`
- `Azure_PTA_Manager::schedule_cleanup()`
- `Azure_PTA_Sync_Engine::schedule_sync_processing()`
- `Azure_PTA_Groups_Manager::schedule_group_sync()`
- `Azure_TEC_Sync_Scheduler::add_custom_cron_schedules()`
- The standalone `add_filter('cron_schedules', …)` at the bottom of `class-newsletter-module.php`

---

## 6. Orphaned settings keys

None — `enable_tickets` is live again (see item #1).

---

## 7. Suspected — needs runtime verification before listing

These look unreferenced but I haven't been able to fully prove they're dead. **Do not delete without first running a search through the live admin UI and any custom theme code:**

- (none currently — leave this section for future findings)

---

## Process for deleting items from this list

1. Pick a single item.
2. Re-run the verification checks listed under it.
3. Search the entire workspace for the file/symbol name (`Grep` across both the plugin and any active theme).
4. Delete the file(s) plus the related setting/UI references.
5. Bump plugin version, deploy to the **staging slot** first, smoke-test, then deploy to production.
6. Remove the item from this list (or move to a "Removed" section at the bottom for history).
