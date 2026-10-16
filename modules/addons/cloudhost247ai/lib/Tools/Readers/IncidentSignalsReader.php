<?php
/** Read-only operational failure signals from existing module event tables. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_incident_source_exists($table)
{
    $allowed = ['mod_ch247apps_deployments', 'mod_chs_provisioning_jobs', 'mod_chs_audit_log'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    if (Db::driver() === 'sqlite') {
        return Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }
    return Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
}

function ch247ai_incident_failure_signals(array $args = [])
{
    $hours = isset($args['window_hours']) ? max(1, min(168, (int) $args['window_hours'])) : 24;
    $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 50;
    $since = Clock::ago($hours * 3600);
    $signals = [];
    $sources = [];

    if (ch247ai_incident_source_exists('mod_ch247apps_deployments')) {
        $sql = "SELECT id, reference, action, status, error_code, completed_at, created_at "
            . "FROM mod_ch247apps_deployments WHERE status IN (?, ?) AND COALESCE(NULLIF(completed_at, ''), created_at) >= ? "
            . "ORDER BY COALESCE(NULLIF(completed_at, ''), created_at) DESC LIMIT " . $limit;
        foreach (Db::query($sql, ['failed', 'timeout', $since]) as $row) {
            $signals[] = [
                'source' => 'app_cloud_deployment', 'record_id' => (int) $row['id'],
                'reference' => (string) $row['reference'], 'action' => (string) $row['action'],
                'status' => (string) $row['status'], 'error_code' => isset($row['error_code']) ? (string) $row['error_code'] : '',
                'occurred_at' => !empty($row['completed_at']) ? $row['completed_at'] : $row['created_at'],
            ];
        }
        $sources[] = 'mod_ch247apps_deployments';
    }

    if (ch247ai_incident_source_exists('mod_chs_provisioning_jobs')) {
        $sql = "SELECT id, type, status, error_code, module_server_id, failed_at, created_at "
            . "FROM mod_chs_provisioning_jobs WHERE status = ? AND COALESCE(failed_at, updated_at, created_at) >= ? "
            . "ORDER BY COALESCE(failed_at, updated_at, created_at) DESC LIMIT " . $limit;
        foreach (Db::query($sql, ['FAILED', $since]) as $row) {
            $signals[] = [
                'source' => 'infrastructure_provisioning', 'record_id' => (int) $row['id'],
                'action' => (string) $row['type'], 'status' => (string) $row['status'],
                'error_code' => isset($row['error_code']) ? (string) $row['error_code'] : '',
                'resource_id' => isset($row['module_server_id']) ? (int) $row['module_server_id'] : null,
                'occurred_at' => !empty($row['failed_at']) ? $row['failed_at'] : $row['created_at'],
            ];
        }
        $sources[] = 'mod_chs_provisioning_jobs';
    }

    if (ch247ai_incident_source_exists('mod_chs_audit_log')) {
        $sql = 'SELECT id, context, created_at FROM mod_chs_audit_log WHERE action = ? AND created_at >= ? ORDER BY id DESC LIMIT ' . $limit;
        foreach (Db::query($sql, ['server.health_check_failed', $since]) as $row) {
            $context = json_decode(isset($row['context']) ? (string) $row['context'] : '', true);
            if (!is_array($context)) {
                $context = [];
            }
            $signals[] = [
                'source' => 'infrastructure_health_check', 'record_id' => (int) $row['id'],
                'action' => 'health_check', 'status' => 'failed',
                'error_code' => isset($context['error_code']) ? (string) $context['error_code'] : '',
                'resource_id' => isset($context['module_server_id']) ? (int) $context['module_server_id'] : null,
                'occurred_at' => (string) $row['created_at'],
            ];
        }
        $sources[] = 'mod_chs_audit_log';
    }

    usort($signals, function ($a, $b) {
        return strcmp((string) $b['occurred_at'], (string) $a['occurred_at']);
    });
    $signals = array_slice($signals, 0, $limit);
    return [
        'signals' => $signals,
        'count' => count($signals),
        'window_start' => $since,
        'sources_available' => $sources,
        'interpretation' => 'These are separate recorded failed operations, not correlated or confirmed incidents. No root cause, customer impact, or recovery state is inferred.',
        '_citations' => array_map(function ($source) { return 'Source table: ' . $source; }, $sources),
    ];
}

function ch247ai_incident_signals_tool()
{
    return new ToolDefinition(
        'read_incident_failure_signals',
        'read.metrics',
        'READ',
        'List recent recorded deployment/provisioning/health-check failures. Signals are not correlated incidents and contain no free-text logs.',
        [
            'window_hours' => ['type' => 'integer', 'description' => 'Look-back window in hours (1-168)', 'required' => false],
            'limit' => ['type' => 'integer', 'description' => 'Maximum combined signals (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            return ch247ai_incident_failure_signals($args);
        },
        ['entity' => 'operational_failure_signals']
    );
}
