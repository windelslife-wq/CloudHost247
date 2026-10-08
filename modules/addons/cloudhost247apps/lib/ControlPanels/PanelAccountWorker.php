<?php
/** Executes WHMCS-bound cPanel account jobs outside HTTP request handlers. */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\RetryableCpanelException;
use Ch247Apps\Core\StateException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Servers\CredentialVault;

class PanelAccountWorker
{
    const JOB_TYPES = [
        JobQueue::TYPE_PANEL_ACCOUNT_VERIFY,
        JobQueue::TYPE_PANEL_ACCOUNT_SUSPEND,
        JobQueue::TYPE_PANEL_ACCOUNT_UNSUSPEND,
        JobQueue::TYPE_PANEL_ACCOUNT_TERMINATE,
    ];

    /** @var Actor */
    private $actor;
    /** @var JobQueue */
    private $queue;
    /** @var PanelAccountService */
    private $accounts;
    /** @var ControlPanelService */
    private $panels;

    public function __construct(Actor $actor, JobQueue $queue = null,
        PanelAccountService $accounts = null, ControlPanelService $panels = null)
    {
        if (!$actor->isSystem()) {
            throw new AuthorizationException('Only the system worker may execute panel-account jobs.');
        }
        $this->actor = $actor;
        $this->queue = $queue ?: new JobQueue();
        $this->accounts = $accounts ?: new PanelAccountService($actor, null, $this->queue);
        $this->panels = $panels ?: new ControlPanelService($actor);
    }

    public static function handles($jobType)
    {
        return in_array((string) $jobType, self::JOB_TYPES, true);
    }

    public function runJob(array $job)
    {
        $jobId = isset($job['id']) ? (int) $job['id'] : 0;
        if ($jobId <= 0) {
            return ['status' => 'failed', 'error' => ['code' => 'JOB_MISSING_ID']];
        }
        $payload = $this->queue->payload($job);
        // The resource id must be in the dedicated queue column; never trust a
        // payload value to select the account/WHM server that receives a call.
        $accountId = !empty($job['panel_account_id']) ? (int) $job['panel_account_id'] : 0;

        try {
            if ((string) (isset($job['queue']) ? $job['queue'] : '') !== JobQueue::QUEUE_CONTROL_PANEL) {
                throw new StateException('Panel-account jobs must be leased from the control-panel queue.', [
                    'error_code' => 'PANEL_QUEUE_MISMATCH',
                ]);
            }
            if ($accountId <= 0) {
                throw new StateException('The panel-account job has no linked account reference.', [
                    'error_code' => 'JOB_MISSING_PANEL_ACCOUNT',
                ]);
            }
            $this->queue->start($jobId, isset($job['leased_by']) ? $job['leased_by'] : 'control-panel-worker');
            if ($this->queue->cancelRequested($jobId)) {
                throw new StateException('The control-panel job was cancelled before its external operation.');
            }
            $this->queue->touch($jobId);
            $action = self::actionForType(isset($job['job_type']) ? (string) $job['job_type'] : '');
            if (isset($payload['action']) && (string) $payload['action'] !== $action) {
                throw new ConflictException('The panel-account job payload does not match its registered job type.');
            }
            if (isset($payload['panel_account_id']) && (int) $payload['panel_account_id'] !== $accountId) {
                throw new ConflictException('The panel-account job payload and queue correlation do not match.');
            }
            $account = $this->accounts->workerAssertActionAllowed($accountId, $jobId, $action);
            $this->assertJobScope($job, $account);
            $this->ensureCredentialVerified((int) $account['server_id']);
            // Credential verification may itself make a WHM request. Recheck
            // WHMCS ownership, eligibility and mapping immediately before any
            // lifecycle call so a status/owner change cannot race the mutation.
            $account = $this->recheckActionScope($job, $account, $jobId, $action);
            $result = $this->execute($account, $job, $jobId, $action);
            Db::transaction(function () use ($accountId, $jobId, $result) {
                $this->accounts->workerComplete($accountId, $jobId, $result['status'], $result['metadata']);
                $this->queue->complete($jobId, $result['job_result']);
            });
            return ['status' => 'completed', 'panel_account_id' => $accountId,
                'result' => $result['job_result']];
        } catch (\Throwable $e) {
            return $this->failJob($jobId, $accountId, $e);
        }
    }

    private function assertJobScope(array $job, array $account)
    {
        if (empty($job['whmcs_service_id'])
            || (int) $job['whmcs_service_id'] !== (int) $account['whmcs_service_id']
            || empty($job['client_id']) || (int) $job['client_id'] !== (int) $account['client_id']) {
            throw new ConflictException('The queued WHMCS owner/service scope does not match the linked panel account.');
        }
    }

    private function recheckActionScope(array $job, array $expected, $jobId, $action)
    {
        $latest = $this->accounts->workerAssertActionAllowed((int) $expected['id'], (int) $jobId, $action);
        $this->assertJobScope($job, $latest);
        foreach (['server_id', 'whmcs_service_id', 'client_id', 'username', 'domain', 'package'] as $field) {
            if ((string) $expected[$field] !== (string) $latest[$field]) {
                throw new ConflictException('The panel-account mapping changed while the worker was preparing the WHM operation.');
            }
        }
        return $latest;
    }

