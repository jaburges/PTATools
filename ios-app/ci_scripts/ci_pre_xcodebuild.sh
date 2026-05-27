#!/bin/sh
set -euo pipefail

echo "[ci_pre_xcodebuild] Validating PTSA Board build inputs"

REPO="${CI_PRIMARY_REPOSITORY_PATH:-$(cd "$(dirname "$0")/../.." && pwd)}"
APP_DIR="$REPO/ios-app"
CONFIG="$APP_DIR/PTSABoard/Config/AppConfig.swift"
PROJECT="$APP_DIR/PTSABoard.xcodeproj"

if [ ! -d "$PROJECT" ]; then
  echo "::error::Expected Xcode project at $PROJECT"
  exit 1
fi

if [ ! -f "$CONFIG" ]; then
  echo "::error::Expected AppConfig.swift at $CONFIG"
  exit 1
fi

if grep -q 'REPLACE_WITH_' "$CONFIG"; then
  echo "::error::AppConfig.swift still contains REPLACE_WITH_* placeholders"
  exit 1
fi

BUNDLE_ID=$(
  xcodebuild -project "$PROJECT" -scheme PTSABoard -configuration Release -showBuildSettings 2>/dev/null |
    awk -F'= ' '/^[[:space:]]+PRODUCT_BUNDLE_IDENTIFIER = / {print $2; exit}'
)

if [ "$BUNDLE_ID" != "net.wilderptsa.PTSABoard" ]; then
  echo "::error::Unexpected PRODUCT_BUNDLE_IDENTIFIER: $BUNDLE_ID"
  exit 1
fi

echo "[ci_pre_xcodebuild] Bundle ID: $BUNDLE_ID"
echo "[ci_pre_xcodebuild] Scheme: PTSABoard"
echo "[ci_pre_xcodebuild] Project: $PROJECT"
echo "[ci_pre_xcodebuild] Complete"
