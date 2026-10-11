#!/usr/bin/env php
<?php
/**
 * HostX Email — credential migration to v1 encryption (findings H-4/H-6).
 *
 * Rewrites legacy (SystemURL-keyed, unauthenticated) credential ciphertext to
 * the v1 format (WHMCS-secret-keyed AES-256-GCM) in tblservers, tblhosting
 * and tblproducts. Also encrypts fresh secrets for pasting into WHMCS.
 *
 *   php upgrade/migrate-credentials.php                 # dry run (default): report only
 *   php upgrade/migrate-credentials.php --confirm       # perform the rewrite
 *   printf '%s' 'new-secret' | php upgrade/migrate-credentials.php --encrypt-stdin
 *   php upgrade/migrate-credentials.php --help
 *
 * Exit codes: 0 ran clean, 2 ran with cells needing manual review or a write
 * failure, 1 could not run (not CLI, WHMCS bootstrap missing, no secret).
 *
 * CLI-only: it refuses to run under any other SAPI, and it never takes a
 * secret on the command line (use --encrypt-stdin; argv is visible in ps).
 */

function hostx_email_migrate_cli_usage()
{
    return <<<'TEXT'
HostX Email credential migration (H-4/H-6): legacy ciphertext -> v1.

Usage:
  php upgrade/migrate-credentials.php                 Dry run (default). Scans and
                                                      reports; writes nothing.
  php upgrade/migrate-credentials.php --confirm       Rewrite legacy cells to v1,
                                                      re-wrapped as found.
  printf '%s' 'secret' | php upgrade/migrate-credentials.php --encrypt-stdin
                                                      Print the v1 ciphertext for
                                                      a fresh secret (paste it
                                                      into the WHMCS field).
  php upgrade/migrate-credentials.php --help          This text.

Back up the database first. Cells the tool cannot classify are listed for
manual review and are never written.

TEXT;
}

function hostx_email_migrate_cli_main(array $argv)
{
    $out = function ($line) { echo $line . "\n"; };

    $flags = array_slice($argv, 1);
    if (in_array('--help', $flags, true) || in_array('-h', $flags, true)) {
        echo hostx_email_migrate_cli_usage();
        return 0;
    }
    if (PHP_SAPI !== 'cli') {
        $out('error: this script runs on the command line only.');
        return 1;
    }

    $init = __DIR__ . '/../../../../init.php';
    if (!is_file($init)) {
        $out('error: WHMCS init.php not found at ' . $init . '; run from the module directory on the WHMCS host.');
        return 1;
    }
    require_once $init;
    require_once dirname(__DIR__) . '/functions.php';
    if (!defined('WHMCS')) {
        $out('error: WHMCS bootstrap did not complete.');
        return 1;
    }

    if (in_array('--encrypt-stdin', $flags, true)) {
        $secret = rtrim(stream_get_contents(STDIN), "\r\n");
        if ($secret === '') {
            $out('error: no secret received on stdin.');
            return 1;
        }
        try {
            $out(hostx_email_encrypt($secret));
        } catch (\Throwable $e) {
            $out('error: ' . $e->getMessage());
            return 1;
        }
        return 0;
    }

    if (hostx_email_get_secret() === '') {
        $out('error: no encryption secret configured ($cc_encryption_hash is missing); refusing to run.');
        return 1;
    }

    $confirm = in_array('--confirm', $flags, true);
    $unknown = array_values(array_diff($flags, ['--confirm', '--dry-run']));
    if (!empty($unknown)) {
        $out('error: unknown option(s): ' . implode(' ', $unknown));
        return 1;
    }

    $report = hostx_email_migrate_credentials(['dry_run' => !$confirm]);
    $out($confirm ? 'mode: LIVE RUN (writing)' : 'mode: DRY RUN (no writes)');
    $out('scanned: ' . $report['scanned'] . ' cells');
    $out(($confirm ? 'migrated: ' : 'would migrate: ') . $report['migrated'] . ' cells');
    $out('already v1: ' . $report['current'] . ' cells');
    $out('empty: ' . $report['empty'] . ' cells');
    foreach ($report['manual'] as $label) {
        $out('needs manual review: ' . $label);
    }
    foreach ($report['errors'] as $error) {
        $out('error: ' . $error);
    }
    if (!$confirm && $report['migrated'] > 0) {
        $out('re-run with --confirm to write these changes.');
    }

    if (!empty($report['errors']) || !empty($report['manual'])) {
        return 2;
    }
    return 0;
}

// Run only when executed directly (never on include, e.g. from the test that
// requires this file to prove it parses).
if (PHP_SAPI === 'cli' && isset($_SERVER['argv']) && is_array($_SERVER['argv']) && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) == __FILE__) {
    exit(hostx_email_migrate_cli_main($_SERVER['argv']));
}
