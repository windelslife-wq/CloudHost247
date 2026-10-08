<?php
/**
 * Small, fail-closed WHM API 1 transport for cPanel account operations.
 *
 * The target host comes from a registered App Cloud cPanel server; callers may
 * not provide a URL or port. TLS verification is always on, redirects are not
 * followed, credentials are sent only in the Authorization header, and API
 * response bodies are excluded from the shared HTTP diagnostic recorder.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\RetryableCpanelException;
use Ch247Apps\Servers\AgentClient;

class CpanelWhmClient
{
    const ENDPOINTS = [
        'version' => 'version',
        'list_accounts' => 'listaccts',
        'create_account' => 'createacct',
        'suspend_account' => 'suspendacct',
        'unsuspend_account' => 'unsuspendacct',
        'terminate_account' => 'removeacct',
    ];

    /**
     * Make one WHM API 1 call.
     *
     * $connection fields: hostname, server_type=cpanel, cpanel_enabled=true,
     * credential_type=whm_api_token, api_username and api_token. Secret values
     * exist only for this call and are never included in exceptions.
     *
     * @return array Decoded WHM response after a successful metadata.result check.
     */
    public function call(array $connection, $operation, $method, array $parameters = [])
    {
        AgentClient::assertWorkerContext();
        $operation = strtolower(trim((string) $operation));
        if (!isset(self::ENDPOINTS[$operation])) {
            throw new CpanelException('The requested WHM operation is not implemented.', [
                'operation' => $operation, 'error_code' => 'CPANEL_OPERATION_UNSUPPORTED',
            ]);
        }

        $method = strtoupper((string) $method);
        $expectedMethod = in_array($operation, ['version', 'list_accounts'], true) ? 'GET' : 'POST';
        if ($method !== $expectedMethod) {
            throw new CpanelException('The WHM operation was requested with an invalid HTTP method.', [
                'operation' => $operation, 'error_code' => 'CPANEL_METHOD_INVALID',
            ]);
        }
        $host = $this->host($connection);
        $username = $this->username($connection);
        $token = $this->token($connection);
        $parameters = $this->parameters($parameters);

        $query = ['api.version' => 1];
        if ($method === 'GET') {
            $query = array_merge($query, $parameters);
        }
        $url = 'https://' . $host . ':2087/json-api/' . self::ENDPOINTS[$operation]
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $options = [
            'headers' => [
                'Authorization' => 'whm ' . $username . ':' . $token,
                'Accept' => 'application/json',
            ],
            'verify_tls' => true,
            'timeout' => 30,
            'connect_timeout' => 8,
            'max_bytes' => 1048576,
            'redact_response' => true,
        ];
        if ($method === 'POST') {
            $options['form'] = $parameters;
        }

        $response = Http::request($method, $url, $options);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableCpanelException('The WHM API could not complete the request.', [
                'operation' => $operation, 'http_status' => $status,
                'error_code' => 'CPANEL_TRANSPORT_FAILED',
            ]);
        }
        if ($status === 401 || $status === 403) {
            throw new CpanelException('WHM rejected the configured API credentials.', [
                'operation' => $operation, 'http_status' => $status,
                'error_code' => 'CPANEL_AUTHENTICATION_FAILED',
            ]);
        }
        if ($status < 200 || $status >= 300) {
            throw new CpanelException('The WHM API returned an unsuccessful HTTP status.', [
                'operation' => $operation, 'http_status' => $status,
                'error_code' => 'CPANEL_HTTP_ERROR',
            ]);
        }

        $body = isset($response['body']) ? (string) $response['body'] : '';
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new CpanelException('The WHM API returned an invalid JSON response.', [
                'operation' => $operation, 'error_code' => 'CPANEL_RESPONSE_INVALID',
            ]);
        }
        $result = isset($decoded['metadata']['result'])
            ? $decoded['metadata']['result']
            : (isset($decoded['result']) ? $decoded['result'] : null);
        if (!in_array($result, [1, '1', true], true)) {
            throw new CpanelException('WHM did not confirm that the requested operation succeeded.', [
                'operation' => $operation, 'error_code' => 'CPANEL_API_REJECTED',
            ]);
        }
        return $decoded;
    }

    private function host(array $connection)
    {
        if (isset($connection['server_type']) && (string) $connection['server_type'] !== 'cpanel') {
            throw new ConfigurationException('The WHM adapter requires a registered cPanel server.', [
                'error_code' => 'CPANEL_SERVER_TYPE_REQUIRED',
            ]);
        }
        if (!isset($connection['server_type']) || !$this->isEnabled(isset($connection['cpanel_enabled'])
            ? $connection['cpanel_enabled'] : false)) {
            throw new ConfigurationException('The registered server is not enabled for cPanel operations.', [
                'error_code' => 'CPANEL_SERVER_DISABLED',
            ]);
        }
        $host = isset($connection['hostname']) ? strtolower(trim((string) $connection['hostname'])) : '';
        if ($host === '' || strlen($host) > 253 || strpos($host, '://') !== false
            || strpos($host, '/') !== false || strpos($host, '@') !== false || strpos($host, ':') !== false) {
            throw new ConfigurationException('The registered cPanel hostname is invalid.', [
                'error_code' => 'CPANEL_HOST_INVALID',
            ]);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host)) {
            throw new ConfigurationException('The registered cPanel hostname is invalid.', [
                'error_code' => 'CPANEL_HOST_INVALID',
            ]);
        }
        return $host;
    }

    private function username(array $connection)
    {
        if (!isset($connection['credential_type'])
            || (string) $connection['credential_type'] !== 'whm_api_token') {
            throw new ConfigurationException('A WHM API token is required for cPanel operations.', [
                'error_code' => 'CPANEL_CREDENTIAL_TYPE_INVALID',
            ]);
        }
        $username = isset($connection['api_username']) ? trim((string) $connection['api_username']) : '';
        if ($username === '' || strlen($username) > 16
            || !preg_match('/^[a-z][a-z0-9]{0,15}$/i', $username)) {
            throw new ConfigurationException('The WHM API token username is invalid or missing.', [
                'error_code' => 'CPANEL_USERNAME_INVALID',
            ]);
        }
        return strtolower($username);
    }

    private function token(array $connection)
    {
        $token = isset($connection['api_token']) ? (string) $connection['api_token'] : '';
        if (trim($token) === '' || trim($token) !== $token || strlen($token) > 4096
            || preg_match('/[\r\n\x00]/', $token)) {
            throw new ConfigurationException('The WHM API token is invalid or missing.', [
                'error_code' => 'CPANEL_TOKEN_INVALID',
            ]);
        }
        return $token;
    }

    private function isEnabled($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function parameters(array $parameters)
    {
        if (isset($parameters['api.version'])) {
            throw new CpanelException('WHM API version is controlled by the adapter.', [
                'error_code' => 'CPANEL_PARAMETER_RESERVED',
            ]);
        }
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/', $key)
                || !(is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                throw new CpanelException('A WHM API parameter is invalid.', [
                    'error_code' => 'CPANEL_PARAMETER_INVALID',
                ]);
            }
        }
        return $parameters;
    }
}
