# Phase 2 — WHMCS boundary and provider/server lifecycle slice

**Scope:** Phase 2 only. This adds the WHMCS addon/API boundary, provider-account and customer-server schema, RBAC, encrypted credentials, queue types, and explicit worker dispatch. It does not begin panel catalog/marketplace work.

> **External gate update — 2026-10-08:** The project owner has confirmed that Phase 2's external WHMCS/MySQL and provider-validation gate passed. The live test report and environment details are not present in this repository. The checked-out code still has no production provider adapter or `providers/bootstrap.php`, so the customer-VM provisioning switch remains off and this source tree does not claim live provider capability. Phase 4 cPanel work is documented separately in [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md).

## Authority and model boundaries

- WHMCS remains authoritative for clients, products, orders, hosting services, invoices, and invoice payment state.
- `mod_ch247apps_servers` remains the App Cloud **deployment-target/agent** registry. It is not a customer VPS table.
- `mod_ch247apps_customer_servers` is the separate **customer-owned provider VM** model, uniquely bound to one WHMCS hosting service. Its invoice is derived from that service's WHMCS order; callers cannot submit an unrelated paid invoice.
- Customer VM jobs use `customer_server_id`, `provider_account_id`, and `whmcs_service_id`. They do not overload `jobs.server_id`, which continues to mean an App Cloud deployment target.

## What is implemented

- API JSON bodies are decoded as nested PHP arrays so object-valued specs and credentials reach their validators correctly. Provider credential values are replaced by a domain-separated HMAC before the idempotency request digest is persisted; retries still match without storing an unkeyed password/token verifier.
- WHMCS addon lifecycle (`cloudhost247apps.php`): additive migrations, RBAC seeding that preserves operator grants, non-destructive deactivation, and an admin readiness page.
- REST boundary at `modules/addons/cloudhost247apps/api/index.php`, with WHMCS session or bearer-token authentication, role and token-scope checks, CSRF on cookie-authenticated writes, no-store/security headers, and idempotency requirements.
- Provider registry contract for real adapters, an informational provider catalog, encrypted provider-account credentials using `Crypto::seal()` context-bound to the account ID, credential rotation, and worker-queued credential verification.
- Customer-server requests validate the WHMCS service → order → invoice relationship, require explicit matching client-owner IDs on the service/order/invoice records, and require WHMCS to confirm the linked invoice is paid before creating a local row or queue job. The worker rechecks the current service/order/invoice linkage and paid state immediately before provider create.
- Customer VM reads and job lookups check the live WHMCS service owner against the stored customer binding. If a service is transferred and the local binding is stale, customer access fails closed until an operator reconciles it.
- A distinct `provisioning` queue and worker dispatch path for provider account verification and customer-server create/poll/reboot/power/rebuild/resize/delete work. Long-running operations are not executed in HTTP requests; asynchronous provider operations are polled by later leased jobs. A queued create also rechecks the provisioning kill switch before making a provider call. Ongoing lifecycle jobs recheck WHMCS service state; suspended/cancelled services cannot be powered on, rebooted, rebuilt or resized, while `power_off` and eligible deletion remain available for cleanup.
- Lifecycle history, stable error codes, operation idempotency, per-customer-server job serialization, and sanitized API presentations. A provider-ready VM remains `provisioning`/`server_ready`; this phase never marks it `active` without later health/security gates.

- MySQL DDL for module foreign keys uses unsigned `BIGINT` to match `Blueprint::id()`. Migration `0009_align_unsigned_foreign_key_types` is an additive, restartable repair for already-created legacy tables; it checks both column types and refuses missing schema or negative/orphaned references rather than coercing data. The offline suite renders and audits the actual MySQL DDL, but does not replace staging verification on the target MySQL/InnoDB version.

## Explicit current limitation

There are **no real infrastructure-provider adapters registered in this checkout**. The registry lists OVHcloud, Hetzner, AWS, DigitalOcean, Vultr, and Contabo as catalog entries only; they all report `adapter_available: false` until a real implementation is registered. Consequently:

- Provider credential verification and provisioning fail clearly with `PROVIDER_UNAVAILABLE` when no adapter exists.
- The server-provisioning feature is disabled by default.
- Customer-facing VM reads are scoped to the owner, but create/lifecycle writes are staff-RBAC-only for now. Self-service ordering must wait for an explicit WHMCS product → provider-account/profile mapping and entitlement limits; customers cannot choose an operator provider account ID.
- No fake API, default provider, test fake, synthetic IP, or simulated provisioning success is shipped in production code.
- Test-only fake adapters validate queueing/dispatch/state behavior but are not part of the provider catalog at runtime.

