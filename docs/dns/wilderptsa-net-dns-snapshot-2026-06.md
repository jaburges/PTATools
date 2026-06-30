# DNS Snapshot — `wilderptsa.net` (2026‑06)

**Purpose:** Read‑only, complete inventory of the current public DNS for `wilderptsa.net`, captured so the zone can be **fully rebuilt in a new DNS provider** if the current Cloudflare zone lapses or nameservers are repointed at GoDaddy.

**Why this matters:** DNS is currently authoritative on **Cloudflare** (`cora.ns.cloudflare.com`, `everton.ns.cloudflare.com`) under an account the owner has **lost access to** (the managing company was retired). **GoDaddy is the registrar.** This file is insurance — nothing here was changed.

- **Capture method:** `dig` against the authoritative Cloudflare nameserver (`@cora.ns.cloudflare.com`) cross‑checked against public resolvers (`@1.1.1.1`, `@8.8.8.8`).
- **Capture date:** 2026‑06‑29
- **Changes made:** NONE. This was purely enumeration via public resolvers. No Cloudflare/GoDaddy login was attempted.
- **DNSSEC:** Not enabled (no `DS` at parent, no `DNSKEY` at apex). Migration does not require a DS rollover.

---

## 1. Apex — `wilderptsa.net`

| Name | Type | TTL | Value | Notes |
|---|---|---|---|---|
| `wilderptsa.net` | A | 10 | `150.171.110.146` (auth) / `150.171.110.147` (1.1.1.1) / `150.171.109.150` (8.8.8.8) | **Azure Front Door anycast.** Apex is **CNAME‑flattened** by Cloudflare to the AFD endpoint — there is no stored A record; these are AFD anycast IPs that vary per resolver/PoP (see discrepancy note). |
| `wilderptsa.net` | AAAA | 10 | `2603:1061:14:90::1` (auth & 8.8.8.8) / `2620:1ec:48:1::70`, `2620:1ec:29:1::70` (1.1.1.1) | Also Azure Front Door anycast IPv6 (flattened). |
| `wilderptsa.net` | NS | 86400 | `cora.ns.cloudflare.com`, `everton.ns.cloudflare.com` | Authoritative nameservers (Cloudflare). Set at GoDaddy. |
| `wilderptsa.net` | SOA | 1800 | `cora.ns.cloudflare.com. dns.cloudflare.com. 2406273658 10000 2400 604800 1800` | Cloudflare‑managed; serial/values regenerate in any new provider. |
| `wilderptsa.net` | MX | 300 | `0 wilderptsa-net.mail.protection.outlook.com.` | **Microsoft 365 / Exchange Online inbound mail.** Load‑bearing for receiving email. |
| `wilderptsa.net` | TXT (SPF) | 300 | `v=spf1 include:spf.protection.outlook.com include:spf.acymailing.com include:mailgun.org -all` | **Load‑bearing SPF.** Authorizes M365, AcyMailing, Mailgun. |
| `wilderptsa.net` | TXT | 300 | `ms-domain-verification=4501263c-aa0c-4429-a525-b5b9dfe50a8b` | **OLD / orphan** Microsoft domain‑verification value. Carry over as‑is to avoid breaking anything, but see ACS note below — a **new** value must be added. |
| `wilderptsa.net` | TXT | 300 | `stripe-verification=3A33E15C443C759CC516C51380E8515FEA389216D549E49436963AA390047191` | Stripe domain verification. |
| `wilderptsa.net` | CAA | — | *(none)* | No CAA published. AFD/M365 cert issuance currently unrestricted. |
| `wilderptsa.net` | CNAME | — | *(none — apex is flattened)* | Cloudflare flattens the apex to Front Door. A new provider must support **ALIAS/ANAME/CNAME‑flattening** at the apex, or use **Azure DNS** (alias record to AFD). |

---

## 2. Subdomains (CNAME / A)

| Name | Type | TTL | Value / Target | Purpose |
|---|---|---|---|---|
| `www.wilderptsa.net` | CNAME | 300 | `wilderptsa.net.` → flattens to `wilderptsa-c20b298090-c8gvc8f0c4aactb0.z02.azurefd.net.` | **Website (Front Door).** Resolves to the AFD endpoint via apex. |
| `shop.wilderptsa.net` | CNAME | 300 | `wilderptsa.net.` → flattens to `wilderptsa-c20b298090-c8gvc8f0c4aactb0.z02.azurefd.net.` | **Website (Front Door).** Same AFD endpoint as www. |
| `email.wilderptsa.net` | CNAME | 60 | `mailgun.org.` | Mailgun tracking/click domain. |
| `autodiscover.wilderptsa.net` | CNAME | 300 | `autodiscover.outlook.com.` | M365 Outlook autodiscover. |
| `lyncdiscover.wilderptsa.net` | CNAME | 300 | `webdir.online.lync.com.` | M365 Teams/Skype discovery. |
| `enterpriseregistration.wilderptsa.net` | CNAME | 300 | `enterpriseregistration.windows.net.` | M365 device/Entra registration. |
| `enterpriseenrollment.wilderptsa.net` | CNAME | 300 | `enterpriseenrollment.manage.microsoft.com.` | M365 Intune enrollment. |

