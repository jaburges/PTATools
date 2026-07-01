# Spam registration hardening — June/July 2026 wave

## Why this exists

Between 2026-06-16 and 2026-06-25 eight bot accounts registered on
wilderptsa.net through the `[pta_newsletter_signup]` self-service path
(WP-native registration has been structurally blocked since 3.141.0 —
see `Azure Plugin/includes/class-anti-spam.php` toggle 1). The existing
pattern filter (toggle 2) only caught fully-random gibberish usernames
(`KcIIFSLaHgonfglOrGeuar`) and a short disposable-email-provider list —
it missed this wave because the bots used **real dictionary words** as
name parts, with the bot-generated signal hidden in a numeric suffix or
a throwaway-TLD domain with a pronounceable second-level label:

| Username | Email | Registered |
|---|---|---|
| `elizabeth.roberts6386` | `elizabeth.roberts6386@gmail.com` | Jun 25 |
| `benjamin.scott108447` | `benjamin.scott108447@gmail.com` | Jun 24 |
| `charles.taylor104982` | `charles.taylor104982@gmail.com` | Jun 24 |
| `osvaldoworsnop` | `rw_ashleysowers@falderewonek.site` | Jun 21 |
| `eric.brown17559` | `eric.brown17559@gmail.com` | Jun 17 |
| `test206847` | `test206847@gmail.com` | Jun 17 |
| `v-c39fb607cfa4eb39d9cb0c8f` | `v-c39fb607cfa4eb39d9cb0c8f@cutemails.online` | Jun 16 |
| `test266967` | `test266967@gmail.com` | Jun 16 |

## New rules added (v3.141.12)

All added to `Azure_Anti_Spam::run_classifier()` in
`Azure Plugin/includes/class-anti-spam.php`, gated by the same
`enable_anti_spam_filter` toggle as the pre-existing heuristics, and
exercised by every existing call site (`registration_errors` hook,
`[pta_newsletter_signup]` REST endpoint, `spam-user-audit` diagnostic)
with no code changes needed at the call sites.

1. **"test" keyword** (`contains_test_keyword()`) — blocks if the
   username OR the email local-part contains the literal substring
   `test`, case-insensitive. Filterable off via
   `pta_antispam_block_test_keyword` if it ever proves too aggressive
   (e.g. a parent surnamed "Testa" — accepted false-positive risk per
   explicit request; flag for human review if it happens).

2. **Trailing digit-run suffix** (`has_suspicious_trailing_digit_run()`)
   — catches `name(.name)*NNN+` patterns using
   `/^[a-z]+(?:[._-]?[a-z]+)*?(\d{3,})$/i` to extract the trailing digit
   run, then applies a scaled threshold instead of one flat cutoff:
   - **5+ digits** → always blocked (no legitimate naming convention
     appends this many digits to a name).
   - **exactly 4 digits** → blocked *unless* it reads as a plausible
     year (1940–2029) appended directly to a single word with no
     separator (`sarah2024`, `mjones1985` pass).
   - **exactly 3 digits** → only blocked when the prefix is a
     multi-word name joined by `.`/`_`/`-` (`firstname.lastnameNNN`
     shape). A bare `mike123` passes — too common among real users to
     flag alone.

3. **Hex-identifier username** (`has_hex_identifier_segment()`) —
   splits the username on `-`/`_` and flags any segment that is ≥16
   characters, entirely `[0-9a-f]`, and contains at least one digit
   *and* one letter (rules out plain long numbers or plain long
   words). Catches `v-c39fb607cfa4eb39d9cb0c8f`-style machine-generated
   IDs.

4. **Hard-blocked throwaway TLDs** (`is_blocked_tld_domain()`) — email
   domains ending in `.site`, `.online`, `.xyz`, `.top`, `.click`, or
   `.info` are rejected **regardless of how plausible the second-level
   label looks** (this is what catches `cutemails.online` and
   `falderewonek.site`, which the pre-existing gibberish-SLD check
   missed because "cutemails" and "falderewonek" don't fail the
   random-string heuristic). Filterable via
   `pta_antispam_blocked_tld_suffixes` (returns/accepts an array of
   `.tld` strings) so the list can be tuned from an mu-plugin without a
   deploy if a legitimate domain ever gets caught.

Sanity-check harness: `tests/test-anti-spam-classifier.php` (no WP
bootstrap required — run `php tests/test-anti-spam-classifier.php`).
Covers all 8 confirmed spam accounts (must block), the pre-existing
gibberish/disposable-domain cases (must still block, i.e. no
regression), and a legitimate-user control group (must allow),
including the explicit false-positive guards (`mike123`, `sarah2024`,
`mjones1985`, real PTSA/school domains). All 22 cases passed before
this was shipped.

