<?php
/**
 * The EXECUTE + VERIFY half of the operating loop.
 *
 *   OBSERVE → UNDERSTAND → PLAN → AUTHORIZE → [ EXECUTE → VERIFY ] → REPORT
 *
 * One entry point, `run()`, turns an approved decision into a performed
 * action. The ordering is the safety property:
 *
 *   1. CLAIM      Atomically flip 'approved' → 'executing'. A second caller
 *                 loses the race and is refused, so an approval is spent
 *                 exactly once even under a double-submitted form or a
 *                 retried cron.
 *   2. REBIND     Re-read the arguments from the approval row rather than
 *                 trusting anything the caller passed. The executed action
 *                 is by construction the approved action.
 *   3. EXECUTE    Through ToolExecutor, so the kill switch, agent grants,
 *                 RBAC, validation, redaction and audit all still apply.
 *   4. VERIFY     Run the tool's verify closure, which re-reads state. A
 *                 tool with no verify closure cannot report success.
 *   5. RECORD     Persist outcome + verification note into the approval row
 *                 and the hash-chained audit log.
 *
 * If execution throws, the row is marked failed with the error. If execution
 * returns but verification cannot confirm the change, the row is ALSO marked
 * failed — a write we cannot see is not a write we claim (brief §29).
 *
 * Nothing here runs on a timer. An approved action waits for an explicit
 * trigger, so approving something is never the same as firing it.
 */

namespace Ch247Ai\Approval;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Tools\ToolExecutor;
use Ch247Ai\Tools\ToolRegistry;

class ApprovalExecutor
{
    /**
     * Execute one approved action.
     *
     * @param int $approvalId
     * @return array ['ok'=>bool,'verified'=>bool,'note'=>string,'result'=>array]
     */
    public static function run($approvalId)
    {
        $approvalId = (int) $approvalId;
        $row = Db::first('approvals', ['id' => $approvalId]);
        if ($row === null) {
            throw new NotFoundException('Approval not found.');
        }

        $adminId = Identity::adminId();
        if (!$adminId || !Rbac::adminCan(Rbac::AI_APPROVE)) {
            throw new ForbiddenException('Only administrators with the AI approve permission can execute approved actions.');
        }
        if ($row['status'] !== 'approved') {
            throw new ForbiddenException('Approval #' . $approvalId . ' is ' . $row['status'] . '; only an approved action can be executed.');
        }
        if (ApprovalEngine::isExpired($row)) {
            Db::update('approvals', ['id' => $approvalId], ['status' => 'expired']);
            throw new ForbiddenException('Approval #' . $approvalId . ' expired before it was executed. Request a fresh approval.');
        }

        $tool = ToolRegistry::find((string) $row['tool']);
        if ($tool === null) {
            throw new NotFoundException('Tool ' . $row['tool'] . ' is no longer registered; refusing to execute.');
        }
        // A write with no verification path may not run at all.
        if ($tool->verify === null && ApprovalEngine::requiresApproval($tool->risk)) {
            throw new ForbiddenException('Tool ' . $tool->name . ' has no verification step; refusing to execute an action whose outcome cannot be confirmed.');
        }

        // 1. Single-use claim.
        if (!ApprovalEngine::claim($approvalId)) {
            throw new ForbiddenException('Approval #' . $approvalId . ' is already being executed or was already spent.');
        }

        // 2. Arguments come from the approved row, never from the caller.
        $args = json_decode((string) $row['arguments'], true);
        if (!is_array($args)) {
            ApprovalEngine::recordOutcome($approvalId, false, false, 'Stored arguments are unreadable; nothing was executed.');
            return ['ok' => false, 'verified' => false, 'note' => 'Stored arguments are unreadable; nothing was executed.', 'result' => []];
        }

        Audit::admin($adminId, 'ai.approval.execution_started', [
            'approval_id' => $approvalId,
            'tool' => (string) $row['tool'],
            'risk' => (string) $row['risk'],
        ]);

        // 3. Execute through the normal pipeline.
        try {
            $result = ToolExecutor::execute((string) $row['agent'], (string) $row['tool'], $args, (int) $row['run_id'], $approvalId);
            $data = $result->data;
        } catch (\Throwable $e) {
            $note = 'Execution failed: ' . $e->getMessage();
            ApprovalEngine::recordOutcome($approvalId, false, false, $note);
            return ['ok' => false, 'verified' => false, 'note' => $note, 'result' => []];
        }

        // 4. Verify by re-reading state.
        $verified = false;
        $note = 'No verification was performed.';
        try {
            $check = call_user_func($tool->verify, $args, ['scope' => 'admin', 'client_id' => 0], $data);
            if (is_array($check)) {
                $verified = !empty($check['verified']);
                $note = isset($check['note']) ? (string) $check['note'] : '';
            }
        } catch (\Throwable $e) {
            $verified = false;
            $note = 'Verification could not complete: ' . $e->getMessage();
        }

        // 5. Record. recordOutcome() downgrades unverified success to failed.
        $clean = self::stripInternals($data);
        $ok = ApprovalEngine::recordOutcome($approvalId, true, $verified, $note, $clean);

        return ['ok' => $ok, 'verified' => $verified, 'note' => $note, 'result' => $clean];
    }

    /** Drop the private _verify_* baselines before anything is displayed or stored. */
    protected static function stripInternals(array $data)
    {
        foreach (array_keys($data) as $key) {
            if (strpos((string) $key, '_verify') === 0) {
                unset($data[$key]);
            }
        }
        return $data;
    }


    /** Approved actions still waiting for an explicit execution trigger. */
    public static function awaitingExecution($limit = 50)
    {
        return Db::all('approvals', ['status' => 'approved'], 'id ASC', (int) $limit);
    }
}
