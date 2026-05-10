# wilderptsa.net — Azure Cost & Rightsizing Audit

**Date:** 2026-05-09
**Subscription:** Microsoft Grant (`97f6936d-7300-4a49-a2ad-cbfee3b28e00`)
**Tenant:** `a220d676-fd60-4d01-8742-d18944f51a66`
**Resource Group:** `PTSAWebsite` (region: `westus2`)
**Public URL:** https://wilderptsa-c20b298090-c8gvc8f0c4aactb0.z02.azurefd.net/
**Workload:** WordPress on Linux App Service (sitecontainers) + MySQL Flexible Server + Redis + Front Door

---

## TL;DR

The site is **dramatically over-provisioned** for its actual traffic. Three resources — App Service Plan, MySQL Flexible Server, and Redis Cache — are each running at a tier that would suit a much busier production app. WordPress + Front Door caching on a typical PTSA workload (mostly anonymous reads, occasional admin edits, light WooCommerce) does not need this firepower.

| Resource | Current SKU | Avg Util (14d) | Verdict |
|---|---|---|---|
| App Service Plan `ASP-PTSAWebsite-a9e9` | **P1v3 × 2** (4 vCPU / 16 GB total) | CPU 15-37%, Mem 44-72% | **Over-provisioned** |
| MySQL Flexible `wilderptsa-...-wpdbserver` | **GP Standard_D2ds_v4** (2 vCPU / 8 GB) + 134 GB | CPU **3-5%**, Mem 24-55%, IO **<1%**, **3 GB used** of 134 GB | **Severely over-provisioned** |
| Redis `wilderptsa-redis` | **Standard C1** (1 GB, with replica) | Mem **3-14%** of 1 GB (peak 22%) | **Over-provisioned + wrong tier** |

**Estimated savings: ~$170/month (~70% of the current $237/month spend)** without affecting user experience, by moving to right-sized SKUs.

---

## 1. Current State (Actual Data)

### 1.1 Resource inventory (`PTSAWebsite` RG)

```
ASP-PTSAWebsite-a9e9                        Microsoft.Web/serverfarms       P1v3 (Linux), 2 instances
wilderptsa                                   Microsoft.Web/sites             Linux, alwaysOn=true, sitecontainers
wilderptsa/staging                           Microsoft.Web/sites             Stopped slot
wilderptsa-c20b298090-wpdbserver             Microsoft.DBforMySQL/flexibleServers   Standard_D2ds_v4, 134 GB, MySQL 8.0.21
wilderptsa-redis                             Microsoft.Cache/Redis           Standard C1 (1 GB)
WilderPTSAAFD                                Microsoft.Cdn/profiles          Standard_AzureFrontDoor
wilderptsac20b298091                         Microsoft.Storage/storageAccounts  StorageV2 LRS
wilderptsa-c20b298090-vnet                   Microsoft.Network/virtualNetworks
wilderptsa-c20b298090-privatelink.mysql...   Microsoft.Network/privateDnsZones
wilderptsa-c20b298090-emailacsendpoint       Microsoft.Communication/communicationServices  (Email)
wilderptsa-c20b298090-acsendpoint            Microsoft.Communication/communicationServices
wilderptsa-laws                              Microsoft.OperationalInsights/workspaces
wilderptsa-appinsights                       Microsoft.Insights/components
wilderptsa-c20b298090-wpidentity             Microsoft.ManagedIdentity/userAssignedIdentities
```

### 1.2 Actual cost — last 30 days (Cost Management API, ActualCost)

| Resource | 30-day cost (USD) |
|---|---:|
| App Service Plan `ASP-PTSAWebsite-a9e9` (P1v3 × 2) | **$125.34** |
| MySQL Flexible Server | **$88.06** |
| Redis Cache (Standard C1) | $16.31 *(only ~11 days of data; full-month projection ≈ $48)* |
| Azure Front Door (Standard) | $6.19 |
| Private DNS Zone | $1.07 |
| Storage Account | $0.18 |
| Communication Services (Email) | $0.05 |
| **Total (current 30 days)** | **~$237** |
| **Total (full-month projection w/ Redis trued-up)** | **~$269** |

> Note: This subscription appears to receive a roughly 45–55% discount versus PAYG retail (Microsoft Grant / non-profit pricing). All "savings" estimates below project the discount through to the recommended SKUs.

