# 0.9.42-rc23 — `bd_country` label survives ERP upgrades; retired governing label hidden

Not built, not installed on QA and not approved for production.

rc23 is rc22 with two display fixes.

### `bd_country` type label (REQ-15)

The Bench-owned `ERP_LookupValues`
type label `erp_lookup_type_list.bd_country` ("Country (Bench Dogs)") stopped
being served on Bench after the ERP-Epicor `1.1.24-rc16` install. It stayed
gone after the additive rc17 and the Bench Dogs rc21 upgrade. Offline diagnosis:
`validation/bd-country-label-diagnosis.md` (2026-09-13).

- Cause, in two Sugar 26.1 behaviours:
  - An upgrade without `uninstall_before_upgrade` only appends to
    ERP-Epicor's application language fragment. The fragment still holds the
    rc16-and-older whole-array `erp_lookup_type_list` assignments ahead of
    rc17's additive keys.
  - Language fragments merge by the order map's stored mtime, and that mtime
    is refreshed only when the file's md5 changes. Bench's fragment was
    byte-identical in rc19, rc21 and rc22, so it kept its old slot. ERP's
    fragment changed on each upgrade, so it merged after Bench and wiped the key.
- Fix: the fragment is renamed to
  `custom/Extension/application/Ext/Language/_override_en_us.bd_country_lookup.php`.
  Its content is unchanged and still additive. Sugar always merges `_override*`
  fragments after the others, whatever their mtime or md5. The name still
  contains `en_us`, so it stays in the en_us merge. Partial Fulfillment already
  ships `_override_en_us.partial_fulfillment_quote_stage.php` the same way.
- No `pack.php` change: every file under `custom/` is still a `copy` installdef.
- On a tenant upgraded in place, the old `en_us.bd_country_lookup.php` stays on
  disk (an upgrade never deletes an omitted file; BD-L-0005). That is harmless,
  because it assigns the same single key.
- Display only. `BdAccountCountryGuard` reads stored type values, and nothing
  else changes.

### Retired `bd_governing_line` removed from view (decision 29)

- Nothing writes `Quotes.bd_governing_line`. Under decision 29 the governing
  selection is the explicit `bd01_ERP_Quote_Line.governing` flag, which
  `ErpQuoteOpportunityContribution` reads fail-closed. The Quote-level label
  therefore only ever showed an empty or stale value.
- `BdQuotesLayoutExtensions::write()` now removes it from the Bench Dogs Quotes
  panel on every install. That includes upgraded tenants, where the append path
  used to return early.
  - The removal touches only that panel, so an admin's own placement elsewhere
    survives.
  - The packaged panel no longer lists it.
- The Account "ERP Quote Pipeline" dashlet no longer lists it. `BdDemoDashboards`
  rewrites its own tiles on install, so upgraded tenants follow.
- The vardef and column stay for existing data, now marked retired and hidden
  from Studio. Nothing in core, the SDK or either Sugar repo maps or reads the
  field.
- Unchanged: `BdGoverningLineHook`, `bd01_ERP_Quote_Line.governing` and the
  contribution itself.

Verification still owed on Bench after install:
- The served `/lang/en_us` `app_list_strings.erp_lookup_type_list.bd_country`
  is "Country (Bench Dogs)".
- The 7 ERP keys are still present.
- The Quotes record view and the Account dashlet no longer show "ERP Governing
  Line".

Tests:
- `test_account_country_guard.py` reads the renamed fragment.
- New `test_governing_line_retired.py` covers:
  - the packaged panel, the dashlet and the retired vardef;
  - a PHP harness of `write()` for upgrade, fresh and replace installs.

## Previous candidates

## 0.9.42-rc22 — adopt Kinetic quotes whose Sugar marker names a deleted Quote

Not installed on QA and not approved for production.

rc22 is rc21 with one REQ-28 fix found live on Bench QA (2026-09-13). All 30 ERP
quotes that carried a Sugar-origin marker (`sugar_quote_id`, parsed from the
Kinetic QuoteComment) pointed at Quotes that no longer exist. Rule 1 treated
them as Sugar-born and never adopted them, so their connector-created Quotes
had no mirror link and the governing contribution answered "not applicable".

- A `sugar_quote_id` whose Quote Sugar no longer has is treated as absent. The
  ERP quote is then adopted by its Kinetic number like any Kinetic-born quote.
- A live marker still means Sugar-born and is left alone.
- A read that fails for any other reason keeps the marker, so a transient error
  never re-routes a real Sugar-born quote.
- Existing rows adopt on their next save or line link.

Tests: `test_kinetic_quote_adoption.py` now has 11 PHP-backed cases.

