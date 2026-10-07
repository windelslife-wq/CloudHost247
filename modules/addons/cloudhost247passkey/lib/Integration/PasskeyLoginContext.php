<?php
/** Non-secret request context passed to the host-owned WHMCS auth bridge. */

namespace CloudHost247\Passkey\Integration;

class PasskeyLoginContext
{
    private $rememberMe;
    private $requestId;
    private $source;
    private $ipAddress;
    private $userAgent;

    private function __construct($rememberMe, $requestId, $source, $ipAddress, $userAgent)
    {
        $this->rememberMe = $rememberMe;
        $this->requestId = $requestId;
        $this->source = $source;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Build context from request metadata only. Assertion JSON, passwords,
     * cookies, session IDs, and tokens are deliberately rejected/not accepted.
     */
    public static function fromArray(array $input)
    {
        $allowed = ['remember_me', 'request_id', 'source', 'ip_address', 'user_agent'];
        if (array_diff(array_keys($input), $allowed)) {
            throw new \InvalidArgumentException('Passkey login context contains unsupported data.');
        }
        $rememberMe = $input['remember_me'] ?? false;
        if (!in_array($rememberMe, [false, true, 0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException('Passkey remember-me state is invalid.');
        }
        $requestId = isset($input['request_id']) ? (string) $input['request_id'] : '';
        if ($requestId === '' || strlen($requestId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId)) {
            throw new \InvalidArgumentException('Passkey login request ID is missing or invalid.');
        }
        $source = isset($input['source']) ? (string) $input['source'] : '';
        if (!in_array($source, ['client_login', 'admin_login'], true)) {
            throw new \InvalidArgumentException('Passkey login source is invalid.');
        }
        $ipAddress = null;
        if (array_key_exists('ip_address', $input) && $input['ip_address'] !== null && $input['ip_address'] !== '') {
            $ipAddress = (string) $input['ip_address'];
            if (strlen($ipAddress) > 45 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('Passkey login IP address is invalid.');
            }
        }
        $userAgent = null;
        if (array_key_exists('user_agent', $input) && $input['user_agent'] !== null && $input['user_agent'] !== '') {
            $userAgent = (string) $input['user_agent'];
            if (strlen($userAgent) > 512 || preg_match('/[\x00-\x1F\x7F]/', $userAgent)) {
                throw new \InvalidArgumentException('Passkey login user agent is invalid.');
            }
        }
        return new self((bool) $rememberMe, $requestId, $source, $ipAddress, $userAgent);
    }

    public function rememberMe()
    {
        return $this->rememberMe;
    }

    public function requestId()
    {
        return $this->requestId;
    }

    public function source()
    {
        return $this->source;
    }

    public function ipAddress()
    {
        return $this->ipAddress;
    }

    public function userAgent()
    {
        return $this->userAgent;
    }

    /** Only non-secret metadata crosses the callback boundary. */
    public function toArray()
    {
        return [
            'remember_me' => $this->rememberMe,
            'request_id' => $this->requestId,
            'source' => $this->source,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }
}
