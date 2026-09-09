#!/usr/bin/env bash
# The image build must refuse to ship without WooCommerce / Stripe.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$ROOT/infra/wp-image/required-plugins.conf"
SCRIPT="$ROOT/infra/wp-image/ensure-required-plugins.sh"

fail=0
pass() { echo "[PASS] $1"; }
bad() { echo "[FAIL] $1"; fail=1; }

grep -q $'^woocommerce-gateway-stripe\t' "$CONF" && pass "Stripe is on the required-plugins list" || bad "Stripe missing from required-plugins.conf"
grep -q $'^redis-cache\t' "$CONF" && pass "Redis Cache is required" || bad "redis-cache missing from required-plugins.conf"
grep -q $'^multiple-roles\t' "$CONF" && pass "Multiple Roles is required" || bad "multiple-roles missing from required-plugins.conf"

tmp="$(mktemp -d)"
mkdir -p "$tmp/plugins/templatespare"
if bash "$SCRIPT" "$tmp/plugins" "$CONF" "$tmp/object-cache.php" >/tmp/ensure-out 2>/tmp/ensure-err; then
  bad "ensure script should fail when WooCommerce is absent from the export"
else
  if grep -q 'woocommerce' /tmp/ensure-err; then
    pass "ensure script fails closed when WooCommerce is missing"
  else
    bad "ensure script failed but did not mention woocommerce"
    cat /tmp/ensure-err
  fi
fi
rm -rf "$tmp"

if [[ "$fail" -ne 0 ]]; then
  echo "required-plugin checks failed"
  exit 1
fi
echo "required-plugin checks passed"
