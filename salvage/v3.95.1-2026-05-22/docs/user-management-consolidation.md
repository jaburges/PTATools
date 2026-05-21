# User Management Consolidation — Design

**Status:** Approved (open items resolved 2026-05-05)
**Author:** Engineering, May 2026
**Supersedes:** AcyMailing as primary subscriber store

---

## 1. Goals

1. Make WordPress users (`wp_users` + `wp_usermeta`) the single source of truth for **identity** and for **bulk-email audience membership**. Eliminate the parallel AcyMailing subscriber list (`wp_acym_user`) once verified clean — but **only when the site admin manually disables AcyMailing**, never automatically.
2. Reuse existing roles. Centralize the parent population on the existing `parent` role and reuse the **already-configured SSO role** for `@{org_domain}` users. No new WP role types are introduced.
3. Onboard ~650 imported parents to live WordPress accounts via a **passwordless magic-link activation** flow — no plaintext passwords ever travel by email. Activation tokens persist plugin upgrades and restarts until they're 7 days expired; cleanup runs on plugin upgrade and only deletes already-expired rows.
4. Centralize bulk email through the existing PTA Tools **Newsletter** module, sending from a branded `noreply@{org_domain}` Mailgun sender (already configured for `wilderptsa.net` per site admin). Keep ACS for transactional 1-to-1 mail.

## 2. Non-goals

- Replacing transactional ACS sending — ACS stays.
- Migrating to a new auth provider — Azure AD SSO continues to be the primary path for `@{org_domain}` users; native WP login + magic-link is the path for everyone else.
- Building a custom CRM. Newsletter audience targeting reuses existing `wp_azure_newsletter_lists` with role-based criteria.
- Hardcoding the org domain. The plugin is multi-tenant by design (`wilderptsa.net`, `ltptsa.net`, future PTSAs); the `org_domain` setting from the setup wizard drives every domain check.
- Auto-creating WP user accounts for school staff in this scope. School-staff emails (`@lwsd.org` for the Wilder install) live as a custom newsletter list only. To be revisited in a follow-up.

## 3. Current state

| Concern | Where it lives today | Notes |
|---|---|---|
| Parent role | `Azure_Parent_Role` (`class-parent-role.php`) | Subscriber-cap clone with three meta flags: `_pta_login_disabled`, `_pta_force_password_change`, `_pta_last_login`. Self-heals on `admin_init`. |
| Subscriber → Parent migrator | `Azure_Parent_Role_Admin::ajax_migrate` | Already correct: removes `subscriber` role, adds `parent`, leaves login state untouched. Batches of 100. |
| **Org domain** | `Azure_Settings::get_setting('org_domain', '')` | Set during the setup wizard (`class-setup-wizard.php:300`). Auto-derived from `WP_HOME` if blank. Used as the SSO domain filter. **Single source of truth — never hardcode.** |
| **SSO role assignment** | `Azure_SSO_Sync::get_sso_role()` (lines 505–538) | Two-mode: (a) `sso_use_custom_role=true` → ensures a role exists with slug = `sanitize_key(strtolower(sso_custom_role_name))` (defaults to `azuread`) and assigns it; (b) otherwise uses `sso_default_role`. Whatever this returns IS "the PTSA role" for newsletter purposes. |
| SSO domain restriction | `Azure_SSO_Sync` lines 739–742 | Only accounts where `email_domain == org_domain` get synced. Other domains are skipped automatically. |
| Parent + Children importer | Referenced as `class-parent-children-importer.php` in `.deploy-stage` and `.build`, **not present in active source tree** | Needs to be built/restored with the upsert contract in §6. |
| Connected family + children | `wp_azure_user_children`, `wp_azure_connected_family`, `wp_azure_connected_family_meta` (via `Azure_User_Children`) | Family-aware lookups; supports primary + secondary parent on a single family. |
| AcyMailing subscribers | `wp_acym_user` (3rd-party plugin) | ~650 rows. Read-only access via `Azure_Diagnostics_API` (which already has a missing-from-acymailing diff). |
| Newsletter lists | `wp_azure_newsletter_lists`, `wp_azure_newsletter_list_members` | Three list types: `all_users`, `role`, `custom`. Role-based lists query WP directly — no sync layer. |
| Newsletter sender | `Azure_Newsletter_Sender` | Pluggable backends: Mailgun, SendGrid, SES, SMTP, Office365. **Mailgun already configured for `wilderptsa.net`.** |
| Newsletter queue | `Azure_Newsletter_Queue`, `Azure_Newsletter_Sender` + `Azure_Newsletter_Tracking` | Throttling, retries, open/click pixels, unsubscribe tokens, bounce handling — all already implemented. |
| Transactional mail | `Azure_Email_Mailer` (Graph / HVE / **ACS**) | ACS configured with verified DKIM/DKIM2/SPF/DMARC on Azure-managed sender domain. Sender hostname is a 36-char GUID — fine for txn but ugly for branded mass mail. |

