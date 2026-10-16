<?php
/** Read-only view of WHMCS services currently marked Pending. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

/**
 * Pending status and registration age are observable from tblhosting. This is
 * not a provisioning event log and does not prove a module job is stuck.
 */
function ch247ai_pending_provisioning_services(array $args = [])
{
    if (!Db::whmcsTableExists('tblhosting')) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: WHMCS service table tblhosting is not present.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $sql = "SELECT id, userid, packageid, domain, domainstatus, regdate, server "
        . "FROM tblhosting WHERE domainstatus = ? ORDER BY regdate ASC, id ASC LIMIT " . $limit;
    $bind = ['Pending'];
    $rows = Db::query($sql, $bind);
    foreach ($rows as &$row) {
        $created = Clock::toTime(isset($row['regdate']) ? $row['regdate'] : null);
        $row['age_days_since_regdate'] = $created === null || $created > Clock::time()
            ? null : (int) floor((Clock::time() - $created) / 86400);
    }
    unset($row);
    return [
        'services' => $rows,
        'count' => count($rows),
        'interpretation' => 'These WHMCS services currently have Pending status. Age is measured from regdate; it is not proof of a failed or stuck provisioning job.',
        '_citations' => [$sql . ' | params=' . json_encode($bind, JSON_UNESCAPED_SLASHES)],
    ];
}

function ch247ai_provisioning_queue_tool()
{
    return new ToolDefinition(
        'read_provisioning_queue',
        'read.services',
        'READ',
        'List WHMCS services currently marked Pending and their age since regdate. This is not a provisioning event log and cannot confirm a job is stuck.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum pending services to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_pending_provisioning_services($args);
        },
        ['entity' => 'tblhosting']
    );
}
