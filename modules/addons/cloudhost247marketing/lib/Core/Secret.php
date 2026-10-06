<?php
/**
 * At-rest obfuscation for transport credentials stored in the module settings
 * table.
 *
 * This is NOT a substitute for secrets management: the key is derived from the
 * WHMCS encryption hash (cc_encryption_hash) when available, so a stolen
 * database dump alone does not hand over the SMTP password, but an attacker
 * with both the database and configuration.php can still recover it. The
 * settings page says exactly that, and recommends the CH247M_* environment
 * variables for production.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Core;

class Secret
{
    private const PREFIX = 'sealed:v1:';

    /** @var string|null test seam */
    private static $keyOverride;

    public static function setKey($key)
    {
        self::$keyOverride = $key === null ? null : (string) $key;
    }

    public static function seal($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        $iv = random_bytes(16);
        if (function_exists('openssl_encrypt')) {
            $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($cipher !== false) {
                $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
                return self::PREFIX . base64_encode($iv . $mac . $cipher);
            }
        }
        // Keystream fallback keeps the module functional on builds without
        // openssl; still authenticated, still keyed.
        $cipher = self::xor($plain, $key, $iv);
        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
        return self::PREFIX . base64_encode($iv . $mac . $cipher);
    }

    public static function open($sealed)
    {
        $sealed = (string) $sealed;
        if ($sealed === '') {
            return '';
        }
        if (strpos($sealed, self::PREFIX) !== 0) {
            return $sealed; // legacy/plaintext value — return as-is
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 48) {
            return '';
        }
        $key = self::key();
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);
        if (!hash_equals($mac, hash_hmac('sha256', $iv . $cipher, $key, true))) {
            return '';
        }
        if (function_exists('openssl_decrypt')) {
            $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($plain !== false) {
                return $plain;
            }
        }
        return self::xor($cipher, $key, $iv);
    }

    public static function isSealed($value)
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    protected static function key()
    {
        if (self::$keyOverride !== null) {
            return hash('sha256', 'ch247m|' . self::$keyOverride, true);
        }
        $material = '';
        if (isset($GLOBALS['cc_encryption_hash']) && is_string($GLOBALS['cc_encryption_hash'])) {
            $material = $GLOBALS['cc_encryption_hash'];
        } elseif (defined('CH247M_ROOT') && is_file(CH247M_ROOT . '/configuration.php')) {
            // Read without executing: configuration.php defines $cc_encryption_hash.
            $text = (string) @file_get_contents(CH247M_ROOT . '/configuration.php');
            if (preg_match('/\$cc_encryption_hash\s*=\s*[\'"]([^\'"]+)/', $text, $m) === 1) {
                $material = $m[1];
            }
        }
        if ($material === '') {
            $material = 'ch247m-fallback-key';
        }
        return hash('sha256', 'ch247m|' . $material, true);
    }

    protected static function xor($data, $key, $iv)
    {
        $out = '';
        $block = '';
        $counter = 0;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            if ($i % 32 === 0) {
                $block = hash('sha256', $key . $iv . $counter, true);
                $counter++;
            }
            $out .= $data[$i] ^ $block[$i % 32];
        }
        return $out;
    }
}
