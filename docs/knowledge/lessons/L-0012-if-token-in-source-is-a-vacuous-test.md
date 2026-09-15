---
id: BD-L-0012
title: '`if token in source` converts "I could not find it" into "there is nothing to check"'
rule: Assert that the thing you are about to make a claim about is PRESENT, and fail when it is absent; never make the assertion conditional on finding it.
severity: critical
subsystems: [benchdogs-sugar, testing-and-ci, data-migration]
paths:
  - scripts/tests/**
  - sugar-sell/ONEOFF-DropBdQuoteMirrorTables/scripts/post_execute.php
pins:
  # NOTE 2026-09-15: this suite exists only in the unlanded Phase-B tree
  # (.worktrees/bd01-phaseb/scripts/tests/). It is NOT on origin/main yet.
  - scripts/tests/test_bd01_mirror_retired.py
---

# `if token in source` converts "I could not find it" into "there is nothing to check"

**What happened.** A test named
`test_the_superseded_drop_package_refuses_before_it_can_do_anything` asserted that a disarm precedes every
destructive call:

```python
for call in ("dropTableName", "$bdMirrorTables", "DROP TABLE"):
    if call in source:            # <- NONE of these is in the file
        self.assertLess(disarm, source.index(call))
```

The real statement is `$bdConn->executeStatement('DROP TABLE IF EXISTS ' . $bdTable)`. **None of the three
guessed tokens appears**, so the loop body ran **zero times** and the test **passed having checked nothing —
on the single highest-consequence, irreversible line in the deliverable** (a drop of 473 rows that exist
nowhere else).

**The vacuity is the `if`.** Guarding an assertion on finding the token means *failure to locate* and
*nothing to verify* produce the identical green result. A rename, a reformat or — as here — **a guess that was
simply wrong** silently converts a safety test into a no-op.

**Why nothing caught it.** The suite was green, the test name described the right property, and the file it
pointed at was the right file. **A passing self-test is not evidence that the test checks what you think.**

**Rules.**
1. **Require the anchor present before you position it.** Assert the token exists; *then* assert the ordering.
   A rename must FAIL, loudly, rather than silently stopping the check.
2. **Mutation-prove both directions.** Here: rename the drop statement → must be KILLED; move a drop **above**
   the disarm → must be KILLED. Under the old assertion the rename mutant **passed**.
3. **Count what you checked.** If a loop can legitimately run zero times, assert the iteration count.
4. **The same shape, one level out, is rule 9 itself** — *before reporting an absence, prove the probe could
   have found a positive.* A test that cannot find its subject is reporting an absence.

**Related.** `BD-L-0011` (a guard on the wrong question), `BD-L-0013` (a grep proof must prove where it
landed), `BD-L-0014` (a filter that cannot express the question).
