#!/usr/bin/env bash
#
# Assemble and publish the production WordPress image.
#
# Pulls the wp-content export out of the migration blob container, overlays the
# plugin from this repository, and pushes a multi-tagged image to GHCR.
#
# Usage:
#   ./build.sh                 # tag from the plugin version, plus :latest
#   ./build.sh --tag 2026-08-01
#   ./build.sh --local         # build only, do not push (for inspection)

set -euo pipefail

SUB="${SUB:-97f6936d-7300-4a49-a2ad-cbfee3b28e00}"
STORAGE_ACCOUNT="${STORAGE_ACCOUNT:-wilderptsac20b298091}"
MIGRATION_CONTAINER="${MIGRATION_CONTAINER:-migration}"
CODE_BLOB="${CODE_BLOB:-wp-code.tar.gz}"
REGISTRY="${REGISTRY:-wilderptsaacr.azurecr.io}"
IMAGE="${IMAGE:-$REGISTRY/wilderptsa-wp}"

# Container Apps runs amd64. Building on Apple Silicon without this produces an
# arm64 image that fails to start with an exec format error.
PLATFORM="linux/amd64"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
CONTEXT="$HERE/.context"

TAG=""
PUSH=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)   TAG="$2"; shift 2 ;;
    --local) PUSH=0; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

if [[ -z "$TAG" ]]; then
  TAG="$(grep -m1 -E '^ \* Version:' "$REPO_ROOT/Azure Plugin/azure-plugin.php" \
         | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
  [[ -n "$TAG" ]] || { echo "could not derive a tag from the plugin version" >&2; exit 1; }
fi

echo "==> image  : $IMAGE:$TAG"
echo "==> platform: $PLATFORM"

rm -rf "$CONTEXT"
mkdir -p "$CONTEXT/wp-content" "$CONTEXT/azure-plugin"

echo "==> downloading wp-content export ($CODE_BLOB)"
# The signed-in user has control-plane rights but not a Storage Blob Data role,
# so the account key is used rather than --auth-mode login.
SA_KEY="$(az storage account keys list \
  --subscription "$SUB" -g PTSAWebsite -n "$STORAGE_ACCOUNT" \
  --query '[0].value' -o tsv)"

az storage blob download \
  --account-name "$STORAGE_ACCOUNT" \
  --account-key "$SA_KEY" \
  --container-name "$MIGRATION_CONTAINER" \
  --name "$CODE_BLOB" \
  --file "$CONTEXT/wp-content.tar.gz" \
  --only-show-errors >/dev/null

echo "==> verifying archive integrity"
gzip -t "$CONTEXT/wp-content.tar.gz"
sha256sum "$CONTEXT/wp-content.tar.gz" 2>/dev/null || shasum -a 256 "$CONTEXT/wp-content.tar.gz"

echo "==> extracting"
tar -xzf "$CONTEXT/wp-content.tar.gz" -C "$CONTEXT/wp-content"
rm -f "$CONTEXT/wp-content.tar.gz"

# The export may be rooted at wp-content/ or at its children depending on how it
# was produced. Normalise both shapes.
if [[ -d "$CONTEXT/wp-content/wp-content" ]]; then
  mv "$CONTEXT/wp-content/wp-content"/* "$CONTEXT/wp-content/"
  rmdir "$CONTEXT/wp-content/wp-content"
fi

for d in themes plugins mu-plugins; do
  if [[ ! -d "$CONTEXT/wp-content/$d" ]]; then
    echo "    creating empty $d (absent from export)"
    mkdir -p "$CONTEXT/wp-content/$d"
  fi
done

if [[ -d "$CONTEXT/wp-content/uploads" ]]; then
  echo "==> discarding uploads from the image (they belong on the file share)"
  rm -rf "$CONTEXT/wp-content/uploads"
fi

# Drop the exported copy of the plugin so the repository version is the only one
# that lands in the image.
rm -rf "$CONTEXT/wp-content/plugins/Azure Plugin"

echo "==> staging plugin $TAG from the repository"
cp -R "$REPO_ROOT/Azure Plugin/." "$CONTEXT/azure-plugin/"

cp "$HERE/Dockerfile" "$HERE/php-opcache.ini" "$HERE/php-wordpress.ini" \
   "$HERE/apache-wp.conf" "$HERE/healthz.php" "$CONTEXT/"
cp -R "$HERE/mu-plugins" "$CONTEXT/mu-plugins"

echo "==> contents going into the image"
printf '    themes    : %s\n' "$(ls -1 "$CONTEXT/wp-content/themes"    2>/dev/null | tr '\n' ' ')"
printf '    plugins   : %s\n' "$(ls -1 "$CONTEXT/wp-content/plugins"   2>/dev/null | wc -l | tr -d ' ') directories"
printf '    mu-plugins: %s\n' "$(ls -1 "$CONTEXT/wp-content/mu-plugins" 2>/dev/null | wc -l | tr -d ' ') entries"

BUILD_ARGS=(--platform "$PLATFORM" -t "$IMAGE:$TAG" -t "$IMAGE:latest" -f "$CONTEXT/Dockerfile" "$CONTEXT")
if [[ "$PUSH" -eq 1 ]]; then
  echo "==> signing in to $REGISTRY"
  az acr login --subscription "$SUB" -n "${REGISTRY%%.*}" >/dev/null
  BUILD_ARGS+=(--push)
else
  BUILD_ARGS+=(--load)
fi

echo "==> building"
docker buildx build "${BUILD_ARGS[@]}"

echo
echo "done: $IMAGE:$TAG"
[[ "$PUSH" -eq 1 ]] && echo "revision update: az containerapp update -g PTSAWebsite -n wilderptsa-wp --image $IMAGE:$TAG"
