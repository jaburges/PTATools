# lwptsa.net staging slot

## Summary

On 2026-10-04 the lwptsa staging slot was rebuilt as a full copy of production
so the redesign (new theme, AI module) can be built and reviewed without
touching the live site. Staging runs PTA Tools 3.147.160 from the
`lwptsa-redesign` branch with the new `lwptsa-recap` theme active and the AI
module on. It cannot send email or post to outside services,
and search engines are told not to index it.

Nothing in this runbook writes to production. Production was only read
(file downloads through Kudu, `SELECT`s, public page fetches).

## Where things are

| | Production | Staging |
|---|---|---|
| Subscription | `6ac44d89-b110-4c79-b3a8-6f4a2ba115bc` (lwptsa, not Wilder) | same |
| App Service | `lwptsa` in `PTSAWebsite` | `lwptsa`, slot `staging` |
| Public URL | https://lwptsa.net | https://lwptsa-staging-endpoint-bgggctbhb4gnc3ds.z02.azurefd.net (WordPress home) |
| Direct host | `lwptsa.azurewebsites.net` | `lwptsa-staging.azurewebsites.net` |
| Kudu | `lwptsa.scm.azurewebsites.net` | `lwptsa-staging.scm.azurewebsites.net` |
| Database | `lwptsa_database` | `lwptsa_database_staging` |
| DB server | `lwptsa-wpdbserver.mysql.database.azure.com`, Entra user `lwptsa-wpidentity` | same server and identity |
| Media blob container | `bloblwptsa` (empty; uploads are local files) | `bloblwptsastaging` |

Both slots run the App Service WordPress image
(`mcr.microsoft.com/appsvc/wordpress-debian-php:8.3`) with nginx, so
`.htaccess` files are ignored.

## How staging was built

1. **Files.** Downloaded production's `wwwroot` through Kudu
   (`GET /api/zip/site/wwwroot/<dir>/`) one directory at a time and uploaded
   to staging with `PUT /api/zip/site/wwwroot/<dir>/` (extracts in place,
   never cleans). Backup folders (`wp-content/updraft`,
   `Azure Plugin/backups`) were left out.
