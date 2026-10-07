<?php
/**
 * Approval engine (the AUTHORIZE step for anything that would ever write).
 *
 * Risk ladder:
 *   READ                      → auto (still audited)
 *   WRITE_LOW                 → human approval   (operator policy: all-gated)
 *   WRITE_CUSTOMER_VISIBLE    → human approval
 *   WRITE_FINANCIAL           → human approval
 *   AVAILABILITY              → human approval
 *   SECURITY_ENFORCEMENT      → human approval
 *   MASS_COMMUNICATION        → human approval
 *
 * Operator policy for this deployment: EVERY write is gated. WRITE_LOW
 * (internal notes, drafts) is deliberately NOT auto-executed — the ladder
 * still distinguishes the classes for reporting and for future policy, but
 * no risk class above READ may execute without an approved row.
 *
 * Phase 1 registers zero write tools, so this engine only ever sees READ —
 * but the gate, the statuses and the expiry are real so Phase 2 can add
 * draft-then-approve tools without redesigning anything.
 *
 * Statuses: pending → approved | rejected | expired | executed
 */

namespace Ch247Ai\Approval;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Settings;

class ApprovalEngine
{
    const RISK_LADDER = ['READ', 'WRITE_LOW', 'WRITE_CUSTOMER_VISIBLE', 'WRITE_FINANCIAL', 'AVAILABILITY', 'SECURITY_ENFORCEMENT', 'MASS_COMMUNICATION'];

    /**
     * Does this risk class require an approved row before execution?
     *
     * Everything that is not a pure READ does. Reads are still audited.
     */
    public static function requiresApproval($risk)
    {
        $risk = (string) $risk;
        if ($risk === 'READ') {
            return false;
        }
        // Unknown/unexpected risk labels fail closed into requiring approval.
        return true;
    }

    /** Gate used before ANY write tool executes. Phase 1: no write tools exist. */
    public static function assertExecutable($approvalId, $risk)
    {
        if (!self::requiresApproval($risk)) {
            return true;
        }
        $row = $approvalId ? Db::first('approvals', ['id' => (int) $approvalId]) : null;
        if ($row === null) {
            throw new ForbiddenException('APPROVAL_REQUIRED: this action requires explicit human approval before execution.');
        }
        // 'executing' is approved-and-in-flight: ApprovalExecutor claims the
        // row (approved -> executing) before calling the tool, so the gate
        // must recognise the claim it just made. Only claim() can produce
        // this state, and it can only do so once, so accepting it here does
        // not widen the window — a second claim on the same row still loses.
        if (!in_array((string) $row['status'], ['approved', 'executing'], true)) {
            throw new ForbiddenException('APPROVAL_REQUIRED: approval #' . $row['id'] . ' is ' . $row['status'] . ', not approved.');
        }
        if (self::isExpired($row)) {
            Db::update('approvals', ['id' => (int) $row['id']], ['status' => 'expired']);
            throw new ForbiddenException('APPROVAL_REQUIRED: approval #' . $row['id'] . ' expired. Request a fresh approval.');
        }
        return true;
    }

    public static function isExpired(array $row)
    {
        $hours = max(1, Settings::int('approval_expiry_hours', 72));
        return Clock::toTime($row['decided_at'] !== null && $row['decided_at'] !== '' ? $row['decided_at'] : $row['created_at']) < Clock::time() - $hours * 3600;
    }

    public static function create($agentSlug, $toolName, array $arguments, $risk, $reason, $requesterType = 'agent', $requesterId = 0)
    {
        $id = Db::insert('approvals', [
            'agent' => (string) $agentSlug,
            'tool' => (string) $toolName,
            'arguments' => json_encode(\Ch247Ai\Core\Redaction::clean($arguments), JSON_UNESCAPED_UNICODE),
            'args_digest' => self::argsDigest($arguments),
            'risk' => (string) $risk,
            'reason' => \Ch247Ai\Core\Validator::clip((string) $reason, 1000),
            'status' => 'pending',
            'requested_by_type' => (string) $requesterType,
            'requested_by_id' => (int) $requesterId,
            'created_at' => Clock::now(),
            'decided_at' => null,
            'decided_by' => null,
            'executed_at' => null,
        ]);
        Audit::agent($agentSlug, 'ai.approval.requested', ['approval_id' => $id, 'tool' => $toolName, 'risk' => $risk]);
        return $id;
    }

