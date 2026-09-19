<?php

// ── RETIRED: Product.bd_deleted_erp_sync_key (decision 1273) ─────────────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING, for the same reason and by the same
// mechanism as its neighbour `bd_governing_line_fields.php`: it was DELETED
// from the build rather than emptied, so it is gone from the tree and still
// live on every tenant that installed it.
//
// MEASURED IN THE ARTIFACTS:
//   rc29 rc31 rc32 rc33 rc34 rc35 rc36 rc38 rc40 rc41 : declared it (varchar)
//   rc43 rc44 rc45 rc48 rc49 rc50                     : file absent entirely
//
// MEASURED ON THE TENANT (ophirsx177, 2026-09-19): `bd_deleted_erp_sync_key` is
// still served in Products module metadata, one of only two bd_* fields left
// there.
//
// ZERO WRITERS, ZERO READERS. Nothing in benchdogs-sugar-custom,
// erp-integration-sugar, erp-integration-core or erp-integration-sdk declares,
// writes or reads this name — not one mention, not even a comment. It is the
// residue of the retired bd01_* quote mirror's soft-delete bookkeeping; core
// tracks deletions in `identity_xref`, which is the layer that owns them.
//
// 🚩 THIS RETIREMENT IS A RECORDED ASSUMPTION, NOT A PRIOR RULING. 🔒 1044
// named four fields and this was not among them, because it was already
// invisible in the tree when that census ran. It is retired here on the owner's
// standing pattern — fewer layers, core owns it — and on the measurement above.
// Reversing it costs one file.
//
// EXISTING DATA. Removing a vardef does not drop the column; stored values stay
// and are harmless with no vardef to read them.
//
// 🛑 DO NOT RE-ADD, and DO NOT DELETE THIS FILE.
// `scripts/tests/test_vardef_orphans.py` fails if it goes missing.
