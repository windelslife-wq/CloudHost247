<?php
/**
 * Layered settings: env override (CH247AI_*) -> test override -> DB -> DEFAULTS.
 * Secrets are env-only and refused by put().
 */

namespace Ch247Ai\Core;

class Settings
{
    public const ENV_PREFIX = 'CH247AI_';
    public const SECRET_KEYS = ['api_key'];

    public const DEFAULTS = [
        'service_enabled' => '1',
        'copilot_enabled' => '1',
        'client_assistant_enabled' => '0',
        // AI Support Operator (fail closed: operator opts in).
        'support_operator_enabled' => '0',
        'support_ticket_dept' => '1',
        'support_presence_ttl' => '300',
        'support_rate_max' => '20',
        'support_rate_window' => '300',
        'support_max_message' => '2000',
        'support_widget_enabled' => '0',
        'knowledge_enabled' => '1',
        'briefings_enabled' => '1',
        'briefing_hour' => '6',
        // Master switch for write execution. Default OFF: a fresh install
        // can observe and propose, but cannot act until an operator opts in.
        'writes_enabled' => '0',
        'evaluations_enabled' => '1',
        'board_enabled' => '1',
        'board_weekly_dow' => '1',
        'board_monthly_dom' => '1',
        'max_tool_calls_per_run' => '5',
        'run_wall_clock_seconds' => '45',
        'daily_tokens_per_agent' => '200000',
        'monthly_platform_cost_micros' => '50000000',
        'kill_switch' => '0',
        'redact_pii' => '1',
        'expose_pii' => '0',
        'event_max_attempts' => '5',
        'approval_expiry_hours' => '72',
        'retention_days_runs' => '180',
        'retention_days_events' => '60',
        'retention_days_audit' => '0',
        'model_fast_endpoint' => '',
        'model_fast_model' => '',
        'model_reasoning_endpoint' => '',
        'model_reasoning_model' => '',
        'model_timeout_seconds' => '60',
        'model_price_per_mtok_in_micros' => '150',
        'model_price_per_mtok_out_micros' => '600',
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
    public static function put($key, $value)
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            throw new Ch247AiException('Secrets are environment-only and never stored in the database.');
        }
        // Per-tool kill switches are dynamic by nature: one row per
        // registered tool, named tool_disabled_<tool>. Everything else must
        // be a declared key so typos cannot create dead settings.
        if (!array_key_exists($key, self::DEFAULTS) && strpos((string) $key, \Ch247Ai\Tools\Bootstrap::DISABLE_PREFIX) !== 0) {
            throw new Ch247AiException('Unknown setting: ' . $key);
        }
        if (!Db::tableExists('settings')) {
            self::$overrides[$key] = (string) $value;
            return;
        }
        $exists = Db::count('settings', ['setting' => $key]) > 0;
        if ($exists) {
            Db::update('settings', ['setting' => $key], ['value' => (string) $value]);
        } else {
            Db::insert('settings', ['setting' => $key, 'value' => (string) $value]);
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
    protected static function row()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            // Module settings page (mod_ch247ai_settings) takes precedence…
            if (Db::tableExists('settings')) {
                foreach (Db::all('settings') as $row) {
                    self::$cache[$row['setting']] = $row['value'];
                }
            }
            // …over the standard WHMCS addon-module configuration, so the
            // master toggle in Addon Modules is honoured too. WHMCS yesno
            // fields store 'on'/'', normalised to 1/0 here.
            if (Db::whmcsTableExists('tbladdonmodules')) {
                foreach (Db::query('SELECT setting, value FROM tbladdonmodules WHERE module = ?', [CH247AI_MODULE_NAME]) as $row) {
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
    public static function editable()
    {
        return array_keys(self::DEFAULTS);
    }
}
