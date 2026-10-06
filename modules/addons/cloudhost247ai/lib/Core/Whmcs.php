<?php
/**
 * Platform seam — the ONLY place this module touches WHMCS APIs.
 *
 * Production: localAPI()/sendMessage() against the live install.
 * Tests: an injected fake records every call and answers from fixtures.
 * When neither is available the seam fails closed (ServiceUnavailable),
 * never with invented data.
 */

namespace Ch247Ai\Core;

class Whmcs
{
    /** @var callable|null test seam: function ($command, array $args) : array */
    private static $apiFake;

    public static function setApiFake(callable $fake = null)
    {
        self::$apiFake = $fake;
    }

    /** @return array localAPI result envelope */
    public static function api($command, array $args = [])
    {
        if (self::$apiFake !== null) {
            return call_user_func(self::$apiFake, $command, $args);
        }
        if (function_exists('localAPI')) {
            $adminUser = self::adminUsernameForApi();
            $result = localAPI($command, $args, $adminUser);
            if (is_array($result) && isset($result['result']) && $result['result'] === 'error') {
                throw new ServiceUnavailableException('WHMCS API ' . $command . ' failed: ' . (isset($result['message']) ? $result['message'] : 'unknown error'));
            }
            return is_array($result) ? $result : [];
        }
        throw new ServiceUnavailableException('WHMCS localAPI is not available; ' . $command . ' cannot be executed here.');
    }

    /** Resolve an admin username for localAPI calls (first super admin). */
    public static function adminUsernameForApi()
    {
        try {
            $rows = Db::query('SELECT username FROM tbladmins ORDER BY roleid ASC, id ASC LIMIT 1');
            if ($rows && isset($rows[0]['username'])) {
                return (string) $rows[0]['username'];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    /** List admin roles for the settings UI. */
    public static function roles()
    {
        try {
            if (Db::whmcsTableExists('tbladminroles')) {
                return Db::query('SELECT id, name FROM tbladminroles ORDER BY id ASC');
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return [];
    }

    /** Best-effort base path prefix for URLs from the WHMCS root. */
    public static function adminBasePath()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        if ($script !== '' && substr($script, -1 * strlen('addonmodules.php')) === 'addonmodules.php') {
            return rtrim(dirname($script), '/') . '/';
        }
        $root = defined('CH247AI_ROOT') ? CH247AI_ROOT : '';
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') : '';
        if ($root !== '' && $docRoot !== '' && strpos($root, $docRoot) === 0) {
            $sub = substr($root, strlen($docRoot));
            return $sub === '' ? '' : rtrim($sub, '/') . '/';
        }
        return '';
    }

    /** Send an internal alert email (admin-facing) through whatever transport exists. */
    public static function sendEmail($toName, $toEmail, $subject, $body)
    {
        if (self::$apiFake !== null) {
            return call_user_func(self::$apiFake, 'SendAdminEmail', ['to_name' => $toName, 'to_email' => $toEmail, 'subject' => $subject, 'body' => $body]);
        }
        if (function_exists('mail')) {
            $to = $toEmail;
            $headers = 'From: no-reply@' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST']) : 'localhost') . "\r\nContent-Type: text/plain; charset=UTF-8";
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        }
        throw new ServiceUnavailableException('No email transport available.');
    }
}
