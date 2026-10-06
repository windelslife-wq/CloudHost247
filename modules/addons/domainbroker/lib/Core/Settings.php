<?php
/**
 * Domain Broker — runtime configuration.
 *
 * Resolution order for every key:
 *   1. an explicit runtime override (tests / CLI),
 *   2. the environment (DOMAINBROKER_<KEY>) — this is where secrets live,
 *   3. the WHMCS addon-module setting of the same name (tbladdonmodules),
 *   4. the domain_broker_settings table (admin-editable operational settings),
 *   5. the shipped default.
 *
 * Secrets are only ever read from (1) or (2); they are never persisted to the
 * settings table and never written to source.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Settings
{
    /** Keys that must never be stored in the database. */
    const SECRET_KEYS = [
        'encryption_key',
        'api_signing_secret',
        'escrow_api_key',
        'escrow_webhook_secret',
        'registrar_api_secret',
    ];

    /** Shipped defaults. Operational values, no prices and no credentials. */
    const DEFAULTS = [
        'service_enabled'              => '1',
        'public_landing_enabled'       => '1',
        'default_currency'             => '',       // falls back to WHMCS default currency
        'allowed_currencies'           => '',       // empty = every active WHMCS currency
        'min_budget_minor'             => '10000',  // 100.00 in a 2-dp currency
        'max_budget_minor'             => '0',      // 0 = no ceiling
        'offer_validity_hours'         => '120',
        'payment_window_hours'         => '120',
        'transfer_window_days'         => '30',
        'request_expiry_days'          => '90',
        'max_negotiation_rounds'       => '20',
        'auto_assign_brokers'          => '0',
        'anonymous_default'            => '1',
        'require_kyc_above_minor'      => '2500000', // 25,000.00 → KYC required
        'high_value_review_minor'      => '5000000', // 50,000.00 → manual review
        'risk_block_score'             => '80',
        'risk_review_score'            => '50',
        'max_failed_payments'          => '3',
        'rapid_offer_seconds'          => '30',
        'rapid_offer_threshold'        => '5',
        'escrow_provider'              => 'internal',
        'escrow_release_requires_transfer' => '1',
        'document_max_bytes'           => '15728640', // 15 MiB
        'document_storage_path'        => '',         // defaults to module storage/documents
        'notifications_email'          => '1',
        'notifications_inapp'          => '1',
        'create_support_ticket'        => '0',
        'support_department_id'        => '0',
        'invoice_payment_method'       => '',
        'tax_enabled'                  => '1',
        'rdap_enabled'                 => '1',
        'rdap_timeout'                 => '6',
        'rdap_cache_minutes'           => '60',
        'api_rate_limit_per_minute'    => '60',
        'api_write_rate_limit_per_minute' => '20',
        'audit_retention_days'         => '0',        // 0 = never purge
        // Values used once, by the installer, to seed the first (editable) fee rule.
        'seed_fee_percentage'          => '10',
        'seed_fee_min_minor'           => '0',
        'seed_fee_max_minor'           => '0',
        'debug_logging'                => '0',

        // Payment / invoicing behaviour.
        'payment_due_days'             => '3',
        'send_whmcs_invoice_email'     => '1',
        'cancel_expired_invoices'      => '1',
        'fee_uses_whmcs_tax'           => '1',

        // Escrow. The endpoint is not a secret; the key and webhook secret
        // are, and are read from the environment only.
        'escrow_endpoint'              => '',

        // Transfers.
        'default_gaining_registrar'    => '',

        // Disputes.
        'dispute_sla_days'             => '5',

        // Documents.
        'virus_scan_enabled'           => '0',

        // Domain intelligence.
        'rdap_endpoint'                => 'https://rdap.org',
        'rdap_include_contacts'        => '0',
        'brokerable_tlds'              => '',      // empty = every TLD

        // API. The admin username localAPI calls are attributed to.
        'api_admin_user'               => '',

        // Presentation. Operator-editable so the service can be white-labelled
        // and dates rendered in the house format.
        'service_name'                 => 'Domain Broker Service',
        'support_email'                => '',
        'portal_base_url'              => '',      // empty = relative links
        'display_date_format'          => 'd M Y',
        'display_datetime_format'      => 'd M Y H:i',
        'landing_headline'             => 'Acquire the domain you actually want',
        'landing_subheadline'          => 'Our brokers approach the current owner, negotiate on your behalf and '
                                          . 'manage payment and transfer from start to finish.',
    ];

    /** @var array runtime overrides */
    protected static $overrides = [];

    /** @var array|null cached DB settings */
    protected static $cache;

    /** @var array|null cached WHMCS addon settings */
    protected static $addonCache;

    public static function override($key, $value)
    {
        self::$overrides[$key] = $value;
    }

    public static function overrideMany(array $values)
    {
        foreach ($values as $k => $v) {
            self::$overrides[$k] = $v;
        }
    }

    public static function clearOverrides()
    {
        self::$overrides = [];
        self::$cache = null;
        self::$addonCache = null;
    }

    public static function flush()
    {
        self::$cache = null;
        self::$addonCache = null;
    }

    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $env = getenv('DOMAINBROKER_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            return $env;
        }

        if (!in_array($key, self::SECRET_KEYS, true)) {
            $addon = self::addonSettings();
            if (isset($addon[$key]) && $addon[$key] !== '') {
                return $addon[$key];
            }
            $db = self::dbSettings();
            if (array_key_exists($key, $db)) {
                return $db[$key];
            }
        }

        if ($default !== null) {
            return $default;
        }
        return isset(self::DEFAULTS[$key]) ? self::DEFAULTS[$key] : null;
    }

    public static function int($key, $default = 0)
    {
        $v = self::get($key);
        return $v === null || $v === '' ? (int) $default : (int) $v;
    }

    public static function bool($key, $default = false)
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return (bool) $default;
        }
        return in_array(strtolower((string) $v), ['1', 'on', 'yes', 'true'], true);
    }

    public static function string($key, $default = '')
    {
        $v = self::get($key);
        return $v === null ? (string) $default : (string) $v;
    }

    /** Comma separated list → trimmed array of non-empty values. */
    public static function listOf($key)
    {
        $raw = self::string($key);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    /**
     * Persist an operational setting. Secrets are rejected outright.
     *
     * @throws ConfigurationException
     */
    public static function set($key, $value, $actorDescription = 'system')
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            throw new ConfigurationException(
                'Secret "' . $key . '" must be supplied through the environment, not stored in the database.'
            );
        }
        $now = Clock::now();
        $existing = Db::first('settings', ['setting_key' => $key]);
        if ($existing) {
            Db::update('settings', [
                'setting_value' => (string) $value,
                'updated_by'    => $actorDescription,
                'updated_at'    => $now,
            ], ['id' => $existing['id']]);
        } else {
            Db::insert('settings', [
                'setting_key'   => $key,
                'setting_value' => (string) $value,
                'is_secret'     => 0,
                'updated_by'    => $actorDescription,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
        self::$cache = null;
    }

    public static function all()
    {
        $out = self::DEFAULTS;
        foreach (array_keys($out) as $k) {
            $out[$k] = self::get($k);
        }
        return $out;
    }

    protected static function dbSettings()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            if (Db::isBound() || class_exists('\WHMCS\Database\Capsule')) {
                if (Db::tableExists('settings')) {
                    foreach (Db::fetch('settings') as $row) {
                        self::$cache[$row['setting_key']] = $row['setting_value'];
                    }
                }
            }
        } catch (\Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }

    protected static function addonSettings()
    {
        if (self::$addonCache !== null) {
            return self::$addonCache;
        }
        self::$addonCache = [];
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return self::$addonCache;
        }
        try {
            $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'domainbroker')->get();
            foreach ($rows as $row) {
                $row = (array) $row;
                self::$addonCache[$row['setting']] = $row['value'];
            }
        } catch (\Throwable $e) {
            self::$addonCache = [];
        }
        return self::$addonCache;
    }
}
