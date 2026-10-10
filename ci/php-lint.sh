#!/usr/bin/env bash
#
# CloudHost247 — whole-repo PHP syntax sweep.
#
#   ci/php-lint.sh
#
# Runs `php -l` over every shipped PHP file except:
#   - modules/addons/hostx and modules/addons/xtreme_currency_rates
#     (100% ionCube-encoded; see owner decision D-8 — there is no parseable
#     source, and `php -l` fails on the ciphertext by design)
#   - third-party vendor/ and node_modules/ trees
#
set -u
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
checked=0
bad=0

while IFS= read -r file; do
    checked=$((checked + 1))
    if ! "$PHP_BIN" -l "$file" > /dev/null 2>&1; then
        echo "SYNTAX FAIL: $file"
        "$PHP_BIN" -l "$file" 2>&1 | head -n 3
        bad=$((bad + 1))
    fi
done < <(find . -name '*.php' -not -path './modules/addons/hostx/*' -not -path './modules/addons/xtreme_currency_rates/*' -not -path '*/vendor/*' -not -path '*/node_modules/*' | sort)

echo "checked=$checked syntax_errors=$bad"
[ "$bad" -eq 0 ]
