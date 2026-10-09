<?php
/**
 * Credential sealing for provider integrations.
 *
 * Provider API credentials (registrar keys, EPP passwords, HTTP tokens) are
 * sealed with AES-256-GCM before they touch the database and are never
 * returned by any read path. The wrapping key comes from the
 * CHS_CREDENTIALS_KEY environment variable (hex or passphrase) — it is never
 * stored in the database, so a database leak alone cannot unseal credentials.
 *
 * When no key is configured the vault refuses to seal, and provider
 * configuration fails closed with a ConfigurationException instead of
 * persisting plaintext.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Secrets
{
    private const CIPHER = 'aes-256-gcm';
    private const ENV_KEY = 'CHS_CREDENTIALS_KEY';

    /** @var string|null test override for the wrapping key */
    private static $overrideKey;

    /** True when a wrapping key is available (env or test override). */
    public static function available()
    {
        return self::key() !== null;
    }

    /**
     * Seal a plaintext credential. @return string base64 envelope
     * @throws ConfigurationException when no wrapping key is configured
     */
    public static function encrypt($plaintext)
    {
        $key = self::key();
        if ($key === null) {
            throw new ConfigurationException(
                'Provider credentials cannot be stored: set the ' . self::ENV_KEY
                . ' environment variable (any passphrase or 64-char hex) and retry.'
            );
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            (string) $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new ConfigurationException('Credential sealing failed.');
        }
        return base64_encode(json_encode([
            'v'   => 1,
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct'  => base64_encode($cipher),
        ]));
    }

    /**
     * Open a sealed credential. @return string|null null when the envelope is
     * absent or cannot be opened with the configured key
     */
    public static function decrypt($envelope)
    {
        if ($envelope === null || $envelope === '') {
            return null;
        }
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $data = json_decode((string) base64_decode((string) $envelope, true), true);
        if (!is_array($data) || empty($data['iv']) || empty($data['tag']) || empty($data['ct'])) {
            return null;
        }
        $plain = openssl_decrypt(
            base64_decode($data['ct']),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($data['iv']),
            base64_decode($data['tag'])
        );
        return $plain === false ? null : $plain;
    }

    /** Test seam: pin a wrapping key for the current process. */
    public static function setKeyOverride($key)
    {
        self::$overrideKey = $key === null ? null : (string) $key;
    }

    /** @return string|null 32-byte binary key */
    private static function key()
    {
        $raw = self::$overrideKey !== null ? self::$overrideKey : getenv(self::ENV_KEY);
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        $raw = (string) $raw;
        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
            return hex2bin($raw);
        }
        return hash('sha256', $raw, true);
    }
}
