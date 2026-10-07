<?php
/**
 * CloudHost247 App Cloud — installations.
 *
 * The customer-facing lifecycle: what they installed, where it runs, and what
 * they may do with it (start, stop, restart, update, back up, restore, delete).
 *
 * Two platform rules are enforced here, before anything is queued:
 *   • nothing is provisioned for an unpaid order. A paid plan leaves the
 *     installation `pending` with `awaiting_payment` until the payment webhook
 *     confirms the invoice, at which point provisioning is triggered — never from
 *     a frontend "payment succeeded" callback
 *   • an application flagged `requires_admin_approval` waits for an administrator
 *     before a worker is allowed near a server
 *
 * Every action returns a deployment record and a queued job: the API never runs
 * Docker itself, and it never reports a status the engine has not produced.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Deployments;

use Ch247Apps\Adapters\DeploymentContext;
use Ch247Apps\Catalog\ApplicationService;
use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Catalog\ManifestRepository;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;
use Ch247Apps\Domains\DomainService;
use Ch247Apps\Servers\ServerService;

class InstallationService
{
    /** @var Actor */
    private $actor;

    /** @var DeploymentService */
    private $deployments;

    /** @var JobQueue */
    private $queue;

    /** @var EnvironmentService */
    private $environment;

    /** @var DomainService */
    private $domains;

    /** @var ServerService */
    private $servers;

    public function __construct(Actor $actor = null, DeploymentService $deployments = null, JobQueue $queue = null)
    {
        $this->actor = $actor ?: Actor::system('InstallationService');
        $this->deployments = $deployments ?: new DeploymentService($this->actor);
        $this->queue = $queue ?: new JobQueue();
        $this->environment = new EnvironmentService($this->actor);
        $this->domains = new DomainService($this->actor);
        $this->servers = new ServerService($this->actor);
    }

    /* ---------------------------------------------------------------- create */

    /**
     * Create an installation (the last step of the install wizard).
     *
     * @param array $input application_id|slug, version_id|version, plan_id, server_id,
     *                     name, domain, domains[], environment[], idempotency_key,
     *                     auto_update, backup_enabled, paid, whmcs_invoice_id,
     *                     whmcs_order_id, whmcs_service_id
     * @return array{installation, deployment|null, job|null, awaiting_payment, awaiting_approval}
     */
    public function create(array $input)
    {
        Rbac::assert($this->actor, Rbac::APP_INSTALL);
        // hit() throws RateLimitException when the bucket is exhausted.
        RateLimiter::hit('install.create', $this->rateKey());

        $clientId = $this->actor->isCustomer() ? (int) $this->actor->clientId
            : (isset($input['customer_id']) ? (int) $input['customer_id'] : 0);
        if ($clientId <= 0) {
            throw new ValidationException('A customer is required to create an installation.', [
                'errors' => ['customer_id' => 'Required'],
            ]);
        }

        $key = isset($input['idempotency_key']) && $input['idempotency_key'] !== ''
            ? Str::clip((string) $input['idempotency_key'], 120)
            : 'install:' . $clientId . ':' . Str::token(10);

        $idempotent = Idempotency::run('install.create', $key, $input, function () use ($input, $clientId, $key) {
            return $this->createInstallation($input, $clientId, $key);
        });

        return $idempotent['result'] + ['replayed' => $idempotent['replayed']];
    }

    private function createInstallation(array $input, $clientId, $key)
    {
        $application = $this->resolveApplication($input);
        $version = $this->resolveVersion($application, $input);
        $manifest = ManifestRepository::forVersion((int) $version['id']);

        if ((string) $application['status'] !== ApplicationService::STATUS_PUBLISHED
            || !(int) $application['deployable']) {
            throw new StateException('That application is not available for installation.', [
                'error_code' => 'APPLICATION_NOT_PUBLISHED', 'status' => $application['status'],
            ]);
        }
        if (!$this->actor->can(Rbac::APP_VIEW_ALL) && (int) $application['requires_admin_approval'] === 1) {
            // Approved below by an administrator; the row is created so the order
            // and the customer's intent are recorded.
            $needsApproval = true;
        } else {
            $needsApproval = false;
        }
        // The application's approval requirement is copied onto the installation so
        // the payment gate can check it without re-reading the catalog row.
        $requiresApprovalColumn = $needsApproval ? 1 : 0;

        $plan = $this->resolvePlan($input, $manifest);
        $requirements = $this->requirementsFrom($manifest, $plan);
        $hostingType = isset($input['hosting_type']) ? (string) $input['hosting_type'] : null;
        $serverId = $this->resolveServer($application, $input, $requirements, $hostingType);

        $values = Validator::make($input)
            ->optional('name')->string('name', 160)
            ->optional('domain')->domain('domain')
            ->optional('auto_update', false)->boolean('auto_update')
            ->optional('backup_enabled', true)->boolean('backup_enabled')
            ->optional('whmcs_invoice_id')->integer('whmcs_invoice_id', 0)
            ->optional('whmcs_order_id')->integer('whmcs_order_id', 0)
            ->optional('whmcs_service_id')->integer('whmcs_service_id', 0)
            ->validate();

        // A customer saying "I paid" is not a payment. Only a machine actor (the
        // payment gate, after WHMCS confirms the invoice) or an administrator who
        // manages plans may mark an installation paid; everyone else waits for the
        // provider webhook.
        $trustedPayment = $this->actor->isMachine() || $this->actor->can(Rbac::PLAN_MANAGE);
        $paid = $trustedPayment && (
            !empty($input['paid'])
            || (!empty($values['whmcs_invoice_id']) && !empty($input['invoice_paid']))
            || (isset($input['payment_status']) && strtolower((string) $input['payment_status']) === 'paid')
        );
        $requiresPayment = (int) (isset($plan['price_minor']) ? $plan['price_minor'] : 0) > 0
            && empty($input['bypass_payment']);
        if ($requiresPayment && !$paid && !$this->actor->can(Rbac::PLAN_MANAGE)) {
            $paid = false;
        }

        $engine = $manifest->engine();
        $adapter = $engine === 'kubernetes' ? 'kubernetes' : ($engine === 'cpanel' ? 'cpanel' : 'docker');
        $now = Clock::now();

        $installationId = Db::insert('installations', [
            'reference' => Str::reference('APP'),
            'uuid' => Str::uuid4(),
            'customer_id' => $clientId,
            'application_id' => (int) $application['id'],
            'application_version_id' => (int) $version['id'],
            'server_id' => $serverId,
            'plan_id' => $plan ? (int) $plan['id'] : null,
            'name' => Str::clip(isset($values['name']) && $values['name'] !== ''
                ? $values['name'] : $manifest->name(), 160),
            'container_project' => DeploymentContext::projectName($clientId, (string) $application['slug']),
            'status' => InstallationState::PENDING,
            'status_changed_at' => $now,
            'adapter' => $adapter,
            'internal_port' => (int) $manifest->primaryPort(),
            'whmcs_service_id' => !empty($values['whmcs_service_id']) ? (int) $values['whmcs_service_id'] : null,
            'whmcs_order_id' => !empty($values['whmcs_order_id']) ? (int) $values['whmcs_order_id'] : null,
            'whmcs_invoice_id' => !empty($values['whmcs_invoice_id']) ? (int) $values['whmcs_invoice_id'] : null,
            'payment_status' => $paid ? 'paid' : ($requiresPayment ? 'unpaid' : 'not_required'),
            'paid_at' => $paid ? $now : null,
            'health_status' => 'unknown',
            'auto_update' => !empty($values['auto_update']) ? 1 : 0,
            'requires_approval' => $requiresApprovalColumn,
            'backup_enabled' => isset($values['backup_enabled']) ? ($values['backup_enabled'] ? 1 : 0) : 1,
            'current_version' => (string) $version['version'],
            'created_by' => $this->actor->actorId() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Environment, volumes and domains are written before anything is queued:
        // the worker must find a complete, valid configuration or fail fast.
        $environmentInput = isset($input['environment']) && is_array($input['environment'])
            ? $input['environment'] : [];
        $this->environment->provision($installationId, $manifest, $environmentInput);
        $this->createVolumes($installationId, $manifest);
        $domainInfo = $this->attachDomains($installationId, $clientId, $manifest, $input, $values);

        Db::update('installations', ['domain' => $domainInfo['primary'], 'updated_at' => Clock::now()],
            ['id' => $installationId]);

        Audit::record($this->actor, Audit::APPLICATION_INSTALLED, [
            'resource_type' => 'installation', 'resource_id' => $installationId,
            'client_id' => $clientId, 'installation_id' => $installationId, 'server_id' => $serverId,
            'metadata' => [
                'application' => $application['slug'], 'version' => $version['version'],
                'plan' => $plan ? $plan['slug'] : null, 'domain' => $domainInfo['primary'],
                'paid' => $paid, 'awaiting_approval' => $needsApproval, 'adapter' => $adapter,
            ],
        ]);
        Events::emit(Events::INSTALLATION_CREATED, [
            'application' => $application['slug'], 'version' => $version['version'],
            'reference' => Db::first('installations', ['id' => $installationId])['reference'],
        ], ['installation_id' => $installationId, 'client_id' => $clientId, 'server_id' => $serverId]);

        $result = [
            'installation' => $this->present($this->row($installationId)),
            'deployment' => null,
            'job' => null,
            'awaiting_payment' => $requiresPayment && !$paid,
            'awaiting_approval' => $needsApproval,
        ];

        if ($result['awaiting_payment']) {
            // The order link is what the payment gate looks for when the provider
            // confirms the invoice; its provisioning_triggered flag makes the
            // release exactly once.
            $result['order'] = (new \Ch247Apps\Billing\PaymentGate($this->actor))->linkOrder($installationId, [
                'invoice_id' => !empty($values['whmcs_invoice_id']) ? (int) $values['whmcs_invoice_id'] : null,
                'order_id' => !empty($values['whmcs_order_id']) ? (int) $values['whmcs_order_id'] : null,
                'service_id' => !empty($values['whmcs_service_id']) ? (int) $values['whmcs_service_id'] : null,
                'total_minor' => $plan ? (int) $plan['price_minor'] : null,
                'currency' => $plan && !empty($plan['currency']) ? $plan['currency'] : 'USD',
                'status' => \Ch247Apps\Billing\PaymentGate::STATUS_PENDING,
            ]);
            Events::emit(Events::ORDER_CREATED, [
                'installation_id' => $installationId,
                'invoice_id' => !empty($values['whmcs_invoice_id']) ? (int) $values['whmcs_invoice_id'] : null,
                'total_minor' => $plan ? (int) $plan['price_minor'] : null,
            ], ['installation_id' => $installationId, 'client_id' => $clientId]);
            Logger::info('Installation created, waiting for payment confirmation.', [
                'installation_id' => $installationId, 'client_id' => $clientId,
                'invoice_id' => isset($values['whmcs_invoice_id']) ? (int) $values['whmcs_invoice_id'] : null,
                'source' => 'deployments',
            ]);
            return $result;
        }
        if ($needsApproval) {
            Logger::info('Installation created, waiting for administrator approval.', [
                'installation_id' => $installationId, 'client_id' => $clientId, 'source' => 'deployments',
            ]);
            return $result;
        }

        return $result + $this->provision($installationId, $key);
    }

    /**
     * Reserve capacity and queue the install.
     *
     * Called immediately for free/approved installations and later by the payment
     * gate — never from a browser.
     */
    public function provision($installationId, $idempotencyKey = null)
    {
        $installation = $this->row($installationId);
        if (in_array((string) $installation['status'], [InstallationState::QUEUED, InstallationState::DEPLOYING,
            InstallationState::HEALTHY, InstallationState::UNHEALTHY], true)) {
            // Already provisioned: idempotent by design.
            $latest = $this->deployments->latestFor((int) $installation['id'], DeploymentService::ACTION_INSTALL);
            return ['deployment' => $latest ? $this->deployments->present($latest) : null,
                'job' => $latest && $latest['job_id'] ? $this->queue->present($this->queue->row((int) $latest['job_id'])) : null,
                'already_provisioned' => true];
        }

        $manifest = ManifestRepository::forVersion((int) $installation['application_version_id']);
        $plan = $installation['plan_id'] ? Db::first('plans', ['id' => (int) $installation['plan_id']]) : [];
        $requirements = $this->requirementsFrom($manifest, $plan);
        $serverId = (int) $installation['server_id'];

        $reserved = $this->servers->allocate($serverId, $requirements);

        $key = $idempotencyKey !== null && $idempotencyKey !== ''
            ? 'deploy:install:' . $idempotencyKey
            : 'deploy:install:' . (int) $installation['id'] . ':' . Str::token(6);
        $deployment = $this->deployments->create((int) $installation['id'], DeploymentService::ACTION_INSTALL, [
            'idempotency_key' => $key,
            'adapter' => $installation['adapter'],
            'payload' => ['requirements' => $requirements, 'manifest_hash' => $manifest->hash(),
                'version' => $installation['current_version']],
        ]);

        $job = $this->queue->enqueue(JobQueue::TYPE_INSTALL, [
            'deployment_id' => (int) $deployment['id'],
            'installation_id' => (int) $installation['id'],
            'action' => DeploymentService::ACTION_INSTALL,
        ], [
            'idempotency_key' => $key,
            'installation_id' => (int) $installation['id'],
            'deployment_id' => (int) $deployment['id'],
            'server_id' => $serverId,
            'client_id' => (int) $installation['customer_id'],
            'priority' => JobQueue::PRIORITY_HIGH,
            'requested_by' => $this->actor->identity(),
        ]);
        Db::update('deployments', ['job_id' => (int) $job['id']], ['id' => (int) $deployment['id']]);

        InstallationState::apply((int) $installation['id'], InstallationState::QUEUED, [], $this->actor,
            'Deployment queued');

        return ['deployment' => $deployment, 'job' => $job, 'capacity' => $reserved];
    }

    /** Payment confirmed server-side: provision what the customer paid for. */
    public function markPaid($installationId, array $billing = [])
    {
        $installation = $this->row($installationId);
        $now = Clock::now();
        Db::update('installations', [
            'payment_status' => 'paid',
            'paid_at' => $now,
            'whmcs_invoice_id' => !empty($billing['invoice_id']) ? (int) $billing['invoice_id']
                : $installation['whmcs_invoice_id'],
            'whmcs_order_id' => !empty($billing['order_id']) ? (int) $billing['order_id']
                : $installation['whmcs_order_id'],
            'whmcs_service_id' => !empty($billing['service_id']) ? (int) $billing['service_id']
                : $installation['whmcs_service_id'],
            'updated_at' => $now,
        ], ['id' => (int) $installation['id']]);

        Audit::record($this->actor, Audit::PAYMENT_CONFIRMED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['invoice_id' => isset($billing['invoice_id']) ? (int) $billing['invoice_id'] : null,
                'amount' => isset($billing['amount']) ? $billing['amount'] : null],
        ]);

        if ((int) $installation['requires_approval'] === 1) {
            return ['provisioned' => false, 'awaiting_approval' => true];
        }
        $result = $this->provision((int) $installation['id'], 'paid:' . (int) $installation['id']);
        return ['provisioned' => true] + $result;
    }

    /** An administrator releases an installation that needed approval. */
    public function approve($installationId, $note = '')
    {
        Rbac::assert($this->actor, Rbac::APP_APPROVE);
        $installation = $this->row($installationId);
        if ((string) $installation['status'] !== InstallationState::PENDING) {
            throw new StateException('Only a pending installation can be approved.', [
                'status' => $installation['status'],
            ]);
        }
        if ((string) $installation['payment_status'] === 'unpaid') {
            throw new StateException('That installation has not been paid for yet.', [
                'error_code' => 'PAYMENT_NOT_CONFIRMED',
            ]);
        }
        Db::update('installations', ['requires_approval' => 0, 'notes' => Str::clip($note, 1000),
            'updated_at' => Clock::now()], ['id' => (int) $installation['id']]);
        Audit::record($this->actor, Audit::APPLICATION_INSTALLED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['action' => 'approved', 'note' => Str::clip($note, 200)],
        ]);
        return ['provisioned' => true] + $this->provision((int) $installation['id'],
            'approved:' . (int) $installation['id']);
    }

    /* --------------------------------------------------------------- actions */

    /**
     * Ask for a lifecycle action. Returns the deployment and the queued job; the
     * caller (and the customer) sees progress from those, never from a guess.
     *
     * @param array $options idempotency_key, version_id, remove_data, reason
     */
    public function requestAction($installationId, $action, array $options = [])
    {
        $action = strtolower((string) $action);
        $installation = $this->row($installationId);
        $this->assertCanManage($installation, $action);

        $permission = $this->permissionFor($action);
        Rbac::assert($this->actor, $permission);

        $status = (string) $installation['status'];
        if (in_array($status, [InstallationState::PENDING, InstallationState::QUEUED], true)
            && !in_array($action, [DeploymentService::ACTION_UNINSTALL], true)) {
            throw new StateException('That installation has not been deployed yet.', [
                'error_code' => 'INSTALLATION_NOT_DEPLOYED', 'status' => $status,
            ]);
        }
        if (in_array($status, [InstallationState::DELETED, InstallationState::TERMINATED], true)) {
            throw new StateException('That installation no longer exists.', ['status' => $status]);
        }
        if (in_array($status, [InstallationState::DEPLOYING, InstallationState::UPDATING,
            InstallationState::DELETING, InstallationState::STARTING], true)
            && $action !== DeploymentService::ACTION_HEALTHCHECK) {
            throw new StateException('Another operation is already running for that installation.', [
                'error_code' => 'INSTALLATION_BUSY', 'status' => $status,
            ]);
        }
        if ($action === DeploymentService::ACTION_RESTART && InstallationState::circuitOpen($installation)) {
            throw new StateException(
                'This application has been restarted too many times and automatic recovery is paused. '
                . 'Support has been notified.',
                ['error_code' => 'CIRCUIT_BREAKER_OPEN', 'restarts' => (int) $installation['restart_count']]
            );
        }
        if ($action === DeploymentService::ACTION_STOP && $status === InstallationState::STOPPED) {
            throw new StateException('That installation is already stopped.', ['status' => $status]);
        }
        if ($action === DeploymentService::ACTION_START && in_array($status, InstallationState::LIVE, true)) {
            throw new StateException('That installation is already running.', ['status' => $status]);
        }

        $jobType = $this->jobTypeFor($action);
        if ($action === DeploymentService::ACTION_UPDATE) {
            $this->assertUpdateAllowed($installation, $options);
        }

        $key = isset($options['idempotency_key']) && $options['idempotency_key'] !== ''
            ? Str::clip((string) $options['idempotency_key'], 120)
            : $action . ':' . (int) $installation['id'] . ':' . Str::token(6);

        $deployment = $this->deployments->create((int) $installation['id'], $action, [
            'idempotency_key' => $key,
            'adapter' => $installation['adapter'],
            'payload' => array_merge($options, ['reference' => $installation['reference']]),
        ]);
        $job = $this->queue->enqueue($jobType, [
            'deployment_id' => (int) $deployment['id'],
            'installation_id' => (int) $installation['id'],
            'action' => $action,
        ], [
            'idempotency_key' => $key,
            'installation_id' => (int) $installation['id'],
            'deployment_id' => (int) $deployment['id'],
            'server_id' => $installation['server_id'] ? (int) $installation['server_id'] : null,
            'client_id' => (int) $installation['customer_id'],
            'priority' => $action === DeploymentService::ACTION_UNINSTALL
                ? JobQueue::PRIORITY_NORMAL : JobQueue::PRIORITY_HIGH,
            'requested_by' => $this->actor->identity(),
        ]);
        Db::update('deployments', ['job_id' => (int) $job['id']], ['id' => (int) $deployment['id']]);

        $auditAction = $this->auditActionFor($action);
        if ($auditAction !== null) {
            Audit::record($this->actor, $auditAction, [
                'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
                'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
                'server_id' => $installation['server_id'] ? (int) $installation['server_id'] : null,
                'deployment_id' => (int) $deployment['id'],
                'metadata' => ['action' => $action, 'reference' => $installation['reference']],
            ]);
        }

        return ['deployment' => $deployment, 'job' => $job,
            'installation' => $this->present($this->row((int) $installation['id']))];
    }

    public function start($installationId, array $options = [])
    {
        return $this->requestAction($installationId, DeploymentService::ACTION_START, $options);
    }

    public function stop($installationId, array $options = [])
    {
        return $this->requestAction($installationId, DeploymentService::ACTION_STOP, $options);
    }

    public function restart($installationId, array $options = [])
    {
        $result = $this->requestAction($installationId, DeploymentService::ACTION_RESTART, $options);
        InstallationState::registerRestart((int) $installationId, 'Customer requested a restart', $this->actor);
        return $result;
    }

    public function update($installationId, array $options = [])
    {
        return $this->requestAction($installationId, DeploymentService::ACTION_UPDATE, $options);
    }

    /** Delete an installation. Data removal is explicit and irreversible. */
    public function delete($installationId, $removeData = true, $reason = '')
    {
        $options = ['remove_data' => (bool) $removeData, 'reason' => Str::clip($reason, 200)];
        return $this->requestAction($installationId, DeploymentService::ACTION_UNINSTALL, $options);
    }

    /* --------------------------------------------------------------- suspend */

    /** Billing/admin suspension: stop the workload, keep the data. */
    public function suspend($installationId, $reason = '')
    {
        if (!$this->actor->isMachine()) {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SUSPEND);
        }
        $installation = $this->row($installationId);
        if ((string) $installation['status'] === InstallationState::SUSPENDED) {
            return $this->present($installation);
        }
        $result = ['deployment' => null, 'job' => null];
        if (in_array((string) $installation['status'], InstallationState::PROVISIONED, true)) {
            try {
                $result = $this->requestAction((int) $installation['id'], DeploymentService::ACTION_STOP, [
                    'reason' => 'suspended: ' . Str::clip($reason, 150),
                ]);
            } catch (StateException $e) {
                Logger::warning('A suspended installation could not be stopped.', [
                    'installation_id' => (int) $installation['id'], 'error' => $e->getMessage(),
                    'source' => 'deployments',
                ]);
            }
        }
        InstallationState::apply((int) $installation['id'], InstallationState::SUSPENDED, [], $this->actor,
            $reason !== '' ? $reason : 'Suspended');
        Audit::record($this->actor, Audit::INSTALLATION_SUSPENDED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['reason' => Str::clip($reason, 200)], 'severity' => 'warning',
        ]);
        return $this->present($this->row((int) $installation['id'])) + $result;
    }

    /** Lift a suspension and start the workload again. */
    public function restore($installationId, $reason = '')
    {
        if (!$this->actor->isMachine()) {
            Rbac::assert($this->actor, Rbac::CUSTOMER_SUSPEND);
        }
        $installation = $this->row($installationId);
        if ((string) $installation['status'] !== InstallationState::SUSPENDED) {
            throw new StateException('That installation is not suspended.', ['status' => $installation['status']]);
        }
        $result = $this->requestAction((int) $installation['id'], DeploymentService::ACTION_START, [
            'reason' => 'restored: ' . Str::clip($reason, 150),
        ]);
        Audit::record($this->actor, Audit::INSTALLATION_RESTORED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['reason' => Str::clip($reason, 200)],
        ]);
        return $this->present($this->row((int) $installation['id'])) + $result;
    }

    /** Terminate: nothing left to run, data removal follows the retention policy. */
    public function terminate($installationId, $reason = '')
    {
        if (!$this->actor->isMachine()) {
            Rbac::assert($this->actor, Rbac::CUSTOMER_MANAGE);
        }
        $installation = $this->row($installationId);
        $result = $this->delete((int) $installation['id'], true, $reason !== '' ? $reason : 'Terminated');
        Db::update('installations', ['terminated_at' => Clock::now(), 'updated_at' => Clock::now()],
            ['id' => (int) $installation['id']]);
        Audit::record($this->actor, Audit::CUSTOMER_TERMINATED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['reason' => Str::clip($reason, 200)], 'severity' => 'warning',
        ]);
        return $result;
    }

    /* ---------------------------------------------------------------- update */

    /** Editable settings that do not need a deployment. */
    public function updateSettings($installationId, array $input)
    {
        $installation = $this->assertOwns($installationId, Rbac::INSTALL_SETTINGS_WRITE);
        $fields = [];
        if (array_key_exists('name', $input)) {
            $fields['name'] = Str::clip((string) $input['name'], 160);
        }
        if (array_key_exists('auto_update', $input)) {
            $fields['auto_update'] = $this->truthy($input['auto_update']) ? 1 : 0;
        }
        if (array_key_exists('backup_enabled', $input)) {
            $fields['backup_enabled'] = $this->truthy($input['backup_enabled']) ? 1 : 0;
        }
        if (array_key_exists('notes', $input)) {
            $fields['notes'] = Str::clip((string) $input['notes'], 2000);
        }
        if ($fields === []) {
            return $this->present($installation);
        }
        $fields['updated_at'] = Clock::now();
        Db::update('installations', $fields, ['id' => (int) $installation['id']]);
        Audit::record($this->actor, Audit::APPLICATION_UPDATED, [
            'resource_type' => 'installation', 'resource_id' => (int) $installation['id'],
            'client_id' => (int) $installation['customer_id'], 'installation_id' => (int) $installation['id'],
            'metadata' => ['changed' => array_keys($fields)],
        ]);
        return $this->present($this->row((int) $installation['id']));
    }

    /* ------------------------------------------------------------------ read */

    /** @throws NotFoundException */
    public function row($installationId)
    {
        $row = Db::first('installations', ['id' => (int) $installationId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That installation does not exist.');
        }
        return $row;
    }

    public function listing(array $filters = [], $limit = 50, $offset = 0)
    {
        $where = ['deleted_at' => null];
        $isAdmin = $this->actor->can(Rbac::APP_VIEW_ALL);
        if (!$isAdmin) {
            Rbac::assert($this->actor, Rbac::INSTALL_VIEW_OWN);
            $where['customer_id'] = (int) $this->actor->clientId;
        } elseif (!empty($filters['customer_id'])) {
            $where['customer_id'] = (int) $filters['customer_id'];
        }
        foreach (['status', 'adapter', 'server_id', 'application_id', 'health_status'] as $key) {
            if (!empty($filters[$key])) {
                $where[$key] = is_int($filters[$key]) ? (int) $filters[$key] : (string) $filters[$key];
            }
        }
        if (!empty($filters['q'])) {
            $search = '%' . strtolower(trim((string) $filters['q'])) . '%';
            $where['name'] = ['like', $search];
        }

        $rows = Db::fetch('installations', $where, [
            'order' => !empty($filters['order']) ? (string) $filters['order'] : 'id',
            'dir' => !empty($filters['dir']) ? (string) $filters['dir'] : 'desc',
            'limit' => max(1, min(200, (int) $limit)), 'offset' => max(0, (int) $offset),
        ]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function present(array $row)
    {
        $application = Db::first('applications', ['id' => (int) $row['application_id']]);
        $version = Db::first('application_versions', ['id' => (int) $row['application_version_id']]);
        $server = $row['server_id'] ? Db::first('servers', ['id' => (int) $row['server_id']]) : null;
        $plan = $row['plan_id'] ? Db::first('plans', ['id' => (int) $row['plan_id']]) : null;
        $latest = $this->deployments->latestFor((int) $row['id']);
        $domains = $this->domains->forInstallation((int) $row['id']);
        $primary = null;
        foreach ($domains as $domain) {
            if ($domain['primary']) {
                $primary = $domain['domain'];
                break;
            }
        }

        return [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'status' => $row['status'],
            'previous_status' => isset($row['previous_status']) ? $row['previous_status'] : null,
            'status_changed_at' => isset($row['status_changed_at']) ? $row['status_changed_at'] : null,
            'health_status' => $row['health_status'],
            'health_message' => isset($row['health_message']) ? $row['health_message'] : null,
            'health_checked_at' => isset($row['health_checked_at']) ? $row['health_checked_at'] : null,
            'customer_id' => (int) $row['customer_id'],
            'application' => $application ? [
                'id' => (int) $application['id'], 'slug' => $application['slug'],
                'name' => $application['name'], 'icon' => isset($application['icon_url'])
                    ? $application['icon_url'] : null,
                'category' => isset($application['category']) ? $application['category'] : null,
            ] : null,
            'version' => $version ? ['id' => (int) $version['id'], 'version' => $version['version'],
                'channel' => isset($version['channel']) ? $version['channel'] : null] : null,
            'current_version' => isset($row['current_version']) ? $row['current_version'] : null,
            'available_version' => isset($row['available_version']) ? $row['available_version'] : null,
            'update_available' => !empty($row['available_version']) && $row['available_version'] !== $row['current_version'],
            'auto_update' => (bool) $row['auto_update'],
            'server' => $server ? ['id' => (int) $server['id'], 'name' => $server['name'],
                'hostname' => $server['hostname'], 'region' => $server['region'],
                'server_type' => $server['server_type']] : null,
            'plan' => $plan ? ['id' => (int) $plan['id'], 'name' => $plan['name'], 'slug' => $plan['slug']] : null,
            'adapter' => $row['adapter'],
            'container_project' => $row['container_project'],
            'internal_port' => $row['internal_port'] ? (int) $row['internal_port'] : null,
            'domain' => $primary !== null ? $primary : (isset($row['domain']) ? $row['domain'] : null),
            'domains' => $domains,
            'access_url' => isset($row['access_url']) && $row['access_url'] !== '' ? $row['access_url']
                : ($primary !== null ? 'https://' . $primary : null),
            'ssl_status' => $row['ssl_status'],
            'payment_status' => $row['payment_status'],
            'paid_at' => isset($row['paid_at']) ? $row['paid_at'] : null,
            'whmcs_service_id' => $row['whmcs_service_id'] ? (int) $row['whmcs_service_id'] : null,
            'whmcs_invoice_id' => $row['whmcs_invoice_id'] ? (int) $row['whmcs_invoice_id'] : null,
            'backup_enabled' => (bool) $row['backup_enabled'],
            'last_backup_at' => isset($row['last_backup_at']) ? $row['last_backup_at'] : null,
            'restart_count' => (int) $row['restart_count'],
            'circuit_state' => $row['circuit_state'],
            'installed_at' => isset($row['installed_at']) ? $row['installed_at'] : null,
            'latest_deployment' => $latest ? [
                'id' => (int) $latest['id'], 'reference' => $latest['reference'],
                'action' => $latest['action'], 'status' => $latest['status'],
                'progress' => (int) $latest['progress'], 'current_step' => $latest['current_step'],
                'error_code' => isset($latest['error_code']) ? $latest['error_code'] : null,
                'error_message' => isset($latest['error_message']) ? $latest['error_message'] : null,
            ] : null,
            'actions' => $this->allowedActions($row),
            'created_at' => $row['created_at'],
        ];
    }

    /** Which actions the current actor may ask for right now. */
    public function allowedActions(array $row)
    {
        $status = (string) $row['status'];
        $out = [];
        $busy = in_array($status, [InstallationState::DEPLOYING, InstallationState::UPDATING,
            InstallationState::STARTING, InstallationState::DELETING, InstallationState::QUEUED], true);
        $gone = in_array($status, [InstallationState::DELETED, InstallationState::TERMINATED], true);
        if ($gone) {
            return $out;
        }
        $provisioned = in_array($status, InstallationState::PROVISIONED, true);

        $out['start'] = $provisioned && $status === InstallationState::STOPPED && !$busy
            && $this->actor->can(Rbac::INSTALL_START);
        $out['stop'] = $provisioned && $status !== InstallationState::STOPPED && !$busy
            && $this->actor->can(Rbac::INSTALL_STOP);
        $out['restart'] = $provisioned && !$busy && InstallationState::circuitOpen($row) === false
            && $this->actor->can(Rbac::INSTALL_RESTART);
        $out['update'] = $provisioned && !$busy && $this->actor->can(Rbac::INSTALL_UPDATE)
            && !empty($row['available_version']) && $row['available_version'] !== $row['current_version'];
        $out['delete'] = !$busy && $this->actor->can(Rbac::INSTALL_DELETE);
        $out['backup'] = $provisioned && !$busy && $this->actor->can(Rbac::INSTALL_BACKUP);
        $out['restore'] = $provisioned && !$busy && $this->actor->can(Rbac::INSTALL_RESTORE);
        $out['logs'] = $this->actor->can(Rbac::INSTALL_LOGS_VIEW);
        $out['metrics'] = $this->actor->can(Rbac::INSTALL_METRICS_VIEW);
        $out['environment'] = $this->actor->can(Rbac::INSTALL_ENV_READ);
        $out['domains'] = $this->actor->can(Rbac::INSTALL_DOMAIN_MANAGE);
        $out['ssl'] = $this->actor->can(Rbac::INSTALL_SSL_MANAGE);
        return $out;
    }

    public function detail($installationId)
    {
        $row = $this->row($installationId);
        $this->assertCanSee($row);
        $detail = $this->present($row);
        $detail['manifest'] = null;
        try {
            $manifest = ManifestRepository::forVersion((int) $row['application_version_id']);
            $detail['manifest'] = [
                'id' => $manifest->id(),
                'version' => $manifest->version(),
                'hash' => $manifest->hash(),
                'requirements' => $manifest->requirements(),
                'services' => array_map(function ($name, $service) {
                    return ['name' => $name, 'image' => $service['image'], 'port' => (int) $service['port'],
                        'primary' => !empty($service['primary'])];
                }, array_keys($manifest->services()), $manifest->services()),
                'healthcheck' => $manifest->healthcheck(),
                'backup' => $manifest->backup(),
                'update' => $manifest->update(),
                'environment' => array_map(function ($entry) {
                    return ['key' => $entry['key'], 'required' => $entry['required'],
                        'secret' => $entry['secret'], 'description' => $entry['description']];
                }, $this->manifestEnvironmentSummary($manifest)),
            ];
        } catch (\Throwable $e) {
            $detail['manifest_error'] = $e->getMessage();
        }
        $detail['environment'] = $this->environment->listing((int) $row['id']);
        $detail['volumes'] = Db::fetch('volumes', ['installation_id' => (int) $row['id']], ['order' => 'name']);
        $detail['deployments'] = [];
        foreach (Db::fetch('deployments', ['installation_id' => (int) $row['id']],
            ['order' => 'id', 'dir' => 'desc', 'limit' => 20]) as $deployment) {
            $detail['deployments'][] = $this->deployments->present($deployment);
        }
        $detail['jobs'] = $this->queue->pendingFor((int) $row['id']);
        $detail['resources'] = [];
        foreach ($this->deployments->liveResources((int) $row['id']) as $resource) {
            $detail['resources'][] = ['type' => $resource['resource_type'], 'name' => $resource['resource_name'],
                'status' => $resource['status'], 'created_at' => $resource['created_at']];
        }
        return $detail;
    }

    private function manifestEnvironmentSummary(Manifest $manifest)
    {
        $environment = $manifest->environment();
        $out = [];
        foreach (['required', 'optional'] as $bucket) {
            foreach ($environment[$bucket] as $key => $entry) {
                $out[] = ['key' => $key, 'required' => $bucket === 'required',
                    'secret' => !empty($entry['secret']),
                    'description' => isset($entry['description']) ? $entry['description'] : null];
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ internals */

    private function resolveApplication(array $input)
    {
        $application = null;
        if (!empty($input['application_id'])) {
            $application = Db::first('applications', ['id' => (int) $input['application_id'], 'deleted_at' => null]);
        } elseif (!empty($input['slug'])) {
            $application = Db::first('applications', ['slug' => Str::slug((string) $input['slug'], 100),
                'deleted_at' => null]);
        }
        if (!$application) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        return $application;
    }

    private function resolveVersion(array $application, array $input)
    {
        $where = ['application_id' => (int) $application['id']];
        $versionId = !empty($input['version_id']) ? $input['version_id']
            : (!empty($input['application_version_id']) ? $input['application_version_id'] : null);
        if ($versionId !== null) {
            $where['id'] = (int) $versionId;
        } elseif (!empty($input['version'])) {
            $where['version'] = (string) $input['version'];
        } else {
            $where['is_latest'] = 1;
            $where['status'] = 'published';
        }
        $version = Db::first('application_versions', $where);
        if (!$version) {
            throw new NotFoundException('That application version is not available.');
        }
        // A version can only be installed once it has passed every publication gate.
        if ((string) $version['status'] !== 'published' && !$this->actor->can(Rbac::APP_TEST_DEPLOY)) {
            throw new StateException('That version has not been published yet.', [
                'error_code' => 'VERSION_NOT_PUBLISHED', 'status' => $version['status'],
            ]);
        }
        // The seven publication gates live in the version's validation report; a
        // customer may only install a version that has passed all of them.
        $report = Str::jsonDecode(isset($version['validation_report']) ? $version['validation_report'] : null, []);
        $missing = [];
        foreach (['manifest', 'security', 'deployment', 'health_check', 'backup', 'update', 'uninstall'] as $gate) {
            if (empty($report[$gate]) || empty($report[$gate]['passed'])) {
                $missing[] = $gate;
            }
        }
        if ($missing !== [] && !$this->actor->can(Rbac::APP_TEST_DEPLOY)) {
            throw new StateException('That version has not passed its publication gates yet: '
                . implode(', ', $missing) . '.', [
                'error_code' => 'VERSION_GATES_INCOMPLETE', 'missing' => $missing,
            ]);
        }
        return $version;
    }

    private function resolvePlan(array $input, Manifest $manifest)
    {
        if (empty($input['plan_id'])) {
            return null;
        }
        $plan = Db::first('plans', ['id' => (int) $input['plan_id'], 'deleted_at' => null]);
        if (!$plan) {
            throw new NotFoundException('That plan does not exist.');
        }
        if (!(int) $plan['active']) {
            throw new StateException('That plan is no longer offered.', ['error_code' => 'PLAN_INACTIVE']);
        }
        $requirements = $manifest->requirements();
        if ((int) $plan['cpu_millicores'] < (int) $requirements['cpu_min_millicores']
            || (int) $plan['memory_mb'] < (int) $requirements['memory_min_mb']
            || (int) $plan['storage_mb'] < (int) $requirements['storage_min_mb']) {
            throw new ValidationException('That plan does not meet this application\'s minimum requirements.', [
                'errors' => ['plan_id' => 'Requires at least '
                    . $requirements['cpu_min'] . ' CPU core(s), '
                    . (int) $requirements['memory_min_mb'] . 'MB memory and '
                    . (int) $requirements['storage_min_mb'] . 'MB storage.'],
                'error_code' => 'PLAN_BELOW_MINIMUM_REQUIREMENTS',
                'requirements' => [
                    'cpu_min_millicores' => (int) $requirements['cpu_min_millicores'],
                    'memory_min_mb' => (int) $requirements['memory_min_mb'],
                    'storage_min_mb' => (int) $requirements['storage_min_mb'],
                ],
            ]);
        }
        $types = Str::jsonDecode(isset($plan['deployment_types']) ? $plan['deployment_types'] : null, []);
        if ($types !== [] && !in_array($manifest->engine(), $types, true)) {
            throw new ValidationException('That plan does not support ' . $manifest->engine() . ' deployments.', [
                'errors' => ['plan_id' => 'Unsupported deployment engine'],
            ]);
        }
        return $plan;
    }

    private function requirementsFrom(Manifest $manifest, $plan)
    {
        $requirements = $manifest->requirements();
        return [
            'cpu_millicores' => max((int) ($plan ? $plan['cpu_millicores'] : 0),
                (int) $requirements['cpu_min_millicores']),
            'memory_mb' => max((int) ($plan ? $plan['memory_mb'] : 0), (int) $requirements['memory_min_mb']),
            'storage_mb' => max((int) ($plan ? $plan['storage_mb'] : 0), (int) $requirements['storage_min_mb']),
        ];
    }

    /**
     * Choose the server: the customer's choice when it is compatible, otherwise
     * the best eligible one. Never a server that cannot run the workload.
     */
    private function resolveServer(array $application, array $input, array $requirements, $hostingType)
    {
        $candidates = $this->servers->candidatesFor((int) $application['id'], $requirements, $hostingType);
        if (!empty($input['server_id'])) {
            $chosen = (int) $input['server_id'];
            foreach ($candidates as $candidate) {
                if ((int) $candidate['id'] === $chosen) {
                    if (!$candidate['eligible']) {
                        throw new StateException('That server cannot host this application: '
                            . implode(', ', $candidate['reasons']) . '.', [
                            'error_code' => 'SERVER_NOT_ELIGIBLE', 'reasons' => $candidate['reasons'],
                        ]);
                    }
                    return $chosen;
                }
            }
            throw new NotFoundException('That server is not available for this application.');
        }
        foreach ($candidates as $candidate) {
            if ($candidate['eligible']) {
                return (int) $candidate['id'];
            }
        }
        throw new StateException('No server can host this application right now.', [
            'error_code' => 'NO_ELIGIBLE_SERVER',
        ]);
    }

    private function createVolumes($installationId, Manifest $manifest)
    {
        $now = Clock::now();
        foreach ($manifest->volumes() as $name => $volume) {
            $existing = Db::first('volumes', ['installation_id' => (int) $installationId, 'name' => (string) $name]);
            $fields = [
                'installation_id' => (int) $installationId,
                'name' => Str::clip((string) $name, 160),
                'service' => isset($volume['service']) ? Str::clip((string) $volume['service'], 80) : null,
                'mount_path' => Str::clip((string) $volume['mount'], 255),
                'driver' => isset($volume['driver']) && $volume['driver'] !== '' ? $volume['driver'] : 'local',
                'size_mb' => (int) $volume['size_mb'],
                'included_in_backup' => $volume['backup'] === false ? 0 : 1,
                'updated_at' => $now,
            ];
            if ($existing) {
                Db::update('volumes', $fields, ['id' => (int) $existing['id']]);
            } else {
                $fields['created_at'] = $now;
                Db::insert('volumes', $fields);
            }
        }
    }

    private function attachDomains($installationId, $clientId, Manifest $manifest, array $input, array $values)
    {
        $domainConfig = $manifest->domain();
        $names = [];
        if (!empty($values['domain'])) {
            $names[] = (string) $values['domain'];
        }
        if (isset($input['domains']) && is_array($input['domains'])) {
            foreach ($input['domains'] as $domain) {
                $name = is_array($domain) ? (isset($domain['domain']) ? (string) $domain['domain'] : '')
                    : (string) $domain;
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        $primary = null;
        $attached = 0;
        foreach ($names as $index => $name) {
            $domain = $this->domains->findOrCreate($clientId, $name);
            $this->domains->attach((int) $installationId, (int) $domain['id'], $index === 0);
            if ($index === 0) {
                $primary = $domain['domain'];
            }
            $attached++;
        }

        if ($primary === null && !empty($domainConfig['enabled'])) {
            // No customer domain: fall back to a platform subdomain when the
            // platform offers one, otherwise the installation is unreachable and
            // the manifest's `required` flag decides whether that is an error.
            $subdomain = $this->domains->platformSubdomain($clientId,
                isset($input['slug']) ? $input['slug'] : 'app');
            if ($subdomain) {
                $this->domains->attach((int) $installationId, (int) $subdomain['id'], true);
                $primary = $subdomain['domain'];
                $attached++;
            } elseif (!empty($domainConfig['required'])) {
                throw new ValidationException('This application requires a domain.', [
                    'errors' => ['domain' => 'Required by the application manifest'],
                ]);
            }
        }

        return ['primary' => $primary, 'attached' => $attached, 'domains' => $names];
    }

    private function assertUpdateAllowed(array $installation, array $options)
    {
        $manifest = ManifestRepository::forVersion((int) $installation['application_version_id']);
        $strategy = $manifest->updateStrategy();
        if ($strategy === 'manual' && empty($options['force'])) {
            throw new StateException('This application only supports manual updates.', [
                'error_code' => 'UPDATE_STRATEGY_MANUAL',
            ]);
        }
        $target = null;
        if (!empty($options['version_id'])) {
            $target = Db::first('application_versions', ['id' => (int) $options['version_id']]);
        } elseif (!empty($installation['available_version'])) {
            $target = Db::first('application_versions', [
                'application_id' => (int) $installation['application_id'],
                'version' => (string) $installation['available_version'], 'status' => 'published',
            ]);
        } else {
            $target = Db::first('application_versions', [
                'application_id' => (int) $installation['application_id'], 'is_latest' => 1, 'status' => 'published',
            ], ['order' => 'id', 'dir' => 'desc']);
        }
        if (!$target) {
            throw new NotFoundException('There is no published version to update to.');
        }
        if ((int) $target['id'] === (int) $installation['application_version_id']) {
            throw new StateException('That installation is already on the newest published version.', [
                'error_code' => 'ALREADY_UP_TO_DATE', 'version' => $target['version'],
            ]);
        }
        if (!$manifest->backup()['enabled'] && empty($options['skip_backup'])) {
            Logger::warning('Updating an application whose manifest declares no backup support.', [
                'installation_id' => (int) $installation['id'], 'source' => 'deployments',
            ]);
        }
        Db::update('installations', ['available_version' => (string) $target['version'],
            'updated_at' => Clock::now()], ['id' => (int) $installation['id']]);
        return $target;
    }

    private function permissionFor($action)
    {
        $map = [
            DeploymentService::ACTION_START => Rbac::INSTALL_START,
            DeploymentService::ACTION_STOP => Rbac::INSTALL_STOP,
            DeploymentService::ACTION_RESTART => Rbac::INSTALL_RESTART,
            DeploymentService::ACTION_UPDATE => Rbac::INSTALL_UPDATE,
            DeploymentService::ACTION_BACKUP => Rbac::INSTALL_BACKUP,
            DeploymentService::ACTION_RESTORE => Rbac::INSTALL_RESTORE,
            DeploymentService::ACTION_UNINSTALL => Rbac::INSTALL_DELETE,
            DeploymentService::ACTION_SSL_PROVISION => Rbac::INSTALL_SSL_MANAGE,
            DeploymentService::ACTION_DOMAIN_CONFIGURE => Rbac::INSTALL_DOMAIN_MANAGE,
            DeploymentService::ACTION_HEALTHCHECK => Rbac::INSTALL_VIEW_OWN,
        ];
        return isset($map[$action]) ? $map[$action] : Rbac::INSTALL_VIEW_OWN;
    }

    private function jobTypeFor($action)
    {
        $map = [
            DeploymentService::ACTION_START => JobQueue::TYPE_START,
            DeploymentService::ACTION_STOP => JobQueue::TYPE_STOP,
            DeploymentService::ACTION_RESTART => JobQueue::TYPE_RESTART,
            DeploymentService::ACTION_UPDATE => JobQueue::TYPE_UPDATE,
            DeploymentService::ACTION_BACKUP => JobQueue::TYPE_BACKUP,
            DeploymentService::ACTION_RESTORE => JobQueue::TYPE_RESTORE,
            DeploymentService::ACTION_UNINSTALL => JobQueue::TYPE_DESTROY,
            DeploymentService::ACTION_SSL_PROVISION => JobQueue::TYPE_SSL,
            DeploymentService::ACTION_DOMAIN_CONFIGURE => JobQueue::TYPE_DOMAIN_CONFIGURE,
            DeploymentService::ACTION_HEALTHCHECK => JobQueue::TYPE_HEALTHCHECK,
        ];
        return isset($map[$action]) ? $map[$action] : JobQueue::TYPE_INSTALL;
    }

    private function auditActionFor($action)
    {
        $map = [
            DeploymentService::ACTION_START => Audit::APPLICATION_STARTED,
            DeploymentService::ACTION_STOP => Audit::APPLICATION_STOPPED,
            DeploymentService::ACTION_RESTART => Audit::APPLICATION_RESTARTED,
            DeploymentService::ACTION_UPDATE => Audit::INSTALLATION_UPDATED,
            DeploymentService::ACTION_UNINSTALL => Audit::APPLICATION_DELETED,
        ];
        return isset($map[$action]) ? $map[$action] : null;
    }

    private function assertCanManage(array $installation, $action)
    {
        if ($this->actor->isMachine() || $this->actor->can(Rbac::APP_VIEW_ALL)) {
            return true;
        }
        if ((int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That installation does not exist.');
        }
        return true;
    }

    private function assertOwns($installationId, $permission)
    {
        $installation = $this->row($installationId);
        if ($this->actor->isMachine() || $this->actor->can(Rbac::APP_VIEW_ALL)) {
            return $installation;
        }
        Rbac::assert($this->actor, $permission);
        if ((int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That installation does not exist.');
        }
        return $installation;
    }

    private function assertCanSee(array $installation)
    {
        if ($this->actor->isMachine() || $this->actor->can(Rbac::APP_VIEW_ALL)) {
            return true;
        }
        Rbac::assert($this->actor, Rbac::INSTALL_VIEW_OWN);
        if ((int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That installation does not exist.');
        }
        return true;
    }

    private function rateKey()
    {
        return $this->actor->isCustomer() ? 'client:' . (int) $this->actor->clientId : $this->actor->identity();
    }

    private function truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
