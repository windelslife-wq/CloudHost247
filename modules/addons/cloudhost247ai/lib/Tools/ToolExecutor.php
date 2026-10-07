<?php
/**
 * Tool execution pipeline — one door every tool call goes through:
 *
 *   kill-switch → tool exists & enabled → agent capability (per-tool grant
 *   list inside agent_capabilities table) → actor authority (Rbac) →
 *   client isolation (forced into SQL when the actor is a client) →
 *   APPROVAL GATE (anything that is not READ needs an approved, unexpired,
 *   argument-matched approval row) → parameter validation → redaction →
 *   call → audit → citation capture.
 *
 * Unknown tools, missing grants, missing permission groups and missing or
 * mismatched approvals all fail closed with REFUSED.
 */

namespace Ch247Ai\Tools;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\ValidationException;
use Ch247Ai\Core\Ch247AiRefused;
use Ch247Ai\Approval\ApprovalEngine;

class ToolExecutor
{
    /** @var array<string,bool> */
    private static $allowedAgents = [];

    /** Test seam: allow an agent slug without DB grant rows. */
    public static function allowAgent($slug)
    {
        self::$allowedAgents[$slug] = true;
    }
    public static function resetAllowedAgents()
    {
        self::$allowedAgents = [];
    }

    /**
     * @param string $agentSlug
     * @param string $toolName
     * @param array  $args
     * @param int    $runId
     * @return ToolResult
     */
    public static function execute($agentSlug, $toolName, array $args, $runId = 0, $approvalId = 0)
    {
        $actorLabel = self::actorLabel();
        $context = [
            'tool' => $toolName,
            'agent' => $agentSlug,
            'run_id' => (int) $runId,
            'actor' => $actorLabel,
            'approval_id' => (int) $approvalId,
        ];
        try {
            $result = self::doExecute($agentSlug, $toolName, $args, $runId, $approvalId);
        } catch (ForbiddenException $e) {
            Audit::record(self::actorType(), Identity::adminId() ?: Identity::clientId() ?: 0, 'ai.tool.refused', [
                'actor_label' => $actorLabel,
                'entity_type' => 'tool',
                'entity_id' => 0,
                'context' => $context + ['reason' => $e->getMessage()],
            ]);
            throw $e;
        }
        Audit::record(self::actorType(), Identity::adminId() ?: Identity::clientId() ?: 0, 'ai.tool.executed', [
            'actor_label' => $actorLabel,
            'entity_type' => 'tool',
            'entity_id' => 0,
            'context' => $context + [
                'citations' => $result->citations,
                'result_digest' => Redaction::digest($result->data),
            ],
        ]);
        return $result;
    }

    protected static function doExecute($agentSlug, $toolName, array $args, $runId, $approvalId = 0)
    {
        if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
            throw new ForbiddenException('The AI service is disabled or the kill switch is active.');
        }
        $tool = ToolRegistry::find($toolName);
        if ($tool === null || ToolRegistry::isDisabled($toolName)) {
            throw new NotFoundException('Unknown or disabled tool: ' . $toolName);
        }
        if (!self::agentMay($agentSlug, $tool)) {
            throw new ForbiddenException('Agent "' . $agentSlug . '" has no grant for tool ' . $toolName . '.');
        }
        if (!self::actorMay($tool)) {
            throw new ForbiddenException('You do not have permission to run this tool.');
        }

        // Approval gate. Reads pass straight through; everything else needs a
        // human-approved row whose arguments digest-match this call.
        if (ApprovalEngine::requiresApproval($tool->risk)) {
            if (!Settings::bool('writes_enabled', false)) {
                throw new ForbiddenException('CONFIGURATION_REQUIRED: write execution is disabled for this installation.');
            }
            ApprovalEngine::assertExecutable($approvalId, $tool->risk);
            ApprovalEngine::assertArgumentsMatch($approvalId, $args);
        }

        $args = Redaction::clean($args);
        foreach ($tool->params as $name => $spec) {
            $required = !isset($spec['required']) || $spec['required'];
            if ($required && (!array_key_exists($name, $args) || $args[$name] === '' || $args[$name] === null)) {
                throw new ValidationException('Missing parameter: ' . $name);
            }
            if (array_key_exists($name, $args) && $args[$name] !== null && isset($spec['type'])) {
                self::assertType($name, $args[$name], $spec['type']);
            }
        }

        $ctx = [
            'scope' => self::scope(),
            'client_id' => self::clientIdForScope(),
            'run_id' => (int) $runId,
        ];
        $raw = call_user_func($tool->fn, $args, $ctx);
        if (!is_array($raw)) {
            throw new Ch247AiRefused('Tool returned a non-array result.');
        }
        $raw = Redaction::clean($raw);
        $citations = [];
        if (isset($raw['_citations'])) {
            $citations = is_array($raw['_citations']) ? $raw['_citations'] : [];
            unset($raw['_citations']);
        }
        if (isset($raw['_error'])) {
            throw new Ch247AiRefused((string) $raw['_error']);
        }
        return new ToolResult($raw, $citations);
    }

    protected static function agentMay($agentSlug, ToolDefinition $tool)
    {
        if (isset(self::$allowedAgents[$agentSlug])) {
            return true;
        }
        try {
            $row = \Ch247Ai\Core\Db::first('agents', ['agent' => (string) $agentSlug]);
            if ($row === null) {
                return false; // unknown agent -> fail closed
            }
            $allowlist = json_decode((string) $row['tool_allowlist'], true);
            return is_array($allowlist) && in_array($tool->name, $allowlist, true);
        } catch (\Throwable $e) {
            return false; // fail closed
        }
    }

    protected static function actorMay(ToolDefinition $tool)
    {
        $group = Rbac::groupForTool($tool->permission);
        if ($group === null) {
            return false;
        }
        if (self::scope() === 'client') {
            // Only read tools explicitly marked client-bound, whose readers
            // force the session's client_id into the SQL WHERE clause.
            return $tool->risk === 'READ' && $tool->clientBound;
        }
        return Rbac::adminCan($group);
    }

    protected static function scope()
    {
        return Identity::clientId() !== null && Identity::adminId() === null ? 'client' : 'admin';
    }
    protected static function clientIdForScope()
    {
        return self::scope() === 'client' ? Identity::clientId() : 0;
    }

    protected static function assertType($name, $value, $type)
    {
        switch ($type) {
            case 'integer':
                if (!is_int($value) && !preg_match('/^\d+$/', (string) $value)) {
                    throw new ValidationException('Parameter ' . $name . ' must be an integer.');
                }
                break;
            case 'number':
                if (!is_numeric($value)) {
                    throw new ValidationException('Parameter ' . $name . ' must be numeric.');
                }
                break;
            case 'boolean':
                if (!is_bool($value) && !in_array($value, ['true', 'false', '0', '1', 0, 1], true)) {
                    throw new ValidationException('Parameter ' . $name . ' must be boolean.');
                }
                break;
            case 'string':
                if (!is_scalar($value)) {
                    throw new ValidationException('Parameter ' . $name . ' must be a string.');
                }
                break;
        }
    }
    protected static function actorType()
    {
        return self::scope() === 'client' ? 'client' : 'admin';
    }
    protected static function actorLabel()
    {
        if (self::scope() === 'client') {
            return 'client #' . Identity::clientId() . ' (assistant)';
        }
        $adminId = Identity::adminId();
        return $adminId ? 'admin #' . $adminId . ' (copilot)' : 'anonymous';
    }
}
