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
            'version'     => '1.2.0',
            'language'    => 'english',
            'fields'      => array(
                'rate_ttl' => array(
                    'FriendlyName' => 'Exchange rate cache TTL (seconds)',
                    'Type'         => 'text',
                    'Size'         => '10',
                    'Default'      => '21600',
                    'Description'  => 'How long a fetched rate table is reused before the configured source is asked again. 21600 = 6 hours.',
                ),

                // ------------------------------------------- auto-detection --
                'detect_enabled' => array(
                    'FriendlyName' => 'Automatic language &amp; currency detection',
                    'Type'         => 'yesno',
                    'Default'      => 'yes',
                    'Description'  => 'Detect the language from the browser and the currency from the visitor\'s country when they have not chosen one. A customer\'s own choice always wins.',
                ),
                'trusted_proxies' => array(
                    'FriendlyName' => 'Trusted proxies (CIDRs)',
                    'Type'         => 'textarea',
                    'Rows'         => '4',
                    'Cols'         => '60',
                    'Default'      => '',
                    'Description'  => 'Comma or newline separated. Forwarded headers (X-Forwarded-For, CF-IPCountry, …) are only believed when the connection came from one of these ranges. Leave empty to ignore them entirely — the safe default. Cloudflare ranges: https://www.cloudflare.com/ips/',
                ),
                'country_header' => array(
                    'FriendlyName' => 'Edge country header',
                    'Type'         => 'text',
                    'Size'         => '30',
                    'Default'      => 'CF-IPCountry',
                    'Description'  => 'Header your CDN sets with the visitor\'s ISO country code. Read only from a trusted proxy.',
                ),
                'geo_endpoint' => array(
                    'FriendlyName' => 'IP geolocation endpoint',
                    'Type'         => 'text',
                    'Size'         => '60',
                    'Default'      => '',
                    'Description'  => 'Optional. A URL containing {ip}, e.g. https://ipwho.is/{ip}. Leave empty to skip geolocation and use the default currency. No request is made until you set this.',
                ),
                'geo_field' => array(
                    'FriendlyName' => 'Country field (dot path)',
                    'Type'         => 'text',
                    'Size'         => '30',
                    'Default'      => 'country_code',
                    'Description'  => 'Where the two-letter country code sits in the JSON response, e.g. country_code or data.country.code.',
                ),
                'geo_allowed_hosts' => array(
                    'FriendlyName' => 'Geolocation hosts (allowlist)',
                    'Type'         => 'text',
                    'Size'         => '60',
                    'Default'      => '',
                    'Description'  => 'Host names the endpoint may use, comma separated. Required before any lookup is made, so a changed setting cannot turn this into an open proxy.',
                ),
                'geo_timeout' => array(
                    'FriendlyName' => 'Geolocation timeout (seconds)',
                    'Type'         => 'text',
                    'Size'         => '5',
                    'Default'      => '2',
                    'Description'  => 'A slow provider must never slow a page. On timeout the default currency is used.',
                ),
                'geo_ttl' => array(
                    'FriendlyName' => 'Geolocation cache TTL (seconds)',
                    'Type'         => 'text',
                    'Size'         => '10',
                    'Default'      => '86400',
                    'Description'  => 'How long a country result is reused. Cached under a salted hash, never with the raw IP.',
                ),
                'use_region_hint' => array(
                    'FriendlyName' => 'Use the browser language region as a currency hint',
                    'Type'         => 'yesno',
                    'Default'      => 'no',
                    'Description'  => 'Off by default: a language tag states a language, not where the visitor pays from. Weaker than geolocation and only used when geolocation finds nothing.',
                ),
                'country_currency_overrides' => array(
                    'FriendlyName' => 'Country &rarr; currency overrides',
                    'Type'         => 'textarea',
                    'Rows'         => '3',
                    'Cols'         => '60',
                    'Default'      => '',
                    'Description'  => 'Optional, e.g. "NG=NGN, DE=EUR". Wins over the built-in ISO 4217 table but is still filtered through your enabled WHMCS currencies.',
                ),
                'cookie_days' => array(
                    'FriendlyName' => 'Remember an explicit choice (days)',
                    'Type'         => 'text',
                    'Size'         => '5',
                    'Default'      => '180',
                    'Description'  => 'How long a visitor\'s own language/currency choice is kept when they are not logged in. HttpOnly and SameSite=Lax.',
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

if (!function_exists('cloudhost247_i18n_detection_settings')) {
    /**
     * Raw module settings, keyed by name.
     *
     * @return array
     */
    function cloudhost247_i18n_detection_settings()
    {
        $settings = array();
        try {
            $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'cloudhost247_i18n')
                ->get();
            foreach ($rows as $row) {
                $settings[$row->setting] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            // Settings table unavailable: the defaults apply.
        }

        return $settings;
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

        echo '<h2>Automatic detection</h2>';

        $detection = $i18n->detection();
        $settings = cloudhost247_i18n_detection_settings();
        $detector = $i18n->detector();
        $map = new \CloudHost247\I18n\Detection\CountryCurrencyMap();
        $yes = '<span class="label label-success">yes</span>';
        $no = '<span class="label label-default">no</span>';

        echo '<div class="table-responsive"><table class="table table-striped table-bordered"><tbody>';
        echo '<tr><th style="width:22em">Detection enabled</th><td>' . (!empty($detection['detection_enabled']) ? $yes : $no) . '</td></tr>';
        echo '<tr><th>Geolocation source</th><td>' . htmlspecialchars(
            $detector !== null ? $detector->explain()['geo_source'] : 'not configured',
            ENT_QUOTES,
            'UTF-8'
        ) . '</td></tr>';
        echo '<tr><th>Trusted proxies</th><td>' . (empty($settings['trusted_proxies'])
            ? 'none — forwarded headers are ignored'
            : (int) count(preg_split('/[\\s,]+/', trim($settings['trusted_proxies'])) ?: array()) . ' range(s)')
            . '</td></tr>';
        echo '<tr><th>Country for this request</th><td>' . htmlspecialchars(
            isset($detection['request']['country']['country']) ? $detection['request']['country']['country'] : 'unknown',
            ENT_QUOTES,
            'UTF-8'
        ) . '</td></tr>';

        // Coverage: how much of the ISO country table this shop can actually
        // act on. A country whose currency is not enabled is not a failure,
        // it is simply a visitor who gets the default currency.
        $enabled = 0;
        foreach ($map->toArray() as $country => $currencies) {
            if ($map->resolve($country, $i18n->currencies()) !== null) {
                $enabled++;
            }
        }
        echo '<tr><th>Countries with an enabled currency</th><td>' . (int) $enabled
            . ' of ' . (int) $map->size() . '</td></tr>';
        echo '<tr><th>Explicit choice remembered for</th><td>' . (int) (isset($settings['cookie_days']) ? $settings['cookie_days'] : 180)
            . ' days (HttpOnly, SameSite=Lax)</td></tr>';
        echo '</tbody></table></div>';

        echo '<p>Precedence for both settings: <strong>an explicit choice</strong> (switcher, '
            . '<code>?language=</code>, <code>?currency=</code>) <strong>&rarr; the account preference</strong> '
            . '<strong>&rarr; automatic detection</strong> <strong>&rarr; the configured default</strong>. '
            . 'Detection is only consulted when nothing has been chosen, and a detected value is stored '
            . 'separately so a later choice always replaces it. Client billing currency '
            . '(<code>tblclients.currency</code>) and historical invoices are never rewritten.</p>';

        $sample = array('NG', 'GB', 'US', 'DE', 'FR', 'JP', 'BR', 'IN', 'ZA', 'CA');
        echo '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
            . '<th>Country</th><th>Maps to</th><th>Offered?</th></tr></thead><tbody>';
        foreach ($sample as $country) {
            $candidates = $map->candidates($country);
            $resolved = $map->resolve($country, $i18n->currencies());
            echo '<tr><td><code>' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td><code>' . htmlspecialchars(implode(', ', $candidates), ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td>' . ($resolved !== null
                    ? htmlspecialchars($resolved->getCode(), ENT_QUOTES, 'UTF-8')
                    : '<span class="text-muted">default currency (not enabled)</span>') . '</td></tr>';
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
