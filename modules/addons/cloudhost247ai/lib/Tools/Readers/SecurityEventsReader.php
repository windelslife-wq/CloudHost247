<?php
/** Read-only aggregate of conservatively classified login events. */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_security_events_tool()
{
    return new ToolDefinition(
        'read_security_auth_events',
        'read.metrics',
        'READ',
        'Read recent explicitly classified successful/failed login events collected from WHMCS activity logs. Ambiguous activity descriptions are ignored.',
        [
            'limit' => ['type' => 'integer', 'description' => 'Maximum recent events to return (1-100)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            if (!Db::tableExists('security_auth_events')) {
                throw new ServiceUnavailableException('DATA_UNAVAILABLE: security auth-event projection is not installed.');
            }
            $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 50;
            $since = Clock::ago(86400);
            $countsSql = 'SELECT event_type, COUNT(*) AS event_count FROM ' . Db::t('security_auth_events')
                . ' WHERE occurred_at >= ? GROUP BY event_type ORDER BY event_type ASC';
            $counts = Db::query($countsSql, [$since]);
            $eventsSql = 'SELECT source_activity_id, event_type, actor_id, occurred_at FROM ' . Db::t('security_auth_events')
                . ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit;
            $events = Db::query($eventsSql, []);
            return [
                'window_start' => $since,
                'last_24h_counts' => $counts,
                'recent_events' => $events,
                'count' => count($events),
                'limitations' => 'Only explicit login success/failure phrases in WHMCS tblactivitylog are collected; ambiguous descriptions are ignored. These counts are not proof of attack or compromise. IPs are stored only as keyed hashes and are not exposed to the model.',
                '_citations' => [
                    $countsSql . ' | params=' . json_encode([$since]),
                    $eventsSql . ' | params=[]',
                ],
            ];
        },
        ['entity' => 'security_auth_events']
    );
}
