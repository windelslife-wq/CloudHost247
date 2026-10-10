<?php
/**
 * Authenticated, same-origin REST surface for provider accounts, customer VMs, and read-only domain inventory.
 *
 * The router is deliberately small and transport-independent so the route,
 * permission, CSRF, and idempotency behavior can be exercised offline.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Api;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Db;
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
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\ControlPanels\PanelAccountService;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Domains\CloudflareDnsInventoryAdapter;
use Ch247Apps\Domains\DnsInventoryProviderRegistry;
use Ch247Apps\Domains\DomainService;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\ContaboAdoptionService;
use Ch247Apps\Infrastructure\OvhLegacyInspectionService;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ServerProductMappingService;

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
                    : (isset($_POST['ch247_token']) ? $_POST['ch247_token'] : null);
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

        if (preg_match('#^/v1/domains/([0-9]+)/dns-inventory$#', $path, $match) && $method === 'GET') {
            if ($input) {
                throw new ValidationException('DNS inventory requests do not accept request fields.');
            }
            return $this->dnsInventory((int) $match[1]);
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

        if ($path === '/v1/panel-accounts' && $method === 'GET') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            return self::response(200, ['data' => (new PanelAccountService($this->actor))->listing()]);
        }
        if ($path === '/v1/panel-accounts' && $method === 'POST') {
            $this->authorize(Rbac::PANEL_ACCOUNT_MANAGE);
            foreach (array_keys($input) as $field) {
                if (!in_array((string) $field, ['service_id', 'server_id', 'username', 'domain', 'package'], true)) {
                    throw new ValidationException('Unsupported panel-account binding field.', ['field' => (string) $field]);
                }
            }
            $account = [];
            foreach (['username', 'domain', 'package'] as $field) {
                if (array_key_exists($field, $input)) {
                    $account[$field] = $input[$field];
                }
            }
            $identifiers = [];
            foreach (['service_id', 'server_id'] as $field) {
                $raw = isset($input[$field]) ? $input[$field] : null;
                if (!is_int($raw) && !is_string($raw)) {
                    throw new ValidationException('A positive numeric identifier is required.', ['field' => $field]);
                }
                $raw = (string) $raw;
                $value = filter_var($raw, FILTER_VALIDATE_INT);
                if (!preg_match('/^[0-9]+$/D', $raw) || $value === false || (int) $value <= 0) {
                    throw new ValidationException('A positive numeric identifier is required.', ['field' => $field]);
                }
                $identifiers[$field] = (int) $value;
            }
            $result = (new PanelAccountService($this->actor))->bindExistingAccount(
                $identifiers['service_id'], $identifiers['server_id'],
                $account, self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)$#', $path, $match) && $method === 'GET') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            return self::response(200, ['data' => (new PanelAccountService($this->actor))->get((int) $match[1])]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)/domains$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            if ($input) {
                throw new ValidationException('The read-only domain inventory request does not accept fields.');
            }
            $result = (new PanelAccountService($this->actor))->requestDomainInventory(
                (int) $match[1], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)/domain-aliases$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            if ($input) {
                throw new ValidationException('The read-only built-in alias request does not accept fields.');
            }
            $result = (new PanelAccountService($this->actor))->requestDomainAliases(
                (int) $match[1], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)/quota-usage$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            if ($input) {
                throw new ValidationException('The read-only quota-usage request does not accept fields.');
            }
            $result = (new PanelAccountService($this->actor))->requestQuotaUsage(
                (int) $match[1], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)/bandwidth-usage$#', $path, $match) && $method === 'POST') {
            $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
            if ($input) {
                throw new ValidationException('The read-only bandwidth-usage request does not accept fields.');
            }
            $result = (new PanelAccountService($this->actor))->requestBandwidthUsage(
                (int) $match[1], self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }
        if (preg_match('#^/v1/panel-accounts/([0-9]+)/actions$#', $path, $match) && $method === 'POST') {
            $action = isset($input['action']) && is_scalar($input['action']) ? (string) $input['action'] : '';
            $this->authorize(PanelAccountService::permissionForAction($action));
            $result = (new PanelAccountService($this->actor))->requestAction(
                (int) $match[1], $input, self::idempotencyKey($headers)
            );
            return self::response(202, ['data' => $result]);
        }

        // Staff-only WHMCS legacy snapshot: no OVH request, reservation or VM.
        if (preg_match('#^/v1/ovh/legacy-services/([0-9]+)$#', $path, $match) && $method === 'GET') {
            $this->authorize(Rbac::CUSTOMER_SERVER_VIEW_ALL);
            $this->authorize(Rbac::CUSTOMER_SERVER_MANAGE);
            return self::response(200, ['data' => (new OvhLegacyInspectionService($this->actor))->inspect($match[1])]);
        }

        // Operator-only Contabo adoption: no customer-provided provider ID, no
        // provider write, and no reuse of the customer VM lifecycle endpoints.
        if ($path === '/v1/contabo/adoptions' && $method === 'GET') {
            $this->authorize(Rbac::CUSTOMER_SERVER_VIEW_ALL);
            return self::response(200, ['data' => (new ContaboAdoptionService($this->actor))->listing()]);
        }
        if (preg_match('#^/v1/contabo/adoptions/([0-9]+)$#', $path, $match) && $method === 'GET') {
            $this->authorize(Rbac::CUSTOMER_SERVER_VIEW_ALL);
            return self::response(200, ['data' => (new ContaboAdoptionService($this->actor))->get((int) $match[1])]);
        }
        if ($path === '/v1/contabo/adoptions' && $method === 'POST') {
            $this->authorize(Rbac::CUSTOMER_SERVER_MANAGE);
            $this->authorize(Rbac::PROVIDER_ACCOUNT_MANAGE);
            $result = (new ContaboAdoptionService($this->actor))->request($input, self::idempotencyKey($headers));
            return self::response(202, ['data' => $result]);
        }

        if ($path === '/v1/server-product-mappings' && $method === 'GET') {
            $this->authorize(Rbac::PLAN_MANAGE);
            $this->authorize(Rbac::PROVIDER_ACCOUNT_MANAGE);
            return self::response(200, ['data' => (new ServerProductMappingService($this->actor))->listing()]);
        }
        if ($path === '/v1/server-product-mappings' && $method === 'POST') {
            $this->authorize(Rbac::PLAN_MANAGE);
            $this->authorize(Rbac::PROVIDER_ACCOUNT_MANAGE);
            $key = self::idempotencyKey($headers);
            $run = Idempotency::run('server-product-mapping.save', $key, $input, function () use ($input) {
                return (new ServerProductMappingService($this->actor))->save($input);
            });
            return self::response(!empty($run['replayed']) ? 200 : 201,
                ['data' => $run['result'], 'replayed' => !empty($run['replayed'])]);
        }
        if ($path === '/v1/servers/self-service' && $method === 'POST') {
            $this->authorize(Rbac::CUSTOMER_SERVER_ORDER);
            if (count($input) !== 1 || !isset($input['service_id'])
                || !ctype_digit((string) $input['service_id']) || (int) $input['service_id'] <= 0) {
                throw new ValidationException('Only a positive WHMCS service_id is accepted.');
            }
            return self::response(202, ['data' => (new CustomerServerService($this->actor))->requestSelfServiceProvision(
                (int) $input['service_id'], self::idempotencyKey($headers)
            )]);
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

    /** Default-off, owner-scoped read-only DNS inventory API endpoint. */
    private function dnsInventory($domainId)
    {
        $permission = $this->actor->isCustomer() ? Rbac::DOMAIN_VIEW_OWN : Rbac::DOMAIN_VIEW_ALL;
        $this->authorize($permission);

        // This App Cloud gate is deliberately checked before even registering or
        // resolving the Cloudflare bridge. The bridge itself retains Phase 12's
        // independent master and DNS-inventory gates.
        if (!Settings::bool('dns_inventory_api_enabled', false)) {
            throw new ProviderUnavailableException('The App Cloud DNS inventory API is disabled.', [
                'provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_API_DISABLED',
            ]);
        }

        $where = ['id' => (int) $domainId, 'deleted_at' => null];
        if ($this->actor->isCustomer()) {
            // Scope in SQL, rather than loading another customer's row and
            // relying only on a later in-memory check. A miss is always a 404.
            $where['customer_id'] = (int) $this->actor->clientId;
        }
        $domainRow = Db::first('domains', $where);
        if (!$domainRow) {
            throw new NotFoundException('That domain does not exist.');
        }
        if (!isset($domainRow['domain_type']) || $domainRow['domain_type'] !== DomainService::TYPE_CUSTOMER) {
            throw new NotFoundException('That domain does not exist.');
        }
        if (!isset($domainRow['verification_status'])
            || $domainRow['verification_status'] !== DomainService::VERIFICATION_VERIFIED) {
            throw new StateException('Verify this domain before reading its DNS inventory.', [
                'error_code' => 'DOMAIN_NOT_VERIFIED',
            ]);
        }

        if (!DnsInventoryProviderRegistry::hasProvider('cloudflare')) {
            DnsInventoryProviderRegistry::register(new CloudflareDnsInventoryAdapter());
        }
        $inventory = DnsInventoryProviderRegistry::forProvider('cloudflare')->listForDomain(
            (int) $domainRow['customer_id'], (string) $domainRow['domain']
        );

        $expectedDomain = strtolower(rtrim(trim((string) $domainRow['domain']), '.'));
        $actualDomain = is_array($inventory) && isset($inventory['domain']) && is_string($inventory['domain'])
            ? strtolower(rtrim(trim($inventory['domain']), '.')) : '';
        if (!is_array($inventory) || !isset($inventory['provider'], $inventory['records'])
            || $inventory['provider'] !== 'cloudflare' || !is_array($inventory['records'])
            || $expectedDomain === '' || $actualDomain !== $expectedDomain
            || array_values($inventory['records']) !== $inventory['records']) {
            throw new ProviderUnavailableException('The DNS inventory provider returned an invalid response.', [
                'provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID',
            ]);
        }

        // Re-project at the HTTP boundary too, so an adapter's internal fields
        // can never leak if its contract grows in a later release.
        $publicRecords = [];
        $recordTypes = [];
        $recordFields = ['id', 'type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'];
        foreach ($inventory['records'] as $record) {
            if (!is_array($record)) {
                throw new ProviderUnavailableException('The DNS inventory provider returned an invalid record.', [
                    'provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID',
                ]);
            }
            foreach ($recordFields as $field) {
                if (!array_key_exists($field, $record)) {
                    throw new ProviderUnavailableException('The DNS inventory provider returned an incomplete record.', [
                        'provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID',
                    ]);
                }
            }
            if (!is_string($record['type']) || !preg_match('/^[A-Za-z0-9]{1,16}$/D', $record['type'])) {
                throw new ProviderUnavailableException('The DNS inventory provider returned an invalid record type.', [
                    'provider_code' => 'cloudflare', 'error_code' => 'DNS_INVENTORY_INVALID',
                ]);
            }
            $publicRecord = [];
            foreach ($recordFields as $field) {
                $publicRecord[$field] = $record[$field];
            }
            $publicRecords[] = $publicRecord;
            $type = strtoupper($record['type']);
            $recordTypes[$type] = isset($recordTypes[$type]) ? $recordTypes[$type] + 1 : 1;
        }
        ksort($recordTypes, SORT_STRING);

        Audit::record($this->actor, Audit::DNS_INVENTORY_READ, [
            'resource_type' => 'domain',
            'resource_id' => (int) $domainRow['id'],
            'client_id' => (int) $domainRow['customer_id'],
            'metadata' => [
                'provider' => 'cloudflare',
                'record_count' => count($publicRecords),
                'record_types' => $recordTypes,
            ],
        ]);

        return self::response(200, ['data' => [
            'provider' => 'cloudflare',
            'domain' => $expectedDomain,
            'records' => $publicRecords,
        ]]);
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
            if (!empty($row['panel_account_id'])) {
                $this->authorize(Rbac::PANEL_ACCOUNT_VIEW);
                (new PanelAccountService($this->actor))->get((int) $row['panel_account_id']);
            } elseif (!empty($row['customer_server_id'])) {
                $permission = Rbac::CUSTOMER_SERVER_VIEW_ALL;
                $this->authorize($permission);
            } elseif (!empty($row['provider_account_id'])) {
                $permission = Rbac::PROVIDER_ACCOUNT_VIEW;
                $this->authorize($permission);
            } else {
                $permission = Rbac::DEPLOYMENT_VIEW_ALL;
                $this->authorize($permission);
            }
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
