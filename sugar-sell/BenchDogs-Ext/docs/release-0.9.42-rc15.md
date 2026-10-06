# BenchDogs Ext 0.9.42 rc15 Notification Reliability

Release candidate. It has not been built, uploaded or installed. It requires
shared ERP-Epicor `1.1.24-rc9`, Partial Fulfillment `1.0.13`, and SDK `1.18`.
It must never be installed on the stock tenant.

## Repair

The Kinetic create and persisted `in_estimating` stage remain the primary
handoff facts. The Sugar notification is now an independently reported,
best-effort delivery. Its failure cannot change the shared ERP result, roll
back the Quote stage, or expose a retry of the non-idempotent ERP create.

Recipient IDs are resolved through fresh User beans. Explicit configuration is
authoritative and accepts only an existing, active, non-group, non-portal Sugar
user whose `sugar_login` flag is enabled; an invalid configured user fails
visibly instead of silently sending to someone else. A missing or disabled
login fails closed even when the Users row says Active. Without configuration,
outbound delivery tries the active
assigned user's active manager and then the assigned user. Return delivery
tries the active assigned user and then the active creator.

The hook uses Sugar's existing unique `Notifications.sync_key`, rather than a
new table or custom field. The key represents one Quote stage transition. A
duplicate callback or stale concurrent save finds the same notification;
another real estimating cycle has a later Quote modification timestamp and a
different key. A notification is successful only after `save(false)` returns
an ID and an uncached read proves its sync key, recipient and Quote parent.

The outbound API returns `erp_handoff_status=completed` and a separate
`notification_status`. Sidecar presents a persistent amber warning for any
notification state other than `created` or `already_created`, continues the
successful Quote refresh, and never invites another ERP create. The progress
label no longer claims notification delivery before it is known.

## Evidence Required Before Installation

- PHP hook tests must cover active and inactive configured users, dynamic
  fallbacks, false and exceptional saves, lost readback, a duplicate-key race,
  same-stage resaves, a later genuine cycle and the initial priced backfill.
- API and Sidecar tests must prove a notification failure preserves ERP
  success, keeps the action guarded and renders amber rather than green.
- Package lint, PHP syntax, archive integrity and manifest/version checks must
  pass from a clean worktree.
- Hosted acceptance must read back exactly one outbound notification visible
  to its active recipient and exactly one return notification after a proven
  estimating-completion transition. An inactive configured user must yield an
  amber warning without a second Kinetic quote.

## Remaining Gate

rc15 does not invent an estimating-completion signal. The authoritative source
contract identifies Kinetic `QuoteHed.Quoted` plus `DateQuoted`; `CurrentStage`
alone still cannot prove that estimating finished. rc15 must not be built or
installed independently before the paired lifecycle candidate preserves and
consumes those fields. The hook deliberately retains the return leg rather
than suppressing it or guessing from `CurrentStage`; hosted acceptance remains
blocked until the paired candidate is reviewed and installed with rc15.

## Installation and Rollback

After independent review of both candidates, build and retain their exact
artifacts. Install rc15 only as the paired lifecycle change is installed on
suspended Bench QA and after shared ERP-Epicor `1.1.24-rc9` is verified.
Use one Module Loader workflow and verify the installed version, hook
registration, response fields and recipient-visible Notifications record
before reactivation. Never install this customer package on stock QA.

On failure, retain package logs and runtime evidence, then use the supported
replacement path to the retained rc14 artifact. Reverify installed metadata
and the seller entrypoint; do not delete customer data tables.
