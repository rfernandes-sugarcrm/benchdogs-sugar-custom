---
id: BD-L-0014
title: A REST filter on a non-db relate field measures nothing, and returns a tidy zero anyway
rule: Before believing a REST count, check the field's vardef — filter a relationship through its LINK, and treat any filter on a `source => non-db` field as an absent instrument rather than a measured zero.
severity: high
subsystems: [benchdogs-sugar, quote-reflection, forecasting]
paths:
  - sugar-sell/BenchDogs-Ext/custom/modules/bd01_ERP_Quote/BdQuoteReflectionHook.php
---

# A REST filter on a non-db relate field measures nothing

**What happened.** A lane reported *"`opportunity_id` is empty on **133 of 133** quotes"* and named its
instrument in the same row: `opportunity_id $not_empty` → **0 of 133**. The conclusion was later shown to be
**true**; the instrument **could not have said anything else.**

**`opportunity_id` is not a column on `quotes`.** Sugar declares it
`'type' => 'relate', 'source' => 'non-db', 'link' => 'opportunities'`
(`modules/Quotes/vardefs.php:913-924`, **byte-identical in SugarEnt-Full 25.2.0 and 26.1.0**), and the truth
lives in the join table `quotes_opportunities` (`metadata/quotes_opportunitiesMetaData.php`). **There is no
stored column for `$not_empty` to test**, so that filter returns `0` on a tenant where every quote is linked
just as readily as on one where none is.

**Note the asymmetry that makes this hard to spot: a READ is fine.** The same field comes back **populated**
in a `fields=` projection, because the relate is filled through the link on retrieval. **Reading it works.
Filtering on it does not.** A lane that validated its probe by reading one known-linked record would still
have been misled.

**The family.** This is the same shape as the bool-filter trap — `filter[erp_is_primary_quote]=true` returns
the **complement**, because `intval("true") === 0` — and as `packages/installed` returning `total: 0` because
`packages` is an **object keyed by id** and `.length` on an object is `0`. In every case **the query cannot
express the question and answers with a plausible number instead of an error.**

**Rules.**
1. **Read the vardef before believing a count.** `source => non-db` means *there is nothing to filter on*.
2. **Filter a relationship through its link**: `{'opportunities.id': {'$not_empty': ''}}`, or sweep
   `GET /{module}/{id}/link/{link}` per record — and reconcile the two arms (`$empty` + `$not_empty` = total).
3. **Run rule-9 controls that can fail**: a deliberately misspelt link must return **404**, a known-populated
   link on the **same bean** must return a record, and a selective filter of the same shape must return a
   non-total number.
4. **When a dead instrument reached a true conclusion, record BOTH.** Retiring the number deletes a true fact;
   blessing the row endorses a probe that cannot work. Say which half survives.

**Related.** `BD-L-0012` (a check that cannot find its subject), `BD-L-0013` (a proof that does not prove
location).
