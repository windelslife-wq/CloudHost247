<?php
namespace DigitalProducts\Core;

class Crypto
{
    protected static function key()
    {
        $configured = getenv('DIGITALPRODUCTS_ENCRYPTION_KEY');
        if ($configured !== false && $configured !== '') return hash('sha256', $configured, true);
        // WHMCS deployments should set DIGITALPRODUCTS_ENCRYPTION_KEY. The
        // fallback is installation-specific and only protects against casual
        // database disclosure; it is never emitted to clients.
        $material = defined('ROOTDIR') ? ROOTDIR : __DIR__;
        return hash('sha256', $material . '|' . __FILE__, true);
    }

    public static function encrypt($plaintext)
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt((string) $plaintext, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new DigitalProductsException('Unable to protect secret.');
        return base64_encode($iv . $cipher);
    }

    public static function decrypt($encoded)
    {
        $raw = base64_decode((string) $encoded, true);
        if ($raw === false || strlen($raw) < 17) return null;
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain === false ? null : $plain;
    }

    public static function randomToken($bytes = 32) { return bin2hex(random_bytes((int) $bytes)); }
}