**Front Door endpoint** (for reference): `wilderptsa-c20b298090-c8gvc8f0c4aactb0.z02.azurefd.net` → `mr-z02.tm-azurefd.net` → AFD anycast (e.g. `150.171.110.146`).

---

## 3. Email authentication (SPF / DKIM / DMARC)

| Name | Type | TTL | Value | Purpose |
|---|---|---|---|---|
| `wilderptsa.net` | TXT (SPF) | 300 | `v=spf1 include:spf.protection.outlook.com include:spf.acymailing.com include:mailgun.org -all` | SPF — see apex table. |
| `_dmarc.wilderptsa.net` | TXT | 300 | `v=DMARC1; p=reject; pct=100; fo=1; ri=3600; rua=mailto:eb00100c@dmarc.mailgun.org,mailto:dad85ecb@inbox.ondmarc.com; ruf=mailto:eb00100c@dmarc.mailgun.org,mailto:dad85ecb@inbox.ondmarc.com;` | **DMARC `p=reject`.** Strict — DKIM/SPF must be intact or mail is rejected. |
| `selector1._domainkey.wilderptsa.net` | CNAME | 300 | `selector1-wilderptsa-net._domainkey.wilderptsa.onmicrosoft.com.` | **M365 DKIM** selector 1. |
| `selector2._domainkey.wilderptsa.net` | CNAME | 300 | `selector2-wilderptsa-net._domainkey.wilderptsa.onmicrosoft.com.` | **M365 DKIM** selector 2. |
| `selector1-azurecomm-prod-net._domainkey.wilderptsa.net` | CNAME | 300 | `selector1-azurecomm-prod-net._domainkey.azurecomm.net.` | **Azure Communication Services (ACS) DKIM** selector 1. |
| `selector2-azurecomm-prod-net._domainkey.wilderptsa.net` | CNAME | 300 | `selector2-azurecomm-prod-net._domainkey.azurecomm.net.` | **Azure Communication Services (ACS) DKIM** selector 2. |
| `mailo._domainkey.wilderptsa.net` | TXT | 300 | `k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDAUWtcgElMpaM61LhTm4MZ1aW4K0+9UupVpfseLGSgw3tW/2liLtPh+QRd/I7tOFS9XImE7c5wmdGlzcvnuPfKzUCd4wJiXIy2g8GSouKPQYTdSYsnjwDf0Ms9USXToYwGQlBYy18AumV8bjs8Q5mIIASUSvoXfoWJBCJmbVYKZQIDAQAB` | **AcyMailing DKIM** (inline RSA public key). |

> DKIM selectors that were probed and returned **nothing**: `smtp`, `k1`, `pic`, `mx`, `mail`, `dkim`, `default`, `krs`, `google` (also under `mg.` subdomain). No Mailgun `mg.` sending subdomain exists.

---

## 4. SRV records (Microsoft 365 / Teams)

| Name | Type | TTL | Value |
|---|---|---|---|
| `_sipfederationtls._tcp.wilderptsa.net` | SRV | 300 | `100 1 5061 sipfed.online.lync.com.` |
| `_sip._tls.wilderptsa.net` | SRV | 300 | `100 1 443 sipdir.online.lync.com.` |

---

## 5. Verification / validation TXT & service records

| Name | Type | TTL | Value | Purpose |
|---|---|---|---|---|
| `wilderptsa.net` | TXT | 300 | `ms-domain-verification=4501263c-aa0c-4429-a525-b5b9dfe50a8b` | **Old/orphan** MS domain verification (see ACS note). |
| `wilderptsa.net` | TXT | 300 | `stripe-verification=3A33E15C443C759CC516C51380E8515FEA389216D549E49436963AA390047191` | Stripe. |
| `_dnsauth.wilderptsa.net` | TXT | 120 | `_f3yv1nt7peuhg19knv2picrgeaygw64` | **Azure Front Door apex custom‑domain validation.** Needed to re‑validate the apex custom domain on AFD. |
| `_dnsauth.www.wilderptsa.net` | TXT | 120 | `_chei0q7kvhb3wid5w1bw3nfcufbeloh` | **Azure Front Door `www` custom‑domain validation.** |
| `_domainconnect.wilderptsa.net` | CNAME | 300 | `_domainconnect.gd.domaincontrol.com.` | GoDaddy Domain Connect pointer (convenience; non‑critical). |

