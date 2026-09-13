---
id: BD-L-0009
title: A green pre-flight is a model of the hosted scanner, not the scanner
rule: Gate every release candidate on Sugar's real ModuleScanner run against the built package, with a known-rejected package as a control, because the repository lint only matches the call shapes someone has written patterns for.
severity: high
subsystems: [benchdogs-sugar, package-build, estimating-handoff]
paths:
  - sugar-sell/BenchDogs-Ext/custom/**
  - scripts/mlp_lint.py
  - scripts/tests/test_mlp_lint.py
pins:
  - scripts/tests/test_mlp_lint.py
---

# A green pre-flight is a model of the hosted scanner, not the scanner

**What happened.** rc16 passed the hosted pre-flight (CI run 34735165577) and
the repository lint reported zero blockers. On the Bench QA instance SugarCloud
refused the upload outright: `BdEstimatingNotificationHook.php` "Code attempted
dynamically-named method call on line 521". The line was a logging helper,
`$GLOBALS['log']->{$level}($message)`. The lint's `MLP017` rule existed for
exactly this class of rejection, but its pattern matched `$obj->$name(` and not
the brace form `$obj->{$name}(`, nor dynamic static calls. The anti-pattern was
treating "pre-flight green" as "the scanner will accept it".

Running Sugar 26.1.0's own `ModuleScanner::scanPackage()` locally against the
extracted rc16 package reproduced the QA rejection line-for-line, and against
the rebuilt rc17 returned no issues.

**Rule.** The lint is a cheap early warning; the real scanner is the gate.
Before calling a candidate installable, scan the built ZIP with the platform's
scanner and scan a known-rejected package in the same run so an inactive gate
cannot look like a pass. When the scanner finds something the lint missed, add
the shape to the lint with a test that reproduces the rejected line.

**Applying it.** Keep logging and dispatch as literal method names and comment
that the shape is scanner-forced. A brace property read (`$bean->{$field}`) is
not a call and is accepted. Record the scanner result next to the artifact
hash in the release note.

**Related.** Shared `erp-integration-sugar` lesson L-0032 (scanner refuses
dynamic calls) and L-0079 (a lint rule must be proven against the real
scanner).
