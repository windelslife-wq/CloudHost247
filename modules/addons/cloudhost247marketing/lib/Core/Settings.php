<?php
/**
 * Layered settings: env override (CH247M_*) -> test override -> DB -> DEFAULTS.
 *
 * Transport secrets (SMTP password, provider API key) are deliberately NOT in
 * SECRET_KEYS-only mode: a WHMCS operator has to be able to type them into the
 * settings page. They are stored encrypted-at-rest-ish via Secret::seal() and
 * can always be overridden by an environment variable, which takes precedence
 * and is the recommended production path.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Core;

class Settings
{
    public const ENV_PREFIX = 'CH247M_';

    /** Settings whose stored value is sealed and never echoed back to the UI. */
    public const SECRET_KEYS = ['smtp_password', 'provider_api_key'];

    public const DEFAULTS = [
        // Master switches
        'service_enabled'          => '1',
        'sending_enabled'          => '0',   // fail closed: operator must opt in
        'kill_switch'              => '0',

        // Sender identity (compliance: CAN-SPAM / GDPR)
        'from_name'                => '',
        'from_email'               => '',
        'reply_to'                 => '',
        'physical_address'         => '',
        'company_name'             => '',

        // Transport
        'transport'                => 'whmcs', // whmcs | smtp | api | null
        'smtp_host'                => '',
        'smtp_port'                => '587',
        'smtp_encryption'          => 'tls',   // tls | ssl | none
        'smtp_username'            => '',
        'smtp_password'            => '',
        'smtp_timeout_seconds'     => '15',
        'provider'                 => '',      // sendgrid | mailgun | postmark | ses_http
        'provider_endpoint'        => '',
        'provider_api_key'         => '',
        'provider_region'          => '',

        // Queue / throughput
        'queue_batch_size'         => '200',
        'send_rate_per_minute'     => '600',
        'max_attempts'             => '5',
        'retry_base_seconds'       => '60',
        'retry_max_seconds'        => '21600',
        'lock_seconds'             => '300',
        'queue_wall_clock_seconds' => '50',

        // Tracking
        'track_opens'              => '1',
        'track_clicks'             => '1',
        'tracking_base_url'        => '',      // defaults to WHMCS SystemURL

        // Compliance
        'require_unsubscribe'      => '1',
        'list_unsubscribe_header'  => '1',
        'double_optin'             => '0',
        'suppress_on_bounce'       => '1',
        'suppress_on_complaint'    => '1',
        'hard_bounce_threshold'    => '1',
        'soft_bounce_threshold'    => '5',

        // Automations
        'automations_enabled'      => '1',
        'abandoned_cart_minutes'   => '120',

        // Retention
        'retention_days_events'    => '365',
        'retention_days_queue'     => '90',

        // Let WHMCS's own cron drain the queue when there is no system crontab
        'cron_fallback_enabled'    => '1',

        // Timezone used when an operator schedules a campaign
        'account_timezone'         => 'UTC',
    ];

    /** @var array<string,string> */
    private static $overrides = [];
    /** @var array<string,string>|null */
    private static $cache;

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
            $value = (string) $row[$key];
            return in_array($key, self::SECRET_KEYS, true) ? Secret::open($value) : $value;
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }
        return (string) $default;
    }

    public static function int($key, $default = 0)
    {
        $value = self::string($key, '');
        return $value === '' ? (int) $default : (int) $value;
    }

    public static function bool($key, $default = false)
    {
        $value = self::string($key, '');
        if ($value === '') {
            return (bool) $default;
        }
        return in_array(strtolower($value), ['1', 'on', 'yes', 'true'], true);
    }

    /** Is this key currently supplied by the environment (and therefore read-only in the UI)? */
    public static function fromEnv($key)
    {
        $env = getenv(self::ENV_PREFIX . strtoupper($key));
        return $env !== false && $env !== '';
    }

    /** Is a secret present at all, without revealing it? */
    public static function hasSecret($key)
    {
        return self::string($key, '') !== '';
    }

    public static function put($key, $value)
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new Ch247MktException('Unknown setting: ' . $key);
        }
        if (self::fromEnv($key)) {
            // Environment always wins; silently writing to the DB would be a lie.
            throw new Ch247MktException('Setting "' . $key . '" is provided by the environment and cannot be edited here.');
        }
        $stored = in_array($key, self::SECRET_KEYS, true) && $value !== ''
            ? Secret::seal((string) $value)
            : (string) $value;

        if (!Db::tableExists('settings')) {
            self::$overrides[$key] = (string) $value;
            return;
        }
        if (Db::count('settings', ['setting' => $key]) > 0) {
            Db::update('settings', ['setting' => $key], ['value' => $stored, 'updated_at' => Clock::now()]);
        } else {
            Db::insert('settings', ['setting' => $key, 'value' => $stored, 'updated_at' => Clock::now()]);
        }
        self::$cache = null;
    }

    public static function resetOverrides()
    {
        self::$overrides = [];
        self::$cache = null;
    }

    /** Test seam. */
    public static function override($key, $value)
    {
        self::$overrides[$key] = (string) $value;
    }

    public static function editable()
    {
        return array_keys(self::DEFAULTS);
    }

    protected static function row()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            if (Db::tableExists('settings')) {
                foreach (Db::all('settings') as $row) {
                    self::$cache[$row['setting']] = $row['value'];
                }
            }
            // The WHMCS addon-module configuration (master toggle) is honoured
            // too, but never overrides an explicit module setting.
            if (defined('CH247M_MODULE_NAME') && Db::whmcsTableExists('tbladdonmodules')) {
                foreach (Db::query('SELECT setting, value FROM tbladdonmodules WHERE module = ?', [CH247M_MODULE_NAME]) as $row) {
                    if (!isset(self::$cache[$row['setting']])) {
                        $value = (string) $row['value'];
                        self::$cache[$row['setting']] = $value === 'on' ? '1' : ($value === '' ? '0' : $value);
                    }
                }
            }
        } catch (\Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }
}
