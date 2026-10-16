<?php
/** Read-only inventory of the server records configured in WHMCS. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

/**
 * Return only non-secret fields from tblservers. This is a WHMCS configuration
 * inventory, not live health, hardware, license, or datacenter telemetry.
 */
function ch247ai_server_inventory(array $args = [])
{
    if (!Db::whmcsTableExists('tblservers')) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: WHMCS server inventory table tblservers is not present.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT id, name, hostname, ipaddress, type, active, maxaccounts, statusaddress '
        . 'FROM tblservers ORDER BY id ASC LIMIT ' . $limit;
    $rows = Db::query($sql, []);
    return [
        'servers' => $rows,
        'count' => count($rows),
        'scope' => 'Configured WHMCS server records only; this is not live reachability or resource telemetry.',
        '_citations' => [
            $sql . ' | params=[]',
            'Source: tblservers. Credential fields (including password and accesshash) are deliberately excluded.',
        ],
    ];
}

function ch247ai_server_inventory_tool()
{
    return new ToolDefinition(
        'read_server_inventory',
        'read.metrics',
        'READ',
        'Read the non-secret configured server records in WHMCS tblservers. This does not test reachability or report CPU, memory, disk, licenses, or physical assets.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum server records to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_server_inventory($args);
        },
        ['entity' => 'tblservers']
    );
}
