<?php
/** Safe, idempotent maintenance entry point. */
$init = dirname(__DIR__, 4) . '/init.php';
if (is_file($init)) require_once $init;
require_once dirname(__DIR__) . '/autoload.php';

use DigitalProducts\Core\RateLimiter;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

try {
    $removedTokens = (new TokenService())->purge();
    $removedLimits = RateLimiter::purge();
    // Keep download history. Operators can export and explicitly prune it;
    // silent deletion would undermine the delivery audit trail.
    if (function_exists('logActivity')) logActivity('DigitalProducts maintenance: removed ' . (int) $removedTokens . ' expired tokens and ' . (int) $removedLimits . ' rate-limit windows.');
} catch (\Throwable $e) {
    if (function_exists('logActivity')) logActivity('DigitalProducts maintenance failed: ' . $e->getMessage());
}
