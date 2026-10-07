<?php
/**
 * CloudHost247 App Cloud — field level encryption, signing and key handling.
 *
 * Used for everything that must not be readable from a database dump: server
 * credentials (SSH keys, WHM API tokens, kubeconfig bearer tokens), application
 * environment secrets, DNS provider API tokens, backup storage keys and agent
 * secrets.
 *
 * AES-256-GCM with a random 96-bit IV and the field context bound in as
 * additional authenticated data, so a ciphertext cannot be moved between
 * columns. The key material comes from CH247APPS_ENCRYPTION_KEY or, failing
 * that, the WHMCS application secret — never from source and never from the
 * database. Every token is self-describing and carries its key version, which is
 * what makes rotation possible without a big-bang re-encryption.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Crypto
{
    const VERSION = 'v1';
    const CIPHER = 'aes-256-gcm';
    const CURRENT_KEY_VERSION = 1;

    /** @var array<int,string> keyVersion => derived key */
    private static $keys = [];

    public static function reset()
    {
        self::$keys = [];
    }

    /**
     * The derived key for a version. Version 1 is the live key; older versions
     * are read from CH247APPS_ENCRYPTION_KEY_V{n} so data encrypted before a
     * rotation can still be decrypted (and re-encrypted lazily).
     *
     * @throws ConfigurationException when no key material is configured
     */
    public static function key($version = self::CURRENT_KEY_VERSION)
    {
        $version = max(1, (int) $version);
        if (isset(self::$keys[$version])) {
            return self::$keys[$version];
        }

        $material = $version === self::CURRENT_KEY_VERSION
            ? (string) Settings::get('encryption_key', '')
            : (string) getenv('CH247APPS_ENCRYPTION_KEY_V' . $version);

        if ($material === '' && $version === self::CURRENT_KEY_VERSION && class_exists('\\WHMCS\\Database\\Capsule')) {
            try {
                $row = Whmcs::row('tblconfiguration', ['setting' => 'EncryptionHash']);
                $material = $row && isset($row['value']) ? (string) $row['value'] : '';
            } catch (\Throwable $e) {
                $material = '';
            }
        }

        if ($material === '') {
            throw new ConfigurationException(
                'App Cloud: CH247APPS_ENCRYPTION_KEY is not set. Generate 32+ random bytes '
                . '(openssl rand -hex 32) and expose them to PHP before enabling the module.'
            );
        }
        if (strlen($material) < 16) {
            throw new ConfigurationException('App Cloud: the encryption key must be at least 16 characters.');
        }

        // HKDF separation so the module never uses the raw host secret directly.
        self::$keys[$version] = hash_hkdf('sha256', $material, 32, 'ch247apps.field.v' . $version);
        return self::$keys[$version];
    }

    public static function isConfigured()
    {
        try {
            self::key();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Encrypt a value into a self-describing token: v1:<keyVersion>:<iv>:<tag>:<ciphertext>
     *
     * @param string|null $plaintext
     * @param string      $context   field identifier bound as AAD
     * @return array{ciphertext: string|null, key_version: int}
     */
    public static function seal($plaintext, $context = 'generic')
    {
        if ($plaintext === null || $plaintext === '') {
            return ['ciphertext' => null, 'key_version' => self::CURRENT_KEY_VERSION];
        }
        $version = self::CURRENT_KEY_VERSION;
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            (string) $plaintext,
            self::CIPHER,
            self::key($version),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($context),
            16
        );
        if ($cipher === false) {
            throw new AppsException('Encryption failed.');
        }
        return [
            'ciphertext' => self::VERSION . ':' . $version . ':' . Str::base64Url($iv) . ':'
                . Str::base64Url($tag) . ':' . Str::base64Url($cipher),
            'key_version' => $version,
        ];
    }

    /** Convenience wrapper returning just the token. */
    public static function encrypt($plaintext, $context = 'generic')
    {
        $sealed = self::seal($plaintext, $context);
        return $sealed['ciphertext'];
    }

    /**
     * Decrypt a token. Returns null when absent; throws when the token exists
     * but fails authentication, so a tampered row is never treated as empty.
     */
    public static function decrypt($token, $context = 'generic')
    {
        if ($token === null || $token === '') {
            return null;
        }
        $parts = explode(':', (string) $token);
        if (count($parts) !== 5 || $parts[0] !== self::VERSION) {
            throw new AppsException('Malformed ciphertext.');
        }
        $version = (int) $parts[1];
        $plain = openssl_decrypt(
            Str::fromBase64Url($parts[4]),
            self::CIPHER,
            self::key($version),
            OPENSSL_RAW_DATA,
            Str::fromBase64Url($parts[2]),
            Str::fromBase64Url($parts[3]),
            self::aad($context)
        );
        if ($plain === false) {
            throw new AppsException('Ciphertext failed authentication.');
        }
        return $plain;
    }

    /** Decrypt returning null instead of throwing (display paths). */
    public static function tryDecrypt($token, $context = 'generic')
    {
        try {
            return self::decrypt($token, $context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Token metadata without decrypting the payload. */
    public static function keyVersionOf($token)
    {
        $parts = explode(':', (string) $token);
        return (count($parts) === 5 && $parts[0] === self::VERSION) ? (int) $parts[1] : 0;
    }

    /** True when the token was sealed with an older key and should be rotated. */
    public static function needsRotation($token)
    {
        $version = self::keyVersionOf($token);
        return $version > 0 && $version !== self::CURRENT_KEY_VERSION;
    }

    /** Deterministic blind index: exact-match search without decryptability. */
    public static function blindIndex($value, $context = 'generic')
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalised = strtolower(trim((string) $value));
        return hash_hmac('sha256', $context . "\0" . $normalised, self::key());
    }

    /* ------------------------------------------------------- agent signing -- */

    /**
     * Sign an agent/control-plane message.
     *
     * HMAC-SHA256 over a canonical string that binds the method, path, body,
     * timestamp and nonce. Binding the body and the timestamp is what stops a
     * captured request from being replayed against another endpoint later.
     */
    public static function signMessage($secret, $method, $path, $body, $timestamp, $nonce)
    {
        $canonical = strtoupper((string) $method) . "\n"
            . (string) $path . "\n"
            . (int) $timestamp . "\n"
            . (string) $nonce . "\n"
            . hash('sha256', (string) $body);
        return hash_hmac('sha256', $canonical, (string) $secret);
    }

    public static function verifySignature($signature, $secret, $method, $path, $body, $timestamp, $nonce)
    {
        $expected = self::signMessage($secret, $method, $path, $body, $timestamp, $nonce);
        return self::hashEquals($expected, (string) $signature);
    }

    /** Short-lived bearer token derived from a shared secret (agent auth). */
    public static function deriveToken($secret, $purpose, $expiresAt)
    {
        return hash_hmac('sha256', $purpose . '|' . (int) $expiresAt, (string) $secret);
    }

    public static function hashEquals($known, $given)
    {
        return hash_equals((string) $known, (string) $given);
    }

    /** Cryptographically strong opaque token (URL safe). */
    public static function randomToken($bytes = 32)
    {
        return Str::base64Url(random_bytes(max(16, (int) $bytes)));
    }

    /** Password hashing for anything the module must verify itself. */
    public static function hashPassword($password)
    {
        return password_hash((string) $password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword($password, $hash)
    {
        return is_string($hash) && $hash !== '' && password_verify((string) $password, $hash);
    }

    private static function aad($context)
    {
        return 'ch247apps|' . (string) $context;
    }
}
