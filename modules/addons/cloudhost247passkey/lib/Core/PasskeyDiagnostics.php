<?php
/**
 * Fail-closed readiness diagnostics for administrators.
 *
 * Every check returns a machine-readable status plus a safe human summary.
 * Missing configuration reports CONFIGURATION_REQUIRED; missing platform
 * dependencies report SERVICE_UNAVAILABLE. No secret values are inspected.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

class PasskeyDiagnostics
{
    public static function collect(array $settings, $isHttps, $requestOrigin = null)
    {
        $checks = [];
        $checks['php_runtime'] = self::phpRuntime();
        $checks['php_extensions'] = self::phpExtensions();
        $checks['composer_dependencies'] = self::composerDependencies();
        $checks['database'] = self::database();
        $checks['https'] = self::https($isHttps);
        $checks['service_switch'] = self::serviceSwitch($settings);
        $checks['webauthn_config'] = self::webauthnConfig($settings, $requestOrigin, $isHttps);
        $checks['entra'] = self::entra($settings);

        $overall = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $overall = 'error';
                break;
            }
            if ($check['status'] === 'warning' && $overall === 'ok') {
                $overall = 'warning';
            }
        }
        return ['overall' => $overall, 'checks' => $checks];
    }

    private static function phpRuntime()
    {
        if (PHP_VERSION_ID < 70400) {
            return self::result('error', 'SERVICE_UNAVAILABLE', 'PHP ' . PHP_VERSION . ' is below the 7.4 floor.');
        }
        $warning = PHP_VERSION_ID < 80000
            ? ' PHP 7.4 is end-of-life; plan an upgrade to a maintained runtime.'
            : '';
        return self::result(
            PHP_VERSION_ID < 80000 ? 'warning' : 'ok',
            'OK',
            'PHP ' . PHP_VERSION . ' meets the compatibility floor.' . $warning
        );
    }

    private static function phpExtensions()
    {
        $required = ['json', 'mbstring', 'openssl', 'simplexml', 'bcmath'];
        $missing = [];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing) {
            return self::result(
                'error',
                'SERVICE_UNAVAILABLE',
                'Missing PHP extensions: ' . implode(', ', $missing) . '.'
            );
        }
        return self::result('ok', 'OK', 'Required PHP extensions are loaded.');
    }

    private static function composerDependencies()
    {
        $autoload = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return self::result(
                'error',
                'SERVICE_UNAVAILABLE',
                'Composer dependencies are not installed (vendor/autoload.php missing).'
            );
        }
        require_once $autoload;
        if (!class_exists('Webauthn\\Server') || !class_exists('Nyholm\\Psr7\\ServerRequest')) {
            return self::result(
                'error',
                'SERVICE_UNAVAILABLE',
                'WebAuthn library classes are unavailable after loading the Composer autoloader.'
            );
        }
        return self::result('ok', 'OK', 'Pinned WebAuthn dependencies autoload.');
    }

    private static function database()
    {
        try {
            $ok = Db::tableExists('credentials') && Db::tableExists('challenges')
                && Db::tableExists('events') && Db::tableExists('settings');
        } catch (\Throwable $error) {
            return self::result('error', 'SERVICE_UNAVAILABLE', 'Passkey database is unreachable.');
        }
        if (!$ok) {
            return self::result(
                'error',
                'CONFIGURATION_REQUIRED',
                'Passkey tables are missing; deactivate and reactivate the addon to run migrations.'
            );
        }
        return self::result('ok', 'OK', 'Passkey tables are present.');
    }

    private static function https($isHttps)
    {
        if ($isHttps !== true) {
            return self::result(
                'error',
                'CONFIGURATION_REQUIRED',
                'Verified HTTPS transport is required for Passkey ceremonies.'
            );
        }
        return self::result('ok', 'OK', 'Request arrived over verified HTTPS.');
    }

    private static function serviceSwitch(array $settings)
    {
        if (!isset($settings['service_enabled']) || !in_array($settings['service_enabled'], ['1', 1], true)) {
            return self::result('warning', 'DISABLED', 'Passkey service switch is off (safe default).');
        }
        return self::result('ok', 'OK', 'Passkey service switch is on.');
    }

    private static function webauthnConfig(array $settings, $requestOrigin, $isHttps)
    {
        if (empty($settings['rp_id']) || empty($settings['allowed_origins']) || $settings['allowed_origins'] === '[]') {
            return self::result(
                'error',
                'CONFIGURATION_REQUIRED',
                'RP ID and the exact HTTPS origin allowlist must be configured.'
            );
        }
        if (!is_string($requestOrigin) || $requestOrigin === '') {
            return self::result('warning', 'UNVERIFIED_ORIGIN', 'RP settings exist; origin check needs a live request.');
        }
        try {
            WebAuthnConfig::fromSettings($settings, $requestOrigin, $isHttps);
        } catch (\Throwable $error) {
            return self::result('error', 'CONFIGURATION_REQUIRED', 'Stored RP/origin settings are invalid.');
        }
        return self::result('ok', 'OK', 'RP ID and request origin validate.');
    }

    private static function entra(array $settings)
    {
        $enabled = isset($settings['entra_enabled']) && in_array($settings['entra_enabled'], ['1', 1], true);
        if (!$enabled) {
            return self::result('ok', 'DISABLED', 'Microsoft Entra ID is disabled (optional).');
        }
        if (empty($settings['entra_tenant_id']) || empty($settings['entra_client_id'])) {
            return self::result('error', 'CONFIGURATION_REQUIRED', 'Entra ID is enabled but tenant/client settings are missing.');
        }
        return self::result(
            'warning',
            'CONFIGURATION_REQUIRED',
            'Entra ID settings exist; interactive OAuth verification is not enabled by this addon version.'
        );
    }

    private static function result($status, $code, $summary)
    {
        return ['status' => $status, 'code' => $code, 'summary' => $summary];
    }
}
