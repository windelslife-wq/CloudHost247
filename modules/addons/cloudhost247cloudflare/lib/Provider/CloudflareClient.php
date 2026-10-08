<?php
namespace CloudHost247\Cloudflare\Provider;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\CloudflareException;

/** Centralized Cloudflare v4 API client with safe logging, bounded retries and a persistent circuit breaker. */
class CloudflareClient
{
    private $baseUrl;
    private $token;
    private $accountPk;
    private $context;
    private $transport;
    private $timeout;

    public function __construct($baseUrl, $token, $accountPk = 0, array $context = [], TransportInterface $transport = null, $timeout = 20)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->token = (string) $token;
        $this->accountPk = (int) $accountPk;
        $this->context = $context;
        $this->transport = $transport ?: new CurlTransport();
        $this->timeout = max(5, min(60, (int) $timeout));
        $this->validateBaseUrl($this->baseUrl);
        if ($this->token === '') throw new ConfigurationException('Cloudflare API token is missing.');
    }

    /**
     * Calls one API endpoint. Query parameters are encoded separately, and
     * request/response bodies are never written to logs.
     */
    public function call($method, $path, array $query = [], array $body = null, $operation = 'api.request')
    {
        $method = strtoupper((string) $method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) throw new \InvalidArgumentException('Unsupported Cloudflare HTTP method.');
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, '..') !== false || strpos($path, '?') !== false || strpos($path, '#') !== false) throw new \InvalidArgumentException('Invalid Cloudflare API path.');
        $this->assertCircuitClosed();
        $url = $this->baseUrl . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $requestId = self::requestId();
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body !== null && $payload === false) throw new \InvalidArgumentException('Cloudflare request body could not be encoded.');
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'CloudHost247-Cloudflare/1.0',
            'X-CloudHost247-Request-ID' => $requestId,
        ];
        $started = microtime(true); $attempt = 0; $lastError = null;
        $retryable = in_array($method, ['GET', 'HEAD'], true);
        while ($attempt < ($retryable ? 3 : 1)) {
            $attempt++;
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
                $status = (int) ($response['status'] ?? 0);
                $decoded = json_decode((string) ($response['body'] ?? ''), true);
                if ($status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['success'])) {
                    if (in_array($operation, ['zone.list', 'dns.list', 'connection.zone_read'], true)) {
                        $this->assertListPayload((string) ($response['body'] ?? ''));
                    }
                    $this->markSuccess();
                    $this->log($requestId, $operation, $method, $path, $status, (int) round((microtime(true) - $started) * 1000), 'success', null);
                    return $decoded['result'] ?? null;
                }
                $error = $this->normalizeError($status, $decoded, $path, $response['headers'] ?? []);
                if ($retryable && $attempt < 3 && in_array($error->errorCode(), ['CLOUDFLARE_RATE_LIMITED', 'CLOUDFLARE_API_ERROR', 'CLOUDFLARE_TIMEOUT', 'SERVICE_UNAVAILABLE'], true) && ($status === 429 || $status >= 500)) {
                    $lastError = $error;
                    $delay = $error->retryAfter() ? min(3, max(1, $error->retryAfter())) : min(2, (int) pow(2, $attempt - 1));
                    usleep($delay * 100000);
                    continue;
                }
                throw $error;
            } catch (CloudflareException $e) {
                $this->markFailure($e);
                $this->log($requestId, $operation, $method, $path, $e->httpStatus(), (int) round((microtime(true) - $started) * 1000), 'error', $e->errorCode());
                throw $e;
            } catch (\Throwable $e) {
                $code = stripos($e->getMessage(), 'timed out') !== false ? 'CLOUDFLARE_TIMEOUT' : 'SERVICE_UNAVAILABLE';
                $safe = $code === 'CLOUDFLARE_TIMEOUT' ? 'Cloudflare did not respond before the request timed out.' : 'Cloudflare is temporarily unavailable.';
                $lastError = new CloudflareException($code, $safe, 0, null, $e);
                if ($retryable && $attempt < 3) { usleep(min(2, (int) pow(2, $attempt - 1)) * 100000); continue; }
                $this->markFailure($lastError);
                $this->log($requestId, $operation, $method, $path, 0, (int) round((microtime(true) - $started) * 1000), 'error', $code);
                throw $lastError;
            }
        }
        if ($lastError instanceof CloudflareException) throw $lastError;
        throw new CloudflareException('SERVICE_UNAVAILABLE', 'Cloudflare is temporarily unavailable.');
    }

    /** Preserve the distinction between a JSON list and an empty JSON object. */
    private function assertListPayload($body)
    {
        $payload = json_decode((string) $body);
        if (!is_object($payload) || !property_exists($payload, 'result') || !is_array($payload->result)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid list response.');
        }
        foreach ($payload->result as $item) {
            if (!is_object($item)) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid list response.');
            }
        }
    }

    private function normalizeError($status, $payload, $path, array $headers)
    {
        $retry = isset($headers['retry-after']) && is_numeric($headers['retry-after']) ? (int) $headers['retry-after'] : null;
        $code = 'CLOUDFLARE_API_ERROR'; $message = 'Cloudflare rejected the request. Check API permissions and the requested feature.';
        if ((int) $status === 401) { $code = 'CLOUDFLARE_AUTH_FAILED'; $message = 'Cloudflare authentication failed. Verify the API token.'; }
        elseif ((int) $status === 403) { $code = 'CLOUDFLARE_PERMISSION_DENIED'; $message = 'The Cloudflare API token does not have permission for this operation.'; }
        elseif ((int) $status === 404) { $code = strpos($path, '/dns_records/') !== false ? 'CLOUDFLARE_RECORD_NOT_FOUND' : 'CLOUDFLARE_ZONE_NOT_FOUND'; $message = $code === 'CLOUDFLARE_RECORD_NOT_FOUND' ? 'The DNS record no longer exists at Cloudflare.' : 'The Cloudflare resource was not found.'; }
        elseif ((int) $status === 429) { $code = 'CLOUDFLARE_RATE_LIMITED'; $message = 'Cloudflare rate limit reached. Retry after a short wait.'; $retry = $retry ?: 60; }
        elseif ((int) $status >= 500 || (int) $status === 0) { $code = 'SERVICE_UNAVAILABLE'; $message = 'Cloudflare is temporarily unavailable.'; }
        elseif (is_array($payload) && isset($payload['errors'][0]['code'])) {
            // The numeric provider code is safe for diagnostics; provider messages may echo request data.
            $code = 'CLOUDFLARE_API_ERROR';
        }
        return new CloudflareException($code, $message, (int) $status, $retry);
    }

    private function assertCircuitClosed()
    {
        if ($this->accountPk <= 0 || !Db::tableExists('accounts')) return;
        $account = Db::first('accounts', ['id' => $this->accountPk]);
        if (!$account) return;
        if (!empty($account['rate_limit_until']) && strtotime($account['rate_limit_until']) > time()) {
            $retry = max(1, strtotime($account['rate_limit_until']) - time());
            throw new CloudflareException('CLOUDFLARE_RATE_LIMITED', 'Cloudflare rate limit reached. Retry after a short wait.', 429, $retry);
        }
        if (!empty($account['circuit_open_until']) && strtotime($account['circuit_open_until']) > time()) {
            throw new CloudflareException('SERVICE_UNAVAILABLE', 'Cloudflare is temporarily unavailable. Retry after the connection circuit resets.', 503, strtotime($account['circuit_open_until']) - time());
        }
    }

    private function markSuccess()
    {
        if ($this->accountPk <= 0 || !Db::tableExists('accounts')) return;
        try { Db::update('accounts', ['id' => $this->accountPk], ['consecutive_failures' => 0, 'circuit_open_until' => null, 'rate_limit_until' => null, 'last_success_at' => Clock::now()]); } catch (\Throwable $e) {}
    }
    private function markFailure(CloudflareException $e)
    {
        if ($this->accountPk <= 0 || !Db::tableExists('accounts')) return;
        try {
            $row = Db::first('accounts', ['id' => $this->accountPk]); if (!$row) return;
            $failures = (int) ($row['consecutive_failures'] ?? 0) + 1;
            $set = ['consecutive_failures' => $failures];
            if ($e->errorCode() === 'CLOUDFLARE_RATE_LIMITED') $set['rate_limit_until'] = Clock::after(max(10, min(3600, (int) ($e->retryAfter() ?: 60))));
            elseif ($failures >= 5 && in_array($e->errorCode(), ['SERVICE_UNAVAILABLE', 'CLOUDFLARE_TIMEOUT'], true)) $set['circuit_open_until'] = Clock::after(30);
            Db::update('accounts', ['id' => $this->accountPk], $set);
        } catch (\Throwable $ignored) {}
    }
    private function log($requestId, $operation, $method, $path, $status, $duration, $outcome, $errorCode)
    {
        try {
            if (!Db::tableExists('api_logs')) return;
            Db::insert('api_logs', [
                'request_id' => $requestId, 'account_id' => $this->accountPk ?: null,
                'service_id' => isset($this->context['service_id']) ? (int) $this->context['service_id'] : null,
                'customer_id' => isset($this->context['customer_id']) ? (int) $this->context['customer_id'] : null,
                'operation' => substr((string) $operation, 0, 80), 'method' => substr((string) $method, 0, 8),
                'path' => substr((string) $path, 0, 255), 'http_status' => (int) $status, 'duration_ms' => max(0, (int) $duration),
                'outcome' => (string) $outcome, 'error_code' => $errorCode ? substr((string) $errorCode, 0, 80) : null, 'created_at' => Clock::now(),
            ]);
        } catch (\Throwable $e) {}
    }
    private function validateBaseUrl($url)
    {
        $parts = parse_url($url);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'api.cloudflare.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('Cloudflare API endpoint must be the official HTTPS api.cloudflare.com host.');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '/client/v4') throw new ConfigurationException('Cloudflare API endpoint must end in /client/v4.');
    }
    private static function requestId()
    {
        try { return bin2hex(random_bytes(16)); } catch (\Throwable $e) { return str_replace('.', '', uniqid('cf', true)); }
    }
}
