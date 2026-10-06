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

---

## AMENDED 2026-09-15 — the known-bad control is **necessary and not sufficient**: it proves the scanner CAN refuse, it cannot prove you scanned the right BYTES

**What changed.** The rule above says to *"scan a known-rejected package in the same run so an inactive gate
cannot look like a pass."* **That is still correct and is not withdrawn.** It closes exactly one failure —
a gate that is switched off. It does **not** close the failure found on 2026-09-15, and the vault must not be
read as though it did.

**What happened.** A re-scan of a Bench Dogs candidate reported **105 files and TEN deny-list findings**
("dynamically-named function call"). The result was terrifying and entirely false: **it was not the artifact
that had been built.** `pack.php` **refuses to overwrite an existing zip**, and the rebuild had been run as
`php pack.php >/dev/null 2>&1`, which **silences the refusal and leaves the old zip in place, looking like a
successful build**. The scan had run against a superseded-design artifact.

**The known-bad control was green in that same run.** It could not have helped: it proves the scanner refuses
a planted `eval()` / `shell_exec`, and the scanner did — **on the wrong zip.** The trap was caught by the
**FILE COUNT** (105 against an expected 174), which is an identity check, not a gate check.

Two more instances of the same shape, both from the same release:
- A scan harness was found with `moduleInstaller.packageScan` **UNSET**, which short-circuits
  `rectorScanPreparedPackage()` to return true **without scanning anything** — the stack as found would have
  reported a clean PASS for any package whatsoever.
- The opposite failure on the same leg: it then **REFUSED a known-GOOD artifact** demonstrably running on
  three tenants. **A probe that can only say NO is exactly as useless as one that can only say YES.**

**Amended rule.** A scan result is trustworthy only when **three** things are asserted in the same run:
1. **the gate is active** — a known-bad control is REFUSED, and its twin with the plant removed PASSES, so the
   refusal is attributable to the plant and not to a rename;
2. **the bytes are the right bytes** — delete the exact zip, **read the "Done. Wrote" line**, and assert the
   **file count and the sha256 recorded next to the verdict**. **Never silence a build.** A rebuild of
   identical source yields a **different sha256** (ZipArchive stores mtimes), so re-hash the artifact on disk
   rather than trusting a hash recorded earlier;
3. **the leg can express both answers** — if it refuses an artifact already installed on a tenant, it is not a
   gate and its PASS means nothing.

**Related.** `BD-L-0010` (a superseded decision's file still ships), `BD-L-0013` (a proof of application must
prove *where* it landed) — the same failure in the mutation-testing register.
