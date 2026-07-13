# Summer park + local build + school-year redeploy

## Reality check: “pause” ≠ $0 on Azure

| Resource | Stop / pause behavior | Verdict |
|----------|----------------------|---------|
| App Service Plan (P1v3) | App can stop; **plan still bills** | Must delete plan for real savings |
| MySQL Flexible Server | Can stop ≤30 days; then auto-starts; storage still bills | Not a summer-long pause |
| Redis | No meaningful pause | Delete |
| Front Door / App Insights | Always-on SKUs | Delete for summer |
| Storage (blob backups) | Pennies | **Keep** — this is the redeploy seed |

So the workable model is: **park public site on free static hosting**, **keep backups in cheap storage**, **delete paid compute**, **build WordPress elsewhere for $0 Azure**, **one scripted “school reopen” path**.

---

## Target architecture (summer)

```text
wilderptsa.net ──DNS──► Free static placeholder (GitHub Pages or Azure SWA Free)
                              │
                              ▼
                     summer-placeholder/
                     (hero image + short message)

Azure PTSAWebsite (near $0)
  └── Storage account  (DB dumps, optional wp-content zip, media archive)
  └── Optional: ACS email (if you still send mail) — else delete later

Your laptop / personal host (dev)
  └── Local WordPress + PTA Tools plugin from this repo
  └── Theme / pages / Woo experiments — no Azure spend
```

**Estimated summer Azure:** ~$0–2/mo (blob) if everything else is deleted.  
**Public site:** free.  
**Dev site:** $0 if local.

---

## Cost tiers (pick deliberately)

### Tier 0 — Recommended: near $0 + free placeholder + local WP
- Delete: App Service + plan, both MySQL servers, Redis, Front Door, App Insights, Log Analytics, availability test, VNet/private DNS (after MySQL gone), leftover ACIs.
- Keep: storage account with verified backups.
- Public: GitHub Pages (or SWA Free) + DNS cutover.
- Dev: Local by Flywheel **or** Docker Compose in this repo.
- Fall: run “redeploy” script/workflow (below).

### Tier 1 — “Cheapest keep WordPress alive in Azure” (~$40–70/mo)
Only if you must keep a live WP URL in Azure all summer:
- One MySQL B1ms + minimal storage, one **B1** Linux App Service (no Premium, no slot, no Redis, no Front Door).
- Still far from $0; worse than Tier 0 for your goals.

### Tier 2 — Stop-only (not recommended)
- Stop web app / MySQL stop → still ~$150+/mo while stopped windows expire and plan bills.

**Recommendation: Tier 0.**

---

## Build WordPress with $0 Azure (dev path)

Best options for iterating the site while Azure is parked:

| Option | Cost | Fit |
|--------|------|-----|
| **Local WP** (Local by Flywheel) | $0 | Best UX on Mac; import DB dump; symlink or copy `Azure Plugin/` from this repo |
| **Docker Compose** in repo | $0 | Reproducible; good for “clone + up”; we can add `docker-compose.yml` + seed instructions |
| Personal cheap shared host | ~$5–15/mo | Only if you need a public URL for others to review |
| WordPress.com / managed hosts | varies | Often awkward for custom plugin + Woo |

**Practical workflow:**
1. Final Azure dump → blob (DB + optional `wp-content` excluding huge caches).
2. Import dump into Local/Docker.
3. Develop theme/pages/plugin against local site.
4. Commit plugin (and any theme) to git as usual.
5. Fall redeploy restores **last good Azure backup** (or a fresh export from local if that’s the new source of truth — decide intentionally).

**Source of truth for fall:** pick one before park:
- **A)** Azure dump is canonical; local is disposable experiments, or  
- **B)** Local becomes canonical; fall import is from a local export you keep in blob/OneDrive.

For a rebuild summer, **B** is often cleaner if the live site is already messy (media gaps, dual MySQL history).

---

## Public placeholder

Already prepared: `summer-placeholder/` (`index.html` + `Wilder-placeholder.png`).

**Preferred host:** GitHub Pages on this repo (branch `gh-pages` or `/docs`).  
**DNS:** point `wilderptsa.net` / `www` at Pages (or SWA Free custom domain). Do this **before** deleting Front Door / App Service so downtime is minutes, not days.

---

## “Redeploy button” for school time

Today there is **no one-click recreate** — infra was grown by hand; only plugin deploy is scripted. The summer project should add a real button:

### Phase 1 (enough for August) — operator script
`scripts/school-year-redeploy.sh` (or documented az sequence) that:
1. Creates MySQL Flexible Server (B1ms, 32–64 GB) in `PTSAWebsite`
2. Creates App Service Plan (**B1** or **P0v3** first — not P1v3) + Linux PHP web app
3. Restores DB from known blob path
4. Deploys WordPress + `Azure Plugin` zip
5. Sets app settings (`DATABASE_*`, managed identity if used)
6. Prints DNS cutover checklist (A/CNAME off Pages → App Service / Front Door if you re-add CDN later)

### Phase 2 (nice) — GitHub Action “Redeploy school year”
- `workflow_dispatch` only  
- Inputs: confirm, SKU size, restore blob URI  
- Uses OIDC / service principal  
- Same steps as the script  

That **is** the redeploy button: Actions → Run workflow.

### What we deliberately do **not** restore on day 1
- Redis (add later if needed)
- Front Door (add if you need WAF/CDN)
- Staging slot + P1v3 (scale up only after school traffic returns)
- Second MySQL server

---

## Park sequence (when you say go)

1. Verify blob backups (DB + optional code/media).
2. Export anything still only on Kudu that you care about.
3. Publish `summer-placeholder` to GitHub Pages / SWA.
4. Cut DNS to placeholder.
5. Delete paid stack (order in `summer-2026-zero-cost-park.md`).
6. Stand up Local/Docker WP from dump or fresh install + plugin.
7. Land `school-year-redeploy` script + short runbook; dry-run create/destroy once on a throwaway name if budget allows a one-day test.

---

## What to decide before we execute

1. **Public host:** GitHub Pages vs Azure SWA Free?  
2. **Dev host:** Local WP vs Docker in-repo?  
3. **Fall source of truth:** last Azure dump vs local export?  
4. **ACS email over summer:** keep (~$0) or delete?  
5. **OK to delete both MySQL servers** after backup verification (irreversible without the dump)?
