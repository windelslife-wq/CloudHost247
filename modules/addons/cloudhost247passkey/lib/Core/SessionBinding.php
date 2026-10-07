<?php
/** Domain-separated hash of the active PHP session ID; the raw ID is never persisted. */

namespace CloudHost247\Passkey\Core;

class SessionBinding
{
    public static function currentHash()
    {
        if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            throw new \RuntimeException('An active WHMCS session is required for Passkey ceremonies.');
        }
        return hash('sha256', 'CloudHost247Passkey' . chr(0) . session_id());
    }
}
