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
