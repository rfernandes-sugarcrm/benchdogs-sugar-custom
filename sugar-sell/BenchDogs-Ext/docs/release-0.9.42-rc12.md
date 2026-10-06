# BenchDogs-Ext 0.9.42-rc12 — Quote-line-only replacement

Local candidate only. It has not been committed, pushed, scanned by Sugar
Cloud, installed on QA or approved for production. Shared dependencies remain
ERP-Epicor `1.1.24-rc5` and Partial Fulfillment `1.0.13`; SDK `1.18` is
unchanged.

## Ownership correction

Bench Dogs uses Opportunities with native Quote lines. This is now a package
invariant rather than a tenant-mode branch: rc12 does not ship an Opportunity
line-item vardef, hook registration, hook class, bean creation, relationship
read, refresh branch or maintenance helper. The account action creates an
Opportunity, Quote, ProductBundle and native Product line only.

The renamed `BdQuoteLineRefreshHook` still reacts when a reflected Kinetic
Quote line changes or is linked. It invokes the existing reflection entry
point so ERP-Core's shared `QuoteOpportunityAmount` remains the only
Opportunity amount writer. Bench consumes that resulting headline for its
independently owned Best/Worst provenance. Partial Fulfillment remains the
only post-order stage writer through Bench's neutral decision provider.

The amount contract is unchanged: selected governing production option plus
prototype, native Quote tax and shipping. Unselected quantity alternatives
remain visible Quote lines and contribute zero. Ambiguous selection still
refuses instead of guessing.

## Native stage presentation

The two Bench `DropdownsStyle` entries are append-only down to the exact key.
If the tenant already owns a style for `Prototype Closed` or `Partial
Production Closed`, that complete value wins; Bench supplies a default only
when the key is absent. Unrelated styles and `applyFormatting` are preserved,
and repeated compilation is idempotent.

## Required clean replacement

An ordinary upgrade does not remove files that an older package copied but a
newer ZIP omits. Therefore **do not install rc12 over rc9**.

1. Suspend Bench integration work and retain the exact rc9 and rc12 archives.
2. In Module Loader, uninstall rc9 and explicitly choose **KEEP / DO NOT REMOVE
   data tables** when prompted.
3. Verify rc9 is absent. Verify the retired Opportunity-line vardef and old
   `bd_rli_refresh` registration/class are absent from deployed and compiled
   metadata.
4. Install rc12 and require a clean hosted ModuleScanner result.
5. Verify installed version/dependencies, compiled stage language and style
   metadata, the neutral Quote-line refresh hook, and absence of all three
   retired paths.
6. Run the fresh-browser prototype journey before reactivating the tenant;
   continue through production release only after the prototype gate passes.

Sugar Module Loader owns removal of copied files during uninstall. The package
does not perform scanner-disallowed filesystem deletion. The offline harness
proves rc9 contains the three retired paths, rc12 omits them, the manifest is
uninstallable with `remove_tables => prompt`, and both uninstall lifecycle
scripts are registered. It cannot prove a hosted uninstall or cache rebuild.

## Rollback

Rollback also requires the clean Module Loader boundary; an in-place rc9
reinstall is unsupported.

1. Suspend integration work.
2. Uninstall rc12 and explicitly **KEEP / DO NOT REMOVE data tables**.
3. Verify rc12 is absent and its copied `DropdownsStyle` file is gone.
4. Install the saved rc9 artifact with SHA-256
   `0c4c91468298b9bc2bda5988671dff9c75554b376b8245e13ca5c4910f027cd4`.
5. Verify the installed version, pinned dependencies and compiled metadata
   before reactivation.

This rollback deliberately restores rc9's noncompliant Opportunity-line
vardef/hook/runtime behavior and its missing stage-style correction. It is an
emergency recovery state, not the accepted Bench Dogs architecture, and must
not be mistaken for production-ready completion.

## Offline validation

- Network-disabled, read-only-source Docker suite: 104 tests, 102 passed and
  two declared environment-fixture skips (native SugarLogic tax/shipping
  materialization and the linter's isolated fixture without copied
  ERP-Epicor).
- The package-invariant regression scans shipped source PHP and the built ZIP
  for the retired module/runtime identifiers and checks the rc9-to-rc12
  archive delta and uninstall lifecycle.
- Source preflight: zero blocker, required or advisory findings. rc12 ZIP
  preflight: zero blocker/required findings and the builder's known MLP014
  advisory for missing `files.md5`.
- Structural manifest/version check passes. All 131 payload files match source
  byte-for-byte; ZIP CRC passes. All 128 source PHP files and all 129 packaged
  PHP files (including `manifest.php`) are syntax-clean.
- `git diff --check` and the scoped secret/path review pass. Historical ignored
  archives remain local and are not release inputs.

## Artifact

`sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc12.zip`

SHA-256: `6e2927fef247c0a5218a1ef2c4b7c94e730dd6c711eb594d0af966cfe8482483`

Hosted ModuleScanner, the clean uninstall/install rehearsal, compiled metadata
inspection, and the exact fresh-browser prototype-then-production journey are
still required before QA acceptance. Do not install this customer package on
the stock tenant.
