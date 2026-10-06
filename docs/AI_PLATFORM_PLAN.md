# CloudHost247 — AI Control Plane

## Scoped, repo-accurate plan for an AI operations layer

**Repository:** `windelslife-wq/CloudHost247` · **Branch:** `arena/c52c742a-cloudhost247`
**Proposed module:** `modules/addons/cloudhost247ai/` (namespace `Ch247Ai\`)
**Status:** work brief. Nothing implemented yet.

This is a rewrite of the "Native AI Operating System" brief against what this repository
actually is: a **WHMCS 8.x overlay** of Smarty templates, PHP addon/provisioning modules and
cron scripts — no application framework, no job queue, no metrics pipeline, no vector
database, no multi-tenancy, and no native PHP binary in the dev workspace.

The core thesis of the original brief is right and worth building: **one shared control plane
with registered tools, permissions, approvals and audit — not thirty chatbots.** What has to
change is scope. The brief specifies 34 agents and a 10-member executive board; this
repository has a real data source for roughly **nine** of them. The rest would be agents with
nothing truthful to read, which is precisely the failure mode the brief's own §29 forbids.

---

# 1. Ground truth

## 1.1 What already exists that the AI layer must reuse

The brief's §36 says "audit first, don't duplicate." Here is the result.

| Control-plane need | Already in this repo | Where |
|---|---|---|
| **Fail-closed AI provider** (§37) | **Already solved.** `AiProviderInterface` + `HttpAiProvider` (any OpenAI-compatible endpoint) + `NullAiProvider` that throws `ProviderNotConfiguredException` naming the missing settings | `cloudhost247services/lib/Providers/Ai/` |
| Secret handling | `Settings::SECRET_KEYS = ['ai_api_key', …]` — env-only (`CHS_AI_API_KEY`), rejected by `put()`, never rendered | `Chs\Core\Settings` |
| **Tool-call pipeline** | **Already solved in pattern.** `Router::ROUTES` is a declarative table of `[method, pattern, controller, action, {permission, public, bucket, idempotent, scope}]` dispatched through authenticate → RBAC → scope → rate-limit → idempotency → execute | `domainbroker/lib/Api/Router.php` |
| Permissions | `DomainBroker\Core\Rbac` — ~40 granular constants split customer / broker / admin | `domainbroker/lib/Core/Rbac.php` |
| Audit log | `Chs\Core\Audit` (`client`/`admin`/`system` actors, action, JSON context) and `DomainBroker\Core\Audit` | two modules |
| Rate limiting | `Chs\Core\RateLimiter::hitOrFail($action, $bucket, $max, $window)` | `Chs\Core\RateLimiter` |
| Idempotency | `DomainBroker\Core\Idempotency` keyed on identity + route + params + body | `domainbroker/lib/Core/Idempotency.php` |
| Migrations | Numbered, additive, re-runnable `install/migrations/NNNN_*.php` + `Migrator` | both in-repo modules |
| Scheduling | `cron/*.php` every 5 min + WHMCS `DailyCronJob` hook | `cloudhost247services/cron/`, `domainbroker/cron/` |
| Notifications | `Chs\Services\NotificationService::notify()` — in-app + email | `cloudhost247services/lib/Services/` |
| **Real diagnostic tools** | ~25 working functions: `ssl_checker`, `dns_lookup`, `dns_health`, `dns_propagation`, `spf_checker`, `dmarc_lookup`, `dkim_checker`, `dnskey/ds_lookup`, `ns/mx/cname_lookup`, `reverse_ip_lookup`, `port_checker`, `asn_lookup`, `website_status`, `domain_whois` | `CloudHost247_tools/includes/tools/` |
| WHOIS + domain intelligence | `Chs\Services\WhoisService`, `ValuationService`, cached provider | `cloudhost247services/lib/Services/` |
| Offline test harness | In-memory SQLite + real migrations + recording `FakeGateway` + php-wasm runner | `*/tests/bootstrap.php`, `tests/run.mjs` |
| Business data | WHMCS core: `tblclients`, `tblorders`, `tblinvoices`, `tblinvoiceitems`, `tblaccounts`, `tblhosting`, `tbldomains`, `tbltickets`, `tblticketreplies`, `tblproducts`, `tblservers`, `tblactivitylog` — via `localAPI()` and Capsule | WHMCS (not in repo) |

**This is a strong foundation.** The AI module should import these patterns wholesale and add
exactly one genuinely new thing: a non-deterministic step (the model) inside an otherwise
deterministic, audited pipeline.

## 1.2 What does not exist — and what the brief assumes

| Brief assumes | Reality here | Consequence |
|---|---|---|
| Multi-tenancy, `tenant_id`, tenant isolation (§18, §23, §34) | WHMCS is **single-tenant**. One install, one operator | Replace "tenant" with **client scoping** (`client_id`). Isolation is still mandatory — it just means client-to-client, which is a hard requirement |
| Server metrics: CPU, RAM, disk, load, network (§4) | **Nothing collects them.** No agent, no Prometheus, no RRD. `tblservers` holds credentials, not telemetry. OVH crons sync server lists and IP status, not utilisation | Infrastructure Guardian / Server Health / Cloud Cost Guardian **have no input**. A collector must exist before the agent does |
| Deployments, builds, containers, rollback (§4.5) | CloudHost247 deploys by `rsync` over the WHMCS root. No CI, no containers, no release records | Deployment Agent has nothing to observe or roll back. Drop it |
| Event bus (§24) | No bus, no broker, no queue | Use **WHMCS hooks as the event source** + a durable `events` table + cron drain. Honest and sufficient |
| Async workers | No daemon, no Redis, no Horizon. PHP-FPM request or cron only | Every agent run must be **cron-driven or short synchronous**. Long reasoning chains cannot block a client-area page |
| Vector database / pgvector | MySQL/MariaDB only | RAG on MySQL `FULLTEXT` + optional stored embeddings with PHP-side cosine over a bounded candidate set |
| HR, recruitment, asset/inventory systems (§11, §12) | No system of record for employees, candidates, hardware or licences | Three agents with no data. Drop |
| Observability stack (§30) | None | Build a minimal one inside the module: runs, tokens, latency, cost, errors |
| `/admin/ai/*`, `/account/ai/*` routes (§32, §33) | WHMCS routes are `addonmodules.php?module=cloudhost247ai&action=…` and `index.php?m=cloudhost247ai&action=…` | Translate every route |

## 1.3 The prerequisite nobody asked about

The module that would sit next to this one, `digitalproducts`, currently has **zero CSRF
protection on admin POSTs, no authorization checks, and a live cross-product IDOR**
(`docs/DIGITAL_PRODUCTS_REBUILD.md` §3). The platform's privacy layer also misses consent on
every page but the homepage (`docs/PLATFORM_FEATURES.md` Part 3).

Giving an AI layer **write access to billing and infrastructure** on a platform whose own
authorization has known holes is the wrong order of operations. An AI tool call is only as
safe as the permission check behind it, and an audit trail is only useful if the actions it
records are the only path to the data.

**Recommendation: Phase 0 is not AI work.** Close the `digitalproducts` P0s and establish a
shared RBAC convention first. Phase 1 AI is then **read-only**, which is independently the
right first step anyway.

---

# 2. Agent triage

The brief lists 34 agents plus 10 executives. Each is classified by whether a truthful data
source exists **in this platform today**.

### Tier A — real data exists now, build these (9)

| # | Agent | Reads | Notes |
|---|---|---|---|
| 1 | **Admin Copilot** (§16) | All WHMCS read tools | The highest-value, lowest-risk deliverable. Natural-language → registered read tools → cited answer. Ship first |
| 2 | **Ledger** — billing answers (§6.11) | `tblinvoices`, `tblinvoiceitems`, `tblaccounts`, `tblorders`, `tblhosting` | Must quote real records and link to them. Never computes an amount it can't cite |
| 3 | **Resolution Pro** — support drafting (§3) | `tbltickets`, `tblticketreplies`, WHMCS KB, `docs/` | **Draft-only in Phase 1.** See §10 on prompt injection — ticket text is attacker-controlled |
| 4 | **DNS & Domain Agent** (§4.6) | `CloudHost247_tools` DNS functions, `tbldomains`, `WhoisService` | Best fit in the repo: real tools, real diagnostics, read-only, no fabrication risk |
| 5 | **SSL/TLS Guardian** (§4.7) | `ssl_checker`, `tbldomains`, `tblhosting` | Expiry scanning + alerts via `NotificationService`. Mostly deterministic; the model only writes the explanation |
| 6 | **Revenue Analyst** (§6.13) | `tblinvoices`, `tblaccounts`, `tblhosting`, `tblorders` | MRR/ARR/churn/ARPU computed in **SQL**, not by the model. The model narrates the numbers it is handed |
| 7 | **Billing Reconciliation** (§6.14) | Orders vs invoices vs transactions vs gateway records | Deterministic diff; model explains discrepancies. Genuinely useful given Blockonomics' async confirmation model |
| 8 | **Collections** (§6.12) | Overdue `tblinvoices` | **Drafts** reminders; sending is an approved action. Respect WHMCS's own dunning so you don't double-notify |
| 9 | **Customer Intelligence** (§9.22) | Joined client/product/ticket/invoice history | Health score must be **computed and explainable**, each factor traced to a record. The model never invents the score |

### Tier B — build the collector first, then the agent (4)

| Agent | Missing input | What to build first |
|---|---|---|
| Infrastructure Guardian / Server Health (§4.2, §4.3) | CPU/RAM/disk/load telemetry | A metrics collector: cron polling provisioning-module APIs + `tblservers` reachability into a `metrics` table with retention. **Weeks of work before any AI** |
| Provisioning Agent (§4.4) | Structured provisioning outcomes | Provisioning state is scattered across module logs and `tblhosting.domainstatus`. Normalise failures into an events table first |
| Security Sentinel (§5.8) | Auth-event stream | `tblactivitylog` has login records but is noisy and unindexed for this. Needs a derived, indexed auth-event table |
| Fraud & Abuse Guardian (§5.9) | Order/payment signal set | WHMCS has fraud fields and gateway data; needs a feature extraction layer. Also the highest false-positive cost — gate hard behind human approval |

### Tier C — no data source in this platform, do not build (the rest)

Deployment Agent, Root Cause Analyst (no logs/metrics/deploy records), Vulnerability Analyst
(no inventory of server software versions), Cloud Cost Guardian (no utilisation or cost feed),
Pricing Analyst (needs the revenue layer first), Pipeline/Sales, Account Expansion, Retention
(no CRM, no usage metering), Campaigner, Content, SEO Intelligence (partially viable later via
the HostX page system), Internal IT, **HR Assistant, Recruitment Assistant, Asset & Inventory**
(no system of record at all), Incident Commander (no incident system yet), Business
Intelligence (fold into Admin Copilot — it is the same thing), Customer Success, Customer
Cloud Assistant (viable later, after client-scoped read tools are proven in Tier A).

The brief already rejects six generic agents (Care Navigator, Claims Copilot, Intake Attorney,
Citizen Desk, Tutor, Dispatch) as domain-mismatched. **The same test eliminates another
fifteen**: an agent whose entire job is to read data the platform does not have is not an
agent, it is a prompt that hallucinates.

---

# 3. The Executive Board

The brief asks for ten C-level agents exchanging findings in a "war room" (§2, §27). Build the
**output**, not the **org chart**.

**Don't:** have agents talk to each other. Agent-to-agent chat is where fabrication compounds —
the brief's own rule "no agent should fabricate another agent's conclusion" is unenforceable
when one agent's prose is another's input. It also multiplies token cost by the number of
participants for no added information.

**Do:** build a **Briefing Composer** (§26).

```
cron (daily / weekly / monthly)
  → deterministic metric packs, each a plain SQL query with a named source:
      revenue · orders · new customers · churn · failed payments · outstanding AR
      · ticket volume + ageing · SLA breaches · domain + SSL expiries · incidents
      · pending approvals · AI cost and override rate
  → ONE model call: turn the metric pack into prose, flag the three things that moved,
    say plainly when a number is unavailable
  → store as a report with its inputs attached
  → notify via NotificationService
```

Every number in the briefing is traceable to the query that produced it. The "board" becomes
**sections** of one report — Finance, Operations, Security, Customers — which is what an
executive actually reads. If a section's data source does not exist (capacity, infrastructure
cost), the section prints `DATA SOURCE NOT CONFIGURED` rather than being written by a model.

A "CEO agent" that summarises other agents' summaries adds a lossy layer over data it never
saw. Skip it.

---

# 4. Architecture

New addon, following the `domainbroker` layout exactly:

```
modules/addons/cloudhost247ai/
├── autoload.php                     PSR-4 Ch247Ai\ → lib/
├── cloudhost247ai.php               _config / _activate (Migrator) / _deactivate / _output
├── hooks.php                        event capture + admin/client surfaces
├── cron/cloudhost247ai.php          event drain, scheduled agents, briefings, GC
├── api.php                          internal JSON endpoint (copilot XHR)
├── install/migrations/0001…         numbered, additive
├── lib/
│   ├── Core/        Audit Clock Csrf Db Idempotency Identity Logger Migrator
│   │                RateLimiter Rbac Settings Validator            (ported, not reinvented)
│   ├── Model/       ModelRouter ModelClient ChatRequest ChatResponse TokenAccounting
│   │                Providers/{OpenAiCompatible, NullProvider}
│   ├── Tools/       ToolRegistry ToolDefinition ToolCall ToolResult
│   │                Readers/{Clients,Billing,Services,Tickets,Domains,Diagnostics}
│   │                Writers/{Tickets,Notifications}       ← Phase 4, approval-gated
│   ├── Agents/      AgentRegistry AgentRuntime AgentDefinition
│   ├── Knowledge/   Ingestor Chunker Retriever Citation
│   ├── Memory/      ShortTerm Operational Organizational
│   ├── Approvals/   ApprovalService ApprovalPolicy
│   ├── Events/      EventCapture EventQueue Dispatcher
│   ├── Reports/     MetricPack BriefingComposer
│   ├── Observability/ RunRecorder CostLedger
│   ├── Http/        AdminPortal CustomerPortal Controller View
│   └── Workflow/    RunStatus TaskStatus ApprovalStatus RiskLevel
├── templates/{admin,client}/
└── tests/           bootstrap.php, 01…10 *Test.php, lint.php, run.mjs
```

**The run loop.** Every agent execution is one `Run` composed of `Steps`:

```
task created (user request | event | schedule)
  → agent resolved from registry (version-pinned prompt + tool allowlist + policy)
  → budget check: token ceiling, wall-clock ceiling, max tool calls, daily spend cap
  → loop, bounded:
        model call → zero or more tool calls
        each tool call: registry lookup → permission → risk gate → rate limit
                        → idempotency → execute → record arguments + result
  → output assembled WITH citations (every factual claim ↔ a tool result or source)
  → verification pass: claims without a citation are stripped and flagged
  → run recorded: steps, tokens, latency, cost, model, prompt version, outcome
  → audit event
```

`AgentRuntime` is the only place a model is ever called. No module, template or hook talks to
a provider directly.

---

# 5. Tool registry

A tool is a **PHP callable with a declared contract**, registered in code (versioned, testable)
and mirrored into the database for enable/disable and audit. Reuse the `Router::ROUTES` shape:

```php
ToolDefinition::make('billing.get_invoice')
    ->description('Fetch one invoice with its line items and payment state.')
    ->input(['invoice_id' => 'int:required'])
    ->output(['id','status','date','duedate','total','balance','items[]','transactions[]'])
    ->permission(Rbac::BILLING_READ)
    ->risk(RiskLevel::READ)
    ->scope(Scope::CLIENT_BOUND)   // result filtered to the acting client
    ->bucket('ai.billing.read')
    ->audit(true);
```

**Phase 1 read tools** (all exist as data today):
`clients.get`, `clients.search`, `billing.get_invoice`, `billing.list_invoices`,
`billing.list_overdue`, `billing.list_failed_payments`, `orders.get`, `orders.list`,
`services.get`, `services.list`, `services.status`, `tickets.get`, `tickets.list`,
`tickets.search`, `domains.get`, `domains.list`, `domains.expiring`,
`diagnostics.dns_lookup`, `diagnostics.dns_health`, `diagnostics.dns_propagation`,
`diagnostics.ssl_check`, `diagnostics.spf_check`, `diagnostics.dmarc_check`,
`diagnostics.website_status`, `diagnostics.port_check`, `diagnostics.whois`,
`knowledge.search`, `metrics.revenue`, `metrics.churn`, `metrics.tickets`.

**Phase 4 write tools**, each approval-gated by default:
`tickets.reply_draft` → `tickets.reply_send`, `tickets.set_status`,
`notifications.send`, `incidents.create`, `tasks.create`.

**Not tools at this stage** — no `restart_server`, `restart_service`, `deploy_service`,
`rollback_deployment`, `create_invoice`, `refund`, `suspend_account`. Three of those have no
backing implementation in this repo at all; the others are irreversible money or availability
operations and should wait until the read layer has an operational track record.

Every tool result carries provenance (`source`, `record_id`, `retrieved_at`) so the citation
layer can enforce §29 mechanically rather than by instruction.

---

# 6. Permissions and approvals

**Permissions.** `Ch247Ai\Core\Rbac`, same constant style as `DomainBroker\Core\Rbac`, with two
independent dimensions that must **both** pass:

1. *Agent capability* — what this agent may ever do (`ai.billing.read`, `ai.support.write`…).
2. *Actor authority* — what the human on whose behalf it runs may do. An agent invoked by a
   support operator can never read more than that operator can. For client-facing agents, every
   tool with `Scope::CLIENT_BOUND` is filtered to the authenticated `client_id` **in the query**,
   not in the prompt.

An agent that cannot name its permission does not run. Permissions live in the registry, never
in a system prompt — a prompt is not an access-control mechanism.

**Approvals.** `mod_ch247ai_approvals` + an admin **Decision Inbox** showing: agent, action,
plain-language reason, the evidence (tool results, cited), risk level, affected client/service,
proposed call with exact arguments, expected result, and Approve / Reject / Modify. Approvals
expire. Approving executes **the recorded arguments**, never a re-generated plan. Every
decision is audited with the approver's admin id.

Default policy: `READ` → auto; `WRITE_LOW` (internal note, draft) → auto with audit;
`WRITE_CUSTOMER_VISIBLE` (send email/reply, notify) → approval; `FINANCIAL`, `AVAILABILITY`,
`SECURITY_ENFORCEMENT`, `MASS_COMMUNICATION` → approval, always, non-configurable in Phase 1.

---

# 7. Data model

The brief lists 22 tables. Consolidated to 14, prefix `mod_ch247ai_`, **no customer data
duplicated** — AI tables store references and provenance, WHMCS remains the system of record.

```
agents            id, slug, name, category, status, model_profile, prompt_version_id,
                  tool_allowlist(json), policy(json), risk_level, enabled, timestamps
prompt_versions   id, agent_id, version, system_prompt, notes, created_by, created_at   (immutable)
tools             id, slug, description, input_schema, output_schema, permission,
                  risk_level, requires_approval, enabled, timestamps
tasks             id, agent_id, origin(user|event|schedule), requested_by_admin_id,
                  client_id, subject, input(json), status, priority, timestamps
runs              id, task_id, agent_id, prompt_version_id, model, status, started_at,
                  finished_at, duration_ms, tokens_in, tokens_out, cost_micros,
                  outcome, error, correlation_id
run_steps         id, run_id, seq, type(model|tool|verify), payload_ref, duration_ms, created_at
tool_calls        id, run_id, tool_id, arguments(json, redacted), result_digest,
                  result_ref, permission_checked, approval_id, success, error, duration_ms
approvals         id, run_id, tool_call_id, agent_id, action, risk_level, reason,
                  evidence(json), proposed_arguments(json), status, decided_by_admin_id,
                  decided_at, expires_at, created_at
events            id, type, source, payload(json), occurred_at, processed_at,
                  attempts, last_error                              ← durable, cron-drained
knowledge_sources id, type(doc|kb|policy|resolution), uri, title, version, checksum,
                  indexed_at, status
knowledge_chunks  id, source_id, seq, content, content_hash, embedding(blob|null),
                  token_count, FULLTEXT(content)
memories          id, scope(short|operational|organizational), subject_type, subject_id,
                  client_id(null), content, evidence_ref, expires_at, created_at
reports           id, type(daily|weekly|monthly|incident), period_start, period_end,
                  metric_pack(json), narrative, model, generated_at
evaluations       id, agent_id, metric, value, window_start, window_end, sample_size, created_at
```

Notes: `ai_audit_logs` is **not** a new table — reuse the module's `Audit` class and its table,
matching `Chs\Core\Audit`. `ai_incidents` waits until an incident system exists. `ai_decisions`
is `approvals`. `ai_workflows` / `ai_workflow_runs` collapse into `tasks` + `runs` until a
second workflow shape actually appears. No `tenant_id` — `client_id` where relevant, enforced
in queries.

**Never store in these tables:** passwords, API keys, tokens, gateway credentials, full card
data, raw licence keys. Tool arguments and results are redacted on write through a single
redaction filter, and large results are stored by reference with a digest.

---

# 8. Knowledge and RAG on MySQL

Sources that exist today and are worth indexing: `docs/*.md`, module `README.md`s, WHMCS
knowledge-base articles, the legal/policy pages at the repo root, HostX page content, and —
highest value — **resolved support tickets** (the real troubleshooting corpus).

Retrieval without a vector database:

1. Chunk (~800 tokens, overlap), store with `FULLTEXT(content)`.
2. Candidate set via `MATCH … AGAINST` in boolean mode + metadata filters.
3. Optional re-rank: if an embeddings endpoint is configured, store embeddings as a blob and
   cosine-rank **only the candidate set** in PHP. Bounded and fast enough; no vector DB.
4. Return chunks **with citations** — source, title, version, chunk id, indexed timestamp.

Answers assemble from retrieved chunks. A claim with no citation does not ship: the verify step
strips it and flags the run. Re-index on cron by checksum; stale sources are marked, not
silently served.

---

# 9. Events and scheduling

No bus. WHMCS hooks are the event source; durability comes from the table.

```
hook fires (OrderPaid, InvoicePaid, InvoiceCreated, TicketOpen, TicketUserReply,
            ServiceSuspend, ServiceUnsuspend, AfterModuleCreate, ClientAdd, …)
  → EventCapture::record() — a single fast INSERT, nothing else, never a model call
  → cron (5 min) drains the queue: match subscriptions → create tasks → run agents
  → failures retry with backoff and a dead-letter after N attempts
```

**Hard rule: no hook may call a model.** A WHMCS page load must never wait on an LLM. The
brief's §37 performance intent and the existing modules' "don't slow WHMCS down" discipline
both require this. Domain/SSL expiry scans, briefings and reconciliation are scheduled, not
event-driven.

---

# 10. Model routing, cost, privacy, injection

**Routing.** `ModelRouter` selects a **profile** (`fast`, `reasoning`, `embedding`) per agent
and task, each mapping to endpoint + model + ceilings. Reuse the existing provider contract and
settings shape (`ai_enabled`, `ai_endpoint`, `ai_model`, `ai_timeout_seconds`,
`CHS_AI_API_KEY`), extended per profile. Unconfigured profile → `NullProvider` →
`CONFIGURATION_REQUIRED`. Classification and extraction use the cheap profile; only synthesis
uses the expensive one.

**Cost.** Per-run token/cost recording, per-agent daily caps, a platform monthly cap, and a
kill switch. Exceeding a cap disables the agent and notifies an admin — it does not silently
degrade. There is precedent: `ai_daily_limit_per_client` already exists.

**Privacy — the item most likely to be overlooked.** Sending `tblclients`, invoices and ticket
bodies to a third-party inference endpoint makes that provider a **data processor** for EU/CH
personal data. This matters concretely given the consent gaps already documented in
`docs/PLATFORM_FEATURES.md`. Required before any customer data reaches a model:

* A configurable allowlist of fields per tool; PII minimisation by default (ids and aggregates
  over names and emails wherever the task permits).
* Redaction of emails, phone numbers, addresses and payment identifiers unless the agent
  demonstrably needs them.
* An operator-visible statement of which provider, which region, and under what DPA — plus a
  setting to pin a self-hosted/EU endpoint. `HttpAiProvider` already works against vLLM/Ollama,
  so a fully on-premise deployment is a configuration choice, not a rewrite.
* Privacy-policy and record-of-processing updates; retention limits on `runs`/`run_steps`.

**Prompt injection.** Resolution Pro reads ticket text written by **anyone on the internet**.
Treat all retrieved content as hostile data, never as instructions:

* Content goes in a clearly delimited data channel, never concatenated into the system prompt.
* Tools are allowlisted **per agent before the run starts** — model output can never widen the
  set.
* An agent that has read untrusted content in a run may not perform a customer-visible write in
  that same run without approval.
* Never put secrets in a context an agent that reads untrusted input can reach.

---

# 11. Safety rules

Keep the brief's §29 list in full. Make these mechanical rather than instructional:

| Rule | Enforcement |
|---|---|
| Never fabricate data | Citation requirement + verify step strips uncited claims |
| Never expose unauthorized information | `Scope::CLIENT_BOUND` filters in SQL, not in prompt |
| Never bypass RBAC | Permission checked in the tool pipeline, before dispatch |
| Never bypass approval | Risk gate in the same pipeline; approval executes recorded arguments |
| Never reveal secrets | Redaction filter on every argument/result write; secrets env-only |
| Never execute arbitrary commands | No shell tool exists. Tools are a closed, registered set |
| Never claim success before verification | Write tools return the re-read record; unverified ⇒ run marked `unverified` |
| Fail closed | `NullProvider` + `CONFIGURATION_REQUIRED`; missing tool ⇒ refuse, never improvise |

Plus two the brief doesn't state: **no model call in a web request hook**, and **no agent is its
own evaluator** — quality signals come from human overrides and outcomes, not self-grading.

---

# 12. Surfaces

WHMCS-native routes, replacing the Next.js-style paths in §32/§33.

**Admin** — `addonmodules.php?module=cloudhost247ai&action=…`:
`dashboard`, `copilot`, `agents`, `agent&id=`, `tasks`, `runs`, `run&id=`, `approvals`,
`knowledge`, `tools`, `events`, `audit`, `observability`, `evaluations`, `reports`, `settings`.

**Client** — `index.php?m=cloudhost247ai&action=…`: `assistant`, `activity`.
The client-facing assistant is **Phase 5 at the earliest** and ships only after client-scoped
read tools have a clean record in admin use. `activity` shows the customer exactly what the AI
did on their behalf, which the brief rightly requires.

Admin UI follows `cloudhost247services`' `.phtml` convention; client UI uses HostX Smarty
components (and, per `docs/PLATFORM_FEATURES.md`, should ship RTL-aware and translatable from
day one rather than joining the backlog).

---

# 13. Observability and evaluation

Dashboard from the `runs` / `tool_calls` / `evaluations` tables: runs by agent and outcome,
p50/p95 latency, tokens and cost per agent and per day, tool-call volume and failure rate,
approval rate, **human override rate** (the single most honest quality signal), refusal rate,
uncited-claim rejections, and dead-lettered events.

Evaluation needs ground truth, so start where it exists:

* **Admin Copilot / Ledger** — a fixture set of questions with known SQL answers, asserted
  offline in the test suite. Regression-gated on every prompt-version change.
* **Resolution Pro** — human-edit distance on drafts, reopen rate, escalation precision.
* **DNS/SSL** — deterministic: compare agent findings against direct tool output.
* **Anything without ground truth** — do not ship a number. Report "not evaluated".

Prompt versions are immutable and pinned per run, so a quality regression is attributable.

---

# 14. Phases

Each phase has a gate. Do not start the next until the gate passes.

| Phase | Scope | Gate |
|---|---|---|
| **0 — Prerequisites** (not AI) | Close `digitalproducts` P0s; agree a shared RBAC convention; decide the inference provider and its data-residency posture | Security fixes merged; provider + DPA decided |
| **1 — Control plane, read-only** | Module skeleton, migrations, Core ports, ToolRegistry, 20+ read tools, ModelRouter, RunRecorder, audit, redaction, **Admin Copilot** | Copilot answers the §16 question set from real data, every answer cited, offline fixtures green, zero write tools registered |
| **2 — Knowledge** | Ingest `docs/`, READMEs, KB, resolved tickets; FULLTEXT retrieval + citations; re-index cron | Retrieval precision on a fixture query set; every answer carries source + version |
| **3 — Tier A read agents** | Ledger, DNS, SSL Guardian, Revenue Analyst, Reconciliation, Customer Intelligence | Each has an evaluation; SSL/domain expiry alerts firing through `NotificationService` |
| **4 — Approvals + first writes** | Approval engine, Decision Inbox, `tickets.reply_draft` → Resolution Pro drafting, Collections drafting | 100% of writes approval-gated and audited; override rate tracked; no unapproved write possible in tests |
| **5 — Briefings** | Metric packs, Briefing Composer, daily/weekly/monthly reports | Every figure traceable to its query; missing sources print `DATA SOURCE NOT CONFIGURED` |
| **6 — Observability & evaluation** | Cost ledger, caps, kill switch, eval harness, override analysis | Caps enforced; regression suite gates prompt changes |
| **7 — Customer assistant** | Client-scoped assistant + activity page | Client-isolation test matrix passes; no cross-client leak under adversarial prompts |
| **Later** | Metrics collector → Infrastructure Guardian / Server Health; auth-event table → Security Sentinel; incident system → Incident Commander | Each needs its collector shipped and proven first |

Realistically, Phases 1–3 are the substantial build. Everything in Tier C stays out until the
platform grows the data to support it.

---

# 15. Definition of done — Phase 1

Phase 1 is complete when, on a staging WHMCS:

* An admin asks *"show today's failed payments"*, *"which customers have overdue invoices"*,
  *"summarise unresolved tickets"*, *"is this domain's DNS healthy"*, *"when does this
  certificate expire"* — and gets answers **built from registered tool calls**, each with a
  citation to the record, with the run visible in the audit log.
* Every tool call recorded with agent, arguments (redacted), permission checked, result digest,
  duration and cost.
* A question whose tool is unavailable returns `CONFIGURATION_REQUIRED` or an explicit refusal —
  **never a plausible-looking invented answer**. This is the single most important acceptance
  test; include adversarial prompts that try to elicit fabrication.
* No write tool is registered. No model call occurs in a web-request hook.
* An operator asking about another client's data through a client-scoped session gets nothing.
* With `ai_enabled=0` or no API key, the module installs, the admin pages render, and every
  AI surface shows a clear configuration state — the rest of WHMCS is untouched.
* Offline suite + lint green; `docs/` updated (`MODULES.md`, `OPERATIONS.md`, a new
  `docs/AI_CONTROL_PLANE.md`).

---

# 16. What I recommend against, and why

| Brief item | Recommendation |
|---|---|
| 34 agents | Build 9. The other 25 have no data source here; several (HR, recruitment, assets, deployments) have no system of record at all |
| 10-member executive board with inter-agent dialogue | Build the briefing instead. Agents quoting agents is how fabrication compounds and cost multiplies |
| `restart_server`, `deploy_service`, `rollback_deployment` | Not implementable — there is no deployment system and no server-control API wired up. Don't register tools that can't be honoured |
| Autonomous security remediation / account lockout | The brief already hedges this. Keep it fully human-gated: false positives here cost customers |
| Tenant isolation | Wrong primitive — WHMCS is single-tenant. Client isolation is the real (and mandatory) requirement |
| Customer-facing AI in Phase 1 | Highest blast radius, lowest tolerance for error. Earn it after the internal read layer is proven |
| "AI cannot fabricate" as a prompt instruction | Enforce it in code: citations, verification, fail-closed tools. Instructions are not controls |

---

# 17. Open questions

1. **Inference provider and residency** — self-hosted (vLLM/Ollama, supported today by
   `HttpAiProvider`), EU-region commercial, or US commercial? This decides what customer data
   may legally flow and is the gating decision for Phases 1–3.
2. **Budget ceiling** — a monthly spend cap drives model-profile choices and whether Resolution
   Pro can run on every ticket or only on request.
3. **Who approves?** The Decision Inbox needs named WHMCS admin roles before the approval
   engine has meaning.
4. **Is the metrics collector in scope?** Without it, Infrastructure Guardian and Server Health
   — two of the brief's headline agents — stay unbuildable. That collector is a worthwhile
   project on its own merits, with or without AI.
5. **Support-ticket corpus** — may resolved tickets be indexed for retrieval, and under what
   retention and redaction rules? It is by far the most valuable knowledge source available.
