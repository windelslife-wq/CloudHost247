<?php
/**
 * Domain Broker — REST API router.
 *
 * Every route passes through the same pipeline, so no endpoint can forget a
 * control:
 *
 *   1. resolve the principal (bearer token or host session) — never the client
 *   2. require authentication unless the route is explicitly public
 *   3. enforce the route's RBAC permission
 *   4. enforce the token's scopes, when the token is scoped
 *   5. CSRF-check cookie-authenticated writes (token calls are exempt: there
 *      is no ambient credential to forge)
 *   6. rate limit per principal
 *   7. wrap financial writes in an idempotency key
 *   8. run the controller and map any exception onto a JSON error envelope
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api;

use DomainBroker\Core\Actor;
use DomainBroker\Core\AuthenticationException;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Csrf;
use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\Identity;
use DomainBroker\Core\Logger;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;

class Router
{
    /**
     * The route table.
     *
     * [method, pattern, controller, action, options]
     *
     * options:
     *   permission   RBAC permission required (null = authenticated only)
     *   public       true to allow unauthenticated access
     *   bucket       rate limit bucket (defaults by method)
     *   idempotent   true to require/derive an Idempotency-Key
     *   scope        API token scope name (defaults to controller.action)
     */
    const ROUTES = [
        // Discovery and identity
        ['GET',    'ping',                        'System',     'ping',        ['public' => true]],
        ['GET',    'me',                          'System',     'me',          []],
        ['GET',    'settings',                    'System',     'settings',    []],

        // Domain intelligence
        ['GET',    'domains/check',               'Domains',    'check',       ['bucket' => 'domain.lookup']],
        ['GET',    'domains/lookup',              'Domains',    'lookup',      ['bucket' => 'domain.lookup']],

        // Acquisition requests
        ['POST',   'requests',                    'Requests',   'store',       ['permission' => 'request.create', 'bucket' => 'request.create', 'idempotent' => true]],
        ['GET',    'requests',                    'Requests',   'index',       []],
        ['GET',    'requests/summary',            'Requests',   'summary',     []],
        ['GET',    'requests/{id}',               'Requests',   'show',        []],
        ['PATCH',  'requests/{id}',               'Requests',   'update',      ['bucket' => 'request.update']],
        ['POST',   'requests/{id}/cancel',        'Requests',   'cancel',      ['bucket' => 'request.update']],
        ['GET',    'requests/{id}/timeline',      'Requests',   'timeline',    []],
        ['POST',   'requests/{id}/assign',        'Requests',   'assign',      ['permission' => 'broker.assign']],
        ['POST',   'requests/{id}/claim',         'Requests',   'claim',       ['permission' => 'request.claim']],
        ['POST',   'requests/{id}/approve',       'Requests',   'approve',     ['permission' => 'request.approve']],
        ['POST',   'requests/{id}/reject',        'Requests',   'reject',      ['permission' => 'request.approve']],
        ['POST',   'requests/{id}/status',        'Requests',   'override',    ['permission' => 'status.override']],
        ['POST',   'requests/{id}/escalate',      'Requests',   'escalate',    ['permission' => 'request.escalate']],

        // Negotiation
        ['GET',    'requests/{id}/offers',        'Offers',     'index',       []],
        ['POST',   'requests/{id}/offers',        'Offers',     'store',       ['permission' => 'offer.create', 'bucket' => 'offer.create']],
        ['POST',   'requests/{id}/owner-contact', 'Offers',     'ownerContact', ['permission' => 'owner.contact']],
        ['POST',   'negotiations/{id}/response',  'Offers',     'ownerResponse', ['permission' => 'offer.record.owner']],
        ['GET',    'offers/{id}',                 'Offers',     'show',        []],
        ['POST',   'offers/{id}/accept',          'Offers',     'accept',      ['permission' => 'offer.respond', 'bucket' => 'offer.action', 'idempotent' => true]],
        ['POST',   'offers/{id}/reject',          'Offers',     'reject',      ['permission' => 'offer.respond', 'bucket' => 'offer.action']],
        ['POST',   'offers/{id}/counter',         'Offers',     'counter',     ['bucket' => 'offer.action']],
        ['POST',   'offers/{id}/withdraw',        'Offers',     'withdraw',    ['bucket' => 'offer.action']],

        // Money
        ['POST',   'requests/{id}/invoice',       'Payments',   'invoice',     ['permission' => 'payment.pay', 'bucket' => 'payment.action', 'idempotent' => true]],
        ['GET',    'requests/{id}/payment',       'Payments',   'show',        []],
        ['POST',   'payments/{id}/sync',          'Payments',   'sync',        ['bucket' => 'payment.action']],
        ['POST',   'payments/{id}/refund',        'Payments',   'refund',      ['permission' => 'payment.refund', 'bucket' => 'payment.action', 'idempotent' => true]],
        ['POST',   'payments/{id}/release',       'Payments',   'release',     ['permission' => 'payment.release', 'idempotent' => true]],
        ['POST',   'payments/{id}/custody',       'Payments',   'custody',     ['permission' => 'payment.release']],
        ['GET',    'transactions',                'Payments',   'transactions', []],

        // Transfer
        ['GET',    'requests/{id}/transfer',      'Transfers',  'show',        []],
        ['POST',   'requests/{id}/transfer',      'Transfers',  'start',       ['permission' => 'transfer.start']],
        ['POST',   'transfers/{id}/status',       'Transfers',  'updateStatus', ['permission' => 'transfer.update']],
        ['POST',   'transfers/{id}/auth-code',    'Transfers',  'recordAuthCode', ['permission' => 'transfer.update']],
        ['POST',   'transfers/{id}/complete',     'Transfers',  'complete',    ['permission' => 'milestone.mark']],
        ['POST',   'requests/{id}/complete',      'Transfers',  'completeAcquisition', ['permission' => 'milestone.mark']],
        ['GET',    'requests/{id}/verification',  'Transfers',  'verification', []],
        ['POST',   'requests/{id}/verification',  'Transfers',  'submitEvidence', ['permission' => 'verification.record']],
        ['POST',   'verifications/{id}/approve',  'Transfers',  'approveVerification', ['permission' => 'verification.approve']],

        // Conversation and evidence
        ['GET',    'requests/{id}/messages',      'Messages',   'index',       []],
        ['POST',   'requests/{id}/messages',      'Messages',   'store',       ['permission' => 'message.send', 'bucket' => 'message.send']],
        ['POST',   'requests/{id}/messages/read', 'Messages',   'markRead',    []],
        ['GET',    'requests/{id}/documents',     'Documents',  'index',       []],
        ['POST',   'requests/{id}/documents',     'Documents',  'store',       ['permission' => 'document.upload', 'bucket' => 'document.upload']],
        ['GET',    'documents/{id}',              'Documents',  'show',        []],

        // Disputes
        ['GET',    'requests/{id}/disputes',      'Disputes',   'index',       []],
        ['POST',   'requests/{id}/disputes',      'Disputes',   'store',       ['permission' => 'dispute.open', 'bucket' => 'dispute.open']],
        ['POST',   'disputes/{id}/status',        'Disputes',   'setStatus',   ['permission' => 'dispute.resolve']],
        ['POST',   'disputes/{id}/resolve',       'Disputes',   'resolve',     ['permission' => 'dispute.resolve', 'idempotent' => true]],
        ['POST',   'disputes/{id}/reject',        'Disputes',   'reject',      ['permission' => 'dispute.resolve']],

        // Broker administration
        ['GET',    'brokers',                     'Brokers',    'index',       ['permission' => 'report.view']],
        ['POST',   'brokers',                     'Brokers',    'store',       ['permission' => 'broker.manage']],
        ['PATCH',  'brokers/{id}',                'Brokers',    'update',      ['permission' => 'broker.manage']],
        ['POST',   'brokers/{id}/deactivate',     'Brokers',    'deactivate',  ['permission' => 'broker.manage']],

        // Fees, reporting, risk, audit
        ['GET',    'fees',                        'Admin',      'fees',        ['permission' => 'report.view']],
        ['POST',   'fees',                        'Admin',      'createFee',   ['permission' => 'fee.manage']],
        ['PATCH',  'fees/{id}',                   'Admin',      'updateFee',   ['permission' => 'fee.manage']],
        ['DELETE', 'fees/{id}',                   'Admin',      'deleteFee',   ['permission' => 'fee.manage']],
        ['GET',    'fees/quote',                  'Admin',      'quote',       []],
        ['GET',    'reports/overview',            'Admin',      'overview',    ['permission' => 'report.view']],
        ['GET',    'reports/brokers',             'Admin',      'brokerReport', ['permission' => 'report.view']],
        ['GET',    'reports/export',              'Admin',      'export',      ['permission' => 'report.export']],
        ['GET',    'risk/flags',                  'Admin',      'riskFlags',   ['permission' => 'risk.review']],
        ['POST',   'risk/flags/{id}/review',      'Admin',      'reviewRisk',  ['permission' => 'risk.review']],
        ['GET',    'requests/{id}/audit',         'Admin',      'audit',       ['permission' => 'audit.view']],
    ];

    /** Dispatch a request and always return a response. */
    public function dispatch(ApiRequest $request)
    {
        try {
            return $this->run($request);
        } catch (\Throwable $e) {
            return $this->toResponse($e, $request);
        }
    }

    protected function run(ApiRequest $request)
    {
        if ($request->method === 'OPTIONS') {
            return (new ApiResponse(204, null))->withHeader('Allow', 'GET, POST, PATCH, DELETE');
        }

        $match = $this->match($request);
        if ($match === null) {
            if ($this->pathExists($request->path)) {
                return ApiResponse::error(405, 'method_not_allowed', 'That method is not supported on this endpoint.');
            }
            return ApiResponse::error(404, 'unknown_endpoint', 'Unknown API endpoint.');
        }

        list($route, $params) = $match;
        $request->params = $params;
        $options = $route[4];

        $actor = $this->authenticate($request);
        $isPublic = !empty($options['public']);
        if (!$isPublic && $actor->isGuest()) {
            throw new AuthenticationException('Authentication is required.');
        }

        if (!empty($options['permission'])) {
            Rbac::assert($actor, $options['permission']);
        }
        $this->assertScope($actor, $route, $options);

        $write = !in_array($request->method, ['GET', 'HEAD'], true);
        if ($write && $actor->authMethod === 'session' && !$actor->isGuest()) {
            // Cookie-authenticated write: the caller must prove intent.
            Csrf::verify($request->header('X-CSRF-Token') ?: $request->input('csrf_token'));
        }

        $bucket = isset($options['bucket']) ? $options['bucket'] : ($write ? 'api.write' : 'api.read');
        RateLimiter::hit($bucket, $actor->isGuest() ? ('ip:' . $request->ip) : $actor->identity());

        $controller = $this->controller($route[2]);
        $action = $route[3];

        if (!empty($options['idempotent'])) {
            return $this->runIdempotent($controller, $action, $actor, $request, $route);
        }

        return $this->normalise($controller->$action($actor, $request));
    }

    /**
     * Financial writes run inside an idempotency guard. A caller that supplies
     * an Idempotency-Key gets exactly-once semantics; a caller that does not
     * gets a key derived from the payload, which still absorbs double-clicks.
     */
    protected function runIdempotent($controller, $action, Actor $actor, ApiRequest $request, array $route)
    {
        $key = $request->idempotencyKey();
        if ($key === '') {
            $key = Idempotency::deriveKey('api', [
                $actor->identity(), $route[1], $request->params, $request->body,
            ]);
        }

        $scope = 'api:' . $route[2] . '.' . $action;
        $outcome = Idempotency::run(
            $scope,
            $key,
            ['actor' => $actor->identity(), 'params' => $request->params, 'body' => $request->body],
            function () use ($controller, $action, $actor, $request) {
                $response = $this->normalise($controller->$action($actor, $request));
                return ['status' => $response->status, 'body' => $response->body];
            }
        );

        $stored = $outcome['result'];
        $response = new ApiResponse(
            isset($stored['status']) ? (int) $stored['status'] : 200,
            isset($stored['body']) ? $stored['body'] : []
        );
        $response->withHeader('Idempotency-Key', $key);
        if (!empty($outcome['replayed'])) {
            $response->withHeader('Idempotent-Replay', 'true');
        }
        return $response;
    }

    protected function normalise($result)
    {
        if ($result instanceof ApiResponse) {
            return $result;
        }
        return ApiResponse::ok($result === null ? [] : $result);
    }

    /* ------------------------------------------------------------ auth */

    protected function authenticate(ApiRequest $request)
    {
        $token = $request->bearerToken();
        if ($token !== '') {
            $actor = Identity::fromApiToken($token);
            if ($actor === null) {
                throw new AuthenticationException('The API token is invalid, expired or revoked.');
            }
            return $actor;
        }
        return Identity::current();
    }

    /**
     * A scoped token may only reach the endpoints it was issued for, even if
     * the underlying role would allow more.
     */
    protected function assertScope(Actor $actor, array $route, array $options)
    {
        $scopes = Identity::tokenScopes($actor);
        if ($scopes === null) {
            return;
        }
        $needed = isset($options['scope']) ? $options['scope'] : strtolower($route[2]) . '.' . $route[3];
        $group = strtolower($route[2]) . '.*';
        if (in_array('*', $scopes, true) || in_array($needed, $scopes, true) || in_array($group, $scopes, true)) {
            return;
        }
        throw new AuthorizationException('This API token does not carry the "' . $needed . '" scope.');
    }

    /* --------------------------------------------------------- routing */

    protected function match(ApiRequest $request)
    {
        $segments = $request->segments();
        foreach (self::ROUTES as $route) {
            if ($route[0] !== $request->method) {
                continue;
            }
            $params = $this->matchPattern($route[1], $segments);
            if ($params !== null) {
                return [$route, $params];
            }
        }
        return null;
    }

    protected function pathExists($path)
    {
        $segments = $path === '' ? [] : explode('/', $path);
        foreach (self::ROUTES as $route) {
            if ($this->matchPattern($route[1], $segments) !== null) {
                return true;
            }
        }
        return false;
    }

    protected function matchPattern($pattern, array $segments)
    {
        $parts = $pattern === '' ? [] : explode('/', $pattern);
        if (count($parts) !== count($segments)) {
            return null;
        }
        $params = [];
        foreach ($parts as $i => $part) {
            if (strlen($part) > 2 && $part[0] === '{' && substr($part, -1) === '}') {
                $params[substr($part, 1, -1)] = $segments[$i];
                continue;
            }
            if ($part !== $segments[$i]) {
                return null;
            }
        }
        return $params;
    }

    protected function controller($name)
    {
        $class = __NAMESPACE__ . '\\Controllers\\' . $name . 'Controller';
        if (!class_exists($class)) {
            throw new \RuntimeException('Unknown API controller: ' . $name);
        }
        return new $class();
    }

    /* ------------------------------------------------------ error map */

    protected function toResponse(\Throwable $e, ApiRequest $request)
    {
        if ($e instanceof DomainBrokerException) {
            $details = $e instanceof ValidationException ? $e->errors() : $e->context();
            $response = ApiResponse::error($e->httpStatus(), $e->errorCode(), $e->getMessage(), is_array($details) ? $details : []);
            if ($e instanceof \DomainBroker\Core\RateLimitException) {
                $response->withHeader('Retry-After', (string) $e->retryAfter);
            }
            if ($e->httpStatus() >= 500) {
                Logger::exception($e, ['path' => $request->path]);
            }
            return $response;
        }

        Logger::exception($e, ['path' => $request->path, 'method' => $request->method]);
        $message = Settings::bool('debug_logging', false)
            ? $e->getMessage()
            : 'An unexpected error occurred. The incident has been logged.';
        return ApiResponse::error(500, 'server_error', $message);
    }
}
