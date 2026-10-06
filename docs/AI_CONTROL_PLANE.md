# CloudHost247 AI Control Plane — module runbook

**Module:** `modules/addons/cloudhost247ai` (namespace `Ch247Ai\`, tables `mod_ch247ai_*`)
**Status:** Phase 1 (read-only) — implemented per `docs/AI_PLATFORM_PLAN.md`
**Scope decision:** ONE shared control plane. No per-agent chatbots, no duplicated AI stacks.

---

## 1. What this is

A single AI operating layer over the WHMCS install:

* an **agent registry** (9 Tier-A agents + a deterministic Briefing Composer),
* a **tool registry** (read-only readers over live WHMCS tables + the real
  CloudHost247_tools diagnostics),
* a **permission system** (agent allowlist ∩ admin permission groups, client
  isolation enforced in SQL),
* an **approval engine** (live, but nothing can reach it in Phase 1 — zero
  write tools are registered),
* a **hash-chained audit log** for every AI operation,
* a durable **event bus** (web hooks only INSERT; the cron drains),
* **RAG-on-MySQL** knowledge base (sources + chunks, FULLTEXT),
* a **model router** that fails closed with `CONFIGURATION_REQUIRED` when no
  provider is configured — it never invents answers.

## 2. What it is NOT (Phase 1)

* No write tools. Nothing can modify WHMCS records through the AI layer.
* No model calls inside web-request hooks (hard-refused by `AgentRuntime`).
* No client-facing assistant yet (Phase 5 at the earliest, per plan §12).
* No Tier-B agents (Infrastructure Guardian, Provisioning, Security Sentinel,
  Fraud) — their data collectors (server telemetry, deployment events) do not
  exist in this platform. They will be added by extending the registry, not by
  prompt changes.
* No inter-agent "executive board" — the Briefing Composer assembles
  deterministic SQL metric packs and optionally narrates them with ONE model
  call.

## 3. Surfaces

| Surface | Where |
|---|---|
| Admin portal | `addonmodules.php?module=cloudhost247ai&action=…` → dashboard, copilot, agents, tools, knowledge, approvals, runs, run&id=, audit, events, settings |
| Copilot XHR | `modules/addons/cloudhost247ai/api.php` (POST JSON, CSRF + admin session + `ai.read` group + rate limit 20/5 min) |
| Cron | `php modules/addons/cloudhost247ai/cron/cloudhost247ai.php` — every 5 minutes (`--quiet`, `--force` supported) |
| Event capture | `hooks.php` — INSERT-only rows in `mod_ch247ai_events` |

## 4. Configuration

1. **Activate** in WHMCS → System Settings → Addon Modules. Activation runs the
   additive migrations (14 domain tables + infra) and seeds the registries.
   Re-activation is a no-op; deactivation drops nothing.
2. **Model provider** (Settings page): set a *fast* profile (endpoint + model)
   and optionally a *reasoning* profile. Any OpenAI-compatible
   `/chat/completions` endpoint works — OpenAI, Azure gateways, or self-hosted
   vLLM/Ollama (recommended for privacy: customer data never leaves your host).
3. **API key** — NEVER stored in the DB. Set `CH247AI_API_KEY` in the server
   environment. Per-setting env overrides use the `CH247AI_*` prefix.
4. **Admin permissions** — role #1 (super admin) always has everything; grant
   other roles the groups `ai.read`, `ai.client.read`, `ai.diagnostics`,
   `ai.approve`, `ai.manage`, `ai.audit` on the Settings page.
5. **Kill switch** — Settings page, top panel. Engaging it stops every model
   call and tool execution immediately, everywhere.

Without a provider the module installs, renders and audits — every AI surface
shows `CONFIGURATION_REQUIRED` instead of guessing.

## 5. Cron schedule

```
*/5 * * * * php /path/to/whmcs/modules/addons/cloudhost247ai/cron/cloudhost247ai.php --quiet
```

Each run: drains up to 100 events → at the configured briefing hour (default
06 UTC) runs the scheduled agents (SSL Guardian, DNS & Domain, Billing
Reconciliation, Collections) and composes the daily briefing → expires stale
approvals → prunes events/memories/runs/rate limits per retention settings.

## 6. Safety model (summary)

* **Fail closed everywhere:** missing WHMCS table → `DATA_UNAVAILABLE`; no
  provider → `CONFIGURATION_REQUIRED`; unknown tool/agent/permission →
  `REFUSED`. Never synthetic success.
* **Anti-fabrication gate:** a run that collected no tool evidence cannot
  return an "answer" — it ends `refused` with a cannot-verify template.
* **Client isolation in SQL:** client-bound readers force the session's
  `client_id` into the WHERE clause; prompts never filter.
* **Dual RBAC:** the agent's tool allowlist AND the acting human's permission
  group must both pass; masquerading admins get no AI authority.
* **Redaction:** every tool argument, result, run input and audit context is
  filtered — secrets by key name, bearer tokens, card-like numbers, phone-like
  runs (ISO dates and IPs preserved), emails masked unless `expose_pii=1`.
* **Budgets:** per-run tool-call cap + wall clock, per-agent daily token cap,
  platform monthly cost cap (micros). Exceeding any stops the run.
* **Audit chain:** append-only SHA-256-chained rows; `verifyChain()` is
  surfaced on the Audit page and tampering is detected (tested).

## 7. Data model (plan §7 — 14 domain tables + infra)

`agents`, `prompt_versions`, `tools`, `tasks`, `runs`, `run_steps`,
`tool_calls`, `approvals`, `events`, `knowledge_sources`, `knowledge_chunks`,
`memories`, `reports`, `evaluations` — plus module infrastructure:
`migrations` (ledger), `settings`, `rate_limits`, `role_permissions`,
`usage_daily`, `audit_log`.

No customer data is duplicated: WHMCS stays the system of record; AI tables
hold references, redacted arguments and digests.

## 8. Agents (Tier A)

| Slug | Mode | Purpose |
|---|---|---|
| `admin_copilot` | interactive | grounded Q&A for admins (the copilot page) |
| `billing_reconciliation` | scheduled | cross-checks orders/invoices/payments |
| `ledger_agent` | scheduled | deterministic daily ledger pack |
| `resolution_pro` | on demand | support reply DRAFTS (never sends) |
| `dns_domain_agent` | scheduled | DNS health/propagation via platform tools |
| `ssl_guardian` | scheduled | TLS expiry checks via ssl_checker |
| `revenue_analyst` | scheduled | revenue packs from SQL |
| `collections_agent` | scheduled | overdue exposure listing (no comms) |
| `customer_intelligence` | on demand | one-customer footprint for staff |
| `briefing_composer` | scheduled | daily briefing (deterministic + 1 optional narration) |

## 9. Tests

```
cd modules/addons/cloudhost247ai
node tests/lint.mjs    # load every shipped PHP file (45 files)
node tests/run.mjs     # 370 assertions, 10 suites
```

The suites include the adversarial acceptance tests from plan §15: fabrication
attempts, prompt injection (in questions and in tool results), cross-client
fishing, anonymous callers, masquerading admins and secret-leak checks.

## 10. Operations runbook

* **Copilot answered with "cannot verify"** — expected when no tool produced
  evidence. Check the Tools page (tool enabled?), the admin's permission
  groups, and whether the underlying WHMCS table exists.
* **`CONFIGURATION_REQUIRED`** — no model provider set. Settings → model
  endpoint/model (+ `CH247AI_API_KEY` env if the endpoint needs a key).
* **Events stuck `pending`** — cron not running. Check the cron entry and
  `event_max_attempts`; events dead-letter to `dead` after max attempts.
* **Suspect AI behaviour** — engage the kill switch (Settings, top panel),
  then review the Audit chain and Runs pages. The kill switch needs no code
  deploy and no deactivation.
* **Chain broken warning on Audit page** — someone modified `mod_ch247ai_audit_log`
  rows directly. Investigate immediately; the chain pinpoints the row.
