# Domain Broker Service — completion report

A production-grade domain acquisition / brokerage system for CloudHost247,
implemented as a WHMCS addon module plus a public landing page in the hostx
theme. It reuses the existing client accounts, invoicing, transactions,
domains, notifications, admin users and client area rather than running a
parallel system.

Branch: `arena/ae786edb-cloudhost247` · Commit: `46897bc`

---

## 1. Test and build results

```
node modules/addons/domainbroker/tests/run.mjs
  01_CoreTest.php          PASS=103  FAIL=0
  02_RequestTest.php       PASS=78   FAIL=0
  03_NegotiationTest.php   PASS=72   FAIL=0
  04_PaymentTest.php       PASS=93   FAIL=0
  05_TransferTest.php      PASS=101  FAIL=0
  06_SecurityTest.php      PASS=131  FAIL=0
  07_FeeReportTest.php     PASS=150  FAIL=0
  08_ApiTest.php           PASS=151  FAIL=0
  09_HttpTest.php          PASS=130  FAIL=0
  ────────────────────────────────────────
  TOTAL                    PASS=1009 FAIL=0

node modules/addons/domainbroker/tests/lint.mjs
  FILES=85  BAD=0
```

**Build gate.** This repository is a WHMCS *overlay*: there is no application
root, no `composer.json`, no `package.json` and no bundler, so there is no
framework build to run. The equivalent gate is `tests/lint.mjs`, which loads
every shipped PHP file through a real PHP 8.3 runtime — proving each file
parses, every parent/interface resolves through the autoloader, and each class
body is well formed — and syntax-checks the five WHMCS entry points (which
cannot be loaded standalone) with `token_get_all(..., TOKEN_PARSE)`. The two
new root-level files, `domain-broker.php` and the amended
`lang/overrides/english.php`, were syntax-checked the same way.

The sandbox has no native `php`, no Composer and no PHPUnit, so the suite runs
on `@php-wasm/node` (PHP 8.3.33, `pdo_sqlite` + `openssl`). Source targets PHP
7.4 for WHMCS 8.x compatibility.

---

## 2. Files created

### Module root — `modules/addons/domainbroker/` (112 files, ~27,100 lines of PHP)

| Area | Files |
| --- | --- |
| Entry points | `domainbroker.php` (addon: `_config`, `_activate`, `_deactivate`, `_output`, `_clientarea`, `_sidebar`), `hooks.php`, `api/index.php`, `api/webhook.php`, `cron/domainbroker.php`, `autoload.php` |
| Migrations | `install/migrations/0001_create_core_tables.php` … `0006_create_api_tokens.php` |
| Core (20) | `Db`, `Blueprint`, `Migrator`, `Clock`, `Crypto`, `Csrf`, `Audit`, `Rbac`, `Actor`, `Identity`, `Http`, `Idempotency`, `RateLimiter`, `Logger`, `Money`, `Settings`, `Str`, `Validator`, `DomainName`, `Exceptions` |
| Workflow (4) | `RequestStatus`, `OfferStatus`, `PaymentStatus`, `TransferStatus` |
| Services (17) | `RequestService`, `NegotiationService`, `AssignmentService`, `PaymentService`, `TransferService`, `VerificationService`, `MessageService`, `DocumentService`, `DisputeService`, `FeeService`, `ReportService`, `RiskService`, `NotificationService`, `BrokerDirectoryService`, `DomainIntelService`, `ContactVaultService`, `ApiTokenService` |
| Billing integration (4) | `Gateway`, `GatewayInterface`, `WhmcsGateway`, `FakeGateway` (tests only) |
| Escrow (6) | `EscrowManager`, `EscrowProviderInterface`, `EscrowResult`, `InternalEscrowProvider`, `HttpEscrowProvider`, `ManualEscrowProvider` |
| REST API (16) | `Router`, `ApiRequest`, `ApiResponse`, `Presenter` + 12 controllers |
| HTML layer (6) | `Controller`, `View`, `Html`, `CustomerPortal`, `BrokerDesk`, `AdminPortal` |
| Client templates (10) | `dashboard`, `requests`, `request_new`, `request_detail`, `transactions`, `notifications`, `help`, `message`, `redirect`, `login_required` |
| Assets (3) | `assets/css/client.css`, `assets/css/admin.css`, `assets/js/domainbroker.js` |
| Tests (13) | `bootstrap.php`, 9 suites, `lint.php`, `lint.mjs`, `run.mjs` |

### Site-level

- `domain-broker.php` — public landing page controller.
- `templates/hostx/domainbroker-landing.tpl` — landing page template.
- `docs/DOMAIN_BROKER.md` — this report.

