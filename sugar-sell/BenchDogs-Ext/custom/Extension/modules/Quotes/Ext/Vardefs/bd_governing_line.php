<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Quotes:
 *
 *   bd_governing_line   the governing price break, as a Quote-level label
 *
 * 🔒 1044 retired it. The governing flag moved OUT of this package: it is `Products.erp_governing` (bool), declared by ERP-Epicor-PartialFulfillment and enforced by that package's ErpQuoteHooks/OpportunityContribution.php together with ERP-Core's QuoteOpportunityAmount.php. Bench ships no governing logic at all any more — no hook, no API, no transformer. This Quote-level copy had NO WRITER and was already stripped from the Bench panel by RETIRED_PANEL_FIELDS, so it only ever rendered empty or stale.
 *
 * WHY THIS FILE IS EMPTIED RATHER THAN DELETED. On Sugar Cloud, neither
 * omitting a file from the build nor uninstalling the package removes a
 * custom/Extension file a previous install already copied — proven on Bench
 * 2026-09-14, when rc24 dropped six such files and was INERT: installed clean,
 * fields still present. ONLY OVERWRITING THE FILE RETIRES IT. This stub is that
 * overwrite; see ERP_OrderLines/Ext/Vardefs/bd_shipped_value.php for the full
 * measurement.
 *
 * EXISTING DATA. Removing a vardef does not drop the column; stored values stay
 * until someone removes them deliberately, which is harmless — with no vardef
 * Sugar neither reads nor displays them. With no writer, every row is null.
 *
 * Safe to delete this stub outright once every instance has taken a release at
 * or after this one.
 */
