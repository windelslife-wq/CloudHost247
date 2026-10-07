<?php
/** One-use, session-bound recovery authorization; it never resets a WHMCS password. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\ResetGrantRecord;

class PasskeyRecoveryGrantService
{
    const DEFAULT_TTL_SECONDS = 600;
    const MIN_TTL_SECONDS = 60;
    const MAX_TTL_SECONDS = 900;
    const TOKEN_BYTES = 32;

    /**
     * Issue an opaque grant for a host integration that has already
     * authenticated the existing WHMCS identity. The raw one-time value is
     * delivered only to the explicit host callback; it is never returned by
     * this service, logged, or persisted.
     */
    public function issue($userType, $userId, callable $deliverToken, $challengeId = null, $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if (!$deliverToken) {
            throw new \InvalidArgumentException('A host token-delivery callback is required.');
        }
        $challengeId = $this->validateOptionalId($challengeId, 'challengeId');
        $ttlSeconds = $this->validateTtl($ttlSeconds);
        $sessionBindingHash = SessionBinding::currentHash();
        $nowEpoch = time();
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);
        if ($challengeId !== null) {
            $this->assertChallengeBinding($challengeId, $userType, $userId, $sessionBindingHash, $now);
        }
        $expiresAt = gmdate('Y-m-d H:i:s', $nowEpoch + $ttlSeconds);
        $token = Base64Url::encode(random_bytes(self::TOKEN_BYTES));
        $tokenHash = self::tokenHash($token);

        Db::insert('reset_grants', [
            'token_hash' => $tokenHash,
            'user_type' => $userType,
            'user_id' => $userId,
            'session_binding_hash' => $sessionBindingHash,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => $now,
        ]);
        call_user_func($deliverToken, $token);
        return [
            'issued' => true,
            'user_type' => $userType,
            'user_id' => $userId,
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Consume exactly once for the same identity and active session. The
     * returned capability contains no token or database hash and is intended
     * only as a boundary result for a host integration.
     */
    public function consume($token, $userType, $userId, $challengeId = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $challengeId = $this->validateOptionalId($challengeId, 'challengeId');
        $token = self::validateToken($token);
        $sessionBindingHash = SessionBinding::currentHash();
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('reset_grants') . '` WHERE `token_hash` = ?',
            [self::tokenHash($token)]
        );
        $now = gmdate('Y-m-d H:i:s');
        if (!$row) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }
        $record = new ResetGrantRecord($row);
        $data = $record->toArray();
        if ($data['user_type'] !== $userType
            || $data['user_id'] !== $userId
            || !hash_equals($data['session_binding_hash'], $sessionBindingHash)
            || ($challengeId !== null && $data['challenge_id'] !== $challengeId)
            || !$record->isUsableAt($now)) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }

        // The consumed_at NULL predicate makes a second concurrent consume
        // fail closed without deleting history or creating a new session.
        $changed = Db::update(
            'reset_grants',
            ['id' => $data['id'], 'consumed_at' => null],
            ['consumed_at' => $now]
        );
        if ($changed !== 1) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }
        return [
            'user_type' => $data['user_type'],
            'user_id' => $data['user_id'],
            'challenge_id' => $data['challenge_id'],
        ];
    }

    public static function tokenHash($token)
    {
        return hash('sha256', 'CloudHost247PasskeyRecoveryGrant' . chr(0) . (string) $token);
    }

    private static function validateToken($token)
    {
        if (!is_string($token) || strlen($token) !== 43) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }
        try {
            $decoded = Base64Url::decode($token, self::TOKEN_BYTES);
        } catch (\Throwable $error) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }
        if (strlen($decoded) !== self::TOKEN_BYTES) {
            throw new \RuntimeException('The Passkey recovery grant is invalid or expired.');
        }
        return $token;
    }

    private function assertChallengeBinding($challengeId, $userType, $userId, $sessionBindingHash, $utcNow)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('challenges') . '` WHERE `id` = ?',
            [$challengeId]
        );
        if (!$row) {
            throw new \RuntimeException('The Passkey recovery challenge is invalid or expired.');
        }
        try {
            $challenge = new ChallengeRecord($row);
            $data = $challenge->toArray();
            if ($data['user_type'] !== $userType
                || $data['user_id'] !== $userId
                || !hash_equals($data['session_binding_hash'], $sessionBindingHash)
                || !$challenge->isUsableAt($utcNow)) {
                throw new \RuntimeException('The Passkey recovery challenge is invalid or expired.');
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getMessage() === 'The Passkey recovery challenge is invalid or expired.') {
                throw $error;
            }
            throw new \RuntimeException('The Passkey recovery challenge is invalid or expired.');
        }
    }

    private function validateOptionalId($value, $field)
    {
        if ($value === null) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || (int) $id < 1) {
            throw new \InvalidArgumentException($field . ' must be a positive integer.');
        }
        return (int) $id;
    }

    private function validateTtl($ttlSeconds)
    {
        $ttlSeconds = filter_var($ttlSeconds, FILTER_VALIDATE_INT);
        if ($ttlSeconds === false || (int) $ttlSeconds < self::MIN_TTL_SECONDS || (int) $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Passkey recovery-grant TTL must be between 60 and 900 seconds.');
        }
        return (int) $ttlSeconds;
    }
}
