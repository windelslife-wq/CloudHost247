<?php
/** Single-use, short-lived challenges bound to browser session, RP, origin, type and identity. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use RuntimeException;

class CeremonyChallengeStore
{
    const TTL_SECONDS = 300;

    public function issue($rawChallenge, $challengeType, $userType, $userId, WebAuthnConfig $config, $optionsJson)
    {
        if (!is_string($rawChallenge) || strlen($rawChallenge) < 16 || strlen($rawChallenge) > 64) {
            throw new \InvalidArgumentException('WebAuthn challenge must contain 16 to 64 random bytes.');
        }
        if (!in_array($challengeType, [ChallengeRecord::REGISTRATION, ChallengeRecord::AUTHENTICATION], true)) {
            throw new \InvalidArgumentException('Unsupported WebAuthn ceremony type.');
        }
        list($userType, $userId) = IdentityScope::validate($userType, $userId, true);
        if ($challengeType === ChallengeRecord::REGISTRATION && $userId === null) {
            throw new \InvalidArgumentException('Registration challenges require a known local identity.');
        }
        if (!is_string($optionsJson) || $optionsJson === '' || strlen($optionsJson) > 262144
            || !self::optionsMatchChallenge($optionsJson, $rawChallenge)) {
            throw new \InvalidArgumentException('WebAuthn option state is invalid.');
        }

        $nowEpoch = time();
        $expiresEpoch = $nowEpoch + self::TTL_SECONDS;
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);
        $expires = gmdate('Y-m-d H:i:s', $expiresEpoch);
        $challengeHash = hash('sha256', $rawChallenge);
        $sessionHash = SessionBinding::currentHash();
        $row = [
            'challenge_hash' => $challengeHash,
            'user_type' => $userType,
            'user_id' => $userId,
            'challenge_type' => $challengeType,
            'action_code' => null,
            'session_binding_hash' => $sessionHash,
            'rp_id' => $config->rpId(),
            'origin' => $config->origin(),
            'expires_at' => $expires,
            'consumed_at' => null,
            'created_at' => $now,
        ];
        $id = Db::insert('challenges', $row);
        $row['id'] = $id;
        new ChallengeRecord($row);

        try {
            SessionCeremonyStore::put($challengeHash, [
                'challenge_type' => $challengeType,
                'user_type' => $userType,
                'user_id' => $userId,
                'rp_id' => $config->rpId(),
                'origin' => $config->origin(),
                'options_json' => $optionsJson,
                'created_epoch' => $nowEpoch,
                'expires_epoch' => $expiresEpoch,
            ]);
        } catch (\Throwable $error) {
            Db::execute('DELETE FROM `' . Db::table('challenges') . '` WHERE `id` = ? AND `consumed_at` IS NULL', [$id]);
            throw $error;
        }
        return $challengeHash;
    }

    /** Return the server-side options JSON after atomically consuming the challenge. */
    public function consume($rawChallenge, $challengeType, $userType, $userId, WebAuthnConfig $config)
    {
        if (!is_string($rawChallenge) || strlen($rawChallenge) < 16 || strlen($rawChallenge) > 64) {
            throw new \InvalidArgumentException('Malformed WebAuthn challenge.');
        }
        list($userType, $userId) = IdentityScope::validate($userType, $userId, true);
        $challengeHash = hash('sha256', $rawChallenge);
        $sessionHash = SessionBinding::currentHash();
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('challenges') . '` WHERE `challenge_hash` = ?',
            [$challengeHash]
        );
        if (!$row) {
            throw new RuntimeException('WebAuthn challenge is unknown or expired.');
        }
        $record = new ChallengeRecord($row);
        $challenge = $record->toArray();
        $now = gmdate('Y-m-d H:i:s');
        if (!$record->isUsableAt($now)
            || $challenge['challenge_type'] !== $challengeType
            || $challenge['user_type'] !== $userType
            || $challenge['user_id'] !== $userId
            || !hash_equals($challenge['session_binding_hash'], $sessionHash)
            || !hash_equals($challenge['rp_id'], $config->rpId())
            || !hash_equals($challenge['origin'], $config->origin())) {
            throw new RuntimeException('WebAuthn challenge binding failed.');
        }
        $state = SessionCeremonyStore::peek($challengeHash);
        if ($state === null
            || ($state['challenge_type'] ?? null) !== $challengeType
            || ($state['user_type'] ?? null) !== $userType
            || ($state['user_id'] ?? null) !== $userId
            || !isset($state['rp_id'], $state['origin'], $state['options_json'])
            || !hash_equals($config->rpId(), (string) $state['rp_id'])
            || !hash_equals($config->origin(), (string) $state['origin'])
            || !self::optionsMatchChallenge((string) $state['options_json'], $rawChallenge)) {
            throw new RuntimeException('WebAuthn ceremony state is missing or does not match its challenge.');
        }

        $changed = Db::update('challenges', [
            'id' => $challenge['id'],
            'consumed_at' => null,
        ], ['consumed_at' => $now]);
        if ($changed !== 1) {
            throw new RuntimeException('WebAuthn challenge has already been consumed.');
        }
        SessionCeremonyStore::remove($challengeHash);
        return $state['options_json'];
    }

    private static function optionsMatchChallenge($optionsJson, $rawChallenge)
    {
        $options = json_decode($optionsJson, true, 32);
        return json_last_error() === JSON_ERROR_NONE
            && is_array($options)
            && isset($options['challenge'])
            && is_string($options['challenge'])
            && hash_equals(Base64Url::encode($rawChallenge), $options['challenge']);
    }
}
