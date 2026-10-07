<?php
/**
 * Redaction filter — applied to EVERY tool argument, result and audit context
 * before storage. Secrets never enter AI tables; PII is masked unless the
 * operator explicitly enabled exposure for a task that needs it.
 */

namespace Ch247Mkt\Core;

class Redaction
{
    /** Keys whose values are always replaced, whatever they contain. */
    const SENSITIVE_KEYS = ['password', 'password2', 'passwd', 'api_key', 'apikey', 'token', 'secret', 'access_token', 'refresh_token', 'cardnum', 'cardnumber', 'ccv', 'cvv', 'authdata', 'gateway_password', 'license_key'];

    public static function clean($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = self::clean($item);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::cleanString($value);
        }
        return $value;
    }

    public static function cleanString($value)
    {
        // key=value / key: value secret assignments in free text.
        $value = preg_replace('/(?i)\b(password|passwd|secret|api[_-]?key|access[_-]?token|auth)\s*[:=]\s*[^\s,;]+/', '$1=[redacted]', $value);
        // Bearer / basic credentials and long opaque tokens.
        $value = preg_replace('/(?i)(bearer|basic)\s+[a-z0-9._~+\-\/=]{8,}/', '$1 [redacted]', $value);
        // Card-like numbers (13-19 digits, optional separators).
        $value = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[redacted-card]', $value);
        // Emails: masked unless PII exposure is enabled.
        if (!Settings::bool('expose_pii', false)) {
            $value = preg_replace_callback('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', function ($m) {
                return $m[1] . '***@' . $m[2];
            }, $value);
            // Phone-like digit runs — but keep ISO dates, times and IPs intact.
            $value = preg_replace_callback('/\b\d(?:[\s().-]*\d){8,}\b/', function ($m) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[0])) {
                    return $m[0];
                }
                if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $m[0])) {
                    return $m[0];
                }
                return '[redacted-number]';
            }, $value);
        }
        return $value;
    }

    /** SHA-256 digest used for tool-result references. */
    public static function digest($value)
    {
        return hash('sha256', is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
