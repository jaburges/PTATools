# Contributing — PTA Tools (wilderptsa.net)

## This repo is the single source of truth

**All changes to the PTA Tools plugin running on `wilderptsa.net` must originate from a commit on this repository.** No exceptions.

This rule exists because we recently shipped from two parallel sources without realising — one Cursor agent session direct-deploying via Kudu without committing, and one (this) Cursor agent session committing to this repo. Production drifted to **v3.95.1** in the other session while this repo stayed at **v3.53**. Reconciling the divergence cost a working day and forced us to abandon some work in both branches. Full incident record: `salvage/v3.95.1-2026-05-22/SALVAGE-NOTES.md` + `CHANGE-DELTAS.md` (repo root).

## What this means in practice

### Allowed deploy paths

1. **GitHub Actions CI** — staging auto-deploys; production is manual:
   - **Push to `dev` or `main`** (path-filtered) → `deploy-staging.yml` deploys the plugin to the staging slot and runs a tolerant smoke test.
   - **Production** is never auto-promoted on push. When staging looks good, promote one of two ways:
     - **Slot swap (preferred when staging is the whole site under test):** run `promote-prod.yml` manually from the Actions tab. It pre-flights staging HTTP health, swaps staging → production, smoke-tests prod, and auto-rolls back on failure.
     - **Plugin-only Kudu deploy:** use the `az webapp deploy` command below against production directly (same as we've used for hotfixes).

2. **Manual Kudu push** (preferred for hot-fixes / when CI is broken) — only after the change is **committed and pushed to this repo first**. Use:
   ```bash
   # build from CLEAN working tree (no uncommitted changes)
   git status   # must show "nothing to commit, working tree clean"
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

### Forbidden

- Direct file edits via the Kudu Bash / File Manager UI.
- `az webapp deploy` from a working copy that has uncommitted changes.
- Uploading a plugin zip via WP admin's `Plugins → Add New → Upload Plugin`.
- Restoring from UpdraftPlus backups that contain plugin files without first reconciling them against this repo.
- WP admin's auto-update mechanism updating the plugin from any source other than this repo's GitHub releases (see "Auto-updater" below).

### When two people / agents are working concurrently

Use separate branches and merge through GitHub:

```text
agent-A → branch dev-agent-a → PR into dev
agent-B → branch dev-agent-b → PR into dev
dev   → main (CI deploys to staging; you promote to prod when ready)
```

**Before starting work**, always `git fetch && git pull --rebase` on your working branch. If a merge conflict appears, resolve it via `git rebase --interactive` (or open it in Cursor's merge UI). Do not bypass the conflict by hand-copying files between working copies — that's exactly how the v3.95.1 drift happened.

## Versioning

`AZURE_PLUGIN_VERSION` in `Azure Plugin/azure-plugin.php` must be bumped on every release. The auto-updater (`azure_plugin_best_github_release()`) refuses to downgrade, so if you ship a version with a number lower than what's installed in production, **the update won't take effect**. Always bump up.

Current canonical version: see `Azure Plugin/azure-plugin.php` (`define('AZURE_PLUGIN_VERSION', ...)`).

## Auto-updater

The plugin includes `azure_plugin_best_github_release()` which:
- Polls `https://api.github.com/repos/jaburges/PTATools/releases/latest`
- Picks the newest release that ships a `pta-tools.zip` asset
- Refuses to install if the candidate version ≤ installed version
- Otherwise advertises an update to WP's plugin updater

This means **GitHub Releases are also part of the source of truth.** A release must:
1. Be tagged from a commit on `main`
2. Have a `pta-tools.zip` asset attached
3. Have a tag name matching the `AZURE_PLUGIN_VERSION` constant in the committed code

The release-building flow is in `.github/workflows/release.yml`.

## Forensic / recovery branches

If we ever end up with a drift situation again, the recovery pattern is:

1. Snapshot prod via Kudu into `salvage/v<VERSION>-<YYYY-MM-DD>/`. Commit it.
2. Generate per-file unified diffs vs the current `dev` HEAD into `salvage/.../_diffs-pristine-vs-v<VERSION>/`. Commit them.
3. Stash any hybrid working-tree state into a `wip-<YYYY-MM-DD>-hybrid` branch and push it for posterity.
4. Decide which side is canonical, write a `CHANGE-DELTAS.md` at repo root listing every divergence + an in/out call per feature.
5. Execute the merge, bump version, commit, push, deploy.

The May 2026 incident's full artifacts are in `salvage/v3.95.1-2026-05-22/` and the `wip-2026-05-22-hybrid` branch on GitHub.

## Quick references

- Deploy workflows: `.github/workflows/deploy-staging.yml`, `promote-prod.yml`, `release.yml`
- Resource Group: `PTSAWebsite` in Azure subscription `97f6936d-7300-4a49-a2ad-cbfee3b28e00` (Microsoft Grant)
- Active plugin folder: `/home/site/wwwroot/wp-content/plugins/Azure Plugin` (NOTE the space; it is NOT `azure-plugin` lowercase — there's a separate folder by that name on prod that WordPress doesn't load)
- Plugin slug in `active_plugins`: `Azure Plugin/azure-plugin.php`
- Smoke-test script: `infra/post-change-smoke.sh`
- Site improvement / perf audit roadmap: `improvements.md` (repo root)
