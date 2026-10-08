<?php
/**
 * CloudHost247 App Cloud — configuration.
 *
 * One typed read path for every tunable in the platform (grace periods, queue
 * leases, retention, ACME e-mail, feature switches, per-role admin mapping).
 * Values resolve in this order:
 *
 *   1. runtime override (tests, CLI flags)
 *   2. environment variable CH247APPS_<KEY> (secrets live here, never in the DB)
 *   3. the module's own settings table (editable in the admin console, audited)
 *   4. the WHMCS addon-module field value (Setup → Addon Modules)
 *   5. the supplied default
 *
 * Nothing secret is ever written to the settings table: encryption keys, agent
 * signing keys and provider credentials come from the environment or from the
 * credential vault.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Settings
{
    /**
     * Documented defaults. The admin console renders from this list, so every
     * tunable is visible and described rather than hidden in code.
     */
    const DEFAULTS = [
        // Platform switches
        'marketplace_enabled'         => '1',
        'install_enabled'             => '1',
        'worker_enabled'              => '1',
        'worker_batch_size'           => '5',
        'worker_poll_seconds'         => '5',
        'worker_max_runtime_seconds'  => '300',
        'job_lease_seconds'           => '900',
        'job_max_attempts'            => '5',
        'job_backoff_base_seconds'    => '30',
        'job_backoff_max_seconds'     => '3600',
        'job_retention_days'          => '30',
        'kubernetes_enabled'          => '0',   // Phase 6: off until a cluster is registered
        'cpanel_enabled'              => '1',
        'customer_server_provisioning_enabled' => '0', // off until a real provider adapter is installed and verified
        'panel_account_workflow_enabled' => '0', // off until WHM staging is validated; customer account creation remains separately gated
        'provider_poll_interval_seconds' => '15',
        'provider_operation_poll_limit' => '120',
        'debug_logging'               => '0',
        'log_level'                   => 'info',
        'log_warnings_to_activity'    => '0',

        // Deployment engine
        'queue_lease_seconds'         => '300',
        'queue_max_attempts'          => '5',
        'queue_backoff_base_seconds'  => '30',
        'queue_dead_letter_after'     => '5',
        'deploy_step_timeout_seconds' => '900',
        'deploy_total_timeout_seconds'=> '3600',
        'rollback_on_failure'         => '1',
        'health_check_timeout_seconds'=> '180',
        'health_check_retries'        => '6',
        'health_check_interval'       => '10',
        'install_requires_paid_order' => '1',
        'install_requires_approval'   => '0',
        'max_installs_per_client'     => '25',
        'project_root'                => '/opt/cloudhost247/apps',

        // Recovery / circuit breaker
        'recovery_enabled'            => '1',
        'recovery_max_restarts'       => '3',
        'recovery_window_minutes'     => '15',
        'recovery_alert_admins'       => '1',

        // Subscriptions
        'grace_period_days'           => '7',
        'past_due_suspend_after_days' => '3',
        'terminate_after_days'        => '30',
        'suspend_containers'          => '1',

        // Backups
        'backup_storage_provider'     => 'local',
        'backup_retention_days'       => '14',
        'backup_keep_last'            => '7',
        'backup_before_update'        => '1',
        'backup_schedule'             => 'daily',

        // SSL / domains
        'ssl_provider'                => 'traefik_acme',
        'acme_email'                  => '',
        'acme_ca'                     => 'letsencrypt',
        'ssl_renew_before_days'       => '21',
        'ssl_redirect_http'           => '1',
        'domain_provider'             => 'manual',
        'domain_verification_ttl_min' => '720',
        'free_subdomain_suffix'       => '',   // e.g. apps.cloudhost247.com

        // Agent
        'agent_token_ttl_seconds'     => '600',
        'agent_request_ttl_seconds'   => '120',
        'agent_request_timeout_seconds' => '120',
        'agent_request_retries'       => '2',
        'agent_retry_backoff_ms'      => '500',
        'agent_heartbeat_stale_min'   => '10',
        'agent_tls_verify'            => '1',

        // Catalog / approval workflow
        'application_auto_publish'    => '0',
        'manifest_max_bytes'          => '262144',
        'catalog_import_on_activate'  => '1',
        'manifest_custom_path'        => '',
        // Manifest security policy. Images must be pinned and must come from a
        // registry an administrator has allowed; remote volume drivers are off.
        'manifest_allow_latest_tag'   => '0',
        'manifest_allow_remote_volumes' => '0',
        'apps_base_path'              => '/opt/cloudhost247/apps',
        'apps_directory_owner'        => 'root',
        'adapters_dry_run'            => '0',
        'health_check_interval_minutes' => '5',
        'crash_loop_restart_threshold' => '5',
        'crash_loop_window_minutes'   => '30',
        'deployment_timeout_seconds'  => '3600',
        'deployment_max_step_attempts' => '3',
        'private_registry_host'       => '',
        'deployment_log_retention_days' => '14',
        'docker_network'              => 'ch247app',
        'docker_isolate_backend_networks' => '1',
        'container_restart_policy'    => 'unless-stopped',
        'app_preview_domain'          => '',
        'traefik_entrypoint'          => 'web',
        'traefik_entrypoint_secure'   => 'websecure',
        'traefik_security_headers'    => '1',
        'traefik_tls_options'         => '0',
        'acme_resolver_name'          => 'letsencrypt',
        'healthcheck_start_period'    => '40s',
        'default_timezone'            => 'UTC',
        'image_registry_allowlist'    => 'docker.io,ghcr.io,quay.io,registry.gitlab.com,public.ecr.aws,mcr.microsoft.com',

        // RBAC bootstrap
        'bootstrap_admin_id'          => '1',
        'default_admin_role'          => 'staff',

        // Secrets (environment only — never stored in the database)
        'encryption_key'              => '',
    ];

    /** @var array runtime overrides */
    private static $overrides = [];

    /** @var array|null memoised DB rows */
    private static $cache;

    /** @var array|null memoised WHMCS addon field values */
    private static $moduleFields;

    public static function override($key, $value)
    {
        self::$overrides[(string) $key] = $value;
    }

    public static function overrideMany(array $values)
    {
        foreach ($values as $key => $value) {
            self::$overrides[(string) $key] = $value;
        }
    }

    public static function resetOverrides()
    {
        self::$overrides = [];
    }

    public static function flush()
    {
        self::$cache = null;
        self::$moduleFields = null;
    }

    /** Raw string value. */
    public static function get($key, $default = null)
    {
        $key = (string) $key;

        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $env = getenv('CH247APPS_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            return $env;
        }

        $rows = self::rows();
        if (isset($rows[$key])) {
            return $rows[$key];
        }

        $fields = self::moduleFieldValues();
        if (isset($fields[$key]) && $fields[$key] !== '') {
            return $fields[$key];
        }

        // An empty-string default means "no opinion": fall through to the shipped
        // default so listOf()/string() callers still get the platform default
        // (the registry allow-list, trusted proxies, …) instead of nothing.
        if ($default !== null && $default !== '') {
            return $default;
        }
        return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;
    }

    public static function int($key, $default = 0)
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : (int) $default;
    }

    public static function float($key, $default = 0.0)
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (float) $value : (float) $default;
    }

    public static function bool($key, $default = false)
    {
        $value = self::get($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'on', 'true', 'yes', 'y'], true);
    }

    public static function string($key, $default = '')
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string) $value : (string) $default;
    }

    /** Comma/newline separated list setting. */
    public static function listOf($key)
    {
        $value = self::get($key, '');
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), function ($v) {
                return trim($v) !== '';
            }));
        }
        $parts = preg_split('/[\s,;]+/', trim((string) $value));
        return array_values(array_filter($parts, function ($v) {
            return $v !== '';
        }));
    }

    /** Persist a setting (admin console). Audited by the caller. */
    public static function set($key, $value, $actorDescription = 'system')
    {
        $key = (string) $key;
        if (in_array($key, ['encryption_key', 'agent_signing_key'], true)) {
            throw new ConfigurationException(
                'Secrets are read from the environment only and cannot be stored in the database.'
            );
        }
        $now = Clock::now();
        $value = is_scalar($value) || $value === null ? (string) $value : Str::jsonEncode($value);

        if (!Db::isBound() || !Db::tableExists('settings')) {
            self::$overrides[$key] = $value;
            return $value;
        }
        $existing = Db::first('settings', ['setting' => $key]);
        if ($existing) {
            Db::update('settings', ['value' => $value, 'updated_at' => $now, 'updated_by' => $actorDescription],
                ['id' => (int) $existing['id']]);
        } else {
            Db::insert('settings', [
                'setting' => $key,
                'value' => $value,
                'updated_by' => $actorDescription,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        self::$cache = null;
        return $value;
    }

    /** @return array<string,string> every effective setting (for the admin UI) */
    public static function all()
    {
        $out = self::DEFAULTS;
        foreach (self::rows() as $key => $value) {
            $out[$key] = $value;
        }
        foreach (self::$overrides as $key => $value) {
            $out[$key] = is_scalar($value) ? (string) $value : Str::jsonEncode($value);
        }
        return $out;
    }

    /** Keys that must be present for a given subsystem to operate. */
    public static function requirementsFor($subsystem)
    {
        switch ($subsystem) {
            case 'crypto':
                return ['encryption_key'];
            case 'ssl':
                return ['acme_email'];
            default:
                return [];
        }
    }

    public static function missingRequirements($subsystem)
    {
        $missing = [];
        foreach (self::requirementsFor($subsystem) as $key) {
            if (trim((string) self::get($key, '')) === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /** @return array<string,string> */
    private static function rows()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            if (Db::isBound() && Db::tableExists('settings')) {
                foreach (Db::fetch('settings') as $row) {
                    self::$cache[$row['setting']] = (string) $row['value'];
                }
            }
        } catch (\Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }

    /** WHMCS addon-module field values (tbladdonmodules), memoised per request. */
    private static function moduleFieldValues()
    {
        if (self::$moduleFields !== null) {
            return self::$moduleFields;
        }
        self::$moduleFields = [];
        try {
            $rows = Whmcs::addonModuleSettings('cloudhost247apps');
            foreach ($rows as $row) {
                if (isset($row['setting'])) {
                    self::$moduleFields[$row['setting']] = (string) $row['value'];
                }
            }
        } catch (\Throwable $e) {
            self::$moduleFields = [];
        }
        return self::$moduleFields;
    }
}
