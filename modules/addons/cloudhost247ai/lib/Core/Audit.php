<?php
/**
 * Immutable, hash-chained AI audit log (ported from the DomainBroker pattern).
 * Nothing in the module ever UPDATEs or DELETEs a row here; verifyChain()
 * reports where tampering would break the chain.
 */

namespace Ch247Ai\Core;

class Audit
{
    /** Append one event. $context is redacted before storage. */
    public static function record($actorType, $actorId, $action, array $options = [])
    {
        $row = [
            'actor_type' => (string) $actorType,
            'actor_id' => (int) $actorId,
            'actor_label' => self::clip(isset($options['actor_label']) ? $options['actor_label'] : '', 190),
            'action' => (string) $action,
            'entity_type' => self::clip(isset($options['entity_type']) ? $options['entity_type'] : 'system', 60),
            'entity_id' => (int) (isset($options['entity_id']) ? $options['entity_id'] : 0),
            'context' => self::encode(Redaction::clean(isset($options['context']) ? $options['context'] : [])),
            'created_at' => Clock::now(),
        ];
        $row['prev_hash'] = self::previousHash();
        $row['record_hash'] = self::chainHash($row);
        return Db::insert('audit_log', $row);
    }

    public static function admin($adminId, $action, array $context = [])
    {
        return self::record('admin', $adminId, $action, ['actor_label' => 'admin #' . $adminId, 'context' => $context]);
    }
    public static function client($clientId, $action, array $context = [])
    {
        return self::record('client', $clientId, $action, ['actor_label' => 'client #' . $clientId, 'context' => $context]);
    }
    public static function system($action, array $context = [])
    {
        return self::record('system', 0, $action, ['actor_label' => 'system', 'context' => $context]);
    }
    public static function agent($agentSlug, $action, array $context = [])
    {
        return self::record('agent', 0, $action, ['actor_label' => 'agent:' . $agentSlug, 'context' => $context]);
    }

    /** @return array{valid: bool, broken_at: int|null, checked: int} */
    public static function verifyChain($limit = 5000)
    {
        $rows = Db::all('audit_log', [], 'id ASC', $limit);
        $prev = '';
        $checked = 0;
        foreach ($rows as $row) {
            $expected = self::chainHash($row);
            if (!hash_equals((string) $row['record_hash'], $expected)) {
                return ['valid' => false, 'broken_at' => (int) $row['id'], 'checked' => $checked];
            }
            $prev = $row['record_hash'];
            $checked++;
        }
        return ['valid' => true, 'broken_at' => null, 'checked' => $checked];
    }

    protected static function previousHash()
    {
        $row = Db::query('SELECT record_hash FROM ' . Db::t('audit_log') . ' ORDER BY id DESC LIMIT 1');
        return $row ? (string) $row[0]['record_hash'] : '';
    }
    protected static function chainHash(array $row)
    {
        $canonical = implode("\x1f", [
            (string) $row['actor_type'], (string) $row['actor_id'], (string) $row['actor_label'],
            (string) $row['action'], (string) $row['entity_type'], (string) $row['entity_id'],
            (string) $row['context'], (string) $row['created_at'], (string) $row['prev_hash'],
        ]);
        return hash('sha256', $canonical);
    }
    protected static function clip($value, $length)
    {
        $value = trim((string) $value);
        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }
    protected static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }
}
