<?php
/** Canonical unpadded base64url codec used at the WebAuthn JSON boundary. */

namespace CloudHost247\Passkey\Core;

class Base64Url
{
    public static function encode($bytes)
    {
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException('Base64url input must be binary string data.');
        }
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode($encoded, $maxBytes = 65536)
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4
            || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded) || strlen($encoded) % 4 === 1) {
            throw new \InvalidArgumentException('Malformed base64url value.');
        }
        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if (!is_string($decoded) || strlen($decoded) > $maxBytes || !hash_equals(self::encode($decoded), $encoded)) {
            throw new \InvalidArgumentException('Non-canonical base64url value.');
        }
        return $decoded;
    }
}
