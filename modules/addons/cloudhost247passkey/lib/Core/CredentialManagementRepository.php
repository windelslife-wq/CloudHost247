<?php
/** Scoped lifecycle operations for already-verified public credentials. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\CredentialRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use RuntimeException;

class CredentialManagementRepository
{
    public function countActive($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        return Db::count('credentials', [
            'user_type' => $userType,
            'user_id' => $userId,
            'revoked_at' => null,
            'disabled_at' => null,
        ]);
    }

    /** Return masked, non-secret summaries scoped to one existing identity. */
    public function listForIdentity($userType, $userId, $includeInactive = true)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $sql = 'SELECT * FROM `' . Db::table('credentials') . '` WHERE `user_type` = ? AND `user_id` = ?';
        $bindings = [$userType, $userId];
        if (!$includeInactive) {
            $sql .= ' AND `revoked_at` IS NULL AND `disabled_at` IS NULL';
        }
        $sql .= ' ORDER BY `id` ASC';
        $rows = Db::query($sql, $bindings);
        $summaries = [];
        foreach ($rows as $row) {
            $summaries[] = (new CredentialRecord($row))->toPublicArray();
        }
        return $summaries;
    }

    public function rename($userType, $userId, $credentialRecordId, $deviceName)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $credentialRecordId = self::recordId($credentialRecordId);
        $deviceName = self::validateDeviceName($deviceName);
        $this->activeRecord($userType, $userId, $credentialRecordId);
        $changed = Db::update('credentials', [
            'id' => $credentialRecordId,
            'user_type' => $userType,
            'user_id' => $userId,
            'revoked_at' => null,
        ], [
            'device_name' => $deviceName,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($changed !== 1) {
            throw new RuntimeException('Passkey credential could not be renamed.');
        }
        return $this->recordSummary($userType, $userId, $credentialRecordId);
    }

    public function revoke($userType, $userId, $credentialRecordId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $credentialRecordId = self::recordId($credentialRecordId);
        $this->activeRecord($userType, $userId, $credentialRecordId);
        $now = gmdate('Y-m-d H:i:s');
        $changed = Db::update('credentials', [
            'id' => $credentialRecordId,
            'user_type' => $userType,
            'user_id' => $userId,
            'revoked_at' => null,
        ], [
            'revoked_at' => $now,
            'updated_at' => $now,
        ]);
        if ($changed !== 1) {
            throw new RuntimeException('Passkey credential could not be revoked.');
        }
        return $this->recordSummary($userType, $userId, $credentialRecordId);
    }

    public function setDisabled($userType, $userId, $credentialRecordId, $disabled)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $credentialRecordId = self::recordId($credentialRecordId);
        $this->nonRevokedRecord($userType, $userId, $credentialRecordId);
        $disabled = (bool) $disabled;
        $now = gmdate('Y-m-d H:i:s');
        $changed = Db::update('credentials', [
            'id' => $credentialRecordId,
            'user_type' => $userType,
            'user_id' => $userId,
            'revoked_at' => null,
        ], [
            'disabled_at' => $disabled ? $now : null,
            'updated_at' => $now,
        ]);
        if ($changed !== 1) {
            throw new RuntimeException('Passkey credential state could not be changed.');
        }
        return $this->recordSummary($userType, $userId, $credentialRecordId);
    }

    private function activeRecord($userType, $userId, $credentialRecordId)
    {
        $record = $this->nonRevokedRecord($userType, $userId, $credentialRecordId);
        if (!$record->isActive()) {
            throw new RuntimeException('Passkey credential is disabled.');
        }
        return $record;
    }

    private function nonRevokedRecord($userType, $userId, $credentialRecordId)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('credentials') . '` WHERE `id` = ? AND `user_type` = ? AND `user_id` = ? '
                . 'AND `revoked_at` IS NULL',
            [$credentialRecordId, $userType, $userId]
        );
        if (!$row) {
            throw new RuntimeException('Passkey credential is missing, revoked, or outside the identity scope.');
        }
        return new CredentialRecord($row);
    }

    private function recordSummary($userType, $userId, $credentialRecordId)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('credentials') . '` WHERE `id` = ? AND `user_type` = ? AND `user_id` = ?',
            [$credentialRecordId, $userType, $userId]
        );
        if (!$row) {
            throw new RuntimeException('Passkey credential disappeared after its update.');
        }
        return (new CredentialRecord($row))->toPublicArray();
    }

    private static function recordId($value)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || (int) $id < 1) {
            throw new \InvalidArgumentException('Passkey credential record ID is invalid.');
        }
        return (int) $id;
    }

    public static function validateDeviceName($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException('Passkey device name is invalid.');
        }
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Passkey device name is empty or invalid.');
        }
        return $value;
    }
}
