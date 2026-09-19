<?php

// ── RETIRED: Quote.bd_comment_pending (🔒 1275 / G38) ───────────────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING. It is restored to the build, empty,
// because that is the only thing that retires a field this package already
// installed — omitting the file does not (§CW; rc24 proved it, six files
// dropped, clean install, INERT).
//
// Retired with the Bench quote-comment lane: core's erp_comment_requested_at is the live trigger, and the Bench duplicate handler is unreachable dead code (`writeback/quotes.py` is imported by nothing — 🔒 1277).
//
// It was DELETED from the build rather than emptied, so on every tenant that
// has it the field is still live and the retirement never took effect. Measured
// in served metadata on ophirsx177 2026-09-19: Quotes carried 17 bd_ fields
// while the shipped package declared 5.
//
// ZERO LIVE READERS OR WRITERS: not referenced by any of the 11 modules
// reachable from the `app.py` core's discovery.py imports, nor by the shipped
// Bench package. Control for that scan: `bd_quoted` and `bd_date_quoted` ARE
// found by it, and are restored rather than stubbed.
//
// The column itself stays in the database; removing a vardef does not drop it.
//
// 🛑 DO NOT RE-ADD, and DO NOT DELETE THIS FILE.
// `scripts/tests/test_vardef_orphans.py` fails if it goes missing.
