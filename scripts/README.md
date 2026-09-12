# MLP pre-flight

Two checks that run before a package reaches an instance, plus the linter's own
test suite. Both are plain Python 3 with no dependencies, so they run anywhere
`python3` does.

```bash
python3 scripts/tests/test_mlp_lint.py     # the linter's own tests, 56 of them
python3 scripts/mlp_lint.py                # audit the package source
python3 scripts/mlp_lint.py --explain MLP001
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

They are copied here rather than referenced because this repository builds and
ships its own package to the same instances, and a gate that lives in another
repository does not run on this one's pull requests. The cost of the copy is
that a rule added upstream does not arrive here by itself. When syncing, take
`mlp_lint.py`, `check_built_packages.py` and `tests/test_mlp_lint.py` together:
the tests are what make the linter trustworthy, and a rule without its test is
how a false positive gets the whole job switched off.

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
instance, which owns the authoritative deny-lists. `MLP002` and `MLP017` are
cheap pre-flights for the rejections these packages have actually hit, not a
reimplementation, and a clean run here is not a promise that the loader will
accept the package.
