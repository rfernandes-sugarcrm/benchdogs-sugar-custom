# Bench Dogs 0.9.42-rc17 — SugarCloud scanner-safe estimating notifications

Release candidate. Not approved for production. SDK packages remain `1.18`;
requires shared ERP-Epicor `>= 1.1.24-rc9` and Partial Fulfillment `1.0.13`,
exactly as rc16.

## Why rc16 could not ship

On 2026-09-13 the Bench QA instance (Sugar ENT 26.1.0) refused the rc16 upload:

> File Issues — `custom/modules/Quotes/BdEstimatingNotificationHook.php`:
> Code attempted dynamically-named method call on line 521

Line 521 was the private logging helper's
`$GLOBALS['log']->{$level}($message)`. Sugar's `DynamicNameVisitor` treats any
method name that is an expression as a dynamically-named call, so one
occurrence rejects the whole package. Nothing was staged or installed; the
tenant kept rc12.

The hosted pre-flight run for rc16 passed because this repository's `MLP017`
pattern only matched `$obj->$name(`, not the brace form `$obj->{$name}(`.
A brace property read such as `$bean->{$field}` (line 397) is not a call and
is accepted by the scanner.

## Change

- `BdEstimatingNotificationHook::logOutcome()` now calls `error()`, `warn()`
  or `info()` by literal name — the only three levels its callers pass — with a
  comment recording that the shape is scanner-forced.
- `scripts/mlp_lint.py` `MLP017` also flags `->{…}(`, `::$name(` and
  `::{…}(`; `scripts/tests/test_mlp_lint.py` pins the rejected shape, the
  static forms and the allowed brace property read. The same rule and tests
  land upstream in `sugarcrm/erp-integration-sugar`.

No behavior, field, layout, dropdown or dependency changes.

## Evidence

Offline, recorded in the commit that introduced this document:

- `php -l` clean for the hook.
- `scripts/tests/test_mlp_lint.py`: 59 tests OK (1 skipped).
- `scripts/tests/test_estimating_notifications.py`: 13 tests OK.
- `scripts/tests/test_quoted_completion_lifecycle.py`: 4 tests OK.
- `scripts/mlp_lint.py` source audit: 0 blocker, 0 required, 0 advisory.

- With the original line 521 restored, the updated `MLP017` reports
  `1 blocker` at `BdEstimatingNotificationHook.php:521` (exit 1).
- Built with `php pack.php` (composer:2 image):
  `releases/sugarai_benchdogs_ext-0.9.42-rc17.zip`, 160,437 bytes, SHA-256
  `7973347c9071ee412329226a2803fc253518935a78da195a0a4a852d355e5bdd`;
  `check_built_packages.py` 0 problems; ZIP lint 0 blocker, 0 required, 1
  non-gating `MLP014` advisory (no root `files.md5`, unchanged from rc16).
- The real Sugar ENT 26.1.0 `ModuleScanner::scanPackage()` run on a local
  26.1.0 instance: extracted rc16 → `has_issues=yes`, "Code attempted
  dynamically-named method call on line 521" (reproduces QA exactly, control);
  extracted rc17 → `has_issues=no`.

Still required before any acceptance claim: fresh authorization for this new
artifact, then upload, install and verification on the Bench QA tenant only
(served `bd-send-estimating.js`/`bd-best-pricing.js`,
`bd01_ERP_Quote.quoted`/`date_quoted` metadata, module reads). Those module
reads are no longer possible: the mirror modules were retired by decision 901,
so this line records what rc17 needed, not what a current build verifies.

## Rollback

Same boundary as rc16: the retained rc12 artifact restores code and layouts
but does not remove the passive nullable `quoted`/`date_quoted` columns an
installed rc17 adds.
