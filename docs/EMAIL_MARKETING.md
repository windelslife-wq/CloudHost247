# CloudHost247 Email Marketing — module runbook

**Module:** `modules/addons/cloudhost247marketing` (namespace `Ch247Mkt\`, tables `mod_ch247m_*`)
**Admin path:** WHMCS admin → Addons → **Email Campaigns**
**Status:** sendable. Queue + cron delivery, tracking, analytics, compliance and
automations are implemented and covered by 674 tests.
**Scope decision:** ONE marketing module. It reuses the audience, queue and
transport layers for broadcasts *and* automations; it does **not** take over
WHMCS transactional mail.

---

## 1. What this is

A GoDaddy-style campaign builder native to this WHMCS install:

* **Audience** — subscribers, lists, tags, custom fields, CSV/TSV import,
  export, double opt-in, consent timestamp + source, and **dynamic WHMCS
  segments** built straight off `tblclients` / `tblhosting` / `tblproducts` /
  `tbldomains` / `tblinvoices` / `tblorders` / `tbltickets` / `tblaffiliates`
  (no CSV round-trip).
* **Composer** — 14 content blocks and 7 layouts rendered to table-based,
  inline-styled, Outlook-safe email HTML, plus a generated plain-text part.
* **Template library** — 14 seeded templates (welcome, newsletter, hosting /
  domain / VPS / RDP promo, new product, special offer, discount, maintenance,
  service announcement, holiday, abandoned cart, blank), save-as-template, and
  duplicate-a-previous-campaign.
* **Personalisation** — 17 merge tags validated *before* a send is allowed.
* **Delivery** — campaign → frozen recipient ledger → queue → cron worker →
  transport. Retries with exponential backoff and jitter, rate limits, leases,
  idempotency keys, a wall-clock budget, and a kill switch.
* **Tracking & analytics** — per-recipient tokens, opens, clicks, bounces,
  unsubscribes, complaints, click map, per-recipient report, dashboard.
* **Compliance** — pre-flight gates, suppression list, `List-Unsubscribe` /
  RFC 8058 one-click, physical postal address, hash-chained audit trail.
* **Automations** — event-triggered multi-step journeys with waits.

## 2. What it is NOT

* **Not** a replacement for WHMCS transactional email. Invoices, password
  resets and service notices keep flowing through WHMCS. A campaign typed
  `transactional` exists so one-off operational blasts can skip the marketing
  unsubscribe rules — it is not wired into WHMCS's own mail.
* **Not** a web-request sender. Nothing is delivered inside an admin page load
  or a client-area hook; see §6.
* **Not** an XLSX importer. Import accepts CSV and TSV (save the spreadsheet as
  CSV first). This is deliberate — no spreadsheet parser is vendored in.
* **Not** a drag-and-drop canvas with a persisted visual editor state. The
  builder (`assets/js/builder.js`) edits the design JSON; the PHP `Renderer`
  remains the single source of truth for the HTML that actually ships.
* **Not** a bounce *receiver*. Bounces are recorded from transport responses
  (SMTP codes / API errors). There is no inbound webhook listener yet.

## 3. Surfaces

| Surface | Where |
|---|---|
| Admin portal | `addonmodules.php?module=cloudhost247marketing&action=…` → `dashboard`, `campaigns`, `campaign`, `builder`, `preview`, `templates`, `automations`, `automation`, `subscribers`, `subscriber`, `import`, `lists`, `segments`, `segment`, `queue`, `suppressions`, `settings` |
| Public tracking | `modules/addons/cloudhost247marketing/track.php?t=o\|c\|u\|p\|v&r=<token>[&l=<link>]` — open pixel, click redirect, unsubscribe, preferences, web view. No session; the unguessable per-recipient token is the only credential. |
| Cron | `php modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php --quiet` — every 5 minutes |
| Hooks | `hooks.php` — client sync, automation triggers, cart activity, and an optional `AfterCronJob` fallback worker |

## 4. Installation

1. Deploy the overlay as described in the repository `README.md`.
2. WHMCS → System Settings → **Addon Modules** → activate *CloudHost247 Email
   Marketing*. Activation runs the seven additive migrations and seeds the 14
   templates plus an `all-customers` list. Re-activation is a no-op;
   deactivation drops nothing.
3. Add the cron entry:

   ```cron
   */5 * * * * php /path/to/whmcs/modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php --quiet
   ```

   If you cannot add a system crontab, turn on `cron_fallback_enabled` and the
   `AfterCronJob` hook will drain a batch on WHMCS's own cron instead. It is
   the slower option — WHMCS cron typically runs every 5 minutes at best.
4. Settings page → set **sender identity** (`from_name`, `from_email`,
   `reply_to`, `company_name`) and the **physical postal address**. Marketing
   sends are blocked until the address is set.
5. Choose a **transport** and verify it (§5).
6. `sending_enabled` ships **off**. Send a test, check the queue page, then
   turn it on.

## 5. Transport

`transport` selects the driver; each is verifiable from the settings page
before you trust it with a campaign.

| Driver | Use when |
|---|---|
| `whmcs` | Smallest setup. Hands the message to WHMCS's configured mail settings. |
| `smtp` | Direct ESMTP, dependency-free (`smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_password`, `smtp_timeout_seconds`). |
| `api` | HTTP provider: `sendgrid`, `postmark`, `mailgun`, or `generic` (`provider`, `provider_endpoint`, `provider_api_key`, `provider_region`). |
| `null` | Dry run. Exercises the whole pipeline — queue, personalisation, tracking rewrite, ledger — without a byte leaving the host. |

`smtp_password` and `provider_api_key` are **sealed** (`sealed:v1:`,
AES-256-CBC + HMAC-SHA256, key derived from the WHMCS `$cc_encryption_hash`).
They are write-only in the UI and never rendered back. Any setting can also be
pinned from the environment with the `CH247M_` prefix (e.g.
`CH247M_PROVIDER_API_KEY`); env-provided keys become read-only in the UI.

A transport that reports itself unconfigured **releases its claimed queue rows
without burning a retry attempt** — a misconfiguration costs you time, not
delivery budget.

## 6. Delivery model

```
Campaign → resolve audience → freeze recipient ledger → queue → cron worker → transport → ledger + events
```

* **Freezing.** `buildRecipients()` writes one `campaign_recipients` row per
  person with its own tracking token. Re-running it never duplicates
  (`UNIQUE(campaign_id, email_hash)`).
* **Idempotency.** Every queue row carries
  `sha256(campaign_id|email)`, so a crashed worker that re-enqueues cannot
  double-send.
* **Leases, not locks.** `claimBatch()` conditionally claims rows for
  `lock_seconds`; a worker that dies has its rows released by the next run.
* **Backoff.** `retry_base_seconds * 2^(n-1)`, ±20% jitter, clamped to
  `retry_max_seconds`, giving up at `max_attempts`.
* **Budgets.** `send_rate_per_minute` and `queue_wall_clock_seconds` stop a run
  before it overruns the cron interval; unprocessed rows go back untouched.
* **Pausing works immediately** — rows belonging to a paused or cancelled
  campaign are skipped at claim time, not mid-batch.
* **Last-moment suppression.** The worker re-checks the suppression list as it
  builds each message, so someone who unsubscribed after the audience was
  frozen is skipped rather than mailed.
* **Stop reasons.** `processBatch()` reports why it stopped:
  `kill_switch`, `sending_disabled`, `rate_limit`, `transport_unconfigured`,
  `wall_clock`, or `''`.

Automation sends reuse the same queue as pre-rendered one-offs (they carry the
trigger's merge context), but they pass a `recipient_id`, so their deliveries,
bounces and events land on the ledger exactly like a broadcast.

## 7. Compliance

Pre-flight **blocks** a send on: sending disabled, kill switch, invalid
`from`/`reply-to`, empty subject, empty body, unknown merge tags, an empty
audience, and — for anything not typed `transactional` — a missing
`{{unsubscribe_url}}` or an unset physical postal address. Spam/deliverability
heuristics are **advisory**: they score and warn, they never block.

* Unsubscribing is **global**: it sets the subscriber's status, writes a
  suppression entry, and removes them from every list and every in-flight
  automation.
* A suppression is never silently downgraded or removed; `unsuppress()` is
  deliberate, attributed and audited.
* The audit log is hash-chained — `Audit::verifyChain()` detects an edited or
  deleted row.
* `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
  (RFC 8058) and `Precedence: bulk` are set on marketing mail.

## 8. Tracking

Links are rewritten at compile time to `track.php?t=c`, with a
`%%CH247M_RCPT%%` sentinel swapped for the recipient's token as each message is
built — so the per-recipient token is never stored in the campaign body.
`mailto:`, anchors and `{{merge_tag}}` hrefs are left alone. The redirector
re-validates the stored URL and only ever emits `http(s)`.

Image proxies and security scanners (Google, Proofpoint, Mimecast, SafeLinks,
scripted clients, generic `bot`/`crawler`/`spider` agents) are filtered at the
HTTP edge: the pixel and the redirect still work, but they do not inflate open
and click counts. A click implies an open, so pixel-blocking clients still
register.

Open and click **rates use `delivered` as the denominator**, not `sent`.

## 9. Automations

14 triggers (`client.created`, `order.placed`, `invoice.created`,
`invoice.paid`, `service.activated` / `suspended` / `unsuspended` /
`terminated` / `cancel_requested`, `ticket.opened`, `affiliate.activated`,
`domain.transferred`, `cart.abandoned`, `manual`) and 6 actions (`wait`,
`send_email`, `add_tag`, `remove_tag`, `add_to_list`, `end`).

Design rules worth knowing before you build one:

* **Hooks only enrol.** A WHMCS hook does one INSERT and returns; it can never
  send mail, and `trigger()` swallows its own errors so a broken automation can
  never break checkout.
* **The cron advances one step per tick**, and `next_run_at` is absolute — a
  missed cron resumes the journey instead of replaying it.
* An automation **starts disabled** and cancels its in-flight enrollments when
  you disable it again.
* Nobody unmailable or suppressed is ever enrolled; unsubscribing mid-journey
  cancels the enrollment.
* One failing step marks **that enrollment** failed, not the automation.
* **Abandoned carts** are detected by the cron sweep after
  `abandoned_cart_minutes`, never by a page view; `notified_at` guarantees one
  fire per cart, and checkout clears it.

## 10. Permissions

Role 1 (super admin) always has everything. Other roles are granted groups on
the settings page:

| Group | Grants |
|---|---|
| `mkt.read` | View dashboards, campaigns and reports |
| `mkt.audience` | Manage subscribers, lists, segments, imports |
| `mkt.compose` | Create and edit campaigns and templates |
| `mkt.send` | Schedule, send, pause, cancel — **separate from compose by design** |
| `mkt.manage` | Settings, transport, permissions, suppressions |
| `mkt.audit` | Read the audit trail |

Every POST goes through CSRF verification plus a group check.

## 11. Data

Seven additive migrations, all prefixed `mod_ch247m_`:

| Migration | Tables |
|---|---|
| `0001_core` | `settings`, `rate_limits`, `role_permissions`, `audit_log` |
| `0002_audience` | `lists`, `subscribers`, `list_members`, `segments` |
| `0003_campaigns` | `templates`, `campaigns`, `campaign_recipients` |
| `0004_delivery` | `email_queue`, `email_events`, `suppressions`, `links` |
| `0005_automations` | `automations`, `automation_steps`, `automation_enrollments` |
| `0006_seed` | 14 templates + the `all-customers` list |
| `0007_cart_activity` | `cart_activity` |

Retention is configurable (`retention_days_events`, `retention_days_queue`) and
enforced by the cron. **Purging events never touches the recipient ledger**, so
campaign reports stay correct after the raw event feed has aged out.

## 12. Operations

```bash
# one cron pass, verbose
php modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php

# a single queue batch instead of draining the full budget
php modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php --once

# run maintenance phases while sending is switched off
php modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php --force
```

`--force` never overrides the kill switch.

**If something is going wrong mid-send:** set `kill_switch` on the settings
page. The worker stops at the top of its next batch without consuming the
queue; clearing it resumes exactly where it stopped.

## 13. Tests

No PHP binary is required — the suites run through php-wasm under Node:

```bash
cd modules/addons/cloudhost247marketing
npm install          # once, pulls @php-wasm/node
node tests/lint.mjs        # every file loads and parses
node tests/crosscheck.mjs  # every Class::member referenced actually exists
node tests/run.mjs         # 674 assertions across 8 suites
node tests/run.mjs Delivery  # one suite
```

The suites run against in-memory SQLite with the **real** migrations and
WHMCS-shaped fixtures, a frozen clock, and the `null` transport.

| Suite | Covers |
|---|---|
| `01_MigrationTest` | Schema, settings layering, secrets, RBAC |
| `02_AudienceTest` | Subscribers, lists, consent, segments, import |
| `03_RenderTest` | Blocks, layouts, renderer hardening, URL policy, merge tags, every shipped template |
| `04_CampaignTest` | Compile, audience resolution, scheduling, state machine, test sends |
| `05_DeliveryTest` | Queue, leases, backoff, retries, bounces, kill switch, rate limits, transports |
| `06_ComplianceTest` | Pre-flight, suppression, double opt-in, audit chain |
| `07_TrackingTest` | Opens, clicks, bot filtering, click map, analytics, web view |
| `08_AutomationTest` | Triggers, enrolment, waits, journeys, abandoned carts |
