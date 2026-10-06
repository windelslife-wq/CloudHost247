<?php
/**
 * Agent memory (plan §7 `memories`). Two scopes:
 *  - operational: appended by the event drain (facts, from real events)
 *  - short: appended when a run finishes (what was asked/answered)
 * Reads are always scoped by agent; a bounded summary is exposed to the agent
 * prompt, never the raw rows. Rows expire via expires_at and cron pruning.
 */

namespace Ch247Ai\Memory;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;

class Memory
{
    const SCOPE_OPERATIONAL = 'operational';
    const SCOPE_SHORT = 'short';

    public static function rememberEvent($agentSlug, array $event, $expiresDays = 90)
    {
        return Db::insert('memories', [
            'agent' => (string) $agentSlug,
            'scope' => self::SCOPE_OPERATIONAL,
            'subject_type' => (string) $event['entity_type'],
            'subject_id' => (int) $event['entity_id'],
            'client_id' => (int) $event['client_id'] ?: null,
            'content' => self::clip((string) $event['event_type'] . ' ' . (string) $event['entity_type'] . ' #' . (int) $event['entity_id'] . ' — ' . (string) $event['payload'], 3000),
            'evidence_ref' => 'event:' . (int) $event['id'],
            'expires_at' => Clock::in($expiresDays * 86400),
            'created_at' => Clock::now(),
        ]);
    }

    public static function rememberRun($agentSlug, $runId, $summary, $expiresDays = 30)
    {
        return Db::insert('memories', [
            'agent' => (string) $agentSlug,
            'scope' => self::SCOPE_SHORT,
            'subject_type' => 'run',
            'subject_id' => (int) $runId,
            'client_id' => null,
            'content' => self::clip((string) $summary, 500),
            'evidence_ref' => 'run:' . (int) $runId,
            'expires_at' => Clock::in($expiresDays * 86400),
            'created_at' => Clock::now(),
        ]);
    }

    /** Recent, unexpired memory lines for an agent prompt (bounded). */
    public static function recent($agentSlug, $limit = 8)
    {
        try {
            $rows = Db::all('memories', ['agent' => $agentSlug], 'id DESC', max(1, min(30, (int) $limit)));
            $now = Clock::now();
            $out = [];
            foreach ($rows as $row) {
                if ($row['expires_at'] !== null && $row['expires_at'] !== '' && $row['expires_at'] < $now) {
                    continue;
                }
                $out[] = '[' . substr((string) $row['created_at'], 0, 10) . '] ' . self::clip((string) $row['content'], 300);
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function prune($days = 180)
    {
        if ($days > 0) {
            return Db::exec('DELETE FROM ' . Db::t('memories') . ' WHERE created_at < ?', [Clock::ago($days * 86400)]);
        }
        return 0;
    }

    protected static function clip($value, $length)
    {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
