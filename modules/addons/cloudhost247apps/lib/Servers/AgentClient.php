<?php
/**
 * CloudHost247 App Cloud — agent client (outbound dispatch).
 *
 * The only component allowed to talk to a server agent. It signs each request,
 * retries with backoff, and translates what comes back into the platform's own
 * exception vocabulary so a deployment step records a real error code
 * (DEPLOYMENT_SERVER_UNAVAILABLE, APPLICATION_IMAGE_PULL_FAILED, …) instead of a
 * string somebody has to parse.
 *
 * Dispatch is refused outside a worker/cron/CLI context. That is the guardrail
 * behind "no Docker from an HTTP request handler": the API enqueues, the worker
 * dispatches, the agent executes.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Servers;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AgentException;
use Ch247Apps\Core\AuthenticationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ServerUnavailableException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class AgentClient
{
    /* Operations the agent implements. Anything not in this list is refused
     * before it leaves the platform: the agent's attack surface is fixed. */
    const OP_PING             = 'ping';
    const OP_CAPABILITIES     = 'capabilities';
    const OP_METRICS          = 'metrics';
    const OP_HEALTH           = 'health';
    const OP_LOGS             = 'logs';
    const OP_COMPOSE_VALIDATE = 'compose_validate';
    const OP_DEPLOY           = 'deploy';
    const OP_UPDATE           = 'update';
    const OP_START            = 'start';
    const OP_STOP             = 'stop';
    const OP_RESTART          = 'restart';
    const OP_REMOVE           = 'remove';
    const OP_BACKUP           = 'backup';
    const OP_RESTORE          = 'restore';
    const OP_SSL_INSTALL      = 'ssl_install';
    const OP_SSL_REVOKE       = 'ssl_revoke';
    const OP_RESOURCE_USAGE   = 'resource_usage';
    const OP_PRUNE            = 'prune';

    const OPERATIONS = [
        self::OP_PING, self::OP_CAPABILITIES, self::OP_METRICS, self::OP_HEALTH, self::OP_LOGS,
        self::OP_COMPOSE_VALIDATE, self::OP_DEPLOY, self::OP_UPDATE, self::OP_START, self::OP_STOP,
        self::OP_RESTART, self::OP_REMOVE, self::OP_BACKUP, self::OP_RESTORE, self::OP_SSL_INSTALL,
        self::OP_SSL_REVOKE, self::OP_RESOURCE_USAGE, self::OP_PRUNE,
    ];

    /** Operations that change state on the node. */
    const MUTATING = [
        self::OP_DEPLOY, self::OP_UPDATE, self::OP_START, self::OP_STOP, self::OP_RESTART,
        self::OP_REMOVE, self::OP_BACKUP, self::OP_RESTORE, self::OP_SSL_INSTALL,
        self::OP_SSL_REVOKE, self::OP_PRUNE,
    ];

    /** Agent error codes → platform exceptions. */
    const ERROR_MAP = [
        'IMAGE_PULL_FAILED'     => 'Ch247Apps\Core\ImagePullException',
        'IMAGE_NOT_FOUND'       => 'Ch247Apps\Core\ImagePullException',
        'REGISTRY_UNAUTHORIZED' => 'Ch247Apps\Core\ImagePullException',
        'HEALTH_CHECK_FAILED'   => 'Ch247Apps\Core\HealthCheckException',
        'COMPOSE_INVALID'       => 'Ch247Apps\Core\ValidationException',
        'PROJECT_NOT_FOUND'     => 'Ch247Apps\Core\NotFoundException',
        'RESOURCE_INSUFFICIENT' => 'Ch247Apps\Core\ResourceInsufficientException',
        'DISK_FULL'             => 'Ch247Apps\Core\ResourceInsufficientException',
        'DOCKER_UNAVAILABLE'    => 'Ch247Apps\Core\ServerUnavailableException',
        'AGENT_BUSY'            => 'Ch247Apps\Core\ServerUnavailableException',
    ];

    /** @var Actor */
    private $actor;

    /** @var array last dispatch summary, for diagnostics */
    private $lastCall = [];

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('AgentClient');
    }

    /**
     * Refuse to dispatch from a web request.
     *
     * @throws ConfigurationException
     */
    public static function assertWorkerContext()
    {
        if (defined('CH247APPS_TESTING') || defined('CH247APPS_WORKER') || defined('CH247APPS_CRON')
            || \PHP_SAPI === 'cli') {
            return true;
        }
        throw new ConfigurationException(
            'Server operations may only be dispatched from the deployment worker or cron, '
            . 'never from an HTTP request handler.'
        );
    }

    /**
     * Send a signed operation to a server's agent.
     *
     * @param array $options timeout, retries, request_id, idempotency_key, allow_pending
     * @return array{ok: bool, status: int, data: array, request_id: string, attempts: int, duration_ms: int}
     * @throws ServerUnavailableException|AgentException|NotFoundException|ValidationException|StateException
     */
    public function dispatch($serverId, $operation, array $payload = [], array $options = [])
    {
        self::assertWorkerContext();

        $operation = strtolower((string) $operation);
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new ValidationException('Unknown agent operation "' . $operation . '".', [
                'errors' => ['operation' => 'Must be one of: ' . implode(', ', self::OPERATIONS)],
            ]);
        }

        $server = Db::first('servers', ['id' => (int) $serverId, 'deleted_at' => null]);
        if (!$server) {
            throw new NotFoundException('That server is not registered.');
        }
        if ((string) $server['status'] === ServerService::STATUS_DISABLED) {
            throw new ServerUnavailableException('That server is disabled.', ['server_id' => (int) $server['id']]);
        }
        // Capability first: a cPanel host can never run a Docker operation, and
        // saying so is more useful (and cheaper) than looking for an agent.
        $this->assertCapability($server, $operation);

        $agent = Db::first('agents', ['id' => (int) $server['agent_id']]);
        if (!$agent) {
            $agent = Db::first('agents', ['server_id' => (int) $server['id'], 'status' => ['notin', ['revoked']]],
                ['order' => 'id', 'dir' => 'desc']);
        }
        if (!$agent) {
            throw new ServerUnavailableException('That server has no registered agent.', [
                'server_id' => (int) $server['id'], 'error_code' => 'AGENT_NOT_REGISTERED',
            ]);
        }
        if ($agent['status'] === 'revoked' || $agent['status'] === 'suspended') {
            throw new AuthenticationException('That agent is ' . $agent['status'] . '.', [
                'reason' => 'agent_' . $agent['status'],
            ]);
        }
        if ($agent['status'] === 'pending' && empty($options['allow_pending']) && $operation !== self::OP_PING) {
            throw new ServerUnavailableException(
                'That agent has not completed its first handshake yet.',
                ['server_id' => (int) $server['id'], 'agent_id' => (int) $agent['id'],
                    'error_code' => 'AGENT_NOT_ACTIVATED']
            );
        }
        if (empty($agent['endpoint'])) {
            throw new ConfigurationException('That agent has no endpoint configured.');
        }

        $requestId = isset($options['request_id']) && $options['request_id'] !== ''
            ? (string) $options['request_id'] : Str::reference('REQ');
        $path = '/agent/v1/' . $operation;
        $body = [
            'request_id' => $requestId,
            'operation' => $operation,
            'server_id' => (int) $server['id'],
            'issued_at' => Clock::now(),
            'issued_by' => $this->actor->identity(),
            'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : null,
            'payload' => Logger::redact($payload),
        ];
        // The agent must see the real payload, not the redacted copy that is logged.
        $body['payload'] = $payload;

        $timeout = isset($options['timeout']) ? (int) $options['timeout']
            : Settings::int('agent_request_timeout_seconds', 120);
        $retries = isset($options['retries']) ? (int) $options['retries']
            : Settings::int('agent_request_retries', 2);

        $attempt = 0;
        $started = microtime(true);
        $lastError = null;

        while ($attempt <= max(0, $retries)) {
            $attempt++;
            // A fresh signature and nonce per attempt: replay protection means an
            // old signed request cannot simply be resent.
            // Sign the exact bytes that go on the wire: Http would otherwise
            // re-encode the body and the agent's signature check would fail.
            $encoded = Str::jsonEncode($body);
            $signed = AgentAuthenticator::sign($agent, 'POST', $path, $encoded);
            $url = rtrim((string) $agent['endpoint'], '/') . $path;

            $response = Http::request('POST', $url, [
                'body' => $encoded,
                'headers' => $signed['headers'],
                'timeout' => $timeout,
                'connect_timeout' => min(15, $timeout),
                'verify' => (bool) Settings::bool('agent_tls_verify', true),
            ]);

            $status = (int) $response['status'];
            $decoded = json_decode((string) $response['body'], true);
            $decoded = is_array($decoded) ? $decoded : [];

            $this->lastCall = [
                'server_id' => (int) $server['id'], 'agent_id' => (int) $agent['id'],
                'operation' => $operation, 'request_id' => $requestId, 'attempt' => $attempt,
                'status' => $status, 'url_host' => parse_url($url, PHP_URL_HOST),
            ];

            if ($status >= 200 && $status < 300 && (empty($decoded['ok']) === false || $decoded === [])) {
                $duration = (int) round((microtime(true) - $started) * 1000);
                Logger::info('Agent operation completed.', [
                    'server_id' => (int) $server['id'], 'operation' => $operation,
                    'request_id' => $requestId, 'status' => $status, 'attempt' => $attempt,
                    'duration_ms' => $duration, 'source' => 'agent',
                ]);
                return [
                    'ok' => true,
                    'status' => $status,
                    'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded,
                    'request_id' => $requestId,
                    'attempts' => $attempt,
                    'duration_ms' => $duration,
                    'agent_version' => isset($decoded['agent_version']) ? $decoded['agent_version'] : null,
                ];
            }

            $lastError = $this->describeFailure($status, $response, $decoded);

            // 4xx (except 408/429) will not improve by retrying.
            if ($status >= 400 && $status < 500 && !in_array($status, [408, 425, 429], true)) {
                break;
            }
            if ($attempt <= $retries) {
                $this->backoff($attempt);
            }
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        Logger::error('Agent operation failed.', array_merge($this->lastCall, [
            'request_id' => $requestId, 'attempts' => $attempt, 'duration_ms' => $duration,
            'error' => $lastError['message'], 'error_code' => $lastError['code'], 'source' => 'agent',
        ]));

        throw $this->exceptionFor($lastError, [
            'server_id' => (int) $server['id'],
            'agent_id' => (int) $agent['id'],
            'operation' => $operation,
            'request_id' => $requestId,
            'attempts' => $attempt,
            'status' => $lastError['status'],
        ]);
    }

    private function assertCapability(array $server, $operation)
    {
        $needsDocker = in_array($operation, [
            self::OP_DEPLOY, self::OP_UPDATE, self::OP_START, self::OP_STOP, self::OP_RESTART,
            self::OP_REMOVE, self::OP_BACKUP, self::OP_RESTORE, self::OP_COMPOSE_VALIDATE,
            self::OP_LOGS, self::OP_RESOURCE_USAGE, self::OP_PRUNE,
        ], true);
        if ($needsDocker && !(int) $server['docker_enabled']) {
            throw new StateException('That server does not run Docker workloads.', [
                'server_id' => (int) $server['id'], 'error_code' => 'SERVER_CAPABILITY_MISSING',
                'capability' => 'docker',
            ]);
        }
    }

    /** Normalise a failure into {status, code, message, retryable}. */
    private function describeFailure($status, array $response, array $decoded)
    {
        if (!empty($response['error'])) {
            return [
                'status' => 0, 'code' => 'AGENT_UNREACHABLE',
                'message' => 'The agent could not be reached: ' . Str::clip((string) $response['error'], 200),
                'retryable' => true,
            ];
        }
        $code = isset($decoded['error_code']) ? strtoupper((string) $decoded['error_code']) : '';
        if ($code === '') {
            $code = $status === 0 ? 'AGENT_UNREACHABLE' : ($status >= 500 ? 'AGENT_SERVER_ERROR' : 'AGENT_REQUEST_FAILED');
        }
        $message = isset($decoded['message']) && $decoded['message'] !== ''
            ? (string) $decoded['message']
            : 'The agent returned HTTP ' . $status . '.';
        return [
            'status' => (int) $status,
            'code' => $code,
            'message' => Str::clip($message, 500),
            'retryable' => $status === 0 || $status >= 500 || in_array($status, [408, 425, 429], true)
                || isset(self::ERROR_MAP[$code]),
        ];
    }

    private function exceptionFor(array $error, array $context)
    {
        // The agent's own code travels alongside the platform code: errorCode()
        // stays the platform vocabulary, and the agent detail is never lost.
        $context['agent_error_code'] = $error['code'];
        $class = isset(self::ERROR_MAP[$error['code']]) ? self::ERROR_MAP[$error['code']] : null;

        if ($error['status'] === 401 || $error['status'] === 403) {
            return new AuthenticationException($error['message'], $context + ['reason' => $error['code']]);
        }
        if ($error['status'] === 404 && $class === null) {
            return new NotFoundException($error['message'], $context);
        }
        if ($error['status'] === 409 && $class === null) {
            return new StateException($error['message'], $context);
        }
        if ($error['status'] === 422 && $class === null) {
            return new ValidationException($error['message'], $context);
        }
        if ($class !== null && class_exists($class)) {
            return new $class($error['message'], $context);
        }
        if ($error['status'] === 0 || $error['status'] >= 500) {
            return new ServerUnavailableException($error['message'], $context);
        }
        return new AgentException($error['message'], $context);
    }

    private function backoff($attempt)
    {
        if (defined('CH247APPS_TESTING')) {
            return;
        }
        $base = Settings::int('agent_retry_backoff_ms', 500);
        $sleepMs = (int) min($base * pow(2, max(0, $attempt - 1)), 15000);
        usleep($sleepMs * 1000);
    }

    /** Convenience: is the agent answering? Never throws. */
    public function ping($serverId)
    {
        try {
            $result = $this->dispatch($serverId, self::OP_PING, [], ['retries' => 0, 'timeout' => 15,
                'allow_pending' => true]);
            return ['reachable' => true, 'data' => $result['data'], 'duration_ms' => $result['duration_ms'],
                'checked_at' => Clock::now()];
        } catch (\Throwable $e) {
            return ['reachable' => false, 'error_code' => $e instanceof \Ch247Apps\Core\AppsException
                ? $e->errorCode() : 'AGENT_UNREACHABLE',
                'message' => $e->getMessage(), 'checked_at' => Clock::now()];
        }
    }

    /** Fetch a real metrics sample from the node. */
    public function collectMetrics($serverId)
    {
        $result = $this->dispatch($serverId, self::OP_METRICS, [], ['retries' => 0, 'timeout' => 30]);
        return $result['data'];
    }

    public function lastCall()
    {
        return $this->lastCall;
    }
}
