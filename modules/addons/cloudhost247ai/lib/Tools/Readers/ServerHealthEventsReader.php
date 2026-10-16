<?php
/** Read-only history of explicit provider server health-check events. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_server_health_audit_table_exists()
{
    $table = 'mod_chs_audit_log';
    if (Db::driver() === 'sqlite') {
        return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }
    return Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
}

/** Return only safe fields from explicit health-check audit events. */
function ch247ai_server_health_events(array $args = [])
{
    if (!ch247ai_server_health_audit_table_exists()) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: CloudHost247 Services health-check audit log is not installed.');
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $sql = "SELECT id, action, context, created_at FROM mod_chs_audit_log "
        . "WHERE action IN (?, ?) ORDER BY id DESC LIMIT " . $limit;
    $bind = ['server.health_check_passed', 'server.health_check_failed'];
    $rows = Db::query($sql, $bind);
    $events = [];
    foreach ($rows as $row) {
        $context = json_decode(isset($row['context']) ? (string) $row['context'] : '', true);
        if (!is_array($context)) {
            $context = [];
        }
        $events[] = [
            'event_id' => (int) $row['id'],
            'result' => $row['action'] === 'server.health_check_passed' ? 'passed' : 'failed',
            'module_server_id' => isset($context['module_server_id']) ? (int) $context['module_server_id'] : null,
            'provider_status' => isset($context['provider_status']) ? (string) $context['provider_status'] : null,
            'error_code' => isset($context['error_code']) ? (string) $context['error_code'] : null,
            'checked_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }
    return [
        'events' => $events,
        'count' => count($events),
        'limitations' => 'These are recorded provider health-check events from CloudHost247 Services only. Missing events do not prove a server is healthy; no CPU, memory, disk, network-quality, or independent uptime telemetry is collected.',
        '_citations' => [$sql . ' | params=' . json_encode($bind, JSON_UNESCAPED_SLASHES)],
    ];
}

function ch247ai_server_health_events_tool()
{
    return new ToolDefinition(
        'read_server_health_checks',
        'read.metrics',
        'READ',
        'Read explicit passed/failed provider health-check audit events from CloudHost247 Services. Does not provide resource metrics or continuous uptime monitoring.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum health-check events to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_server_health_events($args);
        },
        ['entity' => 'mod_chs_audit_log']
    );
}
