#!/usr/bin/env bash
#
# CloudHost247 — JavaScript suite driver.
#
# Runs the offline Node suites (no npm install required, except the optional
# jsdom package that enables the tools_center UI wiring tests):
#
#   ci/run-js.sh
#
# With UI coverage:
#   npm install --prefix /tmp/tc-jsdom --no-save jsdom@24
#   NODE_PATH=/tmp/tc-jsdom/node_modules ci/run-js.sh
#
set -u
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
FAILURES=0

step() {
    echo "--- $*"
    if ! "$@"; then
        echo "FAILED: $*"
        FAILURES=$((FAILURES + 1))
    fi
}

# The tools.test.mjs suite reads the PHP registry from /tmp/tools.json;
# render it with native PHP (dump-catalog.mjs is the no-PHP equivalent).
echo "--- $PHP_BIN modules/addons/CloudHost247_tools/tests/dump-catalog.php > /tmp/tools.json"
if ! "$PHP_BIN" modules/addons/CloudHost247_tools/tests/dump-catalog.php > /tmp/tools.json; then
    echo "FAILED: catalog dump"
    FAILURES=$((FAILURES + 1))
fi

step node modules/addons/CloudHost247_tools/tests/core.test.mjs
step node modules/addons/CloudHost247_tools/tests/qr.test.mjs
step node modules/addons/CloudHost247_tools/tests/tools.test.mjs
step node modules/addons/tools_center/tests/run-tests.js

# Syntax gate for the shipped browser bundles touched by the audits.
for js in \
    modules/addons/tools_center/js/tools-center.js \
    modules/addons/tools_center/js/qr-scanner.js \
    modules/addons/tools_center/js/qr-generator.js \
    modules/addons/CloudHost247_tools/assets/js/CloudHost247-tools.js \
    modules/addons/CloudHost247_tools/assets/js/tools-core.js ; do
    step node --check "$js"
done

if [ "$FAILURES" -ne 0 ]; then
    echo "RESULT: $FAILURES failing step(s)"
    exit 1
fi
echo "RESULT: all JS suites passed"
