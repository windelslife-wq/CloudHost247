# CloudHost247 — Infrastructure Layer (OS catalog, provisioning, server management)

Runbook for the server-provisioning layer of `modules/addons/cloudhost247services`
(v1.2.0). This layer extends the same addon in place — it does **not** add a
second addon, billing system, customer system or job queue. WHMCS remains the
system of record for products, orders, invoices, payments and services
(`tblproducts`, `tblorders`, `tblinvoices`, `tblhosting`, `tblservers`); the
module links to those records by id and never copies billing data.

## 1. Concepts and data model

```
Operating System → OS Version → Architecture → Provider Image Mapping
  (server_os_images: provider_id, operating_system_version_id,
   provider_image_id / provider_template_id, architecture, region)
→ Infrastructure Provider → Provisioning Job → Server (module_servers)
```

- **Operating systems** (`operating_systems`): name, slug, vendor, description,
  `logo_url` (a UI asset only — never the install image), sort order, status
  (ACTIVE/DISABLED/ARCHIVED) and per-type flags (VPS / dedicated / cloud /
  reinstall).
- **OS versions** (`operating_system_versions`): version, display name,
  architecture support (`x86_64`, `arm64`), lifecycle status
  (ACTIVE → MAINTENANCE → EOL_WARNING → EOL → ARCHIVED), `is_lts`,
  `is_default`, release / end-of-life dates. Only ACTIVE and MAINTENANCE are
  selectable for **new** deployments; EOL/retired versions keep showing on
  existing servers. An OS existing in the catalog does **not** make an image
  deployable.
- **Provider image mappings** (`server_os_images`): the translation from
  "Ubuntu 24.04 LTS on x86_64 in Frankfurt" to the provider-specific image /
  template identifier. A mapping is `disabled` until an admin runs
  **Test image** against the provider and then enables it. Enabling is
  refused without a configured provider and a passed test.
- **Infrastructure providers** (`infrastructure_providers`): base URL, JSON
  endpoint map (`operation → "METHOD /path/{server}"`), auth header/prefix/
  token field, capability flags, health status. Credentials are a JSON object
  sealed with AES-256-GCM (`Secrets`, keyed by the `CHS_CREDENTIALS_KEY`
  environment variable) — they are never displayed, logged, emailed or sent
  to the browser. Without the key, saving credentials fails closed.
- **Regions** (`infrastructure_regions`): provider-scoped datacenters.
- **Product rules** (`server_product_rules`): per server product — server type
  classification (vps/dedicated/cloud) and optional provider/region pinning.
- **Provisioning jobs** (`provisioning_jobs`): the durable state machine
  (QUEUED → ALLOCATING → CREATING → INSTALLING_OS → CONFIGURING →
  NETWORK_CONFIGURING → SECURITY_CONFIGURING → HEALTH_CHECK → READY, or
  FAILED/CANCELLED) with per-stage logs, machine error codes, retryability and
  a correlation id. Types: PROVISION, REINSTALL, ACTION.
- **Module servers** (`module_servers`): the module's server registry — links
  client, order, invoice, WHMCS service (`tblhosting`), WHMCS server record
  (`tblservers`), provider, provider server id, IP, OS version, architecture,
  region and status.
- **Customer SSH keys** (`customer_ssh_keys`): public keys only, format
  validated, ownership-checked.

## 2. Provisioning pipeline (all asynchronous, all idempotent)

1. Customer orders via the client area (**My servers → Order a server**):
   product → region → OS card (logos from `logo_url`) → version →
   architecture → SSH key → hostname → review → **Continue to payment**.
   The module creates a real WHMCS order + invoice through the platform
   gateway (`AddOrder`). Valid OS/version/architecture combinations come from
   the database — the frontend never hard-codes operating systems and the
   backend re-validates every selection.
2. **Payment gate**: the `InvoicePaid` hook fires
   `ServerOrderService::onInvoicePaid`, which verifies the invoice is really
   `Paid` through the gateway (never trusting the hook payload) and only then
   enqueues the provisioning job on the existing `mod_chs_jobs` queue. The
   worker **re-verifies payment at execution time** — an unpaid server can
   never provision.
3. The cron (`cron/cloudhost247services.php`, every 5 minutes) drains the
   shared queue via `Worker::run()` (domain + infrastructure jobs together).
   Stages: validate order/payment/product → resolve provider/OS/version/
   architecture/image → allocate → create at provider → wait for OS install →
   configure (hostname/SSH/firewall/monitoring) → network (IP) → security →
   **health check** (server exists, powered on, has IP) → READY.
