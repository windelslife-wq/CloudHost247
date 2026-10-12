#!/usr/bin/env bash
#
# CloudHost247 — native-PHP test driver.
#
# Runs every offline PHP suite with a plain `php` binary (no php-wasm needed).
# Used by .github/workflows/ci.yml and by developers locally:
#
#   ci/run-php.sh all          # everything (PHP 8.x)
#   ci/run-php.sh i18n         # internationalisation + currency suites only
#   ci/run-php.sh legacy74     # modules with documented PHP 7.4 support
#   ci/run-php.sh apps ai ...  # one or more named modules (see MODULES below)
#   ci/run-php.sh lint         # load/parse gates only
#
# Exit code is 0 only when every executed file passes. A file fails when its
# exit code is non-zero OR its output reports a non-zero FAIL/FAILURES/BAD
# counter (belt and braces: a suite that forgets `exit(1)` still fails CI).
#
set -u
set -o pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
FAILURES=0

run_php() {
    local file="$1"
    local output exit_code
    echo "--- php $file"
    output="$("$PHP_BIN" "$file" 2>&1)"
    exit_code=$?
    printf '%s\n' "$output"
    if [ "$exit_code" -ne 0 ]; then
        echo "FAILED (exit $exit_code): $file"
        FAILURES=$((FAILURES + 1))
        return 1
    fi
    if printf '%s\n' "$output" | grep -Eq 'FAIL=[1-9][0-9]*|FAILURES=[1-9][0-9]*|BAD=[1-9][0-9]*|^\[FAIL\]'; then
        echo "FAILED (failure marker in output): $file"
        FAILURES=$((FAILURES + 1))
        return 1
    fi
    return 0
}

run_glob() {
    local pattern="$1"
    local matched=0
    local file
    for file in $pattern; do
        [ -e "$file" ] || continue
        matched=1
        run_php "$file" || true
    done
    if [ "$matched" -eq 0 ]; then
        echo "FAILED (no files matched): $pattern"
        FAILURES=$((FAILURES + 1))
    fi
}

mod_i18n() {
    run_glob 'tests/i18n/*Test.php'
    # WHMCS override completeness: every key in lang/overrides/english.php must
    # have a non-empty value in each shipped language. This one takes an
    # argument, so it cannot go through run_php() (which passes a single
    # filename) — it exits non-zero when anything is missing.
    echo "--- php ci/apply-override-translations.php --check"
    if ! "$PHP_BIN" ci/apply-override-translations.php --check; then
        echo "FAILED (exit non-zero): ci/apply-override-translations.php --check"
        FAILURES=$((FAILURES + 1))
    fi
}
mod_tools()        { run_glob 'modules/addons/CloudHost247_tools/tests/*Test.php'; }
mod_apps()         { run_glob 'modules/addons/cloudhost247apps/tests/*Test.php'; }
mod_services()     { run_glob 'modules/addons/cloudhost247services/tests/*Test.php'; }
mod_ai()           { run_glob 'modules/addons/cloudhost247ai/tests/*Test.php'; }
mod_marketing()    { run_glob 'modules/addons/cloudhost247marketing/tests/*Test.php'; run_php 'modules/addons/cloudhost247marketing/tests/crosscheck.php'; }
mod_domainbroker() { run_glob 'modules/addons/domainbroker/tests/*Test.php'; }
mod_digital()      { run_glob 'modules/addons/digitalproducts/tests/*Test.php'; }
mod_cloudflare()   { run_php 'modules/addons/cloudhost247cloudflare/tests/01_SecurityCoreTest.php'; run_php 'modules/servers/cloudhost247cloudflare/tests/01_ServerModuleTest.php'; }
mod_blockonomics() { run_glob 'modules/gateways/blockonomics/tests/*Test.php'; }
mod_soyoustart_vps() { run_glob 'modules/servers/soyoustart_vps/tests/*Test.php'; }
mod_soyoustart() { run_glob 'modules/addons/soyoustart/tests/*Test.php'; run_glob 'modules/servers/soyoustart/tests/*Test.php'; run_glob 'crons/tests/*Test.php'; }
mod_smmprovisioning() { run_glob 'modules/servers/smmprovisioning/tests/*Test.php'; }
mod_rdp() { run_glob 'modules/servers/RDP/tests/*Test.php'; }
mod_lteproxy() { run_glob 'modules/servers/cloudhost247_lteproxy/tests/*Test.php'; }
mod_affiliate()    { run_php 'modules/addons/customaffiliate/tests/CommissionTest.php'; run_php 'modules/addons/customaffiliate/tests/RefundUpgradeTest.php'; }
mod_phones()       { run_php 'modules/addons/phoneservices/tests/WebhookVerifierTest.php'; }
mod_smm()          { run_php 'modules/addons/smmaddon/tests/AdminSecurityTest.php'; }
mod_hostxemail()   { run_php 'modules/servers/hostx_email/tests/WebhookAuthTest.php'; run_php 'modules/servers/hostx_email/tests/CryptoMigrationTest.php'; }
mod_hostxtools()   { run_php 'modules/addons/hostx_tools/tests/ClientIpTest.php'; run_php 'modules/addons/hostx_tools/tests/DnsNamespaceTest.php'; run_php 'modules/addons/hostx_tools/tests/DnsPtrLookupTest.php'; }
mod_toolscenter()  { run_php 'modules/addons/tools_center/tests/outbound-guard.php'; run_php 'modules/addons/tools_center/tests/tool-outbound-security.php'; }
mod_smtphosting()  { run_php 'modules/servers/Smtphosting/tests/run.php'; }
mod_cartrecovery() { run_php 'modules/addons/cloudhost247_cart_recovery/tests/run.php'; }

