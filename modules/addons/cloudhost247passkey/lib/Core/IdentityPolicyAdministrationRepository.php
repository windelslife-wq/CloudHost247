<?php
/** Write boundary for explicitly authorized per-identity Passkey policy changes. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\ModelValidation;
use CloudHost247\Passkey\Model\UserPolicyRecord;

class IdentityPolicyAdministrationRepository
{
    public function upsert($userType, $userId, $policy, $temporaryDisabledUntil, $reasonCode, $adminId, $utcNow)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $adminId = ModelValidation::positiveInt($adminId, 'updated_by_admin_id');
        $utcNow = ModelValidation::timestamp($utcNow, 'updated_at');
        $candidate = new UserPolicyRecord([
            'id' => 1,
            'user_type' => $userType,
            'user_id' => $userId,
            'policy' => $policy,
            'temporary_disabled_until' => $temporaryDisabledUntil,
            'reason_code' => $reasonCode,
            'updated_by_admin_id' => $adminId,
            'created_at' => $utcNow,
            'updated_at' => $utcNow,
        ]);
        $data = $candidate->toArray();
        $existing = (new IdentityPolicyRepository())->find($userType, $userId);
        if ($existing === null) {
            try {
                Db::insert('user_policies', [
                    'user_type' => $userType,
                    'user_id' => $userId,
                    'policy' => $data['policy'],
                    'temporary_disabled_until' => $data['temporary_disabled_until'],
                    'reason_code' => $data['reason_code'],
                    'updated_by_admin_id' => $data['updated_by_admin_id'],
                    'created_at' => $utcNow,
                    'updated_at' => $utcNow,
                ]);
            } catch (\PDOException $error) {
                // A concurrent authorized administrator may have inserted the
                // unique owner row; the scoped update below handles that case.
            }
        }
        Db::update(
            'user_policies',
            ['user_type' => $userType, 'user_id' => $userId],
            [
                'policy' => $data['policy'],
                'temporary_disabled_until' => $data['temporary_disabled_until'],
                'reason_code' => $data['reason_code'],
                'updated_by_admin_id' => $data['updated_by_admin_id'],
                'updated_at' => $utcNow,
            ]
        );
        $record = (new IdentityPolicyRepository())->find($userType, $userId);
        if (!$record) {
            throw new \RuntimeException('Passkey identity policy was not persisted.');
        }
        return $record;
    }
}
