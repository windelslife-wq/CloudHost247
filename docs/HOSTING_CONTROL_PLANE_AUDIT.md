# CloudHost247 Hosting Control Plane — Phase 1 Architecture Audit

- **Audit date:** 2026-10-07 (UTC)
- **Repository baseline:** `de1e7b191846008409c1f42fe16e3e747c9f14c3` (`arena/4ede8bd1-cloudhost247`)
- **Scope:** source tree and tracked documentation only. The WHMCS installation, live database, production configuration, provider accounts, credentials, and deployed server fleet are not included in this repository and were not available for inspection.
- **Baseline note:** Unless marked as a post-audit update, findings in §§1–6 describe the 2026-10-07 repository baseline above; later Phase 2/3/4 work is called out explicitly below.

## Executive finding

CloudHost247 is a **WHMCS customization overlay**, not a standalone Next.js/Node application. WHMCS is already the system of record for customers, authentication, products, prices, orders, invoices, payments, hosting services, domains, and admin roles. The existing integration must remain WHMCS-native; creating a parallel user, billing, and order platform would duplicate live functionality and contradict the repository's deployment model.

At the 2026-10-07 baseline, there was a substantial but **incomplete and unwired** App Cloud domain core under `modules/addons/cloudhost247apps/`. It contained additive MySQL migrations, application catalog and deployment services, a server/agent registry, a credential vault, an application-deployment queue, a Docker deployment adapter, and WHMCS gateway abstractions. It was the closest reusable foundation for the requested control plane, but that baseline did **not** contain the WHMCS addon entry point, HTTP API, admin/customer portal, agent daemon/ingress, or several adapters promised by `docs/APP_PLATFORM_PLAN.md`. Consequently, that document's “delivered” labels were not an accurate description of the audited code.

At that baseline, the repository also lacked the requested database-driven control-panel marketplace, a general infrastructure-provider API, customer VPS purchase/provisioning orchestration, a control-panel adapter interface, a panel license service, and a connected end-to-end server provisioning path. Existing OVH/SoYouStart, Cloudflare, and other reseller modules were useful integrations, but provider-specific WHMCS modules—not a shared orchestration layer.

**Phase 1 recommendation (at the 2026-10-07 baseline):** do not add static control-panel cards or start a second control plane. Keep WHMCS authoritative, reconcile the App Cloud documentation with source, then extend/reconnect the existing App Cloud foundation in controlled phases. The next coding phase should establish a functioning WHMCS module boundary and implement the missing generic infrastructure-provider/server lifecycle layer before adding the control-panel marketplace.

> **Post-audit status — 2026-10-08:** Phase 2 addon/API/provider-account/customer-server/worker code, Phase 3 panel-catalog metadata/surfaces, and an initial Phase 4 cPanel/WHM account adapter have since been added. The project owner confirmed the external Phase 2 WHMCS/MySQL and provider-validation gate passed; evidence is not in this checkout, which still contains no production infrastructure-provider adapter, so its customer-VM provisioning switch remains off. The earlier waiver authorized Phase 3 only; the later owner confirmation is the basis for beginning Phase 4. Phase 3 remains metadata-only, and the Phase 4 cPanel adapter is a worker-only library slice with no WHMCS account workflow, UAPI operations, panel installer, or license service. See [`PHASE2_INFRASTRUCTURE_PROVISIONING.md`](PHASE2_INFRASTRUCTURE_PROVISIONING.md), [`PHASE3_PANEL_CATALOG.md`](PHASE3_PANEL_CATALOG.md), and [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md).

## 1. Architecture observed in this checkout

