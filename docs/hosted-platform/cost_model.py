#!/usr/bin/env python3
"""Unit economics for a shared, multi-tenant PTA Tools hosting platform.

Prices are Azure retail pay-as-you-go, westus2 / Front Door Zone 1, taken from
https://prices.azure.com/api/retail/prices on 2026-09-24. Grant or nonprofit
discounts are deliberately not applied, so every figure is a ceiling.

Usage figures (traffic, storage, active time) are assumptions calibrated against
wilderptsa.net, which is a busier-than-average PTSA: WooCommerce, auctions,
newsletters. Edit the TIERS and FIXED blocks and re-run to test other scenarios.

    python3 docs/hosted-platform/cost_model.py
"""

import math

HOURS = 730
SECONDS = HOURS * 3600

# Container Apps, Consumption profile.
ACA_VCPU_IDLE = 0.000004
ACA_VCPU_ACTIVE = 0.000034
ACA_MEM = 0.000004  # per GiB-second; active and idle are the same rate

# MySQL Flexible Server, per hour, and storage per GB-month.
MYSQL = {
    "B1ms": 0.017,
    "B2s": 0.068,
    "B2ms": 0.136,
}
MYSQL_STORAGE = 0.115

AFD_BASE = 35.00
AFD_PER_10K_REQ = 0.009
AFD_EGRESS_GB = 0.0825
AFD_ORIGIN_GB = 0.02
# Standard caps a profile at 100 custom domains and 100 origin groups. A tenant
# uses one origin group and typically 2-3 domains (platform subdomain, www and
# apex of its own domain), so plan on 40 tenants per profile.
TENANTS_PER_AFD = 40

ACR_BASIC = 0.1666 * 730 / 24
LOG_INGEST_GB = 2.30
FILES_GB = 0.06
BLOB_COOL_GB = 0.01

TIERS = {
    # 0.25 vCPU / 0.5 GiB, scaled to zero 11pm-6am and for the two summer months.
    "Starter": dict(
        vcpu=0.25, mem=0.5, active=0.05, on_hours=17 / 24, months_live=10,
        db_gb=1, media_gb=2, backup_gb=3, log_gb=0.15,
        requests=100_000, egress_gb=5, origin_gb=1.5,
    ),
    # 0.5 vCPU / 1 GiB, always on during the school year, parked for summer.
    "Standard": dict(
        vcpu=0.5, mem=1.0, active=0.10, on_hours=1.0, months_live=10,
        db_gb=2, media_gb=5, backup_gb=8, log_gb=0.30,
        requests=300_000, egress_gb=15, origin_gb=4,
    ),
}

FIXED = {
    "Container registry (ACR Basic)": ACR_BASIC,
    "DNS: platform zone, private MySQL zone, domain": 2.00,
    "Control plane: portal, API, queue, registry table, jobs": 5.00,
    "Alerts and health checker": 3.00,
}


def mysql_for(n):
    """Shared server sizing. Wilder alone runs comfortably on B1ms; the per-tenant
    working set assumed here is ~0.05 vCPU and ~150 MB of buffer pool."""
    if n <= 3:
        servers, sku = 1, "B1ms"
    elif n <= 12:
        servers, sku = 1, "B2s"
    else:
        servers, sku = math.ceil(n / 30), "B2ms"
    return servers * (MYSQL[sku] * HOURS + 20 * MYSQL_STORAGE), f"{servers} x {sku}"


def tenant_monthly(t):
    live = t["on_hours"] * SECONDS
    vcpu_rate = ACA_VCPU_IDLE + (ACA_VCPU_ACTIVE - ACA_VCPU_IDLE) * t["active"]
    compute = live * (t["vcpu"] * vcpu_rate + t["mem"] * ACA_MEM)
    compute *= t["months_live"] / 12
    storage = (
        t["db_gb"] * MYSQL_STORAGE
        + t["media_gb"] * FILES_GB + 0.10
        + t["backup_gb"] * BLOB_COOL_GB
    )
    edge = (
        t["requests"] / 10_000 * AFD_PER_10K_REQ
        + t["egress_gb"] * AFD_EGRESS_GB
        + t["origin_gb"] * AFD_ORIGIN_GB
    )
    other = t["log_gb"] * LOG_INGEST_GB + 0.02 + 0.25  # Key Vault ops, cron/backup job runs
    return dict(compute=compute, storage=storage, edge=edge, other=other,
                total=compute + storage + edge + other)


def fixed_monthly(n):
    db, db_label = mysql_for(n)
    afd = AFD_BASE * math.ceil(n / TENANTS_PER_AFD)
    return db + afd + sum(FIXED.values()), db_label, afd, db


def stripe_fee(monthly_price, annual_ach):
    if annual_ach:
        yearly = monthly_price * 12
        return (min(yearly * 0.008, 5.0) + yearly * 0.007) / 12
    return monthly_price * (0.029 + 0.007) + 0.30


def main():
    print("Per-tenant variable cost, USD / month (annualised over 12 months)\n")
    print(f"{'Tier':<10}{'Compute':>9}{'Storage':>9}{'Edge':>7}{'Other':>7}{'Total':>8}")
    per = {}
    for name, t in TIERS.items():
        c = tenant_monthly(t)
        per[name] = c["total"]
        print(f"{name:<10}{c['compute']:>9.2f}{c['storage']:>9.2f}{c['edge']:>7.2f}"
              f"{c['other']:>7.2f}{c['total']:>8.2f}")

    print("\nFully loaded cost per tenant as the platform grows, USD / month\n")
    print(f"{'PTSAs':>6}  {'MySQL':<10}{'AFD':>6}{'Fixed':>8}{'Fixed/PTSA':>11}"
          f"{'Starter':>9}{'Standard':>10}")
    for n in (1, 5, 10, 25, 40, 75, 100, 200):
        fixed, db_label, afd, _ = fixed_monthly(n)
        share = fixed / n
        print(f"{n:>6}  {db_label:<10}{afd:>6.0f}{fixed:>8.0f}{share:>11.2f}"
              f"{per['Starter'] + share:>9.2f}{per['Standard'] + share:>10.2f}")

    print("\nStripe overhead on the price charged, USD / month equivalent\n")
    for price in (15, 25):
        print(f"  ${price}/mo  card monthly {stripe_fee(price, False):.2f}   "
              f"ACH annual {stripe_fee(price, True):.2f}")


if __name__ == "__main__":
    main()
