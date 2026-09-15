---
id: BD-L-0016
title: A docstring is a claim, and this one was refuted live
rule: A behavioural assertion in shipped source needs a citable measurement, or it must be written as a hypothesis; never let a docstring close a question that would make a fix inert.
severity: high
subsystems: [benchdogs-sugar, erp-writeback, release-evidence]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/Quotes/**
---

# A docstring is a claim, and this one was refuted live

**What happened.** `connector-base` ships this sentence in
`connector_base/models/erp/quote_qty.py:10-12`, describing Epicor's quote-quantity rungs:

> *"Read-only, extraction-only: the worksheet rollups are ERP truth (**Epicor answers a write to them with 204
> and silently discards it**), so nothing in this connector ever builds one of these to send."*

**It was REFUTED live on 2026-09-15.** In a pre-declared Kinetic window the rung PATCH returned **204 and read
back** — both `OurQuantity` and `UnitPrice`, restored and re-read field by field. **There was never a
measurement behind that sentence** anywhere in the campaign record or in the commit that introduced it.

🚩 **The cost, had nobody checked: a defect fix shipped the same day would have been INERT.** The defect is a
rung carrying a hard `1.0` instead of the seller's quantity, and its fix is *"update the rung after create"* —
which the docstring declares impossible. A reviewer trusting the docstring would have rejected the correct fix.

**Why the sentence was so convincing.** It is specific (a status code), mechanistic (*discards*), scoped
(*those fields*), and it sits in the model class that owns the entity — every signal of a measured fact except
the measurement. **Its confidence was the whole problem.** The same file's neighbouring claims about rung
shape were accurate, which bought it credibility it had not earned.

**Rules.**
1. **A behavioural claim in shipped source needs a citation** — a test, a recorded probe, a journal section.
   No citation, no claim.
2. **Write unmeasured beliefs as hypotheses.** *"We do not write these; whether Epicor would accept a write is
   untested"* costs one line and is true.
3. **Before accepting "the far system refuses X", run the write once with a read-back**, and include a
   **negative control** that proves the instrument can detect a refusal — here, `DocUnitPrice` alone returned
   **400** on the same row, so the 204s are real successes and not a blind endpoint.
4. **A claim that would make a proposed fix inert is load-bearing.** Measure it before the fix is designed, not
   after it is reviewed.

**Note on scope.** The refutation is recorded in core
(`connector_core/erp_adapters/epicor/adapter.py:1218` and
`tests/unit/test_epicor_quote_rung_fixup.py:36`). **The shipped `quote_qty.py` docstring is still wrong** and
belongs to the connector lane; it is named here so a Bench Dogs reader does not inherit it.

**Related.** `BD-L-0008` (a far system's classification label is not the business fact), `BD-L-0014` (an
instrument that cannot express the question).
