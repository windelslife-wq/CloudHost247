<?php
/** Trusted request metadata for an authenticated Passkey enrollment operation. */

namespace CloudHost247\Passkey\Integration;

class PasskeyRegistrationContext
{
    private $ipAddress;
    private $userAgent;

    private function __construct($ipAddress, $userAgent)
    {
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * The host must source these values from its server-side request context,
     * not from browser fields. No credential, challenge, cookie, or secret is
     * accepted here.
     */
    public static function fromTrustedArray(array $input)
    {
        $allowed = ['ip_address', 'user_agent'];
        if (array_diff(array_keys($input), $allowed)) {
            throw new \InvalidArgumentException('Passkey registration context contains unsupported data.');
        }
        $ipAddress = null;
        if (array_key_exists('ip_address', $input) && $input['ip_address'] !== null && $input['ip_address'] !== '') {
            $ipAddress = (string) $input['ip_address'];
            if (strlen($ipAddress) > 45 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('Passkey registration IP address is invalid.');
            }
        }
        $userAgent = null;
        if (array_key_exists('user_agent', $input) && $input['user_agent'] !== null && $input['user_agent'] !== '') {
            $userAgent = (string) $input['user_agent'];
            if (strlen($userAgent) > 512 || preg_match('/[\x00-\x1F\x7F]/', $userAgent)) {
                throw new \InvalidArgumentException('Passkey registration user agent is invalid.');
            }
        }
        return new self($ipAddress, $userAgent);
    }

    public function ipAddress()
    {
        return $this->ipAddress;
    }

    public function userAgent()
    {
        return $this->userAgent;
    }
}