    private function assertNotCancelled($jobId)
    {
        if ($this->queue->cancelRequested((int) $jobId)) {
            throw new StateException('The control-panel job was cancelled before its external mutation.');
        }
    }

    private function execute(array $account, array $job, $jobId, $action)
    {
        $serverId = (int) $account['server_id'];
        $username = (string) $account['username'];
        if (in_array($action, [PanelAccountService::ACTION_SUSPEND,
            PanelAccountService::ACTION_UNSUSPEND, PanelAccountService::ACTION_TERMINATE], true)) {
            $before = $this->panels->getAccount($serverId, $username);
            if ($before === null && $action !== PanelAccountService::ACTION_TERMINATE) {
                throw new ConflictException('A missing cPanel account cannot be changed; verify and reconcile it first.');
            }
            if ($before !== null) {
                // Refuse to mutate a username that no longer matches the linked
                // WHMCS domain/package, even if WHM reports that user exists.
                $this->statusForAccount($account, $before);
            }
        }
        if ($action === PanelAccountService::ACTION_VERIFY) {
            $remote = $this->panels->getAccount($serverId, $username);
            if ($remote === null) {
                return [
                    'status' => PanelAccountService::STATUS_MISSING,
                    'metadata' => ['exists' => false, 'username' => $username],
                    'job_result' => [
                        'panel_account_id' => (int) $account['id'], 'status' => PanelAccountService::STATUS_MISSING,
                        'exists' => false, 'confirmed' => true,
                    ],
                ];
            }
            $status = $this->statusForAccount($account, $remote);
            return [
                'status' => $status,
                'metadata' => ['exists' => true, 'username' => $username,
                    'domain' => (string) $remote['domain'], 'suspended' => (bool) $remote['suspended']],
                'job_result' => [
                    'panel_account_id' => (int) $account['id'], 'status' => $status,
                    'exists' => true, 'confirmed' => true,
                ],
            ];
        }

        if ($action === PanelAccountService::ACTION_SUSPEND) {
            $account = $this->recheckActionScope($job, $account, $jobId, $action);
            $this->assertNotCancelled($jobId);
            $reason = 'WHMCS service status: ' . (string) $account['_whmcs_status'];
            $operation = $this->panels->suspendAccount($serverId, $username, $reason);
            $remote = isset($operation['account']) && is_array($operation['account'])
                ? $operation['account'] : $this->panels->getAccount($serverId, $username);
            if ($remote === null || $this->statusForAccount($account, $remote) !== PanelAccountService::STATUS_SUSPENDED) {
                throw new RetryableCpanelException('WHM account suspension was not confirmed by a matching read-back.', [
                    'error_code' => 'CPANEL_STATE_CHANGE_NOT_CONFIRMED',
                ]);
            }
            return [
                'status' => PanelAccountService::STATUS_SUSPENDED,
                'metadata' => ['username' => $username, 'changed' => !empty($operation['changed']), 'confirmed' => true],
                'job_result' => [
                    'panel_account_id' => (int) $account['id'], 'status' => PanelAccountService::STATUS_SUSPENDED,
                    'changed' => !empty($operation['changed']), 'confirmed' => true,
                ],
            ];
        }

        if ($action === PanelAccountService::ACTION_UNSUSPEND) {
            $account = $this->recheckActionScope($job, $account, $jobId, $action);
            $this->assertNotCancelled($jobId);
            $operation = $this->panels->unsuspendAccount($serverId, $username);
            $remote = isset($operation['account']) && is_array($operation['account'])
                ? $operation['account'] : $this->panels->getAccount($serverId, $username);
            if ($remote === null || $this->statusForAccount($account, $remote) !== PanelAccountService::STATUS_ACTIVE) {
                throw new RetryableCpanelException('WHM account unsuspension was not confirmed by a matching read-back.', [
                    'error_code' => 'CPANEL_STATE_CHANGE_NOT_CONFIRMED',
                ]);
            }
            return [
                'status' => PanelAccountService::STATUS_ACTIVE,
                'metadata' => ['username' => $username, 'changed' => !empty($operation['changed']), 'confirmed' => true],
                'job_result' => [
                    'panel_account_id' => (int) $account['id'], 'status' => PanelAccountService::STATUS_ACTIVE,
                    'changed' => !empty($operation['changed']), 'confirmed' => true,
                ],
            ];
        }

        if ($action === PanelAccountService::ACTION_TERMINATE) {
            $account = $this->recheckActionScope($job, $account, $jobId, $action);
            $this->assertNotCancelled($jobId);
            $confirmation = 'TERMINATE ' . $username;
            $operation = $this->panels->terminateAccount($serverId, $username, $confirmation);
            if (empty($operation['terminated']) || empty($operation['confirmed'])) {
                throw new RetryableCpanelException('WHM account termination was not confirmed by account absence.', [
                    'error_code' => 'CPANEL_TERMINATION_NOT_CONFIRMED',
                ]);
            }
            return [
                'status' => PanelAccountService::STATUS_TERMINATED,
                'metadata' => ['username' => $username, 'already_absent' => !empty($operation['already_absent']),
                    'confirmed' => true],
                'job_result' => [
                    'panel_account_id' => (int) $account['id'], 'status' => PanelAccountService::STATUS_TERMINATED,
                    'already_absent' => !empty($operation['already_absent']), 'confirmed' => true,
                ],
            ];
        }

        throw new ConflictException('The control-panel worker received an unsupported lifecycle action.');
    }

