# Phase 6 — cPanel built-in primary-domain aliases

**Status:** offline implementation only; not staging-tested, not production-approved

**Module version:** `1.5.0`

**Default:** disabled (`cpanel_uapi_aliases_enabled` defaults off)

Phase 6 adds one narrow, read-only cPanel UAPI operation for built-in subdomain aliases associated with the primary domain of an already-linked cPanel account. It is not a general UAPI proxy, DNS-management feature, domain-ownership check, or complete alias/domain inventory.

## Vendor contract and scope

The adapter invokes WHM API 1's fixed `uapi_cpanel` bridge using the documented cPanel UAPI function `DomainInfo::main_domain_builtin_subdomain_aliases`, as a GET for the linked account's WHM username. `hide_temporary_domains=1` is sent. The official contract describes an array response (with `mail` and `www` as example labels) and warns that actual output may omit documented returns. Therefore:

- A successful result must include a valid UAPI status and an array in `data`; an omitted or malformed `data` field fails closed rather than becoming an empty list.
- A valid, explicitly empty array is returned as **`vendor_reported`**, with count zero. The UI never calls it a complete alias or DNS inventory.
- Returned alias labels are normalized to primary-domain-scoped names and checked against the WHMCS-bound primary domain. Duplicates, malformed names, and out-of-scope names fail closed.
- Results are bounded to 1,000 aliases and only include an allowlisted `{alias, domain}` projection.
- The operation is separately allowlisted in `CpanelWhmClient`; callers cannot change its module/function or use the bridge for arbitrary UAPI methods.

Official reference: [cPanel UAPI — `DomainInfo::main_domain_builtin_subdomain_aliases`](https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-main_domain_builtin_subdomain_aliases.md). The integration also relies on the WHM `uapi_cpanel` response envelope described in [`PHASE5_CPANEL_UAPI_DOMAINS.md`](PHASE5_CPANEL_UAPI_DOMAINS.md).

## Authorization and execution path

This capability is available only for an existing `cpanel_whm` panel-account mapping whose account is verified active or suspended and whose linked WHMCS service remains active or suspended. It runs through the dedicated control-panel worker; no admin or API request directly calls WHM.

- **Feature gate:** `cpanel_uapi_aliases_enabled`, independent of the Phase 5 `cpanel_uapi_domains_enabled` switch and off by default.
- **RBAC:** staff require `PANEL_ACCOUNT_VIEW`; customers have no route to the result.
- **HTTP:** `POST /v1/panel-accounts/{id}/domain-aliases`, empty request body, required `Idempotency-Key`; returns a queued job. Staff can read it through the existing `GET /v1/jobs/{jobId}` endpoint.
- **Admin:** `action=panel_accounts` exposes a CSRF-protected queue button only while this gate is enabled and the linked account/service are eligible. Completed results are validated again before rendering.
- **Queue:** `panel_account_domain_aliases_list` on the existing `control-panel` queue; job type, payload, linked panel-account ID, client and WHMCS service scope are checked by the existing worker.
- **Audit:** low-level request/success/failure records use `CPANEL_UAPI_ALIASES_*`; account completion uses `PANEL_ACCOUNT_ALIASES_LISTED`, history event `domain_aliases_listed`, and event hook `panel_account.domain_aliases_listed`. Audit/history metadata stores a bounded count and mapping context, not the alias list.
- **Transport:** uses the registered cPanel server and credential vault, worker-only WHM token access, TLS verification, fixed HTTPS WHM API endpoint, response redaction, no redirect following, and no secret in URLs/log metadata.

## Validation and production gate

Automated coverage uses fake HTTP, WHMCS and worker boundaries only. It verifies the fixed UAPI call, malformed/omitted response handling, explicit empty-list semantics, output normalization, account binding, staff authorization, feature gating, idempotency, queue routing, audit/event behavior and admin rendering. It does **not** prove real cPanel permissions or staging behavior.

Before anyone enables this capability, execute and retain a separate cPanel staging review covering at least:

1. WHM token ACLs and `uapi_cpanel` permission for the exact target account; confirm read-only scope and denial cases.
2. Real response envelopes for success, no aliases, permission denial, warning, missing documented returns, and temporary-domain behavior.
3. TLS certificate validation, host binding, auth failures, timeout/retry behavior and no-follow redirects.
4. Primary-domain/account binding, malformed or out-of-scope value handling, and the 1,000-entry safety limit.
5. Log/audit redaction and staff-versus-customer result access.
6. WHMCS admin rendering, CSRF, queue processing, retries and idempotent replay against a non-production panel.

Do not enable `cpanel_uapi_aliases_enabled` until that evidence is reviewed. No live cPanel call or staging run was performed for this implementation. Phase 4 lifecycle, Phase 5 domain inventory, provider provisioning, customer account creation, password/SSO delivery, DNS writes, panel installation and licensing remain outside this slice and keep their existing gates.
