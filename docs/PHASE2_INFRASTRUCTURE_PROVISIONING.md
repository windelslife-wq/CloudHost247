# Phase 2 — WHMCS boundary and provider/server lifecycle slice

**Scope:** Phase 2 only. This adds the WHMCS addon/API boundary, provider-account and customer-server schema, RBAC, encrypted credentials, queue types, and explicit worker dispatch. It does not begin panel catalog/marketplace work.

> **External gate update — 2026-10-08:** The project owner has confirmed that Phase 2's external WHMCS/MySQL and provider-validation gate passed. The live test report and environment details are not present in this repository. Since then this checkout ships a production Hetzner Cloud adapter (`lib/Infrastructure/Providers/HetznerCloudAdapter.php`, registered by `providers/bootstrap.php`), which is code-complete and contract-tested against a scripted fake of the Hetzner API — but no live Hetzner verification has happened here, so the customer-VM provisioning switch remains off and this source tree does not claim live provider capability. Phase 4 cPanel adapter and existing-account workflow are documented separately in [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md) and [`PHASE4_CPANEL_WORKFLOW.md`](PHASE4_CPANEL_WORKFLOW.md); the cPanel staging procedure is in [`PHASE4_CPANEL_STAGING.md`](PHASE4_CPANEL_STAGING.md).

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
- Lifecycle history, stable error codes, operation idempotency, per-customer-server job serialization, and sanitized API presentations. A provider-ready VM is recorded as `provisioning`/`server_ready`; it becomes customer-visible `active` only after the readiness gates below pass.

- MySQL DDL for module foreign keys uses unsigned `BIGINT` to match `Blueprint::id()`. Migration `0009_align_unsigned_foreign_key_types` is an additive, restartable repair for already-created legacy tables; it checks both column types and refuses missing schema or negative/orphaned references rather than coercing data. The offline suite renders and audits the actual MySQL DDL, but does not replace staging verification on the target MySQL/InnoDB version.

## Explicit current limitation

The checkout ships **three offline-contract-tested create-capable adapters (Hetzner, DigitalOcean, Vultr) and one read-only Contabo adoption adapter** (`HetznerCloudAdapter`, `DigitalOceanAdapter`, `VultrAdapter`, `ContaboReadOnlyAdapter`), registered by `providers/bootstrap.php` and loaded by the API, the WHMCS readiness page, and the worker through `ProviderBootstrap::boot()`. OVHcloud and AWS remain catalog-only. Contabo advertises only `server.get` for its separate operator-only, default-off existing-instance adoption flow; it does not create, cancel or activate customer VMs (see [`CONTABO_ADAPTER_READINESS.md`](CONTABO_ADAPTER_READINESS.md)). Hetzner uses suite 15; DigitalOcean uses suite 18; Vultr uses suite 19. The DigitalOcean and Vultr slices advertise only create/read/delete/power/reboot. None has live provider verification in this checkout. See [`DIGITALOCEAN_ADAPTER_READINESS.md`](DIGITALOCEAN_ADAPTER_READINESS.md) and [`VULTR_ADAPTER_READINESS.md`](VULTR_ADAPTER_READINESS.md) for their ambiguous-create/manual-reconciliation gates. Consequently:

- Catalog providers without an adapter fail clearly with `PROVIDER_UNAVAILABLE`; the readiness page discloses installed adapters without claiming live verification.
- The server-provisioning feature is disabled by default; enabling it still requires an encrypted, verified provider account and passed staging checks.
- Customer-facing VM reads are scoped to the owner. The later, separately gated existing-paid-service self-service create path uses an operator-approved WHMCS product → provider-account/spec mapping; customers cannot choose the operator provider account or size (see [`SELF_SERVICE_VPS.md`](SELF_SERVICE_VPS.md)). Other lifecycle writes remain capability- and RBAC-gated.
- No fake API, default provider, test fake, synthetic IP, or simulated provisioning success is shipped in production code. Hetzner has no idempotency-key header, so the adapter recovers create/delete idempotency through a deterministic server label; size selection reads the provider's own `GET /server_types` catalog and fails closed when no type exactly matches the requested cpu/memory/disk. Hetzner exposes no per-server metrics endpoint, so `server.metrics` is not advertised.
- Test-only fake adapters validate queueing/dispatch/state behavior but are not part of the provider catalog at runtime.

A production provider adapter must use the provider's real API, validate TLS and responses, advertise only implemented capabilities, provide safe replay/reconciliation for create/delete operations (never blindly retry an ambiguous billable create), avoid credentials in logs/errors, and return normalized provider resource IDs/states. Register it in `modules/addons/cloudhost247apps/providers/bootstrap.php`; the same reviewed bootstrap is loaded by the API, WHMCS readiness page, and worker before provisioning is enabled. Do not place secrets in that file.