> Probed and **not present**: `google-site-verification`, `facebook-domain-verification`, `_amazonses`, `_github-pages`, `_mta-sts`, `_smtp._tls`, `_acme-challenge` / `_acme-challenge.www`, `_dnsauth.shop`. No wildcard `*._domainkey`.

---

## 6. Resolver cross‑check & discrepancies

| Record | Authoritative (`cora`) | `1.1.1.1` | `8.8.8.8` | Verdict |
|---|---|---|---|---|
| Apex A | `150.171.110.146` | `150.171.110.147` | `150.171.109.150` | **Expected** — AFD anycast / flattening returns different frontend IPs per PoP. Not a real divergence; do **not** copy a literal A into the new zone — use ALIAS/CNAME to the AFD endpoint. |
| Apex AAAA | `2603:1061:14:90::1` | `2620:1ec:48:1::70`, `2620:1ec:29:1::70` | `2603:1061:14:90::1` | **Expected** — same anycast behavior for IPv6. |
| NS / SOA / MX / SPF / DMARC / DKIM / `_dnsauth` | consistent | consistent | consistent | **No discrepancies.** All authoritative records matched both public resolvers (the only resolver‑specific variance was the AFD anycast addresses above). |

---

## 7. Rebuild order & gotchas

### Load‑bearing for the **website** (Azure Front Door)
1. Apex `wilderptsa.net` → **ALIAS/ANAME/flattened CNAME** to `wilderptsa-c20b298090-c8gvc8f0c4aactb0.z02.azurefd.net`. Do **not** hard‑code the anycast A/AAAA IPs — they rotate.
2. `www` and `shop` CNAMEs (currently → apex; can point directly at the AFD endpoint).
3. `_dnsauth.wilderptsa.net` and `_dnsauth.www.wilderptsa.net` TXT — required to (re)validate the AFD custom domains. Keep these or AFD will mark the custom domain unvalidated and TLS can drop.

### Load‑bearing for **email deliverability**
1. `MX 0 wilderptsa-net.mail.protection.outlook.com` — inbound mail (M365).
2. SPF TXT (`v=spf1 ... -all`) — exact copy; the `-all` hard‑fail plus `p=reject` DMARC means a missing/typo'd SPF or DKIM = **bounced mail**.
3. DKIM CNAMEs: `selector1`/`selector2` (M365), `selector1`/`selector2-azurecomm-prod-net` (ACS), and `mailo._domainkey` TXT (AcyMailing).
4. `_dmarc` TXT (`p=reject`).
5. M365 service records: `autodiscover`, `lyncdiscover`, `enterpriseregistration`, `enterpriseenrollment`, `_sip._tls`, `_sipfederationtls._tcp`.

### In‑progress ACS custom‑domain verification — **ACTION: TO ADD (not yet published)**
- The ACS migration needs a **new** apex TXT:
  - `wilderptsa.net  TXT  "ms-domain-verification=1183d665-c65b-4304-a0d4-1769269dbfd8"`
- **This value is NOT in DNS yet** (only the old orphan `...4501263c...` is present). When rebuilding the zone, add the new value. Keep the old one too unless ACS confirms it can be removed.

### Migration safety (GoDaddy nameserver cutover)
> **WARNING — replicate EVERYTHING, then lower TTLs, BEFORE switching nameservers.**
1. Recreate **every** record above in the new provider (Azure DNS recommended, since it supports apex ALIAS to Front Door natively).
2. **Lower TTLs** (e.g. 300→60) on the new zone and let the *old* TTLs expire **before** changing NS at GoDaddy, so the cutover is fast and reversible.
3. Verify the new zone answers identically (`dig @<new-ns> …`) for all records — especially MX, SPF, all DKIM selectors, DMARC, and the AFD `_dnsauth` TXTs.
4. Only then repoint nameservers at GoDaddy from Cloudflare to the new provider.
5. Because DMARC is `p=reject` and SPF is `-all`, **any** missed email‑auth record will silently bounce mail — double‑check those first.
6. No DNSSEC is enabled, so there is no DS record to coordinate at GoDaddy during the swap.
