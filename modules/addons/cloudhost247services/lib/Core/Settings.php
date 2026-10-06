<?php
/**
 * Suite settings — layered resolution:
 *
 *     env override  →  test override  →  mod_chs_settings  →  DEFAULTS
 *
 * Secret keys (API credentials) are never persisted: they can only ever come
 * from the environment, and Settings::put() refuses them outright so a fat
 * finger in the admin form cannot write a key into the database.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Settings
{
    /** Environment prefix: CHS_<KEY>. */
    public const ENV_PREFIX = 'CHS_';

    /** Keys that are env-managed and rejected by put(). */
    public const SECRET_KEYS = ['ai_api_key', 'valuation_api_key', 'ip_hash_salt'];

    /** Default values for every non-secret key. */
    public const DEFAULTS = [
        'service_enabled'               => '1',
        'public_pages_enabled'          => '1',
        'debug_logging'                 => '0',

        'valuation_enabled'             => '1',
        'valuation_engine'              => 'rules',
        'valuation_api_url'             => '',
        'valuation_guest_daily_limit'   => '20',
        'valuation_client_daily_limit'  => '50',

        'whois_enabled'                 => '1',
        'whois_timeout_seconds'         => '8',
        'whois_cache_minutes'           => '120',
        'whois_daily_limit_per_ip'      => '60',
        'whois_max_response_bytes'      => '131072',
        'lookup_cache_minutes'          => '10',
        'cache_retention_days'          => '7',

        'auction_enabled'               => '1',
        'auction_public_browse'         => '1',
        'auction_client_listing'        => '1',
        'anti_snipe_window_seconds'     => '300',
        'anti_snipe_extend_seconds'     => '300',
        'anti_snipe_max_extensions'     => '5',
        'auction_bid_daily_limit'       => '50',
        'auction_invoice_due_days'      => '3',
        'auction_cancel_unpaid_invoices' => '1',

        'club_enabled'                  => '1',
        'club_allow_registrations'      => '1',
        'club_allow_renewals'           => '1',
        'club_allow_transfers'          => '0',
        'club_invoice_due_days'         => '7',

        'requests_enabled'              => '1',
        'requests_daily_limit'          => '10',

        'logo_enabled'                  => '1',
        'logo_projects_limit'           => '25',

        'inbox_enabled'                 => '1',

        'ai_enabled'                    => '0',
        'ai_provider'                   => '',
        'ai_endpoint'                   => '',
        'ai_model'                      => '',
        'ai_timeout_seconds'            => '60',
        'ai_daily_limit_per_client'     => '10',

        'notifications_email'           => '1',
        'notifications_inapp'           => '1',

        'system_url'                    => '',
        'default_currency'              => '',
    ];

    /** @var array<string,string> in-request test overrides */
    private static $overrides = [];
    /** @var array<string,string>|null in-request read cache */
    private static $cache;

    /** Raw value with full resolution order. */
    public static function string($key, $default = '')
    {
        $env = getenv(self::ENV_PREFIX . strtoupper($key));
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
        if (array_key_exists($key, self::$overrides)) {
            return (string) self::$overrides[$key];
        }
        $row = self::row();
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string) $row[$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }
        return (string) $default;
    }

    public static function int($key, $default = 0)
    {
        $value = self::string($key, '');
        if ($value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

    public static function bool($key, $default = false)
    {
        $value = self::string($key, '');
        if ($value === '') {
            return (bool) $default;
        }
        return in_array(strtolower($value), ['1', 'on', 'yes', 'true'], true);
    }

    /** Generic accessor retained for compatibility. */
    public static function get($key, $default = '')
    {
        return self::string($key, $default);
    }

    /**
     * Persist a non-secret setting. Secret keys are refused — they belong in
     * the environment, never the database.
     */
    public static function put($key, $value)
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            throw new ConfigurationException('Secret setting "' . $key . '" is env-managed and cannot be persisted.');
        }
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new ConfigurationException('Unknown setting "' . $key . '".');
        }
        $now = Clock::now();
        $existing = Db::first('settings', ['setting' => $key]);
        if ($existing) {
            Db::update('settings', ['setting' => $key], ['value' => (string) $value, 'updated_at' => $now]);
        } else {
            Db::insert('settings', ['setting' => $key, 'value' => (string) $value, 'created_at' => $now, 'updated_at' => $now]);
        }
        self::$cache = null; // re-prime on next read; the row is now persisted.
    }

    /** Editable (non-secret) key => value map for the admin form. */
    public static function editable()
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                continue;
            }
            $env = getenv(self::ENV_PREFIX . strtoupper($key));
            $out[$key] = ($env !== false && $env !== '') ? (string) $env : self::string($key, $default);
        }
        return $out;
    }

    /**
     * Test seam: force a resolved value for this request.
     * Pass null to clear the override (back to DB/default resolution).
     */
    public static function override($key, $value)
    {
        if ($value === null) {
            unset(self::$overrides[$key]);
            return;
        }
        self::$overrides[$key] = (string) $value;
    }

    /** Drop all test overrides. */
    public static function resetOverrides()
    {
        self::$overrides = [];
    }

    /** Drop the DB read cache (next string() re-reads). */
    public static function clearCache()
    {
        self::$cache = null;
    }

    /** @return array<string,string> */
    private static function row()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach (Db::all('settings') as $record) {
                self::$cache[$record['setting']] = (string) $record['value'];
            }
        } catch (\Throwable $e) {
            // table absent (pre-activation) — defaults resolve anyway.
        }
        return self::$cache;
    }
}
