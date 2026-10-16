<?php
/**
 * Suite 11 — Public landing page safety & internal escrow wording (B-4, B-5, B-8).
 *
 * Verifies:
 *   - domain-broker.php only accepts RFC-valid hostnames for $_GET['domain'] prefill
 *   - templates/hostx/domainbroker-landing.tpl escapes $dbPrefillDomain in HTML attributes
 *   - InternalEscrowProvider and AdminPortal release notices describe ledger release for disbursement
 *   - tests/lint.php guards $argv with isset() before reading it
 */

require_once __DIR__ . '/bootstrap.php';

$repoRoot = is_file(__DIR__ . '/../../../../domain-broker.php')
    ? realpath(__DIR__ . '/../../../..')
    : '/repo';

section('Public landing page prefill validation and template escaping (B-4)');

$landingPhp = (string) file_get_contents($repoRoot . '/domain-broker.php');
$landingTpl = (string) file_get_contents($repoRoot . '/templates/hostx/domainbroker-landing.tpl');

T::ok('domain-broker.php validates $_GET[\'domain\'] against strict hostname regex',
    strpos($landingPhp, 'preg_match(\'/^[A-Za-z0-9-]{1,63}(\\.[A-Za-z0-9-]{1,63})*\\.[A-Za-z]{2,63}$/D\'') !== false
);

// Evaluate the exact hostname regex against valid and adversarial inputs
$pattern = '/^[A-Za-z0-9-]{1,63}(\.[A-Za-z0-9-]{1,63})*\.[A-Za-z]{2,63}$/D';
T::is('valid domain accepted', 1, preg_match($pattern, 'cloudhost247.com'));
T::is('valid subdomain accepted', 1, preg_match($pattern, 'sub.my-domain.co.uk'));
T::is('XSS payload rejected', 0, preg_match($pattern, '"><script>alert(1)</script>'));
T::is('trailing newline rejected by /D modifier', 0, preg_match($pattern, "example.com\n"));
T::is('whitespace rejected', 0, preg_match($pattern, 'example .com'));
T::is('single label rejected', 0, preg_match($pattern, 'localhost'));

T::ok('domainbroker-landing.tpl escapes $dbPrefillDomain with |escape:\'html\'',
    strpos($landingTpl, '{$dbPrefillDomain|escape:\'html\'}') !== false
);
T::ok('domainbroker-landing.tpl contains no unescaped {$dbPrefillDomain}',
    strpos($landingTpl, '{$dbPrefillDomain}') === false
);

section('Internal escrow ledger release wording (B-5) and lint argv guard (B-8)');

$adminPortalPhp = (string) file_get_contents(dirname(__DIR__) . '/lib/Http/AdminPortal.php');
$lintPhp = (string) file_get_contents(__DIR__ . '/lint.php');

T::ok('AdminPortal release confirmation clarifies ledger recording for seller disbursement',
    strpos($adminPortalPhp, 'Record escrow release in the ledger so funds can be disbursed to the seller?') !== false
);
T::ok('AdminPortal release flash clarifies ledger recording for seller disbursement',
    strpos($adminPortalPhp, 'Escrow release recorded in the ledger for seller disbursement.') !== false
);
T::ok('tests/lint.php guards $argv with isset()',
    strpos($lintPhp, '!isset($argv)') !== false
);

T::summary();
