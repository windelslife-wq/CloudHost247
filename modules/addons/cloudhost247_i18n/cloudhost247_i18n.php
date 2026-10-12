<?php
/**
 * WHMCS addon module — CloudHost247 Internationalization.
 *
 * The translation engine itself lives in includes/i18n/ and is framework
 * agnostic; this module is the WHMCS-facing shell that lets an administrator
 * see, verify and act on it without touching code:
 *
 *   - a completeness report per language (translated keys / total keys, missing
 *     keys listed explicitly) — this is the check behind the rule "never mark a
 *     language complete until its translations are done and checked";
 *   - the current currency configuration: which WHMCS currencies are enabled,
 *     which rates are known, how old they are and where they came from;
 *   - a cache reload action that re-reads configured WHMCS rates. Native
 *     WHMCS automation, not an undocumented API call, owns feed updates.
 *
 * @package    WHMCS
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use CloudHost247\I18n\Formatter;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Whmcs\WhmcsLocale;

require_once dirname(__DIR__, 3) . '/includes/i18n/autoload.php';

if (!function_exists('cloudhost247_i18n_config')) {
    /**
     * @return array
     */
    function cloudhost247_i18n_config()
    {
        return array(
            'name'        => 'CloudHost247 Internationalization',
            'description' => 'Language and currency switching, translation catalog management and locale-aware formatting for the whole site.',
            'author'      => 'CloudHost247',
            'version'     => '1.1.0',
            'language'    => 'english',
            'fields'      => array(
                'rate_ttl' => array(
                    'FriendlyName' => 'Exchange rate cache TTL (seconds)',
                    'Type'         => 'text',
                    'Size'         => '10',
                    'Default'      => '21600',
                    'Description'  => 'How long a fetched rate table is reused before the configured source is asked again. 21600 = 6 hours.',
                ),
            ),
        );
    }
}

if (!function_exists('cloudhost247_i18n_activate')) {
    /**
     * @return array
     */
    function cloudhost247_i18n_activate()
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('mod_cloudhost247_i18n_preferences')) {
                $schema->create('mod_cloudhost247_i18n_preferences', function ($table) {
                    $table->unsignedInteger('client_id')->primary();
                    $table->unsignedInteger('display_currency')->nullable();
                });
            }
            return array('status' => 'success', 'description' => 'Ready. Browsing-currency preferences are stored separately; billing currencies and invoices are not modified.');
        } catch (\Throwable $e) {
            return array('status' => 'error', 'description' => 'Could not create the browsing-preference table. Check the database permissions.');
        }
    }
}

if (!function_exists('cloudhost247_i18n_upgrade')) {
    function cloudhost247_i18n_upgrade(array $vars)
    {
        return cloudhost247_i18n_activate(); // Idempotent additive migration.
    }
}

if (!function_exists('cloudhost247_i18n_deactivate')) {
    /**
     * @return array
     */
    function cloudhost247_i18n_deactivate()
    {
        return array('status' => 'success', 'description' => 'Deactivated. Templates that use $LANG keys fall back to English.');
    }
}

if (!function_exists('cloudhost247_i18n_i18n')) {
    /**
     * Boot the library against this WHMCS install.
     *
     * @return I18n|null
     */
    function cloudhost247_i18n_i18n()
    {
        static $instance = null;
        static $tried = false;

        if ($tried) {
            return $instance;
        }
        $tried = true;

        try {
            $root = dirname(__DIR__, 3);
            $instance = I18n::boot(array('root' => $root, 'rate_cache' => new \CloudHost247\I18n\Rate\FileRateCache($root . '/modules/addons/cloudhost247_i18n/cache/rates.json')));
        } catch (Throwable $e) {
            $instance = null;
        }

        return $instance;
    }
}

