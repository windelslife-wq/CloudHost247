<?php
/**
 * Resolves the configured transport.
 *
 * One instance is reused for a whole queue batch so SMTP keeps its connection
 * open across messages.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

use Ch247Mkt\Core\Settings;

class TransportFactory
{
    public const DRIVERS = ['whmcs', 'smtp', 'api', 'null'];

    public const LABELS = [
        'whmcs' => 'Use the WHMCS mail configuration',
        'smtp'  => 'Dedicated SMTP server',
        'api'   => 'Email provider HTTP API',
        'null'  => 'Dry run — render and log, never send',
    ];

    /** @var TransportInterface|null */
    private static $instance;
    /** @var TransportInterface|null test seam */
    private static $override;

    public static function make($driver = '')
    {
        $driver = $driver !== '' ? $driver : Settings::string('transport', 'whmcs');
        if (!in_array($driver, self::DRIVERS, true)) {
            $driver = 'whmcs';
        }
        switch ($driver) {
            case 'smtp':
                return new SmtpTransport([
                    'host'       => Settings::string('smtp_host', ''),
                    'port'       => Settings::int('smtp_port', 587),
                    'encryption' => Settings::string('smtp_encryption', 'tls'),
                    'username'   => Settings::string('smtp_username', ''),
                    'password'   => Settings::string('smtp_password', ''),
                    'timeout'    => Settings::int('smtp_timeout_seconds', 15),
                    'label'      => 'smtp',
                ]);
            case 'api':
                return new HttpApiTransport([
                    'provider' => Settings::string('provider', ''),
                    'api_key'  => Settings::string('provider_api_key', ''),
                    'endpoint' => Settings::string('provider_endpoint', ''),
                    'region'   => Settings::string('provider_region', ''),
                ]);
            case 'null':
                return new NullTransport();
            default:
                return new WhmcsTransport();
        }
    }

    /** Shared instance for a batch (keeps the SMTP socket alive). */
    public static function current()
    {
        if (self::$override !== null) {
            return self::$override;
        }
        if (self::$instance === null) {
            self::$instance = self::make();
        }
        return self::$instance;
    }

    public static function release()
    {
        if (self::$instance !== null && method_exists(self::$instance, 'disconnect')) {
            self::$instance->disconnect();
        }
        self::$instance = null;
    }

    /** Test seam. */
    public static function setOverride($transport)
    {
        self::$override = $transport;
        self::$instance = null;
    }

    /** @return array{ok:bool,message:string,driver:string} */
    public static function verify($driver = '')
    {
        $transport = self::make($driver);
        $result = ['driver' => $transport->name(), 'ok' => false, 'message' => ''];
        if (!$transport->isConfigured()) {
            $result['message'] = $transport->configurationProblem();
            return $result;
        }
        if (method_exists($transport, 'verify')) {
            $check = $transport->verify();
            $result['ok'] = !empty($check['ok']);
            $result['message'] = (string) ($check['message'] ?? '');
            return $result;
        }
        $result['ok'] = true;
        $result['message'] = 'Configured.';
        return $result;
    }
}
