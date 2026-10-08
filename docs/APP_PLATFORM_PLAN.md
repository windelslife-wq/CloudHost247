# CloudHost247 App Cloud — implementation plan (based on the actual repository)

This is the plan required before any code was written. It records what the
repository actually is, what already exists and must be reused, where the
platform specification maps onto it, and the phased build order.

> **Source audit notice — 2026-10-07:** The “delivered” labels and component
> inventory below do not match the source tree audited on that date. In particular,
> the addon entry point, API, agent ingress/daemon, admin/customer portals,
> cPanel/Kubernetes adapters, and several paths in the proposed file layout were
> absent. Treat those sections as an earlier plan, not proof of shipped or
> deployable functionality. The baseline status is documented in
> [`HOSTING_CONTROL_PLANE_AUDIT.md`](HOSTING_CONTROL_PLANE_AUDIT.md).
>
> **Phase 2 update — 2026-10-08:** The WHMCS addon lifecycle and authenticated
> provider/server API boundary, encrypted provider-account model, separate
> customer-owned VM schema, and provisioning queue dispatch are now present.
> The project owner confirmed the external Phase 2 WHMCS/MySQL and provider-validation
> gate passed on 2026-10-08, but the evidence is not in this repository. No production
> provider adapter is registered here, so customer VM provisioning stays disabled
> and fails clearly rather than returning simulated success. See
> [`PHASE2_INFRASTRUCTURE_PROVISIONING.md`](PHASE2_INFRASTRUCTURE_PROVISIONING.md).
>
> **Phase numbering/status note — 2026-10-08:** This legacy App Platform plan uses
> “Phase 3” for Billing and contains older “delivered” claims that the source audit
> disproved. The active Hosting Control Plane sequence uses “Phase 3” for the panel
> metadata catalog and “Phase 4” for the cPanel/WHM adapter plus staff-only queued
> workflow for existing accounts bound to paid WHMCS services. The workflow is
> disabled by default, does not create accounts or deliver passwords, and does not
> claim that WHM is available on any server. Details are in
> [`PHASE3_PANEL_CATALOG.md`](PHASE3_PANEL_CATALOG.md),
> [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md), and
> [`PHASE4_CPANEL_WORKFLOW.md`](PHASE4_CPANEL_WORKFLOW.md). The staging procedure in
> [`PHASE4_CPANEL_STAGING.md`](PHASE4_CPANEL_STAGING.md) is prepared but unexecuted.
> Phase 2's owner-confirmed
> external pass is not equivalent to a production provider adapter in this checkout.

---

## 1. What the repository actually is

`windelslife-wq/CloudHost247` is **not** a Next.js/Prisma/Node monorepo. It is a
**WHMCS customisation overlay** (see `README.md`): its tree is copied on top of a
standard WHMCS installation, and WHMCS core files are deliberately not stored
here.

| Concern | Reality in this repository |
| --- | --- |
| Language / runtime | PHP 7.4–8.3 (WHMCS), Smarty `.tpl` client-area templates |
| Framework | WHMCS (Laravel Illuminate components + Capsule/Eloquent internally) |
| Database | **MySQL/MariaDB** through WHMCS Capsule. There is no PostgreSQL and no Prisma anywhere in the tree. |
| Front end | `templates/hostx/` client-area theme, root `*.php` landing pages, `templates/orderforms/hostx` |
| Authentication | WHMCS client sessions (`$_SESSION['uid']`), admin sessions (`$_SESSION['adminid']`), plus `modules/addons/cloudhost247passkey` (WebAuthn) |
| RBAC | WHMCS admin roles + per-module role matrices (`domainbroker`, `cloudhost247ai`) |
| Billing | WHMCS: `tblproducts`, `tblpricing`, `tblorders`, `tblinvoices`, `tblinvoiceitems`, `tblhosting`, `tblcurrencies`, gateways in `modules/gateways/` (`blockonomics`) |
| Products/hosting | WHMCS product groups + provisioning ("server") modules in `modules/servers/` (`soyoustart`, `soyoustart_vps`, `RDP`, `hostx_email`, `Smtphosting`, `cloudhost247_lteproxy`, `smmprovisioning`, `cloudhost247cloudflare`) |
| Servers | WHMCS `tblservers` + the OVH/SoYouStart admin addon and its `crons/` |
| Domains | WHMCS `tbldomains`, registrar modules, `domainbroker`, `hostx_tools` |
| Email | WHMCS `SendEmail`/`SendAdminEmail`, email templates, `crons/emailSend.php`, `cloudhost247marketing` |
| Background jobs | WHMCS cron/automation + per-module `cron/*.php` CLI scripts (`domainbroker`, `cloudhost247ai`, `cloudhost247marketing`, `cloudhost247_cart_recovery`) |
| Audit | Hash-chained per-module audit tables (`domainbroker/lib/Core/Audit.php`) |
| Testing | No native `php` binary in the dev sandbox: suites run on **PHP 8.3 compiled to WebAssembly** (`@php-wasm/node`) against in-memory SQLite using the production migrations (`domainbroker/tests/`, `cloudhost247ai/tests/`) |

