---
id: BD-L-0006
title: A customer action must call the shared public owner
rule: Register only callable routes, and make customer entry points thin delegates to the shared action that owns validation, transport and ordinary re-click handling.
severity: high
subsystems: [benchdogs-sugar, estimating-handoff, sidecar]
paths:
  - sugar-sell/BenchDogs-Ext/custom/clients/base/api/BdBenchDogsActionsApi.php
  - sugar-sell/BenchDogs-Ext/custom/modules/Quotes/clients/base/fields/bd-send-estimating/bd-send-estimating.js
---

# A customer action must call the shared public owner

**What happened.** The Bench Quotes layout exposed `Send to Estimating` and
registered `bd-send-to-estimating`, but the API class did not implement the
registered method. The same dictionary also advertised `bd-best-pricing`
after the UI and requirements had explicitly withdrawn catalog repricing;
that method was absent too. Package scans and layout tests passed because none
proved that each route resolved to a callable method.

**Rule.** A customer package may add a business entry point, but it must not
copy a shared ERP write path. Delegate to the shared API's concrete public
method with the exact action contract, then apply only customer-owned state
after shared success. Re-read the bean outside request cache before saving so
the customer update cannot overwrite fields the shared call just changed.
Remember the pre-call ERP identity as well: the shared action treats an
existing ERP quote as a successful re-click, and the wrapper must not regress
that quote from `priced`, `revision` or a later stage back to estimating.

**Applying it.** Bench delegates `advanced_quote` to
`QuotesErpActionsApi::runErpAction`, then and only then moves the fresh Quote
to `in_estimating`, proves that stage through a second uncached read, and
invokes its existing turnaround timestamp helper. A shared error or unproven
stage save cannot be presented as a completed handoff. The withdrawn
best-pricing route is not registered. Tests execute the route-to-method
boundary, preserve an existing priced quote, and prove failure does not stage
the Quote.

The Sidecar button also owns an in-memory pending guard: disabling an anchor
does not prevent a second context event, and errors must unlock only for an
explicit user retry. This guard is deliberately not called idempotency. It is
local to one field instance and cannot serialize concurrent tabs/users or a
lost-response retry. The shared `advanced_quote` path's pre-check narrows the
ordinary re-click case but has no durable cross-request claim for
`quote_to_quote`; that remains a production-readiness gap owned by the shared
layer.

**Do not confuse entry with completion.** The exact scoped key makes the start
timestamp company-safe, but does not prove the estimator has finished. The
current Epicor sample reports `QUOT` for every open quote and the existing map
treats `quoted` as `priced`; therefore `CurrentStage` alone can close the clock
on the first connector sweep. Keep REQ-13 open until Kinetic supplies a
distinct completion fact proven against a real estimating journey.

The endpoint also cannot claim notification delivery: the existing hook keeps
notification lookup/save failures non-blocking so they cannot undo the ERP
handoff. That is a valid failure boundary, but delivery and failure
observability need their own hosted evidence.
