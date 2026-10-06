<?php
/**
 * Server-side Blockonomics connection test (spec §14).
 *
 * Transport is injectable so tests can simulate every failure class without
 * network. Results are sanitized: only the classification and a safe human
 * string ever leave this class — the API key never echoes into results.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class ConnectionTester
{
    const OK            = 'connected';
    const AUTH_FAILED   = 'auth_failed';
    const INVALID_CFG   = 'invalid_configuration';
    const UNAVAILABLE   = 'provider_unavailable';
    const TIMEOUT       = 'connection_timeout';

    /**
     * Blockonomics balance endpoint — cheapest authenticated read in the
     * provider API (same endpoint family the existing testSetup uses).
     */
    const PROBE_URL = 'https://www.blockonomics.co/api/balance';

    /** @var callable fn(url, headers, timeoutSeconds) => ['code'=>int,'body'=>string,'errno'=>int,'error'=>string] */
    private $transport;

    public function __construct(callable $transport)
    {
        $this->transport = $transport;
    }

    /** Production transport (cURL). */
    public static function curlTransport()
    {
        return function ($url, array $headers, $timeout) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, (int) $timeout));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['code' => $code, 'body' => (string) $body, 'errno' => (int) $errno, 'error' => (string) $error];
        };
    }

    /**
     * @param string $apiKey
     * @return array{category:string,label:string,ok:bool}
     */
    public function test($apiKey)
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey === '') {
            return $this->result(self::INVALID_CFG, 'Invalid configuration: API key is not set.');
        }

        try {
            $res = call_user_func($this->transport, self::PROBE_URL, [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ], 15);
        } catch (\Throwable $e) {
            return $this->result(self::UNAVAILABLE, 'Provider unavailable.');
        }

        if (!empty($res['errno'])) {
            // CURLE_OPERATION_TIMEDOUT = 28
            if ((int) $res['errno'] === 28) {
                return $this->result(self::TIMEOUT, 'Connection timeout.');
            }
            return $this->result(self::UNAVAILABLE, 'Provider unavailable.');
        }

        $code = isset($res['code']) ? (int) $res['code'] : 0;
        if ($code >= 200 && $code < 300) {
            return $this->result(self::OK, 'Connected successfully.');
        }
        if ($code === 401 || $code === 403) {
            return $this->result(self::AUTH_FAILED, 'Authentication failed.');
        }
        if ($code === 0) {
            return $this->result(self::UNAVAILABLE, 'Provider unavailable.');
        }
        return $this->result(self::UNAVAILABLE, 'Provider responded with HTTP ' . $code . '.');
    }

    private function result($category, $label)
    {
        return ['category' => $category, 'label' => $label, 'ok' => $category === self::OK];
    }

    /** Transport factory usable in WHMCS where curl is guaranteed. */
    public static function withCurl()
    {
        return new self(self::curlTransport());
    }
}
