<?php
/** Allowlisted, non-secret metadata encoders for audit and authenticator hints. */

namespace CloudHost247\Passkey\Model;

class SafeMetadata
{
    private const EVENT_KEYS = [
        'origin', 'rp_id', 'transport', 'user_verification', 'device_type',
        'error_code', 'policy_source', 'action_code', 'credential_count',
        'notification_status',
    ];

    private const AUTHENTICATOR_KEYS = [
        'aaguid', 'attachment', 'credential_device_type', 'backup_eligible',
        'backed_up', 'user_verified', 'resident_key', 'transports', 'uv_capable',
    ];

    private const FORBIDDEN_KEYS = [
        'privatekey', 'secretkey', 'secret', 'password', 'challenge', 'rawchallenge',
        'sessionid', 'signature', 'assertion', 'authenticatordata', 'attestationobject',
        'biometricdata', 'biometrictemplate', 'facedata', 'faceid', 'touchid',
        'fingerprint', 'fingerprintdata', 'devicepin', 'credentialid', 'clientdatajson',
        'rawresponse', 'privatekeymaterial', 'accesstoken', 'refreshtoken', 'idtoken',
    ];

    public static function eventJson($metadata)
    {
        return self::encode($metadata, self::EVENT_KEYS);
    }

    public static function authenticatorJson($metadata)
    {
        return self::encode($metadata, self::AUTHENTICATOR_KEYS);
    }

    public static function eventArray($json)
    {
        return self::decode($json, self::EVENT_KEYS);
    }

    public static function authenticatorArray($json)
    {
        return self::decode($json, self::AUTHENTICATOR_KEYS);
    }

    public static function transportsJson($transports)
    {
        return json_encode(self::transportsArray($transports), JSON_UNESCAPED_SLASHES);
    }

    public static function transportsArray($transports)
    {
        if (is_string($transports)) {
            $decoded = json_decode($transports, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Transports must be a JSON array.');
            }
            $transports = $decoded;
        }
        if (!is_array($transports)) {
            throw new \InvalidArgumentException('Transports must be an array.');
        }
        $result = [];
        foreach ($transports as $transport) {
            $transport = strtolower(trim((string) $transport));
            if (!preg_match('/^[a-z0-9-]{1,32}$/', $transport)) {
                throw new \InvalidArgumentException('Invalid authenticator transport.');
            }
            if (!in_array($transport, $result, true)) {
                $result[] = $transport;
            }
        }
        return $result;
    }

    private static function encode($metadata, array $allowed)
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Metadata must be a JSON object or array.');
            }
            $metadata = $decoded;
        }
        if (!is_array($metadata)) {
            throw new \InvalidArgumentException('Metadata must be an array.');
        }
        self::rejectSecrets($metadata);
        $clean = [];
        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 255) {
                $clean[$key] = $value;
            }
        }
        return json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decode($json, array $allowed)
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Stored metadata is not valid JSON.');
        }
        self::rejectSecrets($decoded);
        $clean = [];
        foreach ($decoded as $key => $value) {
            if (in_array((string) $key, $allowed, true)) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    private static function rejectSecrets(array $metadata)
    {
        foreach (array_keys($metadata) as $key) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('Secret, biometric, or raw ceremony fields cannot be stored in metadata.');
            }
        }
    }
}