    private function statusForAccount(array $expected, array $remote)
    {
        $username = isset($remote['username']) ? strtolower((string) $remote['username']) : '';
        $domain = isset($remote['domain']) ? strtolower((string) $remote['domain']) : '';
        $package = isset($remote['package']) ? (string) $remote['package'] : '';
        if ($username !== strtolower((string) $expected['username'])
            || $domain !== strtolower((string) $expected['domain'])
            || $package !== (string) $expected['package']) {
            throw new CpanelException('The WHM account does not match the WHMCS service binding.', [
                'error_code' => 'CPANEL_ACCOUNT_BINDING_MISMATCH',
            ]);
        }
        if (!array_key_exists('suspended', $remote) || !is_bool($remote['suspended'])) {
            throw new CpanelException('WHM did not return a verifiable account suspension state.', [
                'error_code' => 'CPANEL_STATE_UNVERIFIED',
            ]);
        }
        return $remote['suspended'] ? PanelAccountService::STATUS_SUSPENDED : PanelAccountService::STATUS_ACTIVE;
    }

    private function ensureCredentialVerified($serverId)
    {
        $credential = Db::first('server_credentials', [
            'server_id' => (int) $serverId,
            'credential_type' => CredentialVault::TYPE_WHM_API_TOKEN,
            'name' => 'primary',
            'status' => CredentialVault::STATUS_ACTIVE,
        ]);
        if (!$credential || empty($credential['username'])) {
            throw new ConfigurationException('The registered cPanel server has no active named WHM API token.', [
                'error_code' => 'CPANEL_CREDENTIAL_MISSING',
            ]);
        }
        if (!empty($credential['expires_at']) && Clock::isPast($credential['expires_at'])) {
            throw new ConfigurationException('The registered WHM API token has expired.', [
                'error_code' => 'CPANEL_CREDENTIAL_EXPIRED',
            ]);
        }
        if (empty($credential['verified'])) {
            $this->panels->verifyServer($serverId);
        }
    }

    private function failJob($jobId, $accountId, \Throwable $e)
    {
        $retryable = $e instanceof AppsException ? $e->isRetryable() : false;
        $code = $e instanceof AppsException ? $e->errorCode() : 'PANEL_ACCOUNT_OPERATION_FAILED';
        $message = $this->safeFailureMessage($e);
        $current = $this->queue->find($jobId);
        $attempts = $current ? (int) $current['attempts'] : 0;
        $maxAttempts = $current ? max(1, (int) $current['max_attempts']) : 1;
        $willRetry = $retryable && $attempts < $maxAttempts;
        try {
            Db::transaction(function () use ($jobId, $message, $code, $retryable, $e, $accountId) {
                $failed = $this->queue->fail($jobId, $message, $code, $retryable, $e);
                if ($accountId > 0) {
                    $this->accounts->workerFailure($accountId, $jobId, $code, $message,
                        (string) $failed['status'] !== JobQueue::STATUS_QUEUED);
                }
            });
        } catch (\Throwable $recordingError) {
            Logger::error('Panel-account job failure could not be fully recorded.', [
                'job_id' => $jobId, 'panel_account_id' => $accountId,
                'exception' => get_class($recordingError), 'source' => 'control_panel',
            ]);
        }
        if ($retryable && $willRetry) {
            throw $e;
        }
        return ['status' => 'failed', 'panel_account_id' => $accountId,
            'error' => ['code' => $code, 'message' => $message, 'retryable' => false]];
    }

    private function safeFailureMessage(\Throwable $e)
    {
        if ($e instanceof ConfigurationException || $e instanceof ConflictException || $e instanceof StateException) {
            return $e->getMessage();
        }
        if ($e instanceof CpanelException) {
            return $e->getMessage();
        }
        if ($e instanceof AppsException) {
            return $e->getMessage();
        }
        return 'The cPanel account operation failed unexpectedly.';
    }

    private static function actionForType($jobType)
    {
        $map = [
            JobQueue::TYPE_PANEL_ACCOUNT_VERIFY => PanelAccountService::ACTION_VERIFY,
            JobQueue::TYPE_PANEL_ACCOUNT_SUSPEND => PanelAccountService::ACTION_SUSPEND,
            JobQueue::TYPE_PANEL_ACCOUNT_UNSUSPEND => PanelAccountService::ACTION_UNSUSPEND,
            JobQueue::TYPE_PANEL_ACCOUNT_TERMINATE => PanelAccountService::ACTION_TERMINATE,
        ];
        if (!isset($map[$jobType])) {
            throw new ConflictException('The control-panel worker received an unknown job type.');
        }
        return $map[$jobType];
    }
}
