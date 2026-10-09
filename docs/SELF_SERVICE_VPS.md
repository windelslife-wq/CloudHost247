# Self-service VPS provisioning — first safe slice

**Code status:** implemented, **disabled by default**; offline tests only. App Cloud `1.10.0` / migration `0012_create_server_product_mappings`. This is a *paid-service provisioning request*, **not** a replacement WHMCS checkout, a product configurator, or automatic post-payment provisioning. The `index.php?m=cloudhost247apps&action=vps` client page links mapped products to the existing WHMCS cart and lists the customer's mapped hosting services with a request button. The customer must first order a recurring WHMCS **server** product via the normal WHMCS cart and pay its linked invoice. No independent checkout or `InvoicePaid` auto-create hook is added.

## Operator setup (staging before production)

1. Upgrade the App Cloud addon and migrate additively. Confirm a real provider adapter is registered and a provider account has passed its credential-verification worker job. A catalog entry alone cannot be used.
2. Configure a recurring WHMCS `server` product in WHMCS, with its **real** price and billing cycle. Do not set up a second invoice or amount in App Cloud. Make sure no other WHMCS auto-setup/provisioning module creates a VM for the same product.
3. As an authorized App Cloud administrator (`plan.manage` **and** `provider_account.manage`), POST to `/modules/addons/cloudhost247apps/api/index.php?path=/v1/server-product-mappings` with WHMCS-session CSRF token (or a bearer token with **both** scopes), `Idempotency-Key`, and JSON:

   ```json
   {"product_id": 123, "provider_account_id": 4, "spec": {"region": "fsn1", "image": "ubuntu-24.04", "cpu_cores": 2, "memory_mb": 2048, "storage_gb": 40}, "enabled": true}
   ```

   Start with `"enabled": false` until the account and product are reviewed. The same route with `GET` lists operator mappings; customer roles cannot access it. A product has exactly one current mapping. The name is derived as `vm-service-<WHMCS service id>`; hostname, provider, and VM size are never taken from the customer's request. Mapping changes are audited.
4. Keep **both** `customer_server_provisioning_enabled` and the separate `customer_server_self_service_enabled` off until the target WHMCS/MySQL version, paid/unpaid invoice cases, product ownership, real provider capabilities/region/image/size and worker retry behavior pass staging review. Enable both only with operator approval and a supervised `--queue=provisioning` worker.

## Customer API

A signed-in **owner** with `customer_server.order` can POST `/v1/servers/self-service` with **only** `{"service_id": 456}`, an `Idempotency-Key`, and session CSRF token. Bearer tokens additionally need `customer_server.order` scope. No invoice ID, client ID, provider account, server size or credentials are accepted. The server checks the service's WHMCS product, ownership, order/invoice relationship and current paid state through WHMCS, the enabled mapping, verified provider account, and both feature switches before queueing one provider create per service. The queue worker rechecks payment and operator mapping immediately before calling the provider. If the mapping is revoked or changed before creation, the job fails without a provider call. After a create starts, disabling a mapping does **not** delete an already-created VM; follow the normal service cancellation/termination process.

For an already-provisioned service, use the authenticated `GET /v1/servers` or `GET /v1/servers/{id}` routes. Customer lifecycle writes, resizing, a custom checkout, panel installation and licensing remain outside this slice. A new WHMCS order is not created by this API; product links on the client page go to WHMCS `cart.php`, which remains authoritative for prices and checkout. The request button uses a same-origin JSON API call, session CSRF, and a per-page idempotency key. The page never exposes the operator's provider account or credentials. Enabling these flags without live staging evidence is unsupported.

## Offline checks

From `modules/addons/cloudhost247apps/`: `npm ci --ignore-scripts && npm test && npm run lint`. `tests/16_SelfServiceVpsTest.php` uses an in-memory WHMCS gateway and provider transport with no real infrastructure calls. This cannot prove production WHMCS behavior or a real provider integration.

## DigitalOcean provider note

The reviewed DigitalOcean Droplet adapter is now registered for the narrow create/read/delete/power/reboot slice, but was validated only against offline HTTP fakes. Its `public_config` must contain a verified `ssh_key_id` and an operator-selected `size_slug` that exactly matches the approved product specification. Do not enable either self-service switch merely because the adapter appears in the catalog; follow the live-provider and ambiguous-create reconciliation checklist in [`DIGITALOCEAN_ADAPTER_READINESS.md`](DIGITALOCEAN_ADAPTER_READINESS.md). Rebuild, resize, snapshots and metrics are not advertised.

## Vultr provider note

The narrow Vultr adapter is registered but has only offline fake-HTTP tests. It needs an operator-selected `plan_id`, an account SSH key UUID and a product mapping whose `image` is an x64 Ubuntu/Debian OS ID. Do not enable customer provisioning solely because it appears in the catalog; complete the live-provider and ambiguous-create reconciliation gate in [`VULTR_ADAPTER_READINESS.md`](VULTR_ADAPTER_READINESS.md). Rebuild, resize, snapshots and metrics are not advertised.

## Contabo adoption is separate from self-service create

The registered Contabo adapter is **read-only** (`server.get` only). It cannot fulfil a customer VPS create request, purchase a Contabo contract, or cancel one. An operator may link an already-existing paid Contabo instance to an active WHMCS service only through the separately default-off `/v1/contabo/adoptions` workflow. That record is staff-only and is not a customer-active VM. See [`CONTABO_ADAPTER_READINESS.md`](CONTABO_ADAPTER_READINESS.md) for the required provider/WHMCS staging gate.
