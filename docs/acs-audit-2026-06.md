# Azure Communication Services (ACS) audit — wilderptsa.net

**Date:** 2026-06-29
**Scope:** Subscription `97f6936d-7300-4a49-a2ad-cbfee3b28e00`
**Type:** Read-only investigation. No Azure resource, App Service setting, or plugin config was modified.
**Question:** Why are there TWO ACS setups? Which one does the WordPress plugin / site actually use? Which to keep vs decommission?

---

## TL;DR

- There are **two** complete ACS setups (Communication Service + Email Communication Service + domain) in the subscription. No third exists.
- **Setup A — `wilderptsa-c20b298090-*` (RG `PTSAWebsite`)** was **auto-provisioned by the WordPress-on-Azure-App-Service "Email" integration** in March 2026. It uses a throwaway Azure-managed domain and is wired to the App Service via a **user-assigned managed identity**. **It is the one that actually carried email traffic** (~65 deliveries in the last 30 days, last activity ~June 10).
- **Setup B — `WilderPTSA*` (RG `PTSA-Communications`)** was **created manually first** (Sept 2025) with a properly verified **custom domain `wilderptsa.net`**. **It has never sent anything** — 0 API requests / 0 deliveries in 30 days, and nothing references it.
- The **PTA Tools "Azure Plugin" itself does not send through either ACS by default.** Its Email Router sends via **Microsoft Graph** (transactional) and **AcyMailing** (newsletter). ACS is a *supported but unused* provider option in the router. The ACS traffic that did flow was driven by the **App Service's own built-in ACS email plugin**, not by PTA Tools.
- **Keep:** Setup A (PTSAWebsite) — it is wired into the App Service and was recently live. **Safe to decommission:** Setup B (PTSA-Communications) — orphaned and unused.

---

## 1. All ACS / Email Communication Services in the subscription

Two of each. Nothing else found via `az communication list`, `az resource list --resource-type Microsoft.Communication/communicationServices`, and `...emailServices`.

| # | Communication Service | Email Communication Service | RG | Created | Created by |
|---|---|---|---|---|---|
| A | `wilderptsa-c20b298090-acsendpoint` | `wilderptsa-c20b298090-emailacsendpoint` | `PTSAWebsite` | 2026-03-31 | jamieb@wilderptsa.net |
| B | `WilderPTSAcommsservice` | `WilderPTSAemailservice` | `PTSA-Communications` | 2025-09-10 | admin@wilderptsa.net |

### Domains & verification

| Setup | Domain | Type | Sender domain | Domain | SPF | DKIM | DKIM2 | DMARC |
|---|---|---|---|---|---|---|---|---|
| A | `AzureManagedDomain` | AzureManaged | `c125b645-add7-46fc-888b-b16640df933b.azurecomm.net` (sender `donotreply@…azurecomm.net`) | Verified | Verified | Verified | Verified | Verified |
| B | `wilderptsa.net` | CustomerManaged (custom) | `wilderptsa.net` | Verified | Verified | Verified | Verified | NotStarted |

- Setup B's SPF record is `v=spf1 include:spf.protection.outlook.com -all` and its domain TXT/CNAME (DKIM) records are live and verified in DNS — i.e. someone did the full custom-domain setup, then never used it.

### Endpoints / key fingerprints (secrets masked)

| Setup | Endpoint host | Primary key (last 4) |
|---|---|---|
| A | `wilderptsa-c20b298090-acsendpoint.unitedstates.communication.azure.com` | `…keBV` |
| B | `wilderptsacommsservice.unitedstates.communication.azure.com` | `…oIa5` |

Both data-located in **United States**.

---

## 2. Who actually uses which ACS

### App Service wiring (the live consumer)

`az webapp config appsettings list -g PTSAWebsite -n wilderptsa` shows:

- `WP_EMAIL_CONNECTION_STRING` = `endpoint=https://wilderptsa-c20b298090-acsendpoint.unitedstates.communication.azure.com/…` — **endpoint-only, no access key** (length 163, no `accesskey=` segment).
- `ENABLE_EMAIL_MANAGED_IDENTITY = true` (also `ENABLE_BLOB_MANAGED_IDENTITY`, `ENABLE_MYSQL_MANAGED_IDENTITY`).

The App Service runs as **user-assigned managed identity `wilderptsa-c20b298090-wpidentity`** (clientId `55f7312e-…`, principalId `213259a9-…`). Its role assignments:

