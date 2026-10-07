<?php
/** Privacy-preserving fixed-window rate-limit row (never contains raw IP/email). */

namespace CloudHost247\Passkey\Model;

class RateLimitRecord
{
    private $row;

    public function __construct(array $row)
    {
        $action = ModelValidation::text($row['action'] ?? '', 'action', 40);
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $action)) {
            throw new \InvalidArgumentException('Invalid Passkey rate-limit action.');
        }
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'action' => $action,
            'principal_hash' => ModelValidation::hash($row['principal_hash'] ?? '', 'principal_hash'),
            'window_started_at' => ModelValidation::timestamp($row['window_started_at'] ?? null, 'window_started_at'),
            'hit_count' => ModelValidation::nonNegativeInt($row['hit_count'] ?? 0, 'hit_count'),
            'expires_at' => ModelValidation::timestamp($row['expires_at'] ?? null, 'expires_at'),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
            'updated_at' => ModelValidation::timestamp($row['updated_at'] ?? null, 'updated_at'),
        ];
    }

    public function toArray()
    {
        return $this->row;
    }
}
