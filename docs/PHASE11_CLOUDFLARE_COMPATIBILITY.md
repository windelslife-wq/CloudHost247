# Phase 11 — Cloudflare API compatibility seam

- **Status:** offline compatibility work only; not a completed App Cloud provider integration
- **Cloudflare addon version:** `1.0.1` (patch)
- **App Cloud version:** unchanged at `1.9.0`
- **External calls:** none; all test requests use a scripted fake transport

The roadmap identifies the existing Cloudflare addon as the first integration to reuse. Phase 11 makes its existing transport-injection seam work end-to-end and adds offline contract coverage before any control-plane wiring is attempted.

## Scope and safety

- `AccountRepository` now passes its optional `TransportInterface` to the existing `CloudflareClient`. Production callers that omit a transport still use the existing cURL transport with HTTPS/TLS verification.
- The App Cloud test harness mounts the existing Cloudflare addon for an offline compatibility suite. It exercises encrypted test credentials, the existing token/account/zone verification path, read-only zone and DNS-record reads, and normalized authentication failures through a scripted transport and in-memory SQLite.
- The tested calls are GET-only. The test suite does not call Cloudflare, create or change zones/records, use real credentials, or alter customer records.
- The Cloudflare addon remains a separate WHMCS integration. This phase does not register an `InfrastructureProviderInterface` adapter (Cloudflare is not a compute provider), create a DNS-provider registry, add an App Cloud route/worker, share or migrate account credentials, or claim Cloudflare is integrated into App Cloud.
- No database migration, UI, feature flag, new provider operation, or production enablement was added.

## Offline validation

The compatibility tests verify the existing Cloudflare API paths and safe error projection using only a fake transport. The App Cloud test/lint runners load the Cloudflare module classes but do not start its cURL transport. Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`: `npm test` → `TOTAL PASS=1449 FAIL=0`; `npm run lint` → `FILES=90, BAD=0`; repository-root `git diff --check` passed.

No live Cloudflare call, staging run, production review, or feature enablement was performed in Phase 11. Phase 12 subsequently adds an internal, default-off, read-only DNS inventory service with owner/entitlement checks and summary-only audit. Phase 13 adds a separate App Cloud bridge, but the DNS registry remains empty unless explicitly registered and no customer route was added. See [`PHASE12_CLOUDFLARE_DNS_INVENTORY.md`](PHASE12_CLOUDFLARE_DNS_INVENTORY.md) and [`PHASE13_CLOUDFLARE_DNS_BRIDGE.md`](PHASE13_CLOUDFLARE_DNS_BRIDGE.md). Staging approval is still required before any live invocation.
