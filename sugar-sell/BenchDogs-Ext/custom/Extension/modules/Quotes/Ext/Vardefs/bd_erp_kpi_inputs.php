<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare two fields on Quotes:
 *
 *   bd_quoted        whether the Bench Dogs ERP quote was quoted (bool)
 *   bd_date_quoted   when it was quoted (datetime)
 *
 * OWNER RULING, 2026-09-19: *"make sure you retire this bd_quoted /
 * bd_date_quoted"*, taken with the finding recorded beside it — **no core twin
 * exists** — and the sequencing the owner set: the connector stops writing
 * them FIRST, then the package retires them. The connector half shipped in
 * PR #8 (Bench connector verified at exactly two written fields on the
 * server); this stub is the package half.
 *
 * 🚩 NO CORE TWIN. Unlike bd_priced_at or bd_erp_total, nothing in ERP-Core
 * replaces these. They are retired because the quoted/date-quoted signal is
 * carried on the ERP quote model in core (`quoted` / `date_quoted` on
 * QuoteERPCore) rather than mirrored onto the Sugar Quote — not because a
 * Sugar-side equivalent exists. If a seller-visible "quoted on" is ever wanted,
 * it is a CORE field, declared once.
 *
 * WHY EMPTIED RATHER THAN DELETED. §CW / G37 — on Sugar Cloud only overwriting
 * the file retires it; rc24 proved dropping files installs clean and INERT.
 *
 * EXISTING DATA. Columns and values remain until removed deliberately.
 */