## 4. Target state

### 4.1 WP roles

**No new WP roles are introduced.** The model uses two existing roles:

| Role | Source | Auth | Newsletter list automatically populated |
|---|---|---|---|
| `parent` | Existing — registered by `Azure_Parent_Role::register_role()` | Local password set after magic-link activation | "Parents" (role-based) |
| *SSO-configured role slug* (resolved via `Azure_SSO_Sync::get_sso_role()`) | Existing — created on-demand by SSO sync | Azure AD SSO via Entra | "PTSA Volunteers" (role-based, criteria points at the resolved slug) |

The **resolved SSO slug** is computed once at list-seed time and re-resolved any time `sso_default_role`, `sso_use_custom_role`, or `sso_custom_role_name` changes. We add a settings filter (`update_option_*` hook) that updates the "PTSA Volunteers" list criteria automatically so the list never drifts from the SSO config.

**Important consequence:** if the existing site is currently using `sso_default_role = subscriber`, then "PTSA Volunteers" would target `subscriber` users — which collides with the subscriber→parent bulk migrator. Mitigation: as part of Phase 1 setup, the admin must confirm the SSO role configuration (settings page already shows it). If they're on `subscriber`, prompt them to either (a) flip on `sso_use_custom_role` and pick a role name, or (b) explicitly set `sso_default_role` to a non-`subscriber` value before running the parent migration. This is surfaced as a one-time admin notice from `Azure_Parent_Role_Admin`.

### 4.2 Newsletter lists

| List name | Type | Criteria | How members are added |
|---|---|---|---|
| Parents | `role` | `{ roles: ['parent'] }` | Automatic — anyone with the role |
| PTSA Volunteers | `role` | `{ roles: [<resolved SSO slug>] }` | Automatic — anyone synced via SSO |
| School Staff | `custom` | n/a | Manual import of school-staff addresses from AcyMailing; no WP user created |
| All Site Users | `all_users` | n/a | Existing |

Role-based lists incur **zero ongoing sync cost** because `Azure_Newsletter_Lists::get_subscribers()` resolves them at send time via `WP_User_Query`.

### 4.3 Identity → role bucketing

Bucketing rule applied by both the AcyMailing migrator and the parent CSV importer (and, going forward, the SSO sync default-role hook). All comparisons use lowercased trimmed values.