### Consequence for the specification

The specification asks for PostgreSQL + Redis + BullMQ + a Next.js app tree, but
it also states the overriding rules:

* *"First inspect the existing CloudHost247 codebase … Reuse existing
  functionality where appropriate. Do not replace working functionality
  unnecessarily."*
* *"Do not create duplicated systems when an existing system in the project can
  be extended."*
* *"If the existing project already uses PostgreSQL, extend it. Do not create a
  second database system unnecessarily."*
* *"Do not rewrite working modules just to match this specification."*
* *"Create an implementation plan based on the actual repository."*

Building a parallel Node/PostgreSQL/Prisma stack would duplicate **every**
already-working subsystem: users, RBAC, billing, orders, invoices, payments,
subscriptions, products, plans, customers, domains, email, tickets, admin
dashboard and customer dashboard. That is exactly what the specification
forbids.

**Decision:** the Application Marketplace and Deployment Platform is built as a
**WHMCS-native control plane** — one addon module that owns the new domain
(applications, manifests, installations, deployments, servers/agents, SSL,
backups, monitoring) and delegates everything WHMCS already does to WHMCS.
Every architectural separation the specification demands (control plane /
portal / marketplace / manifest system / deployment engine / worker / adapters /
server agent / Docker / Traefik / cPanel / Kubernetes) is implemented as a real
component boundary inside that platform; only the *host* framework differs from
the illustrative Node tree in §2 of the specification.

The mapping of the specification's storage requirements onto existing tables is
in §3 below. Nothing is faked: where WHMCS owns a concept, WHMCS is the source
of truth and this module reads it server-side.

---

## 2. Component map (specification §1 → this repository)

```
 WHMCS client area (templates/hostx) + root landing pages     ← Customer portal / Marketplace UI
 WHMCS admin area (addonmodules.php?module=cloudhost247apps)  ← Admin portal
        │
        ▼
 modules/addons/cloudhost247apps/api/index.php                ← /api/v1 control-plane API (RBAC, rate
        │                                                        limit, CSRF, idempotency, audit)
        ▼
 lib/Billing      lib/Catalog      lib/Servers     lib/Domains  ← PostgreSQL role played by the WHMCS
        │                                                        MySQL database (module tables are
        ▼                                                        prefixed mod_ch247apps_)
 lib/Deployments/Queue  (DB-backed queue: leases, retries,
        │                backoff, dead-letter)                   ← Redis/BullMQ role
        ▼
 worker/worker.php  (long-running or cron-driven worker)
        │
        ▼
 lib/Adapters/{Docker,Cpanel,Kubernetes}Adapter
        │
        ├── Docker ──► agent/cloudhost-agent.php on the VPS/dedicated host ──► docker compose + Traefik
        ├── cPanel ──► WHM API (server provisioning) + UAPI (account level)
        └── Kubernetes ► kube-apiserver (namespaces, deployments, services, ingress, PVC, secrets)
```

The API **never** touches Docker. It creates deployment jobs; the worker
executes them; the worker talks to an authenticated server agent; the agent
performs controlled operations on the host. `/var/run/docker.sock` is never
exposed to the network — the agent binds to localhost or a private interface
and accepts only HMAC-signed, replay-protected, short-lived requests.

