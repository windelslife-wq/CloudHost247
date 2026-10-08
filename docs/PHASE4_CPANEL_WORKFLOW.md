# Phase 4 follow-up — WHMCS-bound cPanel account workflow

**Status:** the service-bound existing-account workflow and dedicated queue/worker path are implemented. The workflow is **disabled by default** and is not a production cPanel integration claim.
**Release:** WHMCS addon version `1.3.0`; migration `0011_create_panel_account_workflow` is additive.
**Safety gate:** no live cPanel server or credential was used. Keep `panel_account_workflow_enabled` off until the dedicated WHM staging checks in [`PHASE4_CPANEL_STAGING.md`](PHASE4_CPANEL_STAGING.md) pass.

## Scope and source-of-truth rules

- `panel_accounts` binds one **already-existing** cPanel account to exactly one WHMCS `tblhosting` service. The WHMCS service supplies the client owner; a caller cannot submit an arbitrary owner ID.
- Linking requires the service to be active, its order and invoice to belong to the same WHMCS client, WHMCS to confirm the invoice is paid, the cPanel account domain to match the service domain when present, and the selected registered server to be enabled for cPanel.
- Link requests make no WHM call. They create an `unverified` local mapping and enqueue `panel_account_verify` on the separate `control-panel` queue.
- `PanelAccountWorker` rechecks the live WHMCS service owner/state and queue/service/client correlations before it resolves the registered server and encrypted WHM API token. On the first operation after credential rotation, it verifies the token before account access.
- Only vendor-confirmed WHM read-back updates the local `active`, `suspended`, `missing`, or `terminated` state. A username/domain/package mismatch fails closed and marks the mapping for operator reconciliation.
- Panel jobs have their own `panel_account_id`; they never overload deployment `server_id` or `customer_server_id`. The shared job lease serializes work per panel account.
- Writes have idempotency keys, write-ahead adapter audit records, panel-account lifecycle history, and sanitized queue presentations. Termination requires exact `TERMINATE <username>` confirmation; the phrase is validated at request time and is not stored in the queue payload.

## Staff-only API and worker

These routes use the existing WHMCS API authentication, RBAC, CSRF, and idempotency checks. Customers cannot list, bind, or operate panel accounts.

| Method and route | Effect |
|---|---|
| `GET /v1/panel-accounts` | List sanitized service bindings; requires `panel_account.view`. |
| `POST /v1/panel-accounts` | Link an existing account and enqueue verification; requires `panel_account.manage` and `Idempotency-Key`. Fields: `service_id`, `server_id`, `username`, `domain`, and `package`. Password/credential fields are rejected. |
| `GET /v1/panel-accounts/{id}` | Read one sanitized mapping; requires `panel_account.view`. |
| `POST /v1/panel-accounts/{id}/actions` | Queue `verify`, `suspend`, `unsuspend`, or `terminate`; requires `panel_account.verify`, `panel_account.manage`, or the restricted `panel_account.terminate` permission for the selected action, plus an idempotency key. |
| `GET /v1/jobs/{id}` | Admin/staff job status, authorized through `panel_account.view`. |

Run a dedicated consumer after WHMCS activation/upgrade and worker configuration:

```sh
php -q modules/addons/cloudhost247apps/worker/worker.php --queue=control-panel --batch=2 --runtime=300
```

Lifecycle gates are rechecked in the worker: suspension follows a suspended/cancelled/terminated/fraud WHMCS service; unsuspension requires an active service and a paid linked invoice; termination requires a cancelled or terminated service. Queueing a request is not proof that WHM accepted it.

## Explicitly unavailable

- This `PanelAccountService` and API expose no customer-account creation request or `panel_account_create` queue job. The pre-existing low-level `ControlPanelService::createAccount` adapter entry is worker-only and is not dispatched by this workflow. Do not call it for customer provisioning until a reviewed encrypted-secret handoff or SSO design exists.
- No password is generated, stored, returned, logged, or put in a job. No cPanel login/SSO flow, customer account dashboard/action, WHMCS product-to-package entitlement mapping, panel installer, license service, application deployer, or UAPI capability is added.
- This workflow handles an administrator linking an existing account. It does not create, order, price, provision, or license a product.
- `panel_account_workflow_enabled` defaults to `0`; the admin configuration field also defaults off. Enabling it without real WHM staging validation is unsupported.
- Offline tests use a fake adapter and fake WHMCS gateway. They do not prove live WHMCS behavior, production MySQL migrations, or real cPanel API compatibility.

## Validation

From `modules/addons/cloudhost247apps/`:

```sh
npm test
npm run lint
```

Suite `tests/09_PanelAccountWorkflowTest.php` covers the WHMCS paid-service gate, staff-only access, CSRF/idempotency, worker owner rechecks, account read-back/state transitions, exact termination confirmation, queue separation, and the absence of account creation. Final offline totals are recorded in [`HOSTING_CONTROL_PLANE_AUDIT.md`](HOSTING_CONTROL_PLANE_AUDIT.md); they do not replace a live staging test.
