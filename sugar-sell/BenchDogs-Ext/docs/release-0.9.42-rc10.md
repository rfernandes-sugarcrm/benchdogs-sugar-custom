# BenchDogs-Ext 0.9.42-rc10 — required installer language repair

Local candidate only. Not committed, pushed or installed by this preparation;
independent review and hosted acceptance remain required. Shared dependencies
remain ERP-Epicor `1.1.24-rc5` and Partial Fulfillment `1.0.13`.

## Evidence and change

After rc9 installation, the first hosted canary returned `policy_invalid_stage`
with correct customer policy inputs. A subsequent `bd-tools/repair-ui` call
reported stage installation, application-language rebuilding and metadata
repair successful, then both stage domains present. This identifies a concrete
installer/repair drift but is not by itself proof that a rerun succeeds.

The installer already shipped the append-only stage language fragment and
installed its template, but lacked the repair endpoint's explicit application
language compilation and metadata refresh. Its module-scoped extension rebuild
did not replace those steps.

The new final `post_execute` step uses native Sugar APIs:

1. `ModuleInstaller::rebuild_languages` compiles application language
   extensions for English, the default language and current installer language.
2. `MetaDataManager::refreshLanguagesCache` refreshes those language metadata
   entries after the other extension/relationship repairs.
3. `return_app_list_strings_language($language, false)` verifies uncached
   canonical lists: quote `Partially Fulfilled`, sales `Prototype Closed` and
   `Partial Production Closed`, and their probability mappings 80/90.

Missing domains, wrong probabilities or rebuild/refresh errors log and throw
only `BenchDogs-Ext: required stage language verification failed`. Installation
must not nominally succeed without this required capability. This refusal does
not roll back earlier lifecycle steps; independently inspect installed state
after an error, following the shared Module Loader lessons.

The installer no longer includes RevenueLineItems in its repair module list.
No customer records or stage policy are changed by this patch; no RLI bean
access, historical repair, shared writer change, SDK change or Python feature
deployment is included. The existing manual repair endpoint is unchanged.

## Offline validation

- Actual `post_execute` lifecycle regression was red against rc9: missing
  language rebuild, no verification, and the unwanted RLI repair reference.
- Seven new regressions pass: compile/refresh/verify order, append-only repeated
  upgrade, every required domain/probability, neutral exception refusal,
  current/default language checks, no RLI repair, and built installer parity.
  These execute the real installer with isolated Sugar API doubles, not a live
  Sugar install. Native API signatures/semantics were checked read-only against
  local Sugar source; no native DB operation was performed.
- Full Bench suite: 96 tests, 94 pass and two unchanged skips (native SugarLogic
  materialization unverified; copied ERP-Epicor fixture absent in this repo).
- Current shared MLP preflight: zero source and ZIP findings. This is not a
  hosted ModuleScanner result. No obsolete `files.md5` file was introduced.
- All 131 payload files match source byte-for-byte; manifest/version, exact
  packaged tree and ZIP CRC pass. All 129 packaged PHP files are syntax-clean.
- Compared with rc9, only `manifest.php` and `scripts/post_install.php` change
  inside the ZIP. Both versioned shared dependencies remain unchanged.
- Build/tests used network-disabled local Docker. Source mounted read-only;
  only the existing package release directory was writable for the build.
  Previous ignored archives are preserved, and `git diff --check` passes.

## Artifact

`sugar-sell/BenchDogs-Ext/releases/sugarai_benchdogs_ext-0.9.42-rc10.zip`

SHA-256: `48f0e8718f963f35f66b8508eafa4b9b179f7187cb0e3ab9326c9bdc0d9f6c6e`

Before accepting the repair, validate a hosted fresh/upgrade install **without**
calling the manual repair endpoint, check installed versions/source and the
canonical language domains, then capture the controlled prototype-first action
diagnostic and persisted Opportunity stage/probability. Check production-next
and reconciliation separately. Production readiness and native materialization
gaps are not cleared by an installer regression or a successful package scan.