## 3. Files modified

| File | Change |
| --- | --- |
| `lang/overrides/english.php` | Appended ~40 `$_LANG['domainbroker*']` strings. Nothing existing touched. |
| `templates/hostx/includes/blocks/footer_block.tpl` | One extra "Useful links" entry pointing at `domain-broker.php`. |
| `templates/hostx/includes/blocks/footer_block_latest.tpl` | One extra "Domains" footer entry. |
| `.gitignore` | Ignore the module's `node_modules` symlink and runtime `storage/` contents. |

No WHMCS core file, no existing root page and no existing module was changed.
WHMCS domain registration and transfer are untouched: the broker module writes
only to its own tables and reads WHMCS through `localAPI()`/`Capsule`.

---

## 4. Migrations

Six migrations run by `Migrator` on `_activate` (and idempotent on re-run),
creating 23 tables with foreign keys, indexes, timestamps, status columns,
audit columns and soft-delete columns. Logical names, no `mod_` prefix, table
prefix configurable in `Db`.

| Migration | Tables |
| --- | --- |
| `0001_create_core_tables` | `requests`, `brokers`, `roles`, `assignments`, `contacts`, `activity` |
| `0002_create_negotiation_tables` | `negotiations`, `offers`, `messages`, `documents` |
| `0003_create_financial_tables` | `payments`, `transfers`, `fees`, `verifications`, `webhooks` |
| `0004_create_support_tables` | `disputes`, `notifications`, `risk`, `idempotency`, `ratelimits`, `settings` |
| `0005_seed_defaults` | Seeds RBAC roles, default settings and one editable 10% `standard` fee rule |
| `0006_create_api_tokens` | `tokens` |

Append-only (never updated in place): `offers`, `negotiations`, `payments`,
`activity`. Soft-deleted rather than destroyed: `requests`, `brokers`,
`messages`, `contacts`, `documents`, `fees`, `tokens`.

---

## 5. APIs added

`modules/addons/domainbroker/api/index.php` — **69 routes** across 12
controllers, path resolved from `PATH_INFO` or `?path=`. Envelope:
`{success:true,data,meta}` / `{success:false,error:{code,message,details}}`.

- **Requests** — create, get, list, update, cancel, assign, claim, approve, reject, status override, escalate, timeline, transaction history.
- **Offers / negotiation** — record owner contact, record owner response, create offer, counteroffer, accept, reject, withdraw, list.
- **Payments** — generate invoice, sync with billing, refund (full/partial), release from escrow, custody state.
- **Transfers / verification** — start, update status, record auth code, complete transfer, complete acquisition, submit evidence, approve verification.
- **Messages / documents** — send message, list thread, upload document, list, download.
- **Disputes** — open, set status, resolve, reject.
- **Brokers** — list, create, update, deactivate.
- **Admin** — fee CRUD, reports overview, broker report, export, risk flags, risk review, per-request audit.
- **System** — `ping`.

Every route is authenticated (bearer token or WHMCS session), RBAC-checked,
input-validated, rate-limited per bucket, audit-logged, and the financial ones
(`invoice`, `accept`, `refund`, `release`, `dispute resolve`) are idempotent.
Bearer tokens are exempt from CSRF; cookie sessions are not. Tokens are stored
as SHA-256 with scope strings (`*`, `controller.*`, `controller.action`).

`api/webhook.php` — escrow callbacks with signature verification, replay
protection via a logged `event_id`, and a `{accepted, reason}` result.

---

## 6. Customer pages added

Public: **`/domain-broker.php`** — hero, "how it works" (5 stages),
assurances, FAQ accordion, start-a-request form. Uses the hostx hero /
breadcrumb / accordion idiom and the live fee rule for its pricing sentence.

Client area, under `index.php?m=domainbroker`:

| Page | Contents |
| --- | --- |
| Overview | Summary counters, actionable items, recent requests, spend by currency, unread notifications |
| My Acquisitions | Filterable, searchable, paginated list |
| New Request | Domain + availability lookup, max budget, currency, budget-includes-fees, message, anonymous flag, promo code |
| Request detail | Domain, reference, status tracker, broker, budget, current offer, counteroffer, broker fee, tax, total, payment status, transfer status, timeline, messages, documents, invoices, payment actions |
| Negotiation / offer actions | Accept, decline, counteroffer — all on the detail page, all server-validated |
| Payment | Hands off to the existing WHMCS invoice (`viewinvoice.php`) |
| Transfer tracking | Registrar, auth-code state, transfer milestones (codes themselves never shown) |
| Transactions | Full history with invoice and request links |
| Notifications | In-app feed with mark-all-read |
| Help / FAQ | Service explanation and process questions |

