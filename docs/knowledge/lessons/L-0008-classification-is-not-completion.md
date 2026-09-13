---
id: BD-L-0008
title: ERP classification is not estimating completion
rule: Advance the customer lifecycle only from the nullable Quoted and DateQuoted pair; unknown preserves state, and closed or ordered evidence outranks completion.
severity: critical
subsystems: [benchdogs-sugar, quote-reflection, estimating-handoff]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php
  - sugar-sell/BenchDogs-Ext/custom/Extension/modules/bd01_ERP_Quote/Ext/Vardefs/bd_quote_completion.php
pins:
  - scripts/tests/test_quoted_completion_lifecycle.py
---

# ERP classification is not estimating completion

**What happened.** `QuoteHed.CurrentStage=QUOT` was rendered as `Quoted`, and
the customer hook treated that label as proof that estimating finished. A
normal first sync could therefore stop the turnaround clock and notify sales
before an estimator changed anything.

Read-only EPIC06 evidence disproved that shortcut: every one of 90 open quote
headers was classified `QUOT`, but only 11 had `Quoted=true`. QuoteSvc metadata
defines `Quoted` as the quoter considering details and pricing complete and
defines `DateQuoted` as that business event's date. Across all 129
headers, 50 were quoted, 50 had a date, and the two facts never disagreed.

**Rule.** Classification and lifecycle are separate facts. Advance to priced
only for `Quoted=true` with a nonempty `DateQuoted`. An absent nullable field is
unknown and preserves the current Sugar lifecycle. Explicit false preserves an
active hand-off; an observed true-to-false change after priced marks revision.
A linked order or explicit
closed outcome always outranks completion state.

**Why nothing caught it.** Unit tests asserted a plausible human label instead
of comparing it with the far system's semantic fields, and they stopped before
the Sugar hook that consumed the label. No test represented unknown, false,
true, reopen and replay as distinct states.

**Applying it.** Store both exact fields without defaults and use their pair
as completion evidence. Do not invent sub-day precision from the observed
midnight `DateQuoted` values: use Sugar's first observation of the priced
transition for elapsed-time endpoints, and test the full state matrix. Repeat
true must be stable; a false-to-true revision may
notify again through the existing stage edge, while first-cycle timestamps
remain immutable. This customer policy consumes the facts; core continues to
own transport and must omit unknown nulls at its generic container boundary.
