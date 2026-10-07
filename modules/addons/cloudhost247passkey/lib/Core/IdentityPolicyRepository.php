<?php
/** Read-only repository for per-existing-identity Passkey policy overrides. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\UserPolicyRecord;

class IdentityPolicyRepository
{
    public function find($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('user_policies') . '` WHERE `user_type` = ? AND `user_id` = ?',
            [$userType, $userId]
        );
        return $row ? new UserPolicyRecord($row) : null;
    }

    public function effectivePolicy($userType, $userId, $utcNow)
    {
        $record = $this->find($userType, $userId);
        return $record === null ? UserPolicyRecord::DEFAULT_POLICY : $record->effectivePolicy($utcNow);
    }
}