mod_passkey() {
    if [ ! -f 'modules/addons/cloudhost247passkey/vendor/autoload.php' ]; then
        echo 'FAILED: passkey vendor/ is missing. Run: composer install --working-dir modules/addons/cloudhost247passkey'
        FAILURES=$((FAILURES + 1))
        return 0
    fi
    if [ -z "${CH247PK_TEST_PRIVATE_PEM_B64:-}" ]; then
        # Ephemeral P-256 fixture key (never written to disk); mirrors tests/webauthn-integration.mjs.
        CH247PK_TEST_PRIVATE_PEM_B64="$(openssl ecparam -genkey -name prime256v1 2>/dev/null | openssl base64 -A)"
        export CH247PK_TEST_PRIVATE_PEM_B64
    fi
    if [ -z "${CH247PK_TEST_PRIVATE_PEM_B64:-}" ]; then
        echo 'FAILED: could not generate the ephemeral P-256 fixture key (openssl missing?)'
        FAILURES=$((FAILURES + 1))
        return 0
    fi
    run_php 'modules/addons/cloudhost247passkey/tests/run.php' || true
    run_php 'modules/addons/cloudhost247passkey/tests/webauthn-integration.php' || true
}

mod_lint() {
    # Per-module load/parse gates (each prints BAD= and exits non-zero on failure).
    run_php 'modules/addons/cloudhost247apps/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247services/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247ai/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247marketing/tests/lint.php' || true
    run_php 'modules/addons/domainbroker/tests/lint.php' || true
    run_php 'modules/addons/digitalproducts/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247cloudflare/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247_cart_recovery/tests/lint.php' || true
    run_php 'modules/addons/cloudhost247passkey/tests/lint.php' || true
    # Root landing pages must exist and parse (mirrors services lint-root.mjs).
    local missing=0 f
    for f in domain-search.php bulk-domain-search.php domain-transfer.php tld-directory.php \
             domain-valuation.php domain-auctions.php discount-domain-club.php whois-lookup.php \
             website-builder.php ai-website-builder.php online-store.php hire-an-expert.php \
             digital-marketing.php logo-maker.php unified-inbox.php; do
        if [ ! -f "$f" ]; then
            echo "MISSING $f"
            missing=1
        fi
    done
    if [ "$missing" -ne 0 ]; then
        FAILURES=$((FAILURES + 1))
    fi
}

MODULES="i18n tools apps services ai marketing domainbroker digital cloudflare blockonomics soyoustart_vps soyoustart smmprovisioning rdp lteproxy affiliate phones smm hostxemail hostxtools toolscenter smtphosting cartrecovery passkey"

run_all() {
    local m
    for m in $MODULES; do
        echo "=== module: $m ==="
        "mod_$m" || true
    done
    echo "=== load/parse gates ==="
    mod_lint || true
}

# Modules with documented PHP 7.4 support (see docs/MODULE_COMPLETION_TRACKER.md:
# each of these suites was executed green on PHP 7.4.33). Everything else
# targets PHP 8.x (the php-wasm runners pin 8.3).
run_legacy74() {
    # The i18n library is written to PHP 7.4 syntax; its suite skips the ICU
    # cross-checks when ext/intl is absent rather than failing.
    echo "=== module: i18n ==="
    mod_i18n || true
    echo "=== module: tools ==="
    mod_tools || true
    echo "=== module: digital ==="
    mod_digital || true
    echo "=== module: smtphosting ==="
    mod_smtphosting || true
}

usage() {
    echo "usage: ci/run-php.sh all|legacy74|lint|$MODULES (one or more)"
    exit 2
}

if [ "$#" -eq 0 ]; then
    usage
fi

for target in "$@"; do
    case "$target" in
        all) run_all ;;
        legacy74) run_legacy74 ;;
        lint) mod_lint ;;
        i18n|tools|apps|services|ai|marketing|domainbroker|digital|cloudflare|blockonomics|soyoustart_vps|smmprovisioning|rdp|lteproxy|affiliate|phones|smm|hostxemail|hostxtools|toolscenter|smtphosting|cartrecovery|passkey)
            echo "=== module: $target ==="
            "mod_$target" || true
            ;;
        *) usage ;;
    esac
done

if [ "$FAILURES" -ne 0 ]; then
    echo "RESULT: $FAILURES failing file(s)"
    exit 1
fi
echo "RESULT: all executed suites passed"
