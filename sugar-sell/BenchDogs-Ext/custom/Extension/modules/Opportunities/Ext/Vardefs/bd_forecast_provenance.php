<?php

/**
 * RETIRED. This file intentionally declares no fields.
 *
 * It used to declare one field on Opportunities:
 *
 *   bd_forecast_managed_value   the managed forecast value on the Opportunity
 *
 * 🔒 1044 retired it. ORPHANED BY ITS OWN WRITER'S RETIREMENT, same cause as bd_governing_origin: added by 993aa66, writer removed by 6e483c1 / 55bf113. The surviving test scripts/tests/test_headline_valuation_owner.py still asserts managed_value == 280/530/560 against ERP-Core's QuoteOpportunityAmount.php — but that file contains ZERO references to any bd_ field (its only 'bd_' string is a comment naming bd_sent_to_estimating_at), and the test is currently RED. The assertion outlived the code it described.
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
