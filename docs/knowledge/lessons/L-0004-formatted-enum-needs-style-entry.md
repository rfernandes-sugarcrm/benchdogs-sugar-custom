---
id: BD-L-0004
title: A formatted enum needs both its label and its style entry
rule: When a Sugar record layout uses enum-cascade with formatting enabled, ship and verify a DropdownsStyle entry for every custom domain key.
severity: high
subsystems: [benchdogs-sugar, sidecar-presentation, module-loader-install]
paths:
  - sugar-sell/BenchDogs-Ext/custom/Extension/application/Ext/Language/en_us.bd_stage_doms.php
  - sugar-sell/BenchDogs-Ext/custom/Extension/application/Ext/DropdownsStyle/sales_stage_dom_style.php
  - scripts/tests/test_stage_dropdown_style.py
---

# A formatted enum needs both its label and its style entry

**What happened.** After the language lifecycle was repaired, a real prototype
release returned `updated`; the API and a fresh Sidecar model both contained
`Prototype Closed`/80, but the Opportunity record showed a blank Sales Stage.
Route-away/back and a hard reload reproduced the blank value, ruling out the
canary's polling order and client-model staleness. The layout renders
`sales_stage` as `enum-cascade`. Its compiled style map had
`applyFormatting: true` and entries only for shared stages, not the two Bench
keys. A stock styled stage rendered normally in the same field.

**Rule.** Domain presence proves that a value is valid and translatable; it
does not prove that a formatted field can present it. Every customer-owned key
added to a formatted Sugar dropdown also needs a customer-owned, append-only
entry under `custom/Extension/application/Ext/DropdownsStyle/`.

**Applying it.** Keep the stage decision in the customer policy and the save in
the shared writer. Add presentation metadata rather than a second writer, a
field override or a cache workaround. Assign each customer key only when that
exact style is absent: replacing the entire map loses shared styles, while an
unconditional child assignment still loses a tenant's same-key choice. Test
both cases, repeated inclusion, exact ZIP/source parity and a fresh browser's
model plus visible DOM. An API assertion alone cannot close a seller-experience
requirement.