| Concern | Existing implementation | Audit conclusion |
|---|---|---|
| Application host | WHMCS overlay; core WHMCS files are intentionally absent (`README.md`). PHP addon/server modules and Smarty `.tpl` templates are deployed over an installed WHMCS root. | Follow WHMCS module and routing conventions. Do not introduce a separate Next.js/Prisma/PostgreSQL stack for this work. |
| Public pages | Root-level PHP pages such as `cpanel-hosting.php`, `plesk-hosting.php`, `vps-hosting.php`, and `dedicated-server.php`; HostX templates under `templates/hostx/`; product/order forms under `templates/orderforms/`. | Existing public hosting pages are not a centralized service console. Clean paths such as `/hosting/control-panels` need a WHMCS route or deployment web-server rewrite; no application router/rewrite configuration is shipped here. |
| Customer authentication | WHMCS client session and client-area module hooks. | Reuse WHMCS customer identity and ownership checks; do not create a second customer/authentication store. |
| Admin authentication/RBAC | WHMCS admin session/roles. Some modules add their own permission matrices (for example, `cloudhost247ai`, `domainbroker`, and the App Cloud core). | Use WHMCS admin identity plus explicit module permissions for new privileged actions. The App Cloud RBAC classes exist but have no module entrypoint/API route to protect in this checkout. |
| Database | WHMCS MySQL/MariaDB accessed through Capsule or module-local database wrappers. WHMCS core schema is not checked in. | Preserve WHMCS as the source of truth for business records. No live schema or production migration state can be inferred from this overlay. |
| Billing/orders | WHMCS products/pricing, orders, invoices, transactions, and hosting-service records. The repo includes WHMCS payment gateway/module extensions, including Blockonomics. App Cloud has a plan/payment abstraction that references WHMCS records. | Build the new product flow on WHMCS product/order/invoice/service primitives; do not create competing customer, invoice, or subscription ledgers. Verify real lifecycle hooks against the target WHMCS version. |
| Background work | WHMCS automation/cron plus module-specific cron scripts. App Cloud has a leased DB-backed queue and worker code for application-deployment actions. Other modules have their own queues/cron jobs. | There is no repository-wide queue. Reuse or deliberately extend one queue boundary for new server jobs; do not add another disconnected queue. |
| Frontend routing | Public root pages, WHMCS client-area module routes (`index.php?m=…`), and WHMCS admin addon pages (`addonmodules.php?module=…`). | There are no App Cloud public/customer/admin route files in the tracked tree. The requested route names are not currently implemented. |
| Monitoring | App Cloud has server/application metrics and health-state schema/services; CloudHost247 Tools has read-only DNS/network diagnostics. | No complete server metrics collector, reachable agent ingress, Prometheus/Grafana/Loki stack, or customer-safe monitoring surface is present. |
| Notifications/audit | WHMCS mail facilities; multiple module-local audit/logging implementations. App Cloud migrations include event, notification, log, and hash-chained audit tables/classes. | Reuse the applicable existing delivery/audit boundary after wiring it. The schema/classes alone do not establish production event capture or customer notifications. |

## 2. Database map and model boundaries

### WHMCS-owned records to reuse

The code references WHMCS records including `tblclients`, `tbladmins`, `tblproducts`, `tblproductgroups`, `tblpricing`, `tblorders`, `tblinvoices`, `tblinvoiceitems`, `tbltransactions`, `tblhosting`, `tblhostingaddons`, `tbldomains`, and `tblservers`.

Important distinction: WHMCS `tblservers` represents WHMCS server/module connection configuration; it is not a customer-owned VPS inventory. WHMCS `tblhosting` is the authoritative purchased hosting service. A future provider-backed customer server record must link to the relevant `tblhosting.id`, not replace the WHMCS invoice/service record.

### App Cloud-owned schema already present

The six files under `modules/addons/cloudhost247apps/install/migrations/` define **38 module tables** with the `mod_ch247apps_` prefix. They are exercised by the App Cloud test harness against in-memory SQLite; they have not been applied to a live WHMCS database in this audit.

