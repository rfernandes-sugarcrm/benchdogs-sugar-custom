---
id: BD-L-0013
title: A grep proof that a mutation was applied must prove WHERE it landed
rule: Anchor every mutation on a token unique to the target, and count the marker inside the target function's own body before reading the mutant's result.
severity: high
subsystems: [benchdogs-sugar, testing-and-ci]
paths:
  - scripts/tests/**
pins:
  # NOTE 2026-09-15: this suite exists only in the unlanded Phase-B tree
  # (.worktrees/bd01-phaseb/scripts/tests/). It is NOT on origin/main yet.
  - scripts/tests/test_bd01_mirror_retired.py
---

# A grep proof that a mutation was applied must prove WHERE it landed

**What happened.** A mutation battery reported two mutants as **SURVIVORS** and three others as killing tests
in the wrong lane. Both readings were wrong. The substitution anchor `wire["UnitPrice"] = line.unit_price`
occurs **twice** in the same file — in `_order_line_wire` first and `_quote_line_wire` second — and a
non-global `perl -0pi` takes the **first**. **Six mutations landed on the order lane** while
`grep -c MUTMARK` returned `1` and the mutation looked applied.

**It WAS applied. Just not where the label said.**

**Why this is dangerous in the worst direction.** An apply-proved mutation in the wrong function is
**indistinguishable from a survivor**, and a survivor is read as *"this guard cannot be killed"* — so the
failure manufactures a false confidence in coverage, which is the one thing the battery exists to disprove.

**Rules.**
1. **A grep proof of application is necessary and NOT sufficient.** `grep -c` answers *"did the text change
   anywhere?"* The question is *"did the text change HERE?"*
2. **Anchor uniquely, or assert the location.** Either choose a token that occurs once in the file, or count
   markers **inside the target function's own body** (`awk '/^def <fn>/,0'`) before the mutant's result is
   read.
3. **Read the count, not the exit code.** A non-global substitution that hits one of two sites still exits 0.
4. **The same rule for patches, not only mutations:** prove where a hunk landed, not only that the file
   changed.

**Related.** `BD-L-0009` (a scan must prove it scanned the right bytes — the same failure in the packaging
register), `BD-L-0012` (an assertion that never ran).
