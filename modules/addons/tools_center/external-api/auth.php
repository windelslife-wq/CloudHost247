<?php
/**
 * API Authentication Handler
 * Token-based authentication between WHMCS and Tools API.
 *
 * Credentials are accepted from request headers or, for compatibility with
 * existing POST clients, the parsed POST body only. Query strings and cookies
 * are never authentication sources.
 */

class APIAuth {
    private $config;
    private $clientId = 0;

    public function __construct($config) {
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * Validate a POST request. $requestData must be the already-parsed body;
     * callers must not pass query parameters or cookies here.
     */
    public function validateRequest($requestData = []) {
        $this->clientId = 0;
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
            return false;
        }
        if (!is_array($requestData)) {
            return false;
        }

        $token = $this->getToken($requestData);
        if (!is_string($token) || $token === '' || strlen($token) > 4096 || trim($token) !== $token) {
            return false;
        }

        $validToken = isset($this->config['api_token']) && is_string($this->config['api_token'])
            ? $this->config['api_token']
            : '';
        if ($this->isConfiguredSecret($validToken) && hash_equals($validToken, $token)) {
            $this->clientId = $this->getClientIdFromToken($token);
            return true;
        }

        return $this->validateHmac($token);
    }

    /** Return a request token from an auth header or the POST body. */
    private function getToken($requestData) {
        $headerToken = $this->getHeader('X-API-Token');
        if ($headerToken !== null) {
            return $headerToken;
        }

        $authorization = $this->getHeader('Authorization');
        if ($authorization !== null) {
            if (preg_match('/^Bearer[ \t]+([^\s]+)$/iD', $authorization, $matches)) {
                return $matches[1];
            }
            return null;
        }

        // Kept for backwards compatibility with POST-only clients. Do not use
        // $_REQUEST: it combines query strings and cookies with request bodies.
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '') === 'POST'
            && array_key_exists('api_token', $requestData)
            && is_string($requestData['api_token'])) {
            return $requestData['api_token'];
        }
        return null;
    }

    /** Read a header without relying on the casing chosen by the web server. */
    private function getHeader($wantedName) {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (is_string($name) && strcasecmp($name, $wantedName) === 0) {
                        return is_string($value) ? $value : null;
                    }
                }
            }
        }

        $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $wantedName));
        $serverNames = [$serverName];
        if (strcasecmp($wantedName, 'Authorization') === 0) {
            $serverNames[] = 'REDIRECT_HTTP_AUTHORIZATION';
        }
        foreach ($serverNames as $name) {
            if (array_key_exists($name, $_SERVER)) {
                return is_string($_SERVER[$name]) ? $_SERVER[$name] : null;
            }
        }
        return null;
    }

    /** HMAC tokens must be signed, fresh, and single-use. */
    private function validateHmac($token) {
        $secret = isset($this->config['api_secret']) && is_string($this->config['api_secret'])
            ? $this->config['api_secret']
            : '';
        if (!$this->isConfiguredSecret($secret)) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($parts[0]) > 4096 || !preg_match('/^[a-f0-9]{64}$/iD', $parts[1])) {
            return false;
        }
        $payload = base64_decode($parts[0], true);
        if (!is_string($payload) || $payload === '' || strlen($payload) > 2048) {
            return false;
        }
        $claims = json_decode($payload, true);
        if (!is_array($claims)
            || !isset($claims['client_id']) || !is_int($claims['client_id']) || $claims['client_id'] < 0
            || !isset($claims['timestamp']) || !is_int($claims['timestamp'])
            || !isset($claims['nonce']) || !is_string($claims['nonce'])
            || !preg_match('/^[a-f0-9]{32,128}$/iD', $claims['nonce'])) {
            return false;
        }

        $ttl = max(30, min(900, (int) (isset($this->config['hmac_ttl_seconds']) ? $this->config['hmac_ttl_seconds'] : 300)));
        $clockSkew = max(0, min(60, (int) (isset($this->config['hmac_clock_skew_seconds']) ? $this->config['hmac_clock_skew_seconds'] : 30)));
        $now = time();
        if ($claims['timestamp'] < $now - $ttl || $claims['timestamp'] > $now + $clockSkew) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, strtolower($parts[1]))) {
            return false;
        }

        // Fail closed if the replay store cannot be read or written. The
        // cache path is private deployment storage, not a browser endpoint.
        if (!$this->consumeNonce($claims['nonce'], $claims['timestamp'] + $ttl + $clockSkew)) {
            return false;
        }

        $this->clientId = $claims['client_id'];
        return true;
    }

    /** Atomically record a nonce until its signed token can no longer be valid. */
    private function consumeNonce($nonce, $expiresAt) {
        $directory = isset($this->config['cache_path']) && is_string($this->config['cache_path'])
            ? rtrim($this->config['cache_path'], '/\\')
            : '';
        if ($directory === '' || !is_dir($directory)) {
            return false;
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'hmac-used-nonces.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        rewind($handle);
        $stored = stream_get_contents($handle, 2097153);
        if (!is_string($stored) || strlen($stored) > 2097152) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }

        $entries = [];
        if (trim($stored) !== '') {
            $decoded = json_decode($stored, true);
            if (!is_array($decoded)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                return false;
            }
            $now = time();
            foreach ($decoded as $key => $expiry) {
                if (is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key)
                    && (is_int($expiry) || ctype_digit((string) $expiry)) && (int) $expiry > $now) {
                    $entries[$key] = (int) $expiry;
                }
            }
        }

        $nonceKey = hash('sha256', $nonce);
        if (isset($entries[$nonceKey])) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        $entries[$nonceKey] = (int) $expiresAt;
        $encoded = json_encode($entries);
        if (!is_string($encoded)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }

        rewind($handle);
        $truncated = ftruncate($handle, 0);
        $written = $truncated ? fwrite($handle, $encoded) : false;
        $flushed = $written === strlen($encoded) && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $flushed === true;
    }

    /** Reject empty and shipped example values rather than authenticating them. */
    private function isConfiguredSecret($secret) {
        if (!is_string($secret) || $secret === '') {
            return false;
        }
        return !in_array($secret, [
            'your-secure-api-token-here',
            'your-secure-api-secret-here',
            'your-api-token-here',
            'your-very-secure-random-token-here',
            'your-very-secure-secret-here',
        ], true);
    }

    /** Extract client ID from a configured token payload, if present. */
    private function getClientIdFromToken($token) {
        $parts = explode('.', $token, 2);
        if (count($parts) === 2) {
            $payload = base64_decode($parts[0], true);
            $decoded = $payload === false ? null : json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['client_id']) && is_numeric($decoded['client_id'])) {
                return max(0, (int) $decoded['client_id']);
            }
        }
        return 0;
    }

    /** Get authenticated client ID. */
    public function getClientId() {
        return $this->clientId;
    }

    /** Generate one short-lived, single-use API token for one request. */
    public static function generateToken($clientId, $apiSecret) {
        if (!is_string($apiSecret) || $apiSecret === ''
            || in_array($apiSecret, ['your-secure-api-secret-here', 'your-very-secure-secret-here'], true)) {
            throw new InvalidArgumentException('A configured API secret is required.');
        }
        if (!is_numeric($clientId) || (int) $clientId < 0) {
            throw new InvalidArgumentException('Client ID must be a non-negative integer.');
        }

        $payload = json_encode([
            'client_id' => (int) $clientId,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ]);
        if (!is_string($payload)) {
            throw new RuntimeException('Unable to create API token.');
        }

        $base64Payload = base64_encode($payload);
        $signature = hash_hmac('sha256', $payload, $apiSecret);
        return $base64Payload . '.' . $signature;
    }
}