| Migration | Existing table group |
|---|---|
| `0001_create_core_tables.php` | `settings`, `role_permissions`, `audit_logs`, `idempotency_keys`, `rate_limits`, `events`, `logs`, `api_tokens` |
| `0002_create_catalog_tables.php` | `categories`, `applications`, `application_versions`, `application_compatibility`, `application_dependencies`, `plans` |
| `0003_create_infrastructure_tables.php` | `servers`, `server_credentials`, `agents`, `agent_nonces`, `metrics` |
| `0004_create_installation_tables.php` | `domains`, `installations`, `installation_domains`, `environment`, `volumes`, `certificates` |
| `0005_create_deployment_tables.php` | `jobs`, `deployments`, `deployment_steps`, `deployment_logs`, `created_resources` |
| `0006_create_operations_tables.php` | `backups`, `subscriptions`, `order_links`, `payment_events`, `notifications`, `webhook_events`, `health_probes`, `schedules` |

The App Cloud schema is additive and its migrator intentionally has no generic `down()` operation because it stores customer installation, deployment, and billing history. Production schema changes should continue to be additive and forward-only, with corrective migrations rather than destructive rollbacks.

### Meaning of the existing App Cloud tables

The current `mod_ch247apps_servers` model is an **App Cloud compute/deployment target**: it stores hostname/IP, provider label, region, capacity, Docker/Kubernetes/cPanel feature flags, agent/heartbeat, and available allocation. It has no customer owner or provider resource ID and `ServerService::register()` registers an already-existing target; it does not create a VPS through an infrastructure API.

The `applications`/`application_versions`/`plans` tables describe deployable applications and their resource envelopes. The plan links to a WHMCS product and may mirror a price. They are not a panel marketplace or a server product catalog. `installations` describes an application installation and is not a purchased server or a panel installation.

### Required domain not yet modeled

No App Cloud migration currently defines `control_panels`, `control_panel_plans`, a provider/account registry, provider regions/offerings, a customer-owned provider server resource, panel installation records, or vendor panel-license lifecycle. The existing `applications` table must not be overloaded to represent panels: panel-specific category, OS compatibility, license terms, install status, and operation capabilities are distinct domain data.

The App Cloud server-target table and customer VPS resource are also different entities. Phase 2 should preserve that distinction (for example, retain deployment nodes as nodes and add a provider-backed customer server linked to WHMCS `tblhosting`) rather than silently changing the semantics of the existing table. The final schema naming is a Phase 2 design decision after the WHMCS activation path is restored.

Other relevant stores include the Cloudflare addon's own account/package/service/DNS/job/audit tables and the OVH/SoYouStart addon's provider/product settings. They are separate module-owned schemas; no unified provider registry currently exists.

## 3. API and route map

| Surface | Existing route/convention | Relevant behavior and limit |
|---|---|---|
| Public hosting catalog | Root PHP pages (for example, `cpanel-hosting.php`, `plesk-hosting.php`, `vps-hosting.php`) and HostX templates | Pages use WHMCS product/group data and HostX page configuration. They are marketing/order pages, not control-panel records or management APIs. |
| Customer area | WHMCS client-area module convention, `index.php?m=<module>&action=…`; server modules expose `*_ClientArea()` hooks | Existing modules manage their own products/services. There is no unified `/account/services` control-plane surface in this repo. |
| Admin area | WHMCS addon module convention, `addonmodules.php?module=<module>&action=…` | Existing addons (e.g. Cloudflare, AI, domain broker) implement their own admin surface. `modules/addons/cloudhost247apps/cloudhost247apps.php` is absent, so no App Cloud admin module is registered. |
| App Cloud API | Expected by `docs/APP_PLATFORM_PLAN.md` at `modules/addons/cloudhost247apps/api/index.php` and `api/agent.php` | Both files/directories are absent. There is no App Cloud REST router or agent ingress in the tracked source. |
| Existing module APIs | `modules/addons/domainbroker/api/index.php`, `domainbroker/api/webhook.php`, `cloudhost247ai/api.php`, `digitalproducts/api.php`, `CloudHost247_tools/api/index.php`, plus module-specific endpoints | These APIs belong to their existing product domains; none is a server/control-panel REST API. Reuse WHMCS session/RBAC conventions, not their route namespaces for unrelated work. |
| Provider calls | OVH/SoYouStart through `modules/addons/soyoustart/classes/ApiCall.php` and `modules/servers/soyoustart*`; Cloudflare through its addon provider client | Direct provider-specific integrations; neither is behind a common `InfrastructureProvider` contract. |
| WHMCS billing hooks | WHMCS hooks and provisioning-module callbacks; Cloudflare has `InvoicePaid` handling; App Cloud has `PaymentGate` and WHMCS gateway methods | The App Cloud `hooks.php` claimed by the plan is absent. Therefore no tracked App Cloud `InvoicePaid` hook connects its payment gate to orders. |

