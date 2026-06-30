# Summer 2026 Azure Scale-Down Runbook — wilderptsa.net

**Goal:** Reduce the Azure monthly bill during summer break (no school until **2026-08-01**) using
ONLY reversible scale-down changes, keeping the site up the whole time.

- **Subscription:** `97f6936d-7300-4a49-a2ad-cbfee3b28e00` (Microsoft Grant)
- **Resource group:** `PTSAWebsite` (West US 2)
- **Production site:** https://wilderptsa.net (App Service `wilderptsa` + `staging` slot)
- **Date applied:** 2026-06-30 (UTC) / 2026-06-29 evening PT
- **Applied by:** Cursor agent (Claude Opus 4.8) via `az` CLI, on behalf of the site owner.
- **Verification:** `infra/post-change-smoke.sh` (prod) — **6/6 PASS** after every change.

> **REVERSAL DEADLINE: on or before 2026-08-01.** Run the "Revert (scale back up)" commands
> in the order listed, one at a time, smoke-testing between each. School traffic resumes Aug 1.

---

## 1. Summary of what changed

| Resource | Action | Old | New | Est. $/mo before | Est. $/mo after | Est. savings/mo |
|---|---|---|---|---|---|---|
| MySQL Flexible Server `wilderptsa-c20b298090-wpdbserver` | **Scaled down** | `Standard_B2ms` (Burstable, 2 vCore) | `Standard_B1ms` (Burstable, 1 vCore) | ~$90.71 compute (+$15.80 storage, +$14.19 IO) | ~$45 compute (storage/IO unchanged) | **~$45** |
| Log Analytics workspace `wilderptsa-laws` (App Insights) | **Daily cap guardrail** | quota `-1` (unlimited) | quota `0.5` GB/day | ~$0.10 actual ingestion | ~$0.10 | **$0** (guardrail only) |
| App Service Plan `ASP-PTSAWebsite-a9e9` | **No change** (see §3) | `P1v3` | `P1v3` | ~$125.88 | ~$125.88 | $0 |
| Azure Cache for Redis `wilderptsa-redis` | **No change** (see §3) | `Basic C0` | `Basic C0` | ~$32.17 | ~$32.17 | $0 |
| MySQL storage (134 GB Premium_LRS) | **No change** (see §4) | 134 GB | 134 GB | ~$15.80 | ~$15.80 | $0 |
| Front Door `WilderPTSAAFD` (Standard) | Left alone (cheap) | — | — | ~$4.89 | ~$4.89 | $0 |

**Total projected monthly savings: ~$45/mo** (≈14% off the ~$319/mo run rate → **~$274/mo**).

The single meaningful, safe lever this summer was the MySQL compute SKU. The other large line
items (App Service, Redis, App Insights) are already at or below their safe floors — see §3.

---

## 2. Changes applied (with smoke results)

### 2.1 MySQL `Standard_B2ms` → `Standard_B1ms`
Measured utilization (2 weeks): CPU avg ~5% / max ~16%, memory ~22% steady, ~5 active
connections (max 29), 2.5 GB used of 134 GB. B1ms (1 vCore, 2 GB RAM) absorbs this with margin.

```bash
az mysql flexible-server update \
  -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --sku-name Standard_B1ms --tier Burstable
```
- Result: `state: Ready`, `sku: Standard_B1ms`, `tier: Burstable`.
- Side effect: IOPS settled to **640** (the free maximum for B1ms at this storage size; was 702).
  Measured paid IO is low, so this is fine. autoIoScaling left **Enabled** so it can burst if needed.
- **Smoke after change: 6/6 PASS.**

### 2.2 Log Analytics daily cap `-1` → `0.5` GB/day
App Insights is workspace-based (`ingestionMode: LogAnalytics`). Measured ingestion is
**~0.0012 GB/day (~0.037 GB billable / 30 days)** — essentially zero, so there is no real
ingestion cost to cut (the ~$21.60 historical figure reflects school-year traffic that naturally
falls in summer). A 0.5 GB/day cap is ~400× current usage: a harmless, fully reversible guardrail
against an unexpected telemetry spike. **Projected savings: $0.**

```bash
az monitor log-analytics workspace update \
  -g PTSAWebsite -n wilderptsa-laws --quota 0.5
```
- Result: `dailyQuotaGb: 0.5`, retention unchanged at 30 days.
- This change does NOT recycle the web app. **Smoke after change: 6/6 PASS.**

