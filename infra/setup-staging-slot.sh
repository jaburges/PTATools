#!/usr/bin/env bash
# Phase A.1: Fix staging slot config + mark slot-sticky settings on prod
# Idempotent — safe to re-run.
set -euo pipefail

RG=PTSAWebsite
APP=wilderptsa
SLOT=staging
STAGING_HOSTNAME="wilderptsa-staging-c20b298090-drccadb2badebhh5.z02.azurefd.net"

echo "==> [1/5] Reading prod-only settings to copy to staging..."
WP_REDIS_PASSWORD=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='WP_REDIS_PASSWORD'].value" -o tsv)
WP_EMAIL_CONNECTION_STRING=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='WP_EMAIL_CONNECTION_STRING'].value" -o tsv)

if [ -z "$WP_REDIS_PASSWORD" ] || [ -z "$WP_EMAIL_CONNECTION_STRING" ]; then
  echo "[FAIL] Could not read required settings from prod. Aborting."
  exit 1
fi

echo "==> [2/5] Adding missing settings to staging slot..."
az webapp config appsettings set -g "$RG" -n "$APP" --slot "$SLOT" --settings \
  WP_CACHE=true \
  "WP_REDIS_PASSWORD=$WP_REDIS_PASSWORD" \
  "WP_EMAIL_CONNECTION_STRING=$WP_EMAIL_CONNECTION_STRING" \
  WORDPRESS_MEMORY_LIMIT=512M \
  WORDPRESS_MAX_MEMORY_LIMIT=1024M \
  WORDPRESS_CRON_LOCATION=cron \
  "AFD_DOMAIN=$STAGING_HOSTNAME" \
  >/dev/null

echo "==> [3/5] Marking slot-sticky on PRODUCTION slot..."
DB_NAME=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='DATABASE_NAME'].value" -o tsv)
DB_HOST=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='DATABASE_HOST'].value" -o tsv)
BLOB_PROD=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='BLOB_CONTAINER_NAME'].value" -o tsv)
AFD_DOMAIN_PROD=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='AFD_DOMAIN'].value" -o tsv)
AFD_ENDPOINT_PROD=$(az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?name=='AFD_ENDPOINT'].value" -o tsv)

az webapp config appsettings set -g "$RG" -n "$APP" --slot-settings \
  "DATABASE_NAME=$DB_NAME" \
  "DATABASE_HOST=$DB_HOST" \
  "BLOB_CONTAINER_NAME=$BLOB_PROD" \
  "AFD_DOMAIN=$AFD_DOMAIN_PROD" \
  "AFD_ENDPOINT=$AFD_ENDPOINT_PROD" \
  >/dev/null

echo "==> [4/5] Marking same settings slot-sticky on STAGING slot..."
DB_NAME_STG=$(az webapp config appsettings list -g "$RG" -n "$APP" --slot "$SLOT" \
  --query "[?name=='DATABASE_NAME'].value" -o tsv)
DB_HOST_STG=$(az webapp config appsettings list -g "$RG" -n "$APP" --slot "$SLOT" \
  --query "[?name=='DATABASE_HOST'].value" -o tsv)
BLOB_STG=$(az webapp config appsettings list -g "$RG" -n "$APP" --slot "$SLOT" \
  --query "[?name=='BLOB_CONTAINER_NAME'].value" -o tsv)
AFD_DOMAIN_STG=$(az webapp config appsettings list -g "$RG" -n "$APP" --slot "$SLOT" \
  --query "[?name=='AFD_DOMAIN'].value" -o tsv)
AFD_ENDPOINT_STG=$(az webapp config appsettings list -g "$RG" -n "$APP" --slot "$SLOT" \
  --query "[?name=='AFD_ENDPOINT'].value" -o tsv)

# AFD_ENDPOINT might be missing on staging; only mark sticky if present
STICKY_ARGS=(
  "DATABASE_NAME=$DB_NAME_STG"
  "DATABASE_HOST=$DB_HOST_STG"
  "AFD_DOMAIN=$AFD_DOMAIN_STG"
)
if [ -n "$BLOB_STG" ]; then
  STICKY_ARGS+=("BLOB_CONTAINER_NAME=$BLOB_STG")
fi
if [ -n "$AFD_ENDPOINT_STG" ]; then
  STICKY_ARGS+=("AFD_ENDPOINT=$AFD_ENDPOINT_STG")
fi

az webapp config appsettings set -g "$RG" -n "$APP" --slot "$SLOT" --slot-settings \
  "${STICKY_ARGS[@]}" \
  >/dev/null

echo "==> [5/5] Starting staging slot..."
CURRENT_STATE=$(az webapp show -g "$RG" --name "$APP" --slot "$SLOT" --query state -o tsv)
if [ "$CURRENT_STATE" != "Running" ]; then
  az webapp start -g "$RG" --name "$APP" --slot "$SLOT" >/dev/null
  echo "    Started. Waiting 45s for warmup..."
  sleep 45
else
  echo "    Already running."
fi

echo ""
echo "==> Verifying staging serves..."
HTTP_CODE=$(curl -fsS -o /dev/null -w "%{http_code}" --max-time 30 \
  "https://$STAGING_HOSTNAME/" || echo "000")
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
  echo "    [OK] Staging slot returned HTTP $HTTP_CODE"
else
  echo "    [WARN] Staging returned HTTP $HTTP_CODE — may need a few more minutes to warm up"
  echo "    Verify manually: curl -I https://$STAGING_HOSTNAME/"
fi

echo ""
echo "==> Phase A complete."
echo "    Slot-sticky settings on production:"
az webapp config appsettings list -g "$RG" -n "$APP" \
  --query "[?slotSetting].name" -o tsv | sed 's/^/      - /'
