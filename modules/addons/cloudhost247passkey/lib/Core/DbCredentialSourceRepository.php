<?php
/** Persistence adapter for web-auth/webauthn-lib's credential-source repository. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\CredentialRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\SafeMetadata;
use RuntimeException;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

class DbCredentialSourceRepository implements PublicKeyCredentialSourceRepository
{
    /** @var array<string,int> Original counts make updates optimistic and replay-safe. */
    private $loadedCounts = [];
    private $expectedUserType;
    private $expectedUserId;

    /** This adapter is always scoped to one WHMCS authentication audience. */
    public function restrictToIdentity($userType, $userId = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId, true);
        $this->expectedUserType = $userType;
        $this->expectedUserId = $userId;
        return $this;
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $canonicalId = Base64Url::encode($publicKeyCredentialId);
        $hash = hash('sha256', $canonicalId);
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('credentials') . '` '
                . 'WHERE `credential_id_hash` = ? AND `revoked_at` IS NULL AND `disabled_at` IS NULL',
            [$hash]
        );
        if (!$row || !$this->matchesExpectedScope($row)) {
            return null;
        }
        $source = $this->loadSource($row, $canonicalId);
        $this->loadedCounts[$hash] = (int) $row['sign_count'];
        return $source;
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array
    {
        $owner = UserHandleRepository::findOwnerByHandle($userEntity->getId());
        if ($owner === null || !$this->matchesExpectedScope($owner)) {
            return [];
        }
        $rows = Db::query(
            'SELECT * FROM `' . Db::table('credentials') . '` '
                . 'WHERE `user_type` = ? AND `user_id` = ? '
                . 'AND `revoked_at` IS NULL AND `disabled_at` IS NULL ORDER BY `id` ASC',
            [$owner['user_type'], $owner['user_id']]
        );
        $sources = [];
        foreach ($rows as $row) {
            $source = $this->loadSource($row, $row['credential_id']);
            if (!hash_equals($userEntity->getId(), $source->getUserHandle())) {
                throw new RuntimeException('Credential source user handle does not match the requested identity.');
            }
            $hash = hash('sha256', $row['credential_id']);
            $this->loadedCounts[$hash] = (int) $row['sign_count'];
            $sources[] = $source;
        }
        return $sources;
    }

    /** Persist a newly verified registration; credentials remain bound to the existing local identity. */
    public function saveNewCredentialSource(PublicKeyCredentialSource $source, $userType, $userId, $deviceName = 'Passkey', $registrationIp = null, $registrationUserAgent = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if ($source->getAttestationType() !== 'none') {
            throw new RuntimeException('Only anonymized none-attestation sources may be stored.');
        }
        if (!$this->matchesExpectedScope(['user_type' => $userType, 'user_id' => $userId])
            || !UserHandleRepository::matches($source->getUserHandle(), $userType, $userId)) {
            throw new RuntimeException('New credential user handle does not match the local identity.');
        }
        $canonicalId = Base64Url::encode($source->getPublicKeyCredentialId());
        $hash = hash('sha256', $canonicalId);
        if (Db::count('credentials', ['credential_id_hash' => $hash]) > 0) {
            throw new RuntimeException('This WebAuthn credential is already registered.');
        }
        try {
            $sourceJson = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Validated credential source could not be serialized.', 0, $error);
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'id' => 1,
            'user_type' => $userType,
            'user_id' => $userId,
            'credential_id' => $canonicalId,
            'credential_id_hash' => $hash,
            'public_key' => Base64Url::encode($source->getCredentialPublicKey()),
            'credential_source_json' => $sourceJson,
            'credential_type' => $source->getType(),
            'sign_count' => $source->getCounter(),
            'transports_json' => SafeMetadata::transportsJson($source->getTransports()),
            'authenticator_metadata_json' => SafeMetadata::authenticatorJson([
                'aaguid' => $source->getAaguid()->toString(),
                'transports' => $source->getTransports(),
            ]),
            'device_name' => $deviceName,
            'created_at' => $now,
            'last_used_at' => null,
            'revoked_at' => null,
            'disabled_at' => null,
            'registration_ip' => $registrationIp,
            'registration_user_agent' => $registrationUserAgent,
            'updated_at' => $now,
        ];
        $record = new CredentialRecord($row);
        return Db::insert('credentials', [
            'user_type' => $userType,
            'user_id' => $userId,
            'credential_id' => $record->credentialId(),
            'credential_id_hash' => $hash,
            'public_key' => $record->publicKey(),
            'credential_source_json' => $record->credentialSourceJson(),
            'credential_type' => 'public-key',
            'sign_count' => $source->getCounter(),
            'transports_json' => SafeMetadata::transportsJson($source->getTransports()),
            'authenticator_metadata_json' => SafeMetadata::authenticatorJson([
                'aaguid' => $source->getAaguid()->toString(),
                'transports' => $source->getTransports(),
            ]),
            'device_name' => $row['device_name'],
            'created_at' => $now,
            'last_used_at' => null,
            'revoked_at' => null,
            'disabled_at' => null,
            'registration_ip' => $row['registration_ip'],
            'registration_user_agent' => $row['registration_user_agent'],
            'updated_at' => $now,
        ]);
    }

    /** Return active descriptors to exclude duplicates during registration. */
    public function descriptorsForIdentity($userType, $userId): array
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if (!$this->matchesExpectedScope(['user_type' => $userType, 'user_id' => $userId])) {
            throw new RuntimeException('Credential descriptor query is outside its identity scope.');
        }
        $rows = Db::query(
            'SELECT * FROM `' . Db::table('credentials') . '` WHERE `user_type` = ? AND `user_id` = ? '
                . 'AND `revoked_at` IS NULL AND `disabled_at` IS NULL ORDER BY `id` ASC',
            [$userType, $userId]
        );
        $descriptors = [];
        foreach ($rows as $row) {
            $source = $this->loadSource($row, $row['credential_id']);
            $descriptors[] = $source->getPublicKeyCredentialDescriptor();
        }
        return $descriptors;
    }

    public function saveCredentialSource(PublicKeyCredentialSource $source): void
    {
        $canonicalId = Base64Url::encode($source->getPublicKeyCredentialId());
        $hash = hash('sha256', $canonicalId);
        if (!array_key_exists($hash, $this->loadedCounts)) {
            throw new RuntimeException('Cannot update a credential source that was not loaded by this verifier.');
        }
        $originalCount = $this->loadedCounts[$hash];
        if ($originalCount > 0 && $source->getCounter() <= $originalCount) {
            throw new RuntimeException('Authenticator signature counter did not advance.');
        }

        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('credentials') . '` '
                . 'WHERE `credential_id_hash` = ? AND `revoked_at` IS NULL AND `disabled_at` IS NULL',
            [$hash]
        );
        if (!$row || !$this->matchesExpectedScope($row) || (int) $row['sign_count'] !== $originalCount) {
            throw new RuntimeException('Credential changed or was disabled during assertion verification.');
        }
        if (!UserHandleRepository::matches($source->getUserHandle(), $row['user_type'], $row['user_id'])) {
            throw new RuntimeException('Credential user handle does not match the stored owner.');
        }

        try {
            $sourceJson = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Validated credential source could not be serialized.', 0, $error);
        }
        $updatedRow = $row;
        $updatedRow['credential_id'] = $canonicalId;
        $updatedRow['credential_source_json'] = $sourceJson;
        $updatedRow['public_key'] = Base64Url::encode($source->getCredentialPublicKey());
        $updatedRow['sign_count'] = $source->getCounter();
        $updatedRow['transports_json'] = SafeMetadata::transportsJson($source->getTransports());
        $updatedRow['updated_at'] = gmdate('Y-m-d H:i:s');
        $record = new CredentialRecord($updatedRow);
        $values = [
            'credential_source_json' => $record->credentialSourceJson(),
            'public_key' => $record->publicKey(),
            'sign_count' => $source->getCounter(),
            'transports_json' => SafeMetadata::transportsJson($source->getTransports()),
            'last_used_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $changed = Db::update('credentials', [
            'credential_id_hash' => $hash,
            'user_type' => $row['user_type'],
            'user_id' => (int) $row['user_id'],
            'sign_count' => $originalCount,
            'revoked_at' => null,
            'disabled_at' => null,
        ], $values);
        if ($source->getCounter() !== $originalCount && $changed !== 1) {
            throw new RuntimeException('Credential counter update lost an optimistic concurrency race.');
        }
        if ($changed === 0) {
            $after = Db::firstQuery(
                'SELECT `sign_count`, `credential_source_json` FROM `' . Db::table('credentials') . '` '
                    . 'WHERE `credential_id_hash` = ? AND `revoked_at` IS NULL AND `disabled_at` IS NULL',
                [$hash]
            );
            if (!$after || (int) $after['sign_count'] !== $source->getCounter()
                || !hash_equals((string) $after['credential_source_json'], $record->credentialSourceJson())) {
                throw new RuntimeException('Credential counter update lost an optimistic concurrency race.');
            }
        }
        $this->loadedCounts[$hash] = $source->getCounter();
    }

    private function matchesExpectedScope(array $row)
    {
        if ($this->expectedUserType === null) {
            throw new RuntimeException('Credential-source repository has no authentication scope.');
        }
        return isset($row['user_type'], $row['user_id'])
            && $row['user_type'] === $this->expectedUserType
            && ($this->expectedUserId === null || (int) $row['user_id'] === $this->expectedUserId);
    }

    private function loadSource(array $row, $expectedCanonicalId)
    {
        $record = new CredentialRecord($row);
        if (!hash_equals($record->credentialId(), (string) $expectedCanonicalId)) {
            throw new RuntimeException('Credential lookup returned a different credential ID.');
        }
        try {
            $sourceData = json_decode($record->credentialSourceJson(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($sourceData)) {
                throw new RuntimeException('Stored WebAuthn credential source is not an object.');
            }
            $source = PublicKeyCredentialSource::createFromArray($sourceData);
        } catch (\Throwable $error) {
            throw new RuntimeException('Stored WebAuthn credential source could not be loaded by the library.', 0, $error);
        }
        if (!hash_equals($source->getPublicKeyCredentialId(), Base64Url::decode($record->credentialId(), 8192))
            || !hash_equals($source->getCredentialPublicKey(), Base64Url::decode($record->publicKey(), 65535))
            || $source->getCounter() !== (int) $row['sign_count']
            || !UserHandleRepository::matches($source->getUserHandle(), $row['user_type'], $row['user_id'])) {
            throw new RuntimeException('Stored WebAuthn credential source failed its integrity checks.');
        }
        return $source;
    }
}