## 4. Existing infrastructure/control-panel integrations

| Existing code | What is actually in the repo | Reuse boundary / limitation |
|---|---|---|
| OVHcloud / SoYouStart | `modules/addons/soyoustart/`, `modules/servers/soyoustart/`, `modules/servers/soyoustart_vps/`, plus `crons/getServer.php`, `getIpStatus.php`, `priceSync.php`, and `emailSend.php`. The server modules contain create, suspend, unsuspend, terminate, and selected power/reboot/console actions. | A real, provider-specific WHMCS integration. It is not a generic provider adapter, is not an abstract customer control plane, and should not be forked into a second OVH client. In a later phase, wrap or migrate the existing behavior behind a common interface with parity tests and an explicit cutover plan. |
| cPanel / Plesk | Public `cpanel-hosting.php` and `plesk-hosting.php`, product copy/assets, and cPanel deployment-engine names in App Cloud catalog code. | No custom cPanel/Plesk hosting-panel adapter or marketplace exists in this checkout. `CpanelAdapter` is named by `AdapterFactory` but its class file is absent; Plesk is not registered there. Standard WHMCS modules may exist in a live WHMCS installation outside this overlay and were not auditable here. |
| Cloudflare | `modules/addons/cloudhost247cloudflare/` has a Cloudflare API client, encrypted Cloudflare account tokens, product mapping, Cloudflare service lifecycle, DNS records/zones, a DB queue, and admin/customer surfaces. `modules/servers/cloudhost247cloudflare/` provides WHMCS provisioning hooks. | Useful candidate for the Cloudflare implementation of a future DNS provider contract. It is Cloudflare-specific; it is not a generic DNS interface and should not be duplicated with a second Cloudflare API client. |
| DNS/SSL diagnostics | `modules/addons/CloudHost247_tools/` and `modules/addons/hostx_tools/` include DNS/WHOIS/IP/SSL diagnostic functions. | Diagnostic/read operations are not DNS zone/record mutation, ACME issuance, certificate renewal, or panel APIs. |
| App Cloud Docker application deployment | `modules/addons/cloudhost247apps/lib/Adapters/DockerAdapter.php`, `ComposeBuilder.php`, `Orchestrator.php`, `JobQueue.php`, manifest/catalog services, `AgentClient.php`, and sample application manifests (including Traefik). | Reusable app-deployment domain code exists, but the tracked server-agent daemon and agent HTTP receiver do not. A `worker/worker.php` file exists, but it cannot form the documented API → worker → agent loop without the absent module bootstrap and agent endpoint. It is not a VPS create/rebuild/resize provider. |
| cPanel/Kubernetes in App Cloud | `AdapterFactory` maps `cpanel` to `Ch247Apps\Adapters\CpanelAdapter` and `kubernetes` to `KubernetesAdapter`; manifest validation accepts those engine names. | The corresponding `lib/Adapters/CpanelAdapter.php` and `KubernetesAdapter.php` files are absent. `AdapterFactory::availability()` consequently cannot report those adapters as installed. The deployment `AdapterInterface` is not the requested control-panel management contract. |
| Other WHMCS server modules | `RDP`, `hostx_email`, `Smtphosting`, `cloudhost247_lteproxy`, `smmprovisioning`, and related modules integrate their named reseller/service products. | These are not interchangeable cloud VM providers or control-panel management adapters. Keep their product integration boundaries intact. |
| Digital product licenses | `modules/addons/digitalproducts/` implements downloadable digital product entitlements/licenses. | It is not cPanel/Plesk/vendor license activation, renewal, suspension, or server binding. No panel license provider interface was found. |

