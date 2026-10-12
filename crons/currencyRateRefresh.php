<?php
/**
 * Warms the cache from WHMCS-configured rates. It does not update the feed.
 *
 * This does not fetch rates itself and never invents one. It reads WHMCS’s
 * its own configured table, then writes a fresh cache retrieval timestamp.
 * WHMCS’s native daily cron must update the underlying feed independently.
 *
 * Scheduling: optional cache warm after WHMCS’s native currency automation.
 *
 *   Run every 6 hours (or however often your feed updates):
 *
 *       0 3,9,15,21 <asterisk> <asterisk> <asterisk> php /path/to/whmcs/crons/currencyRateRefresh.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

require_once __DIR__ . '/lib/CronRuntime.php';

\CloudHost247\Cron\CronGuard::requireCli();

$whmcspath = "";

if (file_exists(dirname(__FILE__) . "/config.php")) {
    require_once dirname(__FILE__) . "/config.php";
}

if (!empty($whmcspath)) {
    require_once $whmcspath . "/init.php";
} else {
    require __DIR__ . "/../init.php";
}

$root = !empty($whmcspath) ? rtrim($whmcspath, '/\\') : dirname(__DIR__);

require_once $root . '/includes/i18n/autoload.php';

// Cache warm only: exchange-rate updates belong to WHMCS's own daily cron.
// The native API has no documented UpdateCurrencyRates action.
try {
    $cache = new \CloudHost247\I18n\Rate\FileRateCache($root . '/modules/addons/cloudhost247_i18n/cache/rates.json');
    $cache->clear();
    $i18n = \CloudHost247\I18n\I18n::boot(array('root' => $root, 'rate_cache' => $cache));
    $i18n->clearRateCache();
    $rates = $i18n->rates();
    logActivity('currencyRateRefresh: cache reloaded from configured WHMCS rates; external feed was not fetched. Source: ' . ($rates ? $rates->getSource() : 'unavailable'));
} catch (\Throwable $e) {
    logActivity('currencyRateRefresh: cache reload failed — ' . $e->getMessage());
    exit(1);
}
