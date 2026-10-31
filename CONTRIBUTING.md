# Contributing — PTA Tools (wilderptsa.net, lwptsa.net)

## This repo is the single source of truth

**All changes to the PTA Tools plugin running on `wilderptsa.net` or `lwptsa.net` must originate from a commit on this repository.** No exceptions.

This rule exists because we once shipped from two parallel sources without realising — one Cursor agent session direct-deploying via Kudu without committing, and another committing to this repo. Production drifted to **v3.95.1** while this repo stayed at **v3.53**. Reconciling the divergence cost a working day and forced us to abandon some work in both branches.

## What this means in practice

### Allowed deploy paths

The two sites are hosted differently. Check which one you are changing first.

1. **wilderptsa.net — Container Apps (`wilderptsa-wp`)**. Plugin, theme, mu-plugin and WordPress core code is baked into the image. Ship by building a new image from a clean `main` checkout and pointing the app at it:

   ```bash
   git status   # must show "nothing to commit, working tree clean"
   ./infra/wp-image/build.sh
   az containerapp update -g PTSAWebsite -n wilderptsa-wp \
     --subscription 97f6936d-7300-4a49-a2ad-cbfee3b28e00 \
     --image wilderptsaacr.azurecr.io/wilderptsa-wp:<tag>
   ```

   Then confirm the new revision is `Healthy` with 100% traffic, flush Redis (`az containerapp job start -g PTSAWebsite -n wp-redis-flush`) and run `infra/post-change-smoke.sh`. Full detail and rollback: `infra/wp-image/README.md`.

2. **lwptsa.net — App Service (`lwptsa`)**. Plugin-only zip deploy via Kudu with `Deploy-PtaTools.ps1 -Site lwptsa`, only after the change is committed and pushed. If you use `az webapp deploy` instead, `--target-path` is relative to `wwwroot` (`wp-content/plugins/Azure Plugin`) and **never** pass `--clean true` — it wipes the whole site.

3. **GitHub releases** — nothing deploys automatically:
   - **Push tag `v*-beta*` or `v*-rc*`** → `release-beta.yml` creates a GitHub **pre-release**.
   - **Production GitHub release** → run `release-production.yml` manually. Requires typing `PROMOTE` to confirm. Agents must never run this without explicit user request.

### Forbidden

- Copying plugin or theme files to the `wp-content` Azure Files share for `wilderptsa-wp`. Only `uploads` is mounted and OPcache never revalidates, so it silently does nothing.
- Installing or updating plugins, themes or WordPress core from wp-admin on `wilderptsa.net`. It reverts on the next container start.
- `az webapp deploy --clean true` against `lwptsa`.
- Direct file edits via the Kudu Bash / File Manager UI.
- Deploying from a working copy that has uncommitted changes.
- Uploading a plugin zip via WP admin's `Plugins → Add New → Upload Plugin`.
- WP admin's auto-update mechanism updating the plugin from any source other than this repo's GitHub releases (see "Auto-updater" below).

### When two people / agents are working concurrently

Use separate branches and merge through GitHub:

```text
agent-A → branch feature-a → PR into main
agent-B → branch feature-b → PR into main
main    → build image / release when ready
```

**Before starting work**, always `git fetch && git pull --rebase` on your working branch. If a merge conflict appears, resolve it via `git rebase --interactive` (or open it in Cursor's merge UI). Do not bypass the conflict by hand-copying files between working copies — that's exactly how the v3.95.1 drift happened.

## Versioning

`AZURE_PLUGIN_VERSION` in `Azure Plugin/azure-plugin.php` must be bumped on every release. The auto-updater (`azure_plugin_best_github_release()`) refuses to downgrade, so if you ship a version with a number lower than what's installed in production, **the update won't take effect**. Always bump up.

Current canonical version: see `Azure Plugin/azure-plugin.php` (`define('AZURE_PLUGIN_VERSION', ...)`).

## Auto-updater

The plugin includes `azure_plugin_best_github_release()` which:
- Polls `https://api.github.com/repos/jaburges/PTATools/releases`
- **Skips draft and pre-release** entries, and tags containing `-beta` or `-rc`
- Picks the newest release that ships a `pta-tools.zip` asset
- Refuses to install if the candidate version ≤ installed version
- Otherwise advertises an update to WP's plugin updater

This means **GitHub Releases are also part of the source of truth.** A release must:
1. Be tagged from a commit on `main`
2. Have a `pta-tools.zip` asset attached
3. Have a tag name matching the `AZURE_PLUGIN_VERSION` constant in the committed code

The release-building flows are in `.github/workflows/release-beta.yml` (pre-releases) and `release-production.yml` (manual production only).

## Forensic / recovery branches

If we ever end up with a drift situation again, the recovery pattern is:

1. Snapshot the live plugin into `salvage/v<VERSION>-<YYYY-MM-DD>/` (git-ignored, local only).
2. Generate per-file unified diffs vs the current `main` HEAD alongside it.
3. Stash any hybrid working-tree state into a `wip-<YYYY-MM-DD>-hybrid` branch and push it.
4. Decide which side is canonical, write a `CHANGE-DELTAS.md` listing every divergence + an in/out call per feature.
5. Execute the merge, bump version, commit, push, deploy.

## Quick references

- Release workflows: `.github/workflows/release-beta.yml`, `release-production.yml`
- Resource Group: `PTSAWebsite` in Azure subscription `97f6936d-7300-4a49-a2ad-cbfee3b28e00` (Microsoft Grant) for wilderptsa.net
- wilderptsa.net image build and rollback: `infra/wp-image/README.md`; Container Apps jobs: `infra/aca-wordpress/`
- Plugin folder name: `Azure Plugin` (NOTE the space and capital A) — it must match `Azure Plugin/azure-plugin.php` in `active_plugins`
- Smoke-test script: `infra/post-change-smoke.sh`
