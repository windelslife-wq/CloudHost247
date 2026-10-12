<?php
/**
 * CloudHost247 — i18n & currency test harness.
 *
 * Minimal assertion library plus a repo-root resolver that works in all three
 * places these suites run:
 *
 *   - native PHP in CI            (`php tests/i18n/CatalogIntegrityTest.php`)
 *   - php-wasm                    (`node tests/run.mjs`, repo mounted at /repo)
 *   - a developer's checkout
 *
 * The style (section() / T::ok / T::is / PASS=n FAIL=n) matches the other
 * module suites in this repository so ci/run-php.sh treats them identically.
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/i18n/autoload.php';

/* --------------------------------------------------------- repo location -- */

if (!function_exists('ch247_repo_root')) {
    /**
     * Absolute path to the repository root.
     *
     * @return string
     */
    function ch247_repo_root()
    {
        // php-wasm mounts the checkout at /repo (see tests/run.mjs).
        if (is_dir('/repo/lang/site')) {
            return '/repo';
        }
        if (is_dir('/app/lang/site')) {
            return '/app';
        }

        return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }
}

if (!defined('CH247_REPO_ROOT')) {
    define('CH247_REPO_ROOT', ch247_repo_root());
}

if (!function_exists('ch247_catalog_dir')) {
    function ch247_catalog_dir()
    {
        return CH247_REPO_ROOT . '/lang/site';
    }
}

/* ------------------------------------------------------------ assertions -- */

