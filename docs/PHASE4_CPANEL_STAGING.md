# Phase 4 — cPanel/WHM staging validation runbook

**Status: prepared, not executed.** This is a procedure for a dedicated staging environment; no live WHMCS/cPanel host or credential was available or used in this repository session.

The purpose is to validate the **existing-account, staff-only** workflow from [`PHASE4_CPANEL_WORKFLOW.md`](PHASE4_CPANEL_WORKFLOW.md) before anyone considers enabling its switch. It does not authorize customer account creation, password delivery, or production use.

## 1. Safety boundary

- Use a cloned/test WHMCS installation, isolated database, dedicated cPanel/WHM staging host, and a disposable cPanel account created out-of-band by an authorized operator. Do not use a real customer service/account or production credentials.
- Record a restorable snapshot/backup of the staging WHMCS database and disposable cPanel account before testing. Have the account owner/operator approve the destructive termination case and recovery plan.
- Keep `customer_server_provisioning_enabled` **off** and `panel_account_workflow_enabled` **off** while installing/upgrading and configuring the fixture. Do not copy production secrets or customer data into staging.
- The workflow never creates the cPanel test account. Create and restore the disposable fixture manually through the staging panel or its approved process. No password is to be placed in the WHMCS API request, job, test log, audit metadata, or this report.
- Keep `verify_tls` enabled. Do not bypass certificate or hostname validation to make a test pass. Do not put a WHM hostname, token, account password, bearer token, or CSRF token in source control, issue/PR comments, shell history, or a shared report.
- Test termination only against the approved disposable account. Do not proceed if the WHM hostname, username, domain, package, WHMCS service, or client owner is ambiguous.

## 2. Prerequisites and configuration

1. Record the exact WHMCS version, PHP version/extensions, MySQL/MariaDB version, addon commit/version, and staging hostnames. Confirm they match the intended supported deployment.
2. Install/upgrade the addon on the isolated WHMCS clone. Migration `0011_create_panel_account_workflow` must apply additively. Confirm the new `panel_accounts` and `panel_account_events` tables and the `jobs.panel_account_id` / `events.panel_account_id` correlations exist; confirm older deployment, provider, catalog, billing, and audit rows remain intact. Do not manually drop or rewrite migration data.
3. Configure `CH247APPS_ENCRYPTION_KEY` using the staging secret manager. Register the staging WHM host in the existing server registry with a cPanel-capable server type, `cpanel_enabled` set, and an operator-enabled server state. Store the restricted WHM API token and username in the encrypted `CredentialVault` as `whm_api_token` / `primary`. Never accept host or token values from the panel-account API/job.
4. Verify DNS, outbound access to WHM's fixed HTTPS API endpoint on port 2087, a valid certificate chain/hostname, and the intended WHM token ACLs. Grant only the read and lifecycle operations necessary for the test. Do not disable TLS verification.
5. Prepare one existing, disposable cPanel account with a test domain and package. Confirm its username/domain/package exactly match the test WHMCS hosting service. Keep its credentials outside the test request and logs.
6. Prepare test WHMCS clients/services with an active service, same-owner order/invoice, and a paid linked invoice. Also prepare safe negative cases for unpaid/inactive service and a service-owner transfer. Use disposable staging records only.
7. Ensure the staging administrator has the required `panel_account.manage` / `panel_account.verify` grants; use the super-admin role for the approved termination case. The default `staff` role is intentionally read-only—do not broaden the global fallback just to run the test.
8. Keep both workflow settings off until setup and the disabled-gate case are ready. Confirm production flags/credentials are not in use.

## 3. Offline checks before staging

From `modules/addons/cloudhost247apps/` run:

```sh
npm test
npm run lint
```

At the time this runbook was written, these passed offline (`npm test`: 1,184 passed; `npm run lint`: 89 files, 0 bad). These harness tests use fake WHMCS/HTTP boundaries and are not staging evidence.

## 4. Staging test sequence

Use a fresh `Idempotency-Key` for each distinct operation. Capture sanitized response codes, job IDs, event IDs, and timestamps; never capture tokens, passwords, or CSRF values.

### A. Disabled feature and migration safety

1. Leave `panel_account_workflow_enabled` off. Submit one valid, cookie-authenticated staff binding request with CSRF and an idempotency key.
2. Expect a clear `PANEL_ACCOUNT_WORKFLOW_DISABLED` response. Confirm there is no new panel-account row, no control-panel job, and no WHM request.
3. Confirm customer-server provisioning remains off and existing unrelated rows were not changed.

### B. Authorization and WHMCS binding

