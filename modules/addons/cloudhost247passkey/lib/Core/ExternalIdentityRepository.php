<?php
/** Scoped, append-preserving repository for externally verified identity links. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\ExternalIdentityClaims;
use CloudHost247\Passkey\Model\ExternalIdentityRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\ModelValidation;

class ExternalIdentityRepository
{
    public function findByHash($identityHash)
    {
        $identityHash = ModelValidation::hash($identityHash, 'identity_hash');
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('external_identities') . '` WHERE `identity_hash` = ?',
            [$identityHash]
        );
        return $row ? new ExternalIdentityRecord($row) : null;
    }

    public function findForOwner($userType, $userId, $recordId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $recordId = ModelValidation::positiveInt($recordId, 'recordId');
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('external_identities') . '` '
                . 'WHERE `id` = ? AND `user_type` = ? AND `user_id` = ?',
            [$recordId, $userType, $userId]
        );
        return $row ? new ExternalIdentityRecord($row) : null;
    }

    public function listForOwner($userType, $userId, $includeRevoked = true)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $sql = 'SELECT * FROM `' . Db::table('external_identities') . '` '
            . 'WHERE `user_type` = ? AND `user_id` = ?';
        $bindings = [$userType, $userId];
        if (!$includeRevoked) {
            $sql .= ' AND `revoked_at` IS NULL';
        }
        $sql .= ' ORDER BY `linked_at` DESC, `id` DESC';
        $rows = Db::query($sql, $bindings);
        $records = [];
        foreach ($rows as $row) {
            $records[] = new ExternalIdentityRecord($row);
        }
        return $records;
    }

    public function create(ExternalIdentityClaims $claims, $userType, $userId, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if (!$claims instanceof ExternalIdentityClaims) {
            throw new \InvalidArgumentException('Verified external identity claims are required.');
        }
        $utcNow = $utcNow === null ? gmdate('Y-m-d H:i:s') : $utcNow;
        $utcNow = ModelValidation::timestamp($utcNow, 'utcNow');
        $data = $claims->toArray();
        $id = Db::insert('external_identities', [
            'provider' => $data['provider'],
            'tenant_id' => $data['tenant_id'],
            'subject_id' => $data['subject_id'],
            'identity_hash' => $data['identity_hash'],
            'user_type' => $userType,
            'user_id' => $userId,
            'linked_at' => $utcNow,
            'last_authenticated_at' => null,
            'revoked_at' => null,
            'created_at' => $utcNow,
            'updated_at' => $utcNow,
        ]);
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('external_identities') . '` WHERE `id` = ?',
            [$id]
        );
        if (!$row) {
            throw new \RuntimeException('External identity link was not persisted.');
        }
        return new ExternalIdentityRecord($row);
    }

    public function restoreForOwner($userType, $userId, $recordId, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $recordId = ModelValidation::positiveInt($recordId, 'recordId');
        $utcNow = $utcNow === null ? gmdate('Y-m-d H:i:s') : ModelValidation::timestamp($utcNow, 'utcNow');
        $changed = Db::execute(
            'UPDATE `' . Db::table('external_identities') . '` '
                . 'SET `revoked_at` = NULL, `linked_at` = ?, `updated_at` = ? '
                . 'WHERE `id` = ? AND `user_type` = ? AND `user_id` = ? AND `revoked_at` IS NOT NULL',
            [$utcNow, $utcNow, $recordId, $userType, $userId]
        );
        if ($changed !== 1) {
            throw new \RuntimeException('External identity link is missing, active, or outside the current identity scope.');
        }
        $record = $this->findForOwner($userType, $userId, $recordId);
        if (!$record) {
            throw new \RuntimeException('External identity link was not restored.');
        }
        return $record;
    }

    public function revokeForOwner($userType, $userId, $recordId, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $recordId = ModelValidation::positiveInt($recordId, 'recordId');
        $utcNow = $utcNow === null ? gmdate('Y-m-d H:i:s') : ModelValidation::timestamp($utcNow, 'utcNow');
        $changed = Db::update(
            'external_identities',
            ['id' => $recordId, 'user_type' => $userType, 'user_id' => $userId, 'revoked_at' => null],
            ['revoked_at' => $utcNow, 'updated_at' => $utcNow]
        );
        if ($changed !== 1) {
            throw new \RuntimeException('External identity link is missing, revoked, or outside the current identity scope.');
        }
        $record = $this->findForOwner($userType, $userId, $recordId);
        if (!$record) {
            throw new \RuntimeException('External identity link was not revoked.');
        }
        return $record;
    }

    public function touchAuthenticatedForOwner($userType, $userId, $recordId, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $recordId = ModelValidation::positiveInt($recordId, 'recordId');
        $utcNow = $utcNow === null ? gmdate('Y-m-d H:i:s') : ModelValidation::timestamp($utcNow, 'utcNow');
        $changed = Db::update(
            'external_identities',
            ['id' => $recordId, 'user_type' => $userType, 'user_id' => $userId, 'revoked_at' => null],
            ['last_authenticated_at' => $utcNow, 'updated_at' => $utcNow]
        );
        if ($changed !== 1) {
            throw new \RuntimeException('External identity link is missing, revoked, or outside the current identity scope.');
        }
        return $this->findForOwner($userType, $userId, $recordId);
    }
}