## Also extended: `/diagnostics/spam-user-cleanup`

Added an `explicit_logins` targeting mode (`logins=` body param, comma
or newline separated) that resolves each entry to a user by **exact**
`user_login` OR `user_email` match — no classifier re-evaluation. This
is the safest mode for a small, hand-verified list because it can't
silently widen scope; any entry that doesn't resolve is reported in
`unresolved_logins` and left untouched. Used to clean up the 8
confirmed accounts above (see "Cleanup procedure" below). Pre-existing
`ids=` (explicit numeric IDs) and automatic (`safe_to_delete` re-audit)
modes are unchanged.

## Recommended but NOT implemented this round

Evaluated per the request; these either need infrastructure this repo
doesn't have yet, or are a bigger lift than the "high value / low risk"
bar for this pass. Left here for a future task:

- **Full IP-based rate limiting on registration.** The
  `[pta_newsletter_signup]` REST endpoint already rate-limits at 5
  submissions/IP/hour via a transient (`class-newsletter-signup-shortcode.php`,
  `RATE_LIMIT_PER_HOUR`) — this was already in place, not new. Extending
  that same transient-based approach to the (currently-blocked)
  WP-native registration endpoints is low effort but was out of scope
  since those endpoints are already structurally 403'd by toggle 1.
- **Minimum time-on-page before submit.** Would need a JS timestamp
  hidden field + server-side check in the newsletter shortcode's REST
  handler. Reasonable follow-up (~20 lines) but adds a client-side
  dependency (JS-disabled visitors would need a server-side allowance)
  and the honeypot + pattern filter already cover this wave; holding
  off until there's evidence bots are submitting fast enough that this
  would matter.
- **reCAPTCHA/hCaptcha/Turnstile.** No existing captcha integration or
  site keys configured anywhere in the plugin or `wp-config.php` — this
  would be new infrastructure (site key provisioning, a Cloudflare/Google
  account, a settings UI), not a small lift. Recommend only if the
  pattern-based approach starts missing waves.
- **Akismet.** Not installed/active on wilderptsa.net (no `akismet`
  references anywhere in the active plugin tree). Akismet's registration
  check (`Akismet::rest_auto_check_comment` equivalent for signups) could
  replace some of this custom logic, but it's a paid API key + external
  dependency for a WordPress.com service; the in-house heuristic has
  zero external dependency and zero recurring cost, which matters more
  for a volunteer-run PTSA site. Worth reconsidering only if the pattern
  arms race escalates.

## Already-correct baseline (verified, no change needed)

- `users_can_register` — forced to `0` by toggle 1
  (`option_users_can_register` filter, priority 999) regardless of the
  raw DB option value. Confirmed via `GET /diagnostics/registration`.
- `default_role` — forced away from any dangerous role
  (`DANGEROUS_DEFAULT_ROLES`) back to `subscriber` if it's ever set to
  one. The only self-service account creation paths are SSO, the
  newsletter shortcode (creates `parent`), and WooCommerce checkout
  (creates `customer`).

## Manual test cases (for anyone changing this file without running the harness)

Run `php tests/test-anti-spam-classifier.php` — the file documents the
full case list. Key ones to keep passing:

| Input | Expected |
|---|---|
| `elizabeth.roberts6386` / `...@gmail.com` | **BLOCK** — digit run suffix |
| `v-c39fb607cfa4eb39d9cb0c8f` / `...@cutemails.online` | **BLOCK** — hex id + TLD |
| `test206847` / `test206847@gmail.com` | **BLOCK** — "test" keyword |
| `someone@parent.site` | **BLOCK** — hard TLD |
| `mike123` / `mike123@gmail.com` | **ALLOW** — 3-digit suffix, no separator |
| `sarah2024` / `sarah2024@gmail.com` | **ALLOW** — plausible year, no separator |
| `jamie.burgess@wilderptsa.net` | **ALLOW** — real org domain |

## Cleanup procedure used for the 8 confirmed accounts (2026-07-01)

1. Dry run against the exact list, confirm the resolved user IDs match
   exactly 8 accounts (no more, no less), and that no `unresolved_logins`
   remain unexpectedly:

   ```bash
   curl -sS -X POST 'https://wilderptsa.net/wp-json/pta-tools/v1/diagnostics/spam-user-cleanup' \
     -H "X-Diag-Key: $AZURE_DIAGNOSTICS_API_KEY" \
     -H 'Content-Type: application/json' \
     -d '{
       "confirm": "yes-i-am-sure",
       "dry_run": 1,
       "logins": "elizabeth.roberts6386,benjamin.scott108447,charles.taylor104982,osvaldoworsnop,eric.brown17559,test206847,v-c39fb607cfa4eb39d9cb0c8f,test266967"
     }'
   ```

