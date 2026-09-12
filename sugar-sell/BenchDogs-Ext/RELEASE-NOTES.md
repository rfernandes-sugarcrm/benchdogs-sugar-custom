# 0.9.42-rc5 — fresh release-stage observation candidate

Not installed on QA and not approved for production.

The rc4 prototype action created one exact Epicor order and stamped the native
Quote line, but Opportunity remained Proposal for 60 seconds. The action had
loaded `Quote.products` before stamping `erp_ordered`; `Quote::retrieve()` did
not discard the loaded relationship bean snapshot, so policy observed the old
unordered Product and returned no decision.

rc5 retains the one-writer architecture and changes only observation: resolve
linked Product IDs, retrieve each with `use_cache=false`, then classify against
the same ERP-line identity. A linked Bench quote with no committed line now
throws a deterministic refusal for the shared logger. Amount, governing
formula, Quote lines, RLI boundary and release creation are unchanged.

Offline network-none validation passes 88 tests with two declared skips. Source
preflight is clean; ZIP preflight has zero blocker/required findings and retains
the known `files.md5` advisory. The packaged provider and reflection hook are
byte-identical to source. SHA-256:
`82989bf888f5a864b3ef8695c2dc2979e869e32aaf2bcd899556a5e3f409a2c3`.

Hosted scan and a fresh prototype-then-production QA journey remain required.
Roll back to rc3, the last candidate that preceded release-stage policy; do not
uninstall modules or remove tables.

## Previous candidate

### 0.9.42-rc4 — shared release-stage ownership candidate

Not installed on QA and not approved for production.

Bench now provides release-stage policy as plain data through Partial
Fulfillment 1.0.10's fixed neutral seam. Prototype-only release resolves to
Prototype Closed/80; any ordered production option resolves to Partial
Production Closed/90. Missing or contradictory ERP-line identity refuses.
The provider never writes Opportunity.

`BdQuoteReflectionHook` no longer advances post-order Opportunity stage. It
retains pre-order Proposal initialization and system/human forecast provenance.
When Kinetic reconciliation newly marks a Quote line ordered, it invokes the
same shared `AfterLinesOrdered` dispatcher used by Order Selected Lines, so
trigger order cannot choose a different writer. The package now requires
Partial Fulfillment 1.0.10.

Offline network-none validation passes 87 tests with two declared skips. The
composed real-PHP lifecycle keeps one Opportunity and zero RLI reads while
moving prototype release to Prototype Closed/80 and later production release
to Partial Production Closed/90. Policy cases cover no release, production
precedence, multiple ERP revisions, duplicate line identity, missing identity
and multiple prototypes. Source preflight is clean; ZIP preflight has zero
blocker/required findings and retains the known `files.md5` advisory. Both
changed packaged PHP files are byte-identical to source. SHA-256:
`dbfa731f1d05bda2b45a6bf46eff12634656e69aaf210c1ca9decbe085bf3d80`.

Hosted ModuleScanner and installed prototype-then-production release journeys
remain required before QA approval. Roll back to 0.9.42-rc3 without uninstalling
modules or removing tables.

## Previous candidate

### 0.9.42-rc3 — governing forecast alignment candidate

Installed on Bench QA as package
`7e19d778-aee9-11f1-8749-060ab0eed8b1`; not approved for production.

System-managed Opportunity Best and Worst now equal the shared writer's
Opportunity-currency amount: selected governing production + prototype +
native Quote tax + shipping. Unselected production alternatives remain
visible but contribute zero. The Bench hook invokes the shared writer first
on governing/ERP refresh, then consumes the resulting headline; it does not
duplicate contribution arithmetic or currency conversion.

Three hidden Opportunity fields retain explicit ownership provenance. Best
and Worst can be taken over independently by a person; once a field diverges
from the exact last system value it is marked human-owned and is never
reclaimed by value coincidence. On upgrade, only zero, current-headline or
legacy-deliverable values are adopted as system-owned. This migrates the QA
all-options values while conservatively preserving genuinely different human
forecasts.

The ERP Quote Line primary record panel now renders the editable Governing
Line boolean. rc2 exposed the field through the model/filter and used it in
the calculation, but omitted it from the actual detail layout, so a seller
could not inspect or change the choice in the supported UI.

Offline evidence: 79 repository tests pass with two declared skips (native
SugarLogic and sibling shared package unavailable in the isolated checkout).
The focused hook tests cover one-pass repricing, shared currency conversion,
legacy adoption and independent human takeover. PHP syntax and the source
preflight pass. The rc3 ZIP is structurally valid and has no blocker/required
findings; it retains the known `files.md5` advisory. SHA-256:
`1b9d90b0c9b853abfcc792ce26e005c266def5a602c5a60960731038ceab9f41`.
The repository-wide artifact-version checker also reports retained rc1/rc2
ZIPs as historical mismatches; the targeted rc3 artifact check passes.

Installed QA evidence passes: the 50x80 and75x70 governing options produced
Opportunity amount and system-managed Best/Worst of5850.63 and7100.63,
including prototype500, native tax1340.625 and shipping10. A manual Best
override of999 survived two later governing changes while system-owned Worst
continued to follow the headline. The real browser displayed amount7100.63,
all four Quote lines and the checked, enabled-in-Edit Governing control, with
no page errors.
Zero Opportunity RLIs were created. All12 owned records were deleted and
independently read back absent; browser/API logouts succeeded. Currency beyond
base USD, automatic Kinetic-header selection and concurrent governing edits
remain open. Roll back to 0.9.42-rc2; do not uninstall modules or remove
tables.

## Previous candidate

### 0.9.42-rc2 — governing contribution candidate

Installed on Bench Dogs QA as package
`9dcac8be-aee1-11f1-abd9-060ab0eed8b1`; not approved for production. The QA
site now has shared ERP-Epicor 1.1.24-rc3, satisfying this package's neutral
contribution contract dependency.

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

Installed QA evidence (2026-09-12): one owned Quote retained all production
options (50×80, 75×70, 100×65) plus a $500 prototype. With native tax
$1,340.625 and shipping $10, Opportunity amount was $5,850.63 for the selected
50-unit option and $7,100.63 after selecting the 75-unit option. A later native
Quote save and repeated selection write both remained $7,100.63. Quote total
stayed $17,600.625 and no Opportunity RLIs were created. All 12 exact owned
records were individually confirmed absent after cleanup; API logout returned
200.

This proves the installed downstream provider, uniqueness hook, shared-writer
convergence and arithmetic. It does not prove automatic selection from the
unknown Kinetic header field. The run also found that Bench `best_case` and
`worst_case` became the all-options subtotal ($16,250) while headline amount
was $7,100.63. That measured inconsistency motivated rc3; it remains open in
the installed QA environment until rc3 passes its deployment gates.

## Earlier candidate

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
