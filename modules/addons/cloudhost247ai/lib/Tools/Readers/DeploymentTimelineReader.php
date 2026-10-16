<?php
/** Read-only structured deployment timeline, excluding free-text output/logs. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_deployment_timeline_tables_exist()
{
    foreach (['mod_ch247apps_deployments', 'mod_ch247apps_deployment_steps'] as $table) {
        if (Db::driver() === 'sqlite') {
            $exists = Db::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
        } else {
            $exists = Db::query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== [];
        }
        if (!$exists) {
            return false;
        }
    }
    return true;
}

/** Return the recorded sequence for one deployment; never return logs or context. */
function ch247ai_deployment_timeline($deploymentId)
{
    if (!ch247ai_deployment_timeline_tables_exist()) {
        throw new ServiceUnavailableException('DATA_UNAVAILABLE: CloudHost247 App Cloud deployment timeline tables are not installed.');
    }
    $deploymentId = (int) $deploymentId;
    if ($deploymentId < 1) {
        throw new \Ch247Ai\Core\ValidationException('Deployment id must be a positive integer.');
    }
    $deploymentSql = 'SELECT id, reference, action, status, current_step, error_code, queued_at, started_at, completed_at '
        . 'FROM mod_ch247apps_deployments WHERE id = ?';
    $deployments = Db::query($deploymentSql, [$deploymentId]);
    if (!$deployments) {
        throw new NotFoundException('Deployment record not found.');
    }
    $stepsSql = 'SELECT step_order, name, `key` AS step_key, status, error_code, started_at, completed_at, duration_ms '
        . 'FROM mod_ch247apps_deployment_steps WHERE deployment_id = ? ORDER BY step_order ASC LIMIT 100';
    $steps = Db::query($stepsSql, [$deploymentId]);
    return [
        'deployment' => $deployments[0],
        'steps' => $steps,
        'interpretation' => 'This is a recorded event sequence. A failed step and its error code identify where execution stopped, but do not by themselves establish an underlying root cause.',
        '_citations' => [
            $deploymentSql . ' | params=' . json_encode([$deploymentId]),
            $stepsSql . ' | params=' . json_encode([$deploymentId]),
        ],
    ];
}

function ch247ai_deployment_timeline_tool()
{
    return new ToolDefinition(
        'read_deployment_timeline',
        'read.metrics',
        'READ',
        'Read one App Cloud deployment record and its ordered structured steps. Free-text logs, output, payload and context are excluded.',
        [
            'deployment_id' => ['type' => 'integer', 'description' => 'Deployment record id'],
        ],
        function (array $args, array $ctx) {
            return ch247ai_deployment_timeline($args['deployment_id']);
        },
        ['entity' => 'mod_ch247apps_deployments']
    );
}