1. With the feature still off, confirm customer list/bind/action requests are denied and unauthenticated requests require normal WHMCS/API authentication. Confirm cookie-authenticated writes reject invalid/missing CSRF.
2. On staging only, enable `panel_account_workflow_enabled` after the prerequisites above pass.
3. As authorized admin staff, bind the disposable existing account to its active, paid, owner-matched WHMCS service using `POST /v1/panel-accounts` and fields `service_id`, `server_id`, `username`, `domain`, and `package` only.
4. Expect HTTP `202`, a local `unverified` account mapping, and one `panel_account_verify` job on `control-panel`. The request must not call WHM synchronously. Confirm there is no accepted `client_id`, password, token, or other unlisted field.
5. Replay the same request with the same key and payload. It must return the original mapping/job, not create a duplicate. Reuse the key with changed fields and confirm a conflict. Try a second service/account mapping with a new key and verify the unique service/account constraints.
6. Confirm an unpaid invoice, inactive service, service/order/invoice owner mismatch, domain mismatch, unsupported server, disabled/maintenance server, or missing WHMCS service fails closed without creating a false active account. Use staging fixtures; do not alter production records to manufacture these cases.

### C. Dedicated worker and read-back

1. Start one worker pass from the WHMCS root:

   ```sh
   php -q modules/addons/cloudhost247apps/worker/worker.php --queue=control-panel --batch=2 --runtime=300 --once
   ```

2. Confirm the worker verifies the stored WHM token before account access when it is unverified, reads the account from the registered WHM host, and completes the job only after the allowlisted WHM response matches username, domain, package, and suspension state.
3. Confirm the local state reflects only WHM read-back (`active` or `suspended`), the pending job reservation is cleared, and the job result contains no credentials.
4. Change the owner of a disposable WHMCS test service before its queued worker executes. Confirm the worker fails before account access/mutation and does not update the mapping to an active state. Restore the fixture through WHMCS after the case.
5. On a disposable mismatched account fixture, verify that a domain/package mismatch fails before suspend, unsuspend, or terminate is called and marks the mapping for operator reconciliation.

### D. Lifecycle gates and destructive case

1. **Suspend:** set the linked staging WHMCS service to `Suspended`, request the staff suspend action with an idempotency key, and run the `control-panel` worker. Confirm WHM suspension and a subsequent matching read-back before local state becomes `suspended`. An active service must not pass the suspend gate.
2. **Unsuspend:** set the linked service to `Active` with its linked invoice still paid, request unsuspend, and run the worker. Confirm matching WHM read-back before local state returns to `active`. An unpaid invoice, inactive service, or changed owner must block the operation.
3. **Terminate:** use only the pre-approved disposable staging account after its WHMCS service is `Cancelled` or `Terminated`. Confirm an ordinary admin cannot terminate, a wrong confirmation phrase fails, and the exact `TERMINATE <username>` phrase is not persisted in the job/history. Run the worker, confirm WHM reports the account absent, and only then accept local `terminated` state.
4. If termination cannot be safely authorized or the account identity cannot be proven, stop; do not substitute another account or test on production. Restore/recreate the disposable staging fixture only through the separately approved process.

### E. Failure, audit, and secrecy checks

- With staging-only fixtures, test expired/missing/invalid token, TLS/hostname failure, negative WHM response, network timeout, worker retry, wrong queue, and WHM state-read-back mismatch. None may create a false success; terminal failures must clear the reservation or leave a clearly reported reconciliation condition.
- Verify an operator-disabled/maintenance WHM server is rejected. Verify the worker never trusts a host, token, client ID, confirmation phrase, or panel-account ID from an arbitrary request/job payload.
- Inspect the audit chain, panel-account history, queue result, and redacted HTTP/worker logs. Confirm no token, account password, raw WHM response body, or termination phrase appears. Confirm all mutation attempts have a write-ahead adapter audit event and confirmed/failed outcome.
- Confirm customer roles cannot list or access panel-account records/jobs and read-only staff cannot bind or mutate. Confirm action permissions remain separated (`panel_account.verify`, `.manage`, `.terminate`).

## 5. Evidence record and go/no-go

Keep a staging-only report with:

| Field | Record |
|---|---|
| Run date, operator, change/commit | |
| WHMCS, PHP, MySQL/MariaDB, addon versions | |
| Sanitized WHM hostname, cPanel/WHM version, TLS result | |
| Migration `0011` result and backup/restore reference | |
| Workflow flag state during tests | |
| Test case, expected result, observed result | |
| Sanitized HTTP status/error code, job/event IDs, timestamps | |
| Audit/history/log secrecy review | |
| Termination approval and disposable-account recovery result | |
| Deviations, failures, remediation, retest | |

Do not attach credentials, customer data, full account details, or raw request/response bodies. Keep the report in the approved private change-management system; a public PR should contain only a redacted outcome summary.

**Go/no-go:** all required positive and negative cases must pass, backups/recovery must be demonstrated, and an operator/security review must sign off. A passing staging report is necessary but not sufficient for production enablement; turn the staging workflow flag off after the test/cleanup and keep the production workflow flag off until a separate change approval. Customer account creation remains unavailable until its password/SSO handoff is separately reviewed. UAPI, additional panels, licensing, and customer-facing panel operations are outside this staging scope.

## Current result

This checkout contains no staging host, WHMCS clone, WHM token, or cPanel account, so no live staging test was performed in this session. The runbook is a prepared procedure only; do not report staging or production success from the offline test suite.
