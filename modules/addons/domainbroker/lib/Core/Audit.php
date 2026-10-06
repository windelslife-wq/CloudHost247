<?php
/**
 * Domain Broker — immutable activity & audit log.
 *
 * One append-only table backs both the customer/broker timeline and the
 * administrative audit trail. Each row records the actor, the action, the
 * previous and new values, the request it belongs to, the client IP and user
 * agent, a reason where one is required, and a timestamp.
 *
 * Tamper evidence: every row stores `record_hash`, a SHA-256 over the row's
 * canonical content chained to the previous row's hash for the same request.
 * Editing or deleting history therefore breaks the chain, and verifyChain()
 * will report exactly where. Nothing in the module ever UPDATEs or DELETEs a
 * row in this table.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Audit
{
    /** Who may see a given entry. */
    const VIS_CUSTOMER = 'customer';   // visible to customer, broker and admin
    const VIS_BROKER   = 'broker';     // visible to broker and admin
    const VIS_INTERNAL = 'internal';   // admin only

    /** Actions that always demand a human-supplied reason. */
    const REASON_REQUIRED = [
        'request.status.override',
        'request.cancelled.admin',
        'payment.refunded',
        'dispute.resolved',
        'verification.rejected',
        'transfer.failed',
        'broker.unassigned',
        'request.rejected',
    ];

    /**
     * Append an entry.
     *
     * @param Actor       $actor
     * @param string      $action      dot.separated verb
     * @param array       $options     request_id, entity_type, entity_id,
     *                                 previous, new, reason, visibility, meta
     * @return int new activity id
     * @throws ValidationException when a reason is mandatory but missing
     */
    public static function record(Actor $actor, $action, array $options = [])
    {
        $action = (string) $action;
        $reason = isset($options['reason']) ? trim((string) $options['reason']) : '';

        if (in_array($action, self::REASON_REQUIRED, true) && $reason === '') {
            throw new ValidationException(
                'A reason is required for this action.',
                ['reason' => 'A reason is required for this action.']
            );
        }

        $requestId = isset($options['request_id']) ? (int) $options['request_id'] : null;
        $previous = self::encodeValue(isset($options['previous']) ? $options['previous'] : null);
        $new = self::encodeValue(isset($options['new']) ? $options['new'] : null);
        $meta = self::encodeValue(isset($options['meta']) ? $options['meta'] : null);

        $row = [
            'request_id'    => $requestId,
            'entity_type'   => isset($options['entity_type']) ? (string) $options['entity_type'] : 'request',
            'entity_id'     => isset($options['entity_id']) ? (int) $options['entity_id'] : ($requestId ?: 0),
            'action'        => $action,
            'actor_type'    => $actor->type,
            'actor_id'      => (int) $actor->actorId(),
            'actor_label'   => Str::clip($actor->name, 190),
            'actor_identity' => $actor->identity(),
            'actor_role'    => Str::clip($actor->role, 60),
            'previous_value' => $previous,
            'new_value'     => $new,
            'reason'        => $reason !== '' ? Str::clip($reason, 2000) : null,
            'visibility'    => self::normaliseVisibility(isset($options['visibility']) ? $options['visibility'] : self::VIS_INTERNAL),
            'ip_address'    => Str::clip(self::resolveIp($actor), 45),
            'user_agent'    => Str::clip(self::resolveUserAgent($actor), 400),
            'meta'          => $meta,
            'created_at'    => Clock::now(),
        ];

        $row['record_hash'] = self::chainHash($row, self::previousHash($requestId));

        return Db::insert('activity', $row);
    }

    /** Convenience wrapper for status transitions. */
    public static function transition(Actor $actor, $requestId, $from, $to, array $options = [])
    {
        $options['request_id'] = $requestId;
        $options['previous'] = ['status' => $from];
        $options['new'] = ['status' => $to];
        $options['entity_type'] = isset($options['entity_type']) ? $options['entity_type'] : 'request';
        $options['entity_id'] = isset($options['entity_id']) ? $options['entity_id'] : $requestId;
        $options['visibility'] = isset($options['visibility']) ? $options['visibility'] : self::VIS_CUSTOMER;
        return self::record($actor, isset($options['action']) ? $options['action'] : 'request.status.changed', $options);
    }

    /**
     * Timeline for a request, filtered to what the viewer may see.
     *
     * @param string $audience customer|broker|admin
     */
    public static function timeline($requestId, $audience = 'customer', $limit = 500)
    {
        $visible = [self::VIS_CUSTOMER];
        if ($audience === 'broker') {
            $visible[] = self::VIS_BROKER;
        } elseif ($audience === 'admin') {
            $visible[] = self::VIS_BROKER;
            $visible[] = self::VIS_INTERNAL;
        }

        return Db::fetch('activity', [
            'request_id' => (int) $requestId,
            'visibility' => ['in', $visible],
        ], ['order' => 'id', 'dir' => 'asc', 'limit' => $limit]);
    }

    /** Full audit view (requires Rbac::AUDIT_VIEW at the call site). */
    public static function forRequest($requestId, $limit = 1000)
    {
        return Db::fetch('activity', ['request_id' => (int) $requestId], [
            'order' => 'id', 'dir' => 'asc', 'limit' => $limit,
        ]);
    }

    /**
     * Verify the hash chain of a request's audit entries.
     *
     * @return array{valid: bool, broken_at: int|null, checked: int}
     */
    public static function verifyChain($requestId)
    {
        $rows = Db::fetch('activity', ['request_id' => (int) $requestId], ['order' => 'id', 'dir' => 'asc']);
        $prev = '';
        $checked = 0;
        foreach ($rows as $row) {
            $expected = self::chainHash($row, $prev);
            if (!hash_equals((string) $row['record_hash'], $expected)) {
                return ['valid' => false, 'broken_at' => (int) $row['id'], 'checked' => $checked];
            }
            $prev = $row['record_hash'];
            $checked++;
        }
        return ['valid' => true, 'broken_at' => null, 'checked' => $checked];
    }

    protected static function previousHash($requestId)
    {
        if (!$requestId) {
            $row = Db::selectOne(
                'SELECT record_hash FROM ' . Db::quoteIdentifier(Db::table('activity'))
                . ' WHERE request_id IS NULL ORDER BY id DESC LIMIT 1'
            );
        } else {
            $row = Db::selectOne(
                'SELECT record_hash FROM ' . Db::quoteIdentifier(Db::table('activity'))
                . ' WHERE request_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $requestId]
            );
        }
        return $row && isset($row['record_hash']) ? (string) $row['record_hash'] : '';
    }

    protected static function chainHash(array $row, $previousHash)
    {
        $canonical = implode("\x1f", [
            (string) (isset($row['request_id']) ? $row['request_id'] : ''),
            (string) $row['entity_type'],
            (string) $row['entity_id'],
            (string) $row['action'],
            (string) $row['actor_type'],
            (string) $row['actor_id'],
            (string) $row['actor_identity'],
            (string) (isset($row['previous_value']) ? $row['previous_value'] : ''),
            (string) (isset($row['new_value']) ? $row['new_value'] : ''),
            (string) (isset($row['reason']) ? $row['reason'] : ''),
            (string) $row['visibility'],
            (string) $row['created_at'],
            (string) $previousHash,
        ]);
        return hash('sha256', $canonical);
    }

    protected static function normaliseVisibility($visibility)
    {
        $visibility = (string) $visibility;
        return in_array($visibility, [self::VIS_CUSTOMER, self::VIS_BROKER, self::VIS_INTERNAL], true)
            ? $visibility
            : self::VIS_INTERNAL;
    }

    protected static function encodeValue($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            $value = ['value' => $value];
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : Str::clip($json, 60000);
    }

    protected static function resolveIp(Actor $actor)
    {
        if ($actor->ip) {
            return $actor->ip;
        }
        return Http::clientIp();
    }

    protected static function resolveUserAgent(Actor $actor)
    {
        if ($actor->userAgent) {
            return $actor->userAgent;
        }
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;
    }
}
