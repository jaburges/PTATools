#!/usr/bin/env bash
# Switch existing WilderPTSAAFD default-route between WordPress App Service and summer SWA.
# Uses ARM REST for route updates so custom domains are not dropped (az afd route update can clear them).
set -euo pipefail

RG="${RG:-PTSAWebsite}"
PROFILE="${PROFILE:-WilderPTSAAFD}"
ENDPOINT="${ENDPOINT:-wilderptsa-c20b298090}"
ROUTE="${ROUTE:-default-route}"
TARGET="${1:-}" # wordpress | swa | container
SUB="${SUB:-$(az account show --query id -o tsv)}"
API="2024-02-01"
ACA_HOST="${ACA_HOST:-wilderptsa-wp.wittysky-40aa8bc1.westus2.azurecontainerapps.io}"

if [[ "$TARGET" != "wordpress" && "$TARGET" != "swa" && "$TARGET" != "container" ]]; then
  echo "Usage: $0 wordpress|swa|container" >&2
  echo "  wordpress  App Service (wilderptsa.azurewebsites.net)" >&2
  echo "  swa        summer placeholder Static Web App" >&2
  echo "  container  Container Apps site (wilderptsa-wp)" >&2
  exit 2
fi

TOKEN=$(az account get-access-token --resource https://management.azure.com --query accessToken -o tsv)

if [[ "$TARGET" == "swa" ]]; then
  OG_NAME="summer-swa-origin-group"
  SWA_HOST=$(az staticwebapp show -g "$RG" -n wilderptsa-summer-placeholder --query defaultHostname -o tsv)
  if ! az afd origin-group show -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" &>/dev/null; then
    az afd origin-group create -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" \
      --probe-request-type GET --probe-protocol Https --probe-path '/' --probe-interval-in-seconds 120 \
      --sample-size 4 --successful-samples-required 3 --additional-latency-in-milliseconds 50 -o none
  fi
  if ! az afd origin show -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" --origin-name origin-swa &>/dev/null; then
    az afd origin create -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" --origin-name origin-swa \
      --host-name "$SWA_HOST" --origin-host-header "$SWA_HOST" \
      --http-port 80 --https-port 443 --priority 1 --weight 1000 --enabled-state Enabled \
      --enforce-certificate-name-check true -o none
  fi
elif [[ "$TARGET" == "container" ]]; then
  OG_NAME="wilderptsa-aca-origin-group"
  # The origin host header MUST be the Container Apps FQDN. Verified 2026-08-03:
  # ACA ingress answers 404 to any other Host value, so forwarding the visitor's
  # wilderptsa.net host straight through takes the whole site down.
  #
  # The consequence is that WordPress sees the ACA hostname rather than the real
  # one, so it needs to read X-Forwarded-Host to emit correct URLs. Do not switch
  # this route until that is in place — see infra/aca-wordpress/CUTOVER.md.
  if ! az afd origin-group show -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" &>/dev/null; then
    az afd origin-group create -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" \
      --probe-request-type GET --probe-protocol Https --probe-path '/healthz.php' \
      --probe-interval-in-seconds 120 \
      --sample-size 4 --successful-samples-required 3 --additional-latency-in-milliseconds 50 -o none
  fi
  if ! az afd origin show -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" --origin-name origin-aca &>/dev/null; then
    az afd origin create -g "$RG" --profile-name "$PROFILE" --origin-group-name "$OG_NAME" --origin-name origin-aca \
      --host-name "$ACA_HOST" --origin-host-header "$ACA_HOST" \
      --http-port 80 --https-port 443 --priority 1 --weight 1000 --enabled-state Enabled \
      --enforce-certificate-name-check true -o none
  else
    az afd origin update -g "$RG" --profile-name "$PROFILE" \
      --origin-group-name "$OG_NAME" --origin-name origin-aca \
      --host-name "$ACA_HOST" --origin-host-header "$ACA_HOST" -o none || true
  fi
else
  OG_NAME="wilderptsa-origin-group-c20b298090"
  # Ensure WordPress origin host is restored
  az afd origin update -g "$RG" --profile-name "$PROFILE" \
    --origin-group-name "$OG_NAME" --origin-name origin-app \
    --host-name wilderptsa.azurewebsites.net \
    --origin-host-header wilderptsa.azurewebsites.net -o none || true
fi

ROUTE_URL="https://management.azure.com/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.Cdn/profiles/${PROFILE}/afdEndpoints/${ENDPOINT}/routes/${ROUTE}?api-version=${API}"
BODY=$(cat <<EOF
{
  "properties": {
    "customDomains": [
      {"id": "/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.Cdn/profiles/${PROFILE}/customDomains/wilderptsa-net"},
      {"id": "/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.Cdn/profiles/${PROFILE}/customDomains/www-wilderptsa-net"}
    ],
    "originGroup": {
      "id": "/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.Cdn/profiles/${PROFILE}/originGroups/${OG_NAME}"
    },
    "ruleSets": [],
    "supportedProtocols": ["Http", "Https"],
    "patternsToMatch": ["/*"],
    "forwardingProtocol": "HttpsOnly",
    "httpsRedirect": "Enabled",
    "linkToDefaultDomain": "Enabled",
    "enabledState": "Enabled"
  }
}
EOF
)

curl -sS -X PUT -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" \
  -d "${BODY}" "${ROUTE_URL}" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); p=d.get("properties",{}); print("origin", (p.get("originGroup") or {}).get("id","").split("/")[-1]); print("domains", len(p.get("customDomains") or [])); print("state", p.get("provisioningState"))'

for _ in $(seq 1 18); do
  ST=$(az afd route show -g "$RG" --profile-name "$PROFILE" --endpoint-name "$ENDPOINT" --route-name "$ROUTE" --query provisioningState -o tsv)
  [[ "$ST" == "Succeeded" ]] && break
  sleep 5
done

PURGE_URL="https://management.azure.com/subscriptions/${SUB}/resourceGroups/${RG}/providers/Microsoft.Cdn/profiles/${PROFILE}/afdEndpoints/${ENDPOINT}/purge?api-version=${API}"
curl -sS -X POST -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" \
  -d '{"contentPaths":["/*"]}' "${PURGE_URL}" -o /dev/null -w "purge_http=%{http_code}\n"

echo "Updated default-route → ${OG_NAME}"
echo "Verify: curl -sS https://wilderptsa.net/ | head"
