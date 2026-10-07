<?php
namespace CloudHost247\Cloudflare\Core;

/** Authenticated encryption for provider credentials; never falls back to plaintext. */
class Crypto
{
    const PREFIX = 'cfenc:v1:';
    private static $testKey;

    public static function setKeyForTests($key) { self::$testKey = $key === null ? null : (string) $key; }

    public static function hasKey()
    {
        try { self::key(); return true; } catch (\Throwable $e) { return false; }
    }

    public static function seal($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) throw new ConfigurationException('OpenSSL is required to encrypt Cloudflare credentials.');
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'cloudhost247-cloudflare-v1', 16);
        if ($cipher === false || strlen($tag) !== 16) throw new ConfigurationException('Cloudflare credentials could not be encrypted.');
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    public static function open($sealed)
    {
        $sealed = (string) $sealed;
        if ($sealed === '') return '';
        if (strpos($sealed, self::PREFIX) !== 0) throw new ConfigurationException('Stored Cloudflare credentials are not encrypted; replace the token in Integration settings.');
        if (!function_exists('openssl_decrypt')) throw new ConfigurationException('OpenSSL is required to decrypt Cloudflare credentials.');
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) throw new ConfigurationException('Stored Cloudflare credentials are unreadable; replace the token in Integration settings.');
        $nonce = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'cloudhost247-cloudflare-v1');
        if ($plain === false) throw new ConfigurationException('Cloudflare credential decryption failed. Check that the WHMCS encryption key has not changed.');
        return $plain;
    }

    private static function key()
    {
        if (self::$testKey !== null && self::$testKey !== '') return hash('sha256', 'ch247cf|' . self::$testKey, true);
        $material = (string) (getenv('CLOUDFLARE_ENCRYPTION_KEY') ?: '');
        if ($material === '' && isset($GLOBALS['cc_encryption_hash']) && is_string($GLOBALS['cc_encryption_hash'])) $material = $GLOBALS['cc_encryption_hash'];
        if ($material === '' && isset($GLOBALS['CONFIG']['cc_encryption_hash'])) $material = (string) $GLOBALS['CONFIG']['cc_encryption_hash'];
        if ($material === '' && defined('CH247CF_ROOT') && is_file(CH247CF_ROOT . '/configuration.php')) {
            $source = (string) @file_get_contents(CH247CF_ROOT . '/configuration.php');
            if (preg_match('/\$cc_encryption_hash\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $m)) $material = $m[1];
        }
        if (strlen($material) < 16) throw new ConfigurationException('Credential encryption is not configured. Set CLOUDFLARE_ENCRYPTION_KEY or verify the WHMCS encryption hash.');
        return hash_hmac('sha256', 'cloudhost247-cloudflare-v1', $material, true);
    }
}
