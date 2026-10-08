# Phase 9 — staff view of retained cPanel usage-snapshot jobs

- **Status:** offline implementation only; not production-approved
- **Module version:** `1.8.0`
- **External calls:** none; this phase only reads already-stored queue records

Phase 9 adds a small staff-only history table for quota and bandwidth snapshot jobs already queued by Phases 7 and 8. It is not a new cPanel capability, data collector, polling schedule, metrics database, trend calculator, alerting system, or continuous monitoring service. The list is bounded to the 50 most recent matching jobs and only includes records still present under normal job retention.

## Scope and projection

The linked cPanel admin page now lists recent disk/inode quota and bandwidth job references with the linked account, snapshot type, request timestamp, job status, and a link to the existing job view. It does not include usage values in the history table. The linked view uses the existing result validators and account/client/service correlation checks before displaying a completed snapshot; failed or malformed results are not exposed. No data is copied into a new history table, audit metadata, or account record.

- **Authorization:** the existing human-admin `PANEL_ACCOUNT_VIEW` check protects the entire page. Customers cannot view the page or history.
- **Scope validation:** history query is limited to the `control-panel` queue, the bounded cPanel account listing, and only the Phase 7/8 usage snapshot job types. Each returned record is rechecked against the linked panel-account id, client id, and WHMCS service id before a link is rendered.
- **Result access:** a history row contains a job reference only. The existing per-job display path validates account, queue, job type, client, service, status, and result schema again.
- **Retention:** completed/cancelled job behavior is unchanged. The history view reflects only records retained by the existing queue policy; dead jobs follow existing operator-retention behavior.
- **Schema and external access:** no migration, new feature gate, provider call, WHM API call, or cPanel UAPI call is introduced.

## Offline validation

The offline module tests cover staff-only rendering, the 50-row bound, quota/bandwidth labels, scoped job links, absence of usage values in the list, and continued use of the existing job-result validator. The suite uses the in-memory database and fake WHMCS/job boundaries only. Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`: `npm test` → `TOTAL PASS=1421 FAIL=0`; `npm run lint` → `FILES=90, BAD=0`; repository-root `git diff --check` passed. These checks do not prove production WHMCS rendering or change Phase 7/8's independent cPanel staging requirements.

No live cPanel call, staging run, production review, or feature enablement was performed for Phase 9. The Phase 7 and Phase 8 UAPI capabilities remain disabled by default.
