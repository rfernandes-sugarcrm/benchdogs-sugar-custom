# 0.9.42-rc2 — governing contribution candidate

Not installed on QA or approved for production. Requires shared ERP-Epicor
1.1.24-rc1 or newer for the neutral contribution contract.

Only the selected governing production option contributes to Opportunity
amount; alternatives remain visible. Prototype value, Quote tax and shipping
also contribute. The provider never saves Opportunity or changes Quote lines;
the shared hook remains the sole writer and owns currency conversion. Missing
or ambiguous selection/revision/prototype data refuses without falling back to
the all-options total. Bench governing refresh now invokes that shared writer.

The exact Kinetic header field is unknown, so this candidate uses the explicit
Sugar governing flag and does not claim automated ERP selection. Native
SugarLogic/tax behavior, revisions, concurrency, package scanning and QA/UI
acceptance remain required. Roll back both coordinated MLP candidates to their
saved prior versions; do not uninstall modules or remove tables.

Offline evidence: 73 repository tests pass with two declared skips (native
SugarLogic and sibling shared package unavailable in the isolated checkout).
Five additional composed shared/Bench hook acceptance methods pass from the
workspace validation suite. The rc2 ZIP matches provider/reflection source and
declares the shared dependency; preflight has no blocker/required findings and
the known `files.md5` advisory. SHA-256:
`f2b938949a48a85e115134645698f3c1a6bab3db4ff145578c742f66b74dacaa`.
The retained rc1 ZIP is historical and therefore does not match the current
version file; the targeted rc2 artifact check passes.

## Previous candidate

### 0.9.42-rc1 — local validation candidate

Not installed on QA or approved for production.

## Change

In Opportunities-only mode, Bench reflection no longer overwrites the
Opportunity headline amount calculated by the shared primary-Quote hook.
It retains its existing stage/forecast behavior and does not access or purge
Opportunity Revenue Line Items in this path. Historical cleanup is not part
of an ordinary reflection save.

This fixes competing writers: a Quote save could calculate 280 (250 in lines,
20 tax, 10 shipping), then a Bench reflection could replace it with 250.
It does not implement governing quantity alternatives or change their policy.

## Offline evidence

- Real public PHP hook fixture: 11 passing cases; one native
  SugarLogic/materialization case explicitly unverified.
- Source MLP preflight: no blockers, required findings or advisories.
- Built manifest/version/archive checks: pass.
- Built ZIP preflight: no blockers or required findings; existing builder
  omits root `files.md5` (MLP014 advisory). Real ModuleScanner remains required.
- Packaged reflection-hook bytes match the tested source; PHP syntax passes.

## Remaining QA gates

Install only on the Bench Dogs profile after the coordinated candidate gates
pass; stock must not receive this package. Verify Module Loader history,
native tax/shipping and currency calculations, refresh/repricing convergence,
and zero Opportunity RLI access. Keep governing quantity, revisions,
prototype/production and selected-line ordering open until separately proven.

## Rollback boundary

Retain the installed 0.9.41 package and a recoverable QA backup before changing
the instance. Do not uninstall the module or drop its tables to roll back a
hook change. The rollback procedure and restored installed-version evidence
must be rehearsed before approval; this local build does not prove them.