if (!function_exists('cloudhost247_i18n_completeness')) {
    /**
     * Per-language translation completeness against the reference catalog.
     *
     * @param I18n $i18n
     *
     * @return array
     */
    function cloudhost247_i18n_completeness(I18n $i18n)
    {
        $reference = 'en';
        $catalogs = $i18n->catalogs();
        $locales = $catalogs->availableLocales();

        $total = 0;
        $referenceMessages = array();
        foreach ($catalogs->messages($reference) as $key => $value) {
            $referenceMessages[(string) $key] = $value;
        }
        $total = count($referenceMessages);

        $rows = array();
        foreach ($locales as $code) {
            $meta = $catalogs->meta($code);
            $messages = array();
            foreach ($catalogs->messages($code) as $key => $value) {
                $messages[(string) $key] = $value;
            }

            $missing = array();
            $empty = array();
            foreach ($referenceMessages as $key => $value) {
                if (!array_key_exists($key, $messages)) {
                    $missing[] = $key;
                } elseif ((is_array($messages[$key]) && \CloudHost247\I18n\PluralRule::validateForms($messages[$key]))
                    || (!is_array($messages[$key]) && trim((string) $messages[$key]) === '')) {
                    $empty[] = $key;
                }
            }

            $translated = $total - count($missing) - count($empty);
            $rows[$code] = array(
                'code'        => $code,
                'name'        => isset($meta['english_name']) ? $meta['english_name'] : $code,
                'native_name' => isset($meta['native_name']) ? $meta['native_name'] : $code,
                'status'      => isset($meta['status']) ? $meta['status'] : 'unknown',
                'complete'    => $total > 0 && $translated === $total,
                'total'       => $total,
                'translated'  => $translated,
                'percent'     => $total > 0 ? (int) round(($translated / $total) * 100) : 0,
                'missing'     => $missing,
                'empty'       => $empty,
                'whmcs_name'  => WhmcsLocale::toWhmcsName($code),
            );
        }

        return $rows;
    }
}

