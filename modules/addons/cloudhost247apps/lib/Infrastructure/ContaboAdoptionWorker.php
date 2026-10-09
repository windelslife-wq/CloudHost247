<?php
/** Worker-only read-back: bind a paid service to an existing instance, never order/cancel. */
namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Deployments\JobQueue;

class ContaboAdoptionWorker
{
    private $actor;
    private $queue;
    private $service;

    public function __construct(Actor $actor, JobQueue $queue = null, ContaboAdoptionService $service = null)
    {
        if (!$actor->isSystem()) { throw new AuthorizationException('Only the system worker may read Contabo instances.'); }
        $this->actor = $actor;
        $this->queue = $queue ?: new JobQueue();
        $this->service = $service ?: new ContaboAdoptionService($actor, null, $this->queue);
    }

    public function runJob(array $job)
    {
        $jobId = (int) $job['id'];
        if ((string) $job['queue'] !== JobQueue::QUEUE_PROVISIONING) {
            $this->queue->fail($jobId, 'Contabo adoption must run on the provisioning queue.',
                'INFRASTRUCTURE_QUEUE_MISMATCH', false);
            return ['status' => 'failed', 'error' => ['code' => 'INFRASTRUCTURE_QUEUE_MISMATCH']];
        }
        $this->queue->start($jobId, isset($job['leased_by']) ? $job['leased_by'] : 'contabo-adoption-worker');
        $adoptionId = 0;
        try {
            $payload = $this->queue->payload($job);
            $adoptionId = isset($payload['adoption_id']) ? (int) $payload['adoption_id'] : 0;
            $row = $this->service->internalRow($adoptionId);
            if ($row['status'] !== 'pending') { throw new StateException('This Contabo adoption is no longer pending.'); }
            $this->service->assertEnabled();
            $this->service->billingAndProduct((int) $row['whmcs_service_id']);
            $this->service->assertAccount((int) $row['provider_account_id']);
            $accounts = new ProviderAccountService($this->actor, $this->queue);
            $context = $accounts->operationalContext((int) $row['provider_account_id'], ['server.get']);
            $raw = $context['adapter']->getServer($context['credentials'], $context['config'],
                (string) $row['provider_instance_id']);
            if (!is_array($raw) || !isset($raw['spec'], $raw['product_id'])
                || !is_array($raw['spec'])) { throw $this->invalidRead(); }
            $resource = ProviderResource::normalise($raw, (string) $row['provider_instance_id']);
            if (!in_array($resource['status'], ['active', 'off'], true)
                || $raw['spec'] !== Str::jsonDecode($row['expected_spec'], null)
                || $raw['product_id'] !== $row['expected_product_id']
                || ($resource['status'] === 'active' && $resource['ipv4'] === null && $resource['ipv6'] === null)) {
                throw $this->invalidRead();
            }
            // Recheck payment, account and the independent kill switch immediately
            // before binding. WHMCS can still change later; this is an audit
            // snapshot, not a transfer of billing or provider ownership.
            $this->service->assertEnabled();
            $billing = $this->service->billingAndProduct((int) $row['whmcs_service_id']);
            $this->service->assertAccount((int) $row['provider_account_id']);
            if ((int) $billing['client_id'] !== (int) $row['client_id']
                || (int) $billing['order_id'] !== (int) $row['whmcs_order_id']
                || (int) $billing['invoice_id'] !== (int) $row['whmcs_invoice_id']) {
                throw $this->invalidRead();
            }
            Db::transaction(function () use ($row, $resource, $raw, $adoptionId) {
                $latest = $this->service->internalRow($adoptionId);
                if ($latest['status'] !== 'pending'
                    || (string) $latest['provider_instance_id'] !== (string) $row['provider_instance_id']) {
                    throw new StateException('The Contabo adoption changed while it was being verified.');
                }
                Db::update('contabo_adoptions', [
                    'status' => 'verified', 'verified_spec' => Str::jsonEncode($raw['spec']),
                    'provider_status' => $resource['status'], 'ipv4' => $resource['ipv4'],
                    'ipv6' => $resource['ipv6'], 'error_code' => null, 'updated_at' => Clock::now(),
                ], ['id' => $adoptionId, 'status' => 'pending']);
                Audit::record($this->actor, 'CONTABO_ADOPTION_VERIFIED', [
                    'resource_type' => 'contabo_adoption', 'resource_id' => $adoptionId,
                    'metadata' => ['whmcs_service_id' => (int) $row['whmcs_service_id'],
                        'provider_account_id' => (int) $row['provider_account_id']],
                ]);
            });
            $result = ['adoption_id' => $adoptionId, 'status' => 'verified'];
            $this->queue->complete($jobId, $result);
            return ['status' => 'completed', 'result' => $result];
        } catch (\Throwable $e) {
            $retryable = $e instanceof AppsException && $e->isRetryable();
            $code = $e instanceof AppsException ? $e->errorCode() : 'ADOPTION_VERIFICATION_FAILED';
            $jobNow = $this->queue->find($jobId);
            $willRetry = $retryable && $jobNow && (int) $jobNow['attempts'] < (int) $jobNow['max_attempts'];
            if (!$willRetry && $adoptionId > 0
                && Db::first('contabo_adoptions', ['id' => $adoptionId, 'status' => 'pending'])) {
                Db::update('contabo_adoptions', ['status' => 'failed', 'error_code' => $code,
                    'updated_at' => Clock::now()], ['id' => $adoptionId, 'status' => 'pending']);
            }
            $this->queue->fail($jobId, 'Existing Contabo instance could not be verified; no provider contract was changed.',
                $code, $retryable, $e);
            if ($retryable) {
                throw new \Ch247Apps\Core\RetryableProviderException('Contabo instance verification is temporarily unavailable.',
                    ['error_code' => $code], $e);
            }
            return ['status' => 'failed', 'error' => ['code' => $code]];
        }
    }

    private function invalidRead()
    {
        return new ProviderOperationException('The Contabo instance does not match the approved service envelope.',
            ['error_code' => 'PROVIDER_RESOURCE_MISMATCH']);
    }
}
