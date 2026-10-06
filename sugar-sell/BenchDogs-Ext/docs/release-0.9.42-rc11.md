# BenchDogs-Ext 0.9.42-rc11 — visible native release stages

Local candidate only. Not committed, pushed or installed by this preparation;
independent review and hosted acceptance remain required. Shared dependencies
remain ERP-Epicor `1.1.24-rc5` and Partial Fulfillment `1.0.13`. rc10 remains
an unshipped diagnostic candidate and must not be installed instead of rc11.

## Evidence and change

rc9's first hosted prototype action correctly refused an unavailable stage
domain. After the native language repair, a second action returned
`release_stage_status=updated`; an uncached API read showed Opportunity stage
`Prototype Closed` and probability 80, but Sugar's record view showed a blank
Sales Stage value.

A fresh-browser probe proved the application language lists contained both
Bench stage keys and probability mappings. A disposable Opportunity then
isolated rendering from order timing: the API and Sidecar record model both
held `Prototype Closed`/80 after route-away/back and after a hard reload, while
the field still rendered label-only. The record layout uses Sugar's
`enum-cascade` field and the tenant's compiled
`dropdownsStyle.sales_stage_dom_style` had `applyFormatting: true` but no style
entry for either Bench key. A stock stage with a style rendered normally in
the same record view. The disposable Opportunity was deleted in the same
session (`STAGE_STALE_CLEANUP "deleted"`); subsequent fresh-browser filters
returned no Opportunity at `Prototype Closed`.

This candidate adds the native application `DropdownsStyle` extension Sugar
prescribes for formatted dropdowns. It appends styles only for `Prototype
Closed` and `Partial Production Closed`; it does not replace the shared style
map or change `applyFormatting`. The colors and icon reuse Sugar's existing
stage palette. The package still owns only customer vocabulary and
presentation: Partial Fulfillment remains the sole stage writer.

rc11 also contains rc10's required post-install application-language compile,
metadata refresh and uncached domain/probability verification. The installer
and the manual repair endpoint no longer include RevenueLineItems in their
repair list because both QA tenants use Opportunities-only mode.

## Validation boundary

- The style regression executes the real extension twice over an existing
  customer style and `applyFormatting`, proving append-only idempotence.
- The built-package check requires the ZIP style bytes and manifest path to
  match source exactly.
- Complete network-disabled package suite: 99 tests, 97 passed and two
  unchanged explicit skips (native SugarLogic materialization and the linter's
  isolated fixture lacking a copied ERP-Epicor package).
- Source preflight: zero blocker, required or advisory findings. Targeted rc11
  ZIP preflight: zero blocker/required findings and the builder's known
  `files.md5` advisory. The targeted structural/version check passes.
- All 132 payload files match source byte-for-byte; manifest version/path,
  exact packaged tree and ZIP CRC pass. All 130 packaged PHP files are
  syntax-clean.
- Build and full suite ran in network-disabled local Docker with source mounted
  read-only; only the existing release directory was writable for the build.
  Historical ignored archives were preserved and `git diff --check` passes.

Offline source tests cannot prove Sugar Cloud compiles a newly copied
DropdownsStyle extension or that the browser displays it. Before acceptance,
install rc11 on Bench QA only, verify compiled client metadata contains both
style entries, then rerun the exact prototype journey and require both the
model and visible DOM to say `Prototype Closed`. Continue through production
release separately. Do not install this Bench package on the stock tenant.

## Artifact

`sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc11.zip`

SHA-256: `04188b833bec40529763c6b9f21d2c09d5fd6e2ab89d2e6875083811b6f72026`

Superseded rollback warning: do not use the in-place reinstall instruction from
this unshipped candidate. rc12 removes files that rc9 shipped, and Module Loader
does not remove an omitted copy entry during an upgrade. The supported boundary
is uninstall with data tables retained, then install the target artifact. See
the rc12 evidence for the exact forward and rollback sequences.