if (!function_exists('cloudhost247_i18n_output')) {
    /**
     * Admin area page.
     *
     * @param array $vars
     *
     * @return void
     */
    function cloudhost247_i18n_output(array $vars)
    {
        $i18n = cloudhost247_i18n_i18n();

        if ($i18n === null) {
            echo '<div class="alert alert-danger">The i18n library could not be loaded. Check that <code>includes/i18n/</code> is present on disk.</div>';

            return;
        }

        $action = isset($_REQUEST['ch247action']) ? (string) $_REQUEST['ch247action'] : '';

        if ($action === 'refreshrates') {
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
                && function_exists('check_token')) {
                check_token('WHMCS.admin.default');
                cloudhost247_i18n_refresh_rates($i18n);
            } else {
                echo '<div class="alert alert-danger">A valid admin POST token is required.</div>';
            }
        }

        $rows = cloudhost247_i18n_completeness($i18n);
        $currencies = $i18n->currencies()->all();
        $default = $i18n->currencies()->default();
        $rates = $i18n->rates();

        $formatter = new Formatter('en');
        $rateStamp = $rates !== null ? $rates->getRetrievedAt() : null;
        $rateSource = $rates !== null ? $rates->getSource() : '';

        $modulelink = isset($vars['modulelink']) ? $vars['modulelink'] : '';

        echo '<h2>Catalog coverage</h2>';
        echo '<div class="alert alert-warning">Catalog parity is not a site-wide translation sign-off. Several older policy, service and utility templates still contain English. See docs/I18N_STATUS.md and complete the licensed-WHMCS staging checklist before release.</div>';
        echo '<p>The reference catalog is <strong>English (' . (int) (isset($rows['en']['total']) ? $rows['en']['total'] : 0) . ' strings)</strong>. '
            . 'A catalog is only ever shown as <em>complete</em> when every key is present and non-empty; '
            . 'the gate that enforces this also runs in CI.</p>';
        echo '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
            . '<th>Language</th><th>Code</th><th>WHMCS name</th><th>Status</th><th>Translated</th><th style="width:180px">Coverage</th><th>Missing / empty</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $barClass = $row['complete'] ? 'success' : ($row['percent'] >= 80 ? 'warning' : 'danger');
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['native_name'], ENT_QUOTES, 'UTF-8') . ' <span class="text-muted">(' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . ')</span></td>';
            echo '<td><code>' . htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8') . '</code></td>';
            echo '<td><code>' . htmlspecialchars($row['whmcs_name'], ENT_QUOTES, 'UTF-8') . '</code></td>';
            echo '<td>' . ($row['complete']
                ? '<span class="label label-success">Catalog-complete</span>'
                : '<span class="label label-default">' . htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') . '</span>') . '</td>';
            echo '<td>' . (int) $row['translated'] . ' / ' . (int) $row['total'] . '</td>';
            echo '<td><div class="progress" style="margin-bottom:0"><div class="progress-bar progress-bar-' . $barClass . '" role="progressbar" style="width:' . (int) $row['percent'] . '%">' . (int) $row['percent'] . '%</div></div></td>';
            echo '<td>';
            if (!$row['missing'] && !$row['empty']) {
                echo '<span class="text-success">none</span>';
            } else {
                $all = array_merge($row['missing'], $row['empty']);
                echo '<code>' . htmlspecialchars(implode(', ', array_slice($all, 0, 12)), ENT_QUOTES, 'UTF-8') . '</code>';
                if (count($all) > 12) {
                    echo ' <span class="text-muted">(+' . (count($all) - 12) . ' more)</span>';
                }
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<h2>Currencies</h2>';
        echo '<p>Currencies are read from <code>tblcurrencies</code> — the same list WHMCS itself uses — so enabling '
            . 'a currency in WHMCS makes it selectable here. No currency list is hard-coded anywhere.</p>';
        echo '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
            . '<th>Code</th><th>Name</th><th>Symbol</th><th>Format</th><th>Default</th>'
            . '</tr></thead><tbody>';

        foreach ($currencies as $currency) {
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars($currency->getCode(), ENT_QUOTES, 'UTF-8') . '</code></td>';
            echo '<td>' . htmlspecialchars($currency->getName(), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(trim($currency->getPrefix() . $currency->getSuffix()), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . ($currency->getFormat() === 1 ? 'Symbol then amount' : ($currency->getFormat() === 3 ? 'Amount then symbol' : 'Other')) . '</td>';
            echo '<td>' . ($default !== null && $default->getCode() === $currency->getCode() ? '<span class="label label-success">Default</span>' : '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<h2>Exchange rates</h2>';
        echo '<p><strong>Source:</strong> ' . ($rateSource !== '' ? htmlspecialchars($rateSource, ENT_QUOTES, 'UTF-8') : 'not fetched yet') . '</p>';
        echo '<p><strong>Cache retrieved at:</strong> ' . ($rateStamp ? htmlspecialchars(date('Y-m-d H:i:s', $rateStamp), ENT_QUOTES, 'UTF-8') : 'never') . '</p>';
        echo '<p>Rates are cached and timestamped. If a rate cannot be obtained the price is not converted and no '
            . 'rate is invented — the page shows the catalogue price in its own currency instead.</p>';

        echo '<p><strong>Feed update time:</strong> ' . ($rates !== null && $rates->getAsOf() ? htmlspecialchars($rates->getAsOf(), ENT_QUOTES, 'UTF-8') : 'not supplied by this WHMCS schema') . '</p>';
        echo '<p>WHMCS\'s native currency automation updates the feed. This addon only reads the configured rates; reloading the cache does not imply a new feed update.</p>';
        if ($rates !== null) {
            echo '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr><th>Pair</th><th>Rate</th></tr></thead><tbody>';
            foreach ($currencies as $currency) {
                if ($default !== null && $default->getCode() === $currency->getCode()) {
                    continue;
                }
                $rate = $rates->rate($default->getCode(), $currency->getCode());
                echo '<tr><td><code>' . htmlspecialchars($default->getCode() . ' → ' . $currency->getCode(), ENT_QUOTES, 'UTF-8') . '</code></td>';
                echo '<td>' . ($rate === null ? '<span class="text-danger">unavailable</span>' : htmlspecialchars((string) $rate, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<form method="post" action="' . htmlspecialchars($modulelink, ENT_QUOTES, 'UTF-8') . '">'
            . (function_exists('generate_token') ? generate_token('form') : '')
            . '<input type="hidden" name="ch247action" value="refreshrates">'
            . '<button type="submit" class="btn btn-primary">Reload configured rates into the cache</button>'
            . '</form>';
    }
}

if (!function_exists('cloudhost247_i18n_refresh_rates')) {
    /**
     * Reload only configured WHMCS rates into the shared cache. Native WHMCS
     * automation owns provider updates; no undocumented API is called here.
     *
     * Deliberately reuses WHMCS's configured provider rather than calling an
     * external API from here: one source of truth, one set of credentials, no
     * invented rates.
     *
     * @param I18n $i18n
     *
     * @return void
     */
    function cloudhost247_i18n_refresh_rates(I18n $i18n)
    {
        try {
            // WHMCS exposes no documented UpdateCurrencyRates local API. Do
            // not guess one or pretend to have refreshed an external feed.
            // Re-read only the configured table; WHMCS's native daily cron owns
            // feed updates. Use the same file cache as the frontend hook.
            $cache = new \CloudHost247\I18n\Rate\FileRateCache(dirname(__DIR__, 3) . '/modules/addons/cloudhost247_i18n/cache/rates.json');
            $cache->clear();
            $i18n->clearRateCache();
            $i18n->rates();
            echo '<div class="alert alert-success">Reloaded configured rates from WHMCS. This does not fetch new exchange rates; use WHMCS currency-rate automation for that.</div>';
        } catch (Throwable $e) {
            echo '<div class="alert alert-danger">Could not reload configured rates.</div>';
            if (function_exists('logActivity')) logActivity('CloudHost247 rate-cache reload failed: ' . $e->getMessage());
        }
    }
}
