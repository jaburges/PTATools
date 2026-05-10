#!/usr/bin/env bash
# Post-change health verifier for wilderptsa.net.
# Run after any infra change. Exit 0 if all pass, 1 if any fail.
#
# Usage:
#   ./infra/post-change-smoke.sh                    # tests prod
#   TARGET=staging ./infra/post-change-smoke.sh     # tests staging
#   TARGET=https://example.com ./infra/post-change-smoke.sh  # custom URL
set -uo pipefail

case "${TARGET:-prod}" in
  prod|production|"")
    URL="https://wilderptsa.net"
    ;;
  staging|stage)
    URL="https://wilderptsa-staging-c20b298090-drccadb2badebhh5.z02.azurefd.net"
    ;;
  staging-direct)
    URL="https://wilderptsa-staging.azurewebsites.net"
    ;;
  http*)
    URL="$TARGET"
    ;;
  *)
    echo "Unknown TARGET: $TARGET (use 'prod', 'staging', 'staging-direct', or a full URL)"
    exit 2
    ;;
esac

FAIL=0
PASS=0

check() {
  local name="$1"
  local cmd="$2"
  printf "  %-30s " "[$name]"
  local out
  if out=$(eval "$cmd" 2>&1); then
    echo "OK"
    PASS=$((PASS + 1))
  else
    echo "FAIL"
    [ -n "$out" ] && echo "      $out" | head -3
    FAIL=$((FAIL + 1))
  fi
}

echo "==> Smoke test starting against $URL"

check "homepage 200" \
  "curl -fsS -o /dev/null -w '%{http_code}' --max-time 30 '$URL/' | grep -q '^200\$'"

check "homepage <5s" \
  "[ \"\$(curl -s -o /dev/null -w '%{time_total}' --max-time 10 '$URL/' | awk '{print (\$1 < 5.0)}')\" = '1' ]"

check "wp-json 200" \
  "curl -fsS -o /dev/null -w '%{http_code}' --max-time 30 '$URL/wp-json/' | grep -q '^200\$'"

check "wp-json valid JSON" \
  "curl -fsS --max-time 30 '$URL/wp-json/' | python3 -c 'import sys,json; json.load(sys.stdin)'"

check "admin-ajax 200" \
  "curl -fsS -o /dev/null -w '%{http_code}' --max-time 30 '$URL/wp-admin/admin-ajax.php?action=heartbeat' | grep -q '^200\$'"

check "TLS cert valid" \
  "echo | openssl s_client -servername $(echo $URL | sed 's|https*://||;s|/.*||') -connect $(echo $URL | sed 's|https*://||;s|/.*||'):443 2>/dev/null | openssl x509 -noout -checkend 604800"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "==> [PASS] All $PASS checks passed against $URL"
  exit 0
else
  echo "==> [FAIL] $FAIL of $((PASS + FAIL)) checks failed against $URL"
  exit 1
fi
