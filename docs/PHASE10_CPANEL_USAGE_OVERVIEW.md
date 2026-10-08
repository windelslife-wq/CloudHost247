# Phase 10 — staff overview of retained cPanel usage snapshots

- **Status:** offline implementation only; not production-approved
- **Module version:** `1.9.0`
- **External calls:** none; the overview reads retained completed job results only

Phase 10 adds a staff-only summary of the latest retained quota and bandwidth snapshots for linked cPanel accounts. It is a dashboard projection over the existing Phase 7/8 job results—not a collector, refresh action, metrics store, trend calculation, alert, enforcement feature, or continuous monitoring service.

## Scope and safety

- The overview is part of the existing permission-protected cPanel admin page and remains under `PANEL_ACCOUNT_VIEW`.
- It queries only completed jobs from the `control-panel` queue with the Phase 7/8 job types and cPanel accounts in the existing bounded account listing.
- The overview examines at most the 100 newest matching completed jobs, then presents at most 50 accounts. For each account and snapshot type, it uses the newest matching job ID. A malformed newest result is shown as unavailable; the page does not fall back to an older value of that type.
- Before rendering, each job is rechecked against queue, completed status, linked panel-account ID, client ID, and WHMCS service ID. The existing quota/bandwidth result validators project the output; malformed or cross-account results do not expose values.
- Each value is accompanied by its job reference and request timestamp, clearly labeled cPanel-reported. The page states these are point-in-time retained results, not live readings. It infers no quota or bandwidth semantics.
- Links go to the existing job-result view, which repeats the account/job checks and snapshot validation.
- No migration, new feature setting, background poller, API route, or cPanel/WHM/UAPI call was added. Phases 7/8 remain disabled by default.

## Offline validation

The module test suite covers staff-only access, the 50-account display cap, quota and bandwidth projections, scoped job links, and fail-closed handling of a newer malformed result without exposing its raw value or falling back to an older snapshot. The 100-job query bound is explicit in the implementation. Tests use the in-memory database and fake WHMCS/job boundaries only. Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`: `npm test` → `TOTAL PASS=1434 FAIL=0`; `npm run lint` → `FILES=90, BAD=0`; repository-root `git diff --check` passed.

No live cPanel call, staging run, production review, or feature enablement was performed for Phase 10. The Phase 7 and Phase 8 UAPI capabilities remain disabled by default.
