# CloudHost247 Domain Services / Domain Marketplace — runbook

Operator runbook for the domain platform shipped inside the
`cloudhost247services` addon (v1.1.0, migration `0012_domain_platform.php`).
The platform is an extension of the existing module — there is no second
addon, no second billing system and no duplicate customer system. WHMCS remains
the system of record for clients, carts, orders, invoices, payments and
`tbldomains`; the module links to them and never copies them.

Everything in this document reflects what is wired in code. Where a feature
needs a credential or provider that is not configured, it fails **honestly**
with a machine-readable state — nothing fabricates availability, WHOIS data,
prices, bids, valuations or provider confirmations.

## 1. What customers get

| Surface | Route | Backed by |
|---|---|---|
| Domain Services landing (3-column section: Find a Domain / Domain Investing / Domain Tools & Services) | `domains.php`, `domain.php` | `templates/hostx/chs-domains.tpl` — all 8 items link to real pages |
| Domain search | `domain-search.php?q=…` | `DomainSearchService` → provider registry → WHMCS registrar chain |
| Bulk domain search | `bulk-domain-search.php` (POST `chs_domains` + `_chs_token`; results `?id=`; CSV `?export=<id>`) | `BulkSearchService` (inline ≤ `bulk_sync_threshold`, worker job above) |
| Domain transfer | `domain-transfer.php` (POST `chs_domain` / `chs_epp`) | `TransferService` — eligibility pre-check + quote for guests; tracked transfer + real invoice for clients |
| Client-area search | `index.php?m=cloudhost247services&action=search` (`&format=json` supported) | `DomainSearchService` |
| Client-area bulk | `…&action=bulk`, `…&action=bulkview` (id, page, only_available, `export=csv`) | `BulkSearchService` |
| My domains | `…&action=domains`, `…&action=domain&id=` | `DomainManagementService` (synced from `tbldomains`; IDOR-safe) |
| My transfers | `…&action=transfers`, `…&action=transfer&id=` | `TransferService` |
| WHOIS / auctions / appraisal / club | `whois-lookup.php`, `domain-auctions.php`, `domain-valuation.php`, `discount-domain-club.php` | existing suite services (real, already wired) |

Client-area domain management (`action=domain`, POST `do=`): `auto_renew`,
`privacy`, `nameservers`, `dns_add`, `dns_edit`, `dns_delete`. DNS supports
A/AAAA/CNAME/MX/TXT/NS/SRV/CAA with server-side validation and audit.

## 2. Database (migration 0012)

All tables use the module's `mod_chs_` prefix via `Db::t()`:

| Logical table | Purpose |
|---|---|
| `domain_providers` | Registrar provider configs; credentials column holds AES-256-GCM sealed JSON only |
| `domain_provider_mappings` | TLD → provider overrides (falls back to default provider → WHMCS chain) |
| `domain_services` | Module-side view of a registered domain, linked to WHMCS via `whmcs_domain_id` |
| `domain_transfers` | 9-state transfer machine; EPP code stored sealed, never in plaintext |
| `domain_dns_records` | Customer-managed DNS records with `sync_status` (pending/synced/error) |
| `domain_searches` / `domain_search_results` | Search + bulk-search requests and per-TLD results (rate-limit + audit trail) |
| `jobs` | Worker queue: type, status, attempts/max_attempts, lease, idempotency key, correlation id, entity id, error |
| `domain_renewals` | Auto-renewal execution records (invoice id, provider result, reconciliation state) |
| `domain_events` | Domain lifecycle audit events (see §7) |
| `domain_expiration_notices` | Dedupe ledger for expiration notices (configurable thresholds) |

The migrator records applied ids in `mod_chs_migrations`; re-running is a
no-op. Existing WHMCS tables (`tbldomains`, invoices, etc.) are reused, never
duplicated.

## 3. Credentials & secrets — required before production writes

1. **Set the environment variable before saving any HTTP provider credential:**

   ```
   CHS_CREDENTIALS_KEY=<64 hex chars / 32 random bytes, e.g. output of: php -r "echo bin2hex(random_bytes(32));" >
   ```

   `Chs\Core\Secrets` seals provider credentials (and transfer EPP codes) with
   AES-256-GCM using this key. **If the key is unset, saving credentials fails
   closed** — the admin Providers page refuses rather than storing plaintext.
   Rotate by re-saving credentials under the new key (decrypt-with-old /
   encrypt-with-new happens on next save; keep the old key available until all
   providers are re-saved).
2. Store the key in the server environment (not in the repo, not in the DB).
   The existing suite secrets (`CHS_VALUATION_API_KEY`, `CHS_AI_API_KEY`,
   `CHS_IP_HASH_SALT`) are unaffected.
3. Credentials are **never** returned to the browser: provider list views mask
   them, templates render `••••`, audit payloads redact `password`/`token`/
   `secret`/`epp` keys.

