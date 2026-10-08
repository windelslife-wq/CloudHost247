<?php
/**
 * Authenticated, same-origin REST surface for provider accounts and customer VMs.
 *
 * The router is deliberately small and transport-independent so the route,
 * permission, CSRF, and idempotency behavior can be exercised offline.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Api;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\AuthenticationException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderRegistry;

class InfrastructureApi
{
    private $actor;

    public function __construct(Actor $actor)
    {
        $this->actor = $actor;
    }

    /** @return array{status:int,body:array,headers:array} */
    public function dispatch($method, $path, array $input = [], array $headers = [])
    {
        try {
            if (!$this->actor->isCustomer() && !$this->actor->isAdmin()) {
                throw new AuthenticationException('Sign in to WHMCS or use a valid bearer token.');
            }
            $method = strtoupper((string) $method);
            $path = self::normalisePath($path);
            $headers = self::normaliseHeaders($headers);

            if ($method === 'OPTIONS') {
                return self::response(204, []);
            }
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                $submitted = isset($headers['x-csrf-token']) ? $headers['x-csrf-token']
                    : (isset($_REQUEST['ch247_token']) ? $_REQUEST['ch247_token'] : null);
                if ($this->actor->authMethod !== 'api_token') {
                    Csrf::verify($submitted);
                }
                RateLimiter::hit('server.action', $this->actor->identity());
            } else {
                RateLimiter::hit('deployment.view', $this->actor->identity());
            }

            return $this->route($method, $path, $input, $headers);
        } catch (AppsException $e) {
            return self::response($e->status(), [
                'error' => ['code' => $e->errorCode(), 'message' => self::safeMessage($e)],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Infrastructure API request failed.', [
                'exception' => get_class($e),
                'source' => 'api',
            ]);
            return self::response(500, [
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.'],
            ]);
        }
    }

    private function route($method, $path, array $input, array $headers)
    {
        if (($path === '/v1/providers' || $path === '/v1/providers/adapters') && $method === 'GET') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_VIEW);
            return self::response(200, ['data' => ProviderRegistry::catalog()]);
        }

        if ($path === '/v1/provider-accounts' && $method === 'GET') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_VIEW);
            $accounts = new ProviderAccountService($this->actor);
            return self::response(200, ['data' => $accounts->listing()]);
        }
        if ($path === '/v1/provider-accounts' && $method === 'POST') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_MANAGE);
            $key = self::idempotencyKey($headers);
            $fingerprintPayload = self::protectCredentialFingerprint($input);
            $run = Idempotency::run('provider-account.create', $key, $fingerprintPayload, function () use ($input) {
                return (new ProviderAccountService($this->actor))->create($input);
            });
            return self::response(!empty($run['replayed']) ? 200 : 201, [
                'data' => isset($run['result']) ? $run['result'] : [],
                'replayed' => !empty($run['replayed']),
            ]);
        }

        if (preg_match('#^/v1/provider-accounts/([0-9]+)$#', $path, $match) && $method === 'GET') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_VIEW);
            $account = (new ProviderAccountService($this->actor))->get((int) $match[1]);
            return self::response(200, ['data' => $account]);
        }
        if (preg_match('#^/v1/provider-accounts/([0-9]+)/credentials$#', $path, $match)
            && in_array($method, ['PUT', 'PATCH'], true)) {
            $this->authorize(Rbac::PROVIDER_CREDENTIAL_ROTATE);
            if (!isset($input['credentials']) || !is_array($input['credentials'])) {
                throw new ValidationException('A credentials object is required.', ['field' => 'credentials']);
            }
            $key = self::idempotencyKey($headers);
            $payload = self::protectCredentialFingerprint([
                'provider_account_id' => (int) $match[1],
                'credentials' => $input['credentials'],
            ]);
            $run = Idempotency::run('provider-account.credentials.rotate', $key, $payload, function () use ($match, $input) {
                return (new ProviderAccountService($this->actor))->writeCredentials(
                    (int) $match[1], $input['credentials'], true
                );
            });
            return self::response(200, ['data' => isset($run['result']) ? $run['result'] : [],
                'replayed' => !empty($run['replayed'])]);
        }
        if (preg_match('#^/v1/provider-accounts/([0-9]+)/verify$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_VERIFY);
            $result = (new ProviderAccountService($this->actor))->requestVerification(
                (int) $match[1], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/provider-accounts/([0-9]+)/suspend$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PROVIDER_ACCOUNT_MANAGE);
            $key = self::idempotencyKey($headers);
            $run = Idempotency::run('provider-account.suspend', $key, ['provider_account_id' => (int) $match[1]], function () use ($match) {
                return (new ProviderAccountService($this->actor))->suspend((int) $match[1]);
            });
            return self::response(200, ['data' => isset($run['result']) ? $run['result'] : [],
                'replayed' => !empty($run['replayed'])]);
        }

        if ($path === '/v1/servers' && $method === 'GET') {
            $this->authorize($this->actor->isCustomer()
                ? Rbac::CUSTOMER_SERVER_VIEW_OWN : Rbac::CUSTOMER_SERVER_VIEW_ALL);
            $servers = new CustomerServerService($this->actor);
            return self::response(200, ['data' => $servers->listing()]);
        }
        if ($path === '/v1/servers' && $method === 'POST') {
            $this->authorize(Rbac::CUSTOMER_SERVER_MANAGE);
            if (!isset($input['spec']) || !is_array($input['spec'])) {
                throw new ValidationException('A normalized server spec is required.', ['field' => 'spec']);
            }
            $result = (new CustomerServerService($this->actor))->requestProvision(
                isset($input['service_id']) ? (int) $input['service_id'] : 0,
                isset($input['provider_account_id']) ? (int) $input['provider_account_id'] : 0,
                $input['spec'], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/servers/([0-9]+)$#', $path, $match) && $method === 'GET') {
            $this->authorize($this->actor->isCustomer()
                ? Rbac::CUSTOMER_SERVER_VIEW_OWN : Rbac::CUSTOMER_SERVER_VIEW_ALL);
            $server = (new CustomerServerService($this->actor))->get((int) $match[1]);
            return self::response(200, ['data' => $server]);
        }
        if (preg_match('#^/v1/servers/([0-9]+)/actions$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::CUSTOMER_SERVER_MANAGE);
            $action = isset($input['action']) ? (string) $input['action'] : '';
            $result = (new CustomerServerService($this->actor))->requestAction(
                (int) $match[1], $action, $input, self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }

        if (preg_match('#^/v1/jobs/([0-9]+)$#', $path, $match) && $method === 'GET') {
            return $this->job((int) $match[1]);
        }

        if (in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return self::response(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'API route not found.']]);
        }
        return self::response(405, ['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'HTTP method not allowed.']]);
    }

    private function job($jobId)
    {
        $row = (new JobQueue())->find($jobId);
        if (!$row) {
            throw new NotFoundException('That job does not exist.');
        }
        if ($this->actor->isCustomer()) {
            $this->authorize(Rbac::CUSTOMER_SERVER_VIEW_OWN);
            if (empty($row['customer_server_id']) || (int) $row['client_id'] !== (int) $this->actor->clientId) {
                throw new NotFoundException('That job does not exist.');
            }
            // Job client_id is a snapshot; verify the live WHMCS service owner too.
            (new CustomerServerService($this->actor))->get((int) $row['customer_server_id']);
        } else {
            if (!empty($row['customer_server_id'])) {
                $permission = Rbac::CUSTOMER_SERVER_VIEW_ALL;
            } elseif (!empty($row['provider_account_id'])) {
                $permission = Rbac::PROVIDER_ACCOUNT_VIEW;
            } else {
                $permission = Rbac::DEPLOYMENT_VIEW_ALL;
            }
            $this->authorize($permission);
        }
        $present = (new JobQueue())->present($row);
        if ($this->actor->isCustomer()) {
            $present = [
                'id' => $present['id'],
                'uuid' => $present['uuid'],
                'job_type' => $present['job_type'],
                'queue' => $present['queue'],
                'status' => $present['status'],
                'attempts' => $present['attempts'],
                'max_attempts' => $present['max_attempts'],
                'available_at' => $present['available_at'],
                'completed_at' => $present['completed_at'],
                'error_code' => $present['error_code'],
                'created_at' => $present['created_at'],
            ];
        }
        return self::response(200, ['data' => $present]);
    }

    private function authorize($permission)
    {
        Rbac::assert($this->actor, $permission);
        $scopes = Identity::tokenScopes($this->actor);
        if ($scopes !== null && !in_array((string) $permission, $scopes, true)) {
            throw new AuthorizationException('The bearer token does not include the required API scope.', [
                'permission' => $permission,
            ]);
        }
    }

    /**
     * Keep provider secrets out of the unkeyed request digest persisted by
     * Idempotency. The HMAC is deterministic for retries but cannot be used as
     * an offline credential verifier from a database-only disclosure.
     */
    private static function protectCredentialFingerprint(array $payload)
    {
        if (!isset($payload['credentials']) || !is_array($payload['credentials'])
            || !$payload['credentials']) {
            return $payload;
        }
        $credentials = $payload['credentials'];
        Idempotency::ksortRecursive($credentials);
        $payload['credentials'] = [
            'keyed_hmac_sha256' => Crypto::keyedFingerprint(
                Str::jsonEncode($credentials), 'api-idempotency-provider-credentials-v1'
            ),
        ];
        return $payload;
    }

    private static function idempotencyKey(array $headers)
    {
        $key = isset($headers['idempotency-key']) ? trim((string) $headers['idempotency-key']) : '';
        if ($key === '' || strlen($key) > 120 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new ValidationException('A valid Idempotency-Key header is required (1–120 visible characters).');
        }
        return $key;
    }

    /** Decode the API's required top-level JSON object into nested PHP arrays. */
    public static function decodeJsonObject($raw)
    {
        $raw = (string) $raw;
        $trimmed = ltrim($raw);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private static function normalisePath($path)
    {
        $path = parse_url((string) $path, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '/';
        }
        $path = '/' . trim((string) $path, '/');
        if (strpos($path, '/api/index.php') === 0) {
            $path = substr($path, strlen('/api/index.php'));
            $path = '/' . trim($path, '/');
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function normaliseHeaders(array $headers)
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower(str_replace('_', '-', (string) $name));
            if (strpos($key, 'http-') === 0) {
                $key = substr($key, 5);
            }
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }
        if (!isset($out['idempotency-key'])) {
            $value = Http::header('Idempotency-Key');
            if ($value !== '') {
                $out['idempotency-key'] = $value;
            }
        }
        if (!isset($out['x-csrf-token'])) {
            $value = Http::header('X-CSRF-Token');
            if ($value !== '') {
                $out['x-csrf-token'] = $value;
            }
        }
        return $out;
    }

    private static function safeMessage(AppsException $e)
    {
        if ($e instanceof ProviderAuthenticationException) {
            return 'The provider rejected the configured credentials.';
        }
        if ($e instanceof ProviderUnavailableException || $e instanceof ProviderConfigurationException) {
            return $e->getMessage();
        }
        if ($e instanceof ProviderOperationException) {
            return 'The provider operation failed. Review provider configuration and worker logs.';
        }
        return $e->getMessage();
    }

    private static function response($status, array $body)
    {
        return [
            'status' => (int) $status,
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }
}
