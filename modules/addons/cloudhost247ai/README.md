# CloudHost247 AI — Control Plane (Phase 1, read-only)

One shared AI operating layer for the platform: agent registry, read-only
grounded tools, dual-RBAC permissions, approval engine, hash-chained audit,
durable event bus, RAG knowledge base, daily briefings and a fail-closed model
router. See `docs/AI_CONTROL_PLANE.md` (runbook) and `docs/AI_PLATFORM_PLAN.md`
(architecture and scope decisions).

## Non-negotiable properties

* **Never fabricates.** Answers are built from registered tool calls only; a
  run with no evidence ends `refused`, not with an invented answer.
* **Fails closed.** Missing provider → `CONFIGURATION_REQUIRED`; missing
  WHMCS table → `DATA_UNAVAILABLE`; unknown tool/agent → `REFUSED`.
* **Read-only in Phase 1.** Zero write tools are registered; the approval
  engine exists but nothing can reach it.
* **No model call in a web-request hook.** Hooks INSERT durable event rows;
  the cron drains them. `AgentRuntime` refuses `source=hook` outright.
* **Client isolation in SQL**, never in prompts.
* **Every operation audited** in a SHA-256 hash chain; secrets redacted before
  any storage; API keys are environment-only.

## Install

1. Copy the module to `modules/addons/cloudhost247ai/`.
2. Activate in WHMCS addon modules (additive migrations, nothing destructive).
3. Configure a model endpoint (Settings) — any OpenAI-compatible endpoint;
   self-hosted vLLM/Ollama keeps data on-premise. Set `CH247AI_API_KEY` in the
   environment if required.
4. Add the cron: `*/5 * * * * php .../cron/cloudhost247ai.php --quiet`.
5. Grant admin permission groups on the Settings page.

## Tests (offline, no WHMCS or network needed)

```
npm install          # @php-wasm/node
node tests/lint.mjs  # 45 files load clean
node tests/run.mjs   # 370 assertions / 10 suites, incl. adversarial suite
```

## Layout

```
cloudhost247ai.php        addon entry (config/activate/upgrade/output/sidebar)
hooks.php                 INSERT-only event capture + admin CSS
api.php                   copilot XHR (CSRF + RBAC + rate limited)
cron/cloudhost247ai.php   drain, scheduled agents, briefing, housekeeping
autoload.php              PSR-4 Ch247Ai\ + constants
install/migrations/       0001 core, 0002 governance, 0003 seeds, 0004 fulltext
lib/Core/                 Db, Migrator, Blueprint, Settings, Rbac, Audit,
                          RateLimiter, Clock, Redaction, Csrf, Whmcs seam…
lib/Model/                ModelRouter, OpenAiCompatibleProvider, NullProvider
lib/Tools/                ToolDefinition/Registry/Executor + Readers/
lib/Agents/               AgentRegistry (9 Tier-A + composer), AgentRuntime
lib/Event/                EventBus, Subscribers
lib/Memory/, lib/Approval/, lib/Briefing/, lib/Knowledge/
lib/Http/AdminPortal.php  admin pages (dashboard … settings)
tests/                    lint + 10 suites (php-wasm)
```
