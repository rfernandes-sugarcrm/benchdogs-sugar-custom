---
id: BD-L-0011
title: A guard that fires on the wrong question is worse than no guard
rule: A safety guard must assert the precondition that makes the operation safe, at the moment the operation runs — not an adjacent condition that happens to be checkable.
severity: critical
subsystems: [benchdogs-sugar, module-loader-uninstall, data-migration]
paths:
  - sugar-sell/ONEOFF-DropBdQuoteMirrorTables/scripts/post_execute.php
  - sugar-sell/ONEOFF-DropBdQuoteMirrorTables/pack.php
---

# A guard that fires on the wrong question is worse than no guard

**What happened.** A one-off package existed to drop the Bench Dogs quote **mirror** tables. Its candidate list
also named `bd01_erp_quote_cost`, `bd01_erp_quote_cost_cstm` and `bd01_erp_line_costs_c` — **the cost
worksheet, 473 rows that exist nowhere else.** The package carried an ordering guard, so the hazard looked
contained.

**It was not. The guard passed *after* the phase it was meant to protect.** It asked *"has Phase B run?"* —
a question that becomes true at exactly the moment the drop becomes destructive, because Phase B is the
retirement. So the guard would have **refused once, on a different question, and then dropped the only copy.**

**Nothing was lost, and that is luck rather than design:** the package has never been built, so no zip exists.
The hazard was **latent** — and it had been latent in exactly this way before two separate lanes looked at it.

**Why nothing caught it.** The guard existed, was readable, had a sensible name, and **passed**. A guard that
passes is read as a guard that protects. Nobody asked *which* proposition it establishes.

**Rules.**
1. **Name the invariant the operation needs, then assert THAT.** Here the invariant is *"every row about to be
   dropped has already been re-parented and is readable at its new home"* — not *"a build phase has
   completed."*
2. **Assert it against the instance, at run time.** The authoritative fix re-proves the row match **on the
   instance** and refuses unless the re-parent actually completed. A list-only edit that removes the three
   tables from the candidate array is weaker: it is correct today and silent tomorrow.
3. **A guard's pass must be falsifiable.** Plant a mutation that makes the operation unsafe and prove the guard
   goes red. If no mutation can turn it red, it is not a guard.
4. **Protection must travel with the deliverable.** Two working trees held the disarm and **neither held a
   commit**; a fresh clone or a CI checkout would have had neither. It belongs inside the patch that ships.

**The rule in one line, because it generalises past this package:** *a guard that fires on the wrong question
is worse than no guard — it manufactures confidence.* With no guard, someone checks by hand.

**Related.** `BD-L-0012` (a test that checks nothing and passes), `BD-L-0005` (omitting a file is not an
upgrade removal).
