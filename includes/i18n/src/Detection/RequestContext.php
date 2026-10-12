<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A snapshot of everything detection is allowed to look at, taken once per
 * request.
 *
 * Detection needs the `Accept-Language` header, the IP address and the
 * cookies. Reading `$_SERVER` / `$_COOKIE` directly from a detector would make
 * it untestable and would let unrelated code mutate the inputs mid-flight, so
 * they are captured here instead. Every value is treated as untrusted.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class RequestContext
{
    /** @var array<string,mixed> */
    private $server;

    /** @var array<string,mixed> */
    private $cookie;

    /** @var ClientIp */
    private $clientIp;

    /** @var string|null */
    private $resolvedIp;

    /** @var bool */
    private $ipResolved = false;

    /** @var bool|null */
    private $trustedProxy;

    /**
     * @param array        $server    Usually $_SERVER.
     * @param array        $cookie    Usually $_COOKIE.
     * @param ClientIp|null $clientIp Trusted-proxy policy; defaults to
     *                                "trust nothing but REMOTE_ADDR".
     */
    public function __construct(array $server, array $cookie = array(), ClientIp $clientIp = null)
    {
        $this->server = $server;
        $this->cookie = $cookie;
        $this->clientIp = $clientIp !== null ? $clientIp : new ClientIp();
    }

    /**
     * Capture the current request.
     *
     * @param array $trustedProxies CIDRs whose forwarded headers may be read.
     *
     * @return self
     */
    public static function fromGlobals(array $trustedProxies = array()): self
    {
        $server = isset($_SERVER) && is_array($_SERVER) ? $_SERVER : array();
        $cookie = isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array();

        return new self($server, $cookie, new ClientIp($trustedProxies));
    }

    /**
     * Build a context for a test or a replayed request without touching
     * superglobals.
     *
     * @param array $overrides Keys such as 'accept_language', 'ip', 'country',
     *                         'cookie', 'https', 'trusted_proxies'.
     *
     * @return self
     */
    public static function fixture(array $overrides): self
    {
        $server = array(
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR'    => isset($overrides['ip']) ? (string) $overrides['ip'] : '127.0.0.1',
            'HTTP_HOST'      => 'www.cloudhost247.test',
        );

        if (isset($overrides['accept_language'])) {
            $server['HTTP_ACCEPT_LANGUAGE'] = (string) $overrides['accept_language'];
        }
        if (isset($overrides['country'])) {
            // The header a CDN sets; only believed when the peer is trusted.
            $server['HTTP_CF_IPCOUNTRY'] = (string) $overrides['country'];
        }
        if (!empty($overrides['https'])) {
            $server['HTTPS'] = 'on';
        }
        foreach (isset($overrides['server']) && is_array($overrides['server']) ? $overrides['server'] : array() as $key => $value) {
            $server[$key] = $value;
        }

        $trusted = isset($overrides['trusted_proxies']) && is_array($overrides['trusted_proxies'])
            ? $overrides['trusted_proxies']
            : array();

        return new self(
            $server,
            isset($overrides['cookie']) && is_array($overrides['cookie']) ? $overrides['cookie'] : array(),
            new ClientIp($trusted)
        );
    }

    /**
     * The raw `Accept-Language` header, or '' when absent.
     *
     * @return string
     */
    public function acceptLanguage(): string
    {
        return (string) $this->header('ACCEPT_LANGUAGE');
    }

    /**
     * Read a request header by its conventional name, case-insensitively.
     *
     * @param string $name e.g. 'CF-IPCountry' or 'ACCEPT_LANGUAGE'.
     *
     * @return string|null
     */
    public function header(string $name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key]) && !is_array($this->server[$key])) {
            return (string) $this->server[$key];
        }
        // FastCGI/PHP-FPM also populates a few headers without the prefix.
        if (isset($this->server[$name]) && !is_array($this->server[$name])) {
            return (string) $this->server[$name];
        }

        return null;
    }

    /**
     * A server value, e.g. `REMOTE_ADDR`.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function server(string $key, $default = null)
    {
        return isset($this->server[$key]) && !is_array($this->server[$key]) ? $this->server[$key] : $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function cookies(): array
    {
        return $this->cookie;
    }

    /**
     * A single cookie value, or null.
     *
     * @param string $name
     *
     * @return string|null
     */
    public function cookie(string $name)
    {
        return isset($this->cookie[$name]) && !is_array($this->cookie[$name])
            ? (string) $this->cookie[$name]
            : null;
    }

    /**
     * @return string|null
     */
    public function clientIp()
    {
        if (!$this->ipResolved) {
            $this->ipResolved = true;
            $this->resolvedIp = $this->clientIp->resolve($this->server);
        }

        return $this->resolvedIp;
    }

    /**
     * Whether the connection arrived from a proxy we are configured to trust.
     *
     * Only in that case may a CDN-supplied country header be believed.
     *
     * @return bool
     */
    public function cameFromTrustedProxy(): bool
    {
        if ($this->trustedProxy === null) {
            $this->trustedProxy = $this->clientIp->cameFromTrustedProxy($this->server);
        }

        return $this->trustedProxy;
    }

    /**
     * @return bool
     */
    public function isSecure(): bool
    {
        $https = $this->server('HTTPS');

        return $https !== null && $https !== '' && strtolower((string) $https) !== 'off';
    }

    /**
     * The URI of the current request, or '/'.
     *
     * @return string
     */
    public function requestUri(): string
    {
        $uri = $this->server('REQUEST_URI');

        return $uri === null || $uri === '' ? '/' : (string) $uri;
    }
}
