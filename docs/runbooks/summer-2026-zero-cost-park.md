# Summer 2026 — park WordPress, host free placeholder

Goal: bring `PTSAWebsite` spend near **$0** for summer while `wilderptsa.net` shows a single hero image page. Full WordPress can be rebuilt in the fall from backups.

## What costs money today (June 2026 Cost Management)

| Resource | Approx $/mo | Action for ~$0 |
|----------|-------------|----------------|
| MySQL `wilderptsa-c20b298090-wpdbserver` (B1ms, 134 GB) | ~$126 | **Delete** after final DB dump to blob |
| App Service Plan `ASP-PTSAWebsite-a9e9` (P1v3) | ~$115 | **Delete** (takes `wilderptsa` + staging with it) |
| Redis `wilderptsa-redis` Basic C0 | ~$27 | **Delete** |
| App Insights `wilderptsa-appinsights` (+ LA workspace) | ~$22 | **Delete** or stop ingestion |
| Front Door `WilderPTSAAFD` Standard | ~$6 | **Delete** after DNS points elsewhere |
| MySQL `wilderptsa-wpdb-small` (B1ms, 64 GB) | ~$45 full month | **Delete** after dump (prod was still on old host as of 2026-07-13) |
| Storage `wilderptsac20b298091` | ~$0.10 | Keep (backups) — near free |
| ACS email endpoints | ~$0 | Keep if you still need email; else delete later |
| VNet / private DNS / Logic App / identity | ~$1 | Delete with MySQL private link stack |

**June total for RG: ~$297.**

Stopping alone does **not** hit $0 (MySQL Flexible Server still bills compute+storage when stopped for limited windows; App Service Plan bills whether apps are stopped).

## Free placeholder (already in repo)

Folder: `summer-placeholder/`

- `index.html` — brand + short summer message
- `Wilder-placeholder.png` — homepage hero (1408×768) copied from live site

### Host options (pick one)

1. **GitHub Pages** (simplest $0)  
   - Push `summer-placeholder/` to a branch or `/docs`  
   - Enable Pages on the repo  
   - Point `wilderptsa.net` CNAME / A records at GitHub Pages  

2. **Azure Static Web Apps — Free tier**  
   - Create SWA in a cheap/free RG  
   - Deploy the folder  
   - Custom domain on SWA  

3. **Storage static website** on existing `wilderptsac20b298091`  
   - Enable static website, upload files  
   - Near-$0 blob cost; still need DNS / CDN if you want the custom domain cleanly  

## Before deleting paid stack

1. Confirm latest DB dump exists under `wilderptsac20b298091` / `wordpress-backups`.  
2. Optional: zip Kudu `wp-content` (themes/plugins) to blob — media may already be incomplete.  
3. Note custom domain / Front Door / ACS DNS so fall rebuild is easier.  
4. Switch DNS to the free placeholder **before** or **immediately after** deleting App Service / Front Door so the domain is not dead longer than necessary.

## Suggested delete order (after DNS cutover)

1. Stop + delete web app / plan  
2. Delete both MySQL flexible servers  
3. Delete Redis  
4. Delete Front Door profile  
5. Delete App Insights + availability test + Log Analytics (or leave LA empty)  
6. Delete one-shot container instances if any remain  
7. Keep storage + maybe ACS until domain email story is settled  

## Rebuild later

Restore MySQL from blob dump, new App Service + plan, redeploy plugin, restore media from OneDrive/backups, reattach custom domain.