### File layout of the delivered platform

```
modules/addons/cloudhost247apps/
├── cloudhost247apps.php          WHMCS addon entry (config/activate/upgrade/output/sidebar/clientarea)
├── autoload.php                  PSR-4 autoloader for Ch247Apps\ → lib/
├── hooks.php                     WHMCS hooks: navbar, assets, InvoicePaid → provisioning, suspend/terminate
├── api/index.php                 /api/v1 REST front controller
├── api/agent.php                 server-agent ingress (signed heartbeats, metrics, results, logs)
├── worker/worker.php             deployment worker CLI (queue consumer)
├── cron/cloudhost247apps.php     scheduler: health checks, backups, SSL renewal, suspensions, pruning
├── agent/                        the CloudHost247 Server Agent (runs on customer infrastructure)
│   ├── cloudhost-agent.php       single-file CLI daemon (HTTP + docker/compose/filesystem/backup/ssl)
│   ├── cloudhost-agent.service   systemd unit
│   ├── install-agent.sh          installer (key generation, systemd, firewall guidance)
│   └── agent-config.example.json
├── manifests/                    one YAML manifest per deployable application (+ registry seed)
├── install/migrations/           additive, re-runnable schema migrations
├── lib/
│   ├── Core/                     Db, Blueprint, Migrator, Settings, Rbac, Actor, Identity, Crypto,
│   │                             Audit, Idempotency, RateLimiter, Validator, Logger, Clock, Str,
│   │                             Http, Csrf, Events, Yaml, Exceptions, Whmcs
│   ├── Catalog/                  categories, applications, versions, compatibility, manifest repo +
│   │                             validator, catalog importer (database-driven, never hardcoded pages)
│   ├── Servers/                  server registry, credential vault (encrypted + rotation), agent
│   │                             registry, agent protocol (signing/replay), heartbeat, metrics
│   ├── Deployments/              Queue, Orchestrator, Steps/*, Rollback, DeploymentService
│   ├── Adapters/                 AdapterInterface + Registry + Docker / Cpanel / Kubernetes / Fake
│   ├── Domains/                  domain attach + verification, DNS providers, SSL service, Traefik cfg
│   ├── Backups/                  backup/restore, storage providers (local, S3/R2), retention
│   ├── Billing/                  plan ↔ WHMCS product mapping, order creation, payment verification,
│   │                             subscription lifecycle (PAST_DUE → GRACE → SUSPENDED → TERMINATED)
│   ├── Monitoring/               health probing, status policy (never fabricated), recovery + breaker
│   ├── Notifications/            WHMCS email/notification bridge + templates
│   ├── Api/                      Router, ApiRequest/Response, Controllers/*
│   ├── Http/                     AdminPortal, CustomerPortal, Marketplace, Wizard, View, Html
│   └── Integration/              GatewayInterface, WhmcsGateway, FakeGateway (test seam)
├── templates/client/*.tpl        marketplace, app detail, install wizard, installations, detail pages
├── assets/{css,js}/
├── tests/                        php-wasm suites + lint gate (same harness style as domainbroker)
└── README.md
modules/servers/cloudhost247_appcloud/   WHMCS provisioning module: product → installation → deployment
infrastructure/docker/traefik/           reference Traefik stack for an app-cloud node
app-marketplace.php                      public marketing landing page (hostx theme convention)
templates/hostx/app-marketplace.tpl
docs/APP_PLATFORM.md                     runbook + architecture + operations
```

---

## 3. Data model: specification table → implementation

Module tables are prefixed `mod_ch247apps_` (WHMCS addon convention, as used by
`mod_ch247ai_*`). WHMCS core tables are referenced **by id only** — no foreign
keys into core tables, so the module stays deployable on hosted WHMCS and never
blocks WHMCS maintenance.

