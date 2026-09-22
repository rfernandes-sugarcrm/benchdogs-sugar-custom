<?php

// G278 / 🔒 1506 — "this hsoudl happen in the core". THE TWO RELEASE
// MILESTONES ARE OPPORTUNITY STAGES, AND THIS PACKAGE OWNS THEM.
//
// They belong beside 'Partially Fulfilled' (the sibling _override fragment):
// all three say the same thing on three records - a SUBSET of the work has
// been released to the ERP and the rest is still live. The quote says it on
// quote_stage, the Opportunity says it on sales_stage.
//
// 🛑 THE KEY STRINGS ARE DATA, NOT LABELS. Opportunities on Bench already
// STORE 'Prototype Ordered' / 'Partial Production Ordered' in
// opportunities.sales_stage. A key that differs by one character leaves those
// rows rendering a raw value with no label, which is the failure G220/G273
// exist about. So both are byte-exact, and scripts/tests/
// test_release_stage_keys.py pins them character for character.
//
// APPEND-ONLY, AND NEVER A WHOLE-ARRAY ASSIGNMENT. ERP-Core owns the stock
// stage vocabulary and reduces it (sales_stage_dom.replace.php); this file
// only adds two keys to whatever that leaves. An _override_ fragment is
// evaluated after ordinary ones whatever their modification order (Sugar
// 26.1.0 ModuleInstall/ModuleInstaller.php:2449), so these survive a core
// fragment that still carries a historical whole-array reset - the same
// reason the quote-stage fragment next to this one is named that way. G273
// removed the cause on this side; this keeps the keys safe on a tenant that
// has not taken it yet.
//
// 🛑 NEITHER KEY IS A CLOSED STAGE, AND THAT IS THE POINT (REQ-22): the
// remainder of the deal stays OPEN PIPELINE. Sugar counts a deal as won or
// lost only through the forecast config's sales_stage_won / sales_stage_lost
// (modules/Forecasts/ForecastsDefaults.php:118-120, ['Closed Won'] /
// ['Closed Lost']), and this package's own terminal sets say exactly the same
// two (ErpOpportunityValuation::TERMINAL_SALES_STAGES, ERP-Core's
// OpportunityCloseAmountDecision::TERMINAL_SALES_STAGES). Nothing here adds a
// key to any of them.
//
// The probabilities are the two milestones' own odds: a prototype has been
// ordered (80) and part of production has been ordered (90). Both are short
// of 100, which is what a won deal carries - stated so that "not closed" is
// true of the number as well as of the key.
$app_list_strings['sales_stage_dom']['Prototype Ordered'] = 'Prototype Ordered';
$app_list_strings['sales_stage_dom']['Partial Production Ordered'] = 'Partial Production Ordered';

$app_list_strings['sales_probability_dom']['Prototype Ordered'] = 80;
$app_list_strings['sales_probability_dom']['Partial Production Ordered'] = 90;