if (!class_exists('T')) {
    class T
    {
        public static $pass = 0;
        public static $fail = 0;
        public static $section = '';
        public static $failures = array();

        public static function section($name)
        {
            self::$section = $name;
            echo "\n== {$name} ==\n";
        }

        public static function ok($label, $condition)
        {
            if ($condition) {
                self::$pass++;

                return true;
            }
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo '  FAIL ' . $label . "\n";

            return false;
        }

        public static function is($label, $expected, $actual)
        {
            if ($expected === $actual) {
                self::$pass++;

                return true;
            }
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo '  FAIL ' . $label . ': expected ' . self::dump($expected)
                . ' got ' . self::dump($actual) . "\n";

            return false;
        }

        public static function isnt($label, $notExpected, $actual)
        {
            return self::ok($label, $notExpected !== $actual);
        }

        public static function contains($label, $needle, $haystack)
        {
            if (is_array($haystack)) {
                $haystack = implode(' ', array_map(function ($v) {
                    return is_scalar($v) ? (string) $v : json_encode($v);
                }, $haystack));
            }

            return self::ok(
                $label . ' contains "' . $needle . '"',
                strpos((string) $haystack, (string) $needle) !== false
            );
        }

        public static function notContains($label, $needle, $haystack)
        {
            if (is_array($haystack)) {
                $haystack = implode(' ', array_map(function ($v) {
                    return is_scalar($v) ? (string) $v : json_encode($v);
                }, $haystack));
            }

            return self::ok(
                $label . ' does not contain "' . $needle . '"',
                strpos((string) $haystack, (string) $needle) === false
            );
        }

        public static function throws($label, $class, $fn)
        {
            try {
                $fn();
            } catch (Throwable $e) {
                if ($e instanceof $class) {
                    self::$pass++;

                    return $e;
                }
                self::$fail++;
                self::$failures[] = self::$section . ' / ' . $label;
                echo '  FAIL ' . $label . ': expected ' . $class . ' got ' . get_class($e)
                    . ' (' . $e->getMessage() . ")\n";

                return null;
            }
            self::$fail++;
            self::$failures[] = self::$section . ' / ' . $label;
            echo '  FAIL ' . $label . ': expected ' . $class . ", nothing thrown\n";

            return null;
        }

        public static function nothrow($label, $fn)
        {
            try {
                $result = $fn();
                self::$pass++;

                return $result;
            } catch (Throwable $e) {
                self::$fail++;
                self::$failures[] = self::$section . ' / ' . $label;
                echo '  FAIL ' . $label . ': unexpected ' . get_class($e) . ': ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";

                return null;
            }
        }

        public static function dump($value)
        {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_null($value)) {
                return 'null';
            }
            if (is_scalar($value)) {
                return '"' . $value . '"';
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        public static function summary()
        {
            echo "\n──────────────────────────────\n";
            echo 'PASS=' . self::$pass . '  FAIL=' . self::$fail . "\n";
            if (self::$fail > 0) {
                echo "\nFailures:\n";
                foreach (self::$failures as $failure) {
                    echo '  - ' . $failure . "\n";
                }
                exit(1);
            }
        }
    }
}

if (!function_exists('section')) {
    function section($name)
    {
        T::section($name);
    }
}

/* ---------------------------------------------------------- test fixtures -- */

if (!function_exists('ch247_fixture_currencies')) {
    /**
     * Currency rows shaped like WHMCS `tblcurrencies`.
     *
     * NGN is the default because that is how the live shop is configured; the
     * rate for JPY is deliberately missing to exercise the "no rate, do not
     * convert" path.
     *
     * @return array
     */
    function ch247_fixture_currencies()
    {
        return array(
            array('id' => 1, 'code' => 'NGN', 'prefix' => '₦', 'suffix' => '', 'format' => 2, 'rate' => 1.0, 'default' => 1),
            array('id' => 2, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'format' => 2, 'rate' => 0.00065, 'default' => 0),
            array('id' => 3, 'code' => 'EUR', 'prefix' => '', 'suffix' => ' €', 'format' => 2, 'rate' => 0.00060, 'default' => 0),
            array('id' => 4, 'code' => 'GBP', 'prefix' => '£', 'suffix' => '', 'format' => 2, 'rate' => 0.00051, 'default' => 0),
            array('id' => 5, 'code' => 'JPY', 'prefix' => '¥', 'suffix' => '', 'format' => 1, 'rate' => 0.0, 'default' => 0),
        );
    }
}

if (!function_exists('ch247_fixture_rates')) {
    /**
     * Rates exactly as WHMCS stores them: relative to the default currency.
     *
     * @return array
     */
    function ch247_fixture_rates()
    {
        return array(
            'NGN' => 1.0,
            'USD' => 0.00065,
            'EUR' => 0.00060,
            'GBP' => 0.00051,
        );
    }
}

if (!function_exists('ch247_make_registry')) {
    /**
     * @return \CloudHost247\I18n\CurrencyRegistry
     */
    function ch247_make_registry()
    {
        return new \CloudHost247\I18n\CurrencyRegistry(
            new \CloudHost247\I18n\StaticCurrencySource(ch247_fixture_currencies(), 'test fixture')
        );
    }
}

if (!function_exists('ch247_make_i18n')) {
    /**
     * Boot an I18n instance against fixtures, with nothing global left behind.
     *
     * @param array $overrides
     *
     * @return \CloudHost247\I18n\I18n
     */
    function ch247_make_i18n(array $overrides = array())
    {
        $config = array_merge(array(
            'root'           => CH247_REPO_ROOT,
            'default_locale' => 'en',
            'currency_source' => new \CloudHost247\I18n\StaticCurrencySource(ch247_fixture_currencies(), 'test fixture'),
            'store'          => new \CloudHost247\I18n\Preference\ArrayPreferenceStore(),
            'rate_source'    => new \CloudHost247\I18n\Rate\StaticRateSource(
                'NGN',
                ch247_fixture_rates(),
                'test fixture',
                '2026-10-12T06:00:00+00:00'
            ),
            'logger'         => null,
        ), $overrides);

        return \CloudHost247\I18n\I18n::reset($config);
    }
}

/* --------------------------------------------- automatic-detection helpers -- */

if (!function_exists('ch247_geo_locator')) {
    /**
     * A fixture geolocation source: IP (or CIDR) => ISO country.
     *
     * @param array  $map    e.g. ['203.0.113.7' => 'GB', '198.51.100.0/24' => 'DE'].
     * @param string $source
     *
     * @return \CloudHost247\I18n\Detection\ArrayGeoLocator
     */
    function ch247_geo_locator(array $map, $source = 'fixture geo')
    {
        return new \CloudHost247\I18n\Detection\ArrayGeoLocator($map, $source);
    }
}

if (!function_exists('ch247_fixture_countries')) {
    /**
     * Countries used across the detection scenarios.
     *
     * NGN (default) <- NG ; GBP <- GB ; USD <- US ; EUR <- DE/FR/NL ; JPY <- JP
     * (enabled but deliberately unrateable); INR <- IN (not enabled at all).
     *
     * @return array
     */
    function ch247_fixture_countries()
    {
        return array(
            '203.0.113.7'      => 'NG',
            '198.51.100.4'     => 'GB',
            '192.0.2.44'       => 'US',
            '198.51.100.9'     => 'DE',
            '198.51.100.11'    => 'FR',
            '198.51.100.12'    => 'JP',
            '198.51.100.13'    => 'IN',
            '198.51.100.14'    => 'CA',
            '198.51.100.15'    => 'AQ',
            '198.51.100.16'    => 'BR',
            '203.0.113.99'     => 'XX', // the "unknown" value Cloudflare sends
            '2001:db8::7'      => 'DE',
        );
    }
}

/**
 * Captures cookie writes instead of sending them.
 *
 * PHP's setcookie() cannot be observed from a CLI test, so the store is handed
 * this recorder as its transport. Everything else — validation, expiry, the
 * HttpOnly/SameSite flags — is the real code path.
 */
if (!class_exists('Ch247CookieRecorder')) {
    final class Ch247CookieRecorder
    {
        /** @var array<string,array{value:string,options:array}> */
        public $sent = array();

        /** @var array<string,true> */
        public $cleared = array();

        /**
         * @param string      $name
         * @param string|null $value
         * @param array       $options
         *
         * @return bool
         */
        public function send($name, $value, array $options)
        {
            if ($value === null) {
                $this->cleared[$name] = true;
                unset($this->sent[$name]);

                return true;
            }
            $this->sent[$name] = array('value' => (string) $value, 'options' => $options);

            return true;
        }

        /**
         * @return callable
         */
        public function sender()
        {
            return array($this, 'send');
        }
    }
}

if (!function_exists('ch247_cookie_recorder')) {
    /**
     * @return Ch247CookieRecorder
     */
    function ch247_cookie_recorder()
    {
        return new Ch247CookieRecorder();
    }
}

if (!function_exists('ch247_make_i18n_for')) {
    /**
     * Boot an I18n instance for one simulated visitor.
     *
     * @param array $overrides Detection/cookie/request options; see the
     *                         scenario sections in AutoDetectionTest.php.
     *
     * @return \CloudHost247\I18n\I18n
     */
    function ch247_make_i18n_for(array $overrides = array())
    {
        $config = array(
            'root'            => CH247_REPO_ROOT,
            'default_locale'  => 'en',
            'currency_source' => new \CloudHost247\I18n\StaticCurrencySource(ch247_fixture_currencies(), 'test fixture'),
            'rate_source'     => new \CloudHost247\I18n\Rate\StaticRateSource(
                'NGN',
                ch247_fixture_rates(),
                'test fixture',
                '2026-10-12T06:00:00+00:00'
            ),
            'logger'          => null,
        );

        return \CloudHost247\I18n\I18n::reset(array_merge($config, $overrides));
    }
}
