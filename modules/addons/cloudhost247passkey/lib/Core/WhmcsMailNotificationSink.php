<?php
/**
 * WHMCS delivery sink for Passkey login/security notifications.
 *
 * Delivery uses the supported localAPI mail commands with per-request,
 * server-side content. Contact addresses are resolved from WHMCS-owned
 * tables at send time and are never stored by this addon. Failures raise
 * SERVICE_UNAVAILABLE so callers can audit without blocking authentication.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\SecurityNotification;
use CloudHost247\Passkey\Model\IdentityScope;

class WhmcsMailNotificationSink
{
    public static function deliver(SecurityNotification $notification)
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $identity = $notification->identity();
        $contact = self::resolveContact($identity->userType(), $identity->userId());
        $subject = $notification->notificationType() === SecurityNotification::LOGIN
            ? 'CloudHost247 Passkey sign-in notification'
            : 'CloudHost247 security notification';
        $body = self::renderBody($notification);

        if ($identity->userType() === IdentityScope::ADMIN) {
            $result = localAPI('SendAdminEmail', [
                'customsubject' => $subject,
                'custommessage' => '<p>' . nl2br(self::escape($body)) . '</p>',
                'type' => 'notification',
            ]);
        } else {
            $result = localAPI('SendEmail', [
                'id' => $identity->userId(),
                'customtype' => 'general',
                'customsubject' => $subject,
                'custommessage' => '<p>' . nl2br(self::escape($body)) . '</p>',
                'email' => $contact['email'],
            ]);
        }
        if (!is_array($result) || (isset($result['result']) && $result['result'] === 'error')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        return ['status' => 'delivered'];
    }

    private static function resolveContact($userType, $userId)
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $table = $userType === IdentityScope::ADMIN ? 'tbladmins'
            : ($userType === IdentityScope::CLIENT_USER ? 'tblusers' : 'tblclients');
        try {
            $row = \WHMCS\Database\Capsule::table($table)->where('id', (int) $userId)->first();
        } catch (\Throwable $error) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        if ($row === null) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $row = (array) $row;
        $email = isset($row['email']) ? trim((string) $row['email']) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        $name = trim(
            (isset($row['firstname']) ? (string) $row['firstname'] : '')
            . ' ' . (isset($row['lastname']) ? (string) $row['lastname'] : '')
        );
        return ['email' => $email, 'name' => $name === '' ? $email : $name];
    }

    private static function renderBody(SecurityNotification $notification)
    {
        $identity = $notification->identity();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : 'unknown';
        $lines = [
            'CloudHost247 Passkey security notification',
            '',
            'Account type: ' . $identity->userType() . ' #' . $identity->userId(),
            'Event: ' . $notification->eventType(),
            'Method: Passkey (WebAuthn)',
            'Date/time (UTC): ' . gmdate('Y-m-d H:i:s'),
            'IP address: ' . substr($ip, 0, 45),
            'Browser/device: ' . substr($agent, 0, 200),
            '',
            'If this was you, no action is needed. If you do not recognize',
            'this activity, change your password, review your Passkeys under',
            'Account Security, and contact CloudHost247 support immediately.',
        ];
        return implode("\n", $lines);
    }

    private static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
