<?php

// ── RETIRED: Quote.bd_erp_stage_code (🔒 1045) ───────────────────────────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING.
//
// 🔒 1045 retired it as "a private copy of something core already owns" — the
// connector's own `models/crm/sell/quote_kpi.py` says so at the foot of the
// module. It is referenced there ONLY in that retirement note: measured with
// docstrings and comments stripped, it does not appear in executable code,
// while `bd_quoted` and `bd_date_quoted` in the same file do.
//
// IT USED TO SHARE `bd_erp_kpi_inputs.php` WITH THOSE TWO. That file is
// restored to the build (they are live writes); this stub carries the
// retirement separately so the overwrite lands either way — a tenant holding
// the rc41 copy of `bd_erp_kpi_inputs.php` gets it overwritten by the restored
// file, which no longer declares this field.
//
// 🛑 DO NOT RE-ADD.