## 7. Broker pages added

A dedicated **Broker desk** in the admin area (staff with a broker role get the
desk, not the console) with ten buckets — new & unassigned, assigned to me,
active negotiations, awaiting customer, accepted offers, payment pending,
transfers, completed, unsuccessful, disputes — and actions: claim, contact
owner, record owner response, submit offer, record counteroffer, withdraw
offer, request customer approval, message the customer, internal notes, upload
documents, start transfer, record auth code, update transfer status, mark
milestones, submit verification evidence, escalate.

Internal notes are a separate thread (`is_internal=1`) that the customer
surface can never return; suite 09 asserts a broker note and an admin note are
both absent from the customer's serialised page data.

## 8. Admin pages added

Ten tabs under `addonmodules.php?module=domainbroker`: **Dashboard, Requests,
Brokers, Payments, Disputes, Risk Review, Fees, Reports, Audit Log, Settings**.

Capabilities: search/filter; assign and reassign brokers; view customer,
domain, negotiation, offers, payments, commissions and transfer; approve,
reject, cancel; refund (full and partial) subject to permission; resolve
disputes; override status; manage fee rules, service fees, currencies and
configuration; reports with date filtering; CSV export of five datasets; and a
hash-chain-verified audit view.

RBAC roles: `customer, broker, broker_lead, admin_viewer, admin_manager,
admin_finance, admin_super, system, guest`. `admin_manager` cannot refund,
release funds, override statuses, view PII or manage fees; `admin_finance`
cannot run verifications, assign brokers or override; only `admin_super` holds
`PII_VIEW`, `SETTINGS_MANAGE` and `STATUS_OVERRIDE`. Permission alone is never
sufficient for broker-scoped data — ownership is asserted separately.

---

## 9. Integrations added

- **WHMCS clients** — requests are keyed on `tblclients.id`; the client area resolves the signed-in client server-side and never trusts a posted client id.
- **WHMCS invoices & transactions** — `WhmcsGateway` creates invoices through `localAPI('CreateInvoice')`, reads payment state back, and issues refunds; the customer pays on the normal `viewinvoice.php` screen with the existing gateways. No card data is ever seen or stored by this module.
- **WHMCS hooks** (11) — `ClientAreaHeadOutput`, `ClientAreaPrimaryNavbar`, `ClientAreaSecondarySidebar`, `ClientAreaPageDomainChecker` (exposes the brokerage call-to-action to the domain checker when a searched name is taken), `InvoicePaid` → `PaymentService::syncWithBilling`, `InvoicePaymentReminder`, `LogTransaction` and `InvoiceCancelled` → payment failure recording, `ClientClose`, `AdminAreaHeadOutput`, `AdminHomeWidgets`.
- **WHMCS domains** — a request carries a `whmcs_domain_id` link into `tbldomains`, set when the acquisition completes, so the acquired name ties back to the client's normal domain list. Registration and transfer flows themselves are not modified.
- **Notifications** — email through the WHMCS mail stack plus an in-app feed, for every lifecycle event.
- **Support tickets** — escalations and disputes can raise a ticket through `localAPI`.
- **Cron** — `cron/domainbroker.php` runs seven tasks (prune rate limits, prune idempotency keys, expire requests, expire offers, expire pending payments, expire stalled transfers, retry failed notifications), each isolated so one failure cannot stop the rest.
- **Escrow** — `EscrowManager` behind `EscrowProviderInterface`, with internal, HTTP and manual providers, swappable by configuration; webhooks are signature-verified.
- **Domain intelligence** — availability, registration status, TLD, registrar, creation/expiry, transfer status and DNS via a pluggable resolver; RDAP/WHOIS is off by default (`rdap_enabled=0`) and only surfaces what the registry permits.

---

## 10. Tests added

