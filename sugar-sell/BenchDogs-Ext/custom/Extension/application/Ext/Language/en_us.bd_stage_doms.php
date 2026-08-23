<?php
// Bench Dogs stage vocabulary (REQ-1/REQ-2/REQ-22).
//
// Application-level language extension - the SAME mechanism this package's
// module-scoped labels already deploy through (verified live), chosen after
// the post_install ModuleInstaller::install_languages() route silently
// failed to land these keys on SugarCloud. Rebuild merges this file into
// the cached app strings; no installer-context API calls involved.
//
// 'Partially Fulfilled': a subset win keeps the quote OPEN - deliberately
// not 'Closed Accepted', so the remaining ladder lines stay live.
$app_list_strings['quote_stage_dom']['Partially Fulfilled'] = 'Partially Fulfilled';
// REQ-22: the opportunity stays open and its stage says which slice closed.
$app_list_strings['sales_stage_dom']['Prototype Closed'] = 'Prototype Closed';
$app_list_strings['sales_stage_dom']['Partial Production Closed'] = 'Partial Production Closed';
$app_list_strings['sales_probability_dom']['Prototype Closed'] = 80;
$app_list_strings['sales_probability_dom']['Partial Production Closed'] = 90;