```
let org_domain = Azure_Settings::get_setting('org_domain')
let sso_role   = Azure_SSO_Sync::resolve_configured_role_slug()
let school_staff_domains = Azure_Settings::get_setting('school_staff_domains', [])
                           // Wilder install: ['lwsd.org']
                           // ltptsa install: e.g. ['lwsd.org'] — same district

email = lowercase(trim(email))

# 1. Existing-user merge (catches both 'parent' and 'customer' WP users)
if existing WP user with this email:
    if user has any of {parent, sso_role, administrator, shop_manager, editor}:
        no role change   # Don't downgrade or duplicate
    elif user has 'customer' role:
        add 'parent' role (preserve 'customer' role and all order history,
                            shipping addresses, downloadable permissions, etc.)
    elif user has 'subscriber' role:
        remove 'subscriber', add 'parent'  # Same logic as bulk migrator
    else:
        add 'parent' role (additive)

    NEVER touch _pta_login_disabled or _pta_force_password_change on
        existing users — they're already active customers/parents.
    Do NOT overwrite first_name / last_name if WP already has values.
    Sync child/family rows from CSV (additive, family_id-aware).
    return "merged"

# 2. School staff — list only, no account
elif email_domain ∈ school_staff_domains:
    add to "School Staff" custom newsletter list
    return "staff_list_only"

# 3. PTSA volunteer — wait for SSO to activate
elif email_domain == org_domain:
    create WP user with role = sso_role
    set _pta_login_disabled = 1
        # Cleared automatically by Azure_SSO_Sync on first Entra sign-in.
        # No activation token issued — SSO is the activation path.
    return "pta_role_pending_sso"

# 4. Catch-all parent — magic-link activation
else:
    create WP user, role = 'parent'
    set _pta_login_disabled = 1
    set _pta_force_password_change = 1
    issue _pta_activation_token (HMAC-signed, 7-day expiry)
    return "parent_pending_activation"
```

### 4.4 Customer → parent merge (Q3 confirmation)

The end state is that all WooCommerce `customer`-role users become `parent` (with `customer` role preserved). To find existing customers when importing a parent CSV row, the importer matches by **either** Parent 1 email **or** Parent 2 email — both columns are checked against `wp_users.user_email` so a co-parent who already shopped on the site under their own email gets matched.

For each merged customer:
- WC order history, billing/shipping addresses, downloadable permissions, subscription state, refunds — all untouched.
- The user picks up the `parent` role on top of their `customer` role.
- Children + family are linked via `Azure_User_Children::ensure_family_for_user`.
- If the matched user is the **secondary parent**, they're added to the family record's `secondary_user_id` slot rather than creating a new family.

## 5. Welcome email & magic-link activation

### 5.1 Why no plaintext passwords

A bulk email containing 650 generated passwords trips three failure modes simultaneously: (1) high deletion + spam-flag rate from recipients who weren't expecting it, (2) password reuse / leak risk if the inbox is later compromised, (3) zero ability to expire the credential after delivery. The magic-link pattern avoids all three.

### 5.2 Activation flow

1. Importer creates user with two new usermeta keys:
   - `_pta_activation_token` = `hash_hmac('sha256', user_id . '|' . expires_at . '|' . wp_salt('auth'), wp_salt('auth'))`
   - `_pta_activation_expires_at` (mysql datetime UTC, +7 days)
2. Welcome email body has one CTA: **"Activate your {org_name} account"** → `{home_url}/?pta_activate=<token>&u=<user_id>`.
3. New `Azure_Parent_Activation` class hooks `init` priority 5 to handle `?pta_activate=…`:
   - Look up user by `u=`, fetch stored token + expiry.
   - HMAC-recompute and constant-time compare to the URL token. Reject mismatch.
   - Reject if `_pta_activation_expires_at < gmdate('Y-m-d H:i:s')`.
   - On success: `delete_user_meta` for `_pta_activation_token`, `_pta_activation_expires_at`, `_pta_login_disabled`. Programmatically log the user in. Redirect to `wc_get_account_endpoint_url('edit-account')` so the existing `_pta_force_password_change` flow takes over.
   - On failure (expired/forged): show a friendly "request a fresh activation link" page that triggers a re-send via `wp_ajax_nopriv_pta_resend_activation`.
4. After the user picks a password, `Azure_Parent_Role::clear_force_pw_on_password_change` (existing) clears the force-change flag.