| Specification | Implementation | Notes |
| --- | --- | --- |
| `users`, `roles`, `user_roles` (§4) | WHMCS `tblclients`/`tblcontacts`/`tbladmins` + `mod_ch247apps_role_permissions` | Users are **not** duplicated. RBAC matrix is module-owned and enforced server-side (`Rbac::assert`) on every privileged route and service call. |
| `servers` (§5) | `mod_ch247apps_servers` (+ link to WHMCS `tblservers.id`) | server_type VPS/DEDICATED/CPANEL/KUBERNETES/SHARED, capabilities, agent identity, last heartbeat. |
| `server_credentials` (§6) | `mod_ch247apps_server_credentials` | AES-256-GCM at rest, AAD-bound to the row context, `key_version`, rotation, blind index. Never returned by any API response. |
| `application_categories` (§7) | `mod_ch247apps_categories` | Seeded with the 24 categories listed in the specification. |
| `applications` (§8) | `mod_ch247apps_applications` | Database-driven catalog. `kind` distinguishes APPLICATION / INFRASTRUCTURE / PLATFORM (§60). |
| `application_versions` (§9) | `mod_ch247apps_application_versions` | version, docker image, manifest snapshot, minimums, release notes, status. |
| Manifest system (§10) | `manifests/*.yaml` + `ManifestRepository` + `ManifestValidator`, snapshot into versions | One manifest format, no per-application installer code. |
| `application_installations` (§11) | `mod_ch247apps_installations` | All 11 statuses implemented as a real state machine. |
| `deployments` (§12) | `mod_ch247apps_deployments` | 12 actions, unique idempotency key, error code + message. |
| `deployment_steps` (§13) | `mod_ch247apps_deployment_steps` | Ordered steps with per-step output/error; failure records the exact step. |
| Rollback (§14) | `Rollback` + reverse steps + `created_resources` ledger | FAILED / ROLLING_BACK / ROLLED_BACK; nothing left unrecorded. |
| `domains`, `application_domains` (§15) | `mod_ch247apps_domains`, `mod_ch247apps_application_domains` (+ WHMCS `tbldomains` link) | Verification status, SSL status, expiry. |
| `application_environment` (§16) | `mod_ch247apps_environment` | Encrypted values, `is_secret`; secrets are never returned by the API (masked only). |
| `application_volumes` (§17) | `mod_ch247apps_volumes` | |
| `backups` (§18) | `mod_ch247apps_backups` | local / S3 / R2 / remote providers, checksum, retention, expiry. |
| `products`, `plans` (§19) | WHMCS `tblproducts`/`tblpricing`/`tblproductgroups` + `mod_ch247apps_plans` (resource limits, app link) | Products are **not** duplicated; the plan row adds CPU/RAM/storage/bandwidth limits and the application binding. |
| `subscriptions` (§19, §21) | WHMCS `tblhosting` (authoritative) + `mod_ch247apps_subscriptions` (app-cloud lifecycle) | ACTIVE/TRIALING/PAST_DUE/GRACE_PERIOD/SUSPENDED/CANCELLED/EXPIRED/TERMINATED, configurable grace period. |
| `orders`, `order_items`, `payments` (§19) | WHMCS `tblorders`, `tblinvoices`, `tblinvoiceitems`, `tbltransactions` | Read server-side; the module stores `whmcs_order_id`/`whmcs_invoice_id` references on the installation. |
| Payment rule (§20) | `Billing\PaymentGate` + `InvoicePaid` hook + `webhooks/payment/:provider` | Provisioning is created **only** from a server-verified paid invoice. Browser input is never trusted. |
| Audit (§54) | `mod_ch247apps_audit_logs` (hash-chained) + WHMCS activity log | All listed actions recorded with actor, ip, user agent, metadata. |
| Jobs/queue (§28, §29) | `mod_ch247apps_jobs` | Leases, attempts, backoff, dead-letter, per-action modular handlers. |
| Deployment logs (§62) | `mod_ch247apps_deployment_logs` + `mod_ch247apps_events` | Centralized, queryable by deployment/installation/server/source. |
| Metrics (§51) | `mod_ch247apps_metrics` (server + application samples) | Only written from real agent reports; status policy forbids fabricated values (§65). |

---

## 4. Phases

Each phase ends with: migrations run, lint gate green, test suites green,
API/portals exercised, no placeholder implementations left behind.

