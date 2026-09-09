#!/usr/bin/env bash
# Download pinned plugins into a wp-content/plugins directory and fail if
# anything on the required list is still missing. Used by build.sh so a
# stale migration export cannot ship without Stripe / Redis / Multiple Roles.
set -euo pipefail

plugins_dir="${1:?plugins dir}"
conf="${2:?required-plugins.conf}"
object_cache_dest="${3:-}"

mkdir -p "$plugins_dir"

ensure_plugin() {
  local slug="$1" version="$2" entry="$3"
  local dest="$plugins_dir/$slug"
  if [[ -f "$dest/$entry" ]]; then
    echo "    have $slug ($entry)"
    return 0
  fi
  echo "    fetching $slug $version"
  local zip
  zip="$(mktemp)"
  curl -fSL -o "$zip" "https://downloads.wordpress.org/plugin/${slug}.${version}.zip"
  unzip -q -o "$zip" -d "$plugins_dir"
  rm -f "$zip"
  if [[ ! -f "$dest/$entry" ]]; then
    echo "ERROR: $slug unzipped but $dest/$entry is missing" >&2
    return 1
  fi
}

while IFS=$'\t' read -r slug version entry; do
  [[ -z "${slug:-}" || "$slug" == \#* ]] && continue
  ensure_plugin "$slug" "$version" "$entry"
done < "$conf"

required_dirs=(woocommerce)
for slug in "${required_dirs[@]}"; do
  if [[ ! -d "$plugins_dir/$slug" ]]; then
    echo "ERROR: required plugin directory missing from export: $slug" >&2
    echo "present: $(ls -1 "$plugins_dir" | tr '\n' ' ')" >&2
    exit 1
  fi
  echo "    have $slug (from export)"
done

missing=0
while IFS=$'\t' read -r slug version entry; do
  [[ -z "${slug:-}" || "$slug" == \#* ]] && continue
  if [[ ! -f "$plugins_dir/$slug/$entry" ]]; then
    echo "ERROR: required plugin missing after fetch: $slug/$entry" >&2
    missing=1
  fi
done < "$conf"
if [[ "$missing" -ne 0 ]]; then
  exit 1
fi

if [[ -n "$object_cache_dest" ]]; then
  src="$plugins_dir/redis-cache/includes/object-cache.php"
  if [[ ! -f "$src" ]]; then
    echo "ERROR: redis-cache object-cache drop-in missing at $src" >&2
    exit 1
  fi
  mkdir -p "$(dirname "$object_cache_dest")"
  cp "$src" "$object_cache_dest"
  echo "    wrote object-cache drop-in"
fi

echo "==> plugins that will ship:"
ls -1 "$plugins_dir" | sed 's/^/    /'
