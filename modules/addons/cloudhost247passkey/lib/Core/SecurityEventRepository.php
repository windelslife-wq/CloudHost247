<?php
/** Append-only persistence boundary for redacted Passkey security events. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\ModelValidation;
use CloudHost247\Passkey\Model\SafeMetadata;
use CloudHost247\Passkey\Model\SecurityEventRecord;

class SecurityEventRepository
{
    /**
     * Append one allowlisted event. The repository accepts metadata as an
     * array and never accepts a caller-supplied database ID or raw ceremony
     * field. It intentionally exposes no update/delete operation.
     */
    public function append(array $event)
    {
        ModelValidation::allowKeys($event, [
            'user_type', 'user_id', 'passkey_id', 'event_type', 'success',
            'reason_code', 'ip_address', 'user_agent', 'metadata', 'created_at',
        ], 'security event');
        $metadata = array_key_exists('metadata', $event) ? $event['metadata'] : [];
        $record = new SecurityEventRecord([
            'id' => 1,
            'user_type' => $event['user_type'] ?? '',
            'user_id' => $event['user_id'] ?? null,
            'passkey_id' => $event['passkey_id'] ?? null,
            'event_type' => $event['event_type'] ?? '',
            'success' => $event['success'] ?? 0,
            'reason_code' => $event['reason_code'] ?? null,
            'ip_address' => $event['ip_address'] ?? null,
            'user_agent' => $event['user_agent'] ?? null,
            'metadata_json' => $metadata,
            'created_at' => $event['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ]);
        $data = $record->toArray();
        return Db::insert('events', [
            'user_type' => $data['user_type'],
            'user_id' => $data['user_id'],
            'passkey_id' => $data['passkey_id'],
            'event_type' => $data['event_type'],
            'success' => $data['success'],
            'reason_code' => $data['reason_code'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'],
            'metadata_json' => SafeMetadata::eventJson($data['metadata']),
            'created_at' => $data['created_at'],
        ]);
    }
}