## Readiness gates — `server_ready` to `active`

`ServerProvisioningWorker` runs explicit health/security gates after the provider reports a VM ready (create, rebuild, or resize) and before `CustomerServerService::workerActivate()` marks it customer-visible `active`. Every gate input is provider-sourced or already persisted on the server row; nothing is guessed or synthesized, and a failed gate never activates.

- **Health — address:** the provider-confirmed resource must have an IPv4 or IPv6 address. A ready state without an address fails the gate transiently (`HEALTH_CHECK_FAILED`): the server stays `provisioning`/`server_ready`, the machine-readable error is recorded, and polling continues.
- **Health — metrics:** when the adapter declares `server.metrics`, the worker requires a successful, non-empty provider-sourced metrics response. A failing metrics endpoint is a transient `HEALTH_CHECK_FAILED` (polling continues). Adapters without the capability are recorded as `adapter_declares_no_server_metrics` in the gate report — unsupported is recorded honestly, never faked.
- **Security — deployed-spec integrity:** the persisted server row must still match the approved `requested_spec` exactly (name, hostname, region, image, CPU, memory, storage). Drift or a missing spec is terminal (`SPEC_DRIFT` / `SPEC_MISSING`): the server is failed honestly and never activated. Guest-OS hardening remains the guest's responsibility; the control plane does not claim to verify it.

Transient gate failures keep the server `provisioning` and schedule the next poll (bounded by `provider_operation_poll_limit`), exactly like a slow provider. The gate report is persisted in the `server_activated` lifecycle event. Activation is worker-only (`workerActivate`), idempotent, and guarded to the `provisioning`/`server_ready` milestone; a duplicate poll never demotes an already-activated server. Rebuild and resize re-run the gates against the newly approved spec before re-activating.

## Configuration and worker

1. Activate or upgrade the **CloudHost247 App Cloud** addon in WHMCS. Migration `0007_create_provider_server_tables` is additive and preserves existing data.
2. Set `CH247APPS_ENCRYPTION_KEY` in the PHP/worker environment (at least 16 characters; generate a high-entropy secret, store it outside the repository). Do not place it in addon fields or source control.
3. Hetzner Cloud, DigitalOcean, Vultr and read-only Contabo adoption adapters ship registered in `providers/bootstrap.php`; additional real adapters are installed the same way. Create a provider account through the authenticated API, store credentials in the encrypted vault, then queue verification. Only a successful adapter verification changes an account to `active`.
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
| Real provider adapters | **Hetzner + narrow DigitalOcean/Vultr slices; live verification absent** | The three create-capable adapters are registered and contract-tested with offline HTTP fakes (suites 15, 18 and 19); these use verification → create → poll → readiness gates → `active`. Contabo suite 20 separately covers OAuth/read-back and staff-only adoption, with no purchase or customer activation. No live credential verification or lifecycle call for these providers has happened in this checkout; the owner's external confirmation does not establish these new adapters' live readiness. OVHcloud and AWS remain catalog-only. |
| Live provider account verification and lifecycle calls | **Owner-confirmed externally; evidence not in repo** | The test report/staging environment is external. This checkout cannot reproduce verify, create/idempotent retry, async poll, resize/rebuild, action, or delete/absence confirmation. |
| Production database migration and WHMCS billing/service integration | **Owner-confirmed externally; evidence not in repo** | This checkout's offline suite still cannot prove the target WHMCS/MySQL release or staging billing/service cases. |
| Provisioning release switch | **Off** | Keep `customer_server_provisioning_enabled` and `customer_server_self_service_enabled` disabled: registered offline-tested adapters and an operator mapping are not live provider/billing validation. |

The offline test harness uses PHP 8.3 WebAssembly and in-memory SQLite with the production migrations. It cannot prove a live WHMCS/MySQL upgrade, a production provider API, real provider credentials, or operational provisioning. The project owner confirmed the external Phase 2 gate passed on 2026-10-08, but the evidence is not present in this repository. The checkout now ships offline-tested Hetzner, DigitalOcean and Vultr create-capable adapters plus separate read-only Contabo adoption, yet no live provider verification exists here and the provisioning switch stays off. The earlier waiver applied only to Phase 3; the owner's new confirmation is the basis for proceeding with Phase 4. Neither statement makes this checkout operational. See [`PHASE3_PANEL_CATALOG.md`](PHASE3_PANEL_CATALOG.md), [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md), [`PHASE4_CPANEL_WORKFLOW.md`](PHASE4_CPANEL_WORKFLOW.md), and [`PHASE4_CPANEL_STAGING.md`](PHASE4_CPANEL_STAGING.md).