A production provider adapter must use the provider's real API, validate TLS and responses, advertise only implemented capabilities, ensure create/delete operations are idempotent for the supplied key, avoid credentials in logs/errors, and return normalized provider resource IDs/states. Register it in `modules/addons/cloudhost247apps/providers/bootstrap.php`; the same reviewed bootstrap is loaded by the API, WHMCS readiness page, and worker before provisioning is enabled. Do not place secrets in that file.

## Configuration and worker

1. Activate or upgrade the **CloudHost247 App Cloud** addon in WHMCS. Migration `0007_create_provider_server_tables` is additive and preserves existing data.
2. Set `CH247APPS_ENCRYPTION_KEY` in the PHP/worker environment (at least 16 characters; generate a high-entropy secret, store it outside the repository). Do not place it in addon fields or source control.
3. Install/register a real provider adapter. Create a provider account through the authenticated API, store credentials in the encrypted vault, then queue verification. Only a successful adapter verification changes an account to `active`.
4. Keep `customer_server_provisioning_enabled` off until adapter integration and staging checks pass. Enable it through WHMCS addon settings only after the above is complete.
5. Run the separate queue consumer under a supervisor, for example:

   ```sh
   php -q modules/addons/cloudhost247apps/worker/worker.php --queue=provisioning --batch=2 --runtime=300
   ```

   The existing deployment queue remains `--queue=deployment`. A customer server job is never sent to `Deployments\Orchestrator` merely because it has an integer server reference.

## API outline

Base path:

```text
/modules/addons/cloudhost247apps/api/index.php?path=/v1
```

| Method and path | Purpose |
|---|---|
| `GET /v1/providers` | Provider catalog and adapter/capability availability; no secrets. |
| `GET, POST /v1/provider-accounts` | List sanitized accounts or create an unverified account. |
| `PUT /v1/provider-accounts/{id}/credentials` | Rotate encrypted credentials; requires super-admin permission. |
| `POST /v1/provider-accounts/{id}/verify` | Queue provider verification; it does not contact the provider in the request. |
| `POST /v1/provider-accounts/{id}/suspend` | Suspend account use without deleting credentials or VMs. |
| `GET, POST /v1/servers` | List visible customer VMs or queue a paid-WHMCS-service provisioning request (admin role). |
| `GET /v1/servers/{id}` | Read an authorized customer's VM. |
| `POST /v1/servers/{id}/actions` | Queue a supported lifecycle action (admin role); deletion requires `confirm: true`. |
| `GET /v1/jobs/{id}` | Read authorized queue/job state. |

All mutating calls require an `Idempotency-Key`; cookie-authenticated writes also require `X-CSRF-Token` (or the WHMCS form token). The API never accepts provider credentials in server job payloads, and never returns ciphertext or plaintext credentials. VM deletion is blocked unless the linked WHMCS service is already cancelled/terminated; the worker re-checks that state immediately before calling the provider. If provisioning is switched off after a create is queued, the worker fails it before any provider call. Teardown and account-verification jobs remain available so disabling new creates does not prevent cleanup.

## Phase 2 exit gate — current status

| Gate | Status | Evidence / remaining work |
|---|---|---|
| WHMCS addon boundary, additive activation/upgrade, safe deactivation | Code-tested | Harness covers callbacks and data preservation; still run against the target WHMCS release and MySQL version. |
| Separate customer VM model, RBAC API, encrypted credentials, idempotency and worker routing | Code-tested | Offline suites cover auth/scopes, credential secrecy, queue isolation, lifecycle transitions, WHMCS status/ownership rechecks and fail-closed cases. |
| Real provider adapter | **Absent from this checkout** | The owner reports the external Phase 2 gate passed, but no production adapter or `providers/bootstrap.php` is present here. Do not infer source-level capability from the external confirmation. |
| Live provider account verification and lifecycle calls | **Owner-confirmed externally; evidence not in repo** | The test report/staging environment is external. This checkout cannot reproduce verify, create/idempotent retry, async poll, resize/rebuild, action, or delete/absence confirmation. |
| Production database migration and WHMCS billing/service integration | **Owner-confirmed externally; evidence not in repo** | This checkout's offline suite still cannot prove the target WHMCS/MySQL release or staging billing/service cases. |
| Provisioning release switch | **Off** | Keep `customer_server_provisioning_enabled` disabled because this checkout has no registered provider adapter or reviewed product/provider mapping, even though the owner reports the external gate passed. |

The offline test harness uses PHP 8.3 WebAssembly and in-memory SQLite with the production migrations. It cannot prove a live WHMCS/MySQL upgrade, a production provider API, real provider credentials, or operational provisioning. The project owner confirmed the external Phase 2 gate passed on 2026-10-08, but the evidence is not present in this repository and the checked-out provider registry still has no production adapter. The earlier waiver applied only to Phase 3; the owner's new confirmation is the basis for proceeding with Phase 4. Neither statement makes this checkout operational. See [`PHASE3_PANEL_CATALOG.md`](PHASE3_PANEL_CATALOG.md) and [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md).