2. Verify `targeted_ids` has exactly 8 entries, `unresolved_logins` is
   empty, and `protected` is empty (none of the 8 are admins/self).

3. Re-run with `dry_run: 0` to execute. The route writes a JSON backup
   to `wp-content/uploads/pta-cleanup-backups/spam-users-<timestamp>.json`
   before deleting, and reassigns any orphaned content to `reassign`
   (default user id 1).

4. Verify deletion via `spam-user-audit?days=30&include_safe=1` — the 8
   logins/emails should no longer appear, and a spot check of the admin
   account + known real parent accounts confirms they're untouched.

See the agent transcript for this task for the actual request/response
pairs (with the diagnostics key redacted).

## Executed 2026-07-01 (post DB-cutover-incident resolution)

Confirmed `https://wilderptsa.net/wp-json/` (200, valid JSON) and
`/wp-admin/` (302) before touching anything; full smoke test 6/6.

Dry run against the exact 8 logins resolved to exactly 8 user IDs
(`1296`–`1303`), zero `unresolved_logins`, zero `protected`, all
independently cross-checked against `spam-user-audit` — same
login/email, 0 WooCommerce orders, 0 posts authored, `safe_to_delete:
true`, and the classifier reason matched the exact new rule expected
for each account (`username_digit_run_suffix` ×4, `username_test_keyword`
×2, `blocked_tld_domain` ×1, `username_hex_identifier` ×1).

Executed with `dry_run: 0`. **Result: `deleted_count: 8`, `failed_count:
0`.** Backup written to
`wp-content/uploads/pta-cleanup-backups/spam-users-20260701-185428.json`
(on the server; not extracted into this repo — restore from that file
via Kudu VFS if ever needed).

Post-delete verification: re-running the audit shows `users_examined`
dropped from 11 → 3 in the same 45-day window and `spam_count: 0` — none
of the 8 logins/emails appear anywhere. No MU-plugin fallback was
needed (the diagnostics REST API was directly reachable once the
concurrent DB-cutover incident was resolved).

Real-user spot checks (untouched, confirmed present post-cleanup):
- `jamieb` / `jamieb@wilderptsa.net` — administrator (site owner)
- `maybee`, `akimball`, `tinamu` — real customers/parents with live
  WooCommerce order history

### Additional accounts flagged by the classifier — reviewed, NOT deleted

A full historical audit (`days=3650`, `include_safe=1`) found 33 other
accounts the classifier currently flags. **None were deleted** — none
match the confirmed-wave profile (no throwaway-TLD domains, no hex
identifiers, no additional "test" accounts besides one already-known
false positive). Manual review of registration timestamps strongly
suggests most of these are a **false-positive cluster from a legitimate
bulk parent-data import**, not bots:

- **~23 of the 33** registered in an 11-minute burst on 2026-05-06
  (06:44–06:55) or a 3-minute burst on 2026-05-05 (19:06–19:08), all
  with role `parent` or `customer`, mainstream email domains
  (gmail/hotmail/outlook/icloud/yahoo), and several with real
  WooCommerce orders — consistent with `Azure_Parent_Migration`'s bulk
  CSV-import path, not self-service bot signups.
- **The trailing-digit-run rule (added this round) is the biggest
  contributor to false positives here** — several real parents,
  disproportionately those with East-Asian-convention email handles
  (e.g. a birth-year+ID-style local part, common for `@qq.com`-style
  or Chinese/Vietnamese-heritage users who carried a numeric handle
  over to Gmail/Hotmail), have email local-parts that natively contain
  long digit runs. This is exactly the false-positive risk flagged (but
  not fully avoidable) when the rule was designed — recommend the user
  decide whether to raise the digit-run threshold (e.g. require 6+
  digits, or 5+ instead of 4+ for the "not a year" case) or add a
  per-domain/role exemption before relying on this rule for anything
  beyond registration-time blocking of *new* signups.
- `JamieTest` / `jamieeburgess@outlook.com` — flagged by the new "test"
  keyword rule. This is the site owner's own known test account
  (pre-existing, not a new signup) — expected/accepted false positive,
  explicitly called out as a risk when the rule was added.
- `marybillings` / `marybillings@ptoffice.com` — flagged by the
  pre-existing (not new) gibberish-username heuristic; has 17 authored
  posts, i.e. an active PTA-office content author. Clear false
  positive, unrelated to this round's changes.
- No accounts registered in the last 14 days at all (0 in the
  `days=14` window) — no evidence of new spam since the confirmed wave
  ended June 25.

**Recommendation:** none of these 33 should be deleted without
individual manual confirmation by the PTSA office (they can look up
each by email to confirm real-parent identity). If a genuine additional
bot is later confirmed among them, reuse the `explicit_logins` cleanup
mode exactly as above.
