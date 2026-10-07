<?php
/** Validated module settings; secrets are ciphertext-only and not exposed by accessors. */

namespace CloudHost247\Passkey\Model;

class SettingsRecord
{
    private $row;

    public function __construct(array $row)
    {
        $key = ModelValidation::text($row['setting_key'] ?? '', 'setting_key', 96);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException('Invalid Passkey setting key.');
        }
        $isSecret = ModelValidation::boolean($row['is_secret'] ?? 0, 'is_secret');
        $value = $row['setting_value'] ?? null;
        $ciphertext = $row['encrypted_value'] ?? null;
        if (self::requiresCiphertext($key) && $isSecret !== 1) {
            throw new \InvalidArgumentException('Secret settings must be explicitly marked for encrypted storage.');
        }
        if ($isSecret === 1) {
            if ($value !== null || !is_string($ciphertext) || strpos($ciphertext, 'ch247pk:v1:') !== 0 || strlen($ciphertext) <= 11) {
                throw new \InvalidArgumentException('Secret settings must be stored only as versioned ciphertext.');
            }
        } elseif ($ciphertext !== null) {
            throw new \InvalidArgumentException('Non-secret settings cannot contain ciphertext.');
        }
        if ($value !== null) {
            $value = ModelValidation::text($value, 'setting_value', 65535, true);
        }
        $this->row = [
            'setting_key' => $key,
            'setting_value' => $value,
            'encrypted_value' => $ciphertext,
            'is_secret' => $isSecret,
            'updated_at' => ModelValidation::timestamp($row['updated_at'] ?? null, 'updated_at'),
        ];
    }

    /** Safe defaults: RP information is intentionally blank and service off. */
    public static function defaults()
    {
        return [
            'service_enabled' => '0',
            'client_policy' => 'optional',
            'admin_policy' => 'optional',
            'password_fallback' => 'allowed',
            'max_credentials_client' => '5',
            'max_credentials_admin' => '5',
            'require_https' => '1',
            'user_verification' => 'preferred',
            'sensitive_action_user_verification' => 'required',
            'rp_name' => 'CloudHost247',
            'rp_id' => '',
            'allowed_origins' => '[]',
            'event_retention_days' => '365',
            'challenge_retention_hours' => '24',
            'entra_enabled' => '0',
            'login_notifications_enabled' => '0',
            'security_event_notifications_enabled' => '0',
            'entra_client_login_enabled' => '0',
            'entra_admin_login_enabled' => '0',
            'entra_tenant_id' => '',
            'entra_client_id' => '',
            'entra_redirect_uri' => '',
            'entra_allowed_domains' => '[]',
            'sensitive_action_policy' => 'optional',
        ];
    }

    public function isSecret()
    {
        return $this->row['is_secret'] === 1;
    }

    public function safeArray()
    {
        return [
            'setting_key' => $this->row['setting_key'],
            'value' => $this->isSecret() ? null : $this->row['setting_value'],
            'configured' => $this->isSecret() ? ($this->row['encrypted_value'] !== null) : ($this->row['setting_value'] !== null),
            'is_secret' => $this->row['is_secret'],
            'updated_at' => $this->row['updated_at'],
        ];
    }

    private static function requiresCiphertext($key)
    {
        $key = strtolower((string) $key);
        if (in_array($key, ['password', 'password_secret', 'password_token', 'password_key'], true)) {
            return true;
        }
        return preg_match('/(?:^|_)(?:secret|(?:access|refresh|id)_?token|(?:api|private|signing|encryption)_?key)(?:_|$)/i', $key) === 1;
    }
}
