<?php
/**
 * WHMCS-service-bound cPanel account records and queued lifecycle requests.
 *
 * This service links an already-existing WHM account to one paid WHMCS hosting
 * service. It intentionally has no customer-account creation, password storage,
 * credential delivery, login, or SSO method.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Billing\PaymentGate;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;
use Ch247Apps\Servers\AgentClient;
use Ch247Apps\Servers\ServerService;

class PanelAccountService
{
    const PANEL_KEY = 'cpanel_whm';

    const STATUS_UNVERIFIED = 'unverified';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_MISSING = 'missing';
    const STATUS_TERMINATED = 'terminated';
    const STATUS_ERROR = 'error';

    const ACTION_VERIFY = 'verify';
    const ACTION_SUSPEND = 'suspend';
    const ACTION_UNSUSPEND = 'unsuspend';
    const ACTION_TERMINATE = 'terminate';

    /** @var Actor */
    private $actor;
    /** @var GatewayInterface */
    private $gateway;
    /** @var JobQueue */
    private $queue;
    /** @var ServerService */
    private $servers;

    public function __construct(Actor $actor = null, GatewayInterface $gateway = null,
        JobQueue $queue = null, ServerService $servers = null)
    {
        $this->actor = $actor ?: Actor::system('PanelAccountService');
        $this->gateway = $gateway ?: Gateway::get();
        $this->queue = $queue ?: new JobQueue();
        $this->servers = $servers ?: new ServerService($this->actor);
    }

    /**
     * Link a pre-existing WHM account to one paid, active WHMCS hosting service.
     * No WHM request is made here; the worker verifies the account asynchronously.
     *
     * @return array{account:array,job:array|null,replayed:bool}
     */
    public function bindExistingAccount($serviceId, $serverId, array $account, $idempotencyKey)
    {
        $this->assertHumanAdmin(Rbac::PANEL_ACCOUNT_MANAGE);
        self::assertWorkflowEnabled();
        $serviceId = (int) $serviceId;
        $serverId = (int) $serverId;
        if ($serviceId <= 0 || $serverId <= 0) {
            throw new ValidationException('A WHMCS service and registered cPanel server are required.');
        }
        $account = $this->normaliseBinding($account);
        $key = self::requireIdempotencyKey($idempotencyKey);

        $fingerprintPayload = [
            'service_id' => $serviceId,
            'server_id' => $serverId,
            'username' => $account['username'],
            'domain' => $account['domain'],
            'package' => $account['package'],
        ];
        try {
            $run = Idempotency::run('panel-account.bind-existing', $key, $fingerprintPayload, function () use (
                $serviceId, $serverId, $account
            ) {
                return Db::transaction(function () use ($serviceId, $serverId, $account) {
                    // Verify live ownership, service status and payment before
                    // creating a module-side relationship. The worker repeats it.
                    $billing = $this->serviceContext($serviceId, null, true, true);
                    $this->assertCpanelServer($serverId);
                    $serviceDomain = strtolower(trim((string) (isset($billing['service']['domain'])
                        ? $billing['service']['domain'] : '')));
                    if ($serviceDomain !== '' && $serviceDomain !== $account['domain']) {
                        throw new ConflictException('The cPanel account domain does not match the WHMCS service domain.');
                    }
                    $existing = Db::first('panel_accounts', ['whmcs_service_id' => $serviceId]);
                    if ($existing) {
                        throw new ConflictException('This WHMCS service already has a linked control-panel account.', [
                            'panel_account_id' => (int) $existing['id'],
                        ]);
                    }
                    $collision = Db::first('panel_accounts', [
                        'server_id' => $serverId, 'panel_key' => self::PANEL_KEY,
                        'username' => $account['username'],
                    ]);
                    if ($collision) {
                        throw new ConflictException('That cPanel username is already linked to another WHMCS service.');
                    }

                    $now = Clock::now();
                    $accountId = Db::insert('panel_accounts', [
                        'uuid' => Str::uuid4(),
                        'client_id' => (int) $billing['client_id'],
                        'whmcs_service_id' => $serviceId,
                        'server_id' => $serverId,
                        'panel_key' => self::PANEL_KEY,
                        'username' => $account['username'],
                        'domain' => $account['domain'],
                        'package' => $account['package'],
                        'status' => self::STATUS_UNVERIFIED,
                        'pending_action' => self::ACTION_VERIFY,
                        'pending_job_id' => null,
                        'last_verified_at' => null,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'linked_by' => Str::clip($this->actor->identity(), 120),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->recordHistory($accountId, 0, 'account_linked', null, self::STATUS_UNVERIFIED, [
                        'whmcs_service_id' => $serviceId,
                        'client_id' => (int) $billing['client_id'],
                        'server_id' => $serverId,
                        'username' => $account['username'],
                        'domain' => $account['domain'],
                        'package' => $account['package'],
                    ], $this->actor);
                    $this->requireAudit(Audit::PANEL_ACCOUNT_LINKED, $accountId, $serverId,
                        (int) $billing['client_id'], [
                            'whmcs_service_id' => $serviceId,
                            'username' => $account['username'],
                            'domain' => $account['domain'],
                            'package' => $account['package'],
                            'initial_status' => self::STATUS_UNVERIFIED,
                        ]);

                    $job = $this->queue->enqueue(JobQueue::TYPE_PANEL_ACCOUNT_VERIFY, [
                        'panel_account_id' => $accountId,
                        'action' => self::ACTION_VERIFY,
                    ], [
                        'queue' => JobQueue::QUEUE_CONTROL_PANEL,
                        'idempotency_key' => self::queueKey($accountId, self::ACTION_VERIFY,
                            (string) $accountId . ':' . $serviceId),
                        'panel_account_id' => $accountId,
                        'whmcs_service_id' => $serviceId,
                        'client_id' => (int) $billing['client_id'],
                        'requested_by' => $this->actor->identity(),
                    ]);
                    $linked = Db::update('panel_accounts', [
                        'pending_job_id' => (int) $job['id'], 'updated_at' => Clock::now(),
                    ], ['id' => $accountId, 'pending_action' => self::ACTION_VERIFY]);
                    if ($linked <= 0) {
                        throw new ConflictException('The new panel-account verification job could not be attached to its record.');
                    }
                    $this->recordHistory($accountId, (int) $job['id'], 'verification_queued',
                        self::STATUS_UNVERIFIED, self::STATUS_UNVERIFIED, [
                            'action' => self::ACTION_VERIFY,
                            'whmcs_service_id' => $serviceId,
                        ], $this->actor);
                    return ['account_id' => $accountId, 'job_id' => (int) $job['id']];
                });
            });
        } catch (\PDOException $e) {
            if (!self::isConstraintViolation($e)) {
                throw $e;
            }
            throw new ConflictException('This WHMCS service or cPanel username was linked concurrently. Refresh and review the existing mapping.');
        }

        $result = isset($run['result']) ? $run['result'] : [];
        return $this->responseFor((int) $result['account_id'], (int) $result['job_id'], !empty($run['replayed']));
    }

    /** Queue a staff-authorized lifecycle action; there is intentionally no create action. */
    public function requestAction($accountId, array $input, $idempotencyKey)
    {
        $action = isset($input['action']) && is_scalar($input['action'])
            ? strtolower(trim((string) $input['action'])) : '';
        $requiredPermission = self::permissionForAction($action);
        $this->assertHumanAdmin($requiredPermission);
        self::assertWorkflowEnabled();

        foreach (array_keys($input) as $field) {
            if (!in_array((string) $field, ['action', 'confirmation'], true)) {
                throw new ValidationException('Unsupported control-panel action field.', ['field' => (string) $field]);
            }
        }
        if (!in_array($action, [self::ACTION_VERIFY, self::ACTION_SUSPEND,
            self::ACTION_UNSUSPEND, self::ACTION_TERMINATE], true)) {
            throw new ValidationException('Unsupported cPanel account action.', ['action' => $action]);
        }

        $accountId = (int) $accountId;
        if ($accountId <= 0) {
            throw new ValidationException('A linked panel-account id is required.');
        }
        $account = $this->internalRow($accountId);
        $confirmation = isset($input['confirmation']) && is_string($input['confirmation'])
            ? $input['confirmation'] : '';
        if ($action === self::ACTION_TERMINATE
            && !hash_equals('TERMINATE ' . (string) $account['username'], $confirmation)) {
            throw new ValidationException('Account termination requires the exact username-bound confirmation phrase.', [
                'error_code' => 'CPANEL_TERMINATION_CONFIRMATION_REQUIRED',
            ]);
        }
        if ($action !== self::ACTION_TERMINATE && array_key_exists('confirmation', $input)) {
            throw new ValidationException('A confirmation phrase is accepted only for account termination.', [
                'field' => 'confirmation',
            ]);
        }

        $key = self::requireIdempotencyKey($idempotencyKey);
        $fingerprintPayload = [
            'panel_account_id' => $accountId,
            'action' => $action,
            'confirmed' => $action === self::ACTION_TERMINATE,
        ];
        $run = Idempotency::run('panel-account.action', $key, $fingerprintPayload, function () use (
            $accountId, $action, $account, $key
        ) {
            return Db::transaction(function () use ($accountId, $action, $account, $key) {
                $current = $this->internalRow($accountId);
                if ((int) $current['whmcs_service_id'] !== (int) $account['whmcs_service_id']
                    || (int) $current['server_id'] !== (int) $account['server_id']) {
                    throw new ConflictException('The panel-account mapping changed while this action was being submitted.');
                }
                $this->assertCpanelServer((int) $current['server_id']);
                $context = $this->serviceContext((int) $current['whmcs_service_id'],
                    (int) $current['client_id'], false, $action === self::ACTION_UNSUSPEND);
                $this->assertActionAllowed($current, $action, $context['status']);
                $this->assertNoPendingAction($current);

                if (!Db::compareAndSet('panel_accounts', [
                    'pending_action' => $action, 'updated_at' => Clock::now(),
                ], ['id' => $accountId, 'pending_job_id' => null, 'pending_action' => null])) {
                    throw new ConflictException('Another control-panel action is already being queued for this account.');
                }
                $this->requireAudit(Audit::PANEL_ACCOUNT_ACTION_QUEUED, $accountId,
                    (int) $current['server_id'], (int) $current['client_id'], [
                        'whmcs_service_id' => (int) $current['whmcs_service_id'],
                        'action' => $action,
                        'whmcs_status' => $context['status'],
                        'confirmed' => $action === self::ACTION_TERMINATE,
                    ], $action === self::ACTION_TERMINATE ? 'warning' : 'info');

                $jobType = self::jobType($action);
                $job = $this->queue->enqueue($jobType, [
                    'panel_account_id' => $accountId,
                    'action' => $action,
                ], [
                    'queue' => JobQueue::QUEUE_CONTROL_PANEL,
                    'idempotency_key' => self::queueKey($accountId, $action, $key),
                    'panel_account_id' => $accountId,
                    'whmcs_service_id' => (int) $current['whmcs_service_id'],
                    'client_id' => (int) $current['client_id'],
                    'requested_by' => $this->actor->identity(),
                    'priority' => $action === self::ACTION_TERMINATE ? JobQueue::PRIORITY_HIGH : JobQueue::PRIORITY_NORMAL,
                ]);
                $attached = Db::update('panel_accounts', [
                    'pending_job_id' => (int) $job['id'], 'updated_at' => Clock::now(),
                ], ['id' => $accountId, 'pending_action' => $action, 'pending_job_id' => null]);
                if ($attached <= 0) {
                    throw new ConflictException('The queued cPanel action could not be attached to its account record.');
                }
                $this->recordHistory($accountId, (int) $job['id'], 'action_queued',
                    (string) $current['status'], (string) $current['status'], [
                        'action' => $action,
                        'whmcs_status' => $context['status'],
                        'confirmed' => $action === self::ACTION_TERMINATE,
                    ], $this->actor);
                return ['account_id' => $accountId, 'job_id' => (int) $job['id']];
            });
        });

        $result = isset($run['result']) ? $run['result'] : [];
        return $this->responseFor((int) $result['account_id'], (int) $result['job_id'], !empty($run['replayed']));
    }

    /** Admin list; customer-facing panel-account access is deliberately absent. */
    public function listing($limit = 200)
    {
        $this->assertHumanAdmin(Rbac::PANEL_ACCOUNT_VIEW);
        $out = [];
        foreach (Db::fetch('panel_accounts', [], ['order' => 'id', 'dir' => 'desc',
            'limit' => max(1, min(500, (int) $limit))]) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function get($accountId)
    {
        $this->assertHumanAdmin(Rbac::PANEL_ACCOUNT_VIEW);
        return $this->present($this->internalRow((int) $accountId));
    }

    /** @internal Worker-only raw row; do not return through HTTP. */
    public function internalRow($accountId)
    {
        $row = Db::first('panel_accounts', ['id' => (int) $accountId]);
        if (!$row) {
            throw new NotFoundException('That linked control-panel account does not exist.');
        }
        return $row;
    }

    /** Recheck service owner/status/payment immediately before WHM access. */
    public function workerAssertActionAllowed($accountId, $jobId, $action)
    {
        $this->assertWorker();
        self::assertWorkflowEnabled();
        $row = $this->internalRow($accountId);
        if ((string) $row['panel_key'] !== self::PANEL_KEY
            || (int) $row['pending_job_id'] !== (int) $jobId
            || (string) $row['pending_action'] !== (string) $action) {
            throw new ConflictException('The queued cPanel action no longer matches the linked account state.');
        }
        $context = $this->serviceContext((int) $row['whmcs_service_id'], (int) $row['client_id'], false,
            $action === self::ACTION_UNSUSPEND);
        $this->assertActionAllowed($row, $action, $context['status']);
        $this->assertCpanelServer((int) $row['server_id']);
        $row['_whmcs_status'] = $context['status'];
        return $row;
    }

    /** Persist only a vendor-confirmed account state and clear its matching job. */
    public function workerComplete($accountId, $jobId, $status, array $metadata = [])
    {
        $this->assertWorker();
        if (!in_array((string) $status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED,
            self::STATUS_MISSING, self::STATUS_TERMINATED], true)) {
            throw new ValidationException('The worker returned an unsupported panel-account state.');
        }
        $row = $this->internalRow($accountId);
        if ((int) $row['pending_job_id'] !== (int) $jobId) {
            throw new ConflictException('The completed job no longer owns this panel-account action.');
        }
        $now = Clock::now();
        $updated = Db::update('panel_accounts', [
            'status' => (string) $status,
            'pending_action' => null,
            'pending_job_id' => null,
            'last_verified_at' => $now,
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at' => $now,
        ], ['id' => (int) $accountId, 'pending_job_id' => (int) $jobId]);
        if ($updated <= 0) {
            throw new ConflictException('The linked panel account changed before the worker could record completion.');
        }
        $action = (string) $row['pending_action'];
        $event = $status === self::STATUS_MISSING ? 'account_missing'
            : ($status === self::STATUS_TERMINATED ? 'account_terminated'
                : ($status === self::STATUS_SUSPENDED ? 'account_suspended'
                    : ($action === self::ACTION_VERIFY ? 'account_verified' : 'account_unsuspended')));
        $safeMetadata = ['action' => $action, 'status' => (string) $status] + $metadata;
        $this->recordHistory($accountId, $jobId, $event, (string) $row['status'], (string) $status,
            $safeMetadata, $this->actor);
        $auditId = Audit::transition($this->actor, Audit::PANEL_ACCOUNT_STATE_CHANGED,
            'panel_account', (int) $accountId, (string) $row['status'], (string) $status, [
                'server_id' => (int) $row['server_id'], 'client_id' => (int) $row['client_id'],
                'metadata' => ['job_id' => (int) $jobId, 'whmcs_service_id' => (int) $row['whmcs_service_id']]
                    + $safeMetadata,
                'severity' => $status === self::STATUS_TERMINATED ? 'warning' : 'info',
            ]);
        if (!(int) $auditId) {
            throw new StateException('Panel-account completion cannot be recorded without a writable audit log.', [
                'error_code' => 'PANEL_ACCOUNT_AUDIT_UNAVAILABLE',
            ]);
        }
        Events::emit('panel_account.state_changed', [
            'panel_account_id' => (int) $accountId,
            'job_id' => (int) $jobId,
            'action' => $action,
            'status' => (string) $status,
        ], [
            'panel_account_id' => (int) $accountId,
            'client_id' => (int) $row['client_id'],
            'source' => 'control_panel',
        ]);
        return $this->present($this->internalRow($accountId));
    }

    /** Store safe failures; keep the reservation while a retry is scheduled. */
    public function workerFailure($accountId, $jobId, $errorCode, $message, $clearPending)
    {
        $this->assertWorker();
        $row = $this->internalRow($accountId);
        if ((int) $row['pending_job_id'] !== (int) $jobId) {
            return false;
        }
        $code = Str::clip((string) $errorCode, 60);
        $message = Str::clip((string) $message, 300);
        $fields = [
            'last_error_code' => $code,
            'last_error_message' => $message,
            'updated_at' => Clock::now(),
        ];
        if (in_array($code, ['CPANEL_ACCOUNT_BINDING_MISMATCH', 'CPANEL_AUDIT_UNAVAILABLE',
            'PANEL_ACCOUNT_AUDIT_UNAVAILABLE'], true)) {
            $fields['status'] = self::STATUS_ERROR;
        }
        if ($clearPending) {
            $fields['pending_action'] = null;
            $fields['pending_job_id'] = null;
        }
        Db::update('panel_accounts', $fields, ['id' => (int) $accountId, 'pending_job_id' => (int) $jobId]);
        $this->recordHistory($accountId, $jobId, $clearPending ? 'action_failed' : 'action_retrying',
            (string) $row['status'], isset($fields['status']) ? $fields['status'] : (string) $row['status'], [
                'action' => (string) $row['pending_action'], 'error_code' => $code,
                'retry_scheduled' => !$clearPending,
            ], $this->actor);
        Audit::record($this->actor, Audit::PANEL_ACCOUNT_OPERATION_FAILED, [
            'resource_type' => 'panel_account', 'resource_id' => (int) $accountId,
            'server_id' => (int) $row['server_id'], 'client_id' => (int) $row['client_id'],
            'metadata' => ['job_id' => (int) $jobId, 'error_code' => $code,
                'retry_scheduled' => !$clearPending], 'severity' => 'error',
        ]);
        return true;
    }

    public static function assertWorkflowEnabled()
    {
        if (!Settings::bool('panel_account_workflow_enabled', false)) {
            throw new StateException('The cPanel account workflow is disabled until WHM staging and operational readiness checks pass. Customer account creation remains unavailable pending a separate password/SSO review.', [
                'error_code' => 'PANEL_ACCOUNT_WORKFLOW_DISABLED',
            ]);
        }
        return true;
    }

    /** Confirm the live WHMCS service is still owned by this mapped client. */
    private function serviceContext($serviceId, $expectedClientId = null, $requireActive = false, $requirePaid = false)
    {
        try {
            $service = $this->gateway->getService((int) $serviceId);
        } catch (\Throwable $e) {
            throw new ConflictException('WHMCS service state could not be verified; the panel action is blocked.');
        }
        if (!$service) {
            throw new NotFoundException('The linked WHMCS hosting service does not exist.');
        }
        $clientId = isset($service['userid']) ? (int) $service['userid'] : 0;
        if ($clientId <= 0) {
            throw new PaymentException('The WHMCS service has no valid client owner.');
        }
        if ($expectedClientId !== null && $clientId !== (int) $expectedClientId) {
            throw new ConflictException('The WHMCS service owner changed after the panel account was linked.');
        }
        if ($this->actor->isCustomer()) {
            $this->actor->assertOwns($clientId);
        }
        $status = strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : '')));
        if ($requireActive && $status !== 'active') {
            throw new PaymentException('Only an active WHMCS hosting service can be linked to a panel account.', [
                'error_code' => 'SERVICE_NOT_ACTIVE', 'whmcs_service_id' => (int) $serviceId,
            ]);
        }
        if (!$requirePaid) {
            return ['service' => $service, 'client_id' => $clientId, 'status' => $status];
        }

        $orderId = isset($service['orderid']) ? (int) $service['orderid'] : 0;
        if ($orderId <= 0) {
            throw new PaymentException('The WHMCS service has no linked order; panel-account access is blocked.');
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
            throw new PaymentException('The WHMCS order has no linked invoice; panel-account access is blocked.');
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
        } catch (\Throwable $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            throw new PaymentException('WHMCS payment status could not be verified.');
        }
        return ['service' => $service, 'client_id' => $clientId, 'status' => $status,
            'order_id' => $orderId, 'invoice_id' => $invoiceId];
    }

    private function assertActionAllowed(array $account, $action, $whmcsStatus)
    {
        $status = strtolower(trim((string) $whmcsStatus));
        if ($action === self::ACTION_VERIFY) {
            return true;
        }
        if ($action === self::ACTION_SUSPEND) {
            if (!in_array($status, ['suspended', 'cancelled', 'terminated', 'fraud'], true)) {
                throw new ConflictException('Suspend the WHMCS service before suspending its cPanel account.');
            }
            if (in_array((string) $account['status'], [self::STATUS_MISSING, self::STATUS_TERMINATED], true)) {
                throw new ConflictException('A missing or terminated cPanel account cannot be suspended.');
            }
            return true;
        }
        if ($action === self::ACTION_UNSUSPEND) {
            if ($status !== 'active') {
                throw new ConflictException('The WHMCS service must be active before its cPanel account can be unsuspended.');
            }
            if (!in_array((string) $account['status'], [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
                throw new ConflictException('Verify the linked cPanel account before unsuspending it.');
            }
            return true;
        }
        if ($action === self::ACTION_TERMINATE) {
            if (!in_array($status, ['cancelled', 'terminated'], true)) {
                throw new ConflictException('Cancel or terminate the WHMCS service before terminating its cPanel account.');
            }
            return true;
        }
        throw new ValidationException('Unsupported cPanel account action.');
    }

    private function assertCpanelServer($serverId)
    {
        $server = $this->servers->row((int) $serverId);
        $supportedTypes = [ServerService::TYPE_CPANEL, ServerService::TYPE_SHARED];
        if (!in_array((string) $server['server_type'], $supportedTypes, true)
            || empty($server['cpanel_enabled'])
            || in_array((string) $server['status'], [ServerService::STATUS_DISABLED, ServerService::STATUS_MAINTENANCE], true)) {
            throw new ConflictException('The selected server is not enabled for cPanel account operations.', [
                'server_id' => (int) $serverId, 'error_code' => 'CPANEL_SERVER_DISABLED',
            ]);
        }
        return $server;
    }

    private function normaliseBinding(array $input)
    {
        $allowed = ['username', 'domain', 'package'];
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new ValidationException('Unsupported cPanel account binding field.', ['field' => (string) $key]);
            }
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input) && !is_string($input[$key])) {
                throw new ValidationException('cPanel account binding fields must be strings.', ['field' => $key]);
            }
        }
        $username = strtolower(trim(isset($input['username']) ? (string) $input['username'] : ''));
        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            throw new ValidationException('A valid cPanel username is required.', ['field' => 'username']);
        }
        $domain = strtolower(trim(isset($input['domain']) ? (string) $input['domain'] : ''));
        if ($domain === '' || strlen($domain) > 253 || substr($domain, -1) === '.'
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new ValidationException('A valid fully-qualified account domain is required.', ['field' => 'domain']);
        }
        $package = trim(isset($input['package']) ? (string) $input['package'] : '');
        if ($package === '' || strlen($package) > 80
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $package)) {
            throw new ValidationException('A valid WHM package name is required.', ['field' => 'package']);
        }
        return ['username' => $username, 'domain' => $domain, 'package' => $package];
    }

    private function assertNoPendingAction(array $account)
    {
        if (!empty($account['pending_job_id'])) {
            $job = $this->queue->find((int) $account['pending_job_id']);
            if ($job && in_array((string) $job['status'], JobQueue::LIVE, true)) {
                throw new ConflictException('A control-panel action is already pending for this account.', [
                    'job_id' => (int) $job['id'],
                ]);
            }
            throw new ConflictException('The control-panel action reservation needs operator reconciliation before another action can be queued.');
        }
        if (!empty($account['pending_action'])) {
            throw new ConflictException('The control-panel action reservation needs operator reconciliation before another action can be queued.');
        }
        $live = Db::first('jobs', ['panel_account_id' => (int) $account['id'], 'status' => ['in', JobQueue::LIVE]]);
        if ($live) {
            throw new ConflictException('A control-panel action is already pending for this account.', [
                'job_id' => (int) $live['id'],
            ]);
        }
    }

    private function responseFor($accountId, $jobId, $replayed)
    {
        $job = $jobId > 0 ? $this->queue->find($jobId) : null;
        return [
            'account' => $this->present($this->internalRow($accountId)),
            'job' => $job ? $this->queue->present($job) : null,
            'replayed' => (bool) $replayed,
        ];
    }

    private function present(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'client_id' => (int) $row['client_id'],
            'whmcs_service_id' => (int) $row['whmcs_service_id'],
            'server_id' => (int) $row['server_id'],
            'panel_key' => (string) $row['panel_key'],
            'username' => (string) $row['username'],
            'domain' => (string) $row['domain'],
            'package' => (string) $row['package'],
            'status' => (string) $row['status'],
            'pending_action' => isset($row['pending_action']) ? $row['pending_action'] : null,
            'pending_job_id' => !empty($row['pending_job_id']) ? (int) $row['pending_job_id'] : null,
            'last_verified_at' => isset($row['last_verified_at']) ? $row['last_verified_at'] : null,
            'last_error_code' => isset($row['last_error_code']) ? $row['last_error_code'] : null,
            'last_error_message' => isset($row['last_error_message']) ? $row['last_error_message'] : null,
            'linked_by' => isset($row['linked_by']) ? $row['linked_by'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function recordHistory($accountId, $jobId, $event, $fromStatus, $toStatus, array $metadata, Actor $actor)
    {
        $row = $this->internalRow($accountId);
        Db::insert('panel_account_events', [
            'panel_account_id' => (int) $accountId,
            'job_id' => (int) $jobId > 0 ? (int) $jobId : null,
            'event' => Str::clip((string) $event, 60),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata ? Str::jsonEncode(Logger::redact($metadata)) : null,
            'actor_type' => Str::clip($actor->type, 20),
            'actor_id' => (int) $actor->actorId(),
            'actor_identity' => Str::clip($actor->identity(), 120),
            'created_at' => Clock::now(),
        ]);
        Events::emit('panel_account.action_recorded', [
            'panel_account_id' => (int) $accountId,
            'event' => Str::clip((string) $event, 60),
        ], [
            'panel_account_id' => (int) $accountId,
            'client_id' => (int) $row['client_id'],
            'source' => 'control_panel',
        ]);
    }

    private function requireAudit($action, $accountId, $serverId, $clientId, array $metadata, $severity = 'info')
    {
        $id = Audit::record($this->actor, $action, [
            'resource_type' => 'panel_account',
            'resource_id' => (int) $accountId,
            'server_id' => (int) $serverId,
            'client_id' => (int) $clientId,
            'metadata' => $metadata,
            'severity' => $severity,
        ]);
        if (!(int) $id) {
            throw new StateException('A panel-account workflow cannot proceed without a writable audit log.', [
                'error_code' => 'PANEL_ACCOUNT_AUDIT_UNAVAILABLE',
            ]);
        }
        return $id;
    }

    private function assertHumanAdmin($permission)
    {
        if (!$this->actor->isAdmin()) {
            throw new AuthorizationException('Only an authorized WHMCS administrator may manage linked panel accounts.');
        }
        Rbac::assert($this->actor, $permission);
    }

    private function assertWorker()
    {
        AgentClient::assertWorkerContext();
        if (!$this->actor->isSystem()) {
            throw new AuthorizationException('Only the system worker may update panel-account state.');
        }
        Rbac::assert($this->actor, Rbac::PANEL_ACCOUNT_MANAGE);
    }

    /** Map requested lifecycle actions to their distinct staff permissions. */
    public static function permissionForAction($action)
    {
        $action = strtolower(trim((string) $action));
        if ($action === self::ACTION_TERMINATE) {
            return Rbac::PANEL_ACCOUNT_TERMINATE;
        }
        if ($action === self::ACTION_VERIFY) {
            return Rbac::PANEL_ACCOUNT_VERIFY;
        }
        return Rbac::PANEL_ACCOUNT_MANAGE;
    }

    private static function jobType($action)
    {
        $map = [
            self::ACTION_VERIFY => JobQueue::TYPE_PANEL_ACCOUNT_VERIFY,
            self::ACTION_SUSPEND => JobQueue::TYPE_PANEL_ACCOUNT_SUSPEND,
            self::ACTION_UNSUSPEND => JobQueue::TYPE_PANEL_ACCOUNT_UNSUSPEND,
            self::ACTION_TERMINATE => JobQueue::TYPE_PANEL_ACCOUNT_TERMINATE,
        ];
        if (!isset($map[$action])) {
            throw new ValidationException('Unsupported cPanel account action.');
        }
        return $map[$action];
    }

    private static function queueKey($accountId, $action, $requestKey)
    {
        return 'panel-account:' . (int) $accountId . ':' . $action . ':' . substr(hash('sha256', (string) $requestKey), 0, 48);
    }

    private static function requireIdempotencyKey($key)
    {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 120 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new ValidationException('A valid Idempotency-Key is required (1–120 visible characters).');
        }
        return $key;
    }

    private static function isConstraintViolation(\PDOException $e)
    {
        $state = (string) $e->getCode();
        return strpos($state, '23') === 0 || (int) $state === 19
            || stripos($e->getMessage(), 'unique') !== false
            || stripos($e->getMessage(), 'constraint') !== false;
    }
}
