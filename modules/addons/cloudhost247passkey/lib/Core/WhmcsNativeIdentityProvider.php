<?php
/**
 * Native WHMCS identity resolver backed by WHMCS-owned account tables.
 *
 * Client identities map to tblclients, secondary-user identities to tblusers,
 * and administrator identities to tbladmins. Only the current status flags
 * are read; no profile, password, or 2FA material is returned. Missing WHMCS
 * database access fails closed.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\IdentityScope;

class WhmcsNativeIdentityProvider implements WhmcsIdentityProviderInterface
{
    public function resolve($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);

        if ($userType === IdentityScope::CLIENT) {
            return $this->resolveClient($userId);
        }
        if ($userType === IdentityScope::CLIENT_USER) {
            return $this->resolveClientUser($userId);
        }
        return $this->resolveAdmin($userId);
    }

    private function resolveClient($clientId)
    {
        try {
            $row = $this->capsuleRow('tblclients', (int) $clientId);
        } catch (\Throwable $error) {
            throw new \RuntimeException('WHMCS client account state is unavailable.');
        }
        if ($row === null) {
            return null;
        }
        $status = isset($row['status']) ? (string) $row['status'] : '';
        if ($status !== 'Active') {
            return null;
        }
        return new WhmcsIdentity(IdentityScope::CLIENT, (int) $clientId);
    }

    private function resolveClientUser($userId)
    {
        try {
            $row = $this->capsuleRow('tblusers', (int) $userId);
        } catch (\Throwable $error) {
            throw new \RuntimeException('WHMCS user account state is unavailable.');
        }
        if ($row === null) {
            return null;
        }
        // tblusers rows carry no standalone status flag; eligibility follows
        // the linked client account, which the HTTP layer re-checks from the
        // live session before any privileged transition.
        return new WhmcsIdentity(IdentityScope::CLIENT_USER, (int) $userId);
    }

    private function resolveAdmin($adminId)
    {
        try {
            $row = $this->capsuleRow('tbladmins', (int) $adminId);
        } catch (\Throwable $error) {
            throw new \RuntimeException('WHMCS administrator account state is unavailable.');
        }
        if ($row === null) {
            return null;
        }
        $disabled = isset($row['disabled']) ? (int) $row['disabled'] : 1;
        if ($disabled !== 0) {
            return null;
        }
        return new WhmcsIdentity(IdentityScope::ADMIN, (int) $adminId);
    }

    /**
     * Read one WHMCS-owned row by primary key without exposing its payload.
     *
     * @throws \RuntimeException when the WHMCS database layer is unavailable.
     */
    private function capsuleRow($table, $id)
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            throw new \RuntimeException('WHMCS database layer is unavailable.');
        }
        $row = \WHMCS\Database\Capsule::table($table)->where('id', (int) $id)->first();
        if ($row === null) {
            return null;
        }
        return (array) $row;
    }
}
