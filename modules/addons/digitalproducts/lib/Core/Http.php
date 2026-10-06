<?php
namespace DigitalProducts\Core;

class Http
{
    public static function ip()
    {
        // Do not trust forwarded headers unless the deployment explicitly
        // normalises them at the proxy. REMOTE_ADDR is the safe default.
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    public static function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 400) : '';
    }

    public static function json($payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function systemUrl()
    {
        try {
            return rtrim((string) \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function clientId()
    {
        return (int) ($_SESSION['uid'] ?? 0);
    }
}
