# Phase 7 — cPanel disk and inode quota-usage snapshot

- **Status:** offline implementation only; not staging-tested, not production-approved
- **Module version:** `1.6.0`
- **Default:** disabled (`cpanel_uapi_quota_usage_enabled` defaults off)

Phase 7 adds one read-only, staff-only, account-scoped and queued cPanel UAPI operation: a point-in-time retrieval of the linked account's reported disk and inode quota counters. This is not continuous monitoring, alerting, enforcement, or an independent measurement of filesystem usage. No latest-snapshot table or background polling schedule is introduced; the normalized values remain in the ordinary queued-job result and follow existing job retention.

## Vendor contract and normalization

The cPanel adapter calls the fixed WHM API 1 `uapi_cpanel` bridge with `Quota::get_quota_info` as a GET. The only UAPI fields accepted by the client are `cpanel.user`, `cpanel.module=Quota`, and `cpanel.function=get_quota_info`. The username comes from the worker's mapped panel-account row; the request body and all caller-supplied UAPI arguments are absent.

The adapter projects only these documented numeric values when present:

- Disk: `megabytes_used`, `megabyte_limit`, `megabytes_remain`.
- Inodes: `inodes_used`, `inode_limit`, `inodes_remain`.
- Optional booleans: `under_megabyte_limit`, `under_inode_limit`, `under_quota_overall`.

All six disk/inode numeric fields are required for a complete snapshot. Values are validated and represented as bounded, nonnegative decimal strings (inode values as integer strings); large JSON integers are kept as strings. No arithmetic, percentage, or capacity classification is applied. Optional status flags are normalized to booleans. Unknown response fields are ignored. Missing fields, malformed values, UAPI errors, or any warnings fail closed rather than producing a successful partial or empty snapshot.

**Important vendor ambiguity:** cPanel documents that a zero quota limit or remaining value can mean that quotas are unlimited or disabled on the server; zero used values can mean either no usage or disabled quotas. The implementation preserves zero as reported and does not infer quota state. The admin page repeats this warning and does not calculate percentages, threshold status, or “unlimited” labels.

Official reference: [cPanel UAPI — `Quota::get_quota_info`](https://api.docs.cpanel.net/specifications/cpanel.openapi/disk-quotas/quota-get_quota_info.md) (documentation version 11.138.0.10). The operation is executed through the WHM `uapi_cpanel` proxy; application-side allowlisting is defense-in-depth and does not constrain the full scope of a WHM token that has generic proxy permission.

## Authorization and execution path

Only an existing `cpanel_whm` mapping whose panel account is verified active or suspended and whose linked WHMCS service remains active or suspended can request a snapshot. The worker confirms the current WHM account matches the mapped username, main domain, and package before querying quota usage. It rechecks service ownership/state and mapping, verifies the credential/server scope, and honors cancellation before the external operation. Each linked account can have only one queued panel action at a time.

- **Feature gate:** `cpanel_uapi_quota_usage_enabled`, independent of the lifecycle, domain-inventory, and alias-inventory gates; default off and rechecked by the worker.
- **RBAC:** staff require `PANEL_ACCOUNT_VIEW`; customers cannot queue or read the snapshot.
- **HTTP:** `POST /v1/panel-accounts/{id}/quota-usage` accepts no body fields, requires an `Idempotency-Key`, and returns `202` with the queued job. Staff can read the completed result through `GET /v1/jobs/{jobId}`.
- **Admin:** the CSRF-protected `action=panel_accounts` page exposes the queue button only when the gate is enabled and the account is eligible. It validates job type, control-panel queue, account/client/service bindings, result schema, and the full numeric/boolean projection before rendering.
- **Queue:** `panel_account_quota_usage_read` on the existing `control-panel` queue. Queue correlation, action, panel-account id, client id, and WHMCS service id are rechecked by the existing worker and service workflow.
- **Audit:** low-level UAPI records use `CPANEL_UAPI_QUOTA_USAGE_*`; successful account completion uses `PANEL_ACCOUNT_QUOTA_USAGE_READ`, history event `quota_usage_snapshot_read`, and event hook `panel_account.quota_usage_read`. Lifecycle/audit metadata contains mapping context and reported field count/names, not quota values. Quota values are present only in the staff-authorized job result.
- **Transport:** uses the registered server and encrypted credential vault, worker-only WHM token access, fixed HTTPS endpoint, mandatory TLS verification, Authorization-header token placement, and existing redaction/no-redirect behavior. No live call is made by the HTTP request.

No database migration is required. The snapshot is not written back to the account mapping as a continuous status or as a new account field.

## Offline validation and rollout gate

Automated tests use only fake WHM/UAPI, WHMCS, and worker boundaries. They cover the fixed GET operation and exact parameter allowlist, account scoping, output projection, decimal/zero preservation, malformed or incomplete values, UAPI warning/error behavior, staff/customer access, default-off and worker-time feature gates, idempotency, queue routing, audit/event metadata boundaries, and admin rendering. This is offline code-path evidence only; it does not establish live cPanel compatibility, effective WHM token scope, or staging readiness.

Before enabling this feature in any environment:

1. Use an isolated WHMCS clone and non-production cPanel/WHM account; keep this gate off during setup.
2. Review the full WHM API token ACL. Verify the exact `uapi_cpanel` permission and account-level access; do not assume the application allowlist narrows a generic WHM proxy permission. Obtain security approval for the token's complete scope.
3. Confirm the host binding, valid TLS certificate, WHM API token placement, and absence of redirects with TLS verification enabled.
4. Run the module test and lint suites offline, then conduct staging cases for success, zero/unlimited-or-disabled ambiguity, warning, permission denial, malformed/missing quota values, inactive/mismatched account, stale WHMCS owner, cancellation, timeout, and credential failure.
5. Confirm HTTP requests only enqueue jobs; worker execution observes the separate gate; idempotent retries do not enqueue duplicate work; customers cannot access results; and logs/audits contain no token, raw UAPI body, or metric values.
6. Record sanitized staging evidence and operational approval outside this implementation. Keep the setting off until review is complete; no production enablement is implied here.

No live cPanel call, staging run, production review, or feature enablement was performed for this implementation. The Phase 4 staging runbook remains unexecuted, and the Phase 5/6 UAPI features retain their own independent gates.
