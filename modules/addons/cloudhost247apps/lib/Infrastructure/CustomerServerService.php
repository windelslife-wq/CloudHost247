<?php
/**
 * Customer-owned VM model and WHMCS-backed provisioning requests.
 *
 * This model is intentionally not `Servers\ServerService`: that service manages
 * App Cloud deployment targets/agents. A row here always belongs to one WHMCS
 * hosting service, and a VM is created only after the service's linked invoice
 * is confirmed paid by WHMCS.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Billing\PaymentGate;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class CustomerServerService
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROVISIONING = 'provisioning';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_TERMINATING = 'terminating';
    const STATUS_TERMINATED = 'terminated';
    const STATUS_FAILED = 'failed';

    const STATE_QUEUED = 'queued';
    const STATE_SERVER_CREATING = 'server_creating';
    const STATE_SERVER_READY = 'server_ready';
    const STATE_REBUILDING = 'rebuilding';
    const STATE_RESIZING = 'resizing';
    const STATE_TERMINATING = 'terminating';
    const STATE_TERMINATED = 'terminated';
    const STATE_FAILED = 'failed';

    /** @var Actor */
    private $actor;
    /** @var GatewayInterface */
    private $gateway;
    /** @var ProviderAccountService */
    private $accounts;
    /** @var JobQueue */
    private $queue;

    public function __construct(Actor $actor = null, GatewayInterface $gateway = null,
        ProviderAccountService $accounts = null, JobQueue $queue = null)
    {
        $this->actor = $actor ?: Actor::system('CustomerServerService');
        $this->gateway = $gateway ?: Gateway::get();
        $this->accounts = $accounts ?: new ProviderAccountService($this->actor);
        $this->queue = $queue ?: new JobQueue();
    }

    /**
     * Queue a VM for a paid WHMCS hosting service. No provider call occurs here.
     *
     * @return array{server:array,job:array|null,replayed:bool}
     */
    public function requestProvision($serviceId, $providerAccountId, array $spec, $idempotencyKey)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_MANAGE);
        return $this->queueProvision($serviceId, $providerAccountId, $spec, $idempotencyKey, null);
    }

    /** Customers supply only a purchased WHMCS service id; the operator owns all VM choices. */
    public function requestSelfServiceProvision($serviceId, $idempotencyKey)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_ORDER);
        if (!$this->actor->isCustomer()) {
            throw new AuthorizationException('Only the owning customer can request self-service provisioning.');
        }
        if (!Settings::bool('customer_server_self_service_enabled', false)) {
            throw new ProviderConfigurationException('Self-service VPS provisioning is disabled.');
        }
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0) {
            throw new ValidationException('A WHMCS service is required.');
        }
        $mapping = (new ServerProductMappingService($this->actor, $this->gateway))->forService($serviceId);
        return $this->queueProvision($serviceId, $mapping['provider_account_id'], $mapping['spec'],
            $idempotencyKey, $mapping['id']);
    }

    private function queueProvision($serviceId, $providerAccountId, array $spec, $idempotencyKey, $mappingId)
    {
        if (!Settings::bool('customer_server_provisioning_enabled', false)) {
            throw new ProviderConfigurationException(
                'Customer-server provisioning is disabled until a real provider adapter is configured and verified.'
            );
        }
        $serviceId = (int) $serviceId;
        $providerAccountId = (int) $providerAccountId;
        if ($serviceId <= 0 || $providerAccountId <= 0) {
            throw new ValidationException('A WHMCS service and provider account are required.');
        }
        $key = self::requireIdempotencyKey($idempotencyKey);
        $normalisedSpec = ServerSpec::normalise($spec);
        $payload = [
            'whmcs_service_id' => $serviceId,
            'provider_account_id' => $providerAccountId,
            'spec' => $normalisedSpec,
            'product_mapping_id' => $mappingId,
        ];

        try {
            $run = Idempotency::run('customer-server.provision', $key, $payload, function () use (
                $serviceId, $providerAccountId, $normalisedSpec, $mappingId
            ) {
                if ($mappingId !== null) {
                    $this->assertSelfServiceMapping($serviceId, $providerAccountId, $normalisedSpec, $mappingId);
                }
                $existing = Db::first('customer_servers', ['whmcs_service_id' => $serviceId]);
                if ($existing && (int) $existing['product_mapping_id'] !== (int) $mappingId) {
                    throw new ConflictException('This WHMCS service already has a different provisioning request.');
                }
                if ($existing) {
                    $this->assertSameProvisioningRequest($existing, $providerAccountId, $normalisedSpec);
                    return ['server_id' => (int) $existing['id'], 'job_id' => (int) $existing['create_job_id']];
                }

                if (Db::first('contabo_adoptions', ['whmcs_service_id' => $serviceId])) {
                    throw new ConflictException('This WHMCS service is reserved for an existing Contabo instance.');
                }
                $billing = $this->billingContext($serviceId);
                // Fail before making any record when the configured provider is
                // not real, verified, and capable of creating and reading VMs.
                $this->accounts->assertOperational($providerAccountId, ['server.create', 'server.get']);

                return Db::transaction(function () use ($serviceId, $providerAccountId, $normalisedSpec, $billing, $mappingId) {
                    // Recheck the operator mapping and service ownership at insertion time.
                    if ($mappingId !== null) {
                        $this->assertSelfServiceMapping($serviceId, $providerAccountId, $normalisedSpec, $mappingId);
                    }
                    // Recheck inside the transaction; the unique WHMCS service
                    // key remains the final concurrency guard.
                    $existing = Db::first('customer_servers', ['whmcs_service_id' => $serviceId]);
                    if ($existing && (int) $existing['product_mapping_id'] !== (int) $mappingId) {
                        throw new ConflictException('This WHMCS service already has a different provisioning request.');
                    }
                    if ($existing) {
                        $this->assertSameProvisioningRequest($existing, $providerAccountId, $normalisedSpec);
                        return ['server_id' => (int) $existing['id'], 'job_id' => (int) $existing['create_job_id']];
                    }

                    if (Db::first('contabo_adoptions', ['whmcs_service_id' => $serviceId])) {
                        throw new ConflictException('This WHMCS service is reserved for an existing Contabo instance.');
                    }
                    $now = Clock::now();
                    $serverId = Db::insert('customer_servers', [
                        'uuid' => Str::uuid4(),
                        'client_id' => (int) $billing['client_id'],
                        'whmcs_service_id' => $serviceId,
                        'whmcs_order_id' => (int) $billing['order_id'],
                        'whmcs_invoice_id' => (int) $billing['invoice_id'],
                        'provider_account_id' => $providerAccountId,
                        'product_mapping_id' => $mappingId,
                        'name' => $normalisedSpec['name'],
                        'hostname' => $normalisedSpec['hostname'],
                        'region' => $normalisedSpec['region'],
                        'image' => $normalisedSpec['image'],
                        'cpu_cores' => $normalisedSpec['cpu_cores'],
                        'memory_mb' => $normalisedSpec['memory_mb'],
                        'storage_gb' => $normalisedSpec['storage_gb'],
                        'requested_spec' => Str::jsonEncode($normalisedSpec),
                        'provider_server_id' => null,
                        'provider_operation_id' => null,
                        'provider_operation' => 'create',
                        'provider_state' => null,
                        'ipv4' => null,
                        'ipv6' => null,
                        'status' => self::STATUS_PENDING,
                        'provisioning_state' => self::STATE_QUEUED,
                        'create_job_id' => null,
                        'poll_count' => 0,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'requested_by' => Str::clip($this->actor->identity(), 120),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $job = $this->queue->enqueue(JobQueue::TYPE_SERVER_CREATE, [
                        'customer_server_id' => $serverId,
                        'provider_account_id' => $providerAccountId,
                        'whmcs_service_id' => $serviceId,
                        'spec' => $normalisedSpec,
                    ], [
                        'queue' => JobQueue::QUEUE_PROVISIONING,
                        'idempotency_key' => 'customer-server:create:' . $serviceId,
                        'customer_server_id' => $serverId,
                        'provider_account_id' => $providerAccountId,
                        'whmcs_service_id' => $serviceId,
                        'client_id' => (int) $billing['client_id'],
                        'requested_by' => $this->actor->identity(),
                    ]);
                    Db::update('customer_servers', ['create_job_id' => (int) $job['id']], ['id' => $serverId]);
                    $this->recordLifecycle($serverId, 'provision_queued', null, self::STATUS_PENDING,
                        null, self::STATE_QUEUED, [
                            'provider_account_id' => $providerAccountId,
                            'whmcs_service_id' => $serviceId,
                            'whmcs_invoice_id' => (int) $billing['invoice_id'],
                            'job_id' => (int) $job['id'],
                        ], $this->actor);
                    return ['server_id' => $serverId, 'job_id' => (int) $job['id']];
                });
            });
        } catch (\PDOException $e) {
            // A concurrent request for the same WHMCS service loses at the
            // database unique constraint. Return the winner only if its immutable
            // provider/spec request is identical; otherwise report a conflict.
            if (!self::isConstraintViolation($e)) {
                throw $e;
            }
            $existing = Db::first('customer_servers', ['whmcs_service_id' => $serviceId]);
            if (!$existing) {
                throw new ConflictException('This provisioning request is already being processed.');
            }
            if ((int) $existing['product_mapping_id'] !== (int) $mappingId) {
                throw new ConflictException('This WHMCS service already has a different provisioning request.');
            }
            $this->assertSameProvisioningRequest($existing, $providerAccountId, $normalisedSpec);
            $run = ['result' => ['server_id' => (int) $existing['id'],
                'job_id' => (int) $existing['create_job_id']], 'replayed' => true];
        }

        $ids = isset($run['result']) ? $run['result'] : [];
        return $this->responseFor(isset($ids['server_id']) ? (int) $ids['server_id'] : 0,
            isset($ids['job_id']) ? (int) $ids['job_id'] : 0, !empty($run['replayed']));
    }

    /** Queue one lifecycle action; provider I/O remains in the worker. */
    public function requestAction($serverId, $action, array $input, $idempotencyKey)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_MANAGE);
        $serverId = (int) $serverId;
        $action = strtolower(trim((string) $action));
        $mapping = [
            'reboot' => [JobQueue::TYPE_SERVER_REBOOT, 'server.reboot'],
            'power_on' => [JobQueue::TYPE_SERVER_POWER_ON, 'server.power_on'],
            'power_off' => [JobQueue::TYPE_SERVER_POWER_OFF, 'server.power_off'],
            'rebuild' => [JobQueue::TYPE_SERVER_REBUILD, 'server.rebuild'],
            'resize' => [JobQueue::TYPE_SERVER_RESIZE, 'server.resize'],
            'delete' => [JobQueue::TYPE_SERVER_DELETE, 'server.delete'],
        ];
        if (!isset($mapping[$action])) {
            throw new ValidationException('Unsupported server action.', ['action' => $action]);
        }
        $key = self::requireIdempotencyKey($idempotencyKey);
        $row = $this->internalRow($serverId);
        $deleteConfirmed = isset($input['confirm']) && $input['confirm'] === true;

        $spec = [];
        if (in_array($action, ['rebuild', 'resize'], true)) {
            $base = Str::jsonDecode($row['requested_spec'], []);
            $patch = isset($input['spec']) && is_array($input['spec']) ? $input['spec'] : [];
            $spec = ServerSpec::normalise(array_merge($base, $patch));
        } elseif (isset($input['spec']) && $input['spec'] !== []) {
            throw new ValidationException('This action does not accept a server specification.');
        }

        $providerAccountId = (int) $row['provider_account_id'];
        $payload = ['customer_server_id' => $serverId, 'action' => $action, 'spec' => $spec];
        if ($action === 'delete') {
            $payload['confirm'] = $deleteConfirmed;
        }
        $run = Idempotency::run('customer-server.action', $key, $payload, function () use (
            $action, $mapping, $key, $spec, $providerAccountId, $serverId, $deleteConfirmed
        ) {
            $current = $this->internalRow($serverId);
            if (in_array((string) $current['status'], [self::STATUS_TERMINATING, self::STATUS_TERMINATED], true)) {
                throw new ConflictException('This server is already terminating or terminated.');
            }
            if ((string) $current['provisioning_state'] !== self::STATE_SERVER_READY) {
                throw new ConflictException('Server lifecycle actions are allowed only after the provider reports the VM ready.');
            }
            if (empty($current['provider_server_id'])) {
                throw new ConflictException('The provider has not returned a server id yet.');
            }
            if ($action === 'delete' && !$deleteConfirmed) {
                throw new ValidationException('Set confirm=true to queue server deletion.', ['field' => 'confirm']);
            }
            if ($action === 'delete') {
                $this->assertWhmcsServiceTerminated((int) $current['whmcs_service_id']);
            } else {
                $this->assertWhmcsOperationAllowed((int) $current['whmcs_service_id'], $action);
            }
            $this->accounts->assertOperational($providerAccountId, ['server.get', $mapping[$action][1]]);

            return Db::transaction(function () use (
                $action, $mapping, $key, $spec, $providerAccountId, $serverId, $deleteConfirmed
            ) {
                $current = $this->internalRow($serverId);
                if (in_array((string) $current['status'], [self::STATUS_TERMINATING, self::STATUS_TERMINATED], true)) {
                    throw new ConflictException('This server is already terminating or terminated.');
                }
                if ((string) $current['provisioning_state'] !== self::STATE_SERVER_READY) {
                    throw new ConflictException('Server lifecycle actions are allowed only after the provider reports the VM ready.');
                }
                if (empty($current['provider_server_id'])) {
                    throw new ConflictException('The provider has not returned a server id yet.');
                }
                $fromStatus = (string) $current['status'];
                $fromState = (string) $current['provisioning_state'];
                $toStatus = $fromStatus;
                $toState = $fromState;
                if ($action === 'delete') {
                    if (!$deleteConfirmed) {
                        throw new ValidationException('Set confirm=true to queue server deletion.', ['field' => 'confirm']);
                    }
                    $toStatus = self::STATUS_TERMINATING;
                    $toState = self::STATE_TERMINATING;
                } elseif ($action === 'rebuild') {
                    $toStatus = self::STATUS_PROVISIONING;
                    $toState = self::STATE_REBUILDING;
                } elseif ($action === 'resize') {
                    $toStatus = self::STATUS_PROVISIONING;
                    $toState = self::STATE_RESIZING;
                }
                $lifecycleChange = in_array($action, ['delete', 'rebuild', 'resize'], true);
                if ($lifecycleChange) {
                    $won = Db::compareAndSet('customer_servers', [
                        'status' => $toStatus,
                        'provisioning_state' => $toState,
                        'provider_operation' => $action,
                        'poll_count' => 0,
                        'pending_spec' => in_array($action, ['rebuild', 'resize'], true)
                            ? Str::jsonEncode($spec) : null,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'updated_at' => Clock::now(),
                    ], [
                        'id' => $serverId,
                        'status' => $fromStatus,
                        'provisioning_state' => $fromState,
                        'provider_server_id' => (string) $current['provider_server_id'],
                    ]);
                    if (!$won) {
                        throw new ConflictException('Another lifecycle request changed this server; retry with a new request.');
                    }
                    $this->recordLifecycle($serverId, 'server_' . $action . '_queued',
                        $fromStatus, $toStatus, $fromState, $toState,
                        ['requested_by' => $this->actor->identity()], $this->actor);
                }

                $job = $this->queue->enqueue($mapping[$action][0], [
                    'customer_server_id' => $serverId,
                    'provider_account_id' => $providerAccountId,
                    'whmcs_service_id' => (int) $current['whmcs_service_id'],
                    'provider_server_id' => (string) $current['provider_server_id'],
                    'action' => $action,
                    'spec' => $spec,
                ], [
                    'queue' => JobQueue::QUEUE_PROVISIONING,
                    'idempotency_key' => 'customer-server:' . $action . ':' . $serverId . ':' . substr(hash('sha256', $key), 0, 24),
                    'customer_server_id' => $serverId,
                    'provider_account_id' => $providerAccountId,
                    'whmcs_service_id' => (int) $current['whmcs_service_id'],
                    'client_id' => (int) $current['client_id'],
                    'requested_by' => $this->actor->identity(),
                ]);
                return ['server_id' => $serverId, 'job_id' => (int) $job['id']];
            });
        });

        $ids = isset($run['result']) ? $run['result'] : [];
        if (empty($run['replayed'])) {
            Audit::record($this->actor, Audit::CUSTOMER_SERVER_ACTION_QUEUED, [
                'resource_type' => 'customer_server', 'resource_id' => $serverId,
                'client_id' => (int) $row['client_id'],
                'metadata' => ['action' => $action, 'job_id' => isset($ids['job_id']) ? (int) $ids['job_id'] : null,
                    'whmcs_service_id' => (int) $row['whmcs_service_id']],
                'severity' => $action === 'delete' ? 'warning' : 'info',
            ]);
        }
        return $this->responseFor($serverId, isset($ids['job_id']) ? (int) $ids['job_id'] : 0,
            !empty($run['replayed']));
    }

    /** Customers see their own servers; operations staff see the fleet. */
    public function listing()
    {
        if ($this->actor->isCustomer()) {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_OWN);
            $where = ['client_id' => (int) $this->actor->clientId];
        } else {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_ALL);
            $where = [];
        }
        $out = [];
        foreach (Db::fetch('customer_servers', $where, ['order' => 'id', 'dir' => 'desc', 'limit' => 200]) as $row) {
            if ($this->actor->isCustomer() && !$this->customerOwnsCurrentWhmcsService($row)) {
                continue;
            }
            $out[] = $this->present($row, $this->actor->isCustomer());
        }
        return $out;
    }

    public function get($serverId)
    {
        $row = $this->internalRow($serverId);
        if ($this->actor->isCustomer()) {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_OWN);
            if ((int) $row['client_id'] !== (int) $this->actor->clientId
                || !$this->customerOwnsCurrentWhmcsService($row)) {
                throw new NotFoundException('That customer-owned server does not exist.');
            }
        } else {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_ALL);
        }
        return $this->present($row, $this->actor->isCustomer());
    }

    /** A single state/error/provider response never makes a server `active`. */
    public function present(array $row, $customerView = false)
    {
        $spec = Str::jsonDecode($row['requested_spec'], []);
        $out = [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'hostname' => isset($row['hostname']) ? $row['hostname'] : null,
            'region' => (string) $row['region'],
            'image' => (string) $row['image'],
            'cpu_cores' => (int) $row['cpu_cores'],
            'memory_mb' => (int) $row['memory_mb'],
            'storage_gb' => (int) $row['storage_gb'],
            'status' => (string) $row['status'],
            'provisioning_state' => (string) $row['provisioning_state'],
            'provider_state' => isset($row['provider_state']) ? $row['provider_state'] : null,
            'ipv4' => isset($row['ipv4']) ? $row['ipv4'] : null,
            'ipv6' => isset($row['ipv6']) ? $row['ipv6'] : null,
            'last_error_code' => isset($row['last_error_code']) ? $row['last_error_code'] : null,
            'last_error_message' => isset($row['last_error_message']) ? $row['last_error_message'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
        if (!$customerView) {
            $account = Db::first('provider_accounts', ['id' => (int) $row['provider_account_id']]);
            $out['client_id'] = (int) $row['client_id'];
            $out['whmcs_service_id'] = (int) $row['whmcs_service_id'];
            $out['whmcs_order_id'] = (int) $row['whmcs_order_id'];
            $out['whmcs_invoice_id'] = (int) $row['whmcs_invoice_id'];
            $out['provider_account_id'] = (int) $row['provider_account_id'];
            $out['provider_code'] = $account ? (string) $account['provider_code'] : null;
            $out['provider_server_id'] = isset($row['provider_server_id']) ? $row['provider_server_id'] : null;
            $out['provider_operation_id'] = isset($row['provider_operation_id']) ? $row['provider_operation_id'] : null;
            $out['provider_operation'] = isset($row['provider_operation']) ? $row['provider_operation'] : null;
            $out['create_job_id'] = $row['create_job_id'] ? (int) $row['create_job_id'] : null;
            $out['requested_spec'] = $spec;
            $out['pending_spec'] = Str::jsonDecode(isset($row['pending_spec']) ? $row['pending_spec'] : null, null);
            $out['requested_by'] = isset($row['requested_by']) ? $row['requested_by'] : null;
        }
        return $out;
    }

    /**
     * Customer reads require both the local binding and the current WHMCS owner
     * to agree. If a service is transferred, neither stale owner can read the
     * old binding until an operator reconciles the resource.
     */
    private function customerOwnsCurrentWhmcsService(array $row)
    {
        try {
            $service = $this->gateway->getService((int) $row['whmcs_service_id']);
        } catch (\Throwable $e) {
            throw new ConflictException('WHMCS service ownership could not be verified; VM data is unavailable.');
        }
        return $service !== null
            && isset($service['userid'])
            && (int) $service['userid'] === (int) $this->actor->clientId
            && (int) $row['client_id'] === (int) $this->actor->clientId;
    }

    /* ------------------------------------------------------------ worker API */

    /** Re-check the linked WHMCS service/order/invoice before provider create. */
    public function workerAssertProvisioningAllowed($serverId)
    {
        $this->assertWorker();
        if (!Settings::bool('customer_server_provisioning_enabled', false)) {
            throw new ProviderConfigurationException(
                'Customer-server provisioning was disabled before the queued create operation could run.'
            );
        }
        $row = $this->internalRow($serverId);
        $billing = $this->billingContext((int) $row['whmcs_service_id']);
        if ((int) $billing['client_id'] !== (int) $row['client_id']
            || (int) $billing['order_id'] !== (int) $row['whmcs_order_id']
            || (int) $billing['invoice_id'] !== (int) $row['whmcs_invoice_id']) {
            throw new PaymentException('The WHMCS service billing linkage changed after the provisioning request.');
        }
        return true;
    }

    /** Re-check WHMCS state immediately before a non-delete provider operation. */
    public function workerAssertActionAllowed($serverId, $action)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $this->assertWhmcsOperationAllowed((int) $row['whmcs_service_id'], (string) $action);
        return true;
    }

    /** Re-check WHMCS cancellation immediately before destructive provider work. */
    public function workerAssertDeleteAllowed($serverId)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $this->assertWhmcsServiceTerminated((int) $row['whmcs_service_id']);
        return true;
    }

    /** Move a create job into the provider-operation state. */
    public function workerBegin($serverId, $operation)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        if ($operation === 'create') {
            $this->setState($row, self::STATUS_PROVISIONING, self::STATE_SERVER_CREATING,
                'provider_create_started', ['provider_account_id' => (int) $row['provider_account_id']], $this->actor);
        }
        return $this->internalRow($serverId);
    }

    /** Persist only normalized, provider-sourced values. */
    public function workerApplyResource($serverId, array $resource, $operation)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $operation = (string) $operation;
        $status = (string) $row['status'];
        $state = (string) $row['provisioning_state'];

        if ($operation === 'delete' && in_array($resource['status'], ['deleted', 'not_found'], true)) {
            return $this->workerTerminate($serverId, $resource['status']);
        }
        if (ProviderResource::isFailed($resource)) {
            throw new ProviderOperationException('The provider reports that the server operation failed.', [
                'provider_state' => $resource['status'],
            ]);
        }

        if ($operation === 'delete') {
            $status = self::STATUS_TERMINATING;
            $state = self::STATE_TERMINATING;
        } elseif (ProviderResource::isReady($resource)) {
            // `server_ready` is an infrastructure milestone, not customer-visible
            // ACTIVE. The worker runs the health/security gates after this and
            // only then calls workerActivate().
            $status = self::STATUS_PROVISIONING;
            $state = self::STATE_SERVER_READY;
        } elseif ($operation === 'rebuild') {
            $status = self::STATUS_PROVISIONING;
            $state = self::STATE_REBUILDING;
        } elseif ($operation === 'resize') {
            $status = self::STATUS_PROVISIONING;
            $state = self::STATE_RESIZING;
        } else {
            $status = self::STATUS_PROVISIONING;
            $state = self::STATE_SERVER_CREATING;
        }

        $changes = [
            'provider_server_id' => $resource['id'],
            'provider_operation_id' => $resource['operation_id'],
            'provider_operation' => $operation,
            'provider_state' => $resource['status'],
            'ipv4' => $resource['ipv4'],
            'ipv6' => $resource['ipv6'],
            'last_error_code' => null,
            'last_error_message' => null,
        ];
        if (ProviderResource::isReady($resource) && in_array($operation, ['rebuild', 'resize'], true)) {
            $pending = Str::jsonDecode(isset($row['pending_spec']) ? $row['pending_spec'] : null, null);
            if (!is_array($pending)) {
                throw new ProviderOperationException('The pending server specification is missing or invalid.');
            }
            $pending = ServerSpec::normalise($pending);
            $changes = array_merge($changes, [
                'requested_spec' => Str::jsonEncode($pending),
                'pending_spec' => null,
                'name' => $pending['name'],
                'hostname' => $pending['hostname'],
                'region' => $pending['region'],
                'image' => $pending['image'],
                'cpu_cores' => $pending['cpu_cores'],
                'memory_mb' => $pending['memory_mb'],
                'storage_gb' => $pending['storage_gb'],
            ]);
        }
        $this->setState($row, $status, $state, 'provider_resource_updated', [
            'operation' => $operation,
            'provider_state' => $resource['status'],
            'provider_server_id' => $resource['id'],
        ], $this->actor, $changes);
        return $this->internalRow($serverId);
    }

    /**
     * Mark a provider-ready server customer-active once the health/security
     * gates have passed. Only the worker may activate, and only from the
     * server_ready milestone; a single provider response alone never activates.
     */
    public function workerActivate($serverId, array $gateReport)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        if ((string) $row['status'] === self::STATUS_ACTIVE
            && (string) $row['provisioning_state'] === self::STATE_SERVER_READY) {
            return $this->internalRow($serverId);
        }
        if ((string) $row['status'] !== self::STATUS_PROVISIONING
            || (string) $row['provisioning_state'] !== self::STATE_SERVER_READY) {
            throw new StateException('Only a provider-ready server can be activated.', [
                'status' => (string) $row['status'],
                'provisioning_state' => (string) $row['provisioning_state'],
            ]);
        }
        $this->setState($row, self::STATUS_ACTIVE, self::STATE_SERVER_READY, 'server_activated', [
            'gates' => $gateReport,
        ], $this->actor);
        return $this->internalRow($serverId);
    }

    public function workerTerminate($serverId, $providerState = 'deleted')
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $this->setState($row, self::STATUS_TERMINATED, self::STATE_TERMINATED,
            'provider_delete_confirmed', ['provider_state' => (string) $providerState], $this->actor, [
                'provider_state' => Str::clip((string) $providerState, 40),
                'provider_operation' => 'delete',
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
        return $this->internalRow($serverId);
    }

    /** Retryable failures leave the server provisioning; terminal ones fail it. */
    public function workerFailure($serverId, $code, $message, $terminal, $jobType = '')
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $actionOnly = in_array((string) $jobType, [
            JobQueue::TYPE_SERVER_REBOOT, JobQueue::TYPE_SERVER_POWER_ON, JobQueue::TYPE_SERVER_POWER_OFF,
        ], true);
        $resourceFailure = $terminal && !$actionOnly;
        $status = $resourceFailure ? self::STATUS_FAILED : (string) $row['status'];
        $state = $resourceFailure ? self::STATE_FAILED : (string) $row['provisioning_state'];
        $event = $terminal ? ($actionOnly ? 'provider_action_failed' : 'provider_operation_failed')
            : 'provider_operation_retrying';
        $changes = [
            'last_error_code' => Str::clip((string) $code, 60),
            'last_error_message' => Str::clip((string) $message, 1000),
        ];
        $pendingSpecOperation = in_array((string) $jobType,
            [JobQueue::TYPE_SERVER_REBUILD, JobQueue::TYPE_SERVER_RESIZE], true)
            || ((string) $jobType === JobQueue::TYPE_SERVER_POLL
                && in_array((string) $row['provider_operation'], ['rebuild', 'resize'], true));
        if ($terminal && $pendingSpecOperation) {
            $changes['pending_spec'] = null;
        }
        $this->setState($row, $status, $state, $event, [
            'error_code' => Str::clip((string) $code, 60),
            'job_type' => Str::clip((string) $jobType, 40),
            'retrying' => !$terminal,
        ], $this->actor, $changes);
        return $this->internalRow($serverId);
    }

    /** Schedule a separate, delayed polling job; the HTTP request never waits. */
    public function workerQueuePoll($serverId, $sourceJobId)
    {
        $this->assertWorker();
        $row = $this->internalRow($serverId);
        $key = 'customer-server:poll-after:' . (int) $serverId . ':' . (int) $sourceJobId;
        $existing = $this->queue->forIdempotencyKey($key);
        if ($existing) {
            return $existing;
        }
        $limit = max(1, Settings::int('provider_operation_poll_limit', 120));
        if ((int) $row['poll_count'] >= $limit) {
            throw new ProviderOperationException('The provider operation did not reach a terminal state before the polling limit. ', [
                'error_code' => 'PROVIDER_OPERATION_TIMEOUT', 'customer_server_id' => (int) $row['id'],
            ]);
        }
        $nextPoll = (int) $row['poll_count'] + 1;
        Db::update('customer_servers', ['poll_count' => $nextPoll, 'updated_at' => Clock::now()],
            ['id' => (int) $row['id']]);
        return $this->queue->enqueue(JobQueue::TYPE_SERVER_POLL, [
            'customer_server_id' => (int) $row['id'],
            'provider_account_id' => (int) $row['provider_account_id'],
            'whmcs_service_id' => (int) $row['whmcs_service_id'],
        ], [
            'queue' => JobQueue::QUEUE_PROVISIONING,
            'available_at' => Clock::at(max(1, Settings::int('provider_poll_interval_seconds', 15))),
            'idempotency_key' => $key,
            'customer_server_id' => (int) $row['id'],
            'provider_account_id' => (int) $row['provider_account_id'],
            'whmcs_service_id' => (int) $row['whmcs_service_id'],
            'client_id' => (int) $row['client_id'],
            'requested_by' => 'system:provider-poller',
            'max_attempts' => 5,
        ]);
    }

    /** @internal Worker/service boundary; never serialize this raw row to a customer. */
    public function internalRow($serverId)
    {
        $row = Db::first('customer_servers', ['id' => (int) $serverId]);
        if (!$row) {
            throw new NotFoundException('That customer-owned server does not exist.');
        }
        return $row;
    }

    /* -------------------------------------------------------------- billing */

    private function assertWhmcsServiceTerminated($serviceId)
    {
        try {
            $service = $this->gateway->getService((int) $serviceId);
        } catch (\Throwable $e) {
            throw new ConflictException('WHMCS service state could not be verified; VM deletion is blocked.');
        }
        if (!$service) {
            throw new NotFoundException('The linked WHMCS hosting service does not exist.');
        }
        $status = strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : '')));
        if (!in_array($status, ['cancelled', 'terminated'], true)) {
            throw new ConflictException('Cancel or terminate the linked WHMCS service before deleting its VM.');
        }
        return true;
    }

    /** Gate ongoing VM operations against WHMCS subscription state. */
    private function assertWhmcsOperationAllowed($serviceId, $action)
    {
        try {
            $service = $this->gateway->getService((int) $serviceId);
        } catch (\Throwable $e) {
            throw new ConflictException('WHMCS service state could not be verified; the VM operation is blocked.');
        }
        if (!$service) {
            throw new NotFoundException('The linked WHMCS hosting service does not exist.');
        }
        $status = strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : '')));
        if ($action === 'power_off') {
            return true;
        }
        if (in_array($status, ['suspended', 'cancelled', 'terminated', 'fraud', 'expired'], true)) {
            throw new ConflictException('The linked WHMCS service status does not allow this VM operation.', [
                'whmcs_service_id' => (int) $serviceId,
                'whmcs_status' => Str::clip($status, 24),
                'action' => Str::clip((string) $action, 24),
            ]);
        }
        return true;
    }

    private function assertSelfServiceMapping($serviceId, $accountId, array $spec, $mappingId)
    {
        $mapping = (new ServerProductMappingService($this->actor, $this->gateway))->forService($serviceId);
        if ($mapping['id'] !== (int) $mappingId || $mapping['provider_account_id'] !== (int) $accountId
            || $mapping['spec'] !== $spec) {
            throw new ProviderConfigurationException('Self-service product mapping changed before provisioning.');
        }
    }

    /** Reuse the existing WHMCS service/order/invoice/payment gate for operator-only adoption. */
    public function billingContextForExisting($serviceId)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_MANAGE);
        return $this->billingContext((int) $serviceId);
    }

    private function billingContext($serviceId)
    {
        try {
            $service = $this->gateway->getService($serviceId);
        } catch (\Throwable $e) {
            throw new PaymentException('The WHMCS service could not be verified.');
        }
        if (!$service) {
            throw new NotFoundException('The WHMCS hosting service does not exist.');
        }
        $clientId = isset($service['userid']) ? (int) $service['userid'] : 0;
        if ($clientId <= 0) {
            throw new PaymentException('The WHMCS service has no valid client owner.');
        }
        if ($this->actor->isCustomer()) {
            $this->actor->assertOwns($clientId);
        }
        $serviceStatus = strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : '')));
        if (in_array($serviceStatus, ['cancelled', 'terminated', 'suspended', 'fraud', 'expired'], true)) {
            throw new PaymentException('The WHMCS hosting service is not eligible for provisioning.', [
                'error_code' => 'SERVICE_NOT_ELIGIBLE', 'whmcs_service_id' => $serviceId,
            ]);
        }

        $orderId = isset($service['orderid']) ? (int) $service['orderid'] : 0;
        if ($orderId <= 0) {
            throw new PaymentException('The WHMCS service has no linked order; provisioning is blocked.');
        }
        try {
            $order = $this->gateway->getOrder($orderId);
        } catch (\Throwable $e) {
            throw new PaymentException('The WHMCS order could not be verified.');
        }
        if (!$order || !isset($order['userid']) || (int) $order['userid'] !== $clientId) {
            throw new PaymentException('The WHMCS order could not be matched to this service owner.');
        }
        $invoiceId = isset($order['invoiceid']) ? (int) $order['invoiceid'] : 0;
        if ($invoiceId <= 0) {
            throw new PaymentException('The WHMCS order has no linked invoice; provisioning is blocked.');
        }
        try {
            $invoice = $this->gateway->getInvoice($invoiceId);
        } catch (\Throwable $e) {
            throw new PaymentException('The WHMCS invoice could not be verified.');
        }
        if (!$invoice || !isset($invoice['userid']) || (int) $invoice['userid'] !== $clientId) {
            throw new PaymentException('The WHMCS invoice could not be matched to this service owner.');
        }
        try {
            (new PaymentGate($this->actor, $this->gateway))->assertPaid($invoiceId);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymentException('WHMCS payment status could not be verified.');
        }

        return [
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
        ];
    }

    /* --------------------------------------------------------------- helpers */

    private function assertSameProvisioningRequest(array $row, $providerAccountId, array $spec)
    {
        $existingSpec = Str::jsonDecode($row['requested_spec'], []);
        if ((int) $row['provider_account_id'] !== (int) $providerAccountId
            || Idempotency::fingerprint($existingSpec) !== Idempotency::fingerprint($spec)) {
            throw new ConflictException('A different provider or server specification is already bound to this WHMCS service.', [
                'whmcs_service_id' => (int) $row['whmcs_service_id'],
            ]);
        }
    }

    private function responseFor($serverId, $jobId, $replayed)
    {
        $row = $this->internalRow($serverId);
        $jobRow = $jobId > 0 ? $this->queue->find($jobId) : null;
        return [
            'server' => $this->present($row, $this->actor->isCustomer()),
            'job' => $jobRow ? $this->queue->present($jobRow) : null,
            'replayed' => (bool) $replayed,
        ];
    }

    private function setState(array $row, $status, $state, $event, array $metadata, Actor $actor, array $extra = [])
    {
        $status = (string) $status;
        $state = (string) $state;
        $fromStatus = (string) $row['status'];
        $fromState = (string) $row['provisioning_state'];
        $fields = array_merge([
            'status' => $status,
            'provisioning_state' => $state,
            'updated_at' => Clock::now(),
        ], $extra);
        Db::update('customer_servers', $fields, ['id' => (int) $row['id']]);
        $this->recordLifecycle((int) $row['id'], $event, $fromStatus, $status, $fromState, $state, $metadata, $actor);
    }

    private function recordLifecycle($serverId, $event, $fromStatus, $toStatus, $fromState, $toState,
        array $metadata, Actor $actor)
    {
        $metadata = \Ch247Apps\Core\Logger::redact($metadata);
        Db::insert('customer_server_events', [
            'customer_server_id' => (int) $serverId,
            'event' => Str::clip((string) $event, 60),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata ? Str::jsonEncode($metadata) : null,
            'actor_type' => Str::clip($actor->type, 20),
            'actor_id' => (int) $actor->actorId(),
            'actor_identity' => Str::clip($actor->identity(), 120),
            'created_at' => Clock::now(),
        ]);
        $server = $this->internalRow($serverId);
        Audit::transition($actor, Audit::CUSTOMER_SERVER_STATE_CHANGED, 'customer_server', $serverId,
            $fromStatus . ':' . $fromState, $toStatus . ':' . $toState, [
                'client_id' => (int) $server['client_id'],
                'metadata' => ['event' => $event] + $metadata,
            ]);
        Events::emit('customer_server.state_changed', [
            'customer_server_id' => (int) $serverId,
            'event' => Str::clip((string) $event, 60),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata,
        ], [
            'customer_server_id' => (int) $serverId,
            'client_id' => (int) $server['client_id'],
            'source' => 'infrastructure',
        ]);
    }

    private function assertWorker()
    {
        if (!$this->actor->isSystem()) {
            throw new AuthorizationException('This server lifecycle transition is worker-only.');
        }
    }

    private static function requireIdempotencyKey($key)
    {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 120 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new ValidationException('A valid Idempotency-Key header is required (1–120 visible characters).');
        }
        return $key;
    }

    private static function isConstraintViolation(\PDOException $e)
    {
        $state = (string) $e->getCode();
        if ($state === '23000' || $state === '19') {
            return true;
        }
        return isset($e->errorInfo[0]) && in_array((string) $e->errorInfo[0], ['23000', '19'], true);
    }
}