## 4. Configuring a registrar provider

Admin → **Addon Modules → CloudHost247 Services → Domain Providers**
(`addonmodules.php?module=cloudhost247services&action=providers`):

1. **Add provider** (`do=save`): name, type (`http`), base URL, endpoint map
   (JSON: operation → path, e.g. `{"check":"/check","pricing":"/pricing",…}`),
   auth header name/prefix, credential field name, enabled + default flags,
   and the credentials JSON. Credentials are sealed on save.
2. **Health check** (`do=health`): pings the provider through
   `HttpRegistrarProvider` with the sealed credentials.
3. **TLD mapping** (`do=map` / `do=unmap`): route specific TLDs to a provider;
   unmapped TLDs fall back to the default provider, then to the WHMCS
   registrar chain.

Resolution order (`ProviderRegistry::resolve`): TLD mapping → default
provider → `WhmcsRegistrarProvider` → `NullDomainProvider` (which fails with
`DOMAIN_PROVIDER_NOT_CONFIGURED`).

### Provider behaviour today

- **`WhmcsRegistrarProvider` (default):** availability, WHOIS and pricing are
  real (delegated to the WHMCS registrar chain / `checkAvailability`).
  Write operations (`registerDomain`, `transferDomain`, `renewDomain`,
  nameserver/DNS writes) throw `PROVIDER_OPERATION_UNSUPPORTED` — honestly,
  and the failure stays visible in **Operations (Jobs)** for retry once a real
  provider is configured.
- **`HttpRegistrarProvider`:** full read/write over a generic JSON registrar
  API (curl, configurable endpoint map, timeout from
  `provider_http_timeout_seconds`, default 15s).
- **`NullDomainProvider`:** every call fails with
  `DOMAIN_PROVIDER_NOT_CONFIGURED`.

## 5. Jobs & cron

Schedule (already required for auctions/club) every 5 minutes:

```
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php
```

The cron enqueues the periodic domain jobs with **per-5-minute-window
idempotency keys** (re-runs never duplicate), then drains the queue:

| Job type | What it does | Enqueued by |
|---|---|---|
| `DOMAIN_EXPIRATION_CHECK` | Scans upcoming expirations, sends notices at the configured thresholds, creates auto-renew invoices | cron |
| `DOMAIN_PROVIDER_SYNC` | Reconciles module domain state from `tbldomains` | cron |
| `DOMAIN_RECONCILIATION` | Settles transfers/renewals against platform truth (invoice paid + `tbldomains` state) | cron |
| `DOMAIN_AVAILABILITY_CHECK` | Single availability check | on demand |
| `DOMAIN_BULK_SEARCH` | Large bulk searches (above `bulk_sync_threshold`) | `BulkSearchService` |
| `DOMAIN_REGISTRATION` | Post-payment registration (invoice-paid verified before any provider call) | `InvoicePaid` hook |
| `DOMAIN_TRANSFER` | Transfer submission to the provider | `InvoicePaid` hook / admin retry |
| `DOMAIN_RENEWAL` | Auto-renewal execution after invoice payment | `InvoicePaid` hook |
| `DOMAIN_DNS_SYNC` | Re-push pending/error DNS rows to the provider | management service |
| `DOMAIN_WHOIS_LOOKUP` | Cached WHOIS refresh | on demand |
| `DOMAIN_AUCTION_CLOSE` / `DOMAIN_AUCTION_PAYMENT_CHECK` | Auction lifecycle | existing auction heartbeat |
| `DOMAIN_NOTIFICATION` | Domain lifecycle notifications via the existing notification system | services |

Queue mechanics (`Chs\Workflow\JobQueue`): idempotency keys, lease-based claims
that **recover crashed workers** (a `running` job whose lease expired is
reclaimable), bounded retries with quadratic backoff (`jobs_max_attempts`,
default 5), admin retry, retention purge (`jobs_retention_days`, default 30).

### Admin Operations (Jobs) page

`addonmodules.php?module=cloudhost247services&action=operations` — live queue
view with `do=retry` (re-queue a failed job), `do=run` (drain now),
`do=enqueue_expiration`, `do=enqueue_sync`. Failed jobs keep their last error
and machine code; nothing is silently dropped.

## 6. Module settings (domain platform)

Admin → CloudHost247 Services → **Settings** (all stored in the module
settings, editable in the panel — nothing is hard-coded):

