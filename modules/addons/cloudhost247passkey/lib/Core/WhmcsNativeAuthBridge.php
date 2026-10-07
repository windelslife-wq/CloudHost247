<?php
/**
 * Native WHMCS session bridge for verified Passkey assertions.
 *
 * The bridge re-checks the current WHMCS account state, preserves existing
 * WHMCS two-factor authentication, and then establishes the standard WHMCS
 * session shape (client: uid/upw; administrator: adminid) with a regenerated
 * session ID. No parallel session, token, or account is created.
 *
 * Two-factor interaction (documented Option C): Passkey is an alternative
 * primary method. Accounts with WHMCS 2FA enabled keep the standard
 * password-plus-2FA login; this bridge never skips the second factor and
 * returns a two-factor-required handoff instead of a session.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\PasskeyLoginContext;
use CloudHost247\Passkey\Integration\WhmcsAuthBridgeInterface;
use CloudHost247\Passkey\Integration\WhmcsAuthHandoff;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Model\IdentityScope;

class WhmcsNativeAuthBridge implements WhmcsAuthBridgeInterface
{
    public function handoff(WhmcsIdentity $identity, PasskeyLoginContext $context)
    {
        $expectedSource = $identity->userType() === IdentityScope::ADMIN
            ? 'admin_login'
            : 'client_login';
        if ($context->source() !== $expectedSource) {
            throw new \RuntimeException('Passkey login context is bound to a different WHMCS audience.');
        }
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        if (function_exists('session_status') && session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('An active WHMCS session is required for Passkey login.');
        }

        if ($identity->userType() === IdentityScope::ADMIN) {
            return $this->handoffAdmin($identity);
        }
        if ($identity->userType() === IdentityScope::CLIENT) {
            return $this->handoffClient($identity);
        }
        // Secondary-user session mapping differs across WHMCS versions; fail
        // closed rather than guessing the uid/user_id session shape.
        throw new \RuntimeException('SERVICE_UNAVAILABLE');
    }

    private function handoffClient(WhmcsIdentity $identity)
    {
        $row = $this->whmcsRow('tblclients', $identity->userId());
        if ($row === null || (string) $row['status'] !== 'Active') {
            throw new \RuntimeException('The verified WHMCS identity is not loginable.');
        }
        if (isset($row['authmodule']) && trim((string) $row['authmodule']) !== '') {
            // Existing WHMCS 2FA stays authoritative; do not start a session.
            return WhmcsAuthHandoff::twoFactorRequired();
        }
        $passwordHash = isset($row['password']) ? (string) $row['password'] : '';
        if ($passwordHash === '') {
            throw new \RuntimeException('The verified WHMCS identity is not loginable.');
        }
        $this->regenerateSession();
        $this->setSessionValue('uid', $identity->userId());
        // Same value WHMCS password login stores: later password changes
        // invalidate this session through WHMCS's own validation.
        $this->setSessionValue('upw', $passwordHash);
        unset($_SESSION['ch247pk_passkey_pending']);
        $this->touchClientLogin($identity->userId());
        $this->logActivity(
            'CloudHost247 Passkey sign-in for client #' . $identity->userId() . '.',
            $identity->userId()
        );
        return WhmcsAuthHandoff::sessionEstablished();
    }

    private function handoffAdmin(WhmcsIdentity $identity)
    {
        $row = $this->whmcsRow('tbladmins', $identity->userId());
        if ($row === null || (int) $row['disabled'] !== 0) {
            throw new \RuntimeException('The verified WHMCS identity is not loginable.');
        }
        if (isset($row['authmodule']) && trim((string) $row['authmodule']) !== '') {
            // Administrator 2FA is never bypassed by Passkey login.
            return WhmcsAuthHandoff::twoFactorRequired();
        }
        $this->regenerateSession();
        $this->setSessionValue('adminid', $identity->userId());
        unset($_SESSION['ch247pk_passkey_pending']);
        $this->logActivity('CloudHost247 Passkey sign-in for administrator #' . $identity->userId() . '.');
        return WhmcsAuthHandoff::sessionEstablished();
    }

    private function whmcsRow($table, $id)
    {
        try {
            $row = \WHMCS\Database\Capsule::table($table)->where('id', (int) $id)->first();
        } catch (\Throwable $error) {
            throw new \RuntimeException('SERVICE_UNAVAILABLE');
        }
        return $row === null ? null : (array) $row;
    }

    private function regenerateSession()
    {
        if (function_exists('session_regenerate_id') && !headers_sent()) {
            @session_regenerate_id(true);
        }
        if (session_id() === '') {
            throw new \RuntimeException('WHMCS session renewal failed; login refused.');
        }
    }

    private function setSessionValue($key, $value)
    {
        if (class_exists('WHMCS\\Session') && method_exists('WHMCS\\Session', 'set')) {
            \WHMCS\Session::set($key, $value);
        }
        $_SESSION[$key] = $value;
    }

    private function touchClientLogin($clientId)
    {
        try {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
            \WHMCS\Database\Capsule::table('tblclients')->where('id', (int) $clientId)->update([
                'lastlogin' => gmdate('Y-m-d H:i:s'),
                'ip' => $ip,
                'host' => '',
            ]);
        } catch (\Throwable $error) {
            // Login bookkeeping must not break an otherwise valid handoff.
        }
    }

    private function logActivity($message, $userId = 0)
    {
        if (function_exists('logActivity')) {
            try {
                logActivity($message, (int) $userId);
            } catch (\Throwable $error) {
                // Activity logging is best-effort at the handoff boundary.
            }
        }
    }
}
