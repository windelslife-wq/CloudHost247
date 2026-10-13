<?php
/**
 * Configuration Manager
 * Handles all module settings with caching.
 *
 * Sensitive values (provider credentials, tokens, secrets) are encrypted at
 * rest with WHMCS's own encrypt()/decrypt() and stored under an "enc:v1:"
 * prefix (finding P-6). Legacy plaintext rows written before this version
 * remain readable and are re-encrypted the next time they are saved.
 */

namespace PhoneServices\Core;

class Config
{
    private static $cache = [];

    const ENCRYPT_PREFIX = 'enc:v1:';

    /**
     * Settings stored encrypted at rest.
     */
    public static function sensitiveKeys(): array
    {
        return [
            'twilio_account_sid', 'twilio_auth_token',
            'vonage_api_key', 'vonage_api_secret', 'vonage_signature_secret',
            'airalo_api_token', 'truphone_api_key',
            'sendgrid_api_key', 'whatsapp_business_token',
            'jwt_secret', 'api_key',
        ];
    }

    public static function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::sensitiveKeys(), true);
    }

    /**
     * Encrypt a sensitive value for storage. Outside a WHMCS context (offline
     * tests) the value is returned unchanged because encrypt() is unavailable.
     */
    public static function encryptSecret($value): string
    {
        $value = (string) $value;
        if ($value === '' || !function_exists('encrypt')) {
            return $value;
        }
        return self::ENCRYPT_PREFIX . base64_encode((string) encrypt($value));
    }

    /**
     * Decrypt a stored value if it carries the enc:v1: prefix. Legacy
     * plaintext values (or failed decodes) are returned as stored.
     */
    public static function decryptValue($stored): string
    {
        $stored = (string) $stored;
        if (strpos($stored, self::ENCRYPT_PREFIX) === 0 && function_exists('decrypt')) {
            $plain = decrypt(base64_decode(substr($stored, strlen(self::ENCRYPT_PREFIX))));
            if (is_string($plain) && $plain !== '') {
                return $plain;
            }
        }
        return $stored;
    }

    /**
     * Get a configuration value
     */
    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $raw = self::fetchRaw($key);
        if ($raw === null) {
            return $default;
        }

        $value = self::decryptValue($raw);
        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Read the raw stored value for a key.
     * mod_phoneservices_settings is primary (Config::set writes there);
     * tbladdonmodules remains as a fallback for values saved through the
     * standard WHMCS module-settings page.
     */
    private static function fetchRaw($key)
    {
        $settingsRow = \WHMCS\Database\Capsule::table('mod_phoneservices_settings')
            ->where('setting_name', $key)
            ->first(['setting_value']);
        if ($settingsRow !== null) {
            return (string) $settingsRow->setting_value;
        }

        $addonRow = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'phoneservices')
            ->where('setting', $key)
            ->first(['value']);
        if ($addonRow !== null) {
            return (string) $addonRow->value;
        }

        return null;
    }

    /**
     * Set a configuration value (encrypts sensitive keys at rest)
     */
    public static function set($key, $value)
    {
        $stored = self::isSensitiveKey((string) $key)
            ? self::encryptSecret($value)
            : (string) $value;
        self::$cache[$key] = self::decryptValue($stored);

        $exists = \WHMCS\Database\Capsule::table('mod_phoneservices_settings')
            ->where('setting_name', $key)
            ->exists();
        if ($exists) {
            \WHMCS\Database\Capsule::table('mod_phoneservices_settings')
                ->where('setting_name', $key)
                ->update(['setting_value' => $stored]);
        } else {
            \WHMCS\Database\Capsule::table('mod_phoneservices_settings')
                ->insert(['setting_name' => $key, 'setting_value' => $stored]);
        }
    }

    /**
     * Get all feature toggles
     */
    public static function getFeatureToggles()
    {
        return [
            'numbers' => self::get('enable_numbers', '1') === '1',
            'voip'    => self::get('enable_voip', '1') === '1',
            'sms'     => self::get('enable_sms', '1') === '1',
            'esim'    => self::get('enable_esim', '1') === '1',
        ];
    }
    
    /**
     * Check if a service is enabled
     */
    public static function isServiceEnabled($service)
    {
        $toggles = self::getFeatureToggles();
        return isset($toggles[$service]) ? $toggles[$service] : false;
    }
    
    /**
     * Get provider credentials (decrypted transparently)
     */
    public static function getProviderCredentials($provider)
    {
        $map = [
            'twilio' => ['account_sid' => 'twilio_account_sid', 'auth_token' => 'twilio_auth_token'],
            'vonage' => ['api_key' => 'vonage_api_key', 'api_secret' => 'vonage_api_secret'],
            'airalo' => ['api_token' => 'airalo_api_token'],
            'truphone' => ['api_key' => 'truphone_api_key'],
        ];
        
        if (!isset($map[$provider])) {
            return [];
        }
        
        $credentials = [];
        foreach ($map[$provider] as $key => $configKey) {
            $credentials[$key] = self::get($configKey, '');
        }
        
        return $credentials;
    }
    
    /**
     * Get SendGrid credentials
     */
    public static function getSendgridCredentials()
    {
        return ['api_key' => self::get('sendgrid_api_key', '')];
    }
    
    /**
     * Get WhatsApp credentials
     */
    public static function getWhatsappCredentials()
    {
        return ['token' => self::get('whatsapp_business_token', '')];
    }
    
    /**
     * Clear cache
     */
    public static function clearCache()
    {
        self::$cache = [];
    }
}
