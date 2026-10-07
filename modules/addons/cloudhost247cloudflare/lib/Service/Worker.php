<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Core\CloudflareException;

class Worker
{
    private $provisioning;
    public function __construct(ProvisioningService $provisioning = null) { $this->provisioning = $provisioning ?: new ProvisioningService(); }
    public function run($limit = 20)
    {
        $summary = ['claimed' => 0, 'completed' => 0, 'retrying' => 0, 'failed' => 0, 'skipped' => 0];
        $jobs = JobQueue::due($limit);
        foreach ($jobs as $job) {
            if (!JobQueue::claim($job)) { $summary['skipped']++; continue; }
            $summary['claimed']++;
            try {
                $payload = json_decode((string) ($job['payload_json'] ?? ''), true); if (!is_array($payload)) $payload = [];
                switch ((string) $job['kind']) {
                    case 'provision_service':
                        $service = $this->provisioning->retry((int) $job['service_id'], 'system', 0);
                        break;
                    case 'provision_addon':
                        $this->provisioning->provisionAddon((int) ($payload['whmcs_addon_id'] ?? 0));
                        break;
                    case 'sync_service':
                        $this->provisioning->sync((int) $job['service_id'], 'system', 0);
                        break;
                    case 'sync_origin':
                        $this->provisioning->syncOriginIp((int) $job['service_id'], 'system', 0);
                        break;
                    default:
                        throw new \RuntimeException('Unknown Cloudflare job type.');
                }
                JobQueue::complete((int) $job['id']); $summary['completed']++;
            } catch (\Throwable $e) {
                $code = $e instanceof CloudflareException ? $e->errorCode() : 'CLOUDFLARE_API_ERROR';
                $message = $e instanceof CloudflareException ? $e->getMessage() : 'Cloudflare background job failed.';
                $state = JobQueue::fail($job, $code, $message);
                if ($state['terminal']) $summary['failed']++; else $summary['retrying']++;
                Logger::error('background job failed', ['job_id' => (int) $job['id'], 'kind' => $job['kind'], 'service_id' => (int) ($job['service_id'] ?? 0), 'error_code' => $code, 'terminal' => $state['terminal']]);
            }
        }
        return $summary;
    }
    public function enqueueDueSyncs($limit = 20)
    {
        $cutoff = Clock::before(3600);
        $rows = Db::query('SELECT id FROM `' . Db::table('services') . '` WHERE zone_id IS NOT NULL AND status IN (\'ACTIVE\',\'PENDING_NAMESERVER_UPDATE\') AND (last_synced_at IS NULL OR last_synced_at<?) ORDER BY COALESCE(last_synced_at,\'1970-01-01 00:00:00\') ASC LIMIT ' . max(1, min(100, (int) $limit)), [$cutoff]);
        foreach ($rows as $row) {
            $hour = gmdate('YmdH');
            JobQueue::enqueue('sync_service', (int) $row['id'], [], 'sync:' . (int) $row['id'] . ':' . $hour);
        }
        return count($rows);
    }
}