## 5. Duplicate-prevention and reusable components

### Reuse rather than recreate

1. **WHMCS core** remains authoritative for customer/admin identity, product and recurring pricing, orders, invoices, transactions, hosting services, and WHMCS product provisioning callbacks.
2. **`cloudhost247apps` domain primitives** are candidates for reuse after the missing bootstrap is addressed: `Core\Db`, `Blueprint`, `Migrator`, `Identity`, `Rbac`, `Crypto`, `Audit`, `Idempotency`, `RateLimiter`, `CredentialVault`, `Servers\ServerService`, and the leased `Deployments\JobQueue`. They are App Cloud module-local, not currently a shared framework with a WHMCS activation path.
3. **Existing OVH and Cloudflare integrations** should remain the only implementations of their live provider API behavior until an adapter can call or deliberately replace them. Preserve feature parity and data mappings; do not create a parallel token store/Cloudflare client or an untested second OVH implementation.
4. **CloudHost247 Tools** is a source of real diagnostics, not a control-plane API. Do not present its DNS/SSL checks as DNS/SSL management.
5. **WHMCS HostX order pages and product data** are reusable for product presentation/order completion, but panel metadata/pricing/capabilities need their own catalog records and admin-managed settings.

### Existing module-local duplication to avoid multiplying

Several addons have their own database, migration, audit, crypto, logger, CSRF, rate-limit, and RBAC classes. There is no single installed cross-addon platform library. Do not introduce another full copy of these primitives. The lowest-risk path is to extend the closest existing module and evolve shared boundaries only when one concrete integration needs them; avoid a broad cross-repository refactor in the control-plane rollout.

## 6. Material documentation/source mismatch

`docs/APP_PLATFORM_PLAN.md` describes the App Cloud portal, REST API, agent ingress/daemon, WHMCS hook file, cPanel and Kubernetes adapters, customer/admin templates, server provisioning module, landing page, Traefik infrastructure stack, and `docs/APP_PLATFORM.md` as delivered. The corresponding tracked paths are absent. Present files include the migrations, domain/core services, sample manifests, cron script, worker script, and four PHP test suites—but not those promised entrypoints/components.

`docs/MODULES.md` also omits `cloudhost247apps`. `docs/OPERATIONS.md` and the general README describe the WHMCS overlay and other modules but do not provide a truthful App Cloud deployment runbook. This audit is the current source of truth for the App Cloud status until the code and documents are reconciled.

## 7. Gap assessment against the hosting-control-plane brief

| Brief area | Current status in repository |
|---|---|
| Database-driven control-panel catalog and plan references | **Added after the audit baseline (Phase 3).** Migration `0010_create_panel_catalog_tables` adds dedicated `panel_categories`, `control_panels`, and `control_panel_plans` records; the WHMCS admin manager configures them and the public WHMCS client-area page renders published rows. Existing application categories/applications/plans remain separate. |
| Official metadata/provenance for the 18 named products | **Admin-configurable, not pre-populated.** Each published panel requires an HTTPS official-source URL; documentation/support URLs, license terms, OS/resource requirements, capability flags, and WHMCS product/cycle references are stored. No vendor claims or sample panel records are fabricated. |

Requested catalog scope, all **not currently present as control-panel records or panel adapters**:

