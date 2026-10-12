<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Server-side IP geolocation against a URL an administrator has configured.
 *
 * Deliberately strict, because this is the one detection step that leaves the
 * server:
 *
 *  - it runs only when an endpoint is configured (nothing is dialled by
 *    default, and no provider is hard-coded);
 *  - HTTPS is required unless an administrator explicitly allows plain HTTP;
 *  - the host must appear in `allowed_hosts`, so a compromised settings row
 *    cannot turn this into a request-tunnelling hole;
 *  - a short timeout (default 2s) means a slow provider can never slow a page;
 *  - any failure — DNS, TLS, timeout, non-200, malformed JSON, an unexpected
 *    shape — returns null. Detection then falls back to the default currency.
 *
 * The response is parsed for one field, defaulting to `country_code`, and the
 * value must be a real two-letter ISO 3166-1 code before it is believed.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class HttpGeoLocator implements GeoLocatorInterface
{
    /** @var string URL template containing "{ip}". */
    private $endpoint;

    /** @var string Dot path to the country field, e.g. "country_code". */
    private $field;

    /** @var int Seconds. */
    private $timeout;

    /** @var string[] */
    private $allowedHosts;

    /** @var bool */
    private $allowInsecure;

    /** @var callable|null fn(string $url, int $timeout): string|null */
    private $fetcher;

    /** @var callable|null */
    private $logger;

    /** @var int */
    private $calls = 0;

    /**
     * @param array         $config  Keys: endpoint, field, timeout,
     *                               allowed_hosts, allow_http.
     * @param callable|null $fetcher Injectable transport (tests).
     * @param callable|null $logger
     */
    public function __construct(array $config, $fetcher = null, $logger = null)
    {
        $this->endpoint = isset($config['endpoint']) ? trim((string) $config['endpoint']) : '';
        $this->field = isset($config['field']) && (string) $config['field'] !== ''
            ? (string) $config['field']
            : 'country_code';
        $this->timeout = isset($config['timeout']) && (int) $config['timeout'] > 0
            ? min(10, (int) $config['timeout'])
            : 2;
        $this->allowedHosts = array();
        foreach (isset($config['allowed_hosts']) && is_array($config['allowed_hosts'])
            ? $config['allowed_hosts'] : array() as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $this->allowedHosts[] = $host;
            }
        }
        $this->allowInsecure = !empty($config['allow_http']);
        $this->fetcher = is_callable($fetcher) ? $fetcher : null;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Whether this locator has anything to ask.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && strpos($this->endpoint, '{ip}') !== false;
    }

    /**
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = str_replace('{ip}', rawurlencode($ip), $this->endpoint);
        if (!$this->isAllowedUrl($url)) {
            $this->log('endpoint rejected by the host allowlist');

            return null;
        }

        $this->calls++;
        try {
            $body = $this->fetch($url);
        } catch (\Throwable $e) {
            $this->log('request failed: ' . $e->getMessage());

            return null;
        }

        if ($body === null || trim($body) === '') {
            $this->log('empty response');

            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->log('response was not JSON');

            return null;
        }

        $value = $this->extract($data, $this->field);

        return GeoLocation::fromValue($value, $this->sourceName(), 0.7);
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        $host = $this->endpoint !== '' ? (string) parse_url($this->endpoint, PHP_URL_HOST) : '';

        return $host !== '' ? 'HTTP geolocation (' . $host . ')' : 'HTTP geolocation';
    }

    /**
     * How many outbound requests were attempted (tests assert caching).
     *
     * @return int
     */
    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowInsecure)) {
            return false;
        }

        // No allowlist configured means "nothing is allowed": the endpoint
        // must be pinned by the administrator who enabled it.
        if (!$this->allowedHosts) {
            return false;
        }

        return in_array(strtolower($parts['host']), $this->allowedHosts, true);
    }

    /**
     * @param string $url
     *
     * @return string|null
     */
    private function fetch(string $url)
    {
        if ($this->fetcher !== null) {
            $body = call_user_func($this->fetcher, $url, $this->timeout);

            return $body === null ? null : (string) $body;
        }

        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($url);
        }

        $context = stream_context_create(array(
            'http' => array('method' => 'GET', 'timeout' => $this->timeout, 'ignore_errors' => true),
            'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }

    /**
     * @param string $url
     *
     * @return string|null
     */
    private function fetchWithCurl(string $url)
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'CloudHost247/1.0 (+https://www.cloudhost247.com)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $status >= 400) {
            return null;
        }

        return (string) $body;
    }

    /**
     * Read a dot path out of a decoded response.
     *
     * @param array  $data
     * @param string $path
     *
     * @return mixed
     */
    private function extract(array $data, string $path)
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? $current : null;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
