<?php
/**
 * Stable random opaque WebAuthn user handles mapped to existing WHMCS IDs.
 * The opaque value is never returned in public credential views.
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;
use Throwable;

class UserHandleRepository
{
    /** Return the binary user handle for a scoped existing WHMCS identity. */
    public static function getOrCreate($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $existing = self::findByIdentity($userType, $userId);
        if ($existing !== null) {
            return self::decodeStored($existing['user_handle']);
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $encoded = Base64Url::encode(random_bytes(32));
            $now = gmdate('Y-m-d H:i:s');
            try {
                Db::insert('user_handles', [
                    'user_type' => $userType,
                    'user_id' => $userId,
                    'user_handle' => $encoded,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return Base64Url::decode($encoded, 64);
            } catch (Throwable $error) {
                // The owner uniqueness constraint makes concurrent creation safe.
                $existing = self::findByIdentity($userType, $userId);
                if ($existing !== null) {
                    return self::decodeStored($existing['user_handle']);
                }
                if (Db::count('user_handles', ['user_handle' => $encoded]) > 0) {
                    continue;
                }
                throw $error;
            }
        }
        throw new \RuntimeException('Unable to allocate a unique WebAuthn user handle.');
    }

    /** Return a previously allocated binary handle, or null without creating one. */
    public static function findForIdentity($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $row = self::findByIdentity($userType, $userId);
        return $row === null ? null : self::decodeStored($row['user_handle']);
    }

    /** Resolve only a locally mapped, correctly sized random handle. */
    public static function findOwnerByHandle($binaryHandle)
    {
        $encoded = Base64Url::encode($binaryHandle);
        $row = Db::firstQuery(
            'SELECT `user_type`, `user_id`, `user_handle` FROM `' . Db::table('user_handles') . '` WHERE `user_handle` = ?',
            [$encoded]
        );
        if (!$row) {
            return null;
        }
        self::decodeStored($row['user_handle']);
        list($userType, $userId) = IdentityScope::validate($row['user_type'], $row['user_id']);
        return ['user_type' => $userType, 'user_id' => $userId];
    }

    /** Check exact scope and opaque handle equality without exposing the mapping. */
    public static function matches($binaryHandle, $userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $encoded = Base64Url::encode($binaryHandle);
        return (bool) Db::firstQuery(
            'SELECT `user_id` FROM `' . Db::table('user_handles') . '` '
                . 'WHERE `user_type` = ? AND `user_id` = ? AND `user_handle` = ?',
            [$userType, $userId, $encoded]
        );
    }

    private static function findByIdentity($userType, $userId)
    {
        return Db::firstQuery(
            'SELECT `user_handle` FROM `' . Db::table('user_handles') . '` WHERE `user_type` = ? AND `user_id` = ?',
            [$userType, $userId]
        );
    }

    private static function decodeStored($encoded)
    {
        $handle = Base64Url::decode($encoded, 64);
        if (strlen($handle) !== 32) {
            throw new \RuntimeException('Stored WebAuthn user handle has an invalid size.');
        }
        return $handle;
    }
}