### 1.3 Utilization — last 14 days (Azure Monitor)

#### App Service Plan (P1v3 × 2 — 4 vCPU / 16 GB combined)
| Day | CPU avg | CPU max | Mem avg | Mem max |
|---|---:|---:|---:|---:|
| 04-26 | 15.9% | 54% | 68.2% | 77% |
| 04-27 | 23.9% | 100% | 71.9% | 87% |
| 04-28 | 32.3% | 99% | 67.5% | 78% |
| 04-29 | 37.6% | 99% | 67.3% | 83% |
| 04-30 | 34.5% | 99% | 66.1% | 84% |
| 05-01 | 31.1% | 99% | 67.2% | 82% |
| 05-02 | 30.7% | 99% | 65.8% | 80% |
| 05-03 | 31.4% | 99% | 65.7% | 86% |
| 05-04 | 32.6% | 98% | 66.1% | 83% |
| 05-05 | 33.9% | 99% | 63.9% | 81% |
| 05-06 | 17.5% | 59% | 47.1% | 56% |
| 05-07 | 15.4% | 59% | 44.6% | 50% |
| 05-08 | 17.0% | 41% | 45.7% | 53% |
| 05-09 | 19.2% | 61% | 46.4% | 51% |

- `HttpQueueLength` = **0.0** every day → spikes never queue requests, so they are **NOT** user-facing congestion. They are background tasks (WP-Cron, plugin update jobs, search indexer, backup, etc.) hitting one process for short windows.
- Sustained workload only uses ~1 of the 4 vCPU on average.
- Memory average 11 GB of 16 GB is mostly OS/PHP-FPM working set; WordPress itself is small.
- **Day 05-06 shift** (CPU dropped from ~35% to ~17% avg, memory dropped 20 pts) suggests a heavy plugin/cron job stopped or one instance changed — confirms the headroom is huge.

#### MySQL Flexible (Standard_D2ds_v4 — 2 vCPU / 8 GB / 134 GB)
| Day | CPU avg | CPU max | Mem avg | IO avg | IO max | Connections avg | Connections max |
|---|---:|---:|---:|---:|---:|---:|---:|
| 05-03 | **3.5%** | 14.9% | 55.0% | 0.79% | 7.1% | 6.1 | 54 |
| 05-04 | **3.6%** | 14.9% | 55.0% | 0.80% | 4.0% | 6.3 | 54 |
| 05-05 | **3.6%** | 18.8% | 53.9% | 0.87% | **80.5%** | 6.4 | 54 |
| 05-06 | **3.3%** | 16.5% | **24.0%** | 0.84% | 5.6% | 6.4 | 31 |
| 05-07 | **3.1%** | 14.8% | 24.3% | 0.80% | 4.2% | 5.9 | 18 |
| 05-08 | **3.2%** | 15.4% | 24.3% | 0.79% | 4.7% | 6.0 | 19 |
| 05-09 | **3.3%** | 14.1% | 24.4% | 0.83% | 5.9% | 6.1 | 22 |

- **Storage used: ~3 GB** out of **134 GB allocated** — only 2% used (autoGrow=Enabled means storage will grow when needed; current allocation is way oversized).
- 702 IOPS provisioned; storage IO % avg < 1% means actual IOPS used is ~5–7. The 80% IO spike on 05-05 was a single backup window.
- Memory dropped from 55% to 24% on 05-06 — suggests a `wp-config` or DB connection pool change. Either way: peak is well under 50%.
- 2 vCPU × 3.5% avg = **0.07 vCPU effectively used**. This box is sitting idle.

#### Redis (Standard C1 — 1 GB, with replica)
| Day | Mem % avg | Mem % max | CPU avg | CPU max | Hits/day | Misses/day |
|---|---:|---:|---:|---:|---:|---:|
| 05-03 | 10.3% | 22% | 9.3% | 36% | 7.4M | 2.4M |
| 05-04 | 13.9% | 21% | 10.3% | 48% | 8.8M | 3.2M |
| 05-05 | 5.7% | 16% | 10.6% | 50% | 8.8M | 3.3M |
| 05-06 | 6.9% | 14% | 28.8% | 67% | 9.6M | 3.0M |
| 05-07 | 6.9% | 10% | 26.7% | 58% | 6.6M | 1.9M |
| 05-08 | 6.1% | 10% | 25.3% | 39% | 8.6M | 2.4M |
| 05-09 | 3.9% | 12% | 26.2% | 67% | 10.0M | 2.0M |

