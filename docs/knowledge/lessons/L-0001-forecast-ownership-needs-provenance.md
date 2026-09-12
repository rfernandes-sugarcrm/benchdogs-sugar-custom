---
id: BD-L-0001
title: Forecast ownership needs provenance, not value comparison
rule: Record the exact last system value and independent ownership state before preserving or replacing a native forecast field.
severity: high
subsystems: [benchdogs-sugar, forecasting]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php
  - sugar-sell/BenchDogs-Ext/custom/Extension/modules/Opportunities/Ext/Vardefs/bd_forecast_provenance.php
---

# Forecast ownership needs provenance, not value comparison

**What happened.** The shared Quote hook correctly changed Opportunity amount
from the all-options total to the selected governing option plus prototype,
tax and shipping. Bench then compared Best/Worst with the *new* amount to
decide whether their old values were system-owned. The old system-written
$16,250 no longer matched the new $7,100.63, so both were misclassified as
human overrides and frozen.

**Rule.** A mutable value cannot prove who owns it. Keep an explicit origin for
each independently editable field and the exact last system-written value.
When a visible field diverges from that value, transfer only that field to
human ownership and never reclaim it merely because numbers later coincide.

**Applying it.** Let the shared owner write and currency-convert Opportunity
amount first. Bench forecast logic consumes that persisted result. During a
one-time upgrade with no provenance, adopt only values an earlier version is
known to have written: zero, the current headline, or the legacy deliverable
sum. Treat every other value as human-owned. Test system repricing and a human
override of only one case in the same sequence; isolated first-write tests do
not expose the stale-value bug.