### 5.3 Token persistence (Q5 confirmation)

Tokens **survive** plugin upgrades and restarts. They are only cleared when:

- The user successfully activates (immediate cleanup), OR
- A plugin upgrade or admin action runs the cleanup query *and* the row is genuinely past its expiry:

```sql
DELETE m1, m2 FROM {usermeta} m1
INNER JOIN {usermeta} m2 ON m1.user_id = m2.user_id
WHERE m1.meta_key = '_pta_activation_expires_at'
  AND m2.meta_key = '_pta_activation_token'
  AND m1.meta_value < UTC_TIMESTAMP();
```

This runs from the existing plugin-upgrade hook in `azure-plugin.php` (where the role registration already lives) — no new cron schedule needed (per resource-efficiency rule). A future-dated token always survives.

### 5.4 Sender configuration

- **Bulk welcome blast:** Newsletter module → `newsletter_sending_service = 'mailgun'` → `noreply@{org_domain}`. Newsletter queue throttles to Mailgun's per-minute cap (already implemented via `process_email_queue`).
- **Transactional mail (forgot-password, order receipts):** unchanged, continues via `Azure_Email_Mailer` → ACS. ACS Azure-managed domain has DKIM/DKIM2/SPF/DMARC all `Verified` (queried 2026-05-05).
- **Future improvement (parallel track):** verify `{org_domain}` as a custom sender domain in ACS so transactional mail also sends from a branded address on the Azure grant. 24–72 hr DNS verification — does not block this rollout.

### 5.5 Welcome email template (proposed body)

```
Subject: Your {org_name} account is ready — activate in one click

Hi {{first_name}},

Welcome! We've created an account for you on {org_domain} so you can
sign up for classes, manage your child(ren)'s information in one place,
and bid in this year's auction.

  → ACTIVATE MY ACCOUNT  ({{activation_url}})

This link is good for 7 days. After you click it you'll be asked to
set a password of your own.

If you didn't expect this email, you can ignore it — no account will
be activated until you click the link.

— {org_name}
```

No password is sent. The activation URL is the entire credential.

## 6. Code changes

### 6.1 New files

- `includes/class-parent-activation.php` — Token issuance + activation endpoint + expired-token cleanup. ~200 LOC. Includes a static `issue_token($user_id)` helper used by the importer.
- `includes/class-parent-children-importer.php` — Restored from `.deploy-stage` reference, refactored to the §4.3 contract. CSV upload, dry-run preview (counts of merged / staff_list_only / pta_role_pending_sso / parent_pending_activation / skipped), then commit. Reuses `Azure_User_Children::ensure_family_for_user` and `Azure_User_Children::save_child`. Marks every created user with `_pta_imported_at` (mysql datetime) for audit + rollback.
- `includes/class-acymailing-migrator.php` — One-shot tool. Reads `wp_acym_user`, applies §4.3 bucketing, writes per-row results to `wp_azure_activity_log`. Includes a **dry-run** mode that produces a CSV diff (email, action, before-role, after-role) for review.
- `admin/parent-children-import-page.php` — Admin UI: dry-run button, results panel, commit button, audit log. Loaded only on admin requests per the workspace's resource-efficiency rule.
- `admin/acymailing-migration-page.php` — Same UI pattern, just sourced from AcyMailing instead of CSV upload.

### 6.2 Modifications

- `includes/class-sso-sync.php`
  - Extract `get_sso_role()` (currently private) into a public **`resolve_configured_role_slug()`** method that returns the same slug. The newsletter-list seed and the parent-role-admin call it.
  - Hook `update_option_azure_settings` (or whatever option name persists settings) to re-seed the "PTSA Volunteers" list criteria when `sso_default_role` / `sso_use_custom_role` / `sso_custom_role_name` changes.
- `includes/class-parent-role.php`
  - **No new role registration.** This file stays as-is.