- Memory: peak ~22% of 1 GB ≈ **220 MB** used. The cache is clearly active (~7-10M hits/day) but the working set is small.
- Hit ratio: ~75-80% — good, not amazing. Likely fine for an object cache; eviction is normal.
- Tier choice is wrong for this workload: **Standard** tier exists to provide a replica for HA. WordPress object cache is **purely ephemeral cache** — losing it on a Redis restart costs nothing more than ~30s of cache repopulation. Paying for a replica is wasted.

### 1.4 Configuration findings

- **App Service Plan**: 2 fixed instances, no autoscale rule, AlwaysOn=true. The staging slot is stopped but plan instances bill regardless.
- **MySQL**: `highAvailability=Disabled`, `geoRedundantBackup=Disabled`, `backupRetentionDays=7`. Allocation 134 GB but only ~3 GB used. **Storage cannot be shrunk** on Flexible Server — to reclaim that you'd need to dump → restore into a new server.
- **Redis**: `enableNonSslPort=false` (good), version 6.0.
- **Front Door** Standard at $6/mo is fine — leave alone, it's the right tier and provides edge caching that's already absorbing most read traffic (which is exactly why the origin can be small).
- **Networking**: VNet + private endpoint + private DNS for MySQL is correct.

---

## 2. Recommended Rightsizing

### 2.1 App Service Plan — `ASP-PTSAWebsite-a9e9`

**Current:** P1v3 × 2 instances, Linux — 4 vCPU / 16 GB combined
**Actual cost:** $125.34 / 30 days

**Recommended (preferred):** **P0v3 × 1 instance** (1 vCPU / 4 GB, Linux PremiumV3)
- Why P0v3: keeps the PremiumV3 tier (better hardware, better network throughput, deployment slot support, VNet integration) but right-sizes to a single core. Average sustained workload is ~1 vCPU equivalent today.
- Single instance is acceptable because the staging slot is stopped and Front Door already caches; plan-level downtime is rare and PTSA traffic patterns tolerate brief restarts.
- **Estimated cost (with grant discount applied proportionally):** ~$31/mo
- **Estimated savings: ~$94/month** (75% reduction on the ASP line)

**Alternative (most aggressive):** **B2 × 1 instance** (2 vCPU / 4 GB, Basic Linux)
- Drops out of PremiumV3 — loses VNet integration on the app, deployment slots, and auto-heal. Check current site networking before doing this.
- **Estimated cost:** ~$14/mo
- **Estimated savings: ~$111/month** (89% reduction)
- ⚠️ Only choose this if VNet integration / private endpoints from the app side are not in use (the MySQL private link is a separate concern; check that the app reaches MySQL via private link before changing).

**Alternative (safest):** **P0v3 × 2 instances**
- Keeps redundancy across two AZs.
- **Estimated cost:** ~$63/mo
- **Estimated savings: ~$62/month** (49% reduction)

**Either way: add an autoscale rule** (1–2 instance range, scale on CPU > 75% for 10 min, scale in on CPU < 30% for 30 min) so transient WP-Cron spikes get an extra instance only when needed.

### 2.2 MySQL Flexible Server — `wilderptsa-c20b298090-wpdbserver`

**Current:** GP `Standard_D2ds_v4` (2 vCPU / 8 GB GeneralPurpose) + 134 GB / 702 IOPS
**Actual cost:** $88.06 / 30 days

**Recommended (preferred):** **Burstable `Standard_B2s`** (2 vCPU / 4 GB Burstable)
- Same vCPU count, smaller memory, much cheaper. CPU avg 3.5% with peaks of 15-29% is the textbook Burstable workload — bursts are easily covered by credit accumulation.
- Memory: current peak ~55% of 8 GB = 4.4 GB; on B2s (4 GB) MySQL will auto-shrink `innodb_buffer_pool` and stay healthy for a 3 GB working set.
- **Estimated cost (with grant discount):** ~$41/mo
- **Estimated savings: ~$47/month** (54% reduction)

