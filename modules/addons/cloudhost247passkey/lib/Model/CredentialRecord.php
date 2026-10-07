<?php
/** Server-side passkey credential model. Private keys and biometrics are excluded. */

namespace CloudHost247\Passkey\Model;

class CredentialRecord
{
    private $row;

    public function __construct(array $row)
    {
        ModelValidation::allowKeys($row, [
            'id', 'user_type', 'user_id', 'credential_id', 'credential_id_hash', 'public_key',
            'credential_source_json', 'credential_type', 'sign_count', 'transports_json',
            'authenticator_metadata_json', 'device_name', 'created_at', 'last_used_at',
            'revoked_at', 'disabled_at', 'registration_ip', 'registration_user_agent', 'updated_at',
        ], 'credential');
        ModelValidation::rejectKeys($row, [
            'private_key', 'private_key_material', 'secret_key', 'biometric', 'biometric_data',
            'biometric_template', 'fingerprint', 'fingerprint_data', 'face_data', 'device_pin',
            'attestation_object', 'attestation', 'assertion', 'signature',
        ]);

        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null);
        $credentialId = ModelValidation::text($row['credential_id'] ?? null, 'credential_id', 8192);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $credentialId)) {
            throw new \InvalidArgumentException('Credential ID must be canonical base64url text.');
        }
        $credentialHash = ModelValidation::hash($row['credential_id_hash'] ?? '', 'credential_id_hash');
        if (!hash_equals(hash('sha256', $credentialId), $credentialHash)) {
            throw new \InvalidArgumentException('Credential ID hash does not match the canonical credential ID.');
        }
        if (isset($row['credential_type']) && (string) $row['credential_type'] !== 'public-key') {
            throw new \InvalidArgumentException('Only public-key credentials are supported.');
        }

        $publicKey = ModelValidation::text($row['public_key'] ?? null, 'public_key', 65535);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $publicKey)) {
            throw new \InvalidArgumentException('Credential public key must be canonical base64url text.');
        }
        $signCount = ModelValidation::nonNegativeInt($row['sign_count'] ?? 0, 'sign_count');
        $sourceJson = ModelValidation::text($row['credential_source_json'] ?? null, 'credential_source_json', 262144);
        $source = json_decode($sourceJson, true, 32);
        $sourceKeys = [
            'publicKeyCredentialId', 'type', 'transports', 'attestationType', 'trustPath',
            'aaguid', 'credentialPublicKey', 'userHandle', 'counter', 'otherUI',
        ];
        $requiredSourceKeys = array_slice($sourceKeys, 0, 9);
        if (!is_array($source) || array_diff(array_keys($source), $sourceKeys)
            || array_diff($requiredSourceKeys, array_keys($source))
            || $source['type'] !== 'public-key'
            || $source['publicKeyCredentialId'] !== $credentialId
            || $source['credentialPublicKey'] !== $publicKey
            || !is_int($source['counter']) || $source['counter'] !== $signCount
            || !is_array($source['transports'])
            || !is_array($source['trustPath'])
            || !is_string($source['aaguid'])
            || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $source['aaguid'])
            || !is_string($source['attestationType']) || strlen($source['attestationType']) > 64
            || !is_string($source['userHandle']) || strlen($source['userHandle']) !== 43
            || !preg_match('/^[A-Za-z0-9_-]+$/', $source['userHandle'])
            || (array_key_exists('otherUI', $source) && $source['otherUI'] !== null)) {
            throw new \InvalidArgumentException('Credential source is incomplete or does not match the stored public credential fields.');
        }
        self::rejectSensitiveSourceKeys($source);
        SafeMetadata::transportsArray($source['transports']);
        $handleBase64 = strtr($source['userHandle'], '-_', '+/');
        $handleBase64 .= str_repeat('=', (4 - strlen($handleBase64) % 4) % 4);
        $handleBytes = base64_decode($handleBase64, true);
        if (!is_string($handleBytes) || strlen($handleBytes) !== 32
            || !hash_equals(rtrim(strtr(base64_encode($handleBytes), '+/', '-_'), '='), $source['userHandle'])) {
            throw new \InvalidArgumentException('Credential source user handle is not canonical base64url.');
        }

        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'user_type' => $userType,
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'credential_id_hash' => $credentialHash,
            'public_key' => $publicKey,
            'credential_source_json' => $sourceJson,
            'credential_type' => 'public-key',
            'sign_count' => $signCount,
            'transports_json' => SafeMetadata::transportsJson($row['transports_json'] ?? $source['transports']),
            'authenticator_metadata_json' => SafeMetadata::authenticatorJson($row['authenticator_metadata_json'] ?? []),
            'device_name' => ModelValidation::text($row['device_name'] ?? 'Passkey', 'device_name', 120),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
            'last_used_at' => ModelValidation::timestamp($row['last_used_at'] ?? null, 'last_used_at', true),
            'revoked_at' => ModelValidation::timestamp($row['revoked_at'] ?? null, 'revoked_at', true),
            'disabled_at' => ModelValidation::timestamp($row['disabled_at'] ?? null, 'disabled_at', true),
            'registration_ip' => ModelValidation::ipAddress($row['registration_ip'] ?? null, 'registration_ip', true),
            'registration_user_agent' => isset($row['registration_user_agent'])
                ? ModelValidation::text($row['registration_user_agent'], 'registration_user_agent', 512, true)
                : null,
            'updated_at' => ModelValidation::timestamp($row['updated_at'] ?? null, 'updated_at'),
        ];
    }

    public function status()
    {
        if ($this->row['revoked_at'] !== null) {
            return 'revoked';
        }
        return $this->row['disabled_at'] !== null ? 'disabled' : 'active';
    }

    public function isActive()
    {
        return $this->status() === 'active';
    }

    /** Base64url ID/source values are private server-side verifier inputs only. */
    public function credentialId()
    {
        return $this->row['credential_id'];
    }

    public function publicKey()
    {
        return $this->row['public_key'];
    }

    public function credentialSourceJson()
    {
        return $this->row['credential_source_json'];
    }

    public function toPublicArray()
    {
        $credentialId = (string) $this->row['credential_id'];
        $display = strlen($credentialId) > 16
            ? substr($credentialId, 0, 8) . '…' . substr($credentialId, -4)
            : str_repeat('•', strlen($credentialId));
        return [
            'id' => $this->row['id'],
            'user_type' => $this->row['user_type'],
            'user_id' => $this->row['user_id'],
            'device_name' => $this->row['device_name'],
            'credential_id_display' => $display,
            'created_at' => $this->row['created_at'],
            'last_used_at' => $this->row['last_used_at'],
            'status' => $this->status(),
            'transports' => SafeMetadata::transportsArray($this->row['transports_json']),
            'authenticator' => SafeMetadata::authenticatorArray($this->row['authenticator_metadata_json']),
        ];
    }

    private static function rejectSensitiveSourceKeys($value)
    {
        if (!is_array($value)) {
            return;
        }
        $forbidden = [
            'privatekey', 'privatekeymaterial', 'secretkey', 'secret', 'password',
            'biometric', 'biometricdata', 'biometrictemplate', 'fingerprint',
            'fingerprintdata', 'facedata', 'devicepin', 'sessionid', 'challenge',
            'rawchallenge', 'signature', 'assertion', 'authenticatordata',
            'attestationobject', 'clientdatajson',
        ];
        foreach ($value as $key => $child) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, $forbidden, true)) {
                throw new \InvalidArgumentException('Credential source contains prohibited secret or ceremony data.');
            }
            self::rejectSensitiveSourceKeys($child);
        }
    }
}