- `includes/class-parent-role-admin.php`
  - Add a one-time admin notice (`admin_notices` hook) that warns if `sso_default_role == 'subscriber'` and `sso_use_custom_role == false` — in that state, running the parent migration would also pull SSO users into the parent bucket. Notice links to the SSO settings page.
- `includes/class-newsletter-module.php` (or an upgrade hook in `azure-plugin.php`)
  - On plugin upgrade, ensure the three target lists exist with the right type/criteria. Idempotent. Gated on `get_option('azure_pta_lists_seeded_v1', '0')`.
- `azure-plugin.php` (upgrade hook)
  - Call `Azure_Parent_Activation::cleanup_expired_tokens()` on plugin version bump.

### 6.3 Database

- **Schema:** no new tables. All new state is `usermeta` + the existing `wp_azure_newsletter_*` tables.
- **New usermeta keys:**
  - `_pta_activation_token` (string, HMAC) — cleared on activation
  - `_pta_activation_expires_at` (mysql datetime UTC) — cleared on activation
  - `_pta_imported_at` (mysql datetime UTC) — set once on import; never cleared (audit trail + rollback marker)
  - `_pta_imported_source` (`csv` | `acymailing` | `sso`) — set once on import
- **New options:** `azure_pta_lists_seeded_v1` (bool, gate the one-time seed)

### 6.4 Resource-efficiency compliance

Per `.cursor/rules/wordpress-resource-efficiency.mdc`:

