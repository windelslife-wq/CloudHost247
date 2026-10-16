<?php
/** Read-only deployment summaries from the CloudHost247 App Cloud addon. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_deployment_table_exists()
{
    $table = 'mod_ch247apps_deployments';
    if (Db::driver() === 'sqlite') {
        return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }
    return Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
}

/** Return bounded, non-secret deployment metadata; omit payloads, logs and errors. */
function ch247ai_deployment_status(array $args = [])
{
    if (!ch247ai_deployment_table_exists()) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: CloudHost247 App Cloud deployment records are not installed.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT id, reference, action, status, adapter, progress, current_step, steps_total, '
        . 'steps_completed, attempts, error_code, queued_at, started_at, completed_at, duration_ms '
        . 'FROM mod_ch247apps_deployments ORDER BY id DESC LIMIT ' . $limit;
    $rows = Db::query($sql, []);
    return [
        'deployments' => $rows,
        'count' => count($rows),
        'scope' => 'CloudHost247 App Cloud deployment records only.',
        'limitations' => 'Payloads, deployment logs, customer identifiers, and free-text error messages are omitted to avoid exposing secrets. This reports recorded deployment state, not external application availability.',
        '_citations' => [$sql . ' | params=[]'],
    ];
}

function ch247ai_deployment_status_tool()
{
    return new ToolDefinition(
        'read_deployment_status',
        'read.metrics',
        'READ',
        'Read bounded deployment status summaries from CloudHost247 App Cloud. Payloads, logs, customer identifiers and free-text errors are excluded.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum deployment records to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_deployment_status($args);
        },
        ['entity' => 'mod_ch247apps_deployments']
    );
}
