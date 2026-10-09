<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers;

/**
 * Server-side proxy for the Smtphosting mail usage and sent-log lookups.
 *
 * Security model (S-6 remediation):
 *  - The browser never receives the upstream shared secrets. They are read
 *    server-side only (see loadSecrets()).
 *  - The caller must be an authenticated WHMCS client (non-zero client ID).
 *  - The caller must own the requested service. user_name and main_domain are
 *    taken from that service record, never from the request.
 *  - Only the two fixed upstream endpoints can be reached, over HTTPS, without
 *    following redirects.
 *  - Upstream responses are redacted for the secrets and validated as JSON
 *    before being returned. Upstream error bodies are never echoed.
 *  - Requests are rate-limited per client and fail closed if the limiter
 *    cannot persist its state.
 *
 * The class has no WHMCS dependency. The WHMCS-backed service lookup, the
 * HTTP transport, and the rate limiter are injected, which keeps it testable.
 */
class SmtpUsageProxy
{
    const ENDPOINTS = [
        'usage' => 'https://my.smtphosting.com/smtp/mail-usage-log.php',
        'logs'  => 'https://my.smtphosting.com/smtp/mail-sent-log.php',
    ];

    const DEFAULT_PER_PAGE = 10;
    const MAX_PER_PAGE     = 100;
    const RATE_LIMIT       = 100;
    const RATE_WINDOW      = 300;

    const ENV_USAGE_SECRET = 'SMTPHOSTING_USAGE_SECRET';
    const ENV_LOGS_SECRET  = 'SMTPHOSTING_LOGS_SECRET';

    /** @var array ['usage' => string, 'logs' => string] */
    private $secrets;

    /** @var callable function (int $serviceId, int $clientId): ?array with 'username' and 'domain' */
    private $serviceLookup;

    /** @var callable function (string $url, array $query): array with 'status' (int) and 'body' (string) */
    private $transport;

    /** @var object with allow($key, $limit, $window, $now = null): bool */
    private $rateLimiter;

    /**
     * @param array    $secrets       ['usage' => string, 'logs' => string]
     * @param callable $serviceLookup returns the owned service's identity, or null when not owned
     * @param callable $transport     performs the upstream GET request
     * @param object   $rateLimiter   per-client limiter
     */
    public function __construct(array $secrets, $serviceLookup, $transport, $rateLimiter)
    {
        $this->secrets       = $secrets;
        $this->serviceLookup = $serviceLookup;
        $this->transport     = $transport;
        $this->rateLimiter   = $rateLimiter;
    }

    /**
     * Handle one request.
     *
     * @param array     $request  the GET parameters (only fn, serviceid, page, per_page are read)
     * @param int|null  $clientId authenticated WHMCS client ID, or null/0 when not logged in
     * @return array ['httpStatus' => int, 'payload' => array]
     */
    public function handle(array $request, $clientId)
    {
        $fn = isset($request['fn']) && is_string($request['fn']) ? $request['fn'] : '';
        if (!isset(self::ENDPOINTS[$fn])) {
            return $this->error(400, 'Invalid request.');
        }

        $clientId = is_numeric($clientId) ? (int) $clientId : 0;
        if ($clientId <= 0) {
            return $this->error(401, 'Authentication required.');
        }

        $serviceId = $this->positiveInt(isset($request['serviceid']) ? $request['serviceid'] : null);
        if ($serviceId === null) {
            return $this->error(400, 'Invalid request.');
        }

        try {
            if (!$this->rateLimiter->allow('client:' . $clientId, self::RATE_LIMIT, self::RATE_WINDOW)) {
                return $this->error(429, 'Too many requests. Please try again later.');
            }
        } catch (\RuntimeException $e) {
            return $this->error(503, 'Service temporarily unavailable.');
        }

        $service = call_user_func($this->serviceLookup, $serviceId, $clientId);
        if (!is_array($service)) {
            return $this->error(404, 'Service not found.');
        }
        $username = isset($service['username']) ? trim((string) $service['username']) : '';
        $domain   = isset($service['domain']) ? trim((string) $service['domain']) : '';
        if ($username === '' || $domain === '') {
            return $this->error(404, 'No mail usage data is available for this service.');
        }

        $secret = isset($this->secrets[$fn]) ? (string) $this->secrets[$fn] : '';
        if ($secret === '') {
            return $this->error(503, 'Mail usage service is not configured.');
        }

        $query = [
            'secret'      => $secret,
            'user_name'   => $username,
            'main_domain' => $domain,
        ];
        if ($fn === 'logs') {
            $query['page']     = $this->page($request);
            $query['per_page'] = $this->perPage($request);
        }

        try {
            $result = call_user_func($this->transport, self::ENDPOINTS[$fn], $query);
        } catch (\RuntimeException $e) {
            return $this->error(502, 'Mail service is temporarily unavailable.');
        }

        $status = isset($result['status']) ? (int) $result['status'] : 0;
        $body   = isset($result['body']) ? (string) $result['body'] : '';
        if ($status !== 200) {
            return $this->error(502, 'Mail service is temporarily unavailable.');
        }

        // Defence in depth: never let the upstream echo a secret back to the browser.
        $body    = str_replace($secret, '[redacted]', $body);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $this->error(502, 'Unexpected response from the mail service.');
        }

        return ['httpStatus' => 200, 'payload' => $decoded];
    }

    /**
     * Read the upstream secrets from the environment, falling back to a
     * configuration file that returns ['usage' => ..., 'logs' => ...].
     * Missing values are returned as '' so that handle() fails closed with 503.
     *
     * @param string $configFile absolute path to the untracked secrets file
     * @return array ['usage' => string, 'logs' => string]
     */
    public static function loadSecrets($configFile)
    {
        $secrets = [
            'usage' => (string) getenv(self::ENV_USAGE_SECRET),
            'logs'  => (string) getenv(self::ENV_LOGS_SECRET),
        ];

        if (($secrets['usage'] === '' || $secrets['logs'] === '') && is_file($configFile)) {
            $fromFile = include $configFile;
            if (is_array($fromFile)) {
                foreach (['usage', 'logs'] as $key) {
                    if ($secrets[$key] === '' && isset($fromFile[$key]) && is_string($fromFile[$key])) {
                        $secrets[$key] = $fromFile[$key];
                    }
                }
            }
        }

        return $secrets;
    }

    /**
     * Production HTTP transport: HTTPS only, TLS verified, no redirects, short timeouts.
     *
     * @return array ['status' => int, 'body' => string]
     * @throws \RuntimeException when cURL is unavailable or the request fails
     */
    public static function curlTransport($url, array $query)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is not available.');
        }

        $ch = curl_init($url . '?' . http_build_query($query));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new \RuntimeException('Upstream request failed.');
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    private function error($httpStatus, $message)
    {
        return [
            'httpStatus' => $httpStatus,
            'payload'    => ['status' => 'error', 'msg' => $message],
        ];
    }

    private function positiveInt($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value) && strlen($value) <= 18) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }

    private function page(array $request)
    {
        $page = filter_var(isset($request['page']) ? $request['page'] : null, FILTER_VALIDATE_INT);
        return ($page === false || $page < 1) ? 1 : $page;
    }

    private function perPage(array $request)
    {
        $perPage = filter_var(isset($request['per_page']) ? $request['per_page'] : null, FILTER_VALIDATE_INT);
        if ($perPage === false || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }
        return min($perPage, self::MAX_PER_PAGE);
    }
}
