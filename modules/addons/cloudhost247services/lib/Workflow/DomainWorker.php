<?php
/**
 * Domain platform worker.
 *
 * Dispatches queued jobs to their service handlers. Long-running and
 * provider-touching work never runs inside an HTTP request — it runs here,
 * from the module cron (every 5 minutes) or a host queue worker.
 *
 * Every handler either completes the job or throws; JobQueue::run() records
 * the failure with the exception's machine code and applies retry/backoff.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

use Chs\Core\Settings;
use Chs\Services\AuctionService;
use Chs\Services\BulkSearchService;
use Chs\Services\DomainManagementService;
use Chs\Services\NotificationService;
use Chs\Services\RegistrationService;
use Chs\Services\RenewalService;
use Chs\Services\TransferService;
use Chs\Services\WhoisService;

class DomainWorker
{
    /** @var \Chs\Providers\Domain\ProviderRegistry|null test seam */
    public static $registryOverride;

    /** Registry used by all handlers (override only in tests). */
    public static function registry()
    {
        return self::$registryOverride ?: new \Chs\Providers\Domain\ProviderRegistry();
    }

    /**
     * @return callable[] job type => handler(array $job): void
     */
    public static function handlers()
    {
        return [
            DomainJobTypes::BULK_SEARCH => function (array $job) {
                $payload = $job['payload'];
                (new BulkSearchService(null, new \Chs\Services\DomainSearchService(self::registry())))
                    ->processJob(isset($payload['search_id']) ? (int) $payload['search_id'] : 0);
            },
            DomainJobTypes::AVAILABILITY_CHECK => function (array $job) {
                // Refresh one cached availability answer (kept honest: the
                // provider decides; unknown stays unknown).
                $payload = $job['payload'];
                $domain = \Chs\Core\DomainName::tryParse(isset($payload['domain']) ? (string) $payload['domain'] : '');
                if (!$domain) {
                    throw new \Chs\Core\ValidationException(['domain' => 'Malformed availability job.']);
                }
                $tldRow = (new \Chs\Services\TldCatalogService())->detail($domain->tld());
                (new \Chs\Services\DomainSearchService())->checkOne($domain, $tldRow);
            },
            DomainJobTypes::REGISTRATION => function (array $job) {
                (new RegistrationService(null, self::registry()))->execute($job['payload']);
            },
            DomainJobTypes::TRANSFER => function (array $job) {
                $payload = $job['payload'];
                (new TransferService(self::registry()))
                    ->executeProviderStep(isset($payload['transfer_id']) ? (int) $payload['transfer_id'] : 0);
            },
            DomainJobTypes::RENEWAL => function (array $job) {
                $payload = $job['payload'];
                (new RenewalService(null, self::registry()))
                    ->execute(isset($payload['renewal_id']) ? (int) $payload['renewal_id'] : 0);
            },
            DomainJobTypes::DNS_SYNC => function (array $job) {
                $payload = $job['payload'];
                (new DomainManagementService(self::registry()))
                    ->syncDns(isset($payload['domain_service_id']) ? (int) $payload['domain_service_id'] : 0);
            },
            DomainJobTypes::WHOIS_LOOKUP => function (array $job) {
                $payload = $job['payload'];
                $fqdn = isset($payload['domain']) ? (string) $payload['domain'] : '';
                if ($fqdn === '') {
                    throw new \Chs\Core\ValidationException(['domain' => 'Malformed WHOIS job.']);
                }
                (new WhoisService())->lookup($fqdn); // cached provider refreshes honestly
            },
            DomainJobTypes::AUCTION_CLOSE => function (array $job) {
                unset($job);
                (new AuctionService())->heartbeat();
            },
            DomainJobTypes::AUCTION_PAYMENT_CHECK => function (array $job) {
                unset($job);
                (new AuctionService())->lapseOverdueInvoices();
            },
            DomainJobTypes::EXPIRATION_CHECK => function (array $job) {
                unset($job);
                (new RenewalService(null, self::registry()))->scanExpirations();
                (new TransferService(self::registry()))->syncFromPlatform();
            },
            DomainJobTypes::PROVIDER_SYNC => function (array $job) {
                unset($job);
                (new DomainManagementService(self::registry()))->syncFromPlatform();
                self::registry()->healthCheckAll();
            },
            DomainJobTypes::RECONCILIATION => function (array $job) {
                unset($job);
                (new DomainManagementService(self::registry()))->syncFromPlatform();
                (new TransferService(self::registry()))->syncFromPlatform();
            },
            DomainJobTypes::NOTIFICATION => function (array $job) {
                $payload = $job['payload'];
                (new NotificationService())->notify(
                    isset($payload['client_id']) ? (int) $payload['client_id'] : 0,
                    isset($payload['type']) ? (string) $payload['type'] : 'domain',
                    isset($payload['subject']) ? (string) $payload['subject'] : '',
                    isset($payload['body']) ? (string) $payload['body'] : '',
                    isset($payload['link']) ? (string) $payload['link'] : ''
                );
            },
        ];
    }

    /**
     * Drain due jobs. @return array{ran:int, completed:int, failed:int}
     */
    public static function run($maxJobs = null, $leaseSeconds = null)
    {
        $maxJobs = $maxJobs === null ? max(1, Settings::int('jobs_per_run', 25)) : (int) $maxJobs;
        $leaseSeconds = $leaseSeconds === null ? max(30, Settings::int('jobs_lease_seconds', 300)) : (int) $leaseSeconds;
        return (new JobQueue())->run(self::handlers(), $maxJobs, $leaseSeconds);
    }
}