## 0.9.42-rc21 — Opportunity floor stored as an Administration setting

Not installed on QA and not approved for production. Supersedes rc20, which was
built and scanned but never installed.

rc21 is rc20 with one deployment fix. rc20 read the Opportunity floor only from
`$sugar_config['benchdogs_ext']['materialize_from_quote_num']`, which
SugarCloud administrators cannot set. rc21 still honours that override, but
otherwise stores the floor as the Administration setting
`benchdogs / materialize_from_quote_num`. It is seeded once, on the first
adoption, with the highest Kinetic quote number any Sugar Quote already
carries. The Bench ERP `benchdogs` pipeline now runs after core's
(`depends_on=("core",)`), so core's initial load has created every historical
Quote by then. Historical quotes are adopted and never get an Opportunity; later
quotes do. When no numbered Quote exists, or the setting cannot be read, nothing
is created. No manual deployment step is needed.

Tests: `test_kinetic_quote_adoption.py` now has 8 PHP-backed cases.

## 0.9.42-rc20 — Kinetic-born quotes adopt core's native Quote

Not installed on QA and not approved for production.

rc20 is rc19 with the REQ-28 hook brought under user decision 18 (core owns
native Quote creation) and decision 29 (every Kinetic quantity break arrives as
an unselected native line; only a person's governing selection counts).

- `BdQuoteReflectionHook` adopts the Quote core created, by Kinetic quote
  number, before anything else. Adoption creates nothing.
- It never creates a Quote any more. Until core delivers one the ERP quote
  shows `waiting_native_quote`.
- It never copies mirror lines onto an adopted Quote. Core's per-break lines
  are left alone; only legacy `materialized` quotes are still re-copied.
- An adopted quote gets its Opportunity (and `erp_is_primary_quote`) only when
  the account is matched by ERP key and the quote number is above
  `$sugar_config['benchdogs_ext']['materialize_from_quote_num']`. Without that
  setting no Opportunity is created. **Deployment step:** set it to the last
  Kinetic quote number that predates go-live.

Tests: new `scripts/tests/test_kinetic_quote_adoption.py` (5 PHP-backed cases).

## 0.9.42-rc18 — account action requires the same access as the shared one

Not installed on QA and not approved for production.

rc18 is rc17 with one access-control fix. `POST Accounts/:record/bd-create-opp-quote`
(`BdBenchDogsActionsApi::createOppQuote`) checked only **view** access on the
account and then saved an Opportunity, a Quote, its bundle and a placeholder
line with no create-access check, so a user who could only view an account
could create sales records through the API. It now applies the same gate as
ERP-Epicor's `AccountsErpActionsApi::createOppQuote`: edit access on the account
and save access on Opportunities and Quotes, all checked before the first save.
A refused caller gets `SugarApiExceptionNotAuthorized` and nothing is created.
See `docs/release-0.9.42-rc18.md`.

## 0.9.42-rc17 — SugarCloud scanner-safe estimating notifications

Not installed on QA and not approved for production.

rc17 is rc16 with one scanner-forced change. SugarCloud's ModuleScanner
refused rc16 at upload on QA (2026-09-13): `BdEstimatingNotificationHook.php`
"Code attempted dynamically-named method call on line 521", the logging helper
`$GLOBALS['log']->{$level}($message)`. The helper now calls `error()`,
`warn()` or `info()` by literal name. Notification behavior, redaction and
every other rc16 change are unchanged.

The repository pre-flight missed it because `MLP017` matched
`$obj->$name()` but not the brace form `$obj->{$name}()` or dynamic static
calls; that gap is closed here and upstream in `erp-integration-sugar`.
See `docs/release-0.9.42-rc17.md`.


## 0.9.42-rc16 — exact Kinetic estimating completion

Not uploaded successfully to QA (rejected by the hosted scanner) and not
approved for production.

rc16 retains rc15's independently reviewed notification reliability and
replaces the unsafe `CurrentStage=QUOT` completion inference with the exact
nullable Kinetic facts `QuoteHed.Quoted` and `DateQuoted`. The customer module
stores both without defaults. Unknown preserves lifecycle state, explicit
false preserves an active hand-off, an observed true-to-false change after
priced marks revision, and only true with its business date advances to
priced. Linked-order and closed
outcomes outrank completion.

`DateQuoted` corroborates completion but is not treated as a precise clock:
the observed EPIC06 values carry midnight/date precision. Sugar's first
observed priced transition therefore owns the elapsed-time endpoints. Repeat
true is stable; a false-to-true revision can create a new stage-edge
notification without rewriting the first cycle's immutable timestamps. Shared core must first deliver the paired
container-boundary repair that omits unknown `null` values while preserving
false, zero and blank clears. SDK `1.18`, Quote-line-only behavior, shared
ERP-Epicor `1.1.24-rc9`, and Partial Fulfillment `1.0.13` remain unchanged.

See `docs/release-0.9.42-rc16.md` and
`docs/quote-completion-source-contract.json`.

## Previous candidate

## 0.9.42-rc15 — honest and deduplicated estimating notifications

Not installed on QA and not approved for production.

rc15 keeps the shared ERP action and persisted Bench stage as the primary
handoff result. The secondary Sugar notification now validates active internal
recipients, uses the native unique `Notifications.sync_key` to deduplicate one
stage transition, calls `save(false)`, and reads the record back before
claiming delivery. Missing, inactive, invalid, failed and unobserved delivery
paths remain named warnings and never undo or retry the ERP create.

The action response carries separate `erp_handoff_status` and
`notification_status` values. Sidecar keeps the successful action guarded and
shows an amber operational message when the Kinetic handoff succeeded without
a proven notification. Both configured recipient keys and their authoritative
failure semantics are documented. Shared ERP-Epicor `1.1.24-rc9`, Partial
Fulfillment `1.0.13`, SDK `1.18`, and the Quote-line-only model are unchanged.

Do not build or install rc15 independently. It must be reviewed, built and
accepted together with the paired Kinetic `Quoted`/`DateQuoted` lifecycle
candidate. The notification hook deliberately retains the return leg; it does
not guess completion from `CurrentStage` or hide the missing lifecycle input.

See `docs/release-0.9.42-rc15.md`.

## Previous candidate

## 0.9.42-rc14 — callable and fail-visible estimating handoff

Not installed on QA and not approved for production.

rc14 repairs the advertised Bench `Send to Estimating` action. The customer
route now delegates `advanced_quote` to the shared ERP-Epicor API owner,
applies only the Bench lifecycle transition after shared success, and proves
that transition through an uncached read before reporting completion. Existing
ERP quotes retain their current stage. The Sidecar action rejects repeated
events while a request is pending and does not invite a retry when ERP identity
is ambiguous or absent.

The turnaround start uses only the exact company-scoped Quote key already
stamped by core; it never borrows a company prefix or joins on bare QuoteNum.
The withdrawn best-pricing route is no longer advertised. ERP-Epicor
`1.1.24-rc9` and Partial Fulfillment `1.0.13` are pinned; SDK `1.18` and the
Quote-line-only model are unchanged.

This candidate does not claim durable cross-tab `quote_to_quote` idempotency,
notification delivery, or a valid estimating-completion signal: EPIC06 reports
`CurrentStage=QUOT` on every sampled open quote, so that value alone cannot
prove estimator work is finished. See `docs/release-0.9.42-rc14.md`.

## Previous candidate

### 0.9.42-rc13 — fail-closed commercial charges

rc13 requires explicit native Quote tax and shipping values before the Bench
contribution provider returns an amount. Missing values fail closed; genuine
numeric zero remains valid. It was staged but not installed. rc14 supersedes
it without reusing its version or bytes.

## Earlier candidate

# 0.9.42-rc12 — Quote-line-only visible-stage candidate

Not installed on QA and not approved for production.

rc12 makes the user's Opportunities-only decision a permanent package
boundary. It removes the old Opportunity-line vardef, runtime projection,
record creation and line-refresh implementation rather than hiding them behind
the current tenant configuration. Native Quote lines remain the only itemized
sales records; shared ERP-Core remains the sole Opportunity amount writer and
Partial Fulfillment remains the sole release-stage writer.

The stage styles are now strictly append-only at the key level: an existing
tenant style for either Bench stage wins, while an absent style receives the
Bench default. A regression proves both same-key preservation and preservation
of unrelated styles and `applyFormatting`.

This is a clean replacement, not an in-place upgrade. Module Loader must first
uninstall rc9 with data tables retained, then install rc12; otherwise files the
old archive shipped can remain on disk. Rollback is the reverse supported
sequence—uninstall rc12 with tables retained, then install the saved rc9
artifact—not a simple reinstall over rc12.

See `docs/release-0.9.42-rc12.md` for evidence, gates and rollback.

## Previous candidate

### 0.9.42-rc11 — visible native release-stage candidate

The rc9 hosted action reached `Prototype Closed`/80 in the API after native
language repair, while the Opportunity record view displayed a blank Sales
Stage. A disposable live diagnostic ruled out action timing and Sidecar model
staleness: route-away/back and hard reload both loaded the correct model value,
but the formatted `enum-cascade` remained empty. Client metadata had
`applyFormatting: true` and no `sales_stage_dom_style` entries for either
Bench stage.

rc11 appends those two entries through Sugar's native application
DropdownsStyle extension, preserving every shared and tenant-owned style. It
also includes rc10's installer language compile/refresh/verification and
removes Opportunity RLI repair references from both install and manual repair
lifecycle paths. Stage decision/write ownership, amount arithmetic, SDK and
shared dependencies do not change. See
`docs/release-0.9.42-rc11.md` for evidence, gates and rollback.

## Earlier candidate

### 0.9.42-rc8 — canonical stage-validation candidate

Not installed on QA and not approved for production.

The rc7 hosted run proved the complete persisted policy graph was correct and
still returned `policy_preserved`, disproving stale Link2 beans as the complete
cause. rc8 pins Partial Fulfillment 1.0.12, whose shared sole writer validates
customer stage keys against Sugar's freshly loaded current-language application
strings rather than relying only on a request-global copy that may predate a
language-extension rebuild. Loader failure falls back safely and unknown stage
keys remain rejected.

Bench policy behavior is otherwise unchanged: it decides only, never saves the
Opportunity, never accesses Opportunity Revenue Line Items, and refuses
ambiguous release identity. The rc8 archive is loadable and pins Partial
Fulfillment 1.0.12; SHA-256:
`9f3dd4b1769c46fd440469a09b3938522981863d5191e832f8b958cb8cd4e7e5`.
Hosted scan, installation, and the full prototype-to-production journey remain
required. Roll back to rc7 and Partial Fulfillment 1.0.11 without uninstalling
modules or removing tables.

## Previous candidate

### 0.9.42-rc7 — fresh release-policy graph candidate

Not installed on QA and not approved for production.

The coordinated rc6 hosted run returned
`release_stage_status=policy_preserved` even though post-request reads proved
the prototype Product was ordered, all four Product-to-ERP line numbers
matched, and exactly one ERP quote with one prototype line was linked. The
order action can carry already-loaded Link2 beans at more than one level, so a
clean REST read after the request does not prove what the policy observed
inside that request.

rc7 keeps Partial Fulfillment as the sole Opportunity-stage writer. The Bench
decision-only policy resolves relationship IDs and re-retrieves the ERP quote,
every ERP quote line, and every Product outside BeanFactory's request cache
before classifying the release. Missing, duplicate, or contradictory identity
still refuses rather than guessing. It does not save an Opportunity or access
Opportunity Revenue Line Items.

The source suite passes 85 tests with two declared skips, plus four independent
stale-graph/cardinality controls. Source and built-package preflight are clean
apart from the package line's known `files.md5` advisory. The rc7 archive is
loadable, and the packaged policy is byte-identical to source. Archive SHA-256:
`7022040a7af0da7e7cbab2eba7e911e8bad8329197bba9857b00883184381647`.
Hosted scan, installation, and a fresh prototype-first journey remain required.
Roll back to rc6 without uninstalling modules or removing tables.

## Earlier candidate

### 0.9.42-rc6 — observable shared-stage candidate

Installed on Bench QA for controlled diagnosis; not approved for production.

The installed rc5 package still created exactly one prototype order while the
Opportunity remained Proposal for 60 seconds. That disproved stale Product
beans as the complete cause. rc6 retains rc5's provider behavior but requires
the coordinated ERP-Epicor 1.1.24-rc4 / Partial Fulfillment 1.0.11 repair.
Those shared packages normalize associative Opportunity relationship id arrays
and return a neutral `release_stage_status` in the order response. The hosted
run returned `policy_preserved`, localizing the skip to the policy decision.
The status
contains no record ids, exception text or customer-policy internals and does
not alter the seller's truthful order-success message.

Offline network-none validation passes 88 tests with two declared skips. Source
preflight is clean; ZIP preflight has zero blocker/required findings and retains
the known `files.md5` advisory. The packaged provider and reflection hook are
byte-identical to source, and the manifest pins both coordinated dependencies.
SHA-256:
`f4b6ea37f16ef22bd95d800a6187a290b70739e203afd8167b8fef4a1897081e`.

That controlled run created exact Epicor order 11574, then removed and
absence-verified it and the complete owned Sugar/ERP graph with no cleanup
failures. Roll back to rc3 without uninstalling modules or removing tables.

## Earlier candidate

### 0.9.42-rc5 — fresh release-stage observation candidate

Installed on Bench QA for one controlled prototype-first run; not approved for
production.

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

Hosted scan passed, but the fresh prototype action failed identically to rc4:
exact Epicor order 11572 was created and the Opportunity remained Proposal for
60 seconds. The production leg was withheld and exact cleanup passed. Roll back
to rc3, the last candidate that preceded release-stage policy; do not uninstall
modules or remove tables.

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
