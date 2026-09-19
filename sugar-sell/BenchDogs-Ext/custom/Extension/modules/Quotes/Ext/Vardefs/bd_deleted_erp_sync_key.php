<?php

// ── RETIRED: Quote.bd_deleted_erp_sync_key (🔒 1274 / G38) ───────────────────
//
// THIS FILE DELIBERATELY DECLARES NOTHING. Same field name, same retirement and
// same mechanism as the PRODUCTS copy — and that is exactly why it was nearly
// missed.
//
// 🚩 THE SAME FIELD NAME WAS DECLARED ON TWO MODULES. The first census mapped
// each field name to ONE file by taking the first match while walking the zips
// newest-first, so the Products declaration won and this one never appeared in
// the map. It only surfaced because the post-install read of served metadata
// still showed the field on Quotes after the Products copy was retired.
// A field name is not a key. Map (module, field) -> file, never field -> file.
//
// MEASURED: declared by custom/Extension/modules/Quotes/Ext/Vardefs/
// bd_deleted_erp_sync_key.php in rc29 rc31 rc32 rc33 rc40 rc41; the file is
// ABSENT from rc43 onward — deleted from the build rather than emptied, so it
// stayed live on the tenant (§CW).
//
// ZERO WRITERS, ZERO READERS across benchdogs-sugar-custom, erp-integration-sugar,
// erp-integration-core and erp-integration-sdk. Core tracks deletions in
// `identity_xref`, which is the layer that owns them.
//
// 🛑 DO NOT RE-ADD, and DO NOT DELETE THIS FILE.
