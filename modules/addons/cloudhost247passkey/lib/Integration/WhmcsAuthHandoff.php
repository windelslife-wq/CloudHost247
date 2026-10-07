<?php
/** Result of a host-owned WHMCS session/2FA transition. */

namespace CloudHost247\Passkey\Integration;

class WhmcsAuthHandoff
{
    const SESSION_ESTABLISHED = 'session_established';
    const TWO_FACTOR_REQUIRED = 'two_factor_required';

    private $status;

    private function __construct($status)
    {
        if (!in_array($status, [self::SESSION_ESTABLISHED, self::TWO_FACTOR_REQUIRED], true)) {
            throw new \InvalidArgumentException('Unsupported WHMCS Passkey handoff status.');
        }
        $this->status = $status;
    }

    public static function sessionEstablished()
    {
        return new self(self::SESSION_ESTABLISHED);
    }

    public static function twoFactorRequired()
    {
        return new self(self::TWO_FACTOR_REQUIRED);
    }

    /** Convert a host callback result without accepting session tokens or secrets. */
    public static function fromArray(array $result)
    {
        if (!isset($result['status']) || !is_string($result['status'])) {
            throw new \RuntimeException('WHMCS authentication handoff is missing its status.');
        }
        if (count(array_diff(array_keys($result), ['status'])) > 0) {
            throw new \RuntimeException('WHMCS authentication handoff returned unsupported data.');
        }
        return new self($result['status']);
    }

    public function status()
    {
        return $this->status;
    }

    public function sessionEstablishedValue()
    {
        return $this->status === self::SESSION_ESTABLISHED;
    }

    public function requiresTwoFactor()
    {
        return $this->status === self::TWO_FACTOR_REQUIRED;
    }

    /** Browser-safe status only; no WHMCS session ID, token, or 2FA secret is returned. */
    public function toPublicArray()
    {
        return [
            'status' => $this->status,
            'next_step' => $this->requiresTwoFactor() ? 'two_factor' : 'client_area',
        ];
    }
}
