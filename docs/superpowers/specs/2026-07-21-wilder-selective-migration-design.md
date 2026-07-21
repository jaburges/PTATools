# Wilder PTSA → wp.theburgessfamily.us selective migration

**Status:** Approved (design conversation 2026-07-21)  
**Source:** Live Azure WordPress at `https://wilderptsa.azurewebsites.net` (custom domain `wilderptsa.net` currently serves a static shell and is not used as source)  
**Target:** `https://wp.theburgessfamily.us` (PTA Tools + WooCommerce already installed and active)  
**Cutover:** One-time selective copy. Azure Wilder stays running and authoritative until a later cutover decision.

## Goal

Move a **clean subset** of Wilder content and store history onto the new host without cloning legacy themes, page builders, unused PTA operational data, or orphan media.

## Non-goals

- Full site clone / DB overwrite of the target
- Migrating Beaver Builder, Kadence, or other layout builders (page **text/HTML** only)
- Migrating unused media
- Assigning menus to header/footer theme locations
- Migrating unused PTA Tools operational data (newsletter history, OneDrive queues, calendar cache/mappings not required by kept pages, backup job tables, email logs/queues, unused volunteer sheets, etc.)
- Spinning down Azure Wilder as part of this work

## Scope — keep

| Area | Rule |
|------|------|
| Pages | All **published** pages; strip BB/Kadence layout meta; keep readable `post_content` (plus featured image linkage; portable SEO titles only if already in standard post meta) |
| Posts | All **published** posts; same content treatment as pages |
| WooCommerce | **All** products including drafts and **auction** products; auction bid history tables if present (`wp_azure_auction_bids` and related); **all** orders; coupons; product taxonomies; order/product meta; PTA product-field tables and related store child/meta tables needed for sales |
| Users | All users **except spam-looking** accounts (dry-run list approved before omit); preserve roles and usable credentials/hashes |
| Menus | Menu items and hierarchy; remap internal URLs to the new domain; **do not** assign header/footer locations; skip header/footer widget areas |
| Media | Only attachments **attached to or referenced by** kept published pages/posts or store (products/auctions/orders as needed for product images) |

## Scope — exclude

- Draft / private / trash pages (and non-published posts)
- Orphan / unused uploads
- Header and footer theme chrome (widgets, template parts, menu location assignments for header/footer)
- Legacy plugins and themes from Wilder (target keeps its own theme + PTA Tools + Woo)
- Unused PTA/Azure plugin operational tables and history listed under Non-goals

## Architecture

```
┌─────────────────────────────┐
│ Wilder Azure (live WP)      │
│ wilderptsa.azurewebsites.net│
└──────────────┬──────────────┘
               │ export (DB slices + used media)
               ▼
┌─────────────────────────────┐
│ Local transform workspace   │
│ - filter posts/users/media  │
│ - strip builder meta        │
│ - spam-user dry-run         │
│ - URL remap                 │
└──────────────┬──────────────┘
               │ import (WP-CLI / SQL / REST + SFTP)
               ▼
┌─────────────────────────────┐
│ Target host                 │
│ wp.theburgessfamily.us      │
│ (theme + PTA Tools + Woo)   │
└─────────────────────────────┘
```

### Components

1. **Source access** — Azure App Service + MySQL (and/or latest blob DB dump `2026-07-13` only as fallback if live export fails). Prefer live export so post–July-13 changes are included.
2. **Transform workspace** — Local staging (Docker or existing tooling) holding a filtered dump; never overwrite the target DB wholesale.
3. **Content filter** — Select published pages/posts; strip `_fl_builder*`, Kadence layout meta, and similar; leave plain/HTML content.
4. **Store pack** — Products (all statuses needed for drafts + auctions), orders (all), coupons, WC/PTA product-field related tables.
5. **User pack** — Full user export minus spam dry-run rejects.
6. **Menu pack** — `nav_menu` terms + `nav_menu_item` posts + hierarchy; no theme_mod location assignment for header/footer.
7. **Media pack** — ID set derived from featured images, galleries, content URLs, and product image meta for kept content; files copied via filesystem/SFTP (see Constraints).
8. **Import** — Merge into target without wiping existing starter content unless it conflicts (slug collisions resolved explicitly).

## Constraints

- Target REST works via `?rest_route=` (pretty permalinks for `/wp-json/` may 404).
- Target `max_upload_size` is currently **2 MB** — media must be copied via **SFTP/filesystem** (or the host limit must be raised before WP media upload).
- Do not commit diagnostic API keys or DB credentials to the repo.
- Rotate the target diagnostics API key after migration work completes.

## Open prerequisite

- **SFTP (or equivalent) access** to the target host `wp-content/uploads` (or agreement to raise PHP upload limits). Without one of these, used media cannot be imported reliably.

## Spam-user filter

Reuse PTA Tools spam heuristics (same family as `spam-user-audit`): suspicious email/name patterns, never activated / no commerce history where applicable. Produce a **dry-run list** for operator approval; only then omit those users from import. Buyers, admins, and non-spam parents stay.

## URL remapping

- Replace `https://wilderptsa.net` and `https://wilderptsa.azurewebsites.net` with `https://wp.theburgessfamily.us` in imported content, menus, and attachment URLs.
- Attachment IDs will change on import; product/featured-image meta must be remapped after media import.

## Verification

- Published page count (source vs target) and spot-check of text (no BB shortcodes/layout junk)
- Product count (including drafts/auctions) and sample product images
- Order count and a sample of recent + old orders
- User count ≈ source − approved spam list; spot-check login hash / role
- Menus present with hierarchy; header/footer locations empty/unassigned
- Unused PTA tables empty or near-empty on target (no newsletter/OneDrive junk imported)
- Azure Wilder still responding on `wilderptsa.azurewebsites.net`

## Risks

| Risk | Mitigation |
|------|------------|
| Custom domain DNS broken on Wilder | Use `azurewebsites.net` as source |
| 2 MB upload limit | SFTP / raise limit |
| Builder content empty after strip | Inspect BB HTML storage; fall back to rendered HTML extract if `post_content` is empty |
| Slug collisions on target | Rename or replace starter pages explicitly |
| Attachment ID drift | Remap `_thumbnail_id` / Woo image meta after media import |
| Spam filter false positives | Dry-run approval gate |

## Success criteria

- Store history intact (products + auctions + all orders + related sales meta)
- All published page text readable on the new theme
- Menus importable with hierarchy; operator assigns locations later
- No bulk unused PTA operational data on target
- Spam-looking users omitted only after dry-run approval
- Wilder Azure left running
