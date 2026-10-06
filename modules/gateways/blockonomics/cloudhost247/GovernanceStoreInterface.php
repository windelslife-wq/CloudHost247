<?php
/**
 * Minimal persistence contract for the CloudHost247 Blockonomics governance
 * layer. Two implementations exist:
 *   - CapsuleStore (production, WHMCS database)
 *   - PdoStore     (tests / standalone)
 *
 * Owns exactly two tables, created idempotently by ensureSchema():
 *   mod_blockonomics_governance        key/value state (+ SECRET payload isolation)
 *   mod_blockonomics_governance_audit  append-only admin action trail
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

interface GovernanceStoreInterface
{
    /** Idempotently create governance + audit tables. */
    public function ensureSchema();

    /** @return string|null raw value for key, null when unset */
    public function get($key);

    /** Upsert key/value and refresh updated_at. */
    public function set($key, $value);

    /** @return array<string,string> all rows as key => value */
    public function all();

    /**
     * Append one audit row: ['actor_id'=>, 'action'=>, 'setting'=>,
     * 'old_value'=>, 'new_value'=>, 'ip_hash'=>, 'created_at'=>]
     * Secrets MUST already be redacted by callers — the store never
     * redacts on your behalf.
     */
    public function audit(array $row);

    /** @return array<int,array> newest-first audit rows */
    public function auditList($limit = 50);
}
