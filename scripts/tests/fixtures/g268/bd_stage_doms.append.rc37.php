<?php

/**
 * Bench Dogs stage keys, append-only (existing keys and labels untouched):
 *
 * quote_stage_dom 'Partially Fulfilled' - REQ-1: a subset of the quote's
 * lines has been ordered; the quote is neither open-untouched nor closed.
 * bd-order-winning-line sets it; QuoteAcceptSiblingReject ignores it (that
 * hook fires only on 'Closed Accepted'), which is exactly the point.
 *
 * sales_stage_dom 'Prototype Closed' / 'Partial Production Closed' -
 * REQ-22's agreed answer: express what closed in the OPPORTUNITY STAGE
 * rather than splitting records. Neither key is in the forecast
 * won/lost sets, so the remainder stays open pipeline.
 *
 * sales_probability_dom rows keep Sugar Logic's stage->probability
 * dependency defined for the new stages.
 *
 * Installed by post_install via ModuleInstaller::install_languages() - the
 * scanner-safe route BaseErpDropdown documents (direct file writes are
 * denylisted by ModuleScanner for uploaded package code).
 */

$app_list_strings['quote_stage_dom']['Partially Fulfilled'] = 'Partially Fulfilled';

$app_list_strings['sales_stage_dom']['Prototype Closed'] = 'Prototype Closed';
$app_list_strings['sales_stage_dom']['Partial Production Closed'] = 'Partial Production Closed';

$app_list_strings['sales_probability_dom']['Prototype Closed'] = 80;
$app_list_strings['sales_probability_dom']['Partial Production Closed'] = 90;