4. On READY the module creates/updates the `tblservers` record, links it and
   the hostname on the WHMCS service (status Active), stores the IP and sends
   the customer a "server ready" notification (no credentials in it).

**Idempotency**: the provider server id is persisted before any follow-up
call, so a crashed worker resuming a job never creates a second server;
queue idempotency keys (`<job_key>:run:<n>`) make duplicate deliveries no-ops.
**Failure classification**: transient provider errors (PROVIDER_TIMEOUT,
RATE_LIMITED, PROVIDER_UNAVAILABLE, 5xx) are retried by the queue with
backoff and resume the state machine; permanent errors
(PROVIDER_NOT_CONFIGURED, IMAGE_UNAVAILABLE, INVALID_CONFIGURATION,
AUTHENTICATION_FAILED, INSUFFICIENT_CAPACITY, PAYMENT_NOT_CONFIRMED) fail
terminally with a machine code — no blind retries, but admin retry is always
available. An unconfigured provider fails honestly with
`PROVIDER_NOT_CONFIGURED`; the order form shows no deployable OS images until
an admin maps, tests and enables at least one image.

## 3. Customer server management

Client area **My servers** / **Server detail**: name, status, IP, OS +
version, region, provider, product, renewal date; actions **Start / Stop /
Reboot / Shutdown / Rescue / Console / Delete** — only those the provider
actually supports (capability-gated); **Reinstall OS** behind a destructive
warning + explicit confirmation, executed as a job (never inline); SSH key
management; provisioning history with per-stage logs. Every action is
ownership-checked (no IDOR) and audited.

## 4. Admin surfaces

- **OS Catalog**: OS CRUD (enable/disable/archive/delete-safe), per-OS
  versions with lifecycle (retire → EOL, archive), product compatibility rules.
- **OS Images**: provider image mappings with Edit / **Test image** / Enable /
  Disable / Delete. Test asks the live provider whether the image exists.
- **Infra Providers**: provider config (credentials sealed, masked), JSON
  endpoint map, capability toggles, health check, regions/datacenters.
- **Provisioning**: job journal with filters, per-stage logs, error codes,
  retry/cancel, "Run worker now", KPIs.
- **Settings → Servers & provisioning**: order/provisioning/actions/health/
  notification toggles, max attempts, poll interval, deadline.

## 5. Deploy / operate checklist

1. Enable the module (migrations 0001–0014 run; 0013 creates the
   infrastructure tables, 0014 seeds the 12-OS catalog — **no image mappings**,
   so nothing is deployable yet).
2. Set `CHS_CREDENTIALS_KEY` (32 random bytes, hex) **before** saving provider
   credentials — saving fails closed without it.
3. Admin → **Infra Providers**: add a provider (base URL, endpoint map,
   credentials JSON), add regions, run **Health**.
4. Admin → **OS Catalog**: adjust OS/version metadata if needed; per product,
   set a compatibility rule (server type, optional provider/region pinning).
5. Admin → **OS Images**: map OS versions to provider image/template ids
   (architecture + region), **Test image**, then **Enable**. Only enabled,
   tested images appear in the order form.
6. Cron: `modules/addons/cloudhost247services/cron/cloudhost247services.php`
   every 5 minutes (worker drain + provider sync + provider health checks).
7. Customer area: **My servers → Order a server**.

## 6. Honesty guarantees

- No fake provisioning: no fake servers, IPs or READY states. Unconfigured
  providers fail with `PROVIDER_NOT_CONFIGURED`; unavailable images with
  `IMAGE_UNAVAILABLE`; the UI shows the integration as unavailable.
- Never provision an unpaid server (verified at hook time and at execution).
- Provider credentials server-side only — never in frontend code, `public/`,
  git, DB seeds, logs or emails.
- EOL versions are blocked from new deployments; existing servers keep
  showing their current OS (or `UNKNOWN` when it cannot be determined — never
  invented).
- Migrations are additive; existing server/billing data is never destroyed.
- The development `FakeInfrastructureProvider` lives under `tests/` only — it
  is never registered in production and never mixes with real server data.

## 7. Tests

`node tests/run.mjs` (php-wasm, no native PHP needed) — suites 22–27 cover
the OS catalog, image resolution, provisioning (success, timeout, auth
failure, image unavailable, retry, worker restart, idempotency, health-check
failure, poll continuation, deadline), reinstall (authorized/unauthorized/
invalid OS/unavailable image/success/failure), server actions (incl. IDOR,
capability gating, admin retry/cancel, provider-state sync) and ordering
(product listing, configuration, validation, SSH keys, payment hook).
`node tests/lint.mjs` — syntax gate. Both must stay green.
