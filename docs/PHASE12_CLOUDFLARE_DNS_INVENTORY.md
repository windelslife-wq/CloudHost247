# Phase 12 — gated Cloudflare DNS inventory service

- **Status:** implemented and tested offline only
- **Cloudflare addon version:** `1.0.2` (patch)
- **App Cloud version:** unchanged at `1.9.0`
- **Live Cloudflare calls:** none
- **Staging/production enablement:** none

Phase 12 adds a bounded, read-only DNS inventory service inside the existing Cloudflare WHMCS addon. It does not register Cloudflare as an App Cloud infrastructure adapter or expose a new customer-facing route.

## Boundary and safeguards

- `DnsInventoryService::listForCustomer($customerId, $serviceId)` first resolves the service through `ServiceRepository::forCustomer`, which checks the internal service row against the live WHMCS owner. The returned service/customer identifiers are checked again before proceeding.
- Existing `Features::require($service, 'dns.manage')` enforces the DNS entitlement, service state, linked WHMCS service state and active parent state.
- Both the Cloudflare addon master switch and the independent `dns_inventory_adapter_enabled` setting must be enabled. The adapter setting has a blank/false default and fails closed if the WHMCS addon-settings table cannot be read. No environment override enables this adapter.
- The service's zone name must match the linked WHMCS hosting/addon-parent domain. Its stored zone ID must be a Cloudflare-shaped ID. Before listing records, the adapter reads the zone and requires the returned ID, normalized name and Cloudflare account ID to match; it rejects paused or unsupported zone states.
- Every DNS record is validated as a complete response list: supported type, 32-hex provider ID, unique ID, valid owner name inside the linked zone, bounded content, type-appropriate A/AAAA/target/CAA/SRV syntax, valid TTL, proxy flag and priority. The service returns an explicit field projection and does not persist the inventory or update the addon's DNS cache.
- Successful audit events contain only the record count and sorted type totals. Failed reads contain no record metadata. Record names, contents, comments and provider response bodies are not copied into audit details or API logs.
- `CloudflareApi::listZones` and `listDnsRecords` now reject malformed list pages and throw if every page up to the 20-page/50-zone or 50-page/100-record bound is full. They never return a potentially partial inventory at those limits.
- Only GET calls are made: one zone identity read, followed by the existing paginated DNS-record read. No zone, record, DNSSEC, cache, plan, setting or firewall mutation is exposed by this service.

## Explicit exclusions

- No route, API controller, UI action, worker job, adapter registry entry or cross-addon account sharing was added.
- No Cloudflare credentials were supplied to tests; the test suite uses a scripted transport and in-memory SQLite.
- The feature flag is off by default. Setting it does not create a caller or initiate a request; a trusted internal caller must still invoke the service.
- No live call, staging test, production enablement or claim of production readiness is made by this phase.

## Offline validation

`modules/addons/cloudhost247apps/tests/11_CloudflareDnsInventoryTest.php` verifies the default-off behavior, owner and DNS-entitlement checks, linked-domain and provider-zone identity, allowlisted inventory projection, audit redaction, no-mutation request shape, malformed pagination and full-page bounds using only fake transports.

Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`:

- `npm test -- CloudflareDnsInventory` → `TOTAL PASS=38 FAIL=0`
- Full `npm test` → `TOTAL PASS=1487 FAIL=0`
- `npm run lint` → `FILES=90, BAD=0`
- Repository-root `git diff --check` passed.

No live Cloudflare call, staging run, or production enablement was performed.
