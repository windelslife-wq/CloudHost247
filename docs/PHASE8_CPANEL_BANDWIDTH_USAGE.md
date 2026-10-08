# Phase 8 — cPanel bandwidth-usage snapshot

- **Status:** offline implementation only; not staging-tested, not production-approved
- **Module version:** `1.7.0`
- **Default:** disabled (`cpanel_uapi_bandwidth_usage_enabled` defaults off)

Phase 8 adds one narrow read-only operation for a linked cPanel account: a queued, point-in-time request for the documented `bandwidthusage` StatsBar item. It is not a monitoring service, periodic poller, alerting or enforcement feature, independent usage measurement, or general StatsBar interface. No snapshot table or background schedule is added; data remains in the ordinary queued-job result and follows the existing queue retention policy.

## Vendor contract and output projection

The adapter calls the WHM API 1 `uapi_cpanel` bridge using GET and the fixed UAPI operation `StatsBar::get_stats`. The only accepted fields are the worker-derived `cpanel.user`, `cpanel.module=StatsBar`, `cpanel.function=get_stats`, and the literal `display=bandwidthusage`. The application does not accept a display list or expose any other StatsBar item.

A successful response must contain exactly one row whose `id` is the requested `bandwidthusage` item. The adapter projects only:

- `_count` → `used`, retained as a bounded nonnegative decimal string;
- `_max` → `limit`, retained as a bounded nonnegative decimal string;
- `percent` → an integer from 0–100, accepting only digits with an optional literal percent sign;
- `units` → bounded allowlisted text;
- `zeroisunlimited`, `is_maxed`, and `normalized` → booleans under the names `zero_is_unlimited`, `is_maxed`, and `normalized`.

The normalizer does not calculate a percentage, classify a limit, decide what a zero means, or otherwise derive a usage state. It does not return the vendor's `count` display phrase, arbitrary row text, unrecognized fields, or raw UAPI response. Missing/malformed required fields, more than one row, UAPI failure, warnings, or an account-binding mismatch fail closed rather than producing a partial or empty snapshot. Flags are displayed only as reported.

Official reference: [cPanel UAPI — `StatsBar::get_stats`](https://api.docs.cpanel.net/specifications/cpanel.openapi/resource-usage-and-statistics/statsbar-get_stats.md) (documentation version 11.138.0.10). The reference documents `display` as a pipe-delimited list and includes `bandwidthusage`; this implementation deliberately fixes it to one key. Application allowlisting is defense-in-depth and does not narrow a WHM token that has generic `uapi_cpanel` proxy permission.

## Authorization and execution path

Only a human WHMCS staff administrator with `PANEL_ACCOUNT_VIEW` can request a snapshot for an existing `cpanel_whm` mapping. The linked cPanel account must already be verified active or suspended, and the WHMCS service must still be active or suspended. The worker checks that the remote username, primary domain, and package still match the mapped account before making the UAPI request; it rechecks the mapping/service scope, server, credential and cancellation state. One queued panel action per account is enforced. Customers cannot queue or read this result.

- **Feature gate:** `cpanel_uapi_bandwidth_usage_enabled`, separate from the lifecycle, domain, alias, and quota gates; default off and rechecked by the worker before external access.
- **HTTP:** `POST /v1/panel-accounts/{id}/bandwidth-usage` accepts no body fields, requires an `Idempotency-Key`, and returns `202` with the queued job. Only staff may read the completed result using `GET /v1/jobs/{jobId}`.
- **Admin:** the CSRF-protected linked-account page exposes a queue action only while the gate is enabled and the account is eligible. It validates job type, control-panel queue, account/client/service correlation, and the complete value schema before rendering.
- **Queue:** `panel_account_bandwidth_usage_read` on the existing `control-panel` queue; job type, action, panel-account id, client id, and WHMCS service id are checked through the existing worker/service workflow.
- **Audit:** low-level requests use `CPANEL_UAPI_BANDWIDTH_USAGE_*`; account completion uses `PANEL_ACCOUNT_BANDWIDTH_USAGE_READ`, history event `bandwidth_usage_snapshot_read`, and event hook `panel_account.bandwidth_usage_read`. Audit/history metadata records mapping context and bounded field counts/names, not usage values or vendor phrases. The normalized values are available only in the staff-authorized job result.
- **Transport:** existing registered-server and encrypted credential-vault flow, worker-only WHM token access, HTTPS, mandatory TLS verification, Authorization-header token placement, response redaction, and no redirect following. The HTTP request only enqueues; it never calls cPanel.
- **Schema:** no migration and no continuously updated account status or snapshot column.

## Offline validation and rollout gate

Tests use fake WHM/UAPI, WHMCS, and worker boundaries only. They cover the fixed GET and parameter allowlist, sole `bandwidthusage` projection, rejection of other display keys and multiple rows, warnings and malformed rows, value/flag normalization, audit and result boundaries, staff/customer access, default-off and worker-time gates, idempotency, job routing, and admin rendering. They do not establish live compatibility or token ACL safety.

Before enabling the gate in any environment:

1. Use an isolated WHMCS clone and a non-production cPanel account; keep the feature disabled during setup.
2. Review the **complete** WHM API token ACL. Confirm the actual scope of generic `uapi_cpanel` permission and obtain security approval; the application allowlist cannot reduce the token's server-side privilege.
3. Validate the exact response shape for the supported cPanel/WHM version, including row identity, units, zero/unlimited flag, and `percent` values. Do not infer undocumented semantics from names or zero values.
4. Verify account scoping, TLS certificate validation, hostname binding, auth failure, permission denial, warnings, missing/malformed fields, duplicate rows, timeouts, cancellation, retries, and absence of redirects.
5. Confirm only queued worker requests make external calls; check default-off and worker-time gates, idempotent replay, customer denial, and that logs/audits contain no token, raw UAPI response, vendor phrase, or usage value.
6. Record sanitized staging evidence and operational/security approval outside this implementation. Keep the gate off until that review passes.

No live cPanel call, staging run, production review, or feature enablement was performed for Phase 8. Phase 4 staging and the independent Phase 5/6/7 UAPI staging gates remain outstanding.
