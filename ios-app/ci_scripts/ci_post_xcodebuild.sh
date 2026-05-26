#!/bin/sh
set -euo pipefail

echo "[ci_post_xcodebuild] PTSA Board build finished"
echo "[ci_post_xcodebuild] CI_XCODEBUILD_ACTION=${CI_XCODEBUILD_ACTION:-<unset>}"
echo "[ci_post_xcodebuild] CI_BUILD_NUMBER=${CI_BUILD_NUMBER:-<unset>}"
echo "[ci_post_xcodebuild] CI_BUILD_ID=${CI_BUILD_ID:-<unset>}"
echo "[ci_post_xcodebuild] CI_WORKFLOW=${CI_WORKFLOW:-<unset>}"

if [ -n "${CI_ARCHIVE_PATH:-}" ] && [ -d "$CI_ARCHIVE_PATH" ]; then
  echo "[ci_post_xcodebuild] Archive: $CI_ARCHIVE_PATH"
fi

echo "[ci_post_xcodebuild] Complete"