| Setting | Default | Meaning |
|---|---|---|
| `domain_search_enabled` | on | Master switch for search |
| `search_daily_limit_per_client` / `_per_ip` | 100 / 30 | Rate limits |
| `bulk_search_enabled` | on | Master switch for bulk search |
| `bulk_max_domains` | 500 | Max names per bulk request |
| `bulk_daily_limit_per_client` / `_per_ip` | 10 / 3 | Rate limits |
| `bulk_chunk_size` / `bulk_sync_threshold` | 25 / 10 | Chunk size; inline vs worker threshold |
| `transfer_enabled` | on | Master switch for transfers |
| `transfer_invoice_due_days` | 7 | Due window for transfer invoices |
| `domains_dashboard_enabled` | on | Client-area My Domains |
| `domain_dns_enabled` | on | Client-area DNS management |
| `domain_renewal_notice_days` | `30,14,7,3,1` | Expiration notice thresholds (days before expiry) |
| `domain_auto_renew_lead_days` | 7 | Auto-renew attempt lead time |
| `domain_sync_enabled` | on | Platform sync job |
| `jobs_per_run` / `jobs_lease_seconds` / `jobs_max_attempts` / `jobs_retention_days` | 25 / 300 / 5 / 30 | Queue tuning |
| `provider_http_timeout_seconds` | 15 | HTTP provider timeout |

## 7. Audit & events

`Chs\Services\DomainEventService` records every lifecycle event to
`mod_chs_domain_events` **and** WHMCS `audit_log`, with actor, type, action,
entity, timestamp, hashed IP, user agent, correlation id and sanitized
metadata (EPP codes, passwords, tokens and secrets are redacted).

Event names: `DOMAIN_SEARCHED`, `DOMAIN_REGISTERED`,
`DOMAIN_REGISTRATION_FAILED`, `DOMAIN_TRANSFER_STARTED`,
`DOMAIN_TRANSFER_COMPLETED`, `DOMAIN_TRANSFER_FAILED`, `DOMAIN_RENEWED`,
`DOMAIN_RENEWAL_FAILED`, `DOMAIN_DNS_UPDATED`, `DOMAIN_AUTO_RENEW_CHANGED`,
`DOMAIN_AUCTION_CREATED`, `DOMAIN_BID_PLACED`, `DOMAIN_AUCTION_CLOSED`,
`DOMAIN_APPRAISAL_REQUESTED`, `DOMAIN_CLUB_SUBSCRIBED`,
`DOMAIN_CLUB_CANCELLED`, `DOMAIN_ADMIN_UPDATED`, `DOMAIN_EXPIRING`,
`DOMAIN_EXPIRED`.

## 8. Honest-failure contract (no fake data)

When a capability is not configured, the UI/API surfaces these machine states
instead of invented data:

| State | Meaning |
|---|---|
| `DOMAIN_PROVIDER_NOT_CONFIGURED` | No provider can serve this TLD |
| `DOMAIN_LOOKUP_UNAVAILABLE` | Availability/WHOIS lookup could not run |
| `PROVIDER_OPERATION_UNSUPPORTED` | Provider is read-only (e.g. default WHMCS chain) for this write |
| `unsupported_tld` | TLD not in the catalogue / not mapped |
| availability `unknown` | Lookup inconclusive — **never** reported as "taken" |

Transfers expose `epp_status = "on file (hidden)"`; the EPP code itself is
sealed at rest and never appears in history, audit or view models.

## 9. Admin surfaces

- **Domains** (`action=domains`) — module domain list synced from
  `tbldomains`; `do=sync` (platform sync), `do=dns_sync` (re-push DNS).
- **Domain Providers** (`action=providers`) — §4.
- **Transfers** (`action=transfers`) — all transfers with state machine;
  `do=retry`, `do=submitted` (with provider ref), `do=completed`, `do=sync`
  (platform reconciliation).
- **Operations (Jobs)** (`action=operations`) — §5.
- **Overview** — domain-platform KPIs (searches, transfers by state, jobs
  pending/failed, renewals due).

## 10. Verification gates

From `modules/addons/cloudhost247services/`:

```bash
node tests/run.mjs    # 1062 assertions, 21 suites (14–21 cover the domain platform)
node tests/lint.mjs   # module PHP parse gate: BAD=0 LINT_OK
```

Suite 21 additionally parse-checks every admin `.phtml` view and the root
domain landing pages.

## 11. Known limitations

- With only the default WHMCS provider configured, availability/WHOIS/pricing
  are real, but register/transfer/renew/DNS writes fail honestly
  (`PROVIDER_OPERATION_UNSUPPORTED`) and wait in Operations for retry after a
  write-capable provider is added.
- The bulk-search "add selected to cart" posts `bulkdomains[]` checkboxes to
  `cart.php?a=add&domain=register&bulk=1`; per-domain Add links
  (`cart.php?a=add&domain=register&query=…`) are the guaranteed-real fallback
  and are always rendered alongside.
- `CHS_CREDENTIALS_KEY` must exist before the first HTTP provider credential
  is saved; the save fails closed otherwise (by design).
- Auctions, appraisal and the Discount Club are the pre-existing suite
  services — real and already wired; the domain platform links to them from
  the Domain Services section.
