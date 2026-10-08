# Phase 5 — cPanel UAPI domain inventory

**Status:** The read-only adapter operation, staff-only queue path, WHMCS admin page, validation, and offline tests are implemented. The feature remains disabled by default. No live UAPI/cPanel staging test was performed; this is not an operational-readiness or production claim.

**Release target:** WHMCS addon version `1.4.0`; this slice adds no database migration and uses the existing queue, panel-account mapping, WHM credential vault, and audit tables.

**Capability selected for Phase 5:** list the domains associated with an already-linked cPanel account, using cPanel UAPI `DomainInfo::list_domains`. Other UAPI functions, additional panels, customer-facing panel actions, and application deployment into cPanel accounts remain out of scope.

## Boundary and authorization

- This uses the existing `CpanelWhmAdapter` and WHM API token from the registered server and encrypted `CredentialVault`. The adapter invokes WHM's fixed `uapi_cpanel` bridge as the **username from the stored panel-account mapping**; it does not accept a hostname, username, module, function, or token from the HTTP request or queue payload.
- Only the exact `DomainInfo::list_domains` module/function is permitted by this application through the UAPI bridge. The official WHM `uapi_cpanel` contract specifies `GET` with `cpanel.user`, `cpanel.module`, `cpanel.function`, and operation parameters in the query string; the implementation follows that shape at the fixed HTTPS WHM API 1 endpoint on port 2087. TLS verification stays mandatory, the token remains in the Authorization header, and raw response bodies are redacted.
- WHM's `uapi_cpanel` permission is a generic proxy permission, not proof that the WHM token is restricted to this one UAPI function or username. The application allowlist is defense-in-depth, not a substitute for token ACL review; keep the feature off unless the token's total scope is acceptable and the bridge permission can be constrained appropriately.
- A staff member with `panel_account.view` may queue the read through the API or the WHMCS addon admin page (`action=panel_accounts`). The page only queues domain reads; it exposes no account lifecycle controls. Requests must pass the existing server-enabled and WHMCS-owner checks. The worker rechecks queue correlation, service ownership/status, mapped username/domain/package, and WHM account state before invoking UAPI. A primary-domain mismatch fails and is marked for reconciliation.
- The job runs only on the existing `control-panel` worker queue. It is serialized against other jobs for the same panel account. The HTTP request never contacts WHM synchronously.
- Customers cannot list panel accounts or read these jobs. Results are returned only through the staff-authorized `GET /v1/jobs/{id}` route or the WHMCS admin page, which verifies the job type, queue, panel-account id, WHMCS service, and client before rendering a filtered domain projection. The inventory is stored in the job result subject to normal job retention; account-event/audit metadata stores minimal operation context (the mapped username, expected/confirmed primary domain, status, and domain count), never the full domain list.
- No domain document roots, email accounts, databases, SSL state, passwords, tokens, raw UAPI messages, or account-creation actions are exposed.

## API and feature gate

```http
POST /v1/panel-accounts/{panel_account_id}/domains
Idempotency-Key: <unique key>
X-CSRF-Token: <normal WHMCS session token when using cookie authentication>
Content-Length: 0
```

The API request accepts no body fields and returns `202` with a queued job. Once complete, staff can read the bounded, allowlisted domain projection from `GET /v1/jobs/{job_id}`. The WHMCS admin page is available at `addonmodules.php?module=cloudhost247apps&action=panel_accounts`; it lists linked accounts, offers only the read-only queue action, and shows a result only when the selected job is bound to that account, service, client, queue, and job type.

The independent WHMCS addon setting `cpanel_uapi_domains_enabled` defaults to **off**. Keep it off until the dedicated UAPI staging checks below pass and the operator approves enablement. It does not enable the Phase 4 lifecycle workflow, account creation, or any customer-facing feature. The Phase 4 `panel_account_workflow_enabled` switch also remains off by default.

## UAPI response handling

The adapter requires a successful nested UAPI result (`data.uapi.status`), no errors or warnings, and the documented `main_domain`, `addon_domains`, `parked_domains`, and `sub_domains` fields. It validates each domain name, rejects conflicting duplicates and malformed/partial responses, and returns only `{domain, type}` pairs plus the primary domain and count. Temporary domains are excluded using `hide_temporary_domains=1`.

The cPanel API documentation notes that some permission failures may return blank output fields without an error. Therefore a null/blank primary domain, a primary domain that does not match the stored account binding, or any missing domain-list field is a failure—not an empty successful inventory. No raw vendor error or response body is surfaced.

## Validation and readiness

Offline tests cover the fixed proxy operation, account scoping, TLS/token placement, allowlisted response projection, nested UAPI errors, blank-permission responses, domain-binding mismatches, staff/customer authorization, the staff admin page and its result scoping, idempotency, worker-queue isolation, and audit/job-result boundaries. These fakes do **not** establish live cPanel compatibility.

Before enabling the feature in staging:

1. Use an isolated WHMCS clone and dedicated cPanel/WHM staging host; do not use production or customer data.
2. Keep both `panel_account_workflow_enabled` and `cpanel_uapi_domains_enabled` off during setup. Use an existing, disposable account already linked to the matching WHMCS service.
3. Review the WHM API token's full ACL. It needs the Phase 4 operations plus `uapi_cpanel`, whose documented `cpanel.user` parameter makes the bridge generic; do not assume it is function- or account-scoped. Constrain the ACL as far as WHM allows, obtain security approval for the remaining scope, and verify the registered hostname/certificate with TLS verification on.
4. Run `npm test` and `npm run lint` from `modules/addons/cloudhost247apps/`.
5. Enable only the UAPI feature in staging, queue the domain read as read-only staff, and confirm the HTTP request is asynchronous and the worker returns the exact main/addon/parked/subdomain projection.
6. Confirm a missing UAPI permission or blank result fails closed; mismatched main domain, changed WHMCS owner, disabled/maintenance server, invalid token, TLS failure, and UAPI warnings/errors must never become a successful empty list.
7. Confirm customers cannot queue/read the operation, the result contains no extra UAPI fields, the account-event/audit logs contain no domain list or token, and the HTTP recorder does not retain the raw response.
8. Record sanitized results privately. Turn the staging switch off after testing; keep production disabled until separate review and approval.

Official contract references (checked against cPanel documentation version 11.138.0.10; the offline review does not replace staging): [UAPI `DomainInfo::list_domains`](https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-list_domains.md) and [WHM API 1 `uapi_cpanel`](https://api.docs.cpanel.net/specifications/whm.openapi/api-execution/cpanel-uapi_cpanel.md).

## Current result

No live WHMCS/cPanel environment, token, or account was available for this change. Offline implementation and tests are not staging evidence. The setting remains off, and the Phase 4 staging runbook [`PHASE4_CPANEL_STAGING.md`](PHASE4_CPANEL_STAGING.md) remains unexecuted.
