#!/bin/sh
set -euo pipefail

echo "[ci_post_clone] PTSA Board Xcode Cloud setup"
echo "[ci_post_clone] CI_WORKSPACE=${CI_WORKSPACE:-<unset>}"
echo "[ci_post_clone] CI_PRIMARY_REPOSITORY_PATH=${CI_PRIMARY_REPOSITORY_PATH:-<unset>}"
echo "[ci_post_clone] Xcode: $(xcodebuild -version | tr '\n' ' ')"

# MSAL's SwiftPM package includes a large git history and submodule checkout.
# These settings make Xcode Cloud's git fetches more resilient, matching the
# local workaround that fixed transient "missing MSAL package" failures.
git config --global http.version HTTP/1.1
git config --global http.postBuffer 524288000
git config --global http.maxRequestBuffer 100M
git config --global core.compression 0

REPO="${CI_PRIMARY_REPOSITORY_PATH:-$(cd "$(dirname "$0")/../.." && pwd)}"
cd "$REPO/ios-app"

echo "[ci_post_clone] Resolving Swift packages for PTSABoard"
xcodebuild \
  -project PTSABoard.xcodeproj \
  -scheme PTSABoard \
  -destination 'generic/platform=iOS' \
  -resolvePackageDependencies

echo "[ci_post_clone] Complete"
