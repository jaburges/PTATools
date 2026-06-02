# PTA Tools Plugin — To-Do

## Calendar / pta_event enhancements

### Auto-attach images to Outlook-synced pta_event posts
**Priority:** Low  
**Status:** Parked (no current use case)

Today, Outlook events don't include images, so synced `pta_event` posts have no featured image unless an editor uploads one manually. The single-event template + archive list already render image UI when present (`has_post_thumbnail` gated), so adding manual images already "just works".

If a future workflow needs Outlook-supplied images, build this:

- During `Azure_Calendar_Sync_Engine::upsert_event()`:
  - When the processed Graph event has `attachments` containing image MIME types (or the body HTML contains an `<img src="cid:...">`), sideload the first image to the WP media library via `media_sideload_image()`.
  - Set as featured image via `set_post_thumbnail($post_id, $attachment_id)`.
- During subsequent syncs, only replace the featured image if the Outlook attachment changed (compare hash or last-modified).
- Defensive: never overwrite an admin-uploaded featured image — gate on absence of a `_pta_image_from_outlook` flag.

Use case to wait for: Outlook events that systematically include event flyers as the first inline image (e.g. a calendar admin who's already in the habit of pasting the flyer into the event body).

## Platform / Deployment (Danger Zone + Releases + Marketplace)

### Danger Zone — Main Settings (Platform section)
**Priority:** High  
**Status:** Planned

Add a **Platform → Danger Zone** section on PTA Tools Main Settings (or a dedicated Platform tab). Two actions:

#### 1. Sync Production DB → Staging DB
**Status:** Implemented (v3.100) — PTA Tools → Main Settings → Platform Danger Zone

#### 2. Mark PTA Tools as Production Release
**Status:** Cancelled — use `docs/skills/promote-prod/SKILL.md` (`/promote-prod`) + `release-production.yml` instead (no WP UI).

**Related code changes (release pipeline):**
- [x] `azure_plugin_best_github_release()` — skip `prerelease` releases
- [x] Split into `release-beta.yml` + `release-production.yml`
- [x] `deploy-staging.yml` — deploy beta/rc tags to staging
- [x] CONTRIBUTING.md — agents must never create non-prerelease releases without explicit user request
- [x] Cursor skill `/promote-prod`

---

### Azure Marketplace Offering — WordPress on Azure (PTA Tools stack)
**Priority:** Medium (strategic)  
**Status:** Idea / planning

Publish an **Azure Marketplace** solution template that reproduces the wilderptsa.net App Service architecture for other PTAs, with minimal manual configuration.

**Template should include (parameterized):**
- App Service Plan (Premium v3 — required for deployment slots)
- WordPress on App Service (managed image) + **optional staging slot** (`enableStagingSlot` parameter, default `true`)
- Azure Database for MySQL Flexible Server (+ separate staging database when slot enabled)
- Azure Blob Storage (prod + staging containers when slot enabled)
- Azure Cache for Redis
- Azure Front Door (AFD) profile + endpoint + custom domain hookup
- Application Insights + availability test
- Entra ID **App Registration** (or instructions to use existing) — pass `clientId`, `tenantId` into OOBE
- Managed identities + RBAC for storage, MySQL, Key Vault as today

**OOBE improvements (setup wizard):**
- Accept **App Registration** (`clientId`, `tenantId`, optional secret) and **Storage account** connection details as wizard inputs.
- **Test** buttons (like existing “Test Credentials”) for: Entra auth, blob write, MySQL connect, Graph calendar read.
- When `enableStagingSlot=false`, hide staging-only Danger Zone actions and skip staging DB/container provisioning.

**Custom domain / DNS flow:**
- Marketplace deploy collects intended custom domain (e.g. `ptsa.example.org`).
- Post-deploy guide (and/or wizard step) outputs:
  1. AFD hostname / validation TXT record for domain proof
  2. CNAME target for www (`www` → AFD endpoint)
  3. Apex redirect options (AFD apex, or registrar forwarding)
- Clear copy: *“Complete domain verification at GoDaddy / Cloudflare / your registrar before going live.”*
- Link to registrar-specific docs (GoDaddy, Namecheap, Cloudflare).

**Deliverables:**
- [ ] ARM/Bicep/Terraform template + `createUiDefinition.json` for Marketplace
- [ ] Marketplace listing copy + pricing (BYOL / Azure resource charges only)
- [ ] OOBE wizard updates in plugin to consume template outputs (`mainTemplate.json` outputs → WP options)
- [ ] Runbook: fresh install → domain verified → first beta plugin → production release
- [ ] Document slot-sticky settings (`DATABASE_NAME`, `BLOB_CONTAINER_NAME`, `AFD_*`) in install guide

---

## Planned Modules

### Page Holding
**Priority:** Medium
**Status:** Planned

A module that allows pages to be put into a "holding" state with a full-viewport overlay banner, without changing the publish status. Ideal for seasonal event pages (e.g. Art Night, Carnival) that become outdated after the event ends.

**Key features:**
- Meta-based toggle per page — page stays Published (no broken links, menus, or SEO impact)
- Full-viewport sticky banner overlay with customizable message (e.g. "This event has been and gone this year, but we'll be back with more fun next year!")
- Page content remains visible below the banner if the user scrolls
- Configurable banner background color/image, CTA button text and link
- Default message template in module settings, with per-page override
- Optional: auto-enable holding mode after a configured end date
- Dashboard widget or list view showing all pages currently in holding mode
- Bulk toggle from PTA Tools admin

**Implementation notes:**
- New module toggle "Page Holding" on the main PTA Tools dashboard (default off)
- Per-page meta box in the WordPress editor with toggle + message fields
- Frontend: lightweight CSS overlay (position sticky, 100vh), no JS dependencies
- No new database tables needed — uses post meta only

---

## Backlog / Ideas

_Add future module ideas and improvements here._

---

## Bugs / Investigations

### Calendar Embed module: only 3 of 4 calendars visible
**Priority:** Medium
**Status:** Open

On the Calendar Embed module page, the available-calendars list shows 3 cards. There are actually 4 calendars on the `calendar@wilderptsa.net` shared mailbox — a new **Theater** calendar was added but is not appearing.

**Open questions:**
- Should newly added calendars on `calendar@wilderptsa.net` auto-discover and appear in the module UI? (Expected behavior per Calendar Embed module docs.)
- Is the discovery cached? If so, where, and how do we force a refresh?
- Can the UI grid handle 4 cards across, or does it wrap / truncate at 3? (Possible CSS / responsive layout issue masking a discovery that's actually working.)

**Investigation steps:**
- Check the Calendar Embed module settings page — is there a "Refresh calendars" / "Re-sync" button?
- Inspect the underlying Microsoft Graph call result (`class-calendar-graph-api.php`) to see whether the Theater calendar is returned by Graph but filtered out by the UI, or not returned at all (permissions / sharing).
- Verify the new Theater calendar is shared with the same delegate / has the same access level as the other 3 in `calendar@wilderptsa.net`.
- Test the admin UI grid with 4+ cards to confirm the layout supports it.
