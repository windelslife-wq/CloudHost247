<?php
/**
 * Domain Broker — scheduled maintenance.
 *
 * Run every 5–15 minutes, e.g.
 *   /usr/bin/php -q /path/to/whmcs/modules/addons/domainbroker/cron/domainbroker.php
 *
 * Everything here is driven by the system actor, which deliberately holds no
 * interactive permissions: it can expire and retry, but it can never refund,
 * approve a verification or override a status.
 *
 * @package DomainBroker
 */

define('DOMAINBROKER_CRON', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use DomainBroker\Core\Clock;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\Logger;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Services\NegotiationService;
use DomainBroker\Services\NotificationService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\TransferService;

if (php_sapi_name() !== 'cli' && !defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$started = Clock::now();
$results = [];

/** Run one maintenance task without letting a failure stop the rest. */
$task = function ($name, callable $fn) use (&$results) {
    try {
        $results[$name] = $fn();
    } catch (\Throwable $e) {
        $results[$name] = 'error';
        Logger::error('Domain Broker cron task failed.', [
            'task' => $name,
            'message' => $e->getMessage(),
        ]);
    }
};

// Housekeeping: these tables grow with traffic and must be pruned.
$task('ratelimits_pruned', function () {
    return RateLimiter::prune();
});
$task('idempotency_pruned', function () {
    return Idempotency::prune();
});

// Workflow expiry. Each service enforces its own rules; the cron only asks.
$task('requests_expired', function () {
    return (new RequestService())->expireStale();
});
$task('offers_expired', function () {
    return (new NegotiationService())->expireOffers();
});
$task('payments_expired', function () {
    return (new PaymentService())->expirePending();
});
$task('transfers_expired', function () {
    return (new TransferService())->expireStale();
});

// Delivery retries for notifications that could not be sent first time.
$task('notifications_retried', function () {
    return (new NotificationService())->retryFailed();
});

$summary = [];
foreach ($results as $name => $value) {
    $summary[] = $name . '=' . (is_scalar($value) ? $value : json_encode($value));
}

Logger::info('Domain Broker cron completed.', [
    'started_at' => $started,
    'finished_at' => Clock::now(),
    'results' => $results,
]);

if (php_sapi_name() === 'cli') {
    echo 'Domain Broker cron: ' . implode(' ', $summary) . PHP_EOL;
}
