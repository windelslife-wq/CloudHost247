<?php
/**
 * Updates currency exchange rates and warms the CloudHost247 i18n cache.
 *
 * This does not fetch rates itself and never invents one. It asks WHMCS to run
 * its own configured currency feed update, then drops the cached rate table so
 * the next page view re-reads it with a fresh timestamp.
 *
 * Scheduling: run once or twice a day, before the site's busy period.
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

$root = dirname(__DIR__);

require_once $root . '/includes/i18n/autoload.php';

$i18n = null;
try {
    $i18n = \CloudHost247\I18n\I18n::boot(array('root' => $root));
} catch (\Throwable $e) {
    logActivity('currencyRateRefresh: could not boot the i18n library — ' . $e->getMessage());
    exit(1);
}

try {
    $result = localAPI('UpdateCurrencyRates', array());
} catch (\Throwable $e) {
    logActivity('currencyRateRefresh: UpdateCurrencyRates failed — ' . $e->getMessage());
    exit(1);
}

if (!isset($result['result']) || $result['result'] !== 'success') {
    $message = isset($result['message']) ? $result['message'] : 'unknown error';
    logActivity('currencyRateRefresh: rate update rejected — ' . $message);
    exit(1);
}

// Drop the cached table so the next request re-reads the fresh rates.
$i18n->clearRateCache();

$rates = $i18n->rates();
$count = 0;
if ($rates !== null) {
    foreach ($i18n->currencies()->all() as $currency) {
        $default = $i18n->currencies()->default();
        if ($default === null || $default->getCode() === $currency->getCode()) {
            continue;
        }
        if ($rates->rate($default->getCode(), $currency->getCode()) !== null) {
            $count++;
        }
    }
}

logActivity(sprintf(
    'currencyRateRefresh: refreshed %d rate(s) from source "%s".',
    $count,
    $rates !== null ? $rates->getSource() : 'unknown'
));
