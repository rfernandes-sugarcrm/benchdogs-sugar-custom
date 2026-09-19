<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Quotes:
 *
 *   bd_priced_at   when the Bench Dogs ERP quote was priced (datetime)
 *
 * 🔒 1044 retired it. There is exactly ONE priced-at surface on a quote and it
 * belongs to ERP-Core: `Quotes.erp_priced_at`, declared in
 * ERP-Core/src/custom/Extension/modules/Quotes/Ext/Vardefs/erp_priced_at.php
 * and STAMPED by ERP-Core/src/custom/modules/Quotes/ErpEstimatingStamps.php,
 * which is registered as a live logic hook in
 * ERP-Core/src/custom/Extension/modules/Quotes/Ext/LogicHooks/
 * erp_estimating_stamps.php. Core also draws it: ERP-Epicor's QuotesLayout
 * puts `erp_priced_at` on the record view (line 222) and offers it in the
 * list-view chooser (line 241).
 *
 * WHY IT WAS WRONG, NOT MERELY DUPLICATED. Bench's copy had NO WRITER BEHIND
 * IT — measured across connector_ext_benchdogs: zero references outside tests,
 * and the only consumer was a readonly record-view row in
 * BdQuotesLayoutExtensions.php. So the seller saw a "Priced At" that could
 * never fill, immediately beside core's `erp_priced_at` that does. "Not priced
 * yet" and "this package never measured it" rendered identically — the same
 * defect class as bd_shipped_value (user decision 59), where 304 of 304 rows
 * read a fabricated default.
 *
 * WHY THIS FILE IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither
 * omitting a file from the build nor uninstalling the package removes a
 * custom/Extension file that a previous install already copied — proven on
 * Bench 2026-09-14, when rc24 dropped six such files and was INERT: installed
 * clean, fields still present. ONLY OVERWRITING THE FILE RETIRES IT. This stub
 * is that overwrite. See the sibling
 * ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php for the full measurement.
 *
 * EXISTING DATA. Removing a vardef does not drop the column. Stored values stay
 * in quotes_cstm.bd_priced_at until someone removes them deliberately, which is
 * harmless: with no vardef Sugar neither reads nor displays them. Nothing of
 * value is there in any case — with no writer, every row is null.
 *
 * Safe to delete this stub outright once every instance has taken a release at
 * or after this one.
 */
