<?php
/**
 * CloudHost247 Services — scheduled heartbeat.
 *
 * Recommended schedule: every 5 minutes, e.g.
 *   /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php
 *
 * Auction state transitions (open scheduled listings, close finished ones,
 * settle winners) are time-critical, so they run here rather than waiting
 * for the daily WHMCS cron. Daily housekeeping also runs via the DailyCronJob
 * hook — this script is additive, never a replacement.
 *
 * @package Chs
 */

define('CHS_CRON', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use Chs\Core\Clock;
use Chs\Core\Logger;
use Chs\Services\AuctionService;
use Chs\Services\ClubService;

if (php_sapi_name() !== 'cli' && !defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$started = Clock::now();
$results = [];

$task = function ($name, callable $fn) use (&$results) {
    try {
        $results[$name] = $fn();
    } catch (\Throwable $e) {
        $results[$name] = 'error';
        Logger::error('CHS cron task failed', ['task' => $name, 'message' => $e->getMessage()]);
    }
};

$task('auction_heartbeat', function () {
    return (new AuctionService())->heartbeat();
});

$task('auction_invoice_lapse', function () {
    return (new AuctionService())->lapseOverdueInvoices();
});

$task('club_expiry', function () {
    return (new ClubService())->expireDue();
});

$task('sitemap', function () {
    // Regenerate the site sitemap.xml from the canonical public page list.
    return (new \Chs\Services\SitemapService())->regenerate();
});

Logger::info('CHS cron run complete', ['results' => $results, 'started' => $started]);

if (php_sapi_name() === 'cli') {
    echo 'CloudHost247 Services cron: ' . json_encode($results) . PHP_EOL;
}