2. **Database.** A temporary token-gated PHP tool on staging copied every
   table from `lwptsa_database` into `lwptsa_database_staging`, then rewrote
   `https://lwptsa.net` to the staging address with a serialization-aware
   replace (paged with `OFFSET` so composite-key tables can't loop). The tool
   was deleted afterwards.
3. **Config.** `wp-config.php` is production's env-driven file with new salts
   and one addition:
   `if (preg_match('/_staging$/', getenv('DATABASE_NAME'))) define('WP_ENVIRONMENT_TYPE', 'staging');`
4. **Guard.** `wp-content/mu-plugins/pta-staging-guard.php` (staging only, not
   in the repo) does nothing unless `DATABASE_NAME` ends in `_staging`. When
   active it:
   - drops all mail (`pre_wp_mail`);
   - blocks outbound HTTP other than GET/HEAD, except to the site itself and
     `login.microsoftonline.com/.../oauth2/`;
   - forces `blog_public` to 0;
   - shows a red STAGING node in the admin bar.

   - allows POSTs to the Foundry endpoint (`lwptsa-ai.cognitiveservices.azure.com`)
     for the AI module.
5. **Plugin.** Deployed 3.147.156 and restarted; the database upgrade ran on
   the first admin/cron request.
6. **Checks.** 105 production URLs (pages, posts, events, archives) returned the same status
   codes on staging, with no PHP errors in the HTML. `/wp-json/pta-tools/v1/feed/events`
   and `/feed/stories` return data, and the `[pta-shared-events]` /
   `[pta-shared-stories]` shortcodes render both from staging's own feed and
   from production (through the `.ics` and `/wp/v2/posts` fallback, since
   production is on an older plugin).

## Deploying to staging

- Upload with Kudu `PUT /api/zip/site/wwwroot/<path>/`. The gateway gives up
  at about 230 s and extraction stops part-way **without an error you can
  trust**, so send at most ~1,500 files per zip and compare file counts
  afterwards. A single file can go through `PUT /api/vfs/site/wwwroot/<path>`
  with `If-Match: *`.
- **Restart the staging slot after every deploy**
  (`az webapp restart -g PTSAWebsite -n lwptsa --slot staging --subscription 6ac44d89-b110-4c79-b3a8-6f4a2ba115bc`).
  `WORDPRESS_LOCAL_STORAGE_CACHE_ENABLED=true` means PHP serves a local copy,
  so new or changed files aren't seen until a restart. Restarting also
  interrupts Kudu, so only restart between uploads.
- **Deleting a file is not reliably seen after one restart.** A deleted
  temporary helper kept running from the local copy on 2026-10-04. To remove a
  file: overwrite it with an inert stub that sends a marker header, restart,
  confirm the header, then delete it, restart, and confirm the header is gone.
- Never pass `--slot` values or Kudu hosts for production, and never use
  `--clean`.
- Commits for this work go on `lwptsa-redesign`. Pushing `main` or `dev`
  deploys Wilder.

## New theme (lwptsa-recap)

The block theme in `themes/lwptsa-recap/` (design: `docs/design/lwptsa/`,
"H1 Clean Community" plus the story page) is active on staging. Catch Box
stays installed for rollback.

- **Deploy:** zip the `lwptsa-recap` folder and `PUT` it to
  `/api/zip/site/wwwroot/wp-content/themes/`, then restart. Single files can
  go through VFS. It is not part of the Wilder image build.
- **Activated** with `switch_theme('lwptsa-recap')` from a temporary
  token-gated script (SSO doesn't work on staging yet), since deleted.
- **What it relies on:** PTA Tools' `/pta-tools/v1/feed/events` (called
  in-process for "Coming up", "Next up" and the story sidebar), the
  `featured` category for the homepage lead, and the existing page URLs in
  the header and footer.
- **Plugin templates** (event pages, calendar archive, `ptsa-*` page
  templates) call `get_header()`/`get_footer()`; the theme's `header.php`,
  `footer.php` and `sidebar.php` render the block header and footer for them.
- **Checks:** the 105-URL crawl matches production status codes with the
  theme active and no PHP errors.

### Changes to replay at cut-over

These are content or settings changes made on staging that production will
need too:

1. Activate `lwptsa-recap` (keep Catch Box installed).
2. Calendar page (ID 121): page template `ptsa-full-width.php` → `page-wide`
   so it gets a title and the full width.
3. Plugin 3.147.157 or later. Earlier 3.147.15x builds show an empty month
   grid on the calendar page because its shortcode carries `id="pta"`; .157
   treats non-Outlook ids as "all events".

## AI module (Azure AI Foundry)

PTA Tools 3.147.160 adds an AI module (PTA Tools → AI), on for staging only.

- **Resource:** `lwptsa-ai` (Azure AI Services, S0, westus3) in `PTSAWebsite`,
  endpoint `https://lwptsa-ai.cognitiveservices.azure.com/`. Deployments:
  `chat` = gpt-5.4-mini and `embed` = text-embedding-3-small, both DataZoneStandard
  (US data zone). Key: `az cognitiveservices account keys list -n lwptsa-ai -g PTSAWebsite`.
- **Settings on staging:** summaries, related stories and translation on;
  languages es, zh-Hans, ru, ko, vi, hi; budget $10/month; 40 new
  translations/day.
- **What it does:**
  - Key points: each published post gets a summary and 3–5 points, shown in
    the `pta-tools/ai-summary` block above the story. Editors can correct or
    hide them in the "Key points (AI)" box under the post editor.
  - Related stories and Up Next: picked from 512-dimension embeddings (the
    theme asks through `pta_related_posts` / `pta_up_next_post` and keeps its
    category fallback). Stories reposted under the same title are listed once.
  - Translation: `?pta_lang=xx` on a post. The first request queues a wp-cron
    job and shows "Translation in progress"; later requests serve the cached
    translation with a machine-translation notice, `lang`/`dir`, canonical
    and hreflang. Editing the post or its key points retranslates on the next
    request. Crawlers never queue translations, switcher links are `nofollow`,
    and only current translations are advertised in hreflang.
- **Work happens in wp-cron:** hourly backlog (`pta_ai_process`), a single
  event 30 s after a post is published or updated, and one event per
  requested translation. The settings page has "Process now" and "Test
  connection".
- **Only published posts are sent.** No member, family or order data.
- **Backfill (2026-10-04):** all 445 published posts embedded and 443
  summarised (two are too short), in about 25 minutes for $0.57. A
  translation costs under a cent per post per language.
- **Settings without a login:** SSO doesn't work on staging yet, so the
  settings were written and the backfill driven by a temporary token-gated
  mu-plugin, deleted afterwards. With a login, use PTA Tools → AI.

## Login

Production is SSO-only (default role `azuread`). Entra SSO on staging needs
the staging address added as a redirect URI on the lwptsa app registration
before it works; until then use a password login for a staging admin.

## Findings from the copy (production, not fixed)

- **Backups are publicly downloadable.** `wp-content/updraft/` holds a daily
  Updraft database dump (Sept 2 to the present) plus two full site sets, and
  `wp-content/plugins/Azure Plugin/backups/` holds a partial Sept 2 backup.
  nginx ignores the `.htaccess` that is meant to protect them; a direct URL
  returns 200. Directory listing is off, but the Updraft `log.*.txt` files are
  also downloadable.
- **May 2026 media is gone.** 16 attachments dated 2026-05 have database rows
  but no files, including the Council Standing Rules PDF, the Reflections
  winners PDFs and several homepage slider images. They are not in the
  `bloblwptsa` container or the `wordpress-backups` container. The Updraft
  set from 2026-05-17 15:16 can hold at most the 8 uploaded before then; the
  other 8 need re-uploading from their authors.
- **Duplicate events.** 7 past events (April and May 2026) were each created
  twice by an Outlook sync on 2026-04-29. No upcoming events are duplicated.
- Many posts have no featured image, so the new theme needs placeholders.
