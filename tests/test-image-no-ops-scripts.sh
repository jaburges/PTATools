#!/usr/bin/env bash
# The image build must not ship mu-plugins from the export or retired ops scripts.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPT="$ROOT/infra/wp-image/check-no-ops-scripts.sh"
DOCKERFILE="$ROOT/infra/wp-image/Dockerfile"
BUILD="$ROOT/infra/wp-image/build.sh"

fail=0
pass() { echo "[PASS] $1"; }
bad() { echo "[FAIL] $1"; fail=1; }

[[ ! -e "$ROOT/infra/ops" ]] && pass "infra/ops is gone" || bad "infra/ops still exists"

if grep -qE '^COPY( --[^ ]+)* +wp-content/mu-plugins' "$DOCKERFILE"; then
  bad "Dockerfile copies mu-plugins from the export"
else
  pass "Dockerfile does not copy exported mu-plugins"
fi

grep -q 'rm -rf "$CONTEXT/wp-content/mu-plugins"' "$BUILD" && pass "build.sh discards exported mu-plugins" || bad "build.sh keeps exported mu-plugins"
grep -q 'check-no-ops-scripts.sh' "$BUILD" && pass "build.sh runs the ops-script check" || bad "build.sh does not run the ops-script check"

tmp="$(mktemp -d)"
mkdir -p "$tmp/wp-content/plugins/some-plugin" "$tmp/mu-plugins"
touch "$tmp/wp-content/plugins/some-plugin/some-plugin.php" "$tmp/mu-plugins/000-container-core-pin.php"
bash "$SCRIPT" "$tmp" && pass "clean context passes" || bad "clean context was rejected"

touch "$tmp/wp-content/plugins/dump-small-mysql-2026.php"
if bash "$SCRIPT" "$tmp" 2>/dev/null; then
  bad "a retired ops script in plugins/ was not caught"
else
  pass "a retired ops script in plugins/ fails the build"
fi
rm -rf "$tmp"

if [[ "$fail" -ne 0 ]]; then
  echo "ops-script checks failed"
  exit 1
fi
echo "ops-script checks passed"