    public static function decide($approvalId, $decision, $note = '')
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \Ch247Ai\Core\ValidationException('Decision must be approved or rejected.');
        }
        $row = Db::first('approvals', ['id' => (int) $approvalId]);
        if ($row === null) {
            throw new NotFoundException('Approval not found.');
        }
        if ($row['status'] !== 'pending') {
            throw new ForbiddenException('Approval #' . $approvalId . ' was already ' . $row['status'] . '.');
        }
        $adminId = \Ch247Ai\Core\Identity::adminId();
        if (!$adminId || !Rbac::adminCan(Rbac::AI_APPROVE)) {
            throw new ForbiddenException('Only administrators with the AI approve permission can decide approvals.');
        }
        Db::update('approvals', ['id' => (int) $approvalId], [
            'status' => $decision,
            'decided_at' => Clock::now(),
            'decided_by' => $adminId,
            'decision_note' => \Ch247Ai\Core\Validator::clip((string) $note, 1000),
        ]);
        Audit::admin($adminId, 'ai.approval.' . $decision, ['approval_id' => (int) $approvalId, 'tool' => $row['tool']]);
        return true;
    }

    public static function markExecuted($approvalId)
    {
        Db::update('approvals', ['id' => (int) $approvalId], ['status' => 'executed', 'executed_at' => Clock::now()]);
        Audit::system('ai.approval.executed', ['approval_id' => (int) $approvalId]);
    }

    /** Expire stale pending approvals (cron). */
    public static function expireStale()
    {
        $hours = max(1, Settings::int('approval_expiry_hours', 72));
        $rows = Db::all('approvals', ['status' => 'pending'], 'id ASC', 500);
        $expired = 0;
        foreach ($rows as $row) {
            if (Clock::toTime($row['created_at']) < Clock::time() - $hours * 3600) {
                Db::update('approvals', ['id' => (int) $row['id']], ['status' => 'expired']);
                $expired++;
            }
        }
        return $expired;
    }

    public static function pending()
    {
        return Db::count('approvals', ['status' => 'pending']);
    }

    /**
     * Canonical SHA-256 of tool arguments.
     *
     * Keys are sorted recursively so that key order cannot change the digest,
     * and values are compared as strings so that 401 and "401" bind the same.
     * This is what stops an approved request being spent on different
     * arguments later (approval swapping / TOCTOU).
     */
    public static function argsDigest(array $arguments)
    {
        return hash('sha256', json_encode(self::canonicalize($arguments), JSON_UNESCAPED_UNICODE));
    }

    protected static function canonicalize($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = self::canonicalize($v);
            }
            ksort($out, SORT_STRING);
            return $out;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    /**
     * Confirm the arguments about to execute are the ones a human approved.
     *
     * Rows written before this column existed have a NULL digest; those fail
     * closed rather than being grandfathered through.
     */
    public static function assertArgumentsMatch($approvalId, array $arguments)
    {
        $row = Db::first('approvals', ['id' => (int) $approvalId]);
        if ($row === null) {
            throw new NotFoundException('Approval not found.');
        }
        $stored = isset($row['args_digest']) ? (string) $row['args_digest'] : '';
        if ($stored === '') {
            throw new ForbiddenException('APPROVAL_REQUIRED: approval #' . (int) $approvalId . ' has no argument digest; request a fresh approval.');
        }
        if (!hash_equals($stored, self::argsDigest($arguments))) {
            throw new ForbiddenException('APPROVAL_MISMATCH: approval #' . (int) $approvalId . ' was granted for different arguments.');
        }
        return true;
    }

    /**
     * Atomically take ownership of an approved row for execution.
     *
     * The UPDATE is conditional on status still being 'approved', so two
     * concurrent executors cannot both win, and a replayed request cannot
     * execute an action twice. Returns false when the row was already
     * claimed, decided otherwise, or does not exist.
     */
    public static function claim($approvalId)
    {
        $affected = Db::exec(
            'UPDATE ' . Db::t('approvals') . " SET status = 'executing', attempts = attempts + 1 WHERE id = ? AND status = 'approved'",
            [(int) $approvalId]
        );
        return (int) $affected === 1;
    }

    /** Release a claim when execution could not start (keeps the approval usable). */
    public static function release($approvalId)
    {
        Db::exec(
            'UPDATE ' . Db::t('approvals') . " SET status = 'approved' WHERE id = ? AND status = 'executing'",
            [(int) $approvalId]
        );
    }

    /**
     * Final state of an execution attempt.
     *
     * $verified must come from a real post-write re-read. An unverified
     * attempt is recorded as failed: the platform never reports success it
     * has not confirmed (brief §29).
     */
    public static function recordOutcome($approvalId, $succeeded, $verified, $note, array $result = [])
    {
        $succeeded = (bool) $succeeded && (bool) $verified;
        Db::update('approvals', ['id' => (int) $approvalId], [
            'status' => $succeeded ? 'executed' : 'failed',
            'execution_status' => $succeeded ? 'succeeded' : 'failed',
            'execution_result' => json_encode(\Ch247Ai\Core\Redaction::clean($result), JSON_UNESCAPED_UNICODE),
            'verified' => $verified ? 1 : 0,
            'verification_note' => \Ch247Ai\Core\Validator::clip((string) $note, 500),
            'executed_at' => Clock::now(),
        ]);
        Audit::system('ai.approval.' . ($succeeded ? 'executed' : 'execution_failed'), [
            'approval_id' => (int) $approvalId,
            'verified' => $verified ? 1 : 0,
            'note' => (string) $note,
        ]);
        return $succeeded;
    }
}
