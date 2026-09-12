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
values looked valid in isolation, but the last trigger silently won. The first
one-writer candidate then created a real one-line prototype order while leaving
the Opportunity at Proposal for 60 seconds. The action had loaded
`Quote.products` before stamping `erp_ordered`, but a candidate that freshly
retrieved Products failed identically, disproving that relationship as the
complete cause. A later hosted run proved every Product and ERP quote-line
identity was correct after the request, yet the policy still preserved the
stage: post-request REST reads do not prove what an already-loaded, multi-level
Link2 bean graph exposed inside the action. The next candidate keeps Bench
vocabulary in a decision-only provider, routes Kinetic reconciliation through
the same shared dispatcher, and re-retrieves the ERP quote, its ERP lines and
linked Products outside BeanFactory cache. Hosted validation is still required.

**Rule.** A customer package may decide its stage vocabulary, but must not save
the shared field. Every path that observes a release must invoke the shared
owner with the same fixed contract.

**Applying it.** Preserve pre-order Proposal initialization separately. Refuse
stage classification when ERP-line identity is missing or ambiguous. When a
hook follows writes to related records, use relationship IDs and fresh bean
retrieval rather than trusting any level of an already-loaded relationship
snapshot. A clean REST read after the request is only persistence evidence; it
cannot clear an in-request observation defect. Test stale snapshots at each
role-bearing level as well as prototype-only, production and mixed releases.
