# MLP pre-flight

Two checks that run before a package reaches an instance, plus the linter's own
test suite. Both are plain Python 3 with no dependencies, so they run anywhere
`python3` does.

```bash
python3 scripts/tests/test_mlp_lint_pin.py # the linter is upstream's, unedited (G318)
python3 scripts/tests/test_mlp_lint.py     # the linter's own tests, 105 of them
python3 scripts/mlp_lint.py                # audit the package source
python3 scripts/mlp_lint.py --explain MLP019
```

And against a built artifact, which is where the manifest rules can actually be
evaluated:

```bash
for p in sugar-sell/*/pack.php; do (cd "$(dirname "$p")" && php pack.php); done
python3 scripts/check_built_packages.py
python3 scripts/mlp_lint.py --zips-from sugar-sell
```

Every directory under `sugar-sell` with a `pack.php` is a package the loader
will be handed, the disposable one-off repairs included, so build them all.
CI does the same. A builder nothing exercises is one that breaks quietly and is
found halfway through fixing a broken instance.

`pack.php` needs PHP's `zip` extension. The stock `php:8.2-cli` image does not
ship it; `composer:2` does, which is the quickest way to build locally.

## Where these came from

Both scripts are taken from `sugarcrm/erp-integration-sugar`, where they were
written after an ERP-Epicor release died mid-install on a demo instance with
`Cannot redeclare class ErpDashboardReconcile`, was never recorded in
`upgrade_history`, and left that instance with its ERP modules gone and the
Quotes list throwing. The defect was two install scripts loading one class from
two different paths, visible in the source the whole time.

They are carried here rather than referenced because this repository builds and
ships its own package to the same instances, and a gate that lives in another
repository does not run on this one's pull requests.

## Vendored and pinned, never edited (G318)

Carrying a copy has a cost: a rule added upstream does not arrive by itself.
Through rc67 this repository's copy was hand-synced, had fallen to 17 of
upstream's 19 rules, and CI passed pull requests without ever running MLP018 or
MLP019. MLP019 is the rule whose violation (naming
`DeployedMetaDataImplementation`) makes SugarCloud refuse a package mid-install.

Both repositories are private and in different GitHub accounts, so CI cannot
fetch upstream's file. Instead the linter, its tests and their fixtures are
**vendored byte for byte** from `sugarcrm/erp-integration-sugar` and pinned by
sha256 to one upstream commit in `mlp_lint.PINNED.json`:

- `tests/test_mlp_lint_pin.py` runs first in CI's `rules` job. Every vendored
  file must be upstream's file at the pinned commit, and the linter must have
  MLP001 to MLP019. A hand edit, a partial sync or a stale copy fails there.
- Wherever the `erp-integration-sugar` checkout is present, the same test also
  compares the pin with upstream's branch head, so upstream moving on fails
  that run.
- To take upstream's current linter:
  `git -C ../erp-integration-sugar fetch origin fix/order-selected-lines-hide-once-submitted`,
  then `python3 scripts/refresh_mlp_lint.py`, run the suite, and commit the
  refreshed files with the pin.

Never edit a vendored file here. Fix it upstream and re-vendor. There is one
declared local delta: `test_mlp_lint.py`'s real-scanner test floors its file
count at 12 instead of upstream's 100, because this repository has 18 PHP files
since rc69 cut BenchDogs-Ext to 7 (G280; it was 40 of 61 through rc68).
The delta is recorded in the pin, and the check reverses it before hashing.

## Why there is no baseline file

Upstream carries `mlp_lint_baseline.json` because its rules were written after
its packages, so landing the gate red would have meant blocking every unrelated
pull request. This package audited clean the day the rules arrived, once the two
`MLP001` blockers they found were fixed, so there is no debt to record and the
gate starts green.

If a finding ever genuinely has to be deferred, `--update-baseline` writes one.
Say in the pull request why it is being carried rather than fixed. Do not use it
to silence a finding you introduced.

## What this is not

Not ModuleScanner. The real upload gate is `ModuleScanner::scanPackage()` on the
instance, which owns the authoritative deny-lists. `MLP002` carries those lists
and, where a SugarEnt tree is present, is checked against the real scanner over
every PHP file here. But the scanner also runs Rector and syntax checks
(`MLP019` pre-flights the one Rector refusal these packages have hit), so a
clean run here is not a promise that the loader will accept the package.