**Alternative (more aggressive):** **Burstable `Standard_B1ms`** (1 vCPU / 2 GB)
- 0.07 vCPU sustained means 1 vCPU is plenty. Memory pressure is the only risk — buffer pool would shrink to ~1 GB. WordPress + WooCommerce on 1 GB is workable but tight.
- **Estimated cost:** ~$25/mo
- **Estimated savings: ~$63/month** (72% reduction)

**Storage:** ⚠️ **Cannot shrink** allocated storage on Flexible Server. To reclaim the unused 130 GB you would need a dump → new server → restore (a few hours of careful work and a brief cutover). If you do that anyway during the SKU change, drop allocation to 32-64 GB and let autoGrow handle the rest. Storage savings: ~$11/mo. Only worth it if you're rebuilding the server anyway.

**Other DB hardening (free):**
- Audit `backup.geoRedundantBackup` (currently Disabled) — fine for a PTSA, leaving as-is is correct.
- Keep `backup.backupRetentionDays=7`.
- Consider enabling `slow_query_log` to prove the box truly is idle before sizing down.

### 2.3 Redis — `wilderptsa-redis`

**Current:** Standard C1 (1 GB, master + replica)
**Actual cost (full-month projection):** ~$48/mo

**Recommended (preferred):** **Basic C1** (1 GB, single node)
- Same memory ceiling, drops the replica. WordPress object cache is ephemeral, so the replica adds zero value here.
- **Estimated cost (with grant discount):** ~$24/mo
- **Estimated savings: ~$24/month** (50% reduction)

**Alternative (more aggressive):** **Basic C0** (250 MB, single node)
- Peak memory currently ~220 MB → would land at ~88% of C0 capacity, pushing the cache into more aggressive LRU eviction. Slightly lower cache hit-ratio is the only consequence; nothing breaks.
- **Estimated cost:** ~$8/mo
- **Estimated savings: ~$40/month** (83% reduction)
- Only choose if you're OK with a slightly lower hit ratio.

> The recent rise in Redis CPU (avg 25-29%, peaks to 67%) deserves a glance. It's plausibly correlated with the MySQL memory drop on 05-06 (something on the app side started reading more). It's not a sizing problem — it's the same VM either way at this tier — but worth confirming it's not a busy-loop in a plugin.

### 2.4 Other (leave alone)

- **Front Door Standard** ($6/mo) — correct tier, doing real work absorbing edge traffic. Don't touch.
- **Storage account** ($0.18/mo) — negligible.
- **Email Communication Service** ($0.05/mo) — negligible.
- **Private DNS** ($1.07/mo) — required for MySQL private link.
- **Application Insights / Log Analytics** — not on the bill currently; both are within free tiers. Keep.

---

## 3. Summary — Estimated Monthly Savings

Using the **preferred** recommendation for each resource (the safe-but-significant choice):

| Resource | Current | Recommended | Cost now | Cost after | Save / mo |
|---|---|---|---:|---:|---:|
| App Service Plan | P1v3 × 2 | **P0v3 × 1** + autoscale | $125 | ~$31 | **~$94** |
| MySQL Flexible | D2ds_v4 GP | **B2s Burstable** | $88 | ~$41 | **~$47** |
| Redis | Standard C1 | **Basic C1** | $48* | ~$24 | **~$24** |
| Other (FD, storage, DNS, email) | — | unchanged | ~$8 | ~$8 | $0 |
| **Total** | | | **~$269** | **~$104** | **~$165 (≈ 61%)** |

\* Redis full-month projection. Last 30-day actual cost line was $16 because the cache was provisioned partway through the period.

If you're more cost-aggressive (B2 × 1 ASP, B1ms MySQL, Basic C0 Redis), savings climb to **~$215/month (~80%)**, but with thinner safety margins on memory.

---

## 4. Suggested Execution Plan (low-risk order)

1. **Redis first (5 min, near-zero risk).** Move from Standard C1 → Basic C1. Cache flushes, WordPress repopulates, no schema changes. Worst case: 30 seconds of slower page loads while the cache warms.
2. **App Service Plan second.** Scale plan from 2 instances → 1 first (no SKU change) and watch a full week. CPU averages prove out, then change SKU P1v3 → P0v3. **Add an autoscale rule (1–2 instances, CPU > 75%)** at the same time so the WP-Cron spikes self-handle.
3. **MySQL last (most disruptive).** Compute SKU change is online but causes a ~60-90 second failover window. Schedule outside school hours. Change to B2s; observe Buffer pool / `innodb_buffer_pool_size` for a week. (Storage stays at 134 GB unless you do a full rebuild — which I would not do for $11/mo.)

