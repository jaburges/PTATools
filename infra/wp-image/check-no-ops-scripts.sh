#!/usr/bin/env bash
#
# Refuse to build if any retired one-off ops script is in the build context.
#
# These were token-gated scripts uploaded straight onto the live site. They are
# no longer in the repository, but copies can survive on the site and come back
# through the wp-content export, so the image build checks for them by name.
#
# Usage: check-no-ops-scripts.sh <build-context-dir>

set -euo pipefail

CONTEXT="${1:?usage: check-no-ops-scripts.sh <build-context-dir>}"

RETIRED=(
  add-offline-bid-31942-denise.php
  audit-bids-for-product.php
  audit-lost-media.php
  backfill-child-names.php
  backup-probe.php
  bj-inspect.php
  celebration-dup-check.php
  cleanup-orphan-attachments.php
  consolidate-child-fields.php
  db-export-2026.php
  diag-te-runner-up.php
  dump-small-mysql-2026.php
  export-auction-winners.php
  fast-cleanup-orphan-attachments.php
  inspect-order-payment.php
  onedrive-media-probe.php
  orders-reports-save-probe.php
  orders-reports-widget-export-probe.php
  probe-te-meta.php
  restart-lego-auction-31613.php
  retire-tec.php
  role-cap-audit.php
  tec-state-probe.php
)

found=0
for name in "${RETIRED[@]}"; do
  while IFS= read -r -d '' hit; do
    echo "retired ops script in build context: ${hit#"$CONTEXT"/}" >&2
    found=1
  done < <(find "$CONTEXT" -type f -name "$name" -print0)
done

if [[ "$found" -ne 0 ]]; then
  echo "refusing to build: remove the files above from the export" >&2
  exit 1
fi