---

## 3. What was deliberately NOT changed (and why)

### App Service Plan `ASP-PTSAWebsite-a9e9` — kept at **P1v3**
Metrics (14 days, plan-level): CPU avg ~14% / max ~70%; **memory avg ~57% / max ~67% of 8 GB
= ~4.6 GB avg, ~5.4 GB peak.** The staging slot alone holds ~1.1–1.4 GB and the WordPress
sitecontainers stack adds the rest.

- `P0v3` (the only cheaper PremiumV3 SKU) = **1 vCPU / 4 GB total**. The plan's ~5.4 GB peak
  **exceeds 4 GB → would OOM**. Unsafe.
- Basic tier (`B1/B2/B3`) is cheaper but **does not support deployment slots** — the `staging`
  slot would have to be deleted (out of scope, not reversible cleanly).
- Standard tier supports slots, but the only S-SKU with enough RAM (`S3`, 7 GB) **costs more**
  than P1v3; `S1`/`S2` (1.75/3.5 GB) are too small.

→ No cheaper SKU simultaneously (a) supports the staging slot and (b) fits the ~5.4 GB peak.
Leaving P1v3 in place. (If staging were stopped, the math still lands right at P0v3's 4 GB ceiling
with no headroom plus CPU peaks — still not recommended.)

### Azure Cache for Redis `wilderptsa-redis` — kept at **Basic C0**
`az redis show` reports `sku: {name: Basic, family: C, capacity: 0}` = **Basic C0 (250 MB)**, which
is the **smallest SKU Azure offers**. There is nothing to scale down to. The WordPress object-cache
endpoint (`wilderptsa-redis.redis.cache.windows.net:6380`, SSL) is unchanged.

---

## 4. Future optimization (NOT done — requires migration)

**MySQL storage is over-provisioned: 134 GB allocated, only ~2.5 GB used (1.8%).**
Azure MySQL Flexible Server storage **cannot be shrunk in place** — reducing it requires a
**dump → recreate server with smaller storage → import** migration (with downtime), which is out
of scope for a reversible summer scale-down. Estimated saving if reduced (e.g. to 32 GB):
~$12/mo of the ~$15.80 storage line. Track as a separate maintenance task, not a summer lever.

- `autoGrow` left **Enabled** and `autoIoScaling` left **Enabled**. Turning autoGrow off was
  permitted (used space is far from the floor) but yields **no savings** and removes a safety net,
  so it was left on.

---

## 5. Revert (scale back up) — run on or before **2026-08-01**

Run these **one at a time, sequentially**, and run the smoke test between each. SKU changes
recycle the service; after the MySQL change allow it to return to `Ready` before smoke-testing.

```bash
# 0) Ensure correct subscription
az account set --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00

# 1) MySQL: B1ms -> B2ms (original)
az mysql flexible-server update \
  -g PTSAWebsite -n wilderptsa-c20b298090-wpdbserver \
  --sku-name Standard_B2ms --tier Burstable
#    then: bash infra/post-change-smoke.sh   (expect 6/6 PASS)

# 2) Log Analytics: remove daily cap (back to unlimited)
az monitor log-analytics workspace update \
  -g PTSAWebsite -n wilderptsa-laws --quota -1
#    (no app recycle) then: bash infra/post-change-smoke.sh

# No App Service Plan or Redis revert needed — they were not changed.
```

**Original values for reference (pre-scaledown, captured 2026-06-30):**
- MySQL: `Standard_B2ms`, Burstable, 134 GB Premium_LRS, IOPS 702, autoGrow Enabled,
  autoIoScaling Enabled, backup retention 7 days, HA Disabled, v8.0.21.
- Log Analytics `wilderptsa-laws`: `--quota -1` (unlimited), retention 30 days, SKU PerGB2018.
- App Service Plan: `P1v3` (unchanged). Redis: `Basic C0` (unchanged).

---

## 6. Constraints honored
- Site stayed **UP**; only scale-downs, no deletions.
- Changes applied **one resource at a time**, smoke-tested between each (6/6 PASS each time).
- No `az webapp deploy`, no plugin file edits, no GitHub Actions, no slot swaps, no `--clean`.
- MySQL storage **not** shrunk (impossible in place — noted as future migration in §4).
