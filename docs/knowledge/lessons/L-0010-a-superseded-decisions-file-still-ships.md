---
id: BD-L-0010
title: A superseded decision's file still ships, and a closure-declared vardef burns a version
rule: Delete the artifacts of a superseded decision from the tree when the decision is superseded, and declare vardef arrays literally — a closure invoked through a variable is a dynamically-named call and the hosted scanner refuses it.
severity: high
subsystems: [benchdogs-sugar, package-build, module-loader-install]
paths:
  - sugar-sell/BenchDogs-Ext/custom/Extension/modules/Products/Ext/Vardefs/**
  - sugar-sell/BenchDogs-Ext/pack.php
pins:
  - scripts/tests/test_mlp_lint.py
---

# A superseded decision's file still ships, and a closure-declared vardef burns a version

**What happened.** Decision 105 proposed a **core home** for the Bench Dogs cost worksheet on `Products`.
Decisions 108 and 110 **superseded it**: promotion to core is **REJECTED**, the worksheet stays a Bench
module, and decision 109 re-parents it onto the native rung via a new `bd01_erp_rung_costs` relationship.

**The decision died. The file did not.** `custom/Extension/modules/Products/Ext/Vardefs/bd_cost_worksheet.php`
stayed in the tree and was still being packaged. It declared its fields **through closures invoked as
`$bdCostCurrency(...)`**, and **ModuleScanner flags every such call as a dynamically-named function call.**
The file surfaced only by accident, inside a stale-zip scan that reported ten deny-list findings.

**Had decision 105 survived, that file would have failed the HOSTED scan and burned a version number.**
On SugarCloud a refused upload is not free: the version is spent, the next candidate must renumber, and on a
package whose `id_name` is already installed on a tenant a burnt number can make a lower version permanently
uninstallable there.

**Why nothing caught it.** The repository lint matches the call shapes someone has written patterns for; the
local harness had been passing on a **different zip**. And no check exists that asks *"is every file in this
package still wanted by a live decision?"* — supersession is recorded in the decision register, not in the
build.

**Rules.**
1. **When a decision is superseded, delete its artifacts in the same change.** A vardef, an Extension file or
   a relationship left behind is shipped bytes, and shipped bytes are scanned and installed.
2. **Declare vardef arrays literally.** No closure held in a variable, no callable table, no dynamic dispatch —
   the hosted scanner refuses all of it. Where a literal shape looks redundant, **comment that it is
   scanner-forced**, or the next tidy-up reintroduces the refusal.
3. **Reconcile the built file list against the decision register before a release candidate is scanned**, not
   after. A file count that surprises you is the cheapest defect detector in the build.

**Related.** Shared `erp-integration-sugar` lesson **L-0032** (the hosted scanner refuses a closure called
through a variable) states the platform rule; this lesson records the Bench Dogs instance and the version-cost
consequence. `BD-L-0009` covers proving you scanned the right bytes.
