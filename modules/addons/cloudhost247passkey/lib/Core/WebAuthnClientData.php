<?php
/** Strict untrusted WebAuthn JSON/client-data preflight before the library validators run. */

namespace CloudHost247\Passkey\Core;

class WebAuthnClientData
{
    const RESPONSE_LIMIT = 262144;

    /** Decode untrusted client JSON and enforce exact origin/type/challenge shape. */
    public static function preflight($responseJson, $expectedType, WebAuthnConfig $config)
    {
        if (!in_array($expectedType, ['webauthn.create', 'webauthn.get'], true)) {
            throw new \InvalidArgumentException('Unsupported WebAuthn client-data ceremony type.');
        }
        if (!is_string($responseJson) || $responseJson === '' || strlen($responseJson) > self::RESPONSE_LIMIT) {
            throw new \InvalidArgumentException('WebAuthn response is missing or too large.');
        }
        $credential = json_decode($responseJson, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($credential)
            || ($credential['type'] ?? null) !== 'public-key'
            || !isset($credential['id'], $credential['rawId'], $credential['response'])
            || !is_string($credential['id']) || !is_string($credential['rawId'])
            || !is_array($credential['response'])
            || !isset($credential['response']['clientDataJSON'])
            || !is_string($credential['response']['clientDataJSON'])) {
            throw new \InvalidArgumentException('WebAuthn response has an invalid JSON structure.');
        }
        $rawId = Base64Url::decode($credential['rawId'], 8192);
        if ($rawId === '' || !hash_equals($credential['id'], $credential['rawId'])) {
            throw new \InvalidArgumentException('WebAuthn credential ID is not canonical.');
        }
        $clientDataBytes = Base64Url::decode($credential['response']['clientDataJSON'], 16384);
        $clientData = json_decode($clientDataBytes, true, 16);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($clientData)
            || ($clientData['type'] ?? null) !== $expectedType
            || !isset($clientData['challenge'], $clientData['origin'])
            || !is_string($clientData['challenge']) || !is_string($clientData['origin'])
            || !hash_equals($config->origin(), $clientData['origin'])) {
            throw new \RuntimeException('WebAuthn client data type or exact configured origin check failed.');
        }
        if (array_key_exists('crossOrigin', $clientData) && $clientData['crossOrigin'] !== false) {
            throw new \RuntimeException('Cross-origin WebAuthn ceremonies are not allowed.');
        }
        if (array_key_exists('topOrigin', $clientData)
            && (!is_string($clientData['topOrigin']) || !hash_equals($config->origin(), $clientData['topOrigin']))) {
            throw new \RuntimeException('Embedded WebAuthn ceremonies are not allowed.');
        }
        $challenge = Base64Url::decode($clientData['challenge'], 64);
        if (strlen($challenge) < 16 || strlen($challenge) > 64) {
            throw new \RuntimeException('WebAuthn client challenge has an invalid size.');
        }
        return ['challenge' => $challenge];
    }
}