### Phase 1 — Control plane (delivered)
Schema + Core (Db/Blueprint/Migrator/Settings/Rbac/Actor/Identity/Crypto/Audit/
Idempotency/RateLimiter/Validator/Logger/Clock/Str/Http/Csrf/Events/Yaml),
application catalog + categories + versions, manifest repository/validator,
server registry + credential vault + agent registry, audit logging, `/api/v1`
router with RBAC/CSRF/rate-limit/idempotency, admin application manager
(create/edit/versions/manifest upload/validate/test/publish/approval workflow),
customer marketplace + installation wizard, public landing page, notification
bridge, tests `01`–`03`, `10`, `11`.

### Phase 2 — Docker deployment (delivered)
DB-backed queue, deployment orchestrator with the 15 named steps, Docker
adapter (per-customer compose project under `/opt/cloudhost247/apps/<client>/<app>`),
Traefik label generation from manifest + domain (never hardcoded), resource
limits from the plan, server agent (signed protocol, replay protection,
short-lived tokens), worker CLI, health checks, rollback, logs, metrics,
backups, recovery circuit breaker. Validated application set: WordPress, n8n,
Nextcloud, Uptime Kuma, Vaultwarden, Immich, Grafana, Gitea, PostgreSQL, Redis,
Traefik. Tests `04`–`09`.

### Phase 3 — Billing (delivered)
Plan ↔ WHMCS product mapping, order creation through WHMCS, server-side payment
verification (`InvoicePaid` hook + webhook endpoint with signature verification
and idempotent processing), subscription lifecycle with configurable grace
period, suspension/termination automation, provisioning strictly gated on
verified payment. Tests `06`.

### Legacy Phase 4 — cPanel application adapter (planned; not source-verified)

> This is earlier planning material, not proof that a cPanel application deployment
> adapter shipped. The source tree does not contain `Ch247Apps\\Adapters\\CpanelAdapter`.
> The active Hosting Control Plane Phase 4 is the separate cPanel/WHM account adapter
> slice documented in [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md).
`CpanelAdapter` using **WHM API** (`createacct`, `suspendacct`, `unsuspendacct`,
`removeacct`, `wwwadd`, `adddns`, `modifyacct`, `setresellerlimits`, package
management) and **UAPI** (`DomainInfo`, `Email::add_pop`, `Mysql::create_database`
/`create_user`/`grant`, `SSL::install_autossl`, `Stats::*`, `WordPress*` where
available). WordPress deploys through cPanel *or* Docker, chosen by the hosting
product — the customer never sees the difference. Tests `12`.

### Phase 5 — Catalog expansion (delivered as a governed registry)
The wider catalog is imported as **registry rows** (`status = DRAFT`,
`deployable = 0`) classified APPLICATION / INFRASTRUCTURE / PLATFORM. An
application becomes PUBLISHED only after manifest validation, security
validation, deployment test, health-check test, backup test, update test and
uninstall test — enforced by the approval workflow, not by convention. This is
why the marketplace can show breadth on day one without pretending that
hundreds of applications are already deployable.

### Phase 6 — Advanced infrastructure (foundation delivered)
`KubernetesAdapter` (namespace, deployment, service, ingress, PVC, secret,
config map, HPA) is implemented behind the same adapter interface and gated by
server capability (`kubernetes_enabled`) plus an admin setting; it is marked
experimental until a cluster is registered. Multi-node/HA, autoscaling, GPU and
usage-based billing are schema-ready (manifest `scaling`, `gpu`, metrics) but
not advertised as available.

---

## 5. Non-negotiable rules carried into the code

1. No hardcoded application pages — the catalog is database rows + manifests.
2. No per-application installer code — one manifest system, one engine.
3. No Docker access from HTTP request handlers — API → queue → worker → agent.
4. No plaintext credentials — AES-256-GCM, key versioning, rotation, masked output.
5. No frontend payment trust — provisioning only from a server-verified invoice.
6. No fabricated status — health/metrics are `UNKNOWN` until a real agent report
   or probe confirms them.
7. No deployment of unpaid orders.
8. No cPanel logic inside Docker logic — separate adapters, one interface.
9. No half-provisioned resources — every created resource is recorded and rolled
   back or reported.
10. No duplicate subsystems — WHMCS owns users, billing, products, orders,
    invoices, payments, domains, email and tickets.
