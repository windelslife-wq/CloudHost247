<?php
/**
 * Approval engine (the AUTHORIZE step for anything that would ever write).
 *
 * Risk ladder:
 *   READ                      → auto (still audited)
 *   WRITE_LOW                 → auto + audit (internal notes)          [Phase 2]
 *   WRITE_CUSTOMER_VISIBLE    → human approval
 *   WRITE_FINANCIAL           → human approval
 *   AVAILABILITY              → human approval
 *   SECURITY_ENFORCEMENT      → human approval
 *   MASS_COMMUNICATION        → human approval
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

    /** Does this risk class require an approved row before execution? */
    public static function requiresApproval($risk)
    {
        return in_array((string) $risk, ['WRITE_CUSTOMER_VISIBLE', 'WRITE_FINANCIAL', 'AVAILABILITY', 'SECURITY_ENFORCEMENT', 'MASS_COMMUNICATION'], true);
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
        if ($row['status'] !== 'approved') {
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
}
