# BenchDogs-Ext 0.9.42-rc14 — callable estimating handoff

Release candidate. It has not been uploaded or installed. It requires shared
ERP-Epicor `1.1.24-rc9`, Partial Fulfillment `1.0.13`, and SDK `1.18`. It must
never be installed on the stock tenant.

## Repair

The Bench `Send to Estimating` route now resolves to a concrete method and
delegates `advanced_quote` to shared `QuotesErpActionsApi::runErpAction`.
Shared ERP-Epicor continues to own validation, ACL enforcement, transport,
write-back status and its existing-identity replay guard. Bench owns only its
customer lifecycle transition and turnaround start.

After a fresh shared success, the route re-reads the Quote, saves
`in_estimating`, and requires a second uncached read to prove persistence.
Failure returns a partial-success, unsafe-retry response that retains the ERP
identity instead of presenting a misleading green result. A normal re-click
on an existing ERP quote never regresses a priced or revision stage.

The start timestamp is joined only through exact `Quotes.erp_sync_key`
identity (`<COMPANY>__<QuoteNum>`). Missing, mismatched, duplicate or
unpersisted mirror state remains visible as a non-green timestamp status.
The Sidecar field serializes repeated events in one field instance and stays
guarded if the ERP identity is not visible after refresh. It explicitly tells
the seller not to retry unless an administrator has verified that no Kinetic
quote exists.

The withdrawn catalog best-pricing REST route is removed. No Opportunity
Revenue Line Item is created or read. SDK `1.18` is unchanged.

## Known release gates

- The browser guard is not durable idempotency across tabs, users, process
  loss or a lost ERP response. Shared-layer `quote_to_quote` recovery remains
  required before production readiness.
- Route success does not prove a Sugar Notification was saved or delivered.
  Recipient validation, failure observability and hosted delivery remain.
- `QuoteHed.CurrentStage=QUOT` is not a valid estimating-completion oracle on
  EPIC06: all 106 sampled open quotes carry it. A distinct live-proven signal
  or material-pricing predicate is required before closing the turnaround,
  notifying sales, or claiming REQ-13.

## Offline evidence

- Estimating PHP route/delegation harness: 10 passing tests.
- Shipped Sidecar lifecycle: 10 passing tests.
- Complete source discovery: 116 passed, 45 explicit environment/artifact
  skips; no source-test failures.
- MLP lint: zero blocker, zero required and the known `files.md5` advisory.
- Source and packaged PHP syntax pass; archive integrity and manifest/version
  checks pass.
- All 131 packaged source files are byte-identical to the reviewed clean
  worktree.

## Installation and rollback

Install only after shared ERP-Epicor `1.1.24-rc9` is verified on Bench QA.
Keep the tenant suspended, retain the exact preceding Bench artifact, use one
Module Loader workflow, and verify the installed version, registered route,
metadata and live browser response before reactivation. Do not install this
customer package on the stock tenant.

On any scanner, install, metadata or canary failure, stop and preserve the
package log and exact state. Roll back through the retained prior Bench
artifact only after confirming the supported replace/uninstall path, then
reverify installed metadata and the seller entrypoint.

## Artifact

`sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc14.zip`

SHA-256: `bfa0c3d9375d8d166d7321dc31ab32f74a6ed322a2640ebdf7569f3d1beeb2d1`
