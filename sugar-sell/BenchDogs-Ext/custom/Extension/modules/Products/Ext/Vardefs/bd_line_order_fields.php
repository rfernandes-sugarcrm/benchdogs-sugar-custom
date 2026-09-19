<?php

// ── RETIRED: Product.bd_erp_line_num (🔒 1032) ───────────────────────────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING, and is kept rather than deleted so
// that an UPGRADE overwrites the old declaration on tenants that already have
// it. Deleting the file would leave those tenants declaring a field with no
// writer and no reader, which is the leftover shape this package has been bitten
// by before.
//
// What stood here was the Kinetic line xref for the native quoted line items.
// It is now CORE'S FIELD: `Products.erp_quote_line_num`, an ERP-Core vardef
// written by connector-core's `QuoteLineCoreTransformer`. Both Bench readers
// were repointed onto it in the same change — the release-stage policy
// (`custom/modules/Quotes/ErpQuoteHooks/OpportunityReleaseStagePolicy.php`) and
// the quoted-lines grid column (`custom/modules/Quotes/BdQliColumnsLayout.php`).
//
// It was never a value only Bench could compute: core's `rung_key()` has always
// built `<company>__<QuoteNum>_<QuoteLine>_<QtyNum>`, so the number sat inside
// the sync key of every connector-owned line. This column existed only to make
// it readable.
//
// MEASURED on sugar.local.dev 2026-09-18, before the promotion:
//   bd_erp_line_num    > 0 :   6 of 655 native quote line items
//   erp_quote_line_num > 0 :   0 of 655
//   connector-owned rows whose key already yields a line number : 515 of 521
// Bench's writer had gone with the retired quote mirror, so this column was
// already failing the guard that reads it — 13 of the 14 ORDERED lines on that
// tenant had no value. Core reaches 515.
//
// THE COLUMN ITSELF STAYS IN THE DATABASE, same as `bd_to_order` and
// `bd_ordered` before it (retired in 0.9.39). A vardef removal does not drop a
// column, and nothing here tries to.
//
// 🛑 DO NOT RE-ADD. `scripts/tests/test_line_num_owner.py` fails if this field
// name reappears anywhere in the package.
