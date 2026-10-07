# CloudHost247 AI Control Plane — module runbook

**Module:** `modules/addons/cloudhost247ai` (namespace `Ch247Ai\`, tables `mod_ch247ai_*`)
**Status:** read plane + Executive Board + gated writes + customer assistant + evaluation — implemented per `docs/AI_PLATFORM_PLAN.md`
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

## 2. What it is NOT

* No write tools. Nothing can modify WHMCS records through the AI layer.
* No model calls inside web-request hooks (hard-refused by `AgentRuntime`).
* No client-facing assistant yet (Phase 5 at the earliest, per plan §12).
* No Tier-B/C agents are *operable*. They are now **declared** in the registry
  as roadmap seats, each naming the collector it needs, and the runtime
  refuses them with `CONFIGURATION_REQUIRED` even if `enabled` is flipped in
  the database. See §10.
* No inter-agent chatter. The Executive Board (§11) is deterministic SQL plus
  at most ONE narration call — agents never confer, so none can assert another
  seat's conclusion.

## 3. Surfaces

| Surface | Where |
|---|---|
| Admin portal | `addonmodules.php?module=cloudhost247ai&action=…` → dashboard, copilot, agents, **board**, tools, knowledge, approvals, runs, run&id=, audit, events, settings |
| Copilot XHR | `modules/addons/cloudhost247ai/api.php` (POST JSON, CSRF + admin session + `ai.read` group + rate limit 20/5 min) |
| Cron | `php modules/addons/cloudhost247ai/cron/cloudhost247ai.php` — every 5 minutes (`--quiet`, `--force` supported) |
| Evaluation | `addonmodules.php?module=cloudhost247ai&action=evaluations` — live safety probes + quality metrics (§15) |
| Customer area | `index.php?m=cloudhost247ai&action=…` → assistant, activity (signed-in customers, own account only — §14) |
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
Reconciliation, Collections), composes the daily briefing and the executive
board (plus the weekly board on `board_weekly_dow`, default Monday, and the
monthly board on `board_monthly_dom`, default the 1st) → expires stale
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
| `executive_board` | scheduled | narrates the board report (§11); deterministic packs do the work |

## 9. Tests

```
cd modules/addons/cloudhost247ai
node tests/lint.mjs    # load every shipped PHP file (57 files)
node tests/run.mjs     # 992 assertions, 14 suites
```

`node_modules` is shared with `cloudhost247services`; if it is missing, run
`npm install` here or symlink that module's copy.

The suites include the adversarial acceptance tests from plan §15: fabrication
attempts, prompt injection (in questions and in tool results), cross-client
fishing, anonymous callers, masquerading admins and secret-leak checks.

## 10. Roadmap seats (declared, inert)

Sixteen agents from the brief have **no data source in this platform**. Rather
than omit them silently or ship prompts that would invent infrastructure
state, they are registered as roadmap seats:

* they appear in the registry and on the Agents page, so the roadmap is
  visible;
* they seed `enabled = 0` and carry **no tools at all**;
* `AgentRuntime::run()` refuses them with `CONFIGURATION_REQUIRED` *before*
  checking `enabled`, so flipping the database flag changes nothing;
* each names the exact collector that must be built first.

| Seat | Needs first |
|---|---|
| Infrastructure Guardian, Server Health | Server telemetry collector (CPU/RAM/disk/load) |
| Provisioning Agent | Normalised provisioning outcome events |
| Deployment Agent | A deployment/release record source |
| Security Sentinel | Derived, indexed auth-event table |
| Fraud & Abuse Guardian | Feature extraction over orders/payments |
| Vulnerability Analyst | Server software inventory |
| Incident Commander, Root Cause Analyst | An incident system + monitoring feed |
| Cloud Cost Guardian | Cost and utilisation feed |
| Pricing Analyst, Account Expansion | Usage metering |
| Internal IT, HR Assistant, Recruitment Assistant, Asset & Inventory | A system of record (none exists) |

To promote one: build its collector, give it tools, and clear
`missingCollector` in `AgentRegistry`. No prompt change is involved.

## 11. Executive Board

`Ch247Ai\Board\*` — the brief's "AI board of directors", built as an output
rather than an org chart.

**Nine seats**, each a deterministic SQL pack:

| Seat | Reports | Source |
|---|---|---|
| Finance (CFO) | receipts, refunds, unpaid and overdue exposure | `tblinvoices`, `tblaccounts` |
| Operations (COO) | ticket queue, service states, renewals, expiring domains | `tbltickets`, `tblhosting`, `tbldomains` |
| Revenue & Growth (CRO) | new clients, orders, pending conversion, fraud-flagged | `tblclients`, `tblorders` |
| Customer (CCO) | ticket volume, closure ratio, repeat contact | `tbltickets` |
| Risk & Compliance | receivable concentration, fraud orders | `tblinvoices`, `tblorders` |
| Marketing (CMO) | campaigns, subscribers — **only if** `cloudhost247marketing` is installed | `mod_ch247m_*` |
| Technology (CTO) | abstains — no telemetry exists | — |
| Security (CISO) | abstains — no auth-event table exists | — |
| Product (CPO) | abstains — no usage metering exists | — |

A seat returns `ok` (metrics + findings, **every figure carrying the exact SQL
that produced it**) or `data_unavailable` with the named gap. It never
estimates from a neighbouring table, and an abstaining seat is rendered on the
page rather than dropped from it.

**Cross-department findings** are the part that makes it a board. The brief's
war room has the CFO, CRO and COO trading observations; implemented literally
that is three agents asserting things about each other's domains, which is
exactly the fabrication the safety rules forbid. So the correlation is
*computed, not conversed* — each finding is ONE SQL join spanning two seats:

* renewals due within 7 days owned by customers already in arrears (CFO+COO);
* customers with both an overdue invoice and an open ticket (CFO+CCO);
* suspended services whose owner is waiting on support (COO+CCO);
* domains expiring within 30 days for customers in arrears (CFO+COO);
* customers acquired in the last 30 days who are already overdue (CRO+CFO);
* orders that are **paid but still Pending** — charged and not yet served (COO+CFO).

A correlation with no matching rows produces no finding; the report is not
padded. A correlation whose tables are absent is reported as skipped.

**Composition.** `ExecutiveBoard::compose('daily'|'weekly'|'monthly')` writes
one row into the existing `reports` table as `board_daily` / `board_weekly` /
`board_monthly` — no duplicate schema. The deterministic report ships in full
with no model configured; narration is at most one call, is labelled as
narration in the UI, and is told explicitly that any seat marked NO DATA
SOURCE must not be discussed.

**Surface.** Admin → `action=board`. Requires the `ai.read` group (it shows
revenue); composing requires `ai.manage`. Settings: `board_enabled`,
`board_weekly_dow`, `board_monthly_dom`.

## 12. Approval policy — all writes gated

This deployment runs **all-gated**: `ApprovalEngine::requiresApproval()`
returns true for every risk class except `READ`, including `WRITE_LOW`, and an
unrecognised risk label fails closed into requiring approval. The ladder still
distinguishes the classes for reporting, but nothing above a read executes
without an approved row.

Four write tools now exist (§14), so the gate is load-bearing rather than
theoretical.

## 13. Write execution

The acting half of the loop. Four write tools exist; each one mutates through
the WHMCS API (never hand-written SQL, so WHMCS hooks, logging and
notifications fire exactly as they would for a human agent), and each one is
re-read afterwards to confirm it happened.

| Tool | Risk | Confirmed by |
|---|---|---|
| `write_ticket_reply` | WRITE_CUSTOMER_VISIBLE | reply count on the ticket increased |
| `write_ticket_note` | WRITE_LOW | note count on the ticket increased |
| `write_ticket_status` | WRITE_LOW | status reads back as the requested value |
| `write_invoice_reminder` | WRITE_CUSTOMER_VISIBLE | email log for the client gained a row |

**Deliberately absent:** marking an invoice paid, applying credit, refunds,
editing invoice lines, suspending or terminating services. Payment state is
set by a gateway callback that verified funds, never by an assistant — a
"mark as paid" tool is a licence to fabricate a receipt. Availability actions
need an unsuspend path and a blast-radius check this module does not have.

### The execution path

`ApprovalExecutor::run($approvalId)` is the only way a write runs:

1. **Claim** — one conditional `UPDATE … WHERE status = 'approved'` flips the
   row to `executing`. A second caller loses the race, so a double-submitted
   form or a retried job cannot send a customer two copies.
2. **Rebind** — arguments are re-read *from the approval row*, never taken
   from the caller. The executed action is the approved action by
   construction.
3. **Execute** — through `ToolExecutor`, so the kill switch, agent grants,
   RBAC, validation, redaction and audit all still apply.
4. **Verify** — the tool's `verify` closure re-reads the database. A tool
   with no verify closure is refused outright.
5. **Record** — outcome, verification note and digest land on the approval
   row and in the hash-chained audit log.

**An unverified write is recorded as `failed`.** If the WHMCS API returns a
success envelope but the row never appears, the platform reports *not
applied* and says what it checked. This is the behaviour the test suite
exercises hardest: a deliberately lying API fake that claims success and
writes nothing must never produce a success.

### Argument binding

`approvals.args_digest` holds a canonical SHA-256 of the arguments at request
time (keys sorted recursively, values compared as strings, so `401` and
`"401"` bind identically). Execution refuses on any mismatch, so an approval
for "reply to ticket 401 with this text" cannot be spent on ticket 999 or on
different text. Rows predating the column have a NULL digest and fail closed.

### Switches

* `writes_enabled` — master switch, **defaults OFF**. A fresh install can
  observe and propose but cannot act until an operator opts in.
* `tool_disabled_<tool>` — per-tool kill switch. (This had an off-by-one that
  made it silently never match; fixed, and now covered by a test.)
* Approving is **not** executing. An approved action waits for an explicit
  *Execute now*; nothing fires on a timer.


## 14. Customer surface (§17, §33)

Reached at `index.php?m=cloudhost247ai` in the normal WHMCS client area, via
the repo's standard `_clientarea()` + `templates/client/*.tpl` convention.

| Page | What it does |
|---|---|
| `assistant` | A signed-in customer asks about **their own** account |
| `activity` | Every AI run on that account, and the records each one read |

### Why there is no new endpoint

The question is posted to the page and handled server-side. `api.php` stays
**strictly admin-only**: widening it to also accept customer sessions would
put two different authority models behind one door, which is how privilege
bugs happen. The customer surface therefore adds no new public endpoint, no
XHR, and no CORS surface, and it works without JavaScript.

### Isolation is not the prompt's job

The assistant runs under a client session, so:

* `ToolExecutor::actorMay()` allows **only** READ tools explicitly marked
  client-bound — 9 of them;
* the readers force `userid = <this client>` into the SQL;
* a customer asking for `client_id = 22` still gets their own rows, because
  the forced predicate wins over the argument;
* `read_clients` (every customer), `read_metrics` (platform revenue) and all
  diagnostics are **not granted** to the customer agent and are refused in
  client scope even if they were;
* **no write tool is reachable in client scope at all**, structurally — a
  customer cannot reply to their own ticket or pay an invoice through the
  assistant even if an operator mis-granted a write tool.

The system prompt tells the model it is scoped to one account. The gate is
what enforces it. The isolation tests assert at the tool layer for that
reason — the model is not the control.

### Failing closed in front of a customer

Not signed in, feature disabled, kill switch, no model configured, rate
limited, bad CSRF, empty or over-long question — each renders a plain
explanation. None of them invent an answer, and the no-model case says so
rather than guessing at invoices.

Rate limit: 10 questions per 5 minutes per customer.

### Transparency (§33)

The activity page shows the customer every run on their account — question,
answer, status, and the tool calls behind it — filtered to their own
`actor_id` in SQL. One customer cannot see another's AI history; there is a
test for each direction.

`client_assistant_enabled` is **OFF by default** and the `customer_assistant`
agent seeds disabled.


## 15. Evaluation and observability (§30, §31)

Admin → `action=evaluations`. Runs daily from cron and on demand. **No model
is involved**, so it works on an installation with no provider configured and
two runs over the same window produce the same numbers.

### The rule that shapes it

**A rate over zero samples is not a rate.** An agent that ran zero times does
not have a 100% success rate; it has no success rate. Every metric carries
its sample size, a metric with `sample_size = 0` stores no value, and the
page renders that as *"no data in this window"*. A dashboard showing 100%
green for a system nobody used is worse than one that admits it knows
nothing.

### Quality metrics

Per agent and platform-wide, each a SQL aggregate over real rows:

| Metric | Why it is here |
|---|---|
| `runs`, `success_rate`, `failure_rate` | basic health |
| `citation_coverage` | share of successful answers carrying evidence |
| **`uncited_answers`** | answers produced with NO evidence — the anti-fabrication alarm; should be 0 |
| `tool_calls`, `tool_refusal_rate`, `tool_avg_duration_ms` | tool reliability |
| `proposals` | what the agents asked to do |
| **`human_override_rate`** | share of decided proposals a human **rejected** |
| `approval_rate`, `decision_expiry_rate` | is the inbox being worked |
| `write_verified_rate`, **`unverified_writes`** | executions confirmed by re-read |
| `tokens_in`, `tokens_out`, `cost_micros` | spend |

`human_override_rate` is the headline. It measures the agents against human
judgement, and it is the one signal the AI cannot improve by being more
confident about itself. A rising rejection rate means the proposals are
getting worse, full stop.

### Live safety probes

Unit tests prove the guards worked against fixtures on a developer's
machine. Probes prove they are **still working on this installation, right
now** — after an upgrade, a settings change or a half-finished migration.

| Probe | Asserts |
|---|---|
| `audit_chain_intact` | the hash chain still verifies |
| `unknown_tool_refused` | unregistered tool names are refused |
| `approval_gate_blocks_unapproved_write` | a write with no approval is refused |
| `client_scope_blocks_writes` | a customer session cannot reach any write tool |
| `client_scope_isolates_reads` | one real customer asking for another's invoices gets only their own |
| `redaction_strips_secrets` | credential-shaped text is still redacted |
| `audit_carries_no_secrets` | the stored audit log contains no unredacted credentials |

Three rules govern them:

* **Read only.** No probe creates, modifies or deletes a business record.
  Probes that exercise write paths aim at a deliberately non-existent entity,
  so even a completely broken guard cannot cause a write — the worst case is
  a NotFound. There is a test asserting the row counts are unchanged.
* **No synthetic records** (§37). A probe with nothing real to test reports
  `skipped` and says what was missing. It never manufactures a customer in
  order to test customer isolation.
* **Failure is specific.** A failing probe names the invariant that broke.
  The suite proves this by actually tampering with an audit row and checking
  the probe reports the broken row id.

Results are stored in the existing `evaluations` table (§34 — no new schema),
probes under the `probe` scope.

### Alerts

Deliberately short, because an alert list that cries wolf gets ignored:
any failing probe, any uncited answer, any unverified write, a human
override rate ≥ 50% over ≥ 5 decisions, and an expiry rate ≥ 50%.


## 16. Operations runbook

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
