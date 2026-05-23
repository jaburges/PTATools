---
name: promote-prod
description: >-
  Promote a validated PTA Tools beta build to a production GitHub release and
  optionally deploy to wilderptsa.net production. Use when the user says
  /promote-prod, promote to production, create production release, or ship
  plugin to prod after staging validation. NEVER run without explicit user
  confirmation in the current message.
disable-model-invocation: true
---

# Promote PTA Tools to production

Human-only workflow. **Agents must not run this unless the user explicitly asks in the current message** (e.g. "/promote-prod" or "create the production release now").

There is **no** "Mark as Production" button in the WordPress plugin. Promotion happens only through this skill + GitHub Actions.

Copy this skill to `~/.cursor/skills/promote-prod/SKILL.md` for Cursor to discover `/promote-prod` globally.

## Prerequisites

Before promoting, confirm with the user:

1. Beta was deployed to **staging** and the site loads (homepage, wp-json, admin).
2. `AZURE_PLUGIN_VERSION` in `Azure Plugin/azure-plugin.php` is the **production** semver (no `-beta` or `-rc` suffix).
3. Version was bumped from the last **production** GitHub release (auto-updater never downgrades).
4. Changes are committed and pushed to `main` (or the branch being released).

## Step 1 — Verify staging

From the repo root:

```bash
curl -s -o /dev/null -w "%{http_code}" --max-time 30 "https://wilderptsa-staging.azurewebsites.net/wp-json/"
curl -s -o /dev/null -w "%{http_code}" --max-time 30 "https://wilderptsa-staging.azurewebsites.net/wp-admin/admin-ajax.php?action=heartbeat"
```

Both should return `200` or `400` (admin-ajax without nonce). If not, **stop** and fix staging first.

## Step 2 — Verify version in source

```bash
grep "AZURE_PLUGIN_VERSION" "Azure Plugin/azure-plugin.php"
```

Record the version (e.g. `3.100`). It must not contain `beta` or `rc`.

## Step 3 — Create production GitHub release (manual workflow)

Run the **Create production plugin release** workflow:

```bash
cd /path/to/PTATools
VERSION="3.100"   # must match azure-plugin.php

gh workflow run release-production.yml \
  -f version="$VERSION" \
  -f confirm=PROMOTE

gh run list --workflow=release-production.yml --limit 1
gh run watch   # wait for success
```

This creates a **non-prerelease** GitHub release with `pta-tools.zip`. Other PTA sites will see it via the plugin auto-updater.

**Agents:** Do not use `gh release create` directly. Always use `release-production.yml`.

## Step 4 — Deploy to wilderptsa production (plugin only)

After the production release exists, deploy the same code to **production** (not slot swap for routine plugin updates):

```bash
git status   # must be clean
rm -rf build && mkdir -p build && rsync -a \
  --exclude='.git' --exclude='.github' --exclude='.cursor' \
  --exclude='*.zip' --exclude='PTATools.wiki' --exclude='node_modules' \
  --exclude='.env' --exclude='tests' --exclude='.DS_Store' \
  "Azure Plugin/" "build/azure-plugin/"
(cd build/azure-plugin && zip -rq ../../azure-plugin-flat.zip . -x "*.DS_Store")

az account set --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00
az webapp deploy \
  --resource-group PTSAWebsite \
  --name wilderptsa \
  --src-path azure-plugin-flat.zip \
  --type zip \
  --target-path "/home/site/wwwroot/wp-content/plugins/Azure Plugin" \
  --clean true
```

## Step 5 — Smoke test production

```bash
./infra/post-change-smoke.sh
```

## Beta releases (reminder)

- Tag `v*-beta*` or `v*-rc*` → `release-beta.yml` → GitHub **pre-release** → `deploy-staging.yml` deploys to staging.
- Auto-updater skips pre-releases and `-beta`/`-rc` semver tags.

## Optional — refresh staging DB from prod

Before a test cycle, user can run **PTA Tools → Main Settings → Platform Danger Zone → Sync Prod DB to Staging DB** on production.

## Forbidden

- Creating a non-prerelease GitHub release without user explicitly requesting promotion in this conversation.
- Running `release-production.yml` with a version that does not match `azure-plugin.php`.
- Slot-swapping staging → production for a small plugin-only change (use plugin deploy above unless user asked for full slot swap).
