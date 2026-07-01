<?php
/**
 * Standalone sanity check for Azure_Anti_Spam::check_signup().
 *
 * Not a full WP test suite (this repo has none) — just a fast, no-WP-
 * bootstrap-required regression check for the pattern classifier so a
 * future edit to class-anti-spam.php can be manually verified against
 * the known June 2026 spam wave plus a legitimate-user control group
 * before deploying.
 *
 * Run:  php tests/test-anti-spam-classifier.php
 * Exits non-zero if any case fails, so it can be wired into CI later.
 */

// Minimal WP shims so class-anti-spam.php's ABSPATH guard and its
// defensive function_exists()/class_exists() checks degrade to
// "toggle is default-on, no filters registered" instead of fataling.
define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../Azure Plugin/includes/class-anti-spam.php';

$cases = array(
    // --- Confirmed spam accounts (June 2026 wave) — must be BLOCKED ---
    array('elizabeth.roberts6386', 'elizabeth.roberts6386@gmail.com', true, 'name + 4-digit suffix'),
    array('benjamin.scott108447', 'benjamin.scott108447@gmail.com', true, 'name + 6-digit suffix'),
    array('charles.taylor104982', 'charles.taylor104982@gmail.com', true, 'name + 6-digit suffix'),
    array('osvaldoworsnop', 'rw_ashleysowers@falderewonek.site', true, 'hard-blocked .site TLD'),
    array('eric.brown17559', 'eric.brown17559@gmail.com', true, 'name + 5-digit suffix'),
    array('test206847', 'test206847@gmail.com', true, '"test" keyword'),
    array('v-c39fb607cfa4eb39d9cb0c8f', 'v-c39fb607cfa4eb39d9cb0c8f@cutemails.online', true, 'hex identifier + .online TLD'),
    array('test266967', 'test266967@gmail.com', true, '"test" keyword'),

    // --- Pre-existing gibberish heuristic — must still be BLOCKED ---
    array('KcIIFSLaHgonfglOrGeuar', 'KcIIFSLaHgonfglOrGeuar@gmail.com', true, 'pre-existing case-thrash heuristic'),
    array('', 'qytcnxcsjfykvcsyb@gmail.com', true, 'pre-existing consonant-run heuristic'),
    array('', 'someone@mailinator.com', true, 'pre-existing disposable domain list'),
    array('', 'someone@xnzqrkbjvm.online', true, 'pre-existing gibberish-SLD + soft throwaway TLD'),

    // --- New TLD hard-block, additional throwaway TLDs ---
    array('jordan.baker', 'jordan.baker@somecompany.xyz', true, 'hard-blocked .xyz TLD (even pronounceable SLD)'),
    array('', 'parent@school.top', true, 'hard-blocked .top TLD'),

    // --- Legitimate users — must PASS (not flagged) ---
    array('sarahmiller', 'sarah.miller@gmail.com', false, 'ordinary human name'),
    array('jordanhalpern', 'jordan.halpern@yahoo.com', false, 'pre-existing control case (real name cadence)'),
    array('mike123', 'mike123@gmail.com', false, '3-digit suffix, no separator (jersey/house number)'),
    array('sarah2024', 'sarah2024@gmail.com', false, 'plausible birth/grad year, no separator'),
    array('mjones1985', 'mjones1985@outlook.com', false, 'plausible year suffix'),
    array('christopherson', 'christopherson@gmail.com', false, 'pre-existing control case (long real surname)'),
    array('', 'jamie.burgess@wilderptsa.net', false, 'real PTSA org domain'),
    array('', 'parent@lwsd.org', false, 'real school domain'),
);

$failures = 0;
foreach ($cases as $case) {
    list($username, $email, $expect_block, $label) = $case;
    $reason = Azure_Anti_Spam::check_signup($username, $email, '');
    $blocked = ($reason !== null);
    $ok = ($blocked === $expect_block);
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %-28s %-40s expected=%s got=%s (%s)%s\n",
        $ok ? 'PASS' : 'FAIL',
        $username !== '' ? $username : '(no username)',
        $email,
        $expect_block ? 'BLOCK' : 'ALLOW',
        $blocked ? 'BLOCK(' . $reason . ')' : 'ALLOW',
        $label,
        $ok ? '' : '  <-- MISMATCH'
    );
}

printf("\n%d/%d cases passed.\n", count($cases) - $failures, count($cases));
exit($failures > 0 ? 1 : 0);