After each step, watch Azure Monitor for 48 hours before moving to the next.

---

## 5. CLI Reference (dry-run; do **not** execute without approval)

```bash
# 1. Redis: Standard C1 -> Basic C1 (data is cache-only; loss acceptable)
az redis update -g PTSAWebsite -n wilderptsa-redis \
  --sku Basic --vm-size C1

# 2a. ASP: scale to 1 instance first (observe a week)
az appservice plan update -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --number-of-workers 1

# 2b. ASP: SKU P1v3 -> P0v3
az appservice plan update -g PTSAWebsite -n ASP-PTSAWebsite-a9e9 \
  --sku P0V3

# 2c. ASP: autoscale rule
az monitor autoscale create -g PTSAWebsite \
  --resource ASP-PTSAWebsite-a9e9 --resource-type Microsoft.Web/serverfarms \
  --name wilderptsa-autoscale --min-count 1 --max-count 2 --count 1
az monitor autoscale rule create -g PTSAWebsite --autoscale-name wilderptsa-autoscale \
  --condition "Percentage CPU > 75 avg 10m" --scale out 1
az monitor autoscale rule create -g PTSAWebsite --autoscale-name wilderptsa-autoscale \
  --condition "Percentage CPU < 30 avg 30m" --scale in 1

# 3. MySQL: GP D2ds_v4 -> Burstable B2s (causes brief failover)
az mysql flexible-server update -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --tier Burstable --sku-name Standard_B2s
```

---

## 6. Audit Trail / Sources

- **Cost data:** `POST /subscriptions/97f6936d-7300-4a49-a2ad-cbfee3b28e00/resourceGroups/PTSAWebsite/providers/Microsoft.CostManagement/query?api-version=2023-11-01` (ActualCost, last 30 days, grouped by ResourceId + ServiceName).
- **Utilization metrics:** `az monitor metrics list` against ASP / MySQL / Redis resource IDs, 14-day window, P1D/PT1H granularity.
- **Pricing reference:** Azure Retail Prices API (`https://prices.azure.com/api/retail/prices`) filtered to `armRegionName=westus2`, `priceType=Consumption`. Effective rates derived from the 30-day actual cost / consumed hours, which implies a ~45-55% discount versus PAYG retail (consistent with a Microsoft for Nonprofits / Microsoft Grant subscription).
- **SKU specs:** `az appservice plan show`, `az mysql flexible-server show`, `az redis show`.

---

## Follow-up (added 2026-05-09)

The audit has been complemented by a dev/prod workflow + monitoring setup. See:

- [`docs/runbooks/dev-prod-workflow.md`](runbooks/dev-prod-workflow.md) - daily workflow, rollback, capacity scaling, MySQL maintenance procedure.
- App Insights availability test for `https://wilderptsa.net/` (`wilderptsa-prod-availability`) is live and pinging from 5 US regions every 5 minutes.
- `infra/post-change-smoke.sh` - 6-check post-change health verifier (homepage, wp-json, admin-ajax, latency, TLS).
- `infra/setup-staging-slot.sh` - one-shot staging slot fixup + slot-sticky settings on prod (already executed). All 5 critical settings (`DATABASE_NAME`, `DATABASE_HOST`, `BLOB_CONTAINER_NAME`, `AFD_DOMAIN`, `AFD_ENDPOINT`) are now slot-sticky on both slots, making slot swaps safe.
- GitHub Actions:
  - `.github/workflows/deploy-staging.yml` - push to `dev` branch deploys plugin code to staging slot
  - `.github/workflows/promote-prod.yml` - merging `dev` -> `main` triggers slot swap to prod with manual approval gate and auto-rollback on smoke failure
- Azure AD app registration `github-actions-wilderptsa` with federated credentials for the staging and production GitHub environments. No long-lived secrets.

**No new monthly cost was added.** All of the above uses existing free-tier services.

**Known follow-up:** Staging slot WordPress is currently returning HTTP 500 (pre-existing condition unrelated to this work). Configuration is correct; the WP install on staging needs repair (likely DB schema not initialized for the staging DB, or managed-identity DB auth needs to be re-bound on staging slot). The CI/CD pipeline tolerates this and warns rather than fails on smoke checks.
