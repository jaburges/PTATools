# lwptsa.net redesign cut-over (plan only)

## Summary

This is the plan for moving lwptsa.net production onto the redesign that is
running on the staging slot: the `lwptsa-recap` theme, PTA Tools 3.147.160 or
later, and the AI module. **Nothing here has been run against production.**
Each step needs a go-ahead from the site owner on the day.

The approach is to change production in place (upload files, flip settings),
not to swap slots. A swap would carry staging's database name, staging guard
and staging settings into production. The production database is never
copied from staging; the few content changes made on staging are replayed by
hand (step 6).

Expected visitor impact: none during the uploads (the old theme stays active
until step 5), then one restart of about a minute.

## Before the day

1. **Agree a window** and tell the board who edits the site not to publish
   during it.
2. **Fix the public backups first** (see "Findings" in
   `lwptsa-staging.md`): `wp-content/updraft/` and
   `wp-content/plugins/Azure Plugin/backups/` are downloadable because nginx
   ignores `.htaccess`. Move them out of `wwwroot` (or into blob storage) and
   confirm a direct URL returns 404. This is independent of the redesign and
   worth doing first.
3. **SSO redirect URI.** Production keeps its URL, so SSO keeps working. No
   change needed unless staging review needs SSO too.
4. **Decide on Google Language Translator.** Production has the GLT plugin's
   "Translate »" widget. With AI translation on, a reader has two translators
   on a story page. Options: keep GLT for pages and turn off its floating
   widget on posts, or retire GLT once AI translation has been reviewed.
   Record the decision here before the day.
5. **Review on staging.** Owner signs off on: homepage, a story page in
   English and one other language, category archive, calendar, an event page,
   and the editor's "Key points (AI)" box.

## On the day

All commands target the **production** slot of `lwptsa`
(subscription `6ac44d89-b110-4c79-b3a8-6f4a2ba115bc`, resource group
`PTSAWebsite`) and Kudu `lwptsa.scm.azurewebsites.net`. Never pass `--clean`.

### 1. Back up

- **Database:** export `lwptsa_database` (mysqldump from a short-lived job or
  Kudu-side tool) to the `wordpress-backups` blob container. Note the file
  name here.
- **Files:** download `wp-content/themes/`, `wp-content/plugins/Azure Plugin/`
  and `wp-content/mu-plugins/` with `GET /api/zip/site/wwwroot/<dir>/`.
- Record the current active theme (`template` / `stylesheet` options, expected
  `catchbox`) and current plugin version.

### 2. Upload the plugin

- Build from the `lwptsa-redesign` branch with a clean working tree.
- `PUT /api/zip/site/wwwroot/wp-content/plugins/Azure%20Plugin/` in chunks of
  at most ~1,500 files, then compare file counts. Verify `azure-plugin.php`
  shows the expected `Version:` through `GET /api/vfs/...`.
- Do **not** copy `pta-staging-guard.php` or any `mu-plugins` file from
  staging.

### 3. Upload the theme

- Zip `themes/lwptsa-recap/` and `PUT` it to
  `/api/zip/site/wwwroot/wp-content/themes/`. Catch Box stays installed.

### 4. Restart and check the old site still works

- `az webapp restart -g PTSAWebsite -n lwptsa --subscription 6ac44d89-b110-4c79-b3a8-6f4a2ba115bc`
  (local storage cache is on, so files aren't seen until a restart).
- `https://lwptsa.net/` returns 200 and still shows Catch Box; `wp-admin`
  returns 302. The database upgrade for the new plugin runs on the first
  admin or cron request.

### 5. Activate the theme

- Appearance → Themes → activate "LWPTSA Council" (SSO admin login works on
  production). Check the homepage, a story, the calendar page and an event.

### 6. Replay the staging content changes

From "Changes to replay at cut-over" in `lwptsa-staging.md`:

1. Calendar page (ID 121): page template `ptsa-full-width.php` → `page-wide`.
2. Confirm the `featured` category has the story that should lead the
   homepage.

### 7. Turn on the AI module

1. PTA Tools → turn on **AI** → Configure.
2. Endpoint `https://lwptsa-ai.cognitiveservices.azure.com/`, key from
   `az cognitiveservices account keys list -n lwptsa-ai -g PTSAWebsite`,
   deployments `chat` and `embed`. **Test connection.**
3. Features: key points and related stories on. Translation on with the
   agreed languages (staging uses es, zh-Hans, ru, ko, vi, hi). Budget
   $10/month, 40 new translations/day.
4. **Process now** until "Posts waiting" is 0. On staging the 445-post
   backfill cost $0.57 and took about 25 minutes.
5. Spot-check three stories' key points with the owner. Any can be edited or
   hidden from the post editor.

### 8. Verify

- Re-run the 105-URL status crawl used for staging against production: same
  status codes as before, no PHP warnings in the HTML.
- A story page shows key points, related stories and Up Next.
- `?pta_lang=es` on a story shows "Translation in progress", then the Spanish
  page with the machine-translation notice after a reload.
- PTA Tools → System → Logs has no new errors.

## Rollback

- **Theme:** activate Catch Box again. Nothing in the redesign changes content,
  so this is instant and complete.
- **AI:** turn the module off on the PTA Tools page. Its blocks render nothing
  when off, and its cron events are cleared on the next admin/cron request.
  Its tables (`wp_pta_ai_*`) and post meta (`_pta_ai_*`) can stay.
- **Plugin:** upload the previous `Azure Plugin` folder from the step 1
  download and restart.
- **Database:** restore the step 1 export only if something wrote bad data;
  none of the steps above are expected to.

## Notes

- **Front Door caching.** The production route (`LWPTSAAFD` / `default-route`)
  has no caching configured today, so `?pta_lang=` pages always reach
  WordPress. If edge caching is ever turned on, the route must cache by query
  string (or at least vary on `pta_lang`). Otherwise every language is served
  the cached English page. The plugin already marks "Translation in progress"
  pages as not shareable.
- **wp-cron.** Production doesn't set `DISABLE_WP_CRON`, so wp-cron runs from
  visitor traffic. The AI backlog and translation jobs depend on it. If cron is
  ever moved to a scheduled caller (as on the Container Apps sites), call
  `wp-cron.php` at least every 30 minutes.
- **Data sent to Azure AI.** Only published posts (title, text, excerpt and
  key points). The resource is in the US data zone.
