<?php
/**
 * Bounded cleanup of explicitly tagged agent_linux_uptime samples only.
 * Untagged/legacy/application/provider metrics are never candidates.
 */
namespace Ch247Apps\Servers;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\Settings;

class UptimeRetention
{
    private $actor;

    public function __construct(Actor $actor)
    {
        $this->actor = $actor;
    }

    /** At most 200 rows per invocation; later cron ticks drain the backlog. */
    public function prune()
    {
        if (!$this->actor->isSystem()) {
            throw new AuthorizationException('Only scheduled maintenance may prune node uptime.');
        }
        if (!Settings::bool('agent_uptime_retention_enabled', false)) {
            return 0;
        }
        $raw = Settings::get('agent_uptime_retention_days', '30');
        if (!is_scalar($raw) || !preg_match('/^[1-9][0-9]{0,3}$/D', (string) $raw)
            || (int) $raw > 3650) {
            throw new ConfigurationException('Set an agent uptime retention period of 1–3650 days.');
        }
        if (!Db::tableExists('metrics') || !(new Migrator())->hasColumn('metrics', 'source')) {
            throw new ConfigurationException('The agent uptime source migration must run before retention.');
        }
        $cutoff = Clock::at(-((int) $raw * 86400));
        $where = [
            'source' => ServerService::METRIC_SOURCE_AGENT_UPTIME,
            'sampled_at' => ['<', $cutoff],
        ];
        $rows = Db::fetch('metrics', $where, [
            'order' => 'sampled_at', 'dir' => 'asc', 'order2' => 'id', 'dir2' => 'asc',
            'limit' => 200, 'columns' => ['id'],
        ]);
        if (!$rows) {
            return 0;
        }
        // Repeat the source/time predicate at deletion time so a stale ID list
        // cannot ever touch a row that no longer qualifies.
        $where['id'] = ['in', array_map('intval', array_column($rows, 'id'))];
        $deleted = Db::delete('metrics', $where);
        Logger::info('Expired node uptime samples pruned.', [
            'deleted' => $deleted, 'retention_days' => (int) $raw, 'source' => 'cron',
        ]);
        return $deleted;
    }
}
