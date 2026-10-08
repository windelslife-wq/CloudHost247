<?php
/** Verifies provider credentials outside request handlers using a real adapter. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Deployments\JobQueue;

class ProviderAccountVerifyWorker
{
    private $actor;
    private $queue;
    private $accounts;

    public function __construct(Actor $actor, JobQueue $queue = null, ProviderAccountService $accounts = null)
    {
        if (!$actor->isSystem()) {
            throw new AuthorizationException('Only the system worker may verify provider accounts.');
        }
        $this->actor = $actor;
        $this->queue = $queue ?: new JobQueue();
        $this->accounts = $accounts ?: new ProviderAccountService($actor, $this->queue);
    }

    public function runJob(array $job)
    {
        $jobId = (int) $job['id'];
        $payload = $this->queue->payload($job);
        $accountId = !empty($job['provider_account_id']) ? (int) $job['provider_account_id']
            : (isset($payload['provider_account_id']) ? (int) $payload['provider_account_id'] : 0);
        $this->queue->start($jobId, isset($job['leased_by']) ? $job['leased_by'] : 'provider-worker');
        try {
            if ($accountId <= 0) {
                throw new \Ch247Apps\Core\ValidationException('The verification job has no provider-account reference.');
            }
            $account = $this->accounts->verify($accountId);
            $result = ['provider_account_id' => $accountId, 'status' => $account['status'],
                'last_verified_at' => $account['last_verified_at']];
            $this->queue->complete($jobId, $result);
            return ['status' => 'completed', 'result' => $result];
        } catch (\Throwable $e) {
            $retryable = $e instanceof AppsException ? $e->isRetryable() : false;
            $code = $e instanceof AppsException ? $e->errorCode() : 'PROVIDER_VERIFICATION_FAILED';
            $message = $this->safeFailureMessage($e);
            $this->queue->fail($jobId, $message, $code, $retryable, $e);
            if ($retryable) {
                throw new \Ch247Apps\Core\RetryableProviderException($message, ['error_code' => $code], $e);
            }
            return ['status' => 'failed', 'error' => ['code' => $code, 'message' => $message]];
        }
    }

    private function safeFailureMessage(\Throwable $e)
    {
        if ($e instanceof \Ch247Apps\Core\ProviderAuthenticationException) {
            return 'The provider rejected the configured credentials.';
        }
        if ($e instanceof \Ch247Apps\Core\ProviderOperationException) {
            return 'Provider credential verification failed at the provider boundary.';
        }
        if ($e instanceof \Ch247Apps\Core\ProviderUnavailableException
            || $e instanceof \Ch247Apps\Core\ProviderConfigurationException) {
            return $e->getMessage();
        }
        if ($e instanceof AppsException) {
            return $e->getMessage();
        }
        return 'Provider verification failed unexpectedly.';
    }
}