- **`Custom Email Contributor Role` → `wilderptsa-c20b298090-acsendpoint`** (Setup A) ← email send rights
- `Storage Blob Data Contributor` → `wilderptsac20b298091`
- `CDN Profile Contributor` → `WilderPTSAAFD`

So the App Service's **built-in WordPress ACS email integration** authenticates to **Setup A** via managed identity and sends from the managed `…azurecomm.net` domain. **Setup B is not referenced by any App Service setting or role assignment.**

### PTA Tools plugin behaviour (`Azure Plugin/`)

- `includes/class-email-mailer.php` supports three transports: `graph_api`, `hve` (SMTP), and `acs`. The ACS path reads its config from **WP options** — `email_acs_endpoint`, `email_acs_connection_string`, `email_acs_access_key`, `email_acs_from_email` — **not** from the App Service env var.
- `includes/class-email-router.php` (since v3.123) hooks `pre_wp_mail` at **priority 1** and routes by `From:` header. The **seeded default routing table** is:
  - Newsletter `news@` → **AcyMailing** (`acy`, pass-through)
  - WooCommerce `shop@` → **Microsoft Graph** (`graph`)
  - Default `*` → **Microsoft Graph** (`graph`)
  - **No route uses `acs`.**
- The router's own comments explicitly call out the coexistence of "the ACS App Service email plugin" as a competing `pre_wp_mail` interceptor at priority 10, and warn that in permissive (non-strict) mode a failed Graph send falls through and the App Service ACS plugin can deliver instead (with its own `donotreply@…azurecomm.net` sender). That is the most likely explanation for the ACS deliveries observed below.

> Caveat: the live WP options `email_acs_*` and `azure_email_routing` live in the site DB. The Kudu runtime has no WP-CLI/PHP/MySQL (per the deployment rules), so they couldn't be read directly. The conclusions above rely on the seeded defaults + code + Azure-side traffic/identity evidence, which are consistent. To be 100% certain of the *live* routing table, read **PTA Tools → Emails → Sending** in wp-admin.

### Traffic evidence (last 30 days)

| Setup | ApiRequests | DeliveryStatusUpdate | Most recent activity |
|---|---|---|---|
| A — `wilderptsa-c20b298090-acsendpoint` | **71** | **65** | bursts Jun 1–10 (peak 21/day Jun 8) |
| B — `WilderPTSAcommsservice` | **0** | **0** | none |

Setup A's traffic **stopped after ~June 10** — consistent with the documented pivot to AcyMailing + Graph after ACS rate-limiting (and the 2026-06-15 deploy incident). Setup B has **never** carried traffic.

---

## 3. Why two setups exist (inference)

- **Setup B (PTSA-Communications, Sept 2025)** was the **first, manual attempt** — created by `admin@wilderptsa.net`, with the effort of verifying the **custom `wilderptsa.net` domain** (SPF/DKIM in DNS). The intent was clearly to send branded `@wilderptsa.net` mail via ACS. It was put in its own `PTSA-Communications` resource group and then **abandoned** (zero traffic). It was never connected to the App Service or the plugin.
- **Setup A (PTSAWebsite, March 2026)** was **auto-created by the WordPress-on-App-Service "Email" feature**. The `c20b298090` hash suffix is the App Service offering's naming convention — the same stamp appears on its sibling auto-provisioned resources: the managed identity `wilderptsa-c20b298090-wpidentity` and the storage account `wilderptsac20b298091`. It came with the throwaway `…azurecomm.net` managed domain and was auto-wired via managed identity. This is the one that actually carried mail.

In short: **B = early manual custom-domain experiment that was never adopted; A = the platform-provisioned one that got wired in and used.**

---

## 4. Recommendation

