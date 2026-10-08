# Phase 4 — cPanel & WHM adapter (initial implementation slice)

**Status:** the separate adapter contract, registry, and WHM API 1 account-lifecycle implementation are present. This is not yet a customer-facing or production-enabled cPanel workflow.
**Target:** cPanel & WHM, selected for the first Phase 4 adapter.
**Release:** WHMCS addon version `1.2.0`; upgrade reruns idempotent RBAC seeding for the new panel-account permissions.
**Phase 2 gate:** the project owner confirmed the external Phase 2 validation passed on 2026-10-08. The WHMCS/MySQL/provider evidence is external and is not included in this checkout; the code still contains no infrastructure-provider adapter, so customer-VM provisioning remains disabled.

## Boundary

`Ch247Apps\ControlPanels\ControlPanelAdapterInterface` and `ControlPanelAdapterRegistry` are separate from the application deployment `Ch247Apps\Adapters\AdapterInterface` and `AdapterFactory`. The cPanel control-panel adapter manages WHM accounts; it does not deploy applications into those accounts. The older application cPanel engine remains unavailable because its application adapter has not been implemented.

The built-in `cpanel_whm` code registration means only that the implementation exists in source. It does not claim that a cPanel server is registered, a token is valid, the account is licensed, or a customer operation is enabled. The Phase 3 catalog continues to permit only non-operational metadata status values and keeps panels/plans non-deployable and non-orderable.

## Implemented WHM operations

`CpanelWhmAdapter` currently implements only these WHM API 1 operations:

- `account.verify` — authenticated version probe; reports success only after a valid WHM result and vendor-returned version are present.
- `account.get` — a username-scoped `listaccts` read, normalized to an allowlisted account projection.
- `account.create` — validates username, domain, package, password, and optional contact email; reconciles retries by the WHM username and confirms domain/package with a subsequent read.
- `account.suspend` / `account.unsuspend` — checks current state, performs the WHM mutation, and reads the state back before confirming completion.
- `account.terminate` — requires `TERMINATE <username>`, issues account removal, and confirms the account is absent before reporting completion.

The WHM API transport uses the registered server hostname, fixed HTTPS port 2087, an encrypted-vault token supplied by trusted worker code, mandatory TLS verification, a fixed endpoint allowlist, and no credential-bearing URL parameters. `ControlPanelConnectionFactory` resolves the host from the existing server registry and reveals only an active `whm_api_token` from `CredentialVault`; both resolution and API calls require worker/CLI context. The worker-only `ControlPanelService` applies panel-account RBAC, records a write-ahead audit event before mutations, and records confirmed/failed outcomes. Request options redact the WHM token and account password; cPanel response bodies are redacted from the shared HTTP diagnostic recorder. Errors do not include response bodies or credentials.

## Explicitly not implemented

- No cPanel software installer, license activation/renewal service, login/SSO flow, or customer credential-delivery workflow.
- No UAPI operation is registered yet; domain, email, database, SSL, and WordPress capabilities are not advertised.
- No WHMCS service-to-panel-account mapping, order/entitlement gate, panel-account table, public API route, admin action, or panel-account worker job is added in this slice. Mutating adapter calls are worker-only and must not be made from an HTTP handler.
- The low-level `createAccount` method requires a password from trusted caller code and intentionally does not return or persist it. Do not wire it to a queued or customer-facing workflow until account-secret storage/delivery (or a reviewed SSO flow) is designed. The cPanel API has no idempotency-key parameter; the adapter uses username/domain/package reconciliation and refuses mismatched collisions.
- Offline transport fakes test response parsing and state reconciliation only. No live WHM server or credential was contacted, so no production cPanel integration is claimed.

## Release gates for an operational cPanel workflow

1. Register a cPanel server through the existing App Cloud server registry and store its `whm_api_token` in the existing encrypted `CredentialVault`, including the WHM username. Worker code must resolve host and credentials from those trusted records; it must never accept a host/token in a job or client payload.
2. Add a WHMCS-service-bound account model and a queued worker/service path. Re-check WHMCS ownership, service state, and payment/entitlement immediately before account creation or destructive lifecycle calls; keep customer actions disabled until those gates exist.
3. Design the password/SSO handoff before enabling creation. The password must not be placed in generic queue payloads, audit metadata, logs, or customer-facing job results.
4. Validate permissions and TLS against a dedicated cPanel staging host, including verify, list, create/retry, suspend, unsuspend, and terminate/absence. Keep test credentials outside source control and preserve a reversible recovery plan before testing destructive operations.
5. Add only UAPI methods with confirmed endpoint contracts and scoped credentials; leave unsupported operations absent from the registry capability map.

## Validation

Run from `modules/addons/cloudhost247apps/`:

```sh
npm test
npm run lint
```

The new offline suite is `tests/08_ControlPanelAdapterTest.php`. It uses the shared HTTP fake and asserts response verification, TLS and credential handling, no false success, safe state read-back, write-ahead auditing, and the separation from application deployment. Latest local validation: `npm test` → `TOTAL PASS=1107 FAIL=0`; `npm run lint` → `FILES=86 BAD=0`; `git diff --check` passed. These offline results do not replace real cPanel staging validation.
