<?php
/**
 * Domain Broker — field level encryption.
 *
 * Used for the data that must not be readable from a database dump: domain
 * owner contact details (anonymous brokerage), EPP / auth codes, KYC reference
 * numbers and escrow provider references.
 *
 * AES-256-GCM with a random 96-bit IV and the field name bound in as
 * additional authenticated data, so a ciphertext cannot be moved from one
 * column to another. The key comes from the environment
 * (DOMAINBROKER_ENCRYPTION_KEY) or, failing that, from the WHMCS application
 * secret — never from source and never from the database.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Crypto
{
    const VERSION = 'v1';
    const CIPHER = 'aes-256-gcm';

    /** @var string|null cached derived key */
    protected static $key;

    public static function reset()
    {
        self::$key = null;
    }

    /**
     * @throws ConfigurationException when no key material is configured.
     */
    public static function key()
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $material = Settings::get('encryption_key');

        if (!$material && class_exists('\WHMCS\Database\Capsule')) {
            try {
                $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', 'EncryptionHash')->first();
                if ($row) {
                    $row = (array) $row;
                    $material = isset($row['value']) ? $row['value'] : '';
                }
            } catch (\Throwable $e) {
                $material = '';
            }
        }

        if (!$material) {
            throw new ConfigurationException(
                'Domain Broker: DOMAINBROKER_ENCRYPTION_KEY is not set. '
                . 'Generate 32+ random bytes and expose it to PHP before enabling the module.'
            );
        }

        // HKDF-style separation so the module never uses the raw host secret.
        self::$key = hash_hkdf('sha256', (string) $material, 32, 'domainbroker.field.v1');
        return self::$key;
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
     * Encrypt a value. Returns a self-describing, URL-safe token.
     *
     * @param string $plaintext
     * @param string $context    field identifier bound as AAD
     * @return string|null null for null input (so nullable columns stay null)
     */
    public static function encrypt($plaintext, $context = 'generic')
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            (string) $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($context),
            16
        );
        if ($cipher === false) {
            throw new DomainBrokerException('Encryption failed.');
        }
        return self::VERSION . ':' . self::b64($iv) . ':' . self::b64($tag) . ':' . self::b64($cipher);
    }

    /**
     * Decrypt a token produced by encrypt(). Returns null when the value is
     * absent; throws when the token is present but fails authentication, so a
     * tampered row is never silently treated as empty.
     */
    public static function decrypt($token, $context = 'generic')
    {
        if ($token === null || $token === '') {
            return null;
        }
        $parts = explode(':', (string) $token);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new DomainBrokerException('Malformed ciphertext.');
        }
        $iv = self::unb64($parts[1]);
        $tag = self::unb64($parts[2]);
        $cipher = self::unb64($parts[3]);
        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($context)
        );
        if ($plain === false) {
            throw new DomainBrokerException('Ciphertext failed authentication.');
        }
        return $plain;
    }

    /** Decrypt, returning null instead of throwing (display paths). */
    public static function tryDecrypt($token, $context = 'generic')
    {
        try {
            return self::decrypt($token, $context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Deterministic blind index so encrypted values remain searchable by exact
     * match without being decryptable.
     */
    public static function blindIndex($value, $context = 'generic')
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalised = mb_strtolower(trim((string) $value));
        return hash_hmac('sha256', $context . "\0" . $normalised, self::key());
    }

    /** Constant-time comparison helper. */
    public static function hashEquals($known, $given)
    {
        return hash_equals((string) $known, (string) $given);
    }

    /** Cryptographically strong opaque token (URL safe). */
    public static function randomToken($bytes = 32)
    {
        return self::b64(random_bytes(max(16, (int) $bytes)));
    }

    protected static function aad($context)
    {
        return 'domainbroker|' . (string) $context;
    }

    protected static function b64($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected static function unb64($value)
    {
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false) {
            throw new DomainBrokerException('Malformed ciphertext encoding.');
        }
        return $raw;
    }
}