| Category | Products from the request | What source inspection found |
|---|---|---|
| `SERVER_PANEL` | cPanel, Plesk, DirectAdmin, CyberPanel, HestiaCP, aaPanel, CloudPanel, FASTPANEL, Webuzo, Webmin, TinyCP, Kusanagi | cPanel and Plesk occur in marketing/product copy; cPanel also occurs as an App Cloud deployment-engine label. This does not establish a panel catalog or management/install adapter. No control-plane integration record or adapter was found for the list. |
| `APPLICATION_DEPLOYMENT_PLATFORM` | Dokploy, Coolify, Easypanel, Cloudron, Cosmos | No catalog records or product-specific adapters found. App Cloud's own Docker Compose deployment engine is separate from these named platforms. |
| `SERVER_MANAGEMENT` | AdminBolt | No catalog record or adapter found. |
| Generic infrastructure provider abstraction | **Missing.** No `InfrastructureProvider` interface, provider account registry, region/offer catalog, or common create/delete/reboot/power/rebuild/resize/snapshot API. Existing OVH is a custom WHMCS module. |
| Customer-owned server/service model | **Missing as specified.** App Cloud `servers` are registered app deployment targets; `installations` are app installs; WHMCS `tblhosting` is the service authority. There is no unified customer VPS/server resource linked to a provider ID and a paid WHMCS service. |
| Queue-based provider provisioning | **Partial foundation only.** A DB-backed job queue and worker exist for App Cloud deployment actions. There is no verified provider `createServer` path, provider job handler, unified server state machine, or connected App Cloud HTTP/bootstrap path. |
| Control-panel adapter interface and capabilities | **Missing.** Existing `Adapters\AdapterInterface` abstracts application deployment adapters; it is not a panel lifecycle API with capability flags. No control panel operations are wired. |
| cPanel/Plesk/DirectAdmin/CyberPanel/HestiaCP and remaining panels | **Not integrated in this overlay.** Some marketing copy and engine labels exist; this is not evidence of install, licensing, account, or management APIs. Adapter availability must remain unavailable until implemented and verified. |
| Purchase flow and billing lifecycle | **Existing WHMCS foundation; new flow missing.** WHMCS billing is reusable; App Cloud has a product-linked plan/payment layer but no tracked addon hook connects it to paid server orders. No VPS + panel configurator exists. |
| DNS | **Partial.** Cloudflare-only zone/record management exists in a separate addon. No common `DNSProvider` contract or evidenced CloudHost247 DNS/Route53 adapter in App Cloud. |
| SSL/ACME | **Partial schema/state only.** App Cloud has domain/certificate records, but no tracked App Cloud certificate issuer/renewal service. Diagnostics do not issue certificates. |
| Firewall/security/bootstrap | **Missing for new VPS creation.** No generic safe baseline-hardening job, rollback/merge policy, or provider/server lifecycle flow is connected. |
| Monitoring | **Partial schema/domain methods only.** Metrics/health storage and agent protocol classes exist, but the documented agent ingress/daemon is absent; no complete customer/admin monitoring pipeline is verified. |
| Backups | **Partial schema/service foundation.** App Cloud has backup-related records and application deployment code, not verified end-to-end VPS/server snapshots or provider backup operations. |
| Panel licenses | **Missing.** No panel license provider, activation/validation/renewal flow, or customer-safe license record. Existing digital-product license code is a separate product. |
| Audit, idempotency, retries | **Reusable partial foundation.** App Cloud migrations/classes and tests cover domain logic; no administrative/API operations are wired for this new service. |
| Customer/admin dashboards and provisioning console | **Partial after Phase 3.** A dedicated panel catalog admin manager and public WHMCS client-area catalog now exist; broader customer/admin VPS provisioning dashboards and an operator console remain future work. |
| Secure panel gateway | **Missing.** No authenticated reverse-proxy/gateway route for customer panel access. |
| End-to-end production evidence | **Missing.** No live WHMCS runtime, production DB migration, provider credentials, external panel, worker/agent handshake, or real paid provisioning can be validated from this checkout. |

## 8. Recommended implementation map after Phase 1

This map began as the audit baseline; later owner decisions and implementation updates are noted inline:

