#!/usr/bin/env bash
# Inspect a published image and fail if a required plugin directory is absent.
# This is the check that would have caught 3.147.81: Dockerfile RUN tests
# passed, then VOLUME /var/www/html discarded Stripe from the pushed layers.
#
# Usage: verify-image-plugins.sh [registry/image:tag]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="$HERE/required-plugins.conf"
SUB="${SUB:-97f6936d-7300-4a49-a2ad-cbfee3b28e00}"
REGISTRY="${REGISTRY:-wilderptsaacr.azurecr.io}"
IMAGE_REF="${1:?image reference, e.g. wilderptsaacr.azurecr.io/wilderptsa-wp:3.147.82}"

required=(woocommerce)
while IFS=$'\t' read -r slug version entry; do
  [[ -z "${slug:-}" || "$slug" == \#* ]] && continue
  required+=("$slug")
done < "$CONF"

echo "==> listing plugins in $IMAGE_REF"
listing="$(az acr run --subscription "$SUB" -r "${REGISTRY%%.*}" --cmd \
  "docker run --rm --entrypoint ls ${IMAGE_REF} /var/www/html/wp-content/plugins" \
  /dev/null 2>&1)"
echo "$listing"

missing=0
for slug in "${required[@]}"; do
  if echo "$listing" | grep -qx "$slug" || echo "$listing" | grep -qE "(^|[[:space:]])${slug}([[:space:]]|$)"; then
    echo "    present: $slug"
  else
    echo "ERROR: $slug is not in the published image" >&2
    missing=1
  fi
done

if [[ "$missing" -ne 0 ]]; then
  echo "ERROR: published image is missing required plugins — do not deploy this tag" >&2
  exit 1
fi

echo "==> published image has every required plugin"
