---
id: BD-L-0002
title: A customer release-stage policy is not a second writer
rule: Return a stage decision through the shared seam and route every release trigger through the shared owner.
severity: high
subsystems: [benchdogs-sugar, forecasting]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php
  - sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php
---

# A customer release-stage policy is not a second writer

**What happened.** Bench reflection advanced Opportunity stage from synced ERP
facts while Partial Fulfillment advanced it from Order Selected Lines. Both
values looked valid in isolation, but the last trigger silently won. The repair
moves Bench vocabulary into a decision-only provider and routes Kinetic
reconciliation through the same shared release dispatcher.

**Rule.** A customer package may decide its stage vocabulary, but must not save
the shared field. Every path that observes a release must invoke the shared
owner with the same fixed contract.

**Applying it.** Preserve pre-order Proposal initialization separately. Refuse
stage classification when ERP-line identity is missing or ambiguous, and test
prototype-only, production, mixed and contradictory shapes.
