#!/usr/bin/env bash
#
# Run every standalone test script in this directory.
#
# These are plain PHP scripts with a hand-rolled WordPress shim (wp-shim.php),
# not a WP test-suite install — they need nothing but a PHP binary, so they can
# run locally or in CI before a deploy.
#
# Usage:  tests/run-all.sh
# Exits non-zero if any suite fails.

set -uo pipefail

cd "$(dirname "$0")/.."

failed=0
for test_file in tests/test-*.php; do
    echo "--- ${test_file}"
    if ! php "${test_file}"; then
        failed=$((failed + 1))
        echo "!!! FAILED: ${test_file}"
    fi
done

if [ "${failed}" -ne 0 ]; then
    echo "${failed} suite(s) failed."
    exit 1
fi

echo "All suites passed."
