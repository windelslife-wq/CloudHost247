# Phase 3 — Control-panel catalog and marketplace

**Status:** metadata-only catalog implemented in the existing CloudHost247 App Cloud addon.
**Date:** 2026-10-08.
**Phase 2 status:** the project owner confirmed the external WHMCS/MySQL and real-provider validation gate passed on 2026-10-08. The evidence is not in this repository, whose source still has no production provider adapter; customer-VM provisioning therefore remains disabled. The earlier waiver authorized Phase 3 only; the separate owner confirmation is recorded in [`PHASE2_INFRASTRUCTURE_PROVISIONING.md`](PHASE2_INFRASTRUCTURE_PROVISIONING.md).

## Scope and boundaries

Phase 3 adds catalog records and read-only presentation. It does **not** add a control-panel installer, provider adapter, license service, purchase/checkout flow, or customer panel gateway. Publishing a catalog record means that an administrator approved its metadata for display only. Every panel and plan is returned with `deployable: false` and `orderable: false`. Administrators may select only the descriptive `not_implemented`, `metadata_only`, or `planned` integration/installation statuses; no “available”, “installed”, or equivalent success state is accepted.

CloudHost247 App Cloud remains the WHMCS addon boundary. WHMCS remains authoritative for customer identity, products, prices, orders, invoices, and hosting services. A panel plan may point at a real WHMCS product and billing cycle, but App Cloud stores no local price and exposes no product ID or checkout action to customers. Existing application categories, applications, and hosting plans are unchanged.

## Schema

The WHMCS addon version is `1.1.0`, so an existing `1.0.0` installation receives the idempotent additive migration `modules/addons/cloudhost247apps/install/migrations/0010_create_panel_catalog_tables.php` through WHMCS's upgrade callback. It creates:

- `panel_categories` — administrator-managed name, slug, description, sort order, and active flag.
- `control_panels` — category, vendor and official-source URLs, catalog status, separately configurable non-operational integration/installation status, license-model/terms metadata, supported operating systems, minimum CPU/memory/storage requirements, resource notes, and an allowlisted capability-flag map.
- `control_panel_plans` — per-panel resource tiers, catalog status, WHMCS product ID, and billing-cycle reference. No local amount/currency is stored.

Module-owned foreign keys connect categories, panels, and plans. The WHMCS product ID is a checked reference through the existing `GatewayInterface`, not a foreign key into WHMCS core. No catalog rows are seeded or invented.

## Admin and customer surfaces

- **Admin manager:** WHMCS addon page `addonmodules.php?module=cloudhost247apps&action=panel_catalog`. It uses the existing server-resolved App Cloud actor, `panel_catalog.manage` RBAC permission, and session-bound CSRF checks. Administrators can create/update categories, panels, and plans and move records between draft, published, and retired. Published records require an active category and an HTTPS official-source URL. WHMCS product references are checked against the existing gateway.
- **Customer catalog:** WHMCS client-area module route `index.php?m=cloudhost247apps`, rendered by `templates/client/control-panels.tpl`. It is read-only, displays only published panels in active categories, and nests only published plans. It has no order, install, provision, or license-activation buttons.
- **Audit/RBAC:** privileged catalog writes are recorded in the existing hash-chained audit table. Customers, staff, and guests may view published metadata; admin and super-admin roles may manage it. The default unmapped-admin role remains `staff`.

## Safety and future integrations

- `integration_status` and `installation_status` may be recorded as `not_implemented`, `metadata_only`, or `planned`; the service rejects success-like states such as `available` or `installed`. `deployable` is always forced to `0`, independently of catalog publication or descriptive status.
- Product capability flags describe catalog metadata only. They do not advertise CloudHost247 operations as implemented.
- License model, terms, and URLs are metadata only. No key is stored and no license provider is called.
- A future real integration requires a separate reviewed control-panel adapter/registry and contract tests. Only then may availability be represented as deployable; it must not be inferred from catalog publication.
- Phase 2's external validation was owner-confirmed passed, but the provider-provisioning kill switch remains disabled because this checkout has no production provider adapter. The Phase 4 cPanel/WHM adapter and WHMCS-bound existing-account workflow are tracked separately in [`PHASE4_CPANEL_ADAPTER.md`](PHASE4_CPANEL_ADAPTER.md) and [`PHASE4_CPANEL_WORKFLOW.md`](PHASE4_CPANEL_WORKFLOW.md).

## Validation

Run from `modules/addons/cloudhost247apps/`:

```sh
npm test
npm run lint
```

The offline suite exercises the actual migration on SQLite, checks MySQL DDL foreign-key signedness, tests admin RBAC and validation, verifies WHMCS product references, and asserts that customer output remains non-deployable/non-orderable. These tests do **not** replace live WHMCS/MySQL staging validation or a real provider/panel integration test.