### Keep (for now): Setup A — `wilderptsa-c20b298090-*` (RG `PTSAWebsite`)
- It is **referenced by App Service settings** (`WP_EMAIL_CONNECTION_STRING`) and a **managed-identity role assignment**, and was **recently active**.
- Deleting it could break the App Service email integration / the permissive-mode fallback path for WordPress transactional mail.
- If the longer-term goal is to fully retire ACS in favour of Graph + AcyMailing: first confirm transactional WordPress mail is reliably delivered by Graph (e.g. enable the router's **strict mode** and verify), *then* this can be retired as a follow-up. That is out of scope for this read-only audit.

### Safe to decommission: Setup B — `WilderPTSA*` (RG `PTSA-Communications`)
- **Zero traffic** in 30 days; **not referenced** by any App Service setting, managed identity, or the plugin's routing table. It is orphaned/redundant.
- **Caveats before deletion (do later, with confirmation):**
  1. It owns the **verified custom domain `wilderptsa.net`** with live SPF/DKIM DNS records. Confirm nobody plans to send branded `@wilderptsa.net` mail via ACS before removing it. (The DNS records can be left in place or cleaned up separately.)
  2. The **`PTSA-Communications` resource group** appears to exist only for this — verify it contains nothing else before deleting the whole RG.
  3. Cost saving is **near-zero** (ACS is pay-per-use and this one is idle); the benefit is purely **reducing confusion / drift**.

### Do NOT
- Do not delete or modify anything as part of this audit — this document is a recommendation only.

---

## Appendix — commands used (read-only)

```bash
az account set --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00
az communication list -o json
az resource list --resource-type Microsoft.Communication/communicationServices -o table
az resource list --resource-type Microsoft.Communication/emailServices -o table
az rest --method get --url ".../emailServices/<name>/domains?api-version=2023-04-01-preview"
az communication list-key --name <name> --resource-group <rg>          # secrets masked above
az webapp config appsettings list -g PTSAWebsite -n wilderptsa          # secrets masked above
az webapp identity show -g PTSAWebsite -n wilderptsa
az role assignment list --assignee 213259a9-49f7-4be5-9304-564916fc1015 --all
az monitor metrics list --resource <acs> --metrics ApiRequests DeliveryStatusUpdate --interval P1D
```

---

## Outcome — custom-domain migration (2026-06-29)

**Goal:** move the verified custom domain `wilderptsa.net` from the orphan (Setup B) onto the kept service (Setup A), verify + link it, then delete the orphan.

**Status: BLOCKED — awaiting external DNS (Cloudflare).** The orphan was **NOT** deleted.

### DNS hosting
`wilderptsa.net` is hosted on **Cloudflare** (`cora.ns.cloudflare.com` / `everton.ns.cloudflare.com`). `az network dns zone list` returned **no Azure zones**, so records cannot be published via Azure CLI — they must be added in Cloudflare by the user.

### Actions taken (non-destructive, infra-only)
- Created custom domain `wilderptsa.net` on the **kept** email service `wilderptsa-c20b298090-emailacsendpoint` (RG `PTSAWebsite`), `CustomerManaged`. `provisioningState: Succeeded`.
- Initiated verification; current states on the kept domain:
  - **SPF → Verified**, **DKIM → Verified**, **DKIM2 → Verified** (their records already exist in Cloudflare and are identical across ACS resources).
  - **Domain (TXT) → NotStarted** — needs a new value published (see below).
  - DMARC → NotStarted (same as orphan; not required for sending).
- App Service settings, managed identity, role assignments, the kept managed domain, and all plugin files were **left untouched**.

### Required DNS record the user must add in Cloudflare
| Host | Type | Value | TTL |
|---|---|---|---|
| `wilderptsa.net` (`@`) | TXT | `ms-domain-verification=1183d665-c65b-4304-a0d4-1769269dbfd8` | 3600 |

- This is a **new** per-resource value; the live DNS only has the orphan's old `ms-domain-verification=4501263c-aa0c-4429-a525-b5b9dfe50a8b`.
- **Do not modify SPF** — the live `v=spf1 include:spf.protection.outlook.com include:spf.acymailing.com include:mailgun.org -all` already satisfies ACS and must keep the AcyMailing/Mailgun includes.

### Remaining steps (after the TXT propagates)
1. `az communication email domain initiate-verification … --verification-type Domain`, poll `domain show` until `Domain → Verified`.
2. Link `wilderptsa.net` into the kept Communication Service `wilderptsa-c20b298090-acsendpoint` `linkedDomains` **alongside** the existing managed domain (do not remove the managed domain).
3. Only then delete the orphan (Setup B): the custom domain, `WilderPTSAemailservice`, `WilderPTSAcommsservice` in RG `PTSA-Communications`; delete the RG only if it ends up empty.

### Stale DNS cleanup note (optional, do later)
After the orphan is deleted, the live TXT `ms-domain-verification=4501263c-aa0c-4429-a525-b5b9dfe50a8b` becomes stale and can be removed. Leave the DKIM/DKIM2 CNAMEs — the kept service depends on them.