| Suite | Covers |
| --- | --- |
| `01_CoreTest` | Db/Blueprint/migrations, Clock, Money (incl. JPY/BHD exponents), Crypto, Csrf, Audit hash chain, Rbac matrix, Idempotency, RateLimiter, Validator, DomainName, Settings precedence |
| `02_RequestTest` | Creation, validation, duplicate/idempotent submission, authz, listing, filtering, update, cancellation, expiry, soft delete, risk flagging |
| `03_NegotiationTest` | Owner contact, offers in both directions, multi-round counteroffers, history never overwritten, expiration, accept, reject, withdraw, budget ceiling enforcement |
| `04_PaymentTest` | Invoice generation, idempotency, billing sync, funds secured vs. transfer, release gating, full/partial refunds, over-refund rejection, failed payments, payment expiration, escrow webhooks |
| `05_TransferTest` | Transfer start gating, auth-code encryption and reveal permissions, status transitions, failure paths, verification requirements, completion gating |
| `06_SecurityTest` | CSRF, authn/authz per resource, internal-note leakage, contact vault encryption and crypto-shredding, upload rejection (php, double extensions, `.htaccess`, bad magic, oversize), audit immutability, rate limiting, webhook signature forgery |
| `07_FeeReportTest` | Fixed/percentage/tiered/min/max/currency-specific/promotional fee rules, tax on commission, reporting metrics, CSV export and formula-injection neutralisation |
| `08_ApiTest` | All 69 routes: auth, scopes, RBAC, validation errors, rate limits, idempotency replay, error envelopes |
| `09_HttpTest` | Customer portal, broker desk and admin console: template selection, capability flags, PRG redirects, CSRF refusal, cross-client access denial, internal-note invisibility, budget ceiling, invoice double-submit, CSV export, settings-secret refusal, pagination and filtering |

Both success and failure paths are asserted throughout.

---

## 11. Security and production-quality notes

- No mock or demo behaviour: no fake payment completion, no fake transfer completion. Payment and transfer are separate states and "paid" never implies "completed".
- No hardcoded broker accounts, prices or fees — brokers are rows, fees are rules, both admin-managed.
- No secrets in source. `encryption_key`, `escrow_api_key` and `escrow_webhook_secret` come only from `DOMAINBROKER_<KEY>` environment variables; `Settings::set()` refuses them and the settings screen says so.
- Auth codes, escrow references, verification references and contact details are encrypted (AES-256-GCM, per-context keys); contact emails carry a hashed blind index. Auth-code reveal requires a permission plus ownership plus a logged reason.
- Audit: one append-only, hash-chained activity table discriminated by visibility (`customer|broker|internal`), recording actor, actor type, action, request id, previous value, new value, IP, user agent, timestamp and reason. `Audit::verifyChain()` is surfaced in the admin UI.
- Uploads are stored outside the web root as `hex.ext.bin` with validated type, size and magic bytes; customer uploads are forced to customer visibility.
- Fraud/risk scoring flags high-value acquisitions, repeated failed payments, rapid repeated offers, suspicious accounts, identity/payment mismatches, unusual transfer behaviour and repeated disputes; a score over the review threshold forces manual review and a higher score blocks automatic progression.
- All state changes are validated server-side against the workflow machine; no client-supplied status is ever trusted.

---

## 12. Remaining issues and deployment notes

1. **Activation is required.** Deploy with the documented `rsync`, then
   Setup → Addon Modules → Domain Broker → Activate. `_activate` runs the
   migrations and seeds the RBAC roles; set `bootstrap_admin_id` to the
   administrator who should receive the first `admin_super` grant.
2. **Environment variables must be set before taking live payments:**
   `DOMAINBROKER_ENCRYPTION_KEY` (required — without it nothing encrypts),
   and, if an external escrow provider is used, `DOMAINBROKER_ESCROW_API_KEY`
   and `DOMAINBROKER_ESCROW_WEBHOOK_SECRET`.
3. **Cron.** Add `php -q /path/to/whmcs/modules/addons/domainbroker/cron/domainbroker.php`
   to the system crontab (hourly is appropriate).
4. **Escrow provider.** The shipped default is `InternalEscrowProvider`
   (funds held on the WHMCS invoice/credit ledger with explicit custody
   states). `HttpEscrowProvider` is the integration point for a third-party
   escrow service; its endpoint and credentials are configuration, not code.
5. **Domain intelligence resolver.** `DomainIntelService` ships with a DNS/
   registry-data resolver and RDAP disabled. Enable `rdap_enabled` only where
   the registry permits it, and point the resolver at your registrar module if
   you want authoritative registrar/expiry data.
6. **Main navigation.** The hostx mega menu is stored in the theme's database
   settings, not in template files, so the landing page link was added to both
   footer blocks and to the client-area navbar/sidebar via hooks. Add
   "Domain Broker Service" to the top menu through the hostx theme settings if
   you want it in the main nav.
7. **Tests in a fresh checkout.** `tests/run.mjs` needs `@php-wasm/node`
   resolvable from the module directory (see `tests/README.md`); the
   `node_modules` entry there is git-ignored.
8. **Not implemented by design:** no automatic registrar API transfer
   execution. Transfers are tracked as explicit, evidence-backed milestones
   because faking completion was out of scope; wiring a registrar module into
   `TransferService::updateStatus()` is the natural next step.
