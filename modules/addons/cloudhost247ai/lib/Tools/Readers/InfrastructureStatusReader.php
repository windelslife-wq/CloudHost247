<?php
/** Read-only provider-status snapshot from the CloudHost247 Services addon. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

/** Check a fixed cross-addon table name without depending on that addon's PHP code. */
function ch247ai_infrastructure_table_exists($table)
{
    if (!in_array($table, ['mod_chs_module_servers'], true)) {
        return false;
    }
    if (Db::driver() === 'sqlite') {
        return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }
    return Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
}

/**
 * Read latest stored provider status for servers managed by cloudhost247services.
 * The status was last reconciled by that module's provider worker; this is not
 * CPU, memory, disk, network-quality or an independent uptime probe.
 */
function ch247ai_infrastructure_status(array $args = [])
{
    $table = 'mod_chs_module_servers';
    if (!ch247ai_infrastructure_table_exists($table)) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: CloudHost247 Services infrastructure registry is not installed.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT id, hostname, ip_address, status, provider_id, provisioned_at, updated_at '
        . 'FROM ' . $table . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit;
    $rows = Db::query($sql, []);
    return [
        'servers' => $rows,
        'count' => count($rows),
        'source' => 'CloudHost247 Services module_servers provider-status snapshot',
        'limitations' => 'Status reflects the last provider synchronization stored by CloudHost247 Services; it is not an independent uptime probe or CPU, memory, disk, or network telemetry.',
        '_citations' => [$sql . ' | params=[]'],
    ];
}

function ch247ai_infrastructure_status_tool()
{
    return new ToolDefinition(
        'read_infrastructure_status',
        'read.metrics',
        'READ',
        'Read stored provider status for infrastructure servers managed by CloudHost247 Services. This is not independent uptime or resource telemetry.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum server records to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_infrastructure_status($args);
        },
        ['entity' => 'mod_chs_module_servers']
    );
}
