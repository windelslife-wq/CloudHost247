# CloudHost247 Services Suite

WHMCS addon module `cloudhost247services` — the platform-services layer for the
CloudHost247 rollout: the **Domain Services / Domain Marketplace platform**
(search, transfers, management/DNS, auto-renewal, registration, bulk search),
domain valuation, domain auctions with anti-sniping and real invoicing, the
Discount Domain Club, a database-driven TLD catalogue, public WHOIS lookup,
expert-service intake with quotes, the Logo Studio, the AI Website Builder
shell, the unified inbox and staff dashboards — plus the **infrastructure
layer** (v1.2.0): an OS catalog with version lifecycle, admin-managed provider
image mappings (test-before-enable), a generic HTTP infrastructure-provider
adapter with sealed credentials, idempotent server provisioning / OS
reinstall / server-action jobs on the existing queue, a payment-gated
customer order flow and customer server management. Current version:
**1.2.0**.

The domain platform and the infrastructure layer are extensions of this same
module — there is no second addon, no second billing system and no duplicate
customer system. WHMCS stays the system of record for clients, carts, orders,
invoices, payments, the `tbldomains` registry and server products/services
(`tblproducts`, `tblorders`, `tblinvoices`, `tblhosting`, `tblservers`); the
module links to them by id and never copies billing data.

## Architecture (the short version)

- **One addon module** (`cloudhost247services.php`) with lifecycle handlers
  (config/activate/deactivate/upgrade/output/clientarea/sidebar).
- **`lib/Core`** — Clock, Settings (env→override→DB→default layers with
  secret hygiene), Db (mod_chs_ prefix, identifier-validated), Blueprint +
  Migrator (install/migrations are the schema), Csrf, RateLimiter, Audit
  (hashed IP), Money (integer minor units), Identity, DomainName, Validator,
  Str, Exceptions, Logger, Platform, **Secrets** (AES-256-GCM credential
  sealing; refuses to seal when `CHS_CREDENTIALS_KEY` is unset — fails closed).
- **`Platform::gateway()`** is the only seam to WHMCS: production binds
  `WhmcsGateway`, tests bind the recording `FakeGateway` implementing the same
  `GatewayInterface`.
- **`lib/Services`** — one service per feature area; all business rules,
  validation and workflow status machines (`lib/Workflow/*Status.php`) live
  there, never in templates. Domain platform services: `DomainSearchService`,
  `BulkSearchService`, `TransferService`, `DomainManagementService`,
  `RenewalService`, `RegistrationService`, `DomainEventService`.
- **`lib/Providers/Domain`** — the registrar abstraction:
  `DomainProviderInterface` (checkAvailability, getPricing, registerDomain,
  transferDomain, renewDomain, getDomain, updateDomain, get/setNameservers,
  DNS CRUD, getWhois, getTransferStatus, cancelTransfer),
  `AbstractDomainProvider`, `WhmcsRegistrarProvider` (platform default —
  reads are real via the WHMCS registrar chain, writes fail honestly with
  `PROVIDER_OPERATION_UNSUPPORTED`), `HttpRegistrarProvider` (generic JSON
  registrar API with sealed credentials), `NullDomainProvider`,
  `ProviderRegistry` (TLD mapping → default provider → WHMCS chain → null;
  credentials are masked in every list view and never reach the browser).
- **`lib/Workflow`** — `JobQueue` (idempotency keys, lease-based claims that
  recover crashed workers, bounded retries with quadratic backoff, admin
  retry, retention purge), `DomainJobTypes` (13 job types),
  `DomainWorker` (dispatch with a test seam).
- **`lib/Http`** — AdminPortal (admin tab routing .phtml), CustomerPortal +
  Controller (client area routing, Smarty), Landing (public page bootstrap),
  global `chs_*` display helpers. Domain platform: customer actions
  `search`/`bulk`/`bulkview`/`domains`/`domain`/`transfers`/`transfer`;
  admin sections `domains`/`providers`/`transfers`/`operations`.
- **Providers** — Valuation (rules engine + optional external HTTP engine),
  WHOIS (socket → cached), AI (null → HTTP). Every provider reports an honest
  configuration status instead of pretending to work.
- **`install/migrations/*.php`** — MySQL/SQLite-portable through `Blueprint`;
  applied ids recorded in `mod_chs_migrations`, re-running is a no-op.
  Migration `0012_domain_platform.php` adds the domain platform tables
  (`domain_providers`, `domain_provider_mappings`, `domain_services`,
  `domain_transfers`, `domain_dns_records`, `domain_searches`,
  `domain_search_results`, `jobs`, `domain_renewals`, `domain_events`,
  `domain_expiration_notices`).
- **`hooks.php`** — menu/SEO/badge/invoice contributions to WHMCS, including
  the Domains navbar entries (search / bulk / transfer / whois) and the
  `InvoicePaid` hook that drives transfer + auto-renewal job creation.
- **`cron/cloudhost247services.php`** — closing/settling auctions, club
  expiry, sitemap regeneration, **plus the domain platform**: enqueues the
  periodic jobs (`DOMAIN_EXPIRATION_CHECK`, `DOMAIN_PROVIDER_SYNC`,
  `DOMAIN_RECONCILIATION`) with per-window idempotency keys, drains the queue
  via `DomainWorker::run()`, and purges old jobs. Schedule every 5 minutes.

## Domain platform in one paragraph

Customers search (`/domains`, `/domain-search.php`, module portal `search`),
bulk-search (`/bulk-domain-search.php`, module portal `bulk`/`bulkview` with
CSV export), transfer (`/domain-transfer.php`, module portal `transfers`), and
manage registered domains from the client area (`domains`/`domain` — auto-renew
toggle, privacy, nameservers, DNS records A/AAAA/CNAME/MX/TXT/NS/SRV/CAA with
validation + audit). All registrar traffic goes through the provider
abstraction; unconfigured providers fail honestly
(`DOMAIN_PROVIDER_NOT_CONFIGURED`, `DOMAIN_LOOKUP_UNAVAILABLE`,
`PROVIDER_OPERATION_UNSUPPORTED`) instead of inventing data. Transfers carry a
9-state machine with the EPP code sealed at rest; auto-renewal sends
configurable notices (default 30/14/7/3/1 days) and creates invoices only
through the existing WHMCS gateway. Every lifecycle event lands in
`mod_chs_domain_events` + `audit_log` with correlation IDs and redacted
payloads. Full operator runbook: `docs/DOMAIN_SERVICES.md`.

## Testing

The suite (`tests/*.php`) boots against in-memory SQLite with the *same
migrations that ship to production* and asserts calls against the recording
`FakeGateway`. Run from the module directory:

```
node tests/run.mjs        # all suites (php-wasm runtime), 1062 assertions
node tests/run.mjs Search  # only suites whose file name matches
node tests/lint.mjs       # full-module syntax gate
```

No test touches the network, the real filesystem, or a real payment provider.

## Docs

- `docs/prd/` — product contracts for this suite (03_PRD_CLOUDHOST247.md)
- `docs/database/schema.md` — DDL conventions per project standards
- `docs/OPERATIONS.md` — cron schedule, provider configuration, env secrets
  (`CHS_VALUATION_API_KEY`, `CHS_AI_API_KEY`, `CHS_IP_HASH_SALT`)
- `docs/DOMAIN_SERVICES.md` — domain platform runbook: provider setup,
  `CHS_CREDENTIALS_KEY`, job operations, honest-failure states, routes
