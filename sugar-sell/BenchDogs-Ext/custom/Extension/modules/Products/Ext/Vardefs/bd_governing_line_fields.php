<?php

// ── RETIRED: Product.bd_governing_origin (🔒 1044, completed here) ───────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING. It is restored to the build, empty,
// because that is the ONLY way to retire a field this package has already
// installed — and this one was never retired, only hidden from the source tree.
//
// WHAT WENT WRONG. 🔒 1044 retired `bd_governing_origin` and shipped an
// overwrite stub for the OPPORTUNITIES twin
// (`custom/Extension/modules/Opportunities/Ext/Vardefs/bd_governing_origin.php`).
// It did not ship one for the PRODUCTS twin, because by then the Products
// declaration was no longer visible in the tree: this file had been DELETED
// from the build rather than emptied. Deleting it removed the field from every
// future zip and from every grep — but not from any tenant that had already
// installed it. So the retirement read as complete and was half-applied.
//
// MEASURED IN THE ARTIFACTS, not the tree (the tree is exactly what misled the
// first pass). Across every Bench zip on disk, one filename ever declared
// `$dictionary['Product']['fields']['bd_governing_origin']` — this one:
//
//   rc29 rc31 rc32 rc33 rc34 rc35 rc36 rc38 rc40 rc41 : declared it
//   rc43 rc44 rc45 rc48 rc49 rc50                     : file absent entirely
//
// MEASURED ON THE TENANT (ophirsx177, 2026-09-19), served module metadata:
//   Opportunities  bd_*  = []                                    <- 1044 landed
//   Products       bd_*  = [bd_deleted_erp_sync_key,
//                           bd_governing_origin]                 <- 1044 did not
//   Accounts       bd_customer_group present                     <- control
// 159 fields on Products, so the empty Opportunities list is a real result and
// not a failed read.
//
// WHY EMPTYING AND NOT DELETING — AGAIN. On Sugar Cloud neither omitting a file
// from the build nor uninstalling the package removes a custom/Extension file a
// previous install already copied. rc24 proved it on Bench 2026-09-14: it
// dropped six such files, installed clean, and was INERT. Only overwriting the
// file retires it. The sibling stub
// `ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php` carries the full
// measurement; `Products/Ext/Vardefs/bd_line_order_fields.php` is the same
// pattern on this very module.
//
// ZERO WRITERS, ZERO READERS. Measured across benchdogs-sugar-custom,
// erp-integration-sugar, erp-integration-core and erp-integration-sdk: nothing
// declares or writes this field. The two remaining mentions in core are
// comments explaining why they do NOT use it — the per-line governing pin is
// `Products.erp_governing`, owned by ERP-Epicor-PartialFulfillment (decision
// 694), and `erp-quantity-breaks.js` records that reading `bd_governing_origin`
// was the bug it fixed, because "nothing in this lane ever writes" it.
//
// EXISTING DATA. Removing a vardef does not drop the column; stored values stay
// until someone removes them deliberately, which is harmless — with no vardef
// Sugar neither reads nor displays them. With no writer, every row is null.
//
// 🛑 DO NOT RE-ADD, and DO NOT DELETE THIS FILE. Deleting it is precisely the
// mistake being corrected. `scripts/tests/test_vardef_orphans.py`
// fails if the file goes missing or if the declaration reappears.
//
// Safe to delete this stub outright once every instance has taken a release at
// or after this one.

