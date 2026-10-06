# CloudHost247 Services Suite

WHMCS addon module `cloudhost247services` — the platform-services layer for the
CloudHost247 rollout: domain valuation, domain auctions with anti-sniping and
real invoicing, the Discount Domain Club, a database-driven TLD catalogue,
public WHOIS lookup, expert-service intake with quotes, the Logo Studio, the
AI Website Builder shell, the unified inbox and staff dashboards.

## Architecture (the short version)

- **One addon module** (`cloudhost247services.php`) with lifecycle handlers
  (config/activate/deactivate/upgrade/output/clientarea/sidebar).
- **`lib/Core`** — Clock, Settings (env→override→DB→default layers with
  secret hygiene), Db (mod_chs_ prefix, identifier-validated), Blueprint +
  Migrator (install/migrations are the schema), Csrf, RateLimiter, Audit
  (hashed IP), Money (integer minor units), Identity, DomainName, Validator,
  Str, Exceptions, Logger, Platform.
- **`Platform::gateway()`** is the only seam to WHMCS: production binds
  `WhmcsGateway`, tests bind the recording `FakeGateway` implementing the same
  `GatewayInterface`.
- **`lib/Services`** — one service per feature area; all business rules,
  validation and workflow status machines (`lib/Workflow/*Status.php`) live
  there, never in templates.
- **`lib/Http`** — AdminPortal (admin tab routing .phtml), CustomerPortal +
  Controller (client area routing, Smarty), Landing (public page bootstrap),
  global `chs_*` display helpers.
- **Providers** — Valuation (rules engine + optional external HTTP engine),
  WHOIS (socket → cached), AI (null → HTTP). Every provider reports an honest
  configuration status instead of pretending to work.
- **`install/migrations/*.php`** — MySQL/SQLite-portable through `Blueprint`;
  applied ids recorded in `mod_chs_migrations`, re-running is a no-op.
- **`hooks.php`** — menu/SEO/badge/invoice contributions to WHMCS.
- **`cron/cloudhost247services.php`** — closing/settling auctions etc.; schedule
  every 5 minutes.

## Testing

The suite (`tests/*.php`) boots against in-memory SQLite with the *same
migrations that ship to production* and asserts calls against the recording
`FakeGateway`. Run from the module directory:

```
node tests/run.mjs        # all suites (php-wasm runtime)
node tests/run.mjs Club   # only suites whose file name matches
node tests/lint.mjs       # full-module syntax gate
```

No test touches the network, the real filesystem, or a real payment provider.

## Docs

- `docs/prd/` — product contracts for this suite (03_PRD_CLOUDHOST247.md)
- `docs/database/schema.md` — DDL conventions per project standards
- `docs/operations.md` — cron schedule, provider configuration, env secrets
  (`CHS_VALUATION_API_KEY`, `CHS_AI_API_KEY`, `CHS_IP_HASH_SALT`)