1. **Phase 2 — restore and extend one control-plane boundary.** The WHMCS addon/API, provider-account model, customer-owned VM schema, and queue worker are implemented in code. The owner confirmed the external WHMCS/MySQL and real-provider validation gate passed on 2026-10-08, but its evidence is not in this repository. No production provider adapter is registered in this checkout, so customer-server provisioning remains disabled and is not claimed as operational.
2. **Phase 3 — panel catalog and plans (metadata-only implementation added 2026-10-08).** Migration `0010_create_panel_catalog_tables` adds dedicated panel categories, catalog records, and WHMCS-linked plan references. Admins configure official HTTPS sources, OS/resource requirements, license-term metadata, capability flags, and WHMCS product/cycle references; the public client-area catalog renders stored published rows. No prices are duplicated, no orders are created, and panel installation/licensing remains non-deployable; configurable status values are limited to `not_implemented`, `metadata_only`, and `planned`. See [`PHASE3_PANEL_CATALOG.md`](PHASE3_PANEL_CATALOG.md).
3. **Phase 4 — cPanel & WHM first adapter (initial slice added 2026-10-08).** A separate `ControlPanelAdapterInterface`/registry and WHM API 1 account-lifecycle adapter implement authenticated verification, account reads, create/recovery, suspend, unsuspend, and confirmed termination. Operations require worker context. This does not add UAPI capabilities, panel software installation, licensing, a WHMCS service-bound account workflow, or customer-facing actions. Live cPanel staging validation remains required. See [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md).
4. **Phases 5–6 — additional panel capabilities/adapters.** Add only product operations supported by the official interface or a documented, secure installation method. Model unsupported operations as unavailable; do not emulate success. Keep panel installation/licensing distinct from installing a customer application.
4. **Phases 7–12 — connected operations.** Add DNS/SSL/firewall, monitoring, backups, WHMCS renewal/suspension/termination/resize flows, notifications, and admin/customer dashboards through the existing control plane. Start Cloudflare as a provider adapter by wrapping/reusing the existing implementation, and OVHcloud by adapting the legacy SoYouStart flow with compatibility tests. Add other providers only when credentials and documented APIs are configured.
5. **Compatibility gates.** Add real WHMCS staging tests and provider/panel contract tests before enabling each integration. Fakes remain test-only and must never be selected as production success paths. Keep every migration additive and every operation idempotent/audited.

## 9. Verification performed

From `modules/addons/cloudhost247apps/`:

```text
npm install --ignore-scripts --no-audit --no-fund --package-lock=false
npm run lint   → FILES=60, BAD=0
npm test       → TOTAL PASS=773, FAIL=0
```

The local PHP runtime is unavailable; the module's test/lint harness uses PHP 8.3 WebAssembly and in-memory SQLite. The tests validate the checked-in domain classes and migration definitions with fakes. They do **not** prove WHMCS activation, production MySQL migration, API/UI behavior, provider availability, real panel installation, live payment hooks, or end-to-end provisioning. No credentials or provider requests were used in those baseline checks. `node_modules` is ignored and no package lock or application source was changed by those checks.

**Post-Phase 4 offline validation — 2026-10-08:** `npm test` → `TOTAL PASS=1107 FAIL=0`; `npm run lint` → `FILES=86, BAD=0`; `git diff --check` passed. Suite 08 uses a fake HTTP transport and is not live cPanel evidence.

## 10. Phase 1 exit and next step

Phase 1 is complete as an audit deliverable; the statement here describes the 2026-10-07 baseline only. Subsequent work added the Phase 2 WHMCS/provider boundary, the Phase 3 metadata catalog, and an initial Phase 4 cPanel/WHM worker-only adapter slice. The owner reports that Phase 2's external validation passed, but evidence is not checked in and this source tree still has no production infrastructure-provider adapter. Continue Phase 4 by adding a WHMCS-service-bound account workflow, safe account credential delivery/SSO, worker queue integration, and real cPanel staging tests before describing cPanel operations as production-ready. Do not mark any panel or provider “integrated” solely because code is registered; require real contract and staging evidence.
