<?php
/** Strict immutable WebAuthn configuration; all RP/origin input is fail-closed. */

namespace CloudHost247\Passkey\Core;

class WebAuthnConfig
{
    private $rpName;
    private $rpId;
    private $allowedOrigins;
    private $origin;
    private $userVerification;

    private function __construct($rpName, $rpId, array $allowedOrigins, $origin, $userVerification)
    {
        $this->rpName = $rpName;
        $this->rpId = $rpId;
        $this->allowedOrigins = $allowedOrigins;
        $this->origin = $origin;
        $this->userVerification = $userVerification;
    }

    /**
     * $isHttps must come from WHMCS/server TLS state, never X-Forwarded-* headers.
     * $requestOrigin is only accepted when it exactly matches configured origins.
     */
    public static function fromSettings(array $settings, $requestOrigin, $isHttps)
    {
        if (!isset($settings['service_enabled']) || !in_array($settings['service_enabled'], ['1', 1], true)) {
            throw new \RuntimeException('Passkey authentication is disabled.');
        }
        if (!isset($settings['require_https']) || !in_array($settings['require_https'], ['1', 1], true) || $isHttps !== true) {
            throw new \RuntimeException('Passkey requests require verified HTTPS transport.');
        }

        $rpName = isset($settings['rp_name']) ? trim((string) $settings['rp_name']) : '';
        if ($rpName === '' || strlen($rpName) > 64 || preg_match('/[\\x00-\\x1F\\x7F]/', $rpName)) {
            throw new \InvalidArgumentException('Passkey relying-party display name is missing or invalid.');
        }
        $rpId = isset($settings['rp_id']) ? strtolower(trim((string) $settings['rp_id'])) : '';
        self::assertDomain($rpId, 'relying-party ID');

        if (!isset($settings['allowed_origins']) || !is_string($settings['allowed_origins'])) {
            throw new \InvalidArgumentException('An explicit WebAuthn origin allowlist is required.');
        }
        $origins = json_decode($settings['allowed_origins']);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($origins) || !$origins) {
            throw new \InvalidArgumentException('The WebAuthn origin allowlist is missing or malformed.');
        }
        $expectedIndex = 0;
        $validatedOrigins = [];
        foreach ($origins as $key => $origin) {
            if ($key !== $expectedIndex || !is_string($origin)) {
                throw new \InvalidArgumentException('WebAuthn origins must be a JSON list of exact origin strings.');
            }
            $validated = self::validateOrigin($origin, $rpId);
            if (in_array($validated, $validatedOrigins, true)) {
                throw new \InvalidArgumentException('Duplicate WebAuthn origin in the allowlist.');
            }
            $validatedOrigins[] = $validated;
            $expectedIndex++;
        }

        $requestOrigin = is_string($requestOrigin) ? $requestOrigin : '';
        if (!in_array($requestOrigin, $validatedOrigins, true)) {
            throw new \RuntimeException('The request origin is not explicitly allowed for Passkey ceremonies.');
        }
        $userVerification = isset($settings['user_verification']) ? (string) $settings['user_verification'] : '';
        if (!in_array($userVerification, ['required', 'preferred', 'discouraged'], true)) {
            throw new \InvalidArgumentException('Passkey user-verification policy is missing or invalid.');
        }

        return new self($rpName, $rpId, $validatedOrigins, $requestOrigin, $userVerification);
    }

    public function rpName()
    {
        return $this->rpName;
    }

    public function rpId()
    {
        return $this->rpId;
    }

    public function allowedOrigins()
    {
        return $this->allowedOrigins;
    }

    public function origin()
    {
        return $this->origin;
    }

    public function userVerification()
    {
        return $this->userVerification;
    }

    private static function validateOrigin($origin, $rpId)
    {
        if ($origin === '' || strlen($origin) > 512 || strpos($origin, chr(0)) !== false) {
            throw new \InvalidArgumentException('WebAuthn origin is empty or too long.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new \InvalidArgumentException('Only canonical HTTPS origins without paths or credentials are allowed.');
        }
        $host = strtolower($parts['host']);
        self::assertDomain($host, 'origin host');
        if ($host !== $rpId && substr($host, -strlen('.' . $rpId)) !== '.' . $rpId) {
            throw new \InvalidArgumentException('Origin host must be the RP ID or one of its subdomains.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535 || $port === 443)) {
            throw new \InvalidArgumentException('Origin port is invalid or non-canonical.');
        }
        $canonical = 'https://' . $host . ($port === null ? '' : ':' . $port);
        if (!hash_equals($canonical, $origin)) {
            throw new \InvalidArgumentException('WebAuthn origins must use canonical lowercase HTTPS serialization.');
        }
        return $canonical;
    }

    private static function assertDomain($domain, $label)
    {
        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Passkey ' . $label . ' must be a DNS host name.');
        }
        if ($domain === 'localhost') {
            return;
        }
        $labels = explode('.', $domain);
        foreach ($labels as $part) {
            if (strlen($part) < 1 || strlen($part) > 63
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $part)) {
                throw new \InvalidArgumentException('Passkey ' . $label . ' contains an invalid DNS label.');
            }
        }
    }
}