- New importer + migration classes are loaded **only when `is_admin()`** (gated in `azure-plugin.php`'s `require_module_files()`).
- Activation endpoint: `Azure_Parent_Activation::handle_activation` early-returns if `!isset($_GET['pta_activate'])` so it costs effectively nothing for normal page loads.
- No new `cron_schedules` filter or `wp_schedule_event` registrations — token cleanup runs on plugin upgrade only.
- Newsletter list seed runs once and gates on `get_option('azure_pta_lists_seeded_v1')`.
- All `org_domain` / SSO-role lookups go through `Azure_Settings::get_all_settings()` (request-scoped static).

## 7. Cutover sequence

In order:

1. **Phase 1 (foundation, no user-visible change)**
   1. Add `Azure_Parent_Activation` class + token-cleanup upgrade hook.
   2. Refactor `Azure_SSO_Sync::get_sso_role()` to public `resolve_configured_role_slug()`.
   3. Seed the three newsletter lists (Parents, PTSA Volunteers, School Staff) idempotently on plugin upgrade.
   4. Add the SSO-default-role admin notice.
   5. Smoke-test on staging slot first (currently stopped — restart for this work, then re-stop before traffic events).
2. **Phase 2 (importer + migration tools)**
   1. Build importer (`class-parent-children-importer.php`) with dry-run UI.
   2. Build AcyMailing migrator with dry-run UI.
   3. Run dry-run against current AcyMailing data → review diff CSV with site admin.
   4. Commit migration. Re-run `Azure_Diagnostics_API` AcyMailing diff — expected delta `0` for emails the bucketing rule didn't intentionally skip.
   5. **AcyMailing stays installed and active** until the site admin manually disables it. The plugin code never deactivates AcyMailing automatically.
3. **Phase 3 (welcome blast)**
   1. Confirm Mailgun config in Newsletter Settings (`newsletter_mailgun_api_key`, `newsletter_mailgun_domain`, region).
   2. Build welcome-template newsletter, target the Parents list, dry-send to a small internal cohort first (e.g. 5 admin addresses), verify open + activation flow end-to-end.
   3. Schedule the bulk send. Newsletter queue throttles automatically.
   4. Monitor `wp_azure_newsletter_stats` for opens, clicks, bounces. `Azure_Newsletter_Bounce` already auto-blocks hard bounces.

## 8. Rollback plan

| Failure | Rollback |
|---|---|
| Activation endpoint broken | One-line revert in `Azure_Parent_Activation`; existing `_pta_login_disabled` users remain locked out cleanly. |
| Importer creates duplicate accounts | Importer wraps each user create in a `wpdb` transaction. Manual rollback drops users with `_pta_imported_at >= <run-start>` AND `_pta_imported_source = 'csv'` via a one-shot tool in the admin page. |
| AcyMailing migration miscategorized users | Each migrated row writes a `before/after` snapshot to `wp_azure_activity_log`. Reverse tool reads the log and restores prior role assignment. |
| Welcome blast sends with wrong template | Newsletter queue's `pause` button stops processing immediately. Already-sent rows can't be unsent but the template is fixable mid-blast. |
| Mailgun rate limit hit | Queue retries with exponential backoff (already implemented). No data loss. |
| `sso_default_role` was `subscriber` and PTSA volunteers got migrated to `parent` | Reverse with `Azure_Parent_Role_Admin` (new "demote to SSO role" batch) — uses the `_pta_imported_source` meta to identify only the at-risk users. |

## 9. Resolved decisions (locked-in 2026-05-05)

| # | Decision | Status |
|---|---|---|
| 1 | No new "PTSA volunteer" WP role; reuse the slug already returned by `Azure_SSO_Sync::get_sso_role()`. Domain check uses `org_domain` setting from setup wizard — never hardcoded. | ✅ Locked |
| 2 | Newsletter unsubscribe never strips a WP role. Parents can unsubscribe from any list and still log in / shop. | ✅ Locked |
| 3 | Existing `customer`-role users get `parent` added on top; all order history and addresses preserved. Importer matches CSV rows by **either** Parent 1 email **or** Parent 2 email against existing WP user records. | ✅ Locked |
| 4 | AcyMailing stays installed and active until the site admin manually disables it. Plugin code never auto-deactivates AcyMailing. | ✅ Locked |
| 5 | Activation tokens persist plugin upgrades and restarts. Cleanup runs on plugin upgrade and only deletes `_pta_activation_expires_at < UTC_TIMESTAMP()`. No new cron. | ✅ Locked |

## 10. Acceptance criteria

- [ ] `Azure_SSO_Sync::resolve_configured_role_slug()` exists and returns the same slug `get_sso_role()` does today.
- [ ] Three newsletter lists exist (Parents, PTSA Volunteers, School Staff) with correct types/criteria. The PTSA Volunteers criteria slug stays in sync with SSO settings via the option-update hook.
- [ ] Activation endpoint logs in users + clears the disabled flag + redirects to set-password — verified via end-to-end manual test.
- [ ] Activation tokens survive a plugin upgrade (verified by a manual upgrade after issuing a token).
- [ ] Importer dry-run output matches expected counts; commit reduces `missing_from_acymailing` to `0` for non-skipped rows.
- [ ] Welcome blast sends from `noreply@{org_domain}` (not the `azurecomm.net` GUID) and passes SPF + DKIM + DMARC at major receivers (verified via Gmail "Show original").
- [ ] Co-parent matching: a CSV row whose Parent 2 email matches an existing WC `customer` user merges into that user's family without creating a duplicate account.
- [ ] No new code loads on front-end for non-admin requests (verified via `X-PTA-Trace: 1` header — `mods` list unchanged for `ctx:frontend`).
- [ ] Admin notice appears when `sso_default_role == 'subscriber' && sso_use_custom_role == false` warning that the parent migration would conflict with SSO users.

## 11. Future / out-of-scope

- School-staff WP accounts (deferred). When revisited, consider passwordless magic-link plugin or a custom `Azure_School_Staff_Auth` that issues a sign-in token per session rather than a password. For now: newsletter list only.
- ACS custom sender domain — verify `{org_domain}` in `Microsoft.Communication/EmailServices` so transactional mail can also brand. 24–72 hr DNS verification.
- PTA Module org-chart positions (Treasurer, VP) remain orthogonal to WP roles. Confirmed not in scope here.
