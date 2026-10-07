<?php
/**
 * CloudHost247 App Cloud — host application facade.
 *
 * The single place that talks to WHMCS primitives: localAPI, Capsule queries,
 * the activity log, system URLs and the mailer. Everything above this layer is
 * host-agnostic, which is what makes the module testable offline: the suite
 * installs a fake with setApiFake() and asserts exactly which WHMCS commands the
 * platform would have issued.
 *
 * A missing WHMCS is never fatal here — callers check isAvailable() and degrade
 * to UNKNOWN rather than inventing data (see Monitoring\StatusPolicy).
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Whmcs
{
    /** @var callable|null fn(string $command, array $params): array */
    private static $apiFake;

    /** @var array recorded calls when a fake is installed */
    private static $calls = [];

    /** @var array|null capsule table snapshots for offline fixtures */
    private static $tables = [];

    /** @var string[] activity log lines recorded offline */
    private static $activity = [];

    public static function setApiFake($fake)
    {
        self::$apiFake = $fake;
        self::$calls = [];
    }

    public static function setTable($name, array $rows)
    {
        self::$tables[(string) $name] = $rows;
    }

    public static function reset()
    {
        self::$apiFake = null;
        self::$calls = [];
        self::$tables = [];
        self::$activity = [];
    }

    /** @return array[] recorded API calls: [['command' => …, 'params' => …], …] */
    public static function calls($command = null)
    {
        if ($command === null) {
            return self::$calls;
        }
        return array_values(array_filter(self::$calls, function ($call) use ($command) {
            return $call['command'] === $command;
        }));
    }

    public static function activityLog()
    {
        return self::$activity;
    }

    public static function isAvailable()
    {
        return self::$apiFake !== null
            || function_exists('localAPI')
            || class_exists('\\WHMCS\\Database\\Capsule');
    }

    /**
     * Call the WHMCS internal API.
     *
     * @return array the API result; ['result' => 'error'] when unavailable
     */
    public static function api($command, array $params = [])
    {
        $params = array_merge(['action' => $command], $params);

        if (self::$apiFake !== null) {
            self::$calls[] = ['command' => $command, 'params' => $params];
            $result = call_user_func(self::$apiFake, $command, $params);
            return is_array($result) ? $result : ['result' => 'error'];
        }

        if (!function_exists('localAPI')) {
            self::$calls[] = ['command' => $command, 'params' => $params];
            return ['result' => 'error', 'message' => 'WHMCS API unavailable'];
        }

        self::$calls[] = ['command' => $command, 'params' => $params];
        $result = localAPI($command, $params);
        return is_array($result) ? $result : ['result' => 'error'];
    }

    /** True when the API result indicates success. */
    public static function ok(array $result)
    {
        return isset($result['result']) && strtolower((string) $result['result']) === 'success';
    }

    public static function errorMessage(array $result, $fallback = 'The host application rejected the request.')
    {
        foreach (['message', 'error'] as $key) {
            if (isset($result[$key]) && trim((string) $result[$key]) !== '') {
                return (string) $result[$key];
            }
        }
        return $fallback;
    }

    /**
     * Query a WHMCS core table read-only. Returns rows as arrays. Offline (or
     * when the table is unknown) it returns the fixture registered with
     * setTable(), never a fabricated row.
     */
    public static function rows($table, array $where = [], array $columns = ['*'])
    {
        $table = (string) $table;

        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            try {
                $query = \WHMCS\Database\Capsule::table($table);
                foreach ($where as $column => $value) {
                    if (is_array($value) && isset($value[0])) {
                        $query = $query->where($column, $value[0], isset($value[1]) ? $value[1] : null);
                    } else {
                        $query = $query->where($column, $value);
                    }
                }
                $rows = $query->get();
                $out = [];
                foreach ($rows as $row) {
                    $out[] = (array) $row;
                }
                return $out;
            } catch (\Throwable $e) {
                Logger::debug('Core table read failed.', ['table' => $table, 'message' => $e->getMessage()]);
                return [];
            }
        }

        if (!isset(self::$tables[$table])) {
            return [];
        }
        $out = [];
        foreach (self::$tables[$table] as $row) {
            $match = true;
            foreach ($where as $column => $value) {
                $expected = is_array($value) && isset($value[1]) ? $value[1] : $value;
                if (!array_key_exists($column, $row) || (string) $row[$column] !== (string) $expected) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function row($table, array $where = [])
    {
        $rows = self::rows($table, $where);
        return $rows ? $rows[0] : null;
    }

    /** Addon-module field values from tbladdonmodules. */
    public static function addonModuleSettings($module)
    {
        $rows = self::rows('tbladdonmodules', ['module' => (string) $module]);
        if ($rows) {
            return $rows;
        }
        // tbladdonmodules stores setting/value per module id in real installs.
        $moduleRow = self::row('tbladdons', ['name' => (string) $module]);
        if (!$moduleRow) {
            return [];
        }
        return self::rows('tbladdonmodules', ['addon_id' => isset($moduleRow['id']) ? $moduleRow['id'] : 0]);
    }

    /** Write to the WHMCS activity log (operator visible). */
    public static function logActivity($description, $clientId = 0)
    {
        $description = Str::cleanText($description, 900);
        self::$activity[] = $description;

        if (self::$apiFake !== null) {
            self::api('LogActivity', ['description' => $description, 'userid' => (int) $clientId]);
            return;
        }
        if (function_exists('logActivity')) {
            try {
                logActivity($description);
            } catch (\Throwable $e) {
                // never let logging break the operation
            }
            return;
        }
        if (function_exists('localAPI')) {
            self::api('LogActivity', ['description' => $description, 'userid' => (int) $clientId]);
        }
    }

    /** Absolute URL inside the WHMCS installation. */
    public static function systemUrl($path = '')
    {
        $base = '';
        if (class_exists('\\WHMCS\\Utility\\Environment')) {
            try {
                $base = (string) \WHMCS\Utility\Environment::getBaseUri();
            } catch (\Throwable $e) {
                $base = '';
            }
        }
        if ($base === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $base = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
        }
        $base = rtrim((string) $base, '/');
        if (defined('CH247APPS_TESTING')) {
            $base = $base === '' ? '/whmcs' : $base;
        }
        return $base . '/' . ltrim((string) $path, '/');
    }

    /** The module's own web root, used by portals and assets. */
    public static function moduleUrl($path = '')
    {
        return self::systemUrl('modules/addons/cloudhost247apps/' . ltrim((string) $path, '/'));
    }

    public static function webRoot()
    {
        return isset($GLOBALS['CONFIG']['SystemURL'])
            ? rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/')
            : rtrim(self::systemUrl(), '/');
    }

    /** Currency formatting through WHMCS when present, otherwise plain. */
    public static function formatMoney($minor, $currency = 'USD')
    {
        $amount = round(((int) $minor) / 100, 2);
        if (function_exists('format_as_currency')) {
            try {
                return (string) format_as_currency($amount);
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return $currency . ' ' . number_format($amount, 2);
    }
}
